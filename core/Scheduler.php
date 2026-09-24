<?php

defined('ABSPATH') || exit;

final class BTL_Scheduler
{
    private const GROUP = 'btl';

    private const LOCK_OPTION = 'btl_batch_lock_v2';
    private const LOCK_TTL = 900; // 15 minutes; refreshed by every worker step.

    private const STATE_PREFIX = 'btl_batch_ids_';
    private const CHUNK_SUFFIX = '_c';
    private const IDS_PER_ROW = 500;

    private const PENDING_KEY = 'btl_batch_pending_request';
    private const PENDING_OPTION = 'btl_batch_pending_request_v2';
    private const DEFAULT_BATCH_SIZE = 10;
    private const MAX_BATCH_SIZE = 50;

    private const WATCHDOG_IDLE = 600; // 10 minutes without progress = stalled.
    private const STEP_LEASE = 900;
    private const MAX_RESUMES = 8;

    private static array $acfChanges = [];

    public static function boot(): void
    {
        add_action('btl_batch_step', [self::class, 'process_step'], 10, 2);
        add_action('btl_batch_watchdog', [self::class, 'watchdog'], 10, 1);
        add_action('btl_batch_job', [self::class, 'legacy_forwarder'], 10, 2);
        add_action('btl_product_chunk_job', [self::class, 'legacy_chunk_forwarder'], 10, 1);
        add_action('btl_cleanup_job', [self::class, 'legacy_cleanup'], 10);
    }

    public static function get_batch_size(): int
    {
        $size = (int) apply_filters('btl_price_sync_batch_size', self::DEFAULT_BATCH_SIZE);

        return max(1, min($size, self::MAX_BATCH_SIZE));
    }

    public static function on_pricing_settings_updated($old_value, $new_value): void
    {
        if (class_exists('BTL_Price_Engine')) {
            BTL_Price_Engine::clearMemoryRates();
        }
        wp_cache_delete('rates', 'btl');

        $old = is_array($old_value) ? $old_value : [];
        $new = is_array($new_value) ? $new_value : [];
        $fullRepriceKeys = [
            'global_commission_percent',
            'global_game_discount_percent',
            'global_game_discount_end_date',
            'global_commission_discount_percent',
            'global_commission_discount_end_date',
        ];

        foreach ($fullRepriceKeys as $key) {
            if ((string)($old[$key] ?? '') !== (string)($new[$key] ?? '')) {
                self::schedule([]);
                if (class_exists('BTL_Price_Engine')) {
                    BTL_Price_Engine::scheduleGlobalDiscountBoundary();
                }
                return;
            }
        }

        $fields = [
            'usd_manual_fallback_rate' => 'USD',
            'eur_manual_fallback_rate' => 'EUR',
            'try_manual_fallback_rate' => 'TRY',
            'uah_manual_fallback_rate' => 'UAH',
        ];
        $changed = [];
        foreach ($fields as $field => $currency) {
            if (BTL_Helpers::money($old[$field] ?? 0) !== BTL_Helpers::money($new[$field] ?? 0)) {
                $changed[] = $currency;
            }
        }
        if ($changed) {
            self::schedule($changed);
        }

        if (class_exists('BTL_Price_Engine')) {
            BTL_Price_Engine::scheduleGlobalDiscountBoundary();
        }
    }

    public static function trigger_mass_update($post_id = null): void
    {
        if ($post_id === null || $post_id === 'options') {
            return;
        }

        if (is_numeric($post_id) && get_post_type((int)$post_id) === 'product') {
            $product_id = (int)$post_id;
            if (self::should_reprice_product($product_id)) {
                BTL_Price_Engine::calculate($product_id, true);
            }
        }
    }

    public static function capture_acf_change($value, $post_id, $field, $original = null): void
    {
        if (!is_numeric($post_id) || get_post_type((int)$post_id) !== 'product') {
            return;
        }

        $field_name = is_array($field) ? (string) ($field['name'] ?? '') : '';
        if ($field_name === '') {
            return;
        }

        self::$acfChanges[(int) $post_id][] = $field_name;
    }

    private static function should_reprice_product(int $product_id): bool
    {
        if (!array_key_exists($product_id, self::$acfChanges)) {
            return true;
        }

        $pricing_fields = BTL_Pricing_Fields::acfPricingFields();
        $fields = array_values(array_unique(self::$acfChanges[$product_id]));
        if (!$fields) {
            return true;
        }

        $known_content_fields = BTL_Pricing_Fields::acfContentFields();
        foreach ($fields as $field) {
            if (in_array($field, $pricing_fields, true)) {
                return true;
            }
            if (!in_array($field, $known_content_fields, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Schedule a batch. An empty currency list means a full affected-catalog scan.
     * While another batch is running, requests are merged into a pending rerun.
     */
    public static function schedule(array $changedCurrencies = []): void
    {
        if (!function_exists('as_schedule_single_action')) {
            BTL_Helpers::logger('Scheduler: Action Scheduler is unavailable.');
            return;
        }

        $changedCurrencies = self::normalize_currencies($changedCurrencies);

        $lock = self::read_lock();
        if ($lock !== null) {
            if (self::lock_is_active($lock)) {
                self::queue_pending_request($changedCurrencies);
                return;
            }

            // Recover a stale lock and clean its orphaned state before retrying.
            $stale_token = (string) ($lock['token'] ?? '');
            if ($stale_token !== '' && self::delete_lock_if_owned($lock)) {
                self::cleanup_storage($stale_token);
            } else {
                self::queue_pending_request($changedCurrencies);
                return;
            }
        }

        $pending = self::take_pending_request();
        if ($pending !== null) {
            $changedCurrencies = !empty($pending['full'])
                ? []
                : self::normalize_currencies(array_merge(
                    $changedCurrencies,
                    (array) ($pending['currencies'] ?? [])
                ));
        }

        $affected_ids = self::get_affected_product_ids($changedCurrencies);
        if (!$affected_ids) {
            return;
        }

        $token = wp_generate_password(24, false, false);
        $lock_value = wp_json_encode([
            'token' => $token,
            'expires_at' => time() + self::LOCK_TTL,
            'touched' => time(),
            'offset' => 0,
            'resumes' => 0,
            'resume_offset' => -1,
        ]);

        // add_option() is atomic on the unique option_name key.
        if (!add_option(self::LOCK_OPTION, $lock_value, '', 'no')) {
            $existing = self::read_lock();
            if ($existing !== null && self::lock_is_active($existing)) {
                self::queue_pending_request($changedCurrencies);
                return;
            }

            if (!self::delete_lock_if_owned($existing ?? [])) {
                self::queue_pending_request($changedCurrencies);
                return;
            }
            self::cleanup_storage((string) ($existing['token'] ?? ''));
            if (!add_option(self::LOCK_OPTION, $lock_value, '', 'no')) {
                self::queue_pending_request($changedCurrencies);
                return;
            }
        }

        self::write_state($token, $affected_ids, $changedCurrencies);

        // Arm recovery before scheduling the first step. A crash or process kill
        // between state creation and the first Action Scheduler insert must not
        // leave an active lock with no watchdog.
        self::arm_watchdog($token);

        $action_id = as_schedule_single_action(
            time(),
            'btl_batch_step',
            [
                'token' => $token,
                'offset' => 0,
            ],
            self::GROUP
        );

        if (!$action_id) {
            self::clear_watchdog($token);
            self::cleanup_storage($token);
            self::delete_lock_if_owned($token);
            self::queue_pending_request($changedCurrencies);
            BTL_Helpers::logger('Scheduler: failed to schedule first batch step.');
            return;
        }

        self::arm_watchdog($token);
    }

    public static function process_step($token = '', $offset = 0): void
    {
        $token = (string) $token;
        $offset = max(0, (int) $offset);

        if ($token === '') {
            return;
        }

        $lock = self::read_lock();

        if ($lock === null || ($lock['token'] ?? '') !== $token) {
            self::cleanup_storage($token);
            return;
        }

        $state = self::read_state($token);

        if ($state === null) {
            self::finalize($token);
            return;
        }

        $total = (int) ($state['count'] ?? 0);

        if ($total <= 0 || $offset >= $total) {
            self::finalize($token);
            return;
        }

        if (!self::claim_step($token, $offset)) {
            return;
        }

        $batch_size = self::get_batch_size();

        $currencies = array_values(array_filter(array_map(
            'strval',
            (array) ($state['currencies'] ?? [])
        )));
        $currencies = $currencies ? $currencies : null;

        $chunk = self::read_ids($token, $state, $offset, $batch_size);

        if (!$chunk) {
            self::complete_step($token, $offset, $total);
            self::finalize($token);
            return;
        }

        $changed = self::process_chunk($chunk, $currencies);

        if ($changed > 0) {
            self::bump_changed($token, $changed);
        }

        $next_offset = $offset + count($chunk);

        if ($next_offset < $total) {
            self::complete_step($token, $offset, $next_offset);
            self::queue_step($token, $next_offset);
            return;
        }

        self::complete_step($token, $offset, $next_offset);
        self::finalize($token);
    }

    /**
     * @return int Number of products whose price data actually changed.
     */
    public static function process_chunk(array $products, ?array $currencies = null): int
    {
        $products = array_values(array_filter(array_map('intval', $products)));
        if (!$products) {
            return 0;
        }

        $has_invalidation = class_exists('BTL_Invalidation');
        if ($has_invalidation) {
            BTL_Invalidation::suspend();
        }

        $changed_ids = [];

        try {
            foreach ($products as $product_id) {
                try {
                    if (BTL_Price_Engine::calculate($product_id, false, $currencies)) {
                        $changed_ids[$product_id] = true;
                    }
                } catch (Throwable $e) {
                    BTL_Helpers::logger(sprintf(
                        'Scheduler: product %d failed: %s',
                        $product_id,
                        $e->getMessage()
                    ));
                }
            }
        } finally {
            if ($has_invalidation) {
                // Products WooCommerce touched indirectly (for example a variable
                // parent resynced after a variation save) must be tagged too.
                foreach (BTL_Invalidation::resume() as $deferred_id) {
                    $changed_ids[(int) $deferred_id] = true;
                }
            }
        }

        if (!$changed_ids) {
            return 0;
        }

        // Never use the no-argument WooCommerce helper: it invalidates the whole
        // product transient namespace. The ID form is the narrowest supported API.
        if (function_exists('wc_delete_product_transients')) {
            foreach (array_keys($changed_ids) as $changed_id) {
                wc_delete_product_transients((int) $changed_id);
            }
        }

        if ($has_invalidation) {
            foreach (array_keys($changed_ids) as $changed_id) {
                BTL_Invalidation::bustPricingCache((int) $changed_id);
            }
        }

        if (!function_exists('btl_queue_revalidation') || !$has_invalidation) {
            return count($changed_ids);
        }

        $tags = [];
        foreach (array_keys($changed_ids) as $product_id) {
            foreach (BTL_Invalidation::tagsForProduct(
                (int) $product_id,
                BTL_Invalidation::SCOPE_PRICING
            ) as $tag) {
                $tags[$tag] = true;
            }
        }

        if ($tags) {
            // Queue per worker instead of waiting for the whole catalog. This makes
            // partial failures safe and prevents a giant changed-ID state payload.
            btl_queue_revalidation(array_keys($tags));
        }

        return count($changed_ids);
    }

    /**
     * Finds only published parents touched by the selected currency meta values.
     * Variations resolve to their parent ID; simple products remain their own ID.
     */
    public static function get_affected_product_ids(array $currencies = []): array
    {
        global $wpdb;

        $currencies = self::normalize_currencies($currencies);
        $regionValues = [];
        foreach (BTL_Region_Registry::all() as $config) {
            if (!$currencies || in_array($config['currency'], $currencies, true)) {
                $regionValues = array_merge($regionValues, $config['aliases']);
            }
        }
        $regionValues = array_values(array_unique($regionValues));
        $regionKeys = ['attribute_pa_region_shop','attribute_region_shop','attribute_region','attribute_ریجن','attribute_pa_region','attribute_pa_ریجن'];
        $keyPlaceholders = implode(',', array_fill(0, count($regionKeys), '%s'));
        $valuePlaceholders = implode(',', array_fill(0, count($regionValues), '%s'));
        $legacyPlaceholders = $currencies ? implode(',', array_fill(0, count($currencies), '%s')) : '';
        $args = array_merge($regionKeys, $regionValues);
        $metaPredicate = "(pm.meta_key IN ({$keyPlaceholders}) AND pm.meta_value IN ({$valuePlaceholders}))";
        if ($currencies) {
            $metaPredicate .= " OR (pm.meta_key = 'base_currency_type' AND pm.meta_value IN ({$legacyPlaceholders}))";
            $args = array_merge($args, $currencies);
        } else {
            $metaPredicate .= " OR (pm.meta_key = 'base_currency_type' AND pm.meta_value <> '')";
        }

        $sql = "SELECT DISTINCT
                    IF(
                        p.post_type = 'product_variation',
                        p.post_parent,
                        p.ID
                    ) AS pid
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p
                    ON p.ID = pm.post_id
                LEFT JOIN {$wpdb->posts} parent
                    ON (
                        p.post_type = 'product_variation'
                        AND parent.ID = p.post_parent
                    )
                WHERE ({$metaPredicate})
                  AND (
                        (p.post_type = 'product' AND p.post_status = 'publish')
                        OR
                        (
                            p.post_type = 'product_variation'
                            AND parent.post_status = 'publish'
                        )
                  )";

        $raw = $args
            ? $wpdb->get_col($wpdb->prepare($sql, $args))
            : $wpdb->get_col($sql);

        if (!$raw) {
            return [];
        }

        $ids = array_filter(array_map('intval', $raw), static function (int $id): bool {
            return $id > 0;
        });

        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    // ---------------------------------------------------------------------
    // Batch state. The ID list is stored in fixed-size option rows so a worker
    // only unserializes the window it needs instead of the whole catalog on
    // every single step.
    // ---------------------------------------------------------------------

    private static function state_key(string $token): string
    {
        return self::STATE_PREFIX . $token;
    }

    private static function chunk_key(string $token, int $row): string
    {
        return self::STATE_PREFIX . $token . self::CHUNK_SUFFIX . $row;
    }

    private static function write_state(string $token, array $ids, array $currencies): void
    {
        $rows = array_chunk($ids, self::IDS_PER_ROW);

        foreach ($rows as $index => $row_ids) {
            $key = self::chunk_key($token, (int) $index);
            delete_option($key);
            add_option($key, $row_ids, '', 'no');
        }

        delete_option(self::state_key($token));
        add_option(self::state_key($token), [
            'count' => count($ids),
            'rows' => count($rows),
            'per_row' => self::IDS_PER_ROW,
            'currencies' => $currencies,
            'changed' => 0,
        ], '', 'no');
    }

    private static function read_state(string $token): ?array
    {
        $state = get_option(self::state_key($token), null);

        return (is_array($state) && isset($state['count'])) ? $state : null;
    }

    private static function read_ids(string $token, array $state, int $offset, int $length): array
    {
        $per_row = max(1, (int) ($state['per_row'] ?? self::IDS_PER_ROW));
        $rows = (int) ($state['rows'] ?? 0);
        $length = max(1, $length);

        $ids = [];

        for ($row = intdiv($offset, $per_row); $row < $rows; $row++) {
            $values = get_option(self::chunk_key($token, $row), []);

            if (!is_array($values) || !$values) {
                continue;
            }

            $row_start = $row * $per_row;
            $skip = max(0, $offset - $row_start);

            foreach (array_slice($values, $skip, $length - count($ids)) as $id) {
                $ids[] = (int) $id;
            }

            if (count($ids) >= $length) {
                break;
            }
        }

        return $ids;
    }

    private static function bump_changed(string $token, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $key = self::state_key($token);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $state = get_option($key, null);
            if (!is_array($state)) {
                return;
            }

            $next = $state;
            $next['changed'] = (int) ($state['changed'] ?? 0) + $count;
            if (self::compare_and_swap_option($key, $state, $next)) {
                return;
            }
        }
    }

    private static function claim_step(string $token, int $offset): bool
    {
        $lock = self::read_lock();
        if ($lock === null || ($lock['token'] ?? '') !== $token) {
            return false;
        }

        $now = time();
        if (!empty($lock['step_running']) && (int) ($lock['step_lease'] ?? 0) > $now) {
            return false;
        }

        $before = $lock;
        $lock['step_running'] = true;
        $lock['step_offset'] = $offset;
        $lock['step_lease'] = $now + self::STEP_LEASE;
        $lock['expires_at'] = $now + self::LOCK_TTL;
        $lock['touched'] = $now;

        return self::replace_lock_if_owned($token, $before, $lock);
    }

    private static function complete_step(string $token, int $offset, int $next_offset): bool
    {
        $lock = self::read_lock();
        if (
            $lock === null ||
            ($lock['token'] ?? '') !== $token ||
            empty($lock['step_running']) ||
            (int) ($lock['step_offset'] ?? -1) !== $offset
        ) {
            return false;
        }

        $before = $lock;
        $lock['step_running'] = false;
        $lock['step_lease'] = 0;
        $lock['offset'] = max((int) ($lock['offset'] ?? 0), $next_offset);
        $lock['expires_at'] = time() + self::LOCK_TTL;
        $lock['touched'] = time();

        return self::replace_lock_if_owned($token, $before, $lock);
    }

    private static function queue_step(string $token, int $offset): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(
            'btl_batch_step',
            ['token' => $token, 'offset' => $offset],
            self::GROUP
        )) {
            return;
        }

        $action_id = as_schedule_single_action(
            time() + 1,
            'btl_batch_step',
            [
                'token' => $token,
                'offset' => $offset,
            ],
            self::GROUP
        );

        if ($action_id) {
            return;
        }

        BTL_Helpers::logger(sprintf(
            'Scheduler: failed to schedule next batch step for token %s at offset %d; retrying.',
            $token,
            $offset
        ));

        $action_id = as_schedule_single_action(
            time() + 30,
            'btl_batch_step',
            [
                'token' => $token,
                'offset' => $offset,
            ],
            self::GROUP
        );

        if (!$action_id) {
            BTL_Helpers::logger(
                'Scheduler: batch step could not be scheduled at all — the watchdog will resume it.'
            );
        }
    }

    /**
     * A fatal error inside a worker (OOM, timeout) leaves the chain dead: Action
     * Scheduler marks the action failed and nothing reschedules it, so the rest of
     * the catalog silently keeps stale prices. The watchdog resumes from the last
     * offset the lock recorded.
     */
    private static function arm_watchdog(string $token): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(
            'btl_batch_watchdog',
            ['token' => $token],
            self::GROUP
        )) {
            return;
        }

        as_schedule_single_action(
            time() + self::WATCHDOG_IDLE,
            'btl_batch_watchdog',
            ['token' => $token],
            self::GROUP
        );
    }

    private static function clear_watchdog(string $token): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('btl_batch_watchdog', ['token' => $token], self::GROUP);
        }
    }

    public static function watchdog($token = ''): void
    {
        $token = (string) $token;

        if ($token === '') {
            return;
        }

        $lock = self::read_lock();

        // The batch either finished (lock deleted) or a newer batch owns the lock.
        if ($lock === null || ($lock['token'] ?? '') !== $token) {
            return;
        }

        $touched = (int) ($lock['touched'] ?? 0);
        $resume_from = max(0, (int) ($lock['offset'] ?? 0));

        // Still making progress: check again later.
        if ($touched > 0 && (time() - $touched) < self::WATCHDOG_IDLE) {
            self::arm_watchdog($token);
            return;
        }

        if (!empty($lock['step_running']) && (int) ($lock['step_lease'] ?? 0) > time()) {
            // The worker still owns its step lease; do not start a duplicate window.
            self::arm_watchdog($token);
            return;
        }

        if (
            function_exists('as_has_scheduled_action') &&
            as_has_scheduled_action(
                'btl_batch_step',
                ['token' => $token, 'offset' => $resume_from],
                self::GROUP
            )
        ) {
            self::arm_watchdog($token);
            return;
        }

        $resumes = (int) ($lock['resumes'] ?? 0);
        $lock_before = $lock;

        if ($resumes >= self::MAX_RESUMES) {
            BTL_Helpers::logger(sprintf(
                'Scheduler: abandoning batch %s after %d resume attempts (last offset %d).',
                $token,
                $resumes,
                $resume_from
            ));

            self::finalize($token);
            return;
        }

        // The same offset died twice: skip that window so one poison product cannot
        // block the rest of the catalog, and say so loudly in the log.
        if ((int) ($lock['resume_offset'] ?? -1) === $resume_from) {
            $skip_to = $resume_from + self::get_batch_size();

            BTL_Helpers::logger(sprintf(
                'Scheduler: batch %s stalled twice at offset %d — skipping to %d.',
                $token,
                $resume_from,
                $skip_to
            ));

            $resume_from = $skip_to;
        }

        $lock['expires_at'] = time() + self::LOCK_TTL;
        $lock['touched'] = time();
        $lock['offset'] = $resume_from;
        $lock['resumes'] = $resumes + 1;
        $lock['resume_offset'] = $resume_from;

        if (!self::replace_lock_if_owned($token, $lock_before ?? [], $lock)) {
            return;
        }

        BTL_Helpers::logger(sprintf(
            'Scheduler: watchdog resuming stalled batch %s at offset %d.',
            $token,
            $resume_from
        ));

        self::arm_watchdog($token);
        self::queue_step($token, $resume_from);
    }

    private static function finalize(string $token): void
    {
        $lock = self::read_lock();
        if ($lock === null || ($lock['token'] ?? '') !== $token) {
            return;
        }

        $state = self::read_state($token);
        $changed = $state !== null ? (int) ($state['changed'] ?? 0) : 0;

        self::cleanup_storage($token);
        self::delete_lock_if_owned($token);
        self::clear_watchdog($token);

        if ($changed > 0 && function_exists('btl_queue_revalidation')) {
            // Listing queries carry the broad products tag alongside category tags.
            btl_queue_revalidation(['products']);
        }

        $pending = self::take_pending_request();
        if ($pending !== null) {
            self::schedule($pending['currencies'] ?? []);
        }
    }

    private static function normalize_currencies(array $currencies): array
    {
        $currencies = array_map(
            static fn($value): string => strtoupper(trim((string) $value)),
            $currencies
        );

        $currencies = array_values(array_unique(array_filter(
            $currencies,
            static fn(string $value): bool => $value !== ''
        )));

        return $currencies;
    }

    private static function queue_pending_request(array $currencies): void
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $option_current = get_option(self::PENDING_OPTION, null);
            $legacy_current = $option_current === null ? get_transient(self::PENDING_KEY) : null;
            $current = is_array($option_current)
                ? $option_current
                : (is_array($legacy_current) ? $legacy_current : null);
            $next = $current ?? [
                'full' => empty($currencies),
                'currencies' => $currencies,
            ];

            if ($current !== null && !empty($current['full'])) {
                return;
            }
            if ($current !== null && empty($currencies)) {
                $next['full'] = true;
                $next['currencies'] = [];
            } elseif ($current !== null) {
                $next['currencies'] = self::normalize_currencies(array_merge(
                    (array) ($current['currencies'] ?? []),
                    $currencies
                ));
            }

            $saved = $option_current === null
                ? add_option(self::PENDING_OPTION, $next, '', 'no')
                : self::compare_and_swap_option(self::PENDING_OPTION, $current, $next);
            if ($saved) {
                if ($legacy_current !== null) {
                    delete_transient(self::PENDING_KEY);
                }
                return;
            }
        }

        BTL_Helpers::logger('Scheduler: pending request CAS retries exhausted.');
    }

    private static function read_pending_request(): ?array
    {
        $pending = get_option(self::PENDING_OPTION, null);
        if (is_array($pending)) {
            return $pending;
        }

        // Read legacy transient state during the compatibility window.
        $pending = get_transient(self::PENDING_KEY);
        return is_array($pending) ? $pending : null;
    }

    private static function take_pending_request(): ?array
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $pending = get_option(self::PENDING_OPTION, null);
            if (is_array($pending)) {
                if (self::compare_and_swap_option(self::PENDING_OPTION, $pending, null)) {
                    delete_transient(self::PENDING_KEY);
                    return $pending;
                }
                continue;
            }

            $legacy = get_transient(self::PENDING_KEY);
            if (is_array($legacy)) {
                delete_transient(self::PENDING_KEY);
                return $legacy;
            }
            return null;
        }

        return null;
    }

    private static function clear_pending_request(): void
    {
        delete_option(self::PENDING_OPTION);
        delete_transient(self::PENDING_KEY);
    }

    private static function read_lock(): ?array
    {
        $raw = get_option(self::LOCK_OPTION, null);

        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function lock_is_active(array $lock): bool
    {
        return !empty($lock['token'])
            && max((int) ($lock['expires_at'] ?? 0), (int) ($lock['step_lease'] ?? 0)) > time();
    }

    private static function compare_and_swap_option(string $name, $old, $new): bool
    {
        if ($old === null) {
            if ($new === null) {
                return get_option($name, null) === null;
            }
            return add_option($name, $new, '', 'no');
        }

        global $wpdb;
        if ($new === null) {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",
                $name,
                maybe_serialize($old)
            ));
            if ($deleted) {
                wp_cache_delete($name, 'options');
            }
            return (bool) $deleted;
        }
        $old_serialized = maybe_serialize($old);
        $new_serialized = maybe_serialize($new);
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value=%s
              WHERE option_name=%s AND option_value=%s",
            $new_serialized,
            $name,
            $old_serialized
        ));

        if ($updated === 1) {
            wp_cache_delete($name, 'options');
            return true;
        }

        return false;
    }

    private static function replace_lock_if_owned(string $token, array $before, array $after): bool
    {
        if (($before['token'] ?? '') !== $token || ($after['token'] ?? '') !== $token) {
            return false;
        }

        return self::compare_and_swap_option(
            self::LOCK_OPTION,
            wp_json_encode($before),
            wp_json_encode($after)
        );
    }

    private static function delete_lock_if_owned($owner): bool
    {
        $token = is_array($owner) ? (string) ($owner['token'] ?? '') : (string) $owner;
        if ($token === '') {
            return false;
        }

        global $wpdb;
        $raw = get_option(self::LOCK_OPTION, null);
        $lock = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($lock) || ($lock['token'] ?? '') !== $token || !is_string($raw)) {
            return false;
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",
            self::LOCK_OPTION,
            $raw
        ));
        if ($deleted) {
            wp_cache_delete(self::LOCK_OPTION, 'options');
        }
        return (bool) $deleted;
    }

    private static function cleanup_storage(string $token): void
    {
        if ($token === '') {
            return;
        }

        $state = get_option(self::state_key($token), null);
        $rows = is_array($state) ? (int) ($state['rows'] ?? 0) : 0;

        for ($row = 0; $row < $rows; $row++) {
            delete_option(self::chunk_key($token, $row));
        }

        if ($rows === 0) {
            // Orphaned rows (state row lost, chunks left behind).
            global $wpdb;

            $like = $wpdb->esc_like(self::STATE_PREFIX . $token . self::CHUNK_SUFFIX) . '%';
            $names = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            ));

            foreach ((array) $names as $name) {
                delete_option($name);
            }
        }

        delete_option(self::state_key($token));
    }

    /** @deprecated Legacy compatibility for already scheduled actions. */
    public static function legacy_forwarder($offset = 0, $iteration = 0): void
    {
    }

    /** @deprecated Legacy compatibility for already scheduled actions. */
    public static function legacy_chunk_forwarder($products = []): void
    {
        self::process_chunk(is_array($products) ? $products : []);
    }

    /** @deprecated Legacy compatibility for already scheduled actions. */
    public static function legacy_cleanup(): void
    {
        $lock = self::read_lock();
        if ($lock !== null && !self::lock_is_active($lock)) {
            self::cleanup_storage((string) ($lock['token'] ?? ''));
            self::delete_lock_if_owned($lock);
        }
    }
}
