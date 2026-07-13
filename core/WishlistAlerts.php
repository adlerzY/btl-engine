<?php
defined('ABSPATH') || exit;

final class BTL_Wishlist_Alerts
{
    private const META_KEY = 'btl_wishlist_price_snapshot';

    public static function boot(): void
    {
        add_action('btl_price_dropped', [self::class, 'notify_watchers'], 10, 3);
    }

    public static function snapshot_on_add(int $userId, int $productId): void
    {
        $product = wc_get_product($productId);
        if (!$product) return;

        $snapshots = get_user_meta($userId, self::META_KEY, true);
        if (!is_array($snapshots)) $snapshots = [];

        $snapshots[$productId] = (float)$product->get_price();
        update_user_meta($userId, self::META_KEY, $snapshots);
    }

    public static function clear_on_remove(int $userId, int $productId): void
    {
        $snapshots = get_user_meta($userId, self::META_KEY, true);
        if (!is_array($snapshots)) return;

        unset($snapshots[$productId]);
        update_user_meta($userId, self::META_KEY, $snapshots);
    }

    public static function notify_watchers(int $variationOrProductId, float $oldPrice, float $newPrice): void
    {
        $product = wc_get_product($variationOrProductId);
        if (!$product) return;

        $parentId = $product->is_type('variation') ? $product->get_parent_id() : $variationOrProductId;

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            self::META_KEY
        ));

        if (!$rows) return;

        foreach ($rows as $row) {
            $snapshots = maybe_unserialize($row->meta_value);
            if (!is_array($snapshots) || empty($snapshots[$parentId])) continue;

            $watchedPrice = (float)$snapshots[$parentId];
            if ($newPrice >= $watchedPrice) continue;

            $productObj = wc_get_product($parentId);
            $name = $productObj ? $productObj->get_name() : 'محصول';

            BTL_Notifications::push(
                (int)$row->user_id,
                'افت قیمت در لیست علاقه‌مندی‌ها 🔻',
                sprintf('قیمت «%s» کاهش یافت. همین حالا بررسی کنید.', $name),
                '/my-account/wishlist'
            );

            $snapshots[$parentId] = $newPrice;
            update_user_meta((int)$row->user_id, self::META_KEY, $snapshots);
        }
    }
}