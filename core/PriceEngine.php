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

            if (!$rates) {
                return false;
            }
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
                        $currency = strtoupper((string) get_post_meta((int) $item_id, 'base_currency_type', true));
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
                        strtoupper((string) $variation->get_meta('base_currency_type')),
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
        $currency =
            $variation->get_meta(
                'base_currency_type'
            );

        if (
            empty($rates[$currency])
        ) {
            return false;
        }

        $rate = $rates[$currency];

        $base = self::money(
            $variation->get_meta(
                'base_foreign_price'
            )
        );

        $regular_price =
            round($base * $rate);

        $sale_price = null;

        $priority_sale =
            self::money(
                $variation->get_meta(
                    'priority_foreign_sale_price'
                )
            );

        $normal_sale =
            self::money(
                $variation->get_meta(
                    'base_foreign_sale_price'
                )
            );

        $foreign_sale =
            $priority_sale > 0
                ? $priority_sale
                : $normal_sale;

        $active_price =
            $regular_price;

        $sale_active = false;

        if ($foreign_sale > 0) {
            $candidate =
                round(
                    $foreign_sale * $rate
                );

            if (
                $candidate < $regular_price
            ) {
                $from =
                    (int)$variation->get_meta(
                        'foreign_sale_price_dates_from'
                    );

                $to =
                    (int)$variation->get_meta(
                        'foreign_sale_price_dates_to'
                    );

                $now =
                    current_time(
                        'timestamp',
                        true
                    );

                $sale_active = true;

                if (
                    $from &&
                    $now < $from
                ) {
                    $sale_active = false;
                }

                if (
                    $to &&
                    $now > $to
                ) {
                    $sale_active = false;
                }

                $sale_price =
                    $candidate;

                if (
                    $sale_active
                ) {
                    $active_price =
                        $candidate;
                }
            }
        }

        $dirty = false;

        $dirty |= self::sync_prices(
            $variation,
            $regular_price,
            $sale_price,
            $active_price
        );

        $dirty |= self::sync_gift(
            $variation,
            $rate,
            $base,
            $foreign_sale,
            $sale_active
        );

        $dirty |= self::sync_code(
            $variation,
            $rate,
            $base,
            $foreign_sale,
            $sale_active
        );

        if ($dirty) {
            $variation->save();
            return true;
        }

        return false;
    }

    private static function sync_prices(
        WC_Product $product,
        int $regular,
        ?int $sale,
        int $active
    ): bool {
        $dirty = false;
        $old_active = (float)$product->get_price();

        if (
            (float)$product->get_regular_price()
            !==
            (float)$regular
        ) {
            $product->set_regular_price(
                $regular
            );
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

        if (
            (float)$product->get_price()
            !==
            (float)$active
        ) {
            $product->set_price(
                $active
            );
            $dirty = true;
        }


        return $dirty;
    }

    private static function sync_gift(
        WC_Product $product,
        float $rate,
        float $base,
        float $foreign_sale,
        bool $sale_active
    ): bool {
        $manual =
            $product->get_meta(
                '_gift_price_toman'
            );

        if ($manual !== '') {
            return false;
        }

        $gift =
            $product->get_meta(
                'gift_foreign_price_diff'
            );

        if (
            $gift === '' ||
            $gift === false ||
            $gift === 'no'
        ) {
            return
                self::meta(
                    $product,
                    'giftPriceToman',
                    'disabled'
                )
                |
                self::meta(
                    $product,
                    'giftRegularPriceToman',
                    'disabled'
                );
        }

        $gift_value =
            self::money($gift);

        $gift_regular =
            round(
                $gift_value * $rate
            );

        $gift_sale =
            $sale_active
                ? round(
                    (
                        $foreign_sale +
                        (
                            $gift_value -
                            $base
                        )
                    ) * $rate
                )
                : $gift_regular;

        return
            self::meta(
                $product,
                'giftPriceToman',
                $gift_sale
            )
            |
            self::meta(
                $product,
                'giftRegularPriceToman',
                $gift_regular
            );
    }

    private static function sync_code(
        WC_Product $product,
        float $rate,
        float $base,
        float $foreign_sale,
        bool $sale_active
    ): bool {
        $manual =
            $product->get_meta(
                '_code_price_toman'
            );

        if ($manual !== '') {
            return false;
        }

        $code =
            $product->get_meta(
                'code_foreign_price_diff'
            );

        if (
            $code === '' ||
            $code === false ||
            $code === 'no'
        ) {
            return
                self::meta(
                    $product,
                    'codePriceToman',
                    'disabled'
                )
                |
                self::meta(
                    $product,
                    'codeRegularPriceToman',
                    'disabled'
                );
        }

        $code_value =
            self::money($code);

        $code_regular =
            round(
                $code_value * $rate
            );

        $code_sale =
            $sale_active
                ? round(
                    (
                        $foreign_sale +
                        (
                            $code_value -
                            $base
                        )
                    ) * $rate
                )
                : $code_regular;

        return
            self::meta(
                $product,
                'codePriceToman',
                $code_sale
            )
            |
            self::meta(
                $product,
                'codeRegularPriceToman',
                $code_regular
            );
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
            return self::readTomanMeta($product, '_gift_price_toman', 'giftPriceToman');
        }

        if ($deliveryMethod === 'code') {
            return self::readTomanMeta($product, '_code_price_toman', 'codePriceToman');
        }

        $price = $product->get_price();
        if ($price === '' || $price === null) {
            return null;
        }

        return (float)$price;
    }

    private static function readTomanMeta(WC_Product $product, string $manualKey, string $autoKey): ?float
    {
        $manual = $product->get_meta($manualKey);
        $value = $manual !== '' ? $manual : $product->get_meta($autoKey);

        if ($value === '' || $value === false || $value === 'disabled') {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || !preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            return null;
        }
        return (float) $normalized;
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

        $settings = get_option('site-settings', []);

        $rates = [
            'USD'   => self::money($settings['usd_to_toman_rate'] ?? 0),
            'EUR'   => self::money($settings['eur_to_toman_rate'] ?? 0),
            'TRY'   => self::money($settings['try_to_toman_rate'] ?? 0),
            'UAH'   => self::money($settings['uah_to_toman_rate'] ?? 0),
            'USD_R' => self::money($settings['usd_to_toman_rate_r'] ?? 0),
            'EUR_R' => self::money($settings['eur_to_toman_rate_r'] ?? 0),
        ];

        wp_cache_set('rates', $rates, 'btl', 60);
        self::$memoryRates = $rates;

        return self::$memoryRates;
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