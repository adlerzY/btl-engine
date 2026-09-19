<?php
defined('ABSPATH') || exit;

final class BTL_Login_Throttle
{
    private const READY_OPTION = 'btl_login_attempts_table_ready';
    private const MAX_ATTEMPTS = 10;
    private const WINDOW_SECONDS = 600;

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_login_attempts'; }

    private static function lockName(string $identifier): string
    {
        return 'btl_login_' . md5($identifier);
    }

    private static function releaseLock(string $identifier): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lockName($identifier)));
    }

    public static function boot(): void
    {
        add_action('btl_login_attempts_cleanup', [self::class, 'cleanupExpired']);
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
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            identifier VARCHAR(190) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY identifier_created (identifier, created_at),
            KEY created_at (created_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function schedule_cleanup(): void
    {
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_recurring_action')) {
            if (!as_next_scheduled_action('btl_login_attempts_cleanup', [], 'btl')) {
                as_schedule_recurring_action(time() + 300, 21600, 'btl_login_attempts_cleanup', [], 'btl');
            }
        } elseif (!wp_next_scheduled('btl_login_attempts_cleanup')) {
            wp_schedule_event(time() + 300, 'twicedaily', 'btl_login_attempts_cleanup');
        }
    }

    public static function cleanupExpired(int $limit = 5000): int
    {
        global $wpdb;
        return (int)$wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE created_at < UTC_TIMESTAMP() - INTERVAL 2 DAY LIMIT %d",
            max(100, min($limit, 10000))
        ));
    }

    public static function assertAllowed(string $identifier, string $ip): void
    {
        global $wpdb;
        $lockName = self::lockName($identifier);
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lockName));
        if ($locked !== 1) {
            throw new GraphQL\Error\UserError('سامانه ورود مشغول است. کمی بعد تلاش کنید.');
        }

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE identifier=%s AND created_at > (UTC_TIMESTAMP() - INTERVAL %d SECOND)",
            $identifier, self::WINDOW_SECONDS
        ));

        if ($count >= self::MAX_ATTEMPTS) {
            self::releaseLock($identifier);
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
        self::releaseLock($identifier);
    }

    public static function clearAttempts(string $identifier): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['identifier' => $identifier]);
        self::releaseLock($identifier);
    }
}