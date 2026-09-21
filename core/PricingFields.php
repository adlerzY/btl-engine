<?php
defined('ABSPATH') || exit;

final class BTL_Pricing_Fields
{
    private const ACF_PRICING_FIELDS = [
        'base_currency_type',
        'base_foreign_price',
        'base_foreign_sale_price',
        'priority_foreign_sale_price',
        'foreign_sale_price_dates_from',
        'foreign_sale_price_dates_to',
        'gift_foreign_price_diff',
        'code_foreign_price_diff',
        '_btl_gift_price',
        '_btl_code_price',
        '_btl_game_discount',
        '_btl_commission_discount',
    ];

    private const ACF_CONTENT_FIELDS = [
        'short-notify',
        'short_notify',
        'secondary_gallery',
        'content_matrix',
        'description',
    ];

    private const PRODUCT_PRICING_PROPS = [
        'price',
        'regular_price',
        'sale_price',
        'date_on_sale_from',
        'date_on_sale_to',
        'stock_quantity',
        'stock_status',
        'manage_stock',
        'backorders',
        'low_stock_amount',
        'total_sales',
        'children',
        'variation_ids',
    ];

    public static function acfPricingFields(): array
    {
        return self::ACF_PRICING_FIELDS;
    }

    public static function acfContentFields(): array
    {
        return self::ACF_CONTENT_FIELDS;
    }

    public static function productPricingProps(): array
    {
        return self::PRODUCT_PRICING_PROPS;
    }
}
