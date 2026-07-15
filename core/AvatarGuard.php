<?php
defined('ABSPATH') || exit;

final class BTL_Avatar_Guard
{
    private const ADMIN_PREFIX = '/avatars/admin/';

    public static function boot(): void
    {
        add_filter('update_user_metadata', [self::class, 'guard_admin_avatar'], 10, 4);
    }

    public static function guard_admin_avatar($check, $objectId, $metaKey, $metaValue)
    {
        if ($metaKey !== 'btl_avatar_url') {
            return $check;
        }

        if (strpos((string)$metaValue, self::ADMIN_PREFIX) !== 0) {
            return $check;
        }

        if (user_can((int)$objectId, 'manage_woocommerce')) {
            return $check;
        }

        return false;
    }
}