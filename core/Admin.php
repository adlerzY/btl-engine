<?php

defined('ABSPATH') || exit;

final class BTL_Admin
{
    public static function boot(): void
    {
        add_action(
            'woocommerce_variation_options_pricing',
            [self::class, 'fields'],
            10,
            3
        );

        add_action(
            'woocommerce_save_product_variation',
            [self::class, 'save'],
            10,
            1
        );
    }

    public static function fields($loop, $variation_data, $variation): void
    {
        if (!$variation instanceof WC_Product_Variation) {
            return;
        }

        try {
            woocommerce_wp_text_input([
                'id' => "_gift_price_toman[$loop]",
                'label' => 'Gift Price',
                'value' => (string) get_post_meta($variation->get_id(), '_gift_price_toman', true),
                'wrapper_class' => 'form-row form-row-first'
            ]);

            woocommerce_wp_text_input([
                'id' => "_code_price_toman[$loop]",
                'label' => 'Code Price',
                'value' => (string) get_post_meta($variation->get_id(), '_code_price_toman', true),
                'wrapper_class' => 'form-row form-row-last'
            ]);
        } catch (Throwable $e) {
            BTL_Helpers::logger('Admin::fields failed for variation ' . $variation->get_id() . ': ' . $e->getMessage());
        }
    }

    public static function save(int $variation_id): void
    {
        $product = wc_get_product($variation_id);

        if (!$product) {
            return;
        }

        $index = isset($_POST['variable_post_id'])
            ? array_search($variation_id, array_map('intval', (array) $_POST['variable_post_id']), true)
            : false;

        if ($index === false) {
            return;
        }

        $gift = self::sanitize($_POST['_gift_price_toman'][$index] ?? '');
        $code = self::sanitize($_POST['_code_price_toman'][$index] ?? '');

        $product->update_meta_data('_gift_price_toman', $gift);
        $product->update_meta_data('_code_price_toman', $code);
        $product->save_meta_data();
    }

    private static function sanitize($value): string
    {
        $value = trim(str_replace([',', '،', ' '], '', (string) $value));

        if (strtolower($value) === 'disabled') {
            return 'disabled';
        }

        if (!is_numeric($value)) {
            return '';
        }

        $value = (float) $value;

        if ($value < 0) {
            return '';
        }

        if ($value > 999999999999) {
            return '';
        }

        return (string) round($value);
    }
}