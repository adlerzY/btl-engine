<?php
defined('ABSPATH') || exit;

final class BTL_Review_Moderation
{
    public static function boot(): void
    {
        add_filter('pre_comment_approved', [self::class, 'guard_repeat_approval'], 20, 2);
    }

    public static function guard_repeat_approval($approved, array $commentdata)
    {
        $type = $commentdata['comment_type'] ?? '';
        if ($type !== 'review') {
            return $approved;
        }

        $userId = (int)($commentdata['user_id'] ?? 0);
        if ($userId && user_can($userId, 'manage_woocommerce')) {
            return $approved;
        }

        if ((int)$approved === 1) {
            return 0;
        }

        return $approved;
    }
}