<?php
defined('ABSPATH') || exit;

final class BTL_Avatar_Guard
{
    private const ADMIN_ID_PREFIX = 'admin/';
    private const ADMIN_LEGACY_PREFIX = '/avatars/admin/';

    public static function boot(): void
    {
        add_filter('update_user_metadata', [self::class, 'guard_admin_avatar'], 10, 4);
    }

    public static function guard_admin_avatar($check, $objectId, $metaKey, $metaValue)
    {
        if ($metaKey !== 'btl_avatar_url') {
            return $check;
        }

        $value = (string) $metaValue;
        $isAdminScoped = strpos($value, self::ADMIN_ID_PREFIX) === 0
            || strpos($value, self::ADMIN_LEGACY_PREFIX) === 0;

        if (!$isAdminScoped) {
            return $check;
        }

        if (BTL_Admin_Permissions::get((int)$objectId)) {
            return $check;
        }

        return false;
    }
}