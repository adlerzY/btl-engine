<?php
defined('ABSPATH') || exit;

final class BTL_Admin_Notifications
{
    private const READY_OPTION = 'btl_admin_notifications_table_ready_v1';
    private const KEEP_PER_ADMIN = 200;

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_admin_notifications'; }

    public static function boot(): void
    {
        add_action('woocommerce_new_order', [self::class, 'notify_new_order'], 20, 1);
        add_action('save_post_support_ticket', [self::class, 'notify_new_ticket'], 20, 3);
        add_action('wp_insert_comment', [self::class, 'notify_new_review'], 20, 2);

        if (btl_is_admin_graphql_request()) {
            add_action('graphql_register_types', [self::class, 'register'], 12);
        }
    }

    public static function maybe_install(): void { BTL_Helpers::ensureTable(self::READY_OPTION, [self::class, 'install']); }

    public static function install(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE " . self::table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(32) NOT NULL,
            title VARCHAR(190) NOT NULL,
            body TEXT NOT NULL,
            link VARCHAR(190) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY admin_user_unread (admin_user_id, is_read, id),
            KEY created_at (created_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function pushToStaff(string $type, string $title, string $body, ?string $link = null): void
    {
        foreach (BTL_Admin_Permissions::staffUserIds() as $staffId) {
            self::push((int)$staffId, $type, $title, $body, $link);
        }
    }

    public static function push(int $adminUserId, string $type, string $title, string $body, ?string $link = null): bool
    {
        global $wpdb;
        $ok = $wpdb->insert(self::table(), [
            'admin_user_id' => $adminUserId,
            'type' => sanitize_key($type),
            'title' => sanitize_text_field($title),
            'body' => wp_kses_post($body),
            'link' => $link,
        ], ['%d', '%s', '%s', '%s', '%s']) !== false;

        if ($ok) {
            BTL_Cache::delete("admin_unread_notifications_{$adminUserId}");
            if (wp_rand(1, 20) === 1) {
                self::trimOld($adminUserId);
            }
        }

        return $ok;
    }

    private static function trimOld(int $adminUserId): void
    {
        global $wpdb;
        $table = self::table();
        $cutoff = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE admin_user_id=%d ORDER BY id DESC LIMIT 1 OFFSET %d",
            $adminUserId,
            self::KEEP_PER_ADMIN
        ));

        if ($cutoff > 0) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE admin_user_id=%d AND id<=%d",
                $adminUserId,
                $cutoff
            ));
        }
    }

    public static function register(): void
    {
        if (!btl_is_admin_graphql_request()) return;
        register_graphql_object_type('BtlAdminNotification', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'type' => ['type' => 'String'],
                'title' => ['type' => 'String'],
                'body' => ['type' => 'String'],
                'link' => ['type' => 'String'],
                'isRead' => ['type' => 'Boolean'],
                'createdAt' => ['type' => 'String'],
            ],
        ]);
        register_graphql_field('RootQuery', 'adminUnreadNotificationsCount', [
            'type' => 'Int',
            'resolve' => static function (): int {
                $userId = get_current_user_id();
                if (!$userId || !current_user_can('manage_woocommerce')) return 0;
                return (int) BTL_Cache::remember("admin_unread_notifications_{$userId}", static function () use ($userId): int {
                    global $wpdb;
                    return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table() . " WHERE admin_user_id=%d AND is_read=0", $userId));
                }, 'btl', 10);
            },
        ]);
        register_graphql_field('RootQuery', 'adminNotifications', [
            'type' => ['list_of' => 'BtlAdminNotification'],
            'args' => [
                'first' => ['type' => 'Int'],
                'unreadOnly' => ['type' => 'Boolean'],
            ],
            'resolve' => static function ($root, array $args): array {
                self::assertStaff();
                global $wpdb;
                $limit = min(max((int)($args['first'] ?? 20), 1), 50);
                $where = 'admin_user_id=%d';
                $params = [get_current_user_id()];
                if (!empty($args['unreadOnly'])) { $where .= ' AND is_read=0'; }
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE {$where} ORDER BY id DESC LIMIT %d", ...array_merge($params, [$limit])), ARRAY_A) ?: [];
                return array_map(static fn(array $row): array => [
                    'databaseId' => (int)$row['id'],
                    'type' => (string)$row['type'],
                    'title' => (string)$row['title'],
                    'body' => (string)$row['body'],
                    'link' => $row['link'] !== null ? (string)$row['link'] : null,
                    'isRead' => (bool)$row['is_read'],
                    'createdAt' => (string)$row['created_at'],
                ], $rows);
            },
        ]);
        register_graphql_mutation('markAdminNotificationsRead', [
            'inputFields' => ['notificationIds' => ['type' => ['list_of' => 'Int']]],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertStaff();
                global $wpdb;
                $ids = array_values(array_filter(array_map('absint', (array)($input['notificationIds'] ?? []))));
                $userId = get_current_user_id();
                if (!$ids) {
                    $wpdb->update(self::table(), ['is_read' => 1], ['admin_user_id' => $userId, 'is_read' => 0], ['%d'], ['%d', '%d']);
                    BTL_Cache::delete("admin_unread_notifications_{$userId}");
                    return ['success' => true];
                }
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $sql = "UPDATE " . self::table() . " SET is_read=1 WHERE admin_user_id=%d AND id IN ({$placeholders})";
                $wpdb->query($wpdb->prepare($sql, $userId, ...$ids));
                BTL_Cache::delete("admin_unread_notifications_{$userId}");
                return ['success' => true];
            },
        ]);
    }

    public static function notify_new_order(int $orderId): void
    {
        if ($orderId < 1 || get_post_meta($orderId, '_btl_admin_new_order_notified', true)) return;
        $order = wc_get_order($orderId);
        if (!$order) return;
        update_post_meta($orderId, '_btl_admin_new_order_notified', gmdate('c'));
        BTL_Cache::delete('admin_processing_orders_count');
        self::pushToStaff('order', 'سفارش جدید', 'سفارش #' . $order->get_order_number() . ' نیاز به بررسی عملیاتی دارد.', '/admin/orders?order=' . $orderId);
    }

    public static function notify_new_ticket(int $postId, WP_Post $post, bool $update): void
    {
        if ($update || wp_is_post_revision($postId) || $post->post_status !== 'publish') return;
        if (get_post_meta($postId, '_btl_admin_new_ticket_notified', true)) return;
        update_post_meta($postId, '_btl_admin_new_ticket_notified', gmdate('c'));
        foreach ([10, 20, 50] as $limit) {
            BTL_Cache::delete('admin_open_tickets_' . $limit);
        }
        BTL_Cache::delete('admin_open_tickets_count');
        self::pushToStaff('ticket', 'تیکت جدید', 'تیکت «' . get_the_title($postId) . '» ثبت شد.', '/admin/tickets/' . $postId);
    }

    public static function notify_new_review(int $commentId, WP_Comment $comment): void
    {
        if ($comment->comment_parent || $comment->comment_type !== 'review' || (string)$comment->comment_approved === '1') return;
        if (get_comment_meta($commentId, '_btl_admin_new_review_notified', true)) return;
        update_comment_meta($commentId, '_btl_admin_new_review_notified', 1);
        BTL_Cache::delete('pending_reviews_count');
        $product = wc_get_product((int)$comment->comment_post_ID);
        self::pushToStaff('review', 'نظر جدید', 'یک نظر جدید برای «' . ($product ? $product->get_name() : 'محصول') . '» در انتظار بررسی است.', '/admin/reviews');
    }

    private static function assertStaff(): void
    {
        if (!get_current_user_id() || !current_user_can('manage_woocommerce')) throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
    }
}