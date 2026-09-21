<?php

defined('ABSPATH') || exit;

final class BTL_Region_Registry
{
    private const REGIONS = [
        'eu' => ['label' => 'Europe', 'currency' => 'EUR', 'symbol' => '€', 'aliases' => ['eu', 'eu-global', 'اروپا', 'europe']],
        'us' => ['label' => 'US', 'currency' => 'USD', 'symbol' => '$', 'aliases' => ['us', 'امریکا', 'آمریکا', 'america', 'usa']],
        'tr' => ['label' => 'Turkey', 'currency' => 'TRY', 'symbol' => '₺', 'aliases' => ['tr', 'ترکیه', 'turkey']],
        'ua' => ['label' => 'Ukraine', 'currency' => 'UAH', 'symbol' => '₴', 'aliases' => ['ua', 'اوکراین', 'ukraine']],
    ];

    public static function all(): array { return self::REGIONS; }

    public static function canonical(string $value): ?string
    {
        $needle = self::lower(trim($value));
        if ($needle === '') return null;
        foreach (self::REGIONS as $region => $config) {
            foreach ($config['aliases'] as $alias) {
                if ($needle === self::lower($alias)) return $region;
            }
        }
        return null;
    }

    public static function aliases(string $value): array
    {
        $region = self::canonical($value);
        return $region !== null ? self::REGIONS[$region]['aliases'] : [$value];
    }

    public static function config(string $value): ?array
    {
        $region = self::canonical($value);
        return $region !== null ? ['region' => $region] + self::REGIONS[$region] : null;
    }

    public static function currency(string $value): ?string
    {
        $config = self::config($value);
        return $config['currency'] ?? null;
    }

    public static function fromVariation(WC_Product $variation): ?array
    {
        if (!$variation->is_type('variation')) return null;
        foreach ((array) $variation->get_variation_attributes() as $key => $value) {
            $taxonomy = str_replace('attribute_', '', (string) $key);
            if (stripos($taxonomy, 'region') === false && strpos($taxonomy, 'ریجن') === false) continue;
            return self::config((string) $value);
        }
        return null;
    }

    public static function matches(string $left, string $right): bool
    {
        $a = self::canonical($left);
        $b = self::canonical($right);
        return $a !== null && $a === $b;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
