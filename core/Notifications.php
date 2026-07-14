<?php
defined('ABSPATH') || exit;

final class BTL_Notifications
{
    private const READY_OPTION = 'btl_notifications_table_ready';

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_notifications'; }

    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_install'], 5);
        add_action('woocommerce_order_status_completed', [self::class, 'notify_order_completed']);
    }

    public static function maybe_install(): void
    {
        BTL_Helpers::ensureTable(self::READY_OPTION, [self::class, 'install']);
    }

    public static function install(): void
    {
        global $wpdb;
        $table = self::table(); $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL,
            body TEXT NOT NULL,
            link VARCHAR(190) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY user_id (user_id, is_read)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function push(int $userId, string $title, string $body, ?string $link = null): void
    {
        global $wpdb;
        $wpdb->insert(self::table(), ['user_id' => $userId, 'title' => $title, 'body' => $body, 'link' => $link]);
    }

    public static function forUser(int $userId, int $limit = 20): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id=%d ORDER BY id DESC LIMIT %d", $userId, $limit
        ), ARRAY_A);
    }

    public static function markAllRead(int $userId): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['is_read' => 1], ['user_id' => $userId, 'is_read' => 0]);
    }

    public static function notify_order_completed(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order) return;
        self::push(
            (int)$order->get_customer_id(),
            'سفارش شما تحویل داده شد 🎉',
            "سفارش #{$order->get_order_number()} با موفقیت تکمیل شد.",
            '/my-account/orders'
        );
    }
}