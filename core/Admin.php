<?php

defined('ABSPATH') || exit;

final class BTL_Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'registerSettingsPage']);
        add_action('admin_post_btl_save_pricing_settings', [self::class, 'saveSettings']);
        add_action('admin_post_btl_test_rate_api', [self::class, 'testRateApi']);
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
            $region = BTL_Region_Registry::fromVariation($variation);
            $currency = $region['currency'] ?? 'Region currency';
            $symbol = $region['symbol'] ?? '';
            $rateSource = BTL_Price_Engine::rateSource((string)($region['currency'] ?? ''));

            self::render_text_field([
                'id' => "base_foreign_price[$loop]",
                'label' => sprintf('Base Foreign Price (%s %s)', $currency, $symbol),
                'value' => (string) get_post_meta($variation->get_id(), 'base_foreign_price', true),
                'wrapper_class' => 'form-row form-row-full',
                'description' => sprintf('Direct rate source: %s', $rateSource),
                'desc_tip' => true,
            ]);

            self::render_text_field([
                'id' => "_btl_gift_price[$loop]",
                'label' => sprintf('Gift Price (%s %s)', $currency, $symbol),
                'value' => (string) get_post_meta($variation->get_id(), '_btl_gift_price', true),
                'wrapper_class' => 'form-row form-row-first',
            ]);

            self::render_text_field([
                'id' => "_btl_code_price[$loop]",
                'label' => sprintf('Code Price (%s %s)', $currency, $symbol),
                'value' => (string) get_post_meta($variation->get_id(), '_btl_code_price', true),
                'wrapper_class' => 'form-row form-row-last',
            ]);

            self::render_text_field([
                'id' => "_btl_game_discount[$loop]",
                'label' => 'Game Discount (%)',
                'value' => (string) get_post_meta($variation->get_id(), '_btl_game_discount', true),
                'wrapper_class' => 'form-row form-row-first',
            ]);

            self::render_text_field([
                'id' => "_btl_commission_discount[$loop]",
                'label' => 'Commission Discount (%)',
                'value' => (string) get_post_meta($variation->get_id(), '_btl_commission_discount', true),
                'wrapper_class' => 'form-row form-row-last',
                'description' => 'Discounts only the store commission.',
                'desc_tip' => true,
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

        $gift = self::sanitize($_POST['_btl_gift_price'][$index] ?? '');
        $code = self::sanitize($_POST['_btl_code_price'][$index] ?? '');
        $base = self::sanitize($_POST['base_foreign_price'][$index] ?? '');
        $gameDiscount = self::sanitizePercentage($_POST['_btl_game_discount'][$index] ?? '');
        $commissionDiscount = self::sanitizePercentage($_POST['_btl_commission_discount'][$index] ?? '');

        $oldGift = (string) $product->get_meta('_btl_gift_price');
        $oldCode = (string) $product->get_meta('_btl_code_price');
        $oldBase = (string) $product->get_meta('base_foreign_price');
        $oldGameDiscount = (string) $product->get_meta('_btl_game_discount');
        $oldCommissionDiscount = (string) $product->get_meta('_btl_commission_discount');

        $legacyPending = $product->get_meta('_btl_pricing_legacy_pending');
        $legacyPending = is_array($legacyPending)
            ? array_values(array_intersect(['gift', 'code'], $legacyPending))
            : array_values(array_intersect(['gift', 'code'], array_filter(array_map('trim', explode(',', (string)$legacyPending)))));

        if ($oldGift === $gift && $oldCode === $code && $oldBase === $base
            && $oldGameDiscount === $gameDiscount && $oldCommissionDiscount === $commissionDiscount
            && !$legacyPending) {
            return;
        }

        $product->update_meta_data('_btl_gift_price', $gift);
        $product->update_meta_data('_btl_code_price', $code);
        $product->update_meta_data('base_foreign_price', $base);
        $product->update_meta_data('_btl_game_discount', $gameDiscount);
        $product->update_meta_data('_btl_commission_discount', $commissionDiscount);

        // An explicit variation save confirms the new fields are authoritative,
        // even when their values are intentionally blank/disabled.
        $product->update_meta_data('_btl_pricing_legacy_pending', '');
        $product->save_meta_data();

        $parentId = (int) wp_get_post_parent_id($variation_id);

        if ($parentId && class_exists('BTL_Invalidation')) {
            BTL_Invalidation::queueProduct(
                $parentId,
                BTL_Invalidation::SCOPE_PRICING
            );
        }
    }

    private static function render_text_field(array $args): void
    {
        $id = (string) ($args['id'] ?? '');
        $label = (string) ($args['label'] ?? '');
        $value = (string) ($args['value'] ?? '');
        $wrapperClass = trim((string) ($args['wrapper_class'] ?? ''));
        $description = (string) ($args['description'] ?? '');
        $descTip = !empty($args['desc_tip']);

        if ($id === '') {
            return;
        }

        $fieldId = preg_replace('/[^A-Za-z0-9_:\-\[\]]+/', '', $id);
        $fieldId = $fieldId !== '' ? $fieldId : 'btl_field';
        $classes = trim('short ' . (string) ($args['class'] ?? ''));
        ?>
        <p class="form-field <?php echo esc_attr($fieldId); ?>_field <?php echo esc_attr($wrapperClass); ?>">
            <label for="<?php echo esc_attr($fieldId); ?>"><?php echo esc_html($label); ?></label>
            <input
                type="text"
                class="<?php echo esc_attr($classes); ?>"
                name="<?php echo esc_attr($id); ?>"
                id="<?php echo esc_attr($fieldId); ?>"
                value="<?php echo esc_attr($value); ?>"
            />
            <?php if ($description !== ''): ?>
                <span class="description"<?php echo $descTip ? ' style="display:block; margin-top:4px;"' : ''; ?>><?php echo esc_html($description); ?></span>
            <?php endif; ?>
        </p>
        <?php
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

        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private static function sanitizePercentage($value): string
    {
        $value = self::sanitize($value);
        if ($value === '' || $value === 'disabled') return '';
        return (string) min(100, (float)$value);
    }

    public static function registerSettingsPage(): void
    {
        add_submenu_page(
            'woocommerce',
            'BTL Pricing Engine',
            'Pricing Engine',
            'manage_woocommerce',
            'btl-pricing-engine',
            [self::class, 'renderSettingsPage']
        );
    }

    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');
        $settings = get_option('btl_pricing_settings', []);
        $settings = is_array($settings) ? $settings : [];
        $status = BTL_Rate_Sync::status();
        $rateStatus = BTL_Price_Engine::rateStatus();
        $fields = [
            'usd_manual_fallback_rate' => 'USD manual fallback',
            'eur_manual_fallback_rate' => 'EUR manual fallback',
            'try_manual_fallback_rate' => 'TRY manual fallback',
            'uah_manual_fallback_rate' => 'UAH manual fallback',
        ];
        ?>
        <div class="wrap">
            <h1>BTL Pricing Engine</h1>
            <?php if (isset($_GET['btl_notice'])): ?>
                <div class="notice notice-info"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['btl_notice']))); ?></p></div>
            <?php endif; ?>
            <p><strong>API status:</strong> <?php echo esc_html($status['status'] ?? 'never_tested'); ?></p>
            <p><strong>Last test:</strong> <?php echo !empty($status['checked_at']) ? esc_html(gmdate('Y-m-d H:i:s', (int)$status['checked_at'])) . ' UTC' : 'Never'; ?></p>
            <p><strong>Last successful sync:</strong> <?php echo esc_html((string)get_option('btl_last_rate_sync', 'Never')); ?></p>
            <table class="widefat striped" style="max-width:900px"><thead><tr><th>Currency</th><th>Source</th><th>Rate</th><th>Fetched</th></tr></thead><tbody>
            <?php foreach ($rateStatus as $currency => $rate): ?><tr>
                <td><?php echo esc_html($currency); ?></td>
                <td><?php echo esc_html($rate['source']); ?></td>
                <td><?php echo $rate['rate'] === null ? 'Unavailable' : esc_html((string)$rate['rate']); ?></td>
                <td><?php echo empty($rate['fetchedAt']) ? 'N/A' : esc_html(gmdate('Y-m-d H:i:s', (int)$rate['fetchedAt'])) . ' UTC'; ?></td>
            </tr><?php endforeach; ?>
            </tbody></table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:24px;max-width:700px">
                <input type="hidden" name="action" value="btl_save_pricing_settings">
                <?php wp_nonce_field('btl_save_pricing_settings'); ?>
                <table class="form-table">
                    <tr><th>Global Commission %</th><td><input name="global_commission_percent" type="number" min="0" max="100" step="0.01" value="<?php echo esc_attr($settings['global_commission_percent'] ?? '0'); ?>"></td></tr>
                    <?php foreach ($fields as $name => $label): ?><tr><th><?php echo esc_html($label); ?></th><td><input name="<?php echo esc_attr($name); ?>" type="number" min="0.0001" step="0.0001" value="<?php echo esc_attr($settings[$name] ?? ''); ?>"></td></tr><?php endforeach; ?>
                </table>
                <?php submit_button('Save Pricing Settings'); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="btl_test_rate_api">
                <?php wp_nonce_field('btl_test_rate_api'); ?>
                <?php submit_button('Test Navasan API', 'secondary'); ?>
            </form>
            <p>Credentials are read only from server constants and are never displayed or stored here.</p>
        </div>
        <?php
    }

    public static function saveSettings(): void
    {
        self::authorizeSettings('btl_save_pricing_settings');
        $settings = get_option('btl_pricing_settings', []);
        $settings = is_array($settings) ? $settings : [];
        foreach (['global_commission_percent','usd_manual_fallback_rate','eur_manual_fallback_rate','try_manual_fallback_rate','uah_manual_fallback_rate','rate_sync_interval_hours'] as $key) {
            $value = self::sanitize($_POST[$key] ?? '');
            if ($key === 'global_commission_percent' && $value !== '') $value = (string)min(100, (float)$value);
            $settings[$key] = $value;
        }
        update_option('btl_pricing_settings', $settings);
        wp_safe_redirect(add_query_arg('btl_notice', rawurlencode('Pricing settings saved.'), admin_url('admin.php?page=btl-pricing-engine')));
        exit;
    }

    public static function testRateApi(): void
    {
        self::authorizeSettings('btl_test_rate_api');
        $result = BTL_Rate_Sync::test_now();
        $notice = !empty($result['healthy']) ? 'Healthy: all required rates are valid.' : 'Failed: ' . ($result['status'] ?? 'unknown');
        wp_safe_redirect(add_query_arg('btl_notice', rawurlencode($notice), admin_url('admin.php?page=btl-pricing-engine')));
        exit;
    }

    private static function authorizeSettings(string $action): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');
        check_admin_referer($action);
    }
}