<?php

defined('ABSPATH') || exit;

final class BTL_Password_Reset
{
    private const PURPOSE = 'password_reset';

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }

    public static function register(): void
    {
        register_graphql_mutation('requestPasswordReset', [
            'inputFields' => [
                'identifier' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'channel' => ['type' => 'String'],
                'cooldownSeconds' => ['type' => 'Int'],
            ],
            'mutateAndGetPayload' => function ($input) {
                $raw = trim((string) $input['identifier']);
                if ($raw === '') {
                    throw new GraphQL\Error\UserError('شماره موبایل یا ایمیل را وارد کنید.');
                }

                $isEmail = is_email($raw);
                $channel = $isEmail ? 'email' : 'sms';

                if (!$isEmail && !BTL_Phone_Auth::normalizePhone($raw)) {
                    throw new GraphQL\Error\UserError('شماره موبایل یا ایمیل نامعتبر است.');
                }

                $user = BTL_Credentials_Auth::findUserByIdentifier($raw);

                if (!$user) {
                    return ['success' => true, 'channel' => $channel, 'cooldownSeconds' => 60];
                }

                $ip = BTL_Helpers::clientIp();
                $identifierKey = $isEmail ? mb_strtolower($raw) : BTL_Phone_Auth::normalizePhone($raw);

                BTL_Otp::request($identifierKey, $channel, self::PURPOSE, $ip, static function (string $code) use ($isEmail, $raw, $user) {
                    if ($isEmail) {
                        return BTL_Email_Gateway::sendPasswordResetCode($raw, $user->display_name, $code);
                    }
                    $gateway = new BTL_NirSms_Gateway();
                    return $gateway->sendOtp($raw, $code);
                });

                return ['success' => true, 'channel' => $channel, 'cooldownSeconds' => 60];
            },
        ]);

        register_graphql_mutation('resetPassword', [
            'inputFields' => [
                'identifier' => ['type' => ['non_null' => 'String']],
                'code' => ['type' => ['non_null' => 'String']],
                'newPassword' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'authToken' => ['type' => 'String'],
                'refreshToken' => ['type' => 'String'],
                'requiresLogin' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => function ($input) {
                $raw = trim((string) $input['identifier']);
                $isEmail = is_email($raw);
                $identifierKey = $isEmail ? mb_strtolower($raw) : BTL_Phone_Auth::normalizePhone($raw);

                if (!$identifierKey) {
                    throw new GraphQL\Error\UserError('شماره موبایل یا ایمیل نامعتبر است.');
                }

                $otpRowId = BTL_Otp::validate($identifierKey, self::PURPOSE, sanitize_text_field($input['code']));

                $user = BTL_Credentials_Auth::findUserByIdentifier($raw);
                if (!$user) {
                    throw new GraphQL\Error\UserError('کاربری با این مشخصات یافت نشد.');
                }

                BTL_Credentials_Auth::validatePasswordStrength((string) $input['newPassword']);

                wp_set_password((string) $input['newPassword'], $user->ID);
                update_user_meta($user->ID, 'btl_has_manual_password', 1);

                BTL_Otp::consume($otpRowId);
                BTL_Sessions::revokeAll($user->ID);

                $isStaff = user_can($user->ID, 'manage_woocommerce');

                BTL_Notifications::push(
                    $user->ID,
                    'رمز عبور حساب شما بازیابی شد 🔐',
                    'اگر این تغییر توسط شما انجام نشده، فوراً از طریق تیکت پشتیبانی با ما در ارتباط باشید.',
                    '/my-account/settings',
                    'account'
                );

                if ($isStaff) {
                    BTL_Helpers::logger("PasswordReset: staff account #{$user->ID} password was reset via {$identifierKey}");
                    return ['success' => true, 'authToken' => null, 'refreshToken' => null, 'requiresLogin' => true];
                }

                $tokens = BTL_Phone_Auth::issueTokens($user);

                return [
                    'success' => true,
                    'authToken' => $tokens['authToken'],
                    'refreshToken' => $tokens['refreshToken'],
                    'requiresLogin' => false,
                ];
            },
        ]);
    }
}