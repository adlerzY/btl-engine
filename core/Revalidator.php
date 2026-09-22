<?php
defined('ABSPATH') || exit;

final class BTL_Revalidator
{
    private const GROUP = 'btl';
    private const LEGACY_CACHE_KEY = 'btl_revalidate_tags';
    private const LOCK_OPTION = 'btl_revalidate_lock_v2';
    private const FALLBACK_OPTION = 'btl_revalidate_queue_fallback_v2';
    private const FAILED_OPTION = 'btl_revalidate_failed_tags_v2';
    private const QUEUE_TTL = 3600;
    private const FAILED_TTL = 86400;
    private const BATCH_SIZE = 1000;
    private const MAX_QUEUE_SIZE = 20000;
    private const MAX_RETRIES = 3;
    private const FLUSH_DELAY = 15;
    private const NEXT_BATCH_DELAY = 5;
    private const RETRY_DELAY = 60;
    private const CLAIM_LEASE = 120;

    private static bool $flushScheduledThisRequest = false;
    private static bool $tableReady = false;

    public static function boot(): void
    {
        add_action('btl_revalidate_flush', [self::class, 'flush'], 10);
    }

    public static function queue(array $tags): void
    {
        if (!self::enqueue($tags)) {
            return;
        }

        if (self::$flushScheduledThisRequest) {
            return;
        }

        self::$flushScheduledThisRequest = true;
        self::schedule_flush(self::FLUSH_DELAY);
    }

    /**
     * Idempotent temporary schema installation. Move this to Migrations.php later.
     */
    public static function install_queue_table(): void
    {
        if (self::$tableReady) {
            return;
        }

        global $wpdb;
        $table = self::table();

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $sql = "CREATE TABLE {$table} (
            tag varchar(191) NOT NULL,
            state varchar(12) NOT NULL DEFAULT 'pending',
            claim_token varchar(64) NULL,
            lease_until bigint unsigned NOT NULL DEFAULT 0,
            attempts smallint unsigned NOT NULL DEFAULT 0,
            available_at bigint unsigned NOT NULL DEFAULT 0,
            created_at bigint unsigned NOT NULL DEFAULT 0,
            updated_at bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (tag),
            KEY state_available (state, available_at),
            KEY claim_lease (claim_token, lease_until)
        ) {$wpdb->get_charset_collate()} ENGINE=InnoDB;";

        try {
            dbDelta($sql);
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                throw new RuntimeException('queue table was not created');
            }
            self::$tableReady = true;

            $legacy = get_transient(self::LEGACY_CACHE_KEY);
            if (is_array($legacy) && $legacy && self::enqueue($legacy)) {
                delete_transient(self::LEGACY_CACHE_KEY);
            }

            $fallback = get_option(self::FALLBACK_OPTION, []);
            if (is_array($fallback) && $fallback && self::enqueue($fallback)) {
                self::compare_option_array(self::FALLBACK_OPTION, $fallback, []);
            }
        } catch (Throwable $e) {
            BTL_Helpers::logger('Revalidator: queue table installation failed — ' . $e->getMessage());
        }
    }

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'btl_revalidate_queue';
    }

    private static function ensure_table(): bool
    {
        if (!self::$tableReady) {
            self::install_queue_table();
        }

        return self::$tableReady;
    }

    private static function normalize_tags(array $tags): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn($tag): string => trim((string) $tag), $tags),
            static fn(string $tag): bool => $tag !== ''
        )));
    }

    private static function enqueue(array $tags, bool $prepend = false): bool
    {
        $tags = self::normalize_tags($tags);
        if (!$tags) {
            return false;
        }

        if (!self::ensure_table()) {
            // Options are the durable emergency fallback if the temporary table
            // cannot be installed. The CAS loop prevents lost concurrent writes.
            return self::enqueue_fallback($tags, $prepend);
        }

        global $wpdb;
        $now = time();
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($tags as $tag) {
                if ($wpdb->query($wpdb->prepare(
                    "INSERT INTO " . self::table() . "
                        (tag, state, claim_token, lease_until, attempts, available_at, created_at, updated_at)
                     VALUES (%s, 'pending', NULL, 0, 0, %d, %d, %d)
                     ON DUPLICATE KEY UPDATE
                        attempts = IF(state='failed', 0, attempts),
                        claim_token = IF(state IN ('failed','claimed'), NULL, claim_token),
                        lease_until = IF(state IN ('failed','claimed'), 0, lease_until),
                        state = IF(state IN ('failed','claimed'), 'pending', state),
                        updated_at = VALUES(updated_at),
                        available_at = LEAST(available_at, VALUES(available_at))",
                    $tag,
                    $now,
                    $now,
                    $now
                )) === false) {
                    throw new RuntimeException('queue insert failed');
                }
            }

            $count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . self::table() . " WHERE state IN ('pending','claimed')"
            );

            if ($count > self::MAX_QUEUE_SIZE) {
                BTL_Helpers::logger(sprintf(
                    'Revalidator: queue overflow (%d), collapsing pending tags to controlled catalog tags.',
                    $count
                ));
                if ($wpdb->query("DELETE FROM " . self::table() . " WHERE state='pending'") === false) {
                    throw new RuntimeException('queue collapse failed');
                }
                foreach (['products', 'home-featured', 'home-latest'] as $tag) {
                    if ($wpdb->query($wpdb->prepare(
                        "INSERT INTO " . self::table() . "
                            (tag,state,claim_token,lease_until,attempts,available_at,created_at,updated_at)
                         VALUES (%s,'pending',NULL,0,0,%d,%d,%d)
                         ON DUPLICATE KEY UPDATE state='pending',claim_token=NULL,lease_until=0,attempts=0,available_at=VALUES(available_at),updated_at=VALUES(updated_at)",
                        $tag, $now, $now, $now
                    )) === false) {
                        throw new RuntimeException('queue collapse insert failed');
                    }
                }
            }

            $wpdb->query('COMMIT');
            return true;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            BTL_Helpers::logger('Revalidator: queue insert failed — ' . $e->getMessage());
            return false;
        }
    }

    private static function enqueue_fallback(array $tags, bool $prepend = false): bool
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $current = get_option(self::FALLBACK_OPTION, []);
            $current = is_array($current) ? self::normalize_tags($current) : [];
            $next = $prepend
                ? array_values(array_unique(array_merge($tags, $current)))
                : array_values(array_unique(array_merge($current, $tags)));
            if (count($next) > self::MAX_QUEUE_SIZE) {
                $next = ['products', 'home-featured', 'home-latest'];
            }

            if (self::compare_option_array(self::FALLBACK_OPTION, $current, $next)) {
                return true;
            }
        }

        BTL_Helpers::logger('Revalidator: durable fallback queue CAS retries exhausted.');
        return false;
    }

    private static function fallback_batch(): array
    {
        $queued = get_option(self::FALLBACK_OPTION, []);
        return is_array($queued) ? array_slice(self::normalize_tags($queued), 0, self::BATCH_SIZE) : [];
    }

    private static function remove_fallback(array $batch): void
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $current = get_option(self::FALLBACK_OPTION, []);
            $current = is_array($current) ? self::normalize_tags($current) : [];
            $next = array_values(array_diff($current, $batch));
            if (self::compare_option_array(self::FALLBACK_OPTION, $current, $next)) {
                return;
            }
        }
    }

    private static function compare_option_array(string $name, array $old, array $new): bool
    {
        global $wpdb;
        $oldRaw = maybe_serialize($old);
        $newRaw = maybe_serialize($new);
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value=%s
              WHERE option_name=%s AND option_value=%s",
            $newRaw,
            $name,
            $oldRaw
        ));

        if ($updated === 1) {
            wp_cache_delete($name, 'options');
            return true;
        }

        if ($old === [] && add_option($name, $new, '', 'no')) {
            return true;
        }

        return false;
    }

    private static function schedule_flush(int $delay, bool $force = false): void
    {
        if (!function_exists('as_schedule_single_action')) {
            $timestamp = wp_next_scheduled('btl_revalidate_flush');
            if ($force || !$timestamp) {
                if ($timestamp && $force) wp_unschedule_event($timestamp, 'btl_revalidate_flush');
                wp_schedule_single_event(time() + max(1, $delay), 'btl_revalidate_flush');
            }
            return;
        }

        if (
            !$force &&
            function_exists('as_has_scheduled_action') &&
            as_has_scheduled_action('btl_revalidate_flush', [], self::GROUP)
        ) {
            return;
        }

        as_schedule_single_action(
            time() + max(1, $delay),
            'btl_revalidate_flush',
            [],
            self::GROUP
        );
    }

    public static function flush(): void
    {
        $token = self::acquire_lock();
        if ($token === null) {
            // A live owner may die after this action starts. Leave a durable retry
            // action rather than assuming its finally block will run.
            self::schedule_flush(30);
            return;
        }

        $reschedule_in = 0;

        try {
            if (!self::is_configured()) {
                $reschedule_in = self::has_pending() ? 300 : 0;
                BTL_Helpers::logger(
                    'Revalidator: NEXTJS_API_URL or REVALIDATION_SECRET is not configured; tags remain queued.'
                );
                return;
            }

            $table_available = self::ensure_table();
            $batch = $table_available
                ? self::claim_batch($token)
                : self::fallback_batch();
            if (!$batch) {
                $reschedule_in = self::has_pending() ? self::RETRY_DELAY : 0;
                return;
            }

            $result = self::send($batch);
            if (!$table_available) {
                if ($result['ok']) {
                    self::remove_fallback($batch);
                    $reschedule_in = self::has_pending() ? self::NEXT_BATCH_DELAY : 0;
                } else {
                    $reschedule_in = self::RETRY_DELAY;
                }
                return;
            }
            $failed = self::normalize_tags((array) ($result['failed'] ?? []));
            $failed = array_values(array_intersect($batch, $failed));

            if ($result['ok']) {
                self::delete_claimed($token);
                $reschedule_in = self::has_pending() ? self::NEXT_BATCH_DELAY : 0;
                return;
            }

            if ((int) ($result['code'] ?? 0) === 429) {
                self::release_claimed($token, self::RETRY_DELAY, false, $batch);
                $reschedule_in = max(
                    self::RETRY_DELAY,
                    min(600, (int) ($result['retry_after'] ?? 0))
                );
                BTL_Helpers::logger(sprintf(
                    'Revalidator: frontend rate limit; retrying in %d seconds.',
                    $reschedule_in
                ));
                return;
            }

            self::release_claimed($token, self::RETRY_DELAY, true, $failed);
            if ($failed) {
                self::remember_failed_tags($failed);
                $reschedule_in = self::RETRY_DELAY;
            } else {
                $reschedule_in = self::has_pending() ? self::NEXT_BATCH_DELAY : 0;
            }
        } finally {
            self::release_lock($token);

            if ($reschedule_in > 0) {
                self::schedule_flush($reschedule_in, true);
            }
        }
    }

    private static function claim_batch(string $token): array
    {
        if (!self::ensure_table()) {
            return [];
        }

        global $wpdb;
        $now = time();
        $lease = $now + self::CLAIM_LEASE;
        $table = self::table();

        $wpdb->query('START TRANSACTION');
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT tag FROM {$table}
                 WHERE available_at <= %d
                   AND (state = 'pending' OR state = 'failed' OR (state = 'claimed' AND lease_until < %d))
                 ORDER BY created_at ASC, tag ASC
                 LIMIT %d
                 FOR UPDATE",
                $now,
                $now,
                self::BATCH_SIZE
            ));

            if (!$rows) {
                $wpdb->query('COMMIT');
                return [];
            }

            $tags = array_values(array_filter(array_map(
                static fn($row): string => (string) ($row->tag ?? ''),
                $rows
            )));
            $placeholders = implode(',', array_fill(0, count($tags), '%s'));
            $args = array_merge([$token, $lease, $now], $tags);

            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                    SET state='claimed', attempts=IF(state='failed', 0, attempts),
                        claim_token=%s, lease_until=%d, updated_at=%d
                  WHERE tag IN ({$placeholders})
                    AND (state = 'pending' OR state = 'failed' OR (state = 'claimed' AND lease_until < %d))",
                array_merge($args, [$now])
            ));

            if ($updated !== count($tags)) {
                $wpdb->query('ROLLBACK');
                return [];
            }

            $wpdb->query('COMMIT');
            return $tags;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            BTL_Helpers::logger('Revalidator: claim failed — ' . $e->getMessage());
            return [];
        }
    }

    private static function delete_claimed(string $token): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE state='claimed' AND claim_token=%s",
            $token
        ));
    }

    private static function release_claimed(
        string $token,
        int $delay,
        bool $countAttempt,
        array $failedTags = []
    ): void {
        global $wpdb;
        $now = time();
        $table = self::table();

        if (!$countAttempt) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                    SET state='pending', claim_token=NULL, lease_until=0,
                        available_at=%d, updated_at=%d
                  WHERE state='claimed' AND claim_token=%s",
                $now + max(1, $delay),
                $now,
                $token
            ));
            return;
        }

        $failedTags = self::normalize_tags($failedTags);
        if (!$failedTags) {
            // No structured failed list means the entire request failed.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                    SET state=IF(attempts+1 >= %d, 'failed', 'pending'),
                        attempts=attempts+1,
                        claim_token=NULL, lease_until=0,
                        available_at=IF(attempts+1 >= %d, %d, %d), updated_at=%d
                  WHERE state='claimed' AND claim_token=%s",
                self::MAX_RETRIES,
                $now + HOUR_IN_SECONDS,
                $now + max(1, $delay),
                $now,
                $token
            ));
            return;
        }

        $placeholders = implode(',', array_fill(0, count($failedTags), '%s'));
        $args = array_merge([$token], $failedTags);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
              WHERE state='claimed' AND claim_token=%s AND tag NOT IN ({$placeholders})",
            $args
        ));

        $args = array_merge([
            self::MAX_RETRIES,
            $now + HOUR_IN_SECONDS,
            $now + max(1, $delay),
            $now,
            $token,
        ], $failedTags);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET state=IF(attempts+1 >= %d, 'failed', 'pending'),
                    attempts=attempts+1,
                    claim_token=NULL, lease_until=0,
                    available_at=IF(attempts+1 >= %d, %d, %d), updated_at=%d
              WHERE state='claimed' AND claim_token=%s AND tag IN ({$placeholders})",
            $args
        ));
    }

    private static function has_pending(): bool
    {
        if (!self::ensure_table()) {
            $fallback = get_option(self::FALLBACK_OPTION, []);
            return is_array($fallback) && (bool) $fallback;
        }

        global $wpdb;
        $now = time();
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM " . self::table() . "
             WHERE available_at <= %d
               AND (state='pending' OR state='failed' OR (state='claimed' AND lease_until < %d))
             LIMIT 1",
            $now,
            $now
        ));
    }

    private static function acquire_lock(): ?string
    {
        global $wpdb;
        $token = wp_generate_password(48, false, false);
        $raw = wp_json_encode(['token' => $token, 'expires_at' => time() + 300]);

        if (add_option(self::LOCK_OPTION, $raw, '', 'no')) {
            return $token;
        }

        $existingRaw = get_option(self::LOCK_OPTION, null);
        $existing = is_string($existingRaw) ? json_decode($existingRaw, true) : null;
        if (is_array($existing) && (int) ($existing['expires_at'] ?? 0) > time()) {
            return null;
        }

        if (is_string($existingRaw) && $existingRaw !== '') {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",
                self::LOCK_OPTION,
                $existingRaw
            ));
            if (!$deleted) {
                return null;
            }
            wp_cache_delete(self::LOCK_OPTION, 'options');
        }

        return add_option(self::LOCK_OPTION, $raw, '', 'no') ? $token : null;
    }

    private static function release_lock(string $token): void
    {
        global $wpdb;
        $raw = get_option(self::LOCK_OPTION, null);
        $lock = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($lock) || ($lock['token'] ?? '') !== $token) {
            return;
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",
            self::LOCK_OPTION,
            $raw
        ));
        if ($deleted) {
            wp_cache_delete(self::LOCK_OPTION, 'options');
        }
    }

    private static function remember_failed_tags(array $tags): void
    {
        $tags = self::normalize_tags($tags);
        if (!$tags) {
            return;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $old = get_option(self::FAILED_OPTION, null);
            $oldTags = is_array($old) ? self::normalize_tags($old) : [];
            $newTags = array_values(array_unique(array_merge($oldTags, $tags)));

            if ($old === null) {
                if (add_option(self::FAILED_OPTION, $newTags, '', 'no')) {
                    return;
                }
                continue;
            }

            global $wpdb;
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value=%s
                  WHERE option_name=%s AND option_value=%s",
                maybe_serialize($newTags),
                self::FAILED_OPTION,
                maybe_serialize($old)
            ));
            if ($updated === 1) {
                return;
            }
        }
    }

    private static function is_configured(): bool
    {
        return defined('NEXTJS_API_URL')
            && NEXTJS_API_URL !== ''
            && self::get_secret() !== '';
    }

    private static function get_secret(): string
    {
        if (defined('REVALIDATION_SECRET') && REVALIDATION_SECRET !== '') {
            return (string) REVALIDATION_SECRET;
        }
        if (defined('NEXTJS_REVALIDATE_SECRET') && NEXTJS_REVALIDATE_SECRET !== '') {
            return (string) NEXTJS_REVALIDATE_SECRET;
        }
        return '';
    }

    /** @return array{ok:bool,failed:array<int,string>,code:int,retry_after:int} */
    private static function send(array $tags): array
    {
        if (!$tags || !self::is_configured()) {
            return ['ok' => !$tags, 'failed' => $tags, 'code' => 0, 'retry_after' => 0];
        }

        $response = wp_remote_post(NEXTJS_API_URL, [
            'timeout' => 15,
            'blocking' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-revalidate-secret' => self::get_secret(),
            ],
            'body' => wp_json_encode(['tag' => array_values($tags)]),
        ]);

        if (is_wp_error($response)) {
            BTL_Helpers::logger('Revalidator: transport error — ' . $response->get_error_message());
            return ['ok' => false, 'failed' => $tags, 'code' => 0, 'retry_after' => 0];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            $decoded = json_decode($body, true);
            $failed = is_array($decoded['failed'] ?? null)
                ? self::normalize_tags($decoded['failed'])
                : $tags;

            BTL_Helpers::logger(sprintf('Revalidator: HTTP %d — %s', $code, $body));
            return [
                'ok' => false,
                'failed' => $failed,
                'code' => $code,
                'retry_after' => (int) wp_remote_retrieve_header($response, 'retry-after'),
            ];
        }

        BTL_Helpers::logger('Revalidator: sent ' . count($tags) . ' tags successfully.');
        return ['ok' => true, 'failed' => [], 'code' => $code, 'retry_after' => 0];
    }
}

function btl_queue_revalidation(array $tags): void
{
    BTL_Revalidator::queue($tags);
}
