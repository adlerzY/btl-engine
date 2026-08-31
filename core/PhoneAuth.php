<?php

defined('ABSPATH') || exit;

final class BTL_Phone_Auth
{
    private const PURPOSE = 'login_register';
    private const PHONE_META = 'btl_phone';

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }

    public static function register(): void
    {
        register_graphql_mutation('requestPhoneOtp', [
            'inputFields' => [
                'phone' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'cooldownSeconds' => ['type' => 'Int'],
            ],
            'mutateAndGetPayload' => function ($input) {
                $phone = self::normalizePhone($input['phone']);
                if (!$phone) {
                    throw new GraphQL\Error\UserError('شماره موبایل نامعتبر است.');
                }

                $ip = BTL_Helpers::clientIp();

                BTL_Otp::request($phone, 'sms', self::PURPOSE, $ip, static function (string $code) use ($phone) {
                    $gateway = new BTL_NirSms_Gateway();
                    return $gateway->sendOtp($phone, $code);
                });

                return ['success' => true, 'cooldownSeconds' => 60];
            },
        ]);

        register_graphql_mutation('verifyPhoneOtp', [
            'inputFields' => [
                'phone' => ['type' => ['non_null' => 'String']],
                'code' => ['type' => ['non_null' => 'String']],
                'displayName' => ['type' => 'String'],
                'email' => ['type' => 'String'],
            ],
            'outputFields' => [
                'authToken' => ['type' => 'String'],
                'refreshToken' => ['type' => 'String'],
                'isNewUser' => ['type' => 'Boolean'],
                'requiresProfile' => ['type' => 'Boolean'],
                'requiresAdminTotp' => ['type' => 'Boolean'],
                'requiresAdminTotpSetup' => ['type' => 'Boolean'],
                'pendingTicket' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                $phone = self::normalizePhone($input['phone']);
                if (!$phone) {
                    throw new GraphQL\Error\UserError('شماره موبایل نامعتبر است.');
                }

                $otpRowId = BTL_Otp::validate($phone, self::PURPOSE, sanitize_text_field($input['code']));

                $existingUserId = self::findUserByPhone($phone);
                $isNewUser = !$existingUserId;

                if ($isNewUser) {
                    $displayName = trim((string) ($input['displayName'] ?? ''));

                    if ($displayName === '') {
                        return [
                            'authToken' => null, 'refreshToken' => null, 'isNewUser' => true,
                            'requiresProfile' => true,
                            'requiresAdminTotp' => false, 'requiresAdminTotpSetup' => false,
                            'pendingTicket' => null,
                        ];
                    }

                    $userId = self::createUser($phone, $input);
                } else {
                    $userId = $existingUserId;
                }

                BTL_Otp::consume($otpRowId);

                $user = get_userdata($userId);
                if (!$user) {
                    throw new GraphQL\Error\UserError('حساب کاربری یافت نشد.');
                }

                if (!$isNewUser && user_can($userId, 'manage_woocommerce')) {
                    $ticket = BTL_Admin_Totp::issuePendingTicket($userId);

                    if (!BTL_Admin_Totp::isConfigured($userId)) {
                        return [
                            'authToken' => null, 'refreshToken' => null, 'isNewUser' => false,
                            'requiresProfile' => false,
                            'requiresAdminTotp' => false, 'requiresAdminTotpSetup' => true,
                            'pendingTicket' => $ticket,
                        ];
                    }

                    return [
                        'authToken' => null, 'refreshToken' => null, 'isNewUser' => false,
                        'requiresProfile' => false,
                        'requiresAdminTotp' => true, 'requiresAdminTotpSetup' => false,
                        'pendingTicket' => $ticket,
                    ];
                }

                $tokens = self::issueTokens($user);

                return [
                    'authToken' => $tokens['authToken'],
                    'refreshToken' => $tokens['refreshToken'],
                    'isNewUser' => $isNewUser,
                    'requiresProfile' => false,
                    'requiresAdminTotp' => false,
                    'requiresAdminTotpSetup' => false,
                    'pendingTicket' => null,
                ];
            },
        ]);

        register_graphql_mutation('loginWithPhonePassword', [
            'inputFields' => [
                'phone' => ['type' => ['non_null' => 'String']],
                'password' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'authToken' => ['type' => 'String'],
                'refreshToken' => ['type' => 'String'],
                'requiresAdminTotp' => ['type' => 'Boolean'],
                'requiresAdminTotpSetup' => ['type' => 'Boolean'],
                'pendingTicket' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                $phone = self::normalizePhone($input['phone']);
                if (!$phone) {
                    throw new GraphQL\Error\UserError('شماره موبایل نامعتبر است.');
                }

                $ip = BTL_Helpers::clientIp();
                BTL_Login_Throttle::assertAllowed($phone, $ip);

                $userId = self::findUserByPhone($phone);
                $user = $userId ? get_userdata($userId) : null;

                if (!$user || !wp_check_password($input['password'], $user->user_pass, $userId)) {
                    BTL_Login_Throttle::recordAttempt($phone, $ip);
                    throw new GraphQL\Error\UserError('شماره موبایل یا رمز عبور اشتباه است.');
                }

                BTL_Login_Throttle::clearAttempts($phone);

                if (user_can($userId, 'manage_woocommerce')) {
                    $ticket = BTL_Admin_Totp::issuePendingTicket($userId);

                    if (!BTL_Admin_Totp::isConfigured($userId)) {
                        return [
                            'authToken' => null, 'refreshToken' => null,
                            'requiresAdminTotp' => false, 'requiresAdminTotpSetup' => true,
                            'pendingTicket' => $ticket,
                        ];
                    }

                    return [
                        'authToken' => null, 'refreshToken' => null,
                        'requiresAdminTotp' => true, 'requiresAdminTotpSetup' => false,
                        'pendingTicket' => $ticket,
                    ];
                }

                $tokens = self::issueTokens($user);

                return [
                    'authToken' => $tokens['authToken'],
                    'refreshToken' => $tokens['refreshToken'],
                    'requiresAdminTotp' => false,
                    'requiresAdminTotpSetup' => false,
                    'pendingTicket' => null,
                ];
            },
        ]);
    }

    public static function issueTokens(WP_User $user): array
    {
        if (!class_exists('\WPGraphQL\JWT_Authentication\Auth')) {
            throw new GraphQL\Error\UserError('سرویس صدور توکن پیکربندی نشده است.');
        }

        return [
            'authToken' => \WPGraphQL\JWT_Authentication\Auth::get_token($user),
            'refreshToken' => \WPGraphQL\JWT_Authentication\Auth::get_refresh_token($user),
        ];
    }

    private static function createUser(string $phone, array $input): int
    {
        $displayName = trim((string) ($input['displayName'] ?? ''));
        if ($displayName === '') {
            throw new GraphQL\Error\UserError('نام نمایشی الزامی است.');
        }

        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '') {
            if (!is_email($email)) {
                throw new GraphQL\Error\UserError('ایمیل نامعتبر است.');
            }
            if (email_exists($email)) {
                throw new GraphQL\Error\UserError('این ایمیل قبلاً استفاده شده است.');
            }
        }

        $username = 'u' . substr($phone, -9) . wp_rand(100, 999);

        $userId = wp_insert_user([
            'user_login' => $username,
            'user_pass' => wp_generate_password(32, true, true),
            'display_name' => sanitize_text_field($displayName),
            'nickname' => sanitize_text_field($displayName),
            'user_email' => $email !== '' ? $email : $username . '@phone.arena2battle.local',
            'role' => 'customer',
        ]);

        if (is_wp_error($userId)) {
            throw new GraphQL\Error\UserError('ساخت حساب با خطا مواجه شد: ' . $userId->get_error_message());
        }

        update_user_meta($userId, self::PHONE_META, $phone);
        if ($email !== '') {
            update_user_meta($userId, 'btl_email_verified', 1);
        }

        return $userId;
    }

    private static function findUserByPhone(string $phone): ?int
    {
        $users = get_users([
            'meta_key' => self::PHONE_META,
            'meta_value' => $phone,
            'number' => 1,
            'fields' => 'ID',
        ]);

        return isset($users[0]) ? (int) $users[0] : null;
    }

    private static function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if (str_starts_with($digits, '0098')) $digits = substr($digits, 4);
        elseif (str_starts_with($digits, '98')) $digits = substr($digits, 2);
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) $digits = '0' . $digits;

        return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
    }
}