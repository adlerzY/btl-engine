<?php

defined('ABSPATH') || exit;

final class BTL_Credentials_Auth
{
    private const MANUAL_PASSWORD_META = 'btl_has_manual_password';

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }

    public static function register(): void
    {
        register_graphql_field('User', 'hasManualPassword', [
            'type' => 'Boolean',
            'resolve' => static function ($user) {
                $currentUserId = get_current_user_id();
                if (!$currentUserId || $currentUserId !== (int) $user->databaseId) {
                    return null;
                }
                return (bool) get_user_meta($currentUserId, self::MANUAL_PASSWORD_META, true);
            },
        ]);

        register_graphql_mutation('loginWithPassword', [
            'inputFields' => [
                'identifier' => ['type' => ['non_null' => 'String']],
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
                $rawIdentifier = trim((string) $input['identifier']);
                if ($rawIdentifier === '') {
                    throw new GraphQL\Error\UserError('شماره موبایل یا ایمیل را وارد کنید.');
                }

                $throttleKey = mb_strtolower($rawIdentifier);
                $ip = BTL_Helpers::clientIp();
                BTL_Login_Throttle::assertAllowed($throttleKey, $ip);

                $user = self::findUserByIdentifier($rawIdentifier);
                $isValid = false;

                if ($user) {
                    $isValid = wp_check_password((string) $input['password'], $user->user_pass, $user->ID);
                } else {
                    wp_check_password((string) $input['password'], '$P$Bnothinghere.nothinghere.nothing0', 0);
                }

                if (!$user || !$isValid) {
                    BTL_Login_Throttle::recordAttempt($throttleKey, $ip);
                    throw new GraphQL\Error\UserError('شماره موبایل/ایمیل یا رمز عبور اشتباه است.');
                }

                BTL_Login_Throttle::clearAttempts($throttleKey);

                if (user_can($user->ID, 'manage_woocommerce')) {
                    $ticket = BTL_Admin_Totp::issuePendingTicket($user->ID);

                    if (!BTL_Admin_Totp::isConfigured($user->ID)) {
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

                $tokens = BTL_Phone_Auth::issueTokens($user);

                return [
                    'authToken' => $tokens['authToken'],
                    'refreshToken' => $tokens['refreshToken'],
                    'requiresAdminTotp' => false,
                    'requiresAdminTotpSetup' => false,
                    'pendingTicket' => null,
                ];
            },
        ]);

        register_graphql_mutation('setPassword', [
            'inputFields' => [
                'currentPassword' => ['type' => 'String'],
                'newPassword' => ['type' => ['non_null' => 'String']],
                'sessionId' => ['type' => 'String'],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                }

                $userId = get_current_user_id();
                $user = get_userdata($userId);
                if (!$user) {
                    throw new GraphQL\Error\UserError('کاربر یافت نشد.');
                }

                $hasManual = (bool) get_user_meta($userId, self::MANUAL_PASSWORD_META, true);

                if ($hasManual) {
                    $current = (string) ($input['currentPassword'] ?? '');
                    if ($current === '' || !wp_check_password($current, $user->user_pass, $userId)) {
                        throw new GraphQL\Error\UserError('رمز عبور فعلی صحیح نیست.');
                    }
                }

                self::validatePasswordStrength((string) $input['newPassword']);

                wp_set_password((string) $input['newPassword'], $userId);
                update_user_meta($userId, self::MANUAL_PASSWORD_META, 1);

                BTL_Sessions::revokeAllExcept($userId, $input['sessionId'] ?? null);

                BTL_Notifications::push(
                    $userId,
                    'رمز عبور حساب شما تغییر کرد 🔐',
                    'اگر این تغییر توسط شما انجام نشده، فوراً از طریق تیکت پشتیبانی با ما در ارتباط باشید.',
                    '/my-account/settings',
                    'account'
                );

                return ['success' => true];
            },
        ]);
    }

    public static function findUserByIdentifier(string $identifier): ?WP_User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (is_email($identifier)) {
            $user = get_user_by('email', $identifier);
            return $user instanceof WP_User ? $user : null;
        }

        $phone = BTL_Phone_Auth::normalizePhone($identifier);
        if (!$phone) {
            return null;
        }

        $userId = BTL_Phone_Auth::findUserByPhone($phone);
        return $userId ? (get_userdata($userId) ?: null) : null;
    }

    public static function validatePasswordStrength(string $password): void
    {
        if (mb_strlen($password) < 8) {
            throw new GraphQL\Error\UserError('رمز عبور باید حداقل ۸ کاراکتر باشد.');
        }
        if (mb_strlen($password) > 100) {
            throw new GraphQL\Error\UserError('رمز عبور بیش از حد طولانی است.');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new GraphQL\Error\UserError('رمز عبور باید ترکیبی از حروف انگلیسی و عدد باشد.');
        }
    }
}