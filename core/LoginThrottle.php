<?php
defined('ABSPATH') || exit;

final class BTL_Login_Throttle
{
    private const READY_OPTION = 'btl_login_attempts_table_ready';
    private const MAX_ATTEMPTS = 10;
    private const WINDOW_SECONDS = 600;

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_login_attempts'; }

    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_install'], 5);
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
            identifier VARCHAR(190) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY identifier_created (identifier, created_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function assertAllowed(string $identifier, string $ip): void
    {
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE identifier=%s AND created_at > (UTC_TIMESTAMP() - INTERVAL %d SECOND)",
            $identifier, self::WINDOW_SECONDS
        ));

        if ($count >= self::MAX_ATTEMPTS) {
            throw new GraphQL\Error\UserError('تعداد تلاش‌های ورود بیش از حد مجاز است. چند دقیقه دیگر تلاش کنید.');
        }
    }

    public static function recordAttempt(string $identifier, string $ip): void
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'identifier' => $identifier,
            'ip_address' => $ip !== '' ? $ip : 'unknown',
            'created_at' => current_time('mysql', true),
        ]);
    }

    public static function clearAttempts(string $identifier): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['identifier' => $identifier]);
    }
}