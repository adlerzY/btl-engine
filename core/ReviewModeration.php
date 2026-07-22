<?php
defined('ABSPATH') || exit;

final class BTL_Review_Moderation
{
    public static function boot(): void
    {
        add_filter('pre_comment_approved', [self::class, 'guard_repeat_approval'], 20, 2);
        add_action('transition_comment_status', [self::class, 'revalidate_on_status_change'], 10, 3);
    }

    public static function guard_repeat_approval($approved, array $commentdata)
    {
        $type = $commentdata['comment_type'] ?? '';
        if (!in_array($type, ['review', 'comment'], true)) {
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

    public static function revalidate_on_status_change($newStatus, $oldStatus, $comment): void
    {
        if (!$comment instanceof WP_Comment || !in_array($comment->comment_type, ['review', 'comment'], true)) {
            return;
        }

        if ($newStatus === $oldStatus) {
            return;
        }

        if ($newStatus !== 'approved' && $oldStatus !== 'approved') {
            return;
        }

        if ($comment->comment_type === 'review') {
            $product = wc_get_product((int)$comment->comment_post_ID);
            if ($product && function_exists('btl_queue_revalidation')) {
                btl_queue_revalidation(["product-{$product->get_slug()}"]);
            }
            return;
        }

        BTL_Cache::delete("post_comments_count_{$comment->comment_post_ID}");

        $slug = get_post_field('post_name', $comment->comment_post_ID);
        if ($slug && function_exists('btl_queue_revalidation')) {
            btl_queue_revalidation(["post-{$slug}"]);
        }
    }
}