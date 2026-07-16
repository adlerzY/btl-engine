<?php
defined('ABSPATH') || exit;

final class BTL_CdKey_Admin
{
    public static function boot(): void
    {
        add_action('woocommerce_variation_options_pricing', [self::class, 'render_stock_box'], 20, 3);
        add_action('wp_ajax_btl_cdkey_bulk_add', [self::class, 'ajax_bulk_add']);
        add_action('admin_footer-post.php', [self::class, 'inline_admin_script']);
    }

    public static function render_stock_box($loop, $variation_data, $variation): void
    {
        if (!current_user_can('manage_woocommerce')) return;

        $variationId = $variation->ID;
        $productId = $variation->post_parent;
        $count = BTL_CdKey_Stock::availableCount($productId, $variationId);
        $nonce = wp_create_nonce('btl_cdkey_stock_' . $variationId);

        echo '<div class="form-row form-row-full btl-cdkey-stock-box" style="margin-top:10px;padding:10px;border:1px solid #ccd0d4;background:#f8f9fa;" data-variation="' . esc_attr($variationId) . '" data-product="' . esc_attr($productId) . '" data-nonce="' . esc_attr($nonce) . '">';
        echo '<strong>موجودی کد سی‌دی‌کی: <span class="btl-cdkey-count">' . esc_html($count) . '</span> عدد</strong>';
        echo '<textarea class="btl-cdkey-bulk-input" rows="4" style="width:100%;margin-top:6px;" placeholder="هر کد را در یک خط جداگانه وارد کنید"></textarea>';
        echo '<button type="button" class="button btl-cdkey-bulk-save">افزودن کدها به موجودی</button> ';
        echo '<span class="btl-cdkey-bulk-result"></span>';
        echo '</div>';
    }

    public static function ajax_bulk_add(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('دسترسی غیرمجاز', 403);

        $variationId = absint($_POST['variation_id'] ?? 0);
        $productId = absint($_POST['product_id'] ?? 0);
        $raw = (string)($_POST['keys'] ?? '');

        if (!$variationId || !$productId || trim($raw) === '') wp_send_json_error('ورودی نامعتبر', 400);
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'btl_cdkey_stock_' . $variationId)) wp_send_json_error('نشست نامعتبر', 403);

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $sanitized = array_map('sanitize_text_field', $lines);

        $added = BTL_CdKey_Stock::bulkAdd($productId, $variationId, $sanitized, get_current_user_id());
        $newCount = BTL_CdKey_Stock::availableCount($productId, $variationId);

        wp_send_json_success(['added' => $added, 'count' => $newCount]);
    }

    public static function inline_admin_script(): void
    {
        global $post;
        if (!$post || $post->post_type !== 'product') return;
        $ajaxUrl = admin_url('admin-ajax.php');
        ?>
        <script>
        jQuery(function ($) {
            $(document).on('click', '.btl-cdkey-bulk-save', function () {
                var box = $(this).closest('.btl-cdkey-stock-box');
                var btn = $(this);
                var keys = box.find('.btl-cdkey-bulk-input').val();
                if (!keys.trim()) return;
                btn.prop('disabled', true);
                $.post('<?php echo esc_js($ajaxUrl); ?>', {
                    action: 'btl_cdkey_bulk_add',
                    variation_id: box.data('variation'),
                    product_id: box.data('product'),
                    nonce: box.data('nonce'),
                    keys: keys
                }, function (res) {
                    btn.prop('disabled', false);
                    if (res.success) {
                        box.find('.btl-cdkey-count').text(res.data.count);
                        box.find('.btl-cdkey-bulk-input').val('');
                        box.find('.btl-cdkey-bulk-result').text('✓ ' + res.data.added + ' کد اضافه شد').css('color', '#2271b1');
                    } else {
                        box.find('.btl-cdkey-bulk-result').text(res.data).css('color', '#d63638');
                    }
                });
            });
        });
        </script>
        <?php
    }
}