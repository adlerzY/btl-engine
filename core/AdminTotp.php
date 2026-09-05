<?php
defined('ABSPATH') || exit;

final class BTL_Admin_Totp
{
    private const TICKET_PREFIX   = 'btl_totp_ticket_';
    private const SETUP_PREFIX    = 'btl_totp_setup_';
    private const ATTEMPTS_PREFIX = 'btl_totp_attempts_';
    private const SECRET_META     = 'btl_admin_totp_secret';
    private const RECOVERY_META   = 'btl_admin_totp_recovery';
    private const TICKET_TTL      = 600;
    private const DIGITS          = 6;
    private const PERIOD          = 30;
    private const MAX_VERIFY_ATTEMPTS = 5;

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }

    public static function issuePendingTicket(int $userId): string
    {
        $ticket = bin2hex(random_bytes(32));
        set_transient(self::TICKET_PREFIX . $ticket, $userId, self::TICKET_TTL);
        return $ticket;
    }

    public static function isConfigured(int $userId): bool
    {
        $secret = get_user_meta($userId, self::SECRET_META, true);
        return !empty($secret);
    }

    private static function resolveTicket(string $ticket): int
    {
        $userId = get_transient(self::TICKET_PREFIX . $ticket);
        if (!$userId) {
            throw new GraphQL\Error\UserError('نشست ورود منقضی شده است. دوباره تلاش کنید.');
        }
        return (int) $userId;
    }

    private static function assertTicketNotLocked(string $ticket): void
    {
        $attempts = (int) get_transient(self::ATTEMPTS_PREFIX . $ticket);
        if ($attempts >= self::MAX_VERIFY_ATTEMPTS) {
            delete_transient(self::TICKET_PREFIX . $ticket);
            delete_transient(self::SETUP_PREFIX . $ticket);
            delete_transient(self::ATTEMPTS_PREFIX . $ticket);
            throw new GraphQL\Error\UserError('تعداد تلاش‌های نادرست بیش از حد مجاز است. دوباره وارد شوید.');
        }
    }

    private static function registerFailedAttempt(string $ticket): void
    {
        $attempts = (int) get_transient(self::ATTEMPTS_PREFIX . $ticket);
        set_transient(self::ATTEMPTS_PREFIX . $ticket, $attempts + 1, self::TICKET_TTL);
    }

    private static function clearAttempts(string $ticket): void
    {
        delete_transient(self::ATTEMPTS_PREFIX . $ticket);
    }

    private static function safeExecute(callable $fn)
    {
        try {
            return $fn();
        } catch (GraphQL\Error\UserError $e) {
            throw $e;
        } catch (Throwable $e) {
            BTL_Helpers::logger(
                'AdminTotp fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );
            throw new GraphQL\Error\UserError('خطای داخلی سرور رخ داد، لطفاً با پشتیبانی تماس بگیرید.');
        }
    }

    public static function register(): void
    {
        register_graphql_mutation('requestAdminTotpSetup', [
            'inputFields' => [
                'pendingTicket' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'secret'     => ['type' => 'String'],
                'otpauthUrl' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                return self::safeExecute(function () use ($input) {
                    $userId = self::resolveTicket($input['pendingTicket']);
                    if (self::isConfigured($userId)) {
                        throw new GraphQL\Error\UserError('تأیید دومرحله‌ای قبلاً فعال شده است.');
                    }
                    $secret = self::generateSecret();
                    set_transient(self::SETUP_PREFIX . $input['pendingTicket'], $secret, self::TICKET_TTL);
                    $user = get_userdata($userId);
                    $label = rawurlencode('Arena2Battle:' . $user->user_login);
                    $otpauthUrl = "otpauth://totp/{$label}?secret={$secret}&issuer=Arena2Battle&digits=" . self::DIGITS . "&period=" . self::PERIOD;
                    return ['secret' => $secret, 'otpauthUrl' => $otpauthUrl];
                });
            },
        ]);

        register_graphql_mutation('confirmAdminTotpSetup', [
            'inputFields' => [
                'pendingTicket' => ['type' => ['non_null' => 'String']],
                'code'          => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'authToken'     => ['type' => 'String'],
                'refreshToken'  => ['type' => 'String'],
                'recoveryCodes' => ['type' => ['list_of' => 'String']],
            ],
            'mutateAndGetPayload' => function ($input) {
                return self::safeExecute(function () use ($input) {
                    $userId = self::resolveTicket($input['pendingTicket']);
                    self::assertTicketNotLocked($input['pendingTicket']);
                    $secret = get_transient(self::SETUP_PREFIX . $input['pendingTicket']);
                    if (!$secret) {
                        throw new GraphQL\Error\UserError('نشست تنظیم منقضی شده. دوباره تلاش کنید.');
                    }
                    if (!self::verifyCode($secret, sanitize_text_field($input['code']))) {
                        self::registerFailedAttempt($input['pendingTicket']);
                        throw new GraphQL\Error\UserError('کد وارد شده صحیح نیست.');
                    }
                    $recoveryCodes = self::generateRecoveryCodes();
                    $hashed = array_map(
                        static fn($c) => ['hash' => password_hash($c, PASSWORD_BCRYPT), 'used' => false],
                        $recoveryCodes
                    );
                    update_user_meta($userId, self::SECRET_META, BTL_Secure_Vault::encrypt($secret));
                    update_user_meta($userId, self::RECOVERY_META, $hashed);
                    delete_transient(self::SETUP_PREFIX . $input['pendingTicket']);
                    delete_transient(self::TICKET_PREFIX . $input['pendingTicket']);
                    self::clearAttempts($input['pendingTicket']);
                    $tokens = BTL_Phone_Auth::issueTokens(get_userdata($userId));
                    return [
                        'authToken'    => $tokens['authToken'],
                        'refreshToken' => $tokens['refreshToken'],
                        'recoveryCodes' => $recoveryCodes,
                    ];
                });
            },
        ]);

        register_graphql_mutation('verifyAdminTotp', [
            'inputFields' => [
                'pendingTicket' => ['type' => ['non_null' => 'String']],
                'code'          => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'authToken'    => ['type' => 'String'],
                'refreshToken' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                return self::safeExecute(function () use ($input) {
                    $userId = self::resolveTicket($input['pendingTicket']);
                    self::assertTicketNotLocked($input['pendingTicket']);
                    $code = sanitize_text_field($input['code']);
                    $encryptedSecret = get_user_meta($userId, self::SECRET_META, true);
                    $secret = $encryptedSecret ? BTL_Secure_Vault::decrypt($encryptedSecret) : null;
                    $verified = $secret && self::verifyCode($secret, $code);
                    if (!$verified) {
                        $verified = self::tryRecoveryCode($userId, $code);
                    }
                    if (!$verified) {
                        self::registerFailedAttempt($input['pendingTicket']);
                        throw new GraphQL\Error\UserError('کد وارد شده صحیح نیست.');
                    }
                    delete_transient(self::TICKET_PREFIX . $input['pendingTicket']);
                    self::clearAttempts($input['pendingTicket']);
                    $tokens = BTL_Phone_Auth::issueTokens(get_userdata($userId));
                    return ['authToken' => $tokens['authToken'], 'refreshToken' => $tokens['refreshToken']];
                });
            },
        ]);
    }

    private static function generateSecret(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    private static function verifyCode(string $secret, string $code): bool
    {
        if (strlen($code) !== self::DIGITS || !ctype_digit($code)) {
            return false;
        }

        $timeSlice = (int) floor(time() / self::PERIOD);
        for ($i = -1; $i <= 1; $i++) {
            if (hash_equals(self::calculateOtp($secret, $timeSlice + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    private static function calculateOtp(string $secret, int $timeSlice): string
    {
        $secretKey = self::base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hmac = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hmac, -1)) & 0x0F;
        $hashpart = substr($hmac, $offset, 4);
        $value = unpack('N', $hashpart)[1] & 0x7FFFFFFF;
        $modulo = 10 ** self::DIGITS;
        return str_pad((string) ($value % $modulo), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $secret): string
    {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32charsFlipped = array_flip(str_split($base32chars));
        $secret = strtoupper($secret);
        $binaryString = '';

        for ($i = 0; $i < strlen($secret); $i++) {
            if (!isset($base32charsFlipped[$secret[$i]])) {
                continue;
            }
            $binaryString .= str_pad(decbin($base32charsFlipped[$secret[$i]]), 5, '0', STR_PAD_LEFT);
        }

        $binaryArgs = str_split($binaryString, 8);
        $binary = '';

        foreach ($binaryArgs as $arg) {
            if (strlen($arg) === 8) {
                $binary .= chr(bindec($arg));
            }
        }

        return $binary;
    }

    private static function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = sprintf('%04d-%04d', random_int(1000, 9999), random_int(1000, 9999));
        }
        return $codes;
    }

    private static function tryRecoveryCode(int $userId, string $code): bool
    {
        $recovery = get_user_meta($userId, self::RECOVERY_META, true);
        if (!is_array($recovery)) {
            return false;
        }

        $code = trim($code);
        $used = false;

        foreach ($recovery as $key => $item) {
            if (empty($item['used']) && password_verify($code, $item['hash'])) {
                $recovery[$key]['used'] = true;
                $used = true;
                break;
            }
        }

        if ($used) {
            update_user_meta($userId, self::RECOVERY_META, $recovery);
            return true;
        }

        return false;
    }
}