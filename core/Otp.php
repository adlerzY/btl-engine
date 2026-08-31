<?php

defined('ABSPATH') || exit;

final class BTL_Otp
{
    private const READY_OPTION = 'btl_otp_codes_table_ready';
    private const CODE_LENGTH = 5;
    private const EXPIRES_SECONDS = 120;
    private const MAX_ATTEMPTS = 5;
    private const MAX_PER_IDENTIFIER = 3;
    private const IDENTIFIER_WINDOW_SECONDS = 600;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_PER_IP = 8;
    private const IP_WINDOW_SECONDS = 600;

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_otp_codes'; }

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
            channel VARCHAR(10) NOT NULL DEFAULT 'sms',
            purpose VARCHAR(30) NOT NULL DEFAULT 'login_register',
            code_hash VARCHAR(255) NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY identifier_purpose (identifier, purpose),
            KEY created_at (created_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function request(string $identifier, string $channel, string $purpose, string $ip, callable $sendFn): void
    {
        global $wpdb;
        $table = self::table();

        if ($ip !== '') {
            $ipCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE ip_address=%s AND created_at > (UTC_TIMESTAMP() - INTERVAL %d SECOND)",
                $ip, self::IP_WINDOW_SECONDS
            ));
            if ($ipCount >= self::MAX_PER_IP) {
                throw new GraphQL\Error\UserError('تعداد درخواست از این آی‌پی بیش از حد مجاز است. کمی بعد تلاش کنید.');
            }
        }

        $recent = $wpdb->get_row($wpdb->prepare(
            "SELECT created_at FROM {$table} WHERE identifier=%s AND purpose=%s ORDER BY id DESC LIMIT 1",
            $identifier, $purpose
        ));

        if ($recent) {
            $secondsSince = time() - strtotime($recent->created_at . ' UTC');
            if ($secondsSince < self::RESEND_COOLDOWN_SECONDS) {
                $wait = self::RESEND_COOLDOWN_SECONDS - $secondsSince;
                throw new GraphQL\Error\UserError("لطفاً {$wait} ثانیه دیگر دوباره تلاش کنید.");
            }
        }

        $windowCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE identifier=%s AND purpose=%s AND created_at > (UTC_TIMESTAMP() - INTERVAL %d SECOND)",
            $identifier, $purpose, self::IDENTIFIER_WINDOW_SECONDS
        ));
        if ($windowCount >= self::MAX_PER_IDENTIFIER) {
            throw new GraphQL\Error\UserError('تعداد درخواست کد بیش از حد مجاز است. چند دقیقه دیگر تلاش کنید.');
        }

        $code = (string) random_int(10 ** (self::CODE_LENGTH - 1), (10 ** self::CODE_LENGTH) - 1);

        $wpdb->insert($table, [
            'identifier' => $identifier,
            'channel' => $channel,
            'purpose' => $purpose,
            'code_hash' => password_hash($code, PASSWORD_BCRYPT),
            'ip_address' => $ip ?: null,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::EXPIRES_SECONDS),
            'created_at' => current_time('mysql', true),
        ]);

        if (!$sendFn($code)) {
            throw new GraphQL\Error\UserError('ارسال کد با خطا مواجه شد. دوباره تلاش کنید.');
        }
    }
    public static function validate(string $identifier, string $purpose, string $code): int
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE identifier=%s AND purpose=%s AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1",
            $identifier, $purpose
        ));

        if (!$row) {
            throw new GraphQL\Error\UserError('کد نامعتبر یا منقضی شده. دوباره درخواست دهید.');
        }

        if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
            throw new GraphQL\Error\UserError('تعداد تلاش‌های نادرست بیش از حد مجاز است. کد جدید درخواست دهید.');
        }

        if (!password_verify($code, $row->code_hash)) {
            $wpdb->update($table, ['attempts' => (int) $row->attempts + 1], ['id' => $row->id]);
            throw new GraphQL\Error\UserError('کد وارد شده صحیح نیست.');
        }

        return (int) $row->id;
    }

    public static function consume(int $rowId): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['consumed_at' => current_time('mysql', true)], ['id' => $rowId]);
    }

    public static function verify(string $identifier, string $purpose, string $code): void
    {
        $rowId = self::validate($identifier, $purpose, $code);
        self::consume($rowId);
    }
}