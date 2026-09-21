<?php

defined('ABSPATH') || exit;

final class BTL_Price_Engine
{
    private static array $running = [];
    private static ?array $memoryRates = null;

    public static function boot(): void
    {
        add_action(
            'woocommerce_process_product_meta',
            [self::class, 'handle_product_update'],
            99
        );

        add_action(
            'woocommerce_save_product_variation',
            [self::class, 'handle_product_update'],
            99
        );
    }

    public static function handle_product_update(
        int $product_id
    ): void {
        self::calculate(
            $product_id,
            true
        );
    }

    public static function calculate(
        int $product_id,
        bool $notify = true,
        ?array $currencies = null
    ): bool {
        $pinned_id = 0;

        try {
            $product = wc_get_product(
                $product_id
            );

            if (!$product) {
                return false;
            }

            if ($product->is_type('variation')) {
                $product_id =
                    $product->get_parent_id();

                $product = wc_get_product(
                    $product_id
                );

                if (!$product) {
                    return false;
                }
            }

            if (isset(self::$running[$product_id])) {
                return false;
            }

            $guard_id = $product_id;
            self::$running[$guard_id] = true;

            $rates = self::rates();

            if (class_exists('BTL_Invalidation')) {
                $pinned_id = $product_id;

                BTL_Invalidation::pin_scope(
                    $pinned_id,
                    BTL_Invalidation::SCOPE_PRICING
                );
            }

            $currencies = self::normalize_currencies($currencies);

            $items = $product->is_type('variable')
                ? $product->get_children()
                : [$product_id];

            if ($currencies !== null && $product->is_type('variable')) {
                $items = array_values(array_filter(
                    $items,
                    static function ($item_id) use ($currencies): bool {
                        $item = wc_get_product((int)$item_id);
                        $currency = $item ? self::variationCurrency($item) : null;
                        return in_array($currency, $currencies, true);
                    }
                ));
            }

            $changed = false;

            foreach ($items as $item_id) {
                $variation = wc_get_product(
                    $item_id
                );

                if (!$variation) {
                    continue;
                }

                if (
                    $currencies !== null &&
                    !in_array(
                        self::variationCurrency($variation),
                        $currencies,
                        true
                    )
                ) {
                    continue;
                }

                if (
                    self::process_variation(
                        $variation,
                        $rates
                    )
                ) {
                    $changed = true;
                }
            }

            if (
                $changed &&
                $product->is_type('variable')
            ) {
                WC_Product_Variable::sync(
                    $product_id
                );
            }

            if ($changed) {
                clean_post_cache($product_id);

                // Skipped inside a batch: the scheduler flushes the product
                // transient group once per worker instead of once per product.
                if (
                    function_exists('wc_delete_product_transients')
                    && !(
                        class_exists('BTL_Invalidation')
                        && is_callable(['BTL_Invalidation', 'is_suspended'])
                        && BTL_Invalidation::is_suspended()
                    )
                ) {
                    wc_delete_product_transients($product_id);
                }

                wp_cache_delete(
                    "variations_{$product_id}",
                    'btl'
                );
            }

            if (
                $changed &&
                $notify &&
                class_exists('BTL_Invalidation')
            ) {
                BTL_Invalidation::queueProduct(
                    $product_id,
                    BTL_Invalidation::SCOPE_PRICING
                );
            }

            return $changed;

        } finally {
            if (
                $pinned_id &&
                class_exists('BTL_Invalidation')
            ) {
                BTL_Invalidation::unpin_scope($pinned_id);
            }

            unset(
                self::$running[$guard_id]
            );
        }
    }
    private static function normalize_currencies(?array $currencies): ?array
    {
        if ($currencies === null) {
            return null;
        }

        $currencies = array_values(array_unique(array_filter(
            array_map(
                static fn($value): string => strtoupper(trim((string) $value)),
                $currencies
            ),
            static fn(string $value): bool => $value !== ''
        )));

        return $currencies;
    }

    private static function process_variation(
        WC_Product $variation,
        array $rates
    ): bool {
        $region = BTL_Region_Registry::fromVariation($variation);
        $currency = $region['currency'] ?? null;
        if ($region === null || $currency === null) {
            // Region is the sole source of truth for pricing currency.
            // Never guess currency from legacy metadata.
            return false;
        }
        $rate = $currency !== null && isset($rates[$currency]) ? (float)$rates[$currency] : null;
        $base = self::priceValue($variation->get_meta('base_foreign_price'));
        $gameDiscountRaw = $variation->get_meta('_btl_game_discount');
        $gameDiscount = $gameDiscountRaw === ''
            ? self::legacyGameDiscount($variation, $base)
            : self::percentage($gameDiscountRaw);
        $commissionDiscount = self::percentage($variation->get_meta('_btl_commission_discount'));
        $globalCommission = self::globalCommission();

        $regularPrice = null;
        $activePrice = null;
        if ($base !== null && $rate !== null && $rate > 0) {
            $converted = $base * $rate;
            $commission = $converted * ($globalCommission / 100);
            $effectiveCommission = $commission * (1 - ($commissionDiscount / 100));
            $regularPrice = (int) round($converted + $effectiveCommission);
            $activePrice = self::applyDiscount($regularPrice, $gameDiscount);
        }

        $dirty = self::sync_prices(
            $variation,
            $regularPrice,
            $regularPrice !== null && $activePrice !== $regularPrice ? $activePrice : null,
            $activePrice
        );

        $dirty |= self::syncDelivery($variation, 'gift', $gameDiscount, $rate);
        $dirty |= self::syncDelivery($variation, 'code', $gameDiscount, $rate);
        $dirty |= self::meta($variation, '_btl_pricing_region', $region['region'] ?? '');
        $dirty |= self::meta($variation, '_btl_pricing_currency', $currency ?? '');
        $dirty |= self::meta($variation, '_btl_rate_source', $currency !== null ? self::rateSource($currency) : 'unavailable');

        if ($dirty) {
            $variation->save();
            return true;
        }

        return false;
    }

    private static function variationCurrency(WC_Product $variation): ?string
    {
        $region = BTL_Region_Registry::fromVariation($variation);
        return $region !== null && !empty($region['currency'])
            ? strtoupper((string)$region['currency'])
            : null;
    }

    private static function sync_prices(
        WC_Product $product,
        ?int $regular,
        ?int $sale,
        ?int $active
    ): bool {
        $dirty = false;
        $regularValue = $regular === null ? '' : (string)$regular;
        $activeValue = $active === null ? '' : (string)$active;

        if ((string)$product->get_regular_price() !== $regularValue) {
            $product->set_regular_price($regularValue);
            $dirty = true;
        }

        $current_sale =
            (string)$product->get_sale_price();

        if ($sale === null) {
            if ($current_sale !== '') {
                $product->set_sale_price('');
                $dirty = true;
            }
        } elseif (
            $current_sale === '' ||
            (float)$current_sale !== (float)$sale
        ) {
            $product->set_sale_price(
                (string)$sale
            );
            $dirty = true;
        }

        if ((string)$product->get_price() !== $activeValue) {
            $product->set_price($activeValue);
            $dirty = true;
        }


        return $dirty;
    }

    private static function syncDelivery(WC_Product $product, string $method, float $gameDiscount, ?float $rate): bool
    {
        $newKey = $method === 'gift' ? '_btl_gift_price' : '_btl_code_price';
        $finalKey = $method === 'gift' ? '_btl_gift_final_price' : '_btl_code_final_price';
        $regularKey = $method === 'gift' ? '_btl_gift_regular_price' : '_btl_code_regular_price';

        $rawNewPrice = $product->get_meta($newKey);
        $price = self::priceValue($rawNewPrice);

        if ($price === null && trim((string)$rawNewPrice) === '') {
            $pending = self::legacyPendingMethods($product);
            if (in_array($method, $pending, true)) {
                $legacyKey = $method === 'gift' ? '_gift_price_toman' : '_code_price_toman';
                $legacyPrice = self::priceValue($product->get_meta($legacyKey));
                if ($legacyPrice !== null) {
                    $price = $legacyPrice;
                }
            }
        }

        if ($price === null) {
            return self::meta($product, $finalKey, 'disabled')
                | self::meta($product, $regularKey, 'disabled');
        }

        $regular = (int) round($price);
        return self::meta($product, $finalKey, self::applyDiscount($regular, $gameDiscount))
            | self::meta($product, $regularKey, $regular);
    }

    private static function applyDiscount(float $price, float $percent): int
    {
        return (int) round(max(0, $price * (1 - ($percent / 100))));
    }

    private static function percentage($value): float
    {
        $number = self::priceValue($value);
        return $number === null ? 0.0 : max(0.0, min(100.0, $number));
    }

    private static function globalCommission(): float
    {
        $settings = get_option('btl_pricing_settings', []);
        if (!is_array($settings)) return 0.0;
        return self::percentage($settings['global_commission_percent'] ?? $settings['btl_global_commission_percent'] ?? 0);
    }

    private static function legacyGameDiscount(WC_Product $variation, ?float $base): float
    {
        if ($base === null || $base <= 0) return 0.0;
        $priority = self::priceValue($variation->get_meta('priority_foreign_sale_price'));
        $normal = self::priceValue($variation->get_meta('base_foreign_sale_price'));
        $sale = $priority !== null && $priority > 0 ? $priority : $normal;
        if ($sale === null || $sale < 0 || $sale >= $base) return 0.0;

        $now = current_time('timestamp', true);
        $from = (int)$variation->get_meta('foreign_sale_price_dates_from');
        $to = (int)$variation->get_meta('foreign_sale_price_dates_to');
        if (($from > 0 && $now < $from) || ($to > 0 && $now > $to)) return 0.0;

        return max(0.0, min(100.0, (($base - $sale) / $base) * 100));
    }

    /**
     * Empty/disabled means unavailable. Numeric zero is deliberately valid/free.
     */
    public static function priceValue($value): ?float
    {
        if ($value === null || $value === false) return null;
        $value = trim((string)$value);
        if ($value === '' || strtolower($value) === 'disabled' || strtolower($value) === 'no') return null;
        $value = strtr($value, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '،'=>',',
        ]);
        $value = str_replace([',', ' '], '', $value);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $value)) return null;
        $number = (float)$value;
        return is_finite($number) && $number >= 0 ? $number : null;
    }

    private static function legacyPendingMethods(WC_Product $product): array
    {
        $raw = $product->get_meta('_btl_pricing_legacy_pending');
        if (is_array($raw)) return array_values(array_intersect(['gift', 'code'], $raw));
        $parts = array_filter(array_map('trim', explode(',', (string)$raw)));
        return array_values(array_intersect(['gift', 'code'], $parts));
    }

    private static function meta(
        WC_Product $product,
        string $key,
        $value
    ): bool {
        $current =
            $product->get_meta(
                $key
            );

        if (
            (string)$current ===
            (string)$value
        ) {
            return false;
        }

        $product->update_meta_data(
            $key,
            $value
        );

        return true;
    }

    public static function resolveDeliveryPrice(WC_Product $product, string $deliveryMethod): ?float
    {
        if ($deliveryMethod === 'gift') {
            return self::readFinalMeta($product, '_btl_gift_final_price');
        }

        if ($deliveryMethod === 'code') {
            return self::readFinalMeta($product, '_btl_code_final_price');
        }

        $price = $product->get_price();
        if ($price === '' || $price === null) {
            return null;
        }

        return (float)$price;
    }

    private static function readFinalMeta(WC_Product $product, string $finalKey): ?float
    {
        $final = $product->get_meta($finalKey);
        return self::priceValue($final);
    }

    public static function rates(): array
    {
        if (self::$memoryRates !== null) {
            return self::$memoryRates;
        }

        $cached = wp_cache_get('rates', 'btl');
        if ($cached !== false && is_array($cached)) {
            self::$memoryRates = $cached;
            return self::$memoryRates;
        }

        $rates = [];
        foreach (['USD', 'EUR', 'TRY', 'UAH', 'USD_R', 'EUR_R'] as $currency) {
            $resolved = self::resolveRate($currency);
            if ($resolved['rate'] !== null) $rates[$currency] = $resolved['rate'];
        }

        wp_cache_set('rates', $rates, 'btl', 60);
        self::$memoryRates = $rates;

        return self::$memoryRates;
    }

    public static function rateSource(string $currency): string
    {
        return self::resolveRate(strtoupper($currency))['source'];
    }

    public static function rateStatus(): array
    {
        $result = [];
        foreach (['USD', 'EUR', 'TRY', 'UAH', 'USD_R', 'EUR_R'] as $currency) {
            $result[$currency] = self::resolveRate($currency);
        }
        return $result;
    }

    private static function resolveRate(string $currency): array
    {
        $now = time();
        $liveTtl = defined('BTL_RATE_LIVE_TTL') ? max(300, (int)BTL_RATE_LIVE_TTL) : 12 * HOUR_IN_SECONDS;
        $lkgTtl = defined('BTL_RATE_LKG_TTL') ? max($liveTtl, (int)BTL_RATE_LKG_TTL) : 7 * DAY_IN_SECONDS;

        $live = get_option('btl_live_exchange_rates', []);
        $liveRate = self::rateFromRecord($live, $currency, $now, $liveTtl);
        if ($liveRate !== null) return ['rate' => $liveRate, 'source' => 'live', 'fetchedAt' => (int)$live['fetched_at']];

        $last = get_option('btl_last_successful_exchange_rates', []);
        $lastRate = self::rateFromRecord($last, $currency, $now, $lkgTtl);
        if ($lastRate !== null) return ['rate' => $lastRate, 'source' => 'last_successful', 'fetchedAt' => (int)$last['fetched_at']];

        $settings = get_option('btl_pricing_settings', []);
        $fields = [
            'USD'=>'usd_manual_fallback_rate', 'EUR'=>'eur_manual_fallback_rate',
            'TRY'=>'try_manual_fallback_rate', 'UAH'=>'uah_manual_fallback_rate',
            'USD_R'=>'usd_manual_fallback_rate', 'EUR_R'=>'eur_manual_fallback_rate',
        ];
        $manual = is_array($settings) ? self::priceValue($settings[$fields[$currency] ?? ''] ?? null) : null;
        if ($manual !== null && $manual > 0) return ['rate' => $manual, 'source' => 'manual_fallback', 'fetchedAt' => null];

        return ['rate' => null, 'source' => 'unavailable', 'fetchedAt' => null];
    }

    private static function rateFromRecord($record, string $currency, int $now, int $ttl): ?float
    {
        if (!is_array($record) || !isset($record['rates']) || !is_array($record['rates'])) return null;
        $fetchedAt = (int)($record['currency_fetched_at'][$currency] ?? $record['fetched_at'] ?? 0);
        if ($fetchedAt < 1 || ($now - $fetchedAt) > $ttl) return null;
        $rate = self::priceValue($record['rates'][$currency] ?? null);
        return $rate !== null && $rate > 0 ? $rate : null;
    }

    public static function clearMemoryRates(): void
    {
        self::$memoryRates = null;
    }

    public static function money(
        $value
    ): float {
        return (float)
            str_replace(
                [',', ' ', '،'],
                '',
                (string)$value
            );
    }
}