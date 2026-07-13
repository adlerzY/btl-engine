<?php

defined('ABSPATH') || exit;

final class BTL_Price_Engine
{
    private static array $running = [];

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
        bool $notify = true
    ): void {
        if (isset(self::$running[$product_id])) {
            return;
        }

        self::$running[$product_id] = true;

        try {
            $product = wc_get_product(
                $product_id
            );

            if (!$product) {
                return;
            }

            if ($product->is_type('variation')) {
                $product_id =
                    $product->get_parent_id();

                $product = wc_get_product(
                    $product_id
                );

                if (!$product) {
                    return;
                }
            }

            $rates = self::rates();

            if (!$rates) {
                return;
            }

            $items = $product->is_type('variable')
                ? $product->get_children()
                : [$product_id];

            $changed = false;

            foreach ($items as $item_id) {
                $variation = wc_get_product(
                    $item_id
                );

                if (!$variation) {
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
                wp_cache_delete(
                    "variations_{$product_id}",
                    'btl'
                );
            }

            if (
                $changed &&
                $notify &&
                function_exists(
                    'btl_queue_revalidation'
                )
            ) {
                btl_queue_revalidation([
                    'products',
                    "product-{$product_id}"
                ]);
            }

        } finally {
            unset(
                self::$running[$product_id]
            );
        }
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
                $candidate <
                $regular_price
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
            (string)$product->get_regular_price()
            !==
            (string)$regular
        ) {
            $product->set_regular_price(
                $regular
            );
            $dirty = true;
        }

        $sale_value =
            $sale === null
                ? ''
                : (string)$sale;

        if (
            (string)$product->get_sale_price()
            !==
            $sale_value
        ) {
            $product->set_sale_price(
                $sale_value
            );
            $dirty = true;
        }

        if (
            (string)$product->get_price()
            !==
            (string)$active
        ) {
            $product->set_price(
                $active
            );
            $dirty = true;
        }

        if ($dirty && $old_active > 0 && $active < $old_active) {
            do_action('btl_price_dropped', $product->get_id(), $old_active, $active);
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

    public static function rates(): array
    {
        $cached =
            wp_cache_get(
                'rates',
                'btl'
            );

        if ($cached !== false) {
            return $cached;
        }

        $settings =
            get_option(
                'site-settings',
                []
            );

        $rates = [
            'USD'   => self::money(
                $settings['usd_to_toman_rate'] ?? 0
            ),
            'EUR'   => self::money(
                $settings['eur_to_toman_rate'] ?? 0
            ),
            'TRY'   => self::money(
                $settings['try_to_toman_rate'] ?? 0
            ),
            'UAH'   => self::money(
                $settings['uah_to_toman_rate'] ?? 0
            ),
            'USD_R' => self::money(
                $settings['usd_to_toman_rate_r'] ?? 0
            ),
            'EUR_R' => self::money(
                $settings['eur_to_toman_rate_r'] ?? 0
            ),
        ];

        wp_cache_set(
            'rates',
            $rates,
            'btl',
            60
        );

        return $rates;
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