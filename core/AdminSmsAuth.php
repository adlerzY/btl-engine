<?php
defined('ABSPATH') || exit;

final class BTL_Admin_Sms_Auth
{
    private const PURPOSE = 'admin_sms_login';
    private const PENDING_PHONE_PREFIX = 'btl_admin_sms_phone_';
    private const PENDING_PHONE_TTL = 600;
    private const PHONE_META = 'btl_phone';
    private const RESEND_COOLDOWN_SECONDS = 60;

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }

    public static function register(): void
    {
        register_graphql_mutation('requestAdminSmsOtp', [
            'inputFields' => [
                'pendingTicket' => ['type' => ['non_null' => 'String']],
                'phone' => ['type' => 'String'],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'maskedPhone' => ['type' => 'String'],
                'requiresPhoneInput' => ['type' => 'Boolean'],
                'cooldownSeconds' => ['type' => 'Int'],
            ],
            'mutateAndGetPayload' => function ($input) {
                return self::safeExecute(function () use ($input) {
                    $ticket = $input['pendingTicket'];
                    $userId = BTL_Admin_Totp::resolvePendingUserId($ticket);
                    if (!BTL_Admin_Totp::isSmsFallbackAllowed($userId)) {
                        throw new GraphQL\Error\UserError('برای این حساب، ورود پیامکی فعال نیست.');
                    }
                    $storedPhone = get_user_meta($userId, self::PHONE_META, true);

                    if ($storedPhone) {
                        $phone = $storedPhone;
                    } else {
                        $rawPhone = trim((string) ($input['phone'] ?? ''));
                        $phone = $rawPhone !== '' ? BTL_Phone_Auth::normalizePhone($rawPhone) : null;

                        if (!$phone) {
                            return [
                                'success' => false,
                                'maskedPhone' => null,
                                'requiresPhoneInput' => true,
                                'cooldownSeconds' => 0,
                            ];
                        }
                        throw new GraphQL\Error\UserError('شماره ورود پیامکی باید از قبل برای حساب ثبت و تأیید شده باشد.');
                    }

                    $ip = BTL_Helpers::clientIp();

                    BTL_Otp::request($phone, 'sms', self::PURPOSE, $ip, static function (string $code) use ($phone) {
                        $gateway = new BTL_NirSms_Gateway();
                        return $gateway->sendOtp($phone, $code);
                    });

                    return [
                        'success' => true,
                        'maskedPhone' => self::mask($phone),
                        'requiresPhoneInput' => false,
                        'cooldownSeconds' => self::RESEND_COOLDOWN_SECONDS,
                    ];
                });
            },
        ]);

        register_graphql_mutation('verifyAdminSmsOtp', [
            'inputFields' => [
                'pendingTicket' => ['type' => ['non_null' => 'String']],
                'code' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'authToken' => ['type' => 'String'],
                'refreshToken' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                return self::safeExecute(function () use ($input) {
                    $ticket = $input['pendingTicket'];
                    $userId = BTL_Admin_Totp::resolvePendingUserId($ticket);
                    if (!BTL_Admin_Totp::isSmsFallbackAllowed($userId)) {
                        throw new GraphQL\Error\UserError('برای این حساب، ورود پیامکی فعال نیست.');
                    }

                    $storedPhone = get_user_meta($userId, self::PHONE_META, true);
                    $phone = $storedPhone;

                    if (!$phone) {
                        throw new GraphQL\Error\UserError('نشست منقضی شده، دوباره درخواست کد دهید.');
                    }

                    BTL_Otp::verify($phone, self::PURPOSE, sanitize_text_field($input['code']));

                    BTL_Admin_Totp::clearPendingTicket($ticket);

                    $user = get_userdata($userId);
                    $tokens = BTL_Phone_Auth::issueTokens($user);

                    return ['authToken' => $tokens['authToken'], 'refreshToken' => $tokens['refreshToken']];
                });
            },
        ]);
    }

    private static function mask(string $phone): string
    {
        $len = strlen($phone);
        if ($len < 6) {
            return $phone;
        }
        return substr($phone, 0, 4) . str_repeat('•', $len - 6) . substr($phone, -2);
    }

    private static function safeExecute(callable $fn)
    {
        try {
            return $fn();
        } catch (GraphQL\Error\UserError $e) {
            throw $e;
        } catch (Throwable $e) {
            BTL_Helpers::logger(
                'AdminSmsAuth fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );
            throw new GraphQL\Error\UserError('خطای داخلی سرور رخ داد، لطفاً با پشتیبانی تماس بگیرید.');
        }
    }
}