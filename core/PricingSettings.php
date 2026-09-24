<?php
defined('ABSPATH') || exit;

final class BTL_Pricing_Settings
{
    private const OPTION_KEY = 'btl_pricing_settings';

    private const CURRENCIES = [
        'USD' => 'usd_manual_fallback_rate',
        'EUR' => 'eur_manual_fallback_rate',
        'TRY' => 'try_manual_fallback_rate',
        'UAH' => 'uah_manual_fallback_rate',
    ];

    private const GLOBAL_GAME_DISCOUNT = 'global_game_discount_percent';
    private const GLOBAL_GAME_DISCOUNT_END = 'global_game_discount_end_date';
    private const GLOBAL_COMMISSION_DISCOUNT = 'global_commission_discount_percent';
    private const GLOBAL_COMMISSION_DISCOUNT_END = 'global_commission_discount_end_date';

    public static function get(): array
    {
        $settings = get_option(self::OPTION_KEY, []);
        return is_array($settings) ? $settings : [];
    }

    public static function currencyManualKey(string $currency): ?string
    {
        return self::CURRENCIES[strtoupper($currency)] ?? null;
    }

    public static function currencies(): array
    {
        return array_keys(self::CURRENCIES);
    }

    public static function apiRates(): array
    {
        $live = get_option('btl_live_exchange_rates', []);
        $last = get_option('btl_last_successful_exchange_rates', []);
        $result = [];

        foreach (self::CURRENCIES as $currency => $field) {
            $apiRate = self::priceValue($live['rates'][$currency] ?? null);
            $apiFetchedAt = (int)($live['currency_fetched_at'][$currency] ?? $live['fetched_at'] ?? 0);
            $lastRate = self::priceValue($last['rates'][$currency] ?? null);
            $lastFetchedAt = (int)($last['currency_fetched_at'][$currency] ?? $last['fetched_at'] ?? 0);

            $result[$currency] = [
                'apiRate' => $apiRate,
                'apiFetchedAt' => $apiFetchedAt ?: null,
                'lastSuccessfulRate' => $lastRate,
                'lastSuccessfulFetchedAt' => $lastFetchedAt ?: null,
            ];
        }

        return $result;
    }

    public static function adminSnapshot(): array
    {
        $settings = self::get();
        $rates = BTL_Price_Engine::rateStatus();
        $api = self::apiRates();
        $currencies = [];

        foreach (self::CURRENCIES as $currency => $field) {
            $resolved = is_array($rates[$currency] ?? null) ? $rates[$currency] : [];
            $currencies[] = [
                'currency' => $currency,
                'apiRate' => $api[$currency]['apiRate'],
                'apiFetchedAt' => $api[$currency]['apiFetchedAt'],
                'lastSuccessfulRate' => $api[$currency]['lastSuccessfulRate'],
                'lastSuccessfulFetchedAt' => $api[$currency]['lastSuccessfulFetchedAt'],
                'manualRate' => self::priceValue($settings[$field] ?? null),
                'effectiveRate' => isset($resolved['rate']) ? $resolved['rate'] : null,
                'source' => (string)($resolved['source'] ?? 'unavailable'),
                'fetchedAt' => isset($resolved['fetchedAt']) ? $resolved['fetchedAt'] : null,
            ];
        }

        return [
            'globalCommissionPercent' => self::percentage($settings['global_commission_percent'] ?? $settings['btl_global_commission_percent'] ?? ''),
            'globalGameDiscountPercent' => self::percentage($settings[self::GLOBAL_GAME_DISCOUNT] ?? ''),
            'globalGameDiscountEndDate' => self::normalizeDate($settings[self::GLOBAL_GAME_DISCOUNT_END] ?? ''),
            'globalCommissionDiscountPercent' => self::percentage($settings[self::GLOBAL_COMMISSION_DISCOUNT] ?? ''),
            'globalCommissionDiscountEndDate' => self::normalizeDate($settings[self::GLOBAL_COMMISSION_DISCOUNT_END] ?? ''),
            'rateSyncIntervalHours' => max(1, (int)($settings['rate_sync_interval_hours'] ?? 6)),
            'currencies' => $currencies,
        ];
    }

    public static function save(array $input): array
    {
        $settings = self::get();

        if (array_key_exists('globalCommissionPercent', $input)) {
            $settings['global_commission_percent'] = self::sanitizePercentage($input['globalCommissionPercent']);
        }

        if (array_key_exists('rateSyncIntervalHours', $input)) {
            $interval = (int)$input['rateSyncIntervalHours'];
            $settings['rate_sync_interval_hours'] = max(1, min(168, $interval));
        }

        foreach (self::CURRENCIES as $currency => $field) {
            if (!array_key_exists($currency, $input)) {
                continue;
            }
            $settings[$field] = self::sanitizeRate($input[$currency]);
        }

        if (array_key_exists('globalGameDiscountPercent', $input)) {
            $settings[self::GLOBAL_GAME_DISCOUNT] = self::sanitizePercentage($input['globalGameDiscountPercent']);
        }

        if (array_key_exists('globalGameDiscountEndDate', $input)) {
            $settings[self::GLOBAL_GAME_DISCOUNT_END] = self::sanitizeDate($input['globalGameDiscountEndDate']);
        }

        if (array_key_exists('globalCommissionDiscountPercent', $input)) {
            $settings[self::GLOBAL_COMMISSION_DISCOUNT] = self::sanitizePercentage($input['globalCommissionDiscountPercent']);
        }

        if (array_key_exists('globalCommissionDiscountEndDate', $input)) {
            $settings[self::GLOBAL_COMMISSION_DISCOUNT_END] = self::sanitizeDate($input['globalCommissionDiscountEndDate']);
        }

        update_option(self::OPTION_KEY, $settings, false);

        return self::adminSnapshot();
    }

    public static function variationDate(int $variationId, string $key): string
    {
        $timestamp = (int)get_post_meta($variationId, $key, true);
        return $timestamp > 0 ? wp_date('Y-m-d', $timestamp, wp_timezone()) : '';
    }

    public static function parseDateEnd($value): ?int
    {
        $date = self::sanitizeDate($value);
        if ($date === '') {
            return null;
        }

        $datetime = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $date . ' 23:59:59',
            wp_timezone()
        );

        if (!$datetime) {
            return null;
        }

        return $datetime->getTimestamp();
    }

    public static function discountActive($percent, $endTimestamp): bool
    {
        $percent = self::percentage($percent);
        if ($percent <= 0) {
            return false;
        }

        $endTimestamp = (int)$endTimestamp;
        return $endTimestamp < 1 || time() <= $endTimestamp;
    }

    public static function globalDiscount(string $type): array
    {
        $settings = self::get();

        if ($type === 'game') {
            $percentKey = self::GLOBAL_GAME_DISCOUNT;
            $dateKey = self::GLOBAL_GAME_DISCOUNT_END;
        } else {
            $percentKey = self::GLOBAL_COMMISSION_DISCOUNT;
            $dateKey = self::GLOBAL_COMMISSION_DISCOUNT_END;
        }

        $raw = array_key_exists($percentKey, $settings) ? (string)$settings[$percentKey] : '';
        if ($raw === '' || strtolower($raw) === 'disabled') {
            return ['configured' => false, 'active' => false, 'percent' => 0.0, 'end' => 0];
        }

        $percent = self::percentage($raw);
        $date = self::normalizeDate($settings[$dateKey] ?? '');
        $end = self::parseDateEnd($date);

        return [
            'configured' => true,
            'active' => self::discountActive($percent, $end),
            'percent' => $percent,
            'end' => $end ?: 0,
        ];
    }

    public static function nextGlobalDiscountEnd(): ?int
    {
        $ends = [];
        foreach (['game', 'commission'] as $type) {
            $discount = self::globalDiscount($type);
            if (!empty($discount['configured']) && (int)$discount['end'] > time()) {
                $ends[] = (int)$discount['end'];
            }
        }

        if (!$ends) {
            return null;
        }

        sort($ends, SORT_NUMERIC);
        return $ends[0];
    }

    private static function sanitizeRate($value): string
    {
        $number = self::priceValue($value);
        if ($number === null || $number <= 0) {
            return '';
        }
        return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }

    private static function sanitizePercentage($value): string
    {
        $value = trim(str_replace([',', '،', ' '], '', (string)$value));
        if ($value === '') {
            return '';
        }
        if (!is_numeric($value)) {
            return '';
        }
        $number = max(0, min(100, (float)$value));
        return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }

    private static function sanitizeDate($value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
        if (!$date || $date->format('Y-m-d') !== $value) {
            return '';
        }
        return $value;
    }

    private static function normalizeDate($value): string
    {
        return self::sanitizeDate($value);
    }

    private static function percentage($value): float
    {
        $value = self::priceValue($value);
        return $value === null ? 0.0 : max(0.0, min(100.0, $value));
    }

    private static function priceValue($value): ?float
    {
        return BTL_Price_Engine::priceValue($value);
    }
}
