<?php
defined('ABSPATH') || exit;

final class BTL_Rate_Sync
{
    private const GROUP = 'btl';
    private const HOOK = 'btl_sync_exchange_rates';
    private const OPTION_KEY = 'btl_pricing_settings';
    private const LAST_SYNC_OPTION = 'btl_last_rate_sync';
    private const LIVE_RATES_OPTION = 'btl_live_exchange_rates';
    private const LAST_SUCCESSFUL_OPTION = 'btl_last_successful_exchange_rates';
    private const STATUS_OPTION = 'btl_rate_engine_status';
    private const HEALTH_CHECK_OPTION = 'btl_rate_sync_last_health_check';
    private const HEALTH_CHECK_INTERVAL = 86400;
    private const DEFAULT_INTERVAL_HOURS = 6;
    private const MAX_DEVIATION_RATIO = 0.2;

    public static function boot(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
        add_action('update_option_' . self::OPTION_KEY, [self::class, 'maybe_reschedule'], 10, 2);
    }

    public static function activate(): void
    {
        if (self::schedule(self::interval_hours(), true)) {
            update_option(self::HEALTH_CHECK_OPTION, time(), true);
        }
    }

    public static function maybe_schedule(): void
    {
        $lastCheck = (int) get_option(self::HEALTH_CHECK_OPTION, 0);

        if ($lastCheck > 0 && (time() - $lastCheck) < self::HEALTH_CHECK_INTERVAL) {
            return;
        }

        if (self::schedule(self::interval_hours(), false)) {
            update_option(self::HEALTH_CHECK_OPTION, time(), true);
        }
    }

    public static function schedule(int $hours, bool $force = false): bool
    {
        if (!function_exists('as_schedule_recurring_action')) {
            return false;
        }

        $hours = max(1, $hours);

        if (!$force && function_exists('as_has_scheduled_action')) {
            if (as_has_scheduled_action(self::HOOK, [], self::GROUP)) {
                return true;
            }
        }

        if ($force && function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [], self::GROUP);
        }

        $actionId = as_schedule_recurring_action(
            time() + 60,
            $hours * HOUR_IN_SECONDS,
            self::HOOK,
            [],
            self::GROUP
        );

        return (bool) $actionId;
    }

    public static function maybe_reschedule($old_value, $new_value): void
    {
        $oldHours = self::extract_interval(is_array($old_value) ? $old_value : []);
        $newHours = self::extract_interval(is_array($new_value) ? $new_value : []);

        if ($oldHours === $newHours) {
            return;
        }

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [], self::GROUP);
        }

        if (self::schedule($newHours, true)) {
            update_option(self::HEALTH_CHECK_OPTION, time(), true);
        }
    }

    private static function interval_hours(): int
    {
        return self::extract_interval(get_option(self::OPTION_KEY, []));
    }

    private static function extract_interval(array $settings): int
    {
        $hours = isset($settings['rate_sync_interval_hours'])
            ? (int) $settings['rate_sync_interval_hours']
            : 0;

        return $hours > 0 ? $hours : self::DEFAULT_INTERVAL_HOURS;
    }

    public static function run(): void
    {
        $gateway = new BTL_Navasan_Rate_Gateway();
        $result = $gateway->test();
        self::store_status($result);

        if (empty($result['healthy']) || empty($result['rates'])) {
            BTL_Helpers::logger('RateSync: دریافت نرخ معتبر ناموفق بود (' . sanitize_key((string)($result['status'] ?? 'failed')) . ').');
            return;
        }

        $rates = $result['rates'];
        $previous = get_option(self::LAST_SUCCESSFUL_OPTION, []);
        $previousRates = is_array($previous) && isset($previous['rates']) && is_array($previous['rates'])
            ? $previous['rates']
            : [];
        $settings = get_option(self::OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];
        $manualFields = [
            'USD' => 'usd_manual_fallback_rate',
            'EUR' => 'eur_manual_fallback_rate',
            'TRY' => 'try_manual_fallback_rate',
            'UAH' => 'uah_manual_fallback_rate',
        ];
        $changedCurrencies = [];
        $acceptedRates = [];
        $rejectedCurrencies = [];

        foreach ($rates as $currency => $newValue) {
            $newValue = (float) $newValue;
            $oldValue = isset($previousRates[$currency]) ? (float) $previousRates[$currency] : 0.0;

            if ($oldValue > 0) {
                $deviation = abs($newValue - $oldValue) / $oldValue;

                if ($deviation > self::MAX_DEVIATION_RATIO) {
                    $rejectedCurrencies[] = strtoupper((string)$currency);
                    BTL_Helpers::logger(sprintf('RateSync: نرخ %s به دلیل safety deviation رد شد.', $currency));
                    continue;
                }
            }

            $acceptedRates[$currency] = $newValue;
            $manual = isset($manualFields[$currency]) ? BTL_Price_Engine::priceValue($settings[$manualFields[$currency]] ?? null) : null;
            if ($oldValue !== $newValue && ($manual === null || $manual <= 0)) {
                $changedCurrencies[] = $currency;
            }
        }

        if (!$acceptedRates) {
            $failed = $result;
            $failed['healthy'] = false;
            $failed['status'] = 'deviation_rejected';
            $failed['message'] = 'All returned rates were rejected by the safety guard.';
            $failed['rejected'] = $rejectedCurrencies;
            self::store_status($failed);
            return;
        }

        $previousTimes = is_array($previous) && is_array($previous['currency_fetched_at'] ?? null) ? $previous['currency_fetched_at'] : [];
        $mergedRates = $previousRates;
        $mergedTimes = $previousTimes;
        foreach ($acceptedRates as $currency => $value) {
            $mergedRates[$currency] = $value;
            $mergedTimes[$currency] = time();
        }
        $record = ['rates' => $mergedRates, 'currency_fetched_at' => $mergedTimes, 'fetched_at' => time()];
        update_option(self::LIVE_RATES_OPTION, $record, false);
        update_option(self::LAST_SUCCESSFUL_OPTION, $record, false);
        update_option(self::LAST_SYNC_OPTION, current_time('mysql', true), false);

        $result['healthy'] = true;
        $result['status'] = $rejectedCurrencies ? 'partial_success' : 'success';
        $result['message'] = $rejectedCurrencies
            ? 'Some rates were rejected by the safety deviation guard.'
            : 'All returned rates were accepted.';
        $result['rejected'] = $rejectedCurrencies;
        self::store_status($result);

        wp_cache_delete('rates', 'btl');
        BTL_Price_Engine::clearMemoryRates();

        if ($changedCurrencies) BTL_Scheduler::schedule($changedCurrencies);

        BTL_Helpers::logger(sprintf(
            'RateSync: نرخ‌های معتبر ذخیره شدند — ارزهای تغییریافته: %s',
            implode(', ', $changedCurrencies)
        ));
    }

    public static function test_now(): array
    {
        $result = (new BTL_Navasan_Rate_Gateway())->test();
        self::store_status($result);
        return $result;
    }

    public static function status(): array
    {
        $status = get_option(self::STATUS_OPTION, []);
        return is_array($status) ? $status : [];
    }

    private static function store_status(array $result): void
    {
        update_option(self::STATUS_OPTION, [
            'healthy' => !empty($result['healthy']),
            'status' => sanitize_key((string)($result['status'] ?? 'failed')),
            'message' => sanitize_text_field((string)($result['message'] ?? '')),
            'missing' => array_values(array_map('sanitize_key', (array)($result['missing'] ?? []))),
            'rejected' => array_values(array_map('sanitize_key', (array)($result['rejected'] ?? []))),
            'checked_at' => (int)($result['checked_at'] ?? time()),
        ], false);
    }
}
