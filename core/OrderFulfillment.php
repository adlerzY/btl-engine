<?php
defined('ABSPATH') || exit;

final class BTL_Order_Fulfillment
{
    private const FULFILLMENT_LABELS = [
        'queued' => 'در صف انجام',
        'logging_in' => 'در حال ورود به اکانت',
        'processing' => 'در حال واریز/انجام',
        'completed' => 'تکمیل شده',
    ];

    public static function boot(): void
    {
        add_action('woocommerce_after_order_itemmeta', [self::class, 'render_admin_fields'], 10, 3);
        add_action('wp_ajax_btl_save_cdkey', [self::class, 'ajax_save_cdkey']);
        add_action('wp_ajax_btl_reveal_credential', [self::class, 'ajax_reveal_credential']);
        add_action('wp_ajax_btl_update_fulfillment_status', [self::class, 'ajax_update_fulfillment_status']);
        add_action('admin_footer-post.php', [self::class, 'inline_admin_script']);
    }

    public static function render_admin_fields($item_id, $item, $product): void
    {
        if (!current_user_can('manage_woocommerce') || !$item instanceof WC_Order_Item_Product) return;

        $orderId = $item->get_order_id();
        $deliveryMethod = $item->get_meta('روش تحویل');

        if ($deliveryMethod === 'code') {
            $needed = max(1, (int)$item->get_quantity());
            $assigned = BTL_Secure_Fields::countByOrderItem($orderId, $item_id, 'cdkey');
            $nonce = wp_create_nonce('btl_cdkey_' . $item_id);
            echo '<div class="btl-box" style="margin-top:8px;padding:8px;border:1px solid #ccd0d4;background:#f8f9fa;">';
            echo '<strong>کد سی‌دی‌کی:</strong> ';
            if ($assigned >= $needed) {
                echo '<span style="color:#2271b1;">✓ ' . esc_html((string)$assigned) . ' از ' . esc_html((string)$needed) . ' کد ثبت و رمزنگاری شده است.</span>';
            } else {
                echo '<span style="color:#d63638;">' . esc_html((string)$assigned) . ' از ' . esc_html((string)$needed) . ' کد تخصیص یافته.</span> ';
                echo '<input type="text" class="btl-cdkey-input" dir="ltr" style="width:220px;" placeholder="کد را وارد کنید" />';
                echo '<button type="button" class="button btl-cdkey-save" data-item="' . esc_attr($item_id) . '" data-order="' . esc_attr($orderId) . '" data-nonce="' . esc_attr($nonce) . '">افزودن کد</button>';
            }
            echo '</div>';
        }

        if (in_array($deliveryMethod, ['direct', 'gift'], true)) {
            $nonce = wp_create_nonce('btl_reveal_' . $item_id);
            echo '<div class="btl-box btl-reveal-box" style="margin-top:8px;padding:8px;border:1px solid #ccd0d4;background:#f8f9fa;" data-item="' . esc_attr($item_id) . '" data-order="' . esc_attr($orderId) . '" data-nonce="' . esc_attr($nonce) . '">';
            echo '<button type="button" class="button btl-reveal-btn">نمایش اطلاعات ورود</button>';
            echo '<div class="btl-reveal-result" style="margin-top:6px;font-family:monospace;direction:ltr;"></div>';
            echo '</div>';

            $fulfillmentNonce = wp_create_nonce('btl_fulfillment_' . $item_id);
            $currentStatus = $item->get_meta('_fulfillment_status') ?: 'queued';

            echo '<div class="btl-box btl-fulfillment-box" style="margin-top:8px;padding:8px;border:1px solid #ccd0d4;background:#f8f9fa;" data-item="' . esc_attr($item_id) . '" data-order="' . esc_attr($orderId) . '" data-nonce="' . esc_attr($fulfillmentNonce) . '">';
            echo '<strong>وضعیت تحویل:</strong> ';
            echo '<select class="btl-fulfillment-select">';
            foreach (self::FULFILLMENT_LABELS as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($currentStatus, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select> ';
            echo '<button type="button" class="button btl-fulfillment-save">ذخیره وضعیت</button> ';
            echo '<span class="btl-fulfillment-result"></span>';
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

    public static function ajax_update_fulfillment_status(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('دسترسی غیرمجاز', 403);

        $itemId  = absint($_POST['item_id'] ?? 0);
        $orderId = absint($_POST['order_id'] ?? 0);
        $status  = sanitize_text_field($_POST['status'] ?? '');

        if (!$itemId || !$orderId || !isset(self::FULFILLMENT_LABELS[$status])) {
            wp_send_json_error('ورودی نامعتبر', 400);
        }
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'btl_fulfillment_' . $itemId)) {
            wp_send_json_error('نشست نامعتبر', 403);
        }

        $item = WC_Order_Factory::get_order_item($itemId);
        if (!$item || (int)$item->get_order_id() !== $orderId) {
            wp_send_json_error('آیتم نامعتبر', 404);
        }

        $previousStatus = $item->get_meta('_fulfillment_status') ?: 'queued';

        $item->update_meta_data('_fulfillment_status', $status);
        $item->save();

        if ($status === 'completed' && $previousStatus !== 'completed') {
            $order = wc_get_order($orderId);
            $customerId = $order ? (int)$order->get_customer_id() : 0;
            if ($customerId) {
                BTL_Notifications::push(
                    $customerId,
                    'تحویل سفارش شما تکمیل شد ✅',
                    sprintf('آیتم «%s» از سفارش شما آماده و تحویل داده شد.', $item->get_name()),
                    '/my-account/orders',
                    'order'
                );
            }
        }

        wp_send_json_success(['status' => $status]);
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

            $(document).on('click', '.btl-fulfillment-save', function () {
                var box = $(this).closest('.btl-fulfillment-box');
                var btn = $(this);
                var status = box.find('.btl-fulfillment-select').val();
                btn.prop('disabled', true);
                $.post('<?php echo esc_js($ajaxUrl); ?>', {
                    action: 'btl_update_fulfillment_status',
                    item_id: box.data('item'),
                    order_id: box.data('order'),
                    nonce: box.data('nonce'),
                    status: status
                }, function (res) {
                    btn.prop('disabled', false);
                    if (res.success) {
                        box.find('.btl-fulfillment-result').text('✓ ذخیره شد').css('color', '#2271b1');
                    } else {
                        box.find('.btl-fulfillment-result').text(res.data).css('color', '#d63638');
                    }
                });
            });
        });
        </script>
        <?php
    }
}