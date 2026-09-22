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
        $remote = self::validIp($_SERVER['REMOTE_ADDR'] ?? '');
        $trustedProxy = false;

        $configuredRanges = getenv('BTL_TRUSTED_PROXY_RANGES');
        $ranges = $configuredRanges !== false && trim($configuredRanges) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $configuredRanges))))
            : ['127.0.0.1/32', '::1/128'];
        $ranges = apply_filters('btl_trusted_proxy_ranges', $ranges);
        foreach ((array) $ranges as $range) {
            if (self::ipInCidr($remote, (string) $range)) {
                $trustedProxy = true;
                break;
            }
        }

        if ($trustedProxy && getenv('BTL_TRUST_PROXY_HEADERS') === 'true') {
            $cloudflareIp = self::validIp($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
            if ($cloudflareIp) return $cloudflareIp;

            if (getenv('BTL_USE_X_FORWARDED_FOR') === 'true') {
                foreach (explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $candidate) {
                    $ip = self::validIp($candidate);
                    if ($ip) return $ip;
                }
            }
        }

        return $remote ?: 'unknown';
    }

    private static function validIp($value): ?string
    {
        $value = trim((string) $value);
        return filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }

    private static function ipInCidr(?string $ip, string $cidr): bool
    {
        if (!$ip) return false;
        $parts = explode('/', trim($cidr), 2);
        $network = self::validIp($parts[0] ?? '');
        if (!$network) return false;
        if (!isset($parts[1])) return $ip === $network;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $prefix = (int) $parts[1];
            if ($prefix < 0 || $prefix > 32) return false;
            $ipLong = ip2long($ip);
            $networkLong = ip2long($network);
            $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));
            return (($ipLong & $mask) === ($networkLong & $mask));
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $prefix = (int) $parts[1];
            if ($prefix < 0 || $prefix > 128) return false;
            $ipBin = inet_pton($ip);
            $networkBin = inet_pton($network);
            if ($ipBin === false || $networkBin === false) return false;
            $fullBytes = intdiv($prefix, 8);
            $remaining = $prefix % 8;
            if ($fullBytes && substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) return false;
            if ($remaining === 0) return true;
            $mask = chr((0xFF << (8 - $remaining)) & 0xFF);
            return (ord($ipBin[$fullBytes]) & ord($mask)) === (ord($networkBin[$fullBytes]) & ord($mask));
        }
        return false;
    }
}