<?php
defined('ABSPATH') || exit;

final class BTL_Order_Fulfillment
{
    public static function boot(): void
    {
        add_action('woocommerce_after_order_itemmeta', [self::class, 'render_admin_fields'], 10, 3);
        add_action('wp_ajax_btl_save_cdkey', [self::class, 'ajax_save_cdkey']);
        add_action('wp_ajax_btl_reveal_credential', [self::class, 'ajax_reveal_credential']);
        add_action('admin_footer-post.php', [self::class, 'inline_admin_script']);
    }

    public static function render_admin_fields($item_id, $item, $product): void
    {
        if (!current_user_can('manage_woocommerce') || !$item instanceof WC_Order_Item_Product) return;

        $orderId = $item->get_order_id();
        $deliveryMethod = $item->get_meta('روش تحویل');

        if ($deliveryMethod === 'code') {
            $hasKey = BTL_Secure_Fields::exists($orderId, $item_id, 'cdkey');
            $nonce = wp_create_nonce('btl_cdkey_' . $item_id);
            echo '<div class="btl-box" style="margin-top:8px;padding:8px;border:1px solid #ccd0d4;background:#f8f9fa;">';
            echo '<strong>کد سی‌دی‌کی:</strong> ';
            if ($hasKey) {
                echo '<span style="color:#2271b1;">✓ کد ثبت و رمزنگاری شده است.</span>';
            } else {
                echo '<input type="text" class="btl-cdkey-input" dir="ltr" style="width:220px;" placeholder="کد را وارد کنید" />';
                echo '<button type="button" class="button btl-cdkey-save" data-item="' . esc_attr($item_id) . '" data-order="' . esc_attr($orderId) . '" data-nonce="' . esc_attr($nonce) . '">ذخیره‌ی رمزنگاری‌شده</button>';
            }
            echo '</div>';
        }

        if (in_array($deliveryMethod, ['direct', 'gift'], true)) {
            $nonce = wp_create_nonce('btl_reveal_' . $item_id);
            echo '<div class="btl-box btl-reveal-box" style="margin-top:8px;padding:8px;border:1px solid #ccd0d4;background:#f8f9fa;" data-item="' . esc_attr($item_id) . '" data-order="' . esc_attr($orderId) . '" data-nonce="' . esc_attr($nonce) . '">';
            echo '<button type="button" class="button btl-reveal-btn">نمایش اطلاعات ورود</button>';
            echo '<div class="btl-reveal-result" style="margin-top:6px;font-family:monospace;direction:ltr;"></div>';
            echo '</div>';
        }
    }

    public static function ajax_save_cdkey(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('دسترسی غیرمجاز', 403);

        $itemId  = absint($_POST['item_id'] ?? 0);
        $orderId = absint($_POST['order_id'] ?? 0);
        $key     = sanitize_text_field($_POST['cdkey'] ?? '');

        if (!$itemId || !$orderId || $key === '') wp_send_json_error('ورودی نامعتبر', 400);
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'btl_cdkey_' . $itemId)) wp_send_json_error('نشست نامعتبر', 403);

        $item = WC_Order_Factory::get_order_item($itemId);
        if (!$item || (int)$item->get_order_id() !== $orderId) wp_send_json_error('آیتم نامعتبر', 404);

        $item->update_meta_data('_secure_cdkey', $key);
        $item->save();

        wp_send_json_success(['message' => 'کد با موفقیت رمزنگاری و ذخیره شد.']);
    }

    public static function ajax_reveal_credential(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('دسترسی غیرمجاز', 403);

        $itemId  = absint($_POST['item_id'] ?? 0);
        $orderId = absint($_POST['order_id'] ?? 0);
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'btl_reveal_' . $itemId)) wp_send_json_error('نشست نامعتبر', 403);

        $userId = get_current_user_id();
        $result = [];
        foreach (['email', 'password', 'battletag'] as $type) {
            $value = BTL_Secure_Fields::revealForStaff($orderId, $itemId, $type, $userId);
            if ($value !== null) $result[$type] = $value;
        }

        if (empty($result)) {
            wp_send_json_error('اطلاعاتی یافت نشد (شاید سفارش تکمیل/لغو شده و اطلاعات پاک شده باشد).', 404);
        }

        wp_send_json_success($result);
    }

    public static function inline_admin_script(): void
    {
        $ajaxUrl = admin_url('admin-ajax.php');
        ?>
        <script>
        jQuery(function ($) {
            $(document).on('click', '.btl-cdkey-save', function () {
                var btn = $(this);
                $.post('<?php echo esc_js($ajaxUrl); ?>', {
                    action: 'btl_save_cdkey',
                    item_id: btn.data('item'),
                    order_id: btn.data('order'),
                    nonce: btn.data('nonce'),
                    cdkey: btn.siblings('.btl-cdkey-input').val()
                }, function (res) {
                    if (res.success) location.reload();
                    else alert(res.data);
                });
            });

            $(document).on('click', '.btl-reveal-btn', function () {
                var box = $(this).closest('.btl-reveal-box');
                $.post('<?php echo esc_js($ajaxUrl); ?>', {
                    action: 'btl_reveal_credential',
                    item_id: box.data('item'),
                    order_id: box.data('order'),
                    nonce: box.data('nonce')
                }, function (res) {
                    if (!res.success) { box.find('.btl-reveal-result').text(res.data); return; }
                    var lines = [];
                    if (res.data.email) lines.push('ایمیل: ' + res.data.email);
                    if (res.data.password) lines.push('پسورد: ' + res.data.password);
                    if (res.data.battletag) lines.push('بتل‌تگ: ' + res.data.battletag);
                    box.find('.btl-reveal-result').text(lines.join(' | '));
                });
            });
        });
        </script>
        <?php
    }
}