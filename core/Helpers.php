<?php
defined('ABSPATH') || exit;

final class BTL_Helpers
{
    private const AUTOLOAD_MIGRATION_OPTION = 'btl_ready_flags_autoload_migrated';

    public static function money($value): float
    {
        return (float) str_replace(
            [',', '،', ' '],
            '',
            (string) $value
        );
    }

    public static function sanitizeNumber($value): string
    {
        $value = trim(
            str_replace(
                [',', '،', ' '],
                '',
                (string) $value
            )
        );

        if ($value === '') {
            return '';
        }

        if (strtolower($value) === 'disabled') {
            return 'disabled';
        }

        if (!is_numeric($value)) {
            return '';
        }

        $value = (float) $value;

        if ($value < 0) {
            return '';
        }

        if ($value > 999999999999) {
            return '';
        }

        return (string) round($value);
    }

    public static function bool($value): bool
    {
        return filter_var(
            $value,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    public static function now(): int
    {
        return current_time(
            'timestamp',
            true
        );
    }

    public static function logger(string $message): void
    {
        error_log('[BTL] ' . $message);
    }

    public static function cacheKey(string $prefix, $id): string
    {
        return sprintf(
            '%s_%s',
            $prefix,
            $id
        );
    }

    public static function slugTag(string $prefix, string $slug): string
    {
        $normalized = rawurldecode($slug);

        $ascii = preg_replace('/[^a-zA-Z0-9-]+/', '-', $normalized);
        $ascii = preg_replace('/-+/', '-', (string) $ascii);
        $ascii = trim((string) $ascii, '-');
        $ascii = substr($ascii, 0, 40);

        $hash = substr(sha1($normalized), 0, 16);

        return $ascii !== ''
            ? "{$prefix}-{$ascii}-{$hash}"
            : "{$prefix}-{$hash}";
    }

    public static function ensureTable(
        string $readyOption,
        callable $installer
    ): void {
        self::maybeMigrateAutoloadFlags();

        if (get_option($readyOption) === '1') {
            return;
        }

        try {
            global $wpdb;
            $wpdb->last_error = '';
            $installer();
            if ($wpdb->last_error !== '') {
                throw new RuntimeException($wpdb->last_error);
            }
            update_option(
                $readyOption,
                '1',
                true
            );
        } catch (Throwable $e) {
            self::logger(
                "ensureTable({$readyOption}) failed: " .
                $e->getMessage()
            );
        }
    }

    private static function maybeMigrateAutoloadFlags(): void
    {
        if (get_option(self::AUTOLOAD_MIGRATION_OPTION) === '1') {
            return;
        }

        global $wpdb;

        $flags = [
            'btl_secure_fields_table_ready',
            'btl_notifications_table_ready',
            'btl_notifications_type_column_ready',
            'btl_sessions_table_ready',
            'btl_ticket_replies_table_ready',
            'btl_otp_codes_table_ready',
            'btl_post_ratings_table_ready',
            'btl_blog_follows_table_ready',
            'btl_login_attempts_table_ready',
            'btl_cdkey_stock_table_ready',
        ];

        $placeholders = implode(
            ',',
            array_fill(
                0,
                count($flags),
                '%s'
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET autoload = 'yes' WHERE option_name IN ({$placeholders}) AND autoload <> 'yes'",
                $flags
            )
        );

        wp_cache_delete(
            'alloptions',
            'options'
        );

        update_option(
            self::AUTOLOAD_MIGRATION_OPTION,
            '1',
            true
        );
    }

    public static function clientIp(): string
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

        if ($xff) {
            $parts = explode(
                ',',
                $xff
            );

            return trim($parts[0]);
        }

        return sanitize_text_field(
            $_SERVER['REMOTE_ADDR'] ?? ''
        );
    }
}