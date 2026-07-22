<?php
defined('ABSPATH') || exit;

final class BTL_Post_Ratings
{
    private const READY_OPTION = 'btl_post_ratings_table_ready';

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_post_ratings'; }

    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_install'], 5);
        add_action('graphql_register_types', [self::class, 'register'], 10);
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
            post_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY post_user (post_id, user_id),
            KEY post_id (post_id)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function register(): void
    {
        register_graphql_field('Post', 'averageRating', [
            'type' => 'Float',
            'resolve' => static fn($post) => self::aggregate((int)$post->databaseId)['average'],
        ]);

        register_graphql_field('Post', 'ratingCount', [
            'type' => 'Int',
            'resolve' => static fn($post) => self::aggregate((int)$post->databaseId)['count'],
        ]);

        register_graphql_field('Post', 'myRating', [
            'type' => 'Int',
            'resolve' => static function ($post) {
                if (!is_user_logged_in()) return null;
                return self::userRating((int)$post->databaseId, get_current_user_id());
            },
        ]);

        register_graphql_mutation('rateBlogPost', [
            'inputFields' => [
                'postId' => ['type' => ['non_null' => 'Int']],
                'rating' => ['type' => ['non_null' => 'Int']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'averageRating' => ['type' => 'Float'],
                'ratingCount' => ['type' => 'Int'],
                'myRating' => ['type' => 'Int'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');

                $rating = (int)$input['rating'];
                if ($rating < 1 || $rating > 5) throw new GraphQL\Error\UserError('امتیاز باید بین ۱ تا ۵ باشد.');

                $postId = (int)$input['postId'];
                if (get_post_type($postId) !== 'post') throw new GraphQL\Error\UserError('پست یافت نشد.');

                self::upsert($postId, get_current_user_id(), $rating);
                $agg = self::aggregate($postId);

                return [
                    'success' => true,
                    'averageRating' => $agg['average'],
                    'ratingCount' => $agg['count'],
                    'myRating' => $rating,
                ];
            },
        ]);
    }

    public static function upsert(int $postId, int $userId, int $rating): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (post_id, user_id, rating) VALUES (%d, %d, %d)
             ON DUPLICATE KEY UPDATE rating=VALUES(rating), created_at=CURRENT_TIMESTAMP",
            $postId, $userId, $rating
        ));
        BTL_Cache::delete("post_rating_agg_{$postId}");
    }

    public static function userRating(int $postId, int $userId): ?int
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT rating FROM " . self::table() . " WHERE post_id=%d AND user_id=%d", $postId, $userId
        ));
        return $value !== null ? (int)$value : null;
    }

    public static function aggregate(int $postId): array
    {
        return BTL_Cache::remember("post_rating_agg_{$postId}", static function () use ($postId) {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT AVG(rating) as avg_rating, COUNT(*) as cnt FROM " . self::table() . " WHERE post_id=%d",
                $postId
            ));
            return [
                'average' => $row && $row->cnt > 0 ? round((float)$row->avg_rating, 1) : 0.0,
                'count' => $row ? (int)$row->cnt : 0,
            ];
        }, 'btl', 120);
    }
}