<?php
defined('ABSPATH') || exit;

final class BTL_Blog_Follow
{
    private const READY_OPTION = 'btl_blog_follows_table_ready';
    private const NOTIF_TYPE = 'blog';

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_blog_follows'; }

    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_install'], 5);
        add_action('graphql_register_types', [self::class, 'register'], 20);
        add_action('transition_post_status', [self::class, 'on_status_change'], 10, 3);
    }

    public static function maybe_install(): void
    {
        BTL_Helpers::ensureTable(self::READY_OPTION, [self::class, 'install']);
    }

    public static function install(): void
    {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY user_category (user_id, category_id),
            KEY category_id (category_id)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function register(): void
    {
        register_graphql_field('Category', 'isFollowedByViewer', [
            'type' => 'Boolean',
            'resolve' => static function ($category) {
                if (!is_user_logged_in()) return false;
                return self::isFollowing(get_current_user_id(), (int)$category->databaseId);
            },
        ]);

        register_graphql_field('Category', 'followerCount', [
            'type' => 'Int',
            'resolve' => static function ($category) {
                $id = (int)$category->databaseId;
                return BTL_Cache::remember("blog_follower_count_{$id}", static function () use ($id) {
                    global $wpdb;
                    return (int)$wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM " . self::table() . " WHERE category_id=%d", $id
                    ));
                }, 'btl', 300);
            },
        ]);

        register_graphql_field('BtlNotification', 'type', [
            'type' => 'String',
            'resolve' => static fn($n) => $n['type'] ?? 'engagement',
        ]);

        register_graphql_mutation('followBlogCategory', [
            'inputFields' => ['categoryId' => ['type' => ['non_null' => 'Int']]],
            'outputFields' => ['success' => ['type' => 'Boolean'], 'isFollowing' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                self::follow(get_current_user_id(), (int)$input['categoryId']);
                return ['success' => true, 'isFollowing' => true];
            },
        ]);

        register_graphql_mutation('unfollowBlogCategory', [
            'inputFields' => ['categoryId' => ['type' => ['non_null' => 'Int']]],
            'outputFields' => ['success' => ['type' => 'Boolean'], 'isFollowing' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                self::unfollow(get_current_user_id(), (int)$input['categoryId']);
                return ['success' => true, 'isFollowing' => false];
            },
        ]);
    }

    public static function isFollowing(int $userId, int $categoryId): bool
    {
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table() . " WHERE user_id=%d AND category_id=%d", $userId, $categoryId
        ));
        return (bool)$row;
    }

    public static function follow(int $userId, int $categoryId): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . self::table() . " (user_id, category_id) VALUES (%d, %d)",
            $userId, $categoryId
        ));
        BTL_Cache::delete("blog_follower_count_{$categoryId}");
    }

    public static function unfollow(int $userId, int $categoryId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['user_id' => $userId, 'category_id' => $categoryId]);
        BTL_Cache::delete("blog_follower_count_{$categoryId}");
    }

    private static function followerIds(int $categoryId): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM " . self::table() . " WHERE category_id=%d", $categoryId
        )));
    }

    public static function on_status_change($newStatus, $oldStatus, $post): void
    {
        if (!$post instanceof WP_Post || $post->post_type !== 'post') return;
        if ($newStatus !== 'publish') return;

        $categories = get_the_category($post->ID);
        if (!$categories) return;

        $tags = ['all-blog-posts', "post-{$post->post_name}"];
        $catIds = [];

        foreach ($categories as $cat) {
            $tags[] = "blog-category-{$cat->slug}";
            $catIds[] = (int)$cat->term_id;

            if ($cat->parent) {
                $catIds[] = (int)$cat->parent;
                $parentTerm = get_term($cat->parent, 'category');
                if ($parentTerm && !is_wp_error($parentTerm)) {
                    $tags[] = "blog-category-{$parentTerm->slug}";
                }
            }
        }
        $catIds = array_unique($catIds);

        if (function_exists('btl_queue_revalidation')) {
            btl_queue_revalidation(array_unique($tags));
        }

        if ($oldStatus === 'publish') return;

        $link = '/blog/' . $categories[0]->slug . '/' . $post->post_name;
        $notified = [];

        foreach ($catIds as $catId) {
            foreach (self::followerIds($catId) as $userId) {
                if (isset($notified[$userId])) continue;
                $notified[$userId] = true;
                BTL_Notifications::push(
                    $userId,
                    'خبر جدید در دسته‌بندی مورد علاقه شما 📰',
                    sprintf('«%s» منتشر شد.', get_the_title($post)),
                    $link,
                    self::NOTIF_TYPE
                );
            }
        }
    }
}