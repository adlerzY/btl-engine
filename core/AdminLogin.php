<?php
// core/AdminLogin.php
defined('ABSPATH') || exit;

final class BTL_Admin_Login
{
    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }

    public static function register(): void
    {
        register_graphql_mutation('loginWithUsernamePassword', [
            'inputFields' => [
                'username' => ['type' => ['non_null' => 'String']],
                'password' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'requiresAdminTotp' => ['type' => 'Boolean'],
                'requiresAdminTotpSetup' => ['type' => 'Boolean'],
                'pendingTicket' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => function ($input) {
                $login = sanitize_text_field($input['username']);
                $identifier = strtolower($login);
                $ip = BTL_Helpers::clientIp();

                BTL_Login_Throttle::assertAllowed($identifier, $ip);

                $user = is_email($login) ? get_user_by('email', $login) : get_user_by('login', $login);

                if (!$user || !wp_check_password($input['password'], $user->user_pass, $user->ID)) {
                    BTL_Login_Throttle::recordAttempt($identifier, $ip);
                    throw new GraphQL\Error\UserError('نام کاربری یا رمز عبور اشتباه است.');
                }

                if (!user_can($user->ID, 'manage_woocommerce')) {
                    BTL_Login_Throttle::recordAttempt($identifier, $ip);
                    throw new GraphQL\Error\UserError('این مسیر ورود فقط برای اعضای تیم پشتیبانی است.');
                }

                BTL_Login_Throttle::clearAttempts($identifier);

                $ticket = BTL_Admin_Totp::issuePendingTicket($user->ID);

                if (!BTL_Admin_Totp::isConfigured($user->ID)) {
                    return [
                        'requiresAdminTotp' => false,
                        'requiresAdminTotpSetup' => true,
                        'pendingTicket' => $ticket,
                    ];
                }

                return [
                    'requiresAdminTotp' => true,
                    'requiresAdminTotpSetup' => false,
                    'pendingTicket' => $ticket,
                ];
            },
        ]);
    }
}