<?php
defined('ABSPATH') || exit;

final class BTL_CdKey_Stock
{
    private const READY_OPTION = 'btl_cdkey_stock_table_ready_v2';
    private const STOCK_DISPLAY_TTL = 20;
    private const STOCK_DISPLAY_CACHE_GROUP = 'btl_cdkey_stock';
    private const BACKFILL_LIMIT = 200;
    private const RESERVATION_TTL = 3600;

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_cdkey_stock'; }

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 20);
        add_action('woocommerce_order_status_changed', [self::class, 'maybe_assign_on_status_change'], 20, 4);
        add_action('before_delete_post', [self::class, 'release_deleted_order'], 10);
        add_action('woocommerce_before_delete_order', [self::class, 'release_deleted_order'], 10);
        add_action('btl_cdkey_cleanup_orphans', [self::class, 'cleanupOrphanReservations']);
        add_action('btl_cdkey_backfill', [self::class, 'backfillPendingOrders'], 10, 3);
    }

    public static function maybe_install(): void { BTL_Helpers::ensureTable(self::READY_OPTION, [self::class, 'install']); }
    public static function install(): void
    {
        global $wpdb;
        $sql = "CREATE TABLE " . self::table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ciphertext TEXT NOT NULL,
            key_fingerprint VARBINARY(32) NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'available',
            order_id BIGINT UNSIGNED NULL,
            item_id BIGINT UNSIGNED NULL,
            reservation_token VARCHAR(64) NULL,
            reserved_at DATETIME NULL,
            added_by BIGINT UNSIGNED NULL,
            assignment_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            failure_reason VARCHAR(190) NULL,
            failed_at DATETIME NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_plaintext (key_fingerprint),
            KEY product_variation_status (product_id, variation_id, status),
            KEY order_item_status (order_id, item_id, status),
            KEY reservation_status (reservation_token, status),
            KEY status_reserved_at (status, reserved_at)
        ) " . $wpdb->get_charset_collate() . ' ENGINE=InnoDB;';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function schedule_cleanup(): void
    {
        if (function_exists('as_next_scheduled_action')) {
            if (!as_next_scheduled_action('btl_cdkey_cleanup_orphans', [], 'btl')) as_schedule_recurring_action(time() + 300, 300, 'btl_cdkey_cleanup_orphans', [], 'btl');
        } elseif (!wp_next_scheduled('btl_cdkey_cleanup_orphans')) {
            wp_schedule_event(time() + 300, 'hourly', 'btl_cdkey_cleanup_orphans');
        }
    }

    public static function add(int $productId, int $variationId, string $plaintext, int $staffUserId, bool $invalidateCache = true): bool
    {
        global $wpdb;
        $plaintext = trim($plaintext);
        if ($productId < 1 || $variationId < 1 || $plaintext === '') return false;
        try {
            $ciphertext = BTL_Secure_Vault::encrypt($plaintext);
            $fingerprint = BTL_Secure_Vault::fingerprint($plaintext);
        } catch (Throwable $e) {
            BTL_Helpers::logger('CdKeyStock: encryption failed');
            return false;
        }
        $inserted = $wpdb->insert(self::table(), [
            'product_id' => $productId,
            'variation_id' => $variationId,
            'ciphertext' => $ciphertext,
            'key_fingerprint' => $fingerprint,
            'status' => 'available',
            'added_by' => $staffUserId ?: null,
        ], ['%d','%d','%s','%s','%s','%d']);
        if ($inserted === false) return false;
        if ($invalidateCache) self::invalidateStockCache($productId, $variationId);
        return true;
    }

    public static function bulkAdd(int $productId, int $variationId, array $plaintextKeys, int $staffUserId): int
    {
        global $wpdb;
        if ($productId < 1 || $variationId < 1) return 0;

        $rows = [];
        foreach ($plaintextKeys as $rawKey) {
            $plaintext = trim((string)$rawKey);
            if ($plaintext === '') continue;
            try {
                $rows[] = [
                    'product_id' => $productId,
                    'variation_id' => $variationId,
                    'ciphertext' => BTL_Secure_Vault::encrypt($plaintext),
                    'key_fingerprint' => BTL_Secure_Vault::fingerprint($plaintext),
                    'status' => 'available',
                    'added_by' => $staffUserId ?: null,
                ];
            } catch (Throwable $e) {
                BTL_Helpers::logger('CdKeyStock: bulk encryption failed');
            }
        }

        $inserted = 0;
        foreach (array_chunk($rows, 100) as $chunk) {
            $valueSql = [];
            $params = [];
            foreach ($chunk as $row) {
                $valueSql[] = '(%d,%d,%s,%s,%s,%d)';
                $params[] = (int)$row['product_id'];
                $params[] = (int)$row['variation_id'];
                $params[] = (string)$row['ciphertext'];
                $params[] = (string)$row['key_fingerprint'];
                $params[] = (string)$row['status'];
                $params[] = (int)($row['added_by'] ?? 0);
            }

            $sql = $wpdb->prepare(
                "INSERT IGNORE INTO " . self::table() . " (product_id,variation_id,ciphertext,key_fingerprint,status,added_by) VALUES " . implode(',', $valueSql),
                $params
            );
            $result = $wpdb->query($sql);
            if ($result !== false) $inserted += (int)$result;
        }

        if ($inserted > 0) {
            self::invalidateStockCache($productId, $variationId);
            self::backfillPendingOrders($productId, $variationId);
            if (class_exists('BTL_Invalidation')) BTL_Invalidation::queueProduct($productId, BTL_Invalidation::SCOPE_PRICING);
        }
        return $inserted;
    }

    public static function availableCount(int $productId, int $variationId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table() . " WHERE product_id=%d AND variation_id=%d AND status='available'", $productId, $variationId));
    }

    public static function deleteUnused(int $stockId): bool
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,product_id,variation_id,status,order_id,item_id FROM " . self::table() . " WHERE id=%d LIMIT 1", $stockId));
        if (!$row || !in_array((string)$row->status, ['available','duplicate','decrypt_failed','failed'], true) || $row->order_id || $row->item_id) return false;
        $deleted = (int)$wpdb->delete(self::table(), ['id' => $stockId], ['%d']);
        if ($deleted === 1) self::invalidateStockCache((int)$row->product_id, (int)$row->variation_id);
        return $deleted === 1;
    }

    /**
     * Batch-load available code-key counts for every variation of one product.
     * This keeps the product detail GraphQL resolver at one stock query instead
     * of one COUNT(*) query per variation.
     *
     * @return array<int, int> variation ID => available count
     */
    private static function cachedAvailableCountsForProduct(int $productId): array
    {
        if ($productId < 1) return [];

        return (array) BTL_Cache::remember(
            "stock_counts_product_{$productId}",
            static function () use ($productId): array {
                global $wpdb;
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT variation_id, COUNT(*) AS row_count
                     FROM " . self::table() . "
                     WHERE product_id=%d AND status='available'
                     GROUP BY variation_id",
                    $productId
                ));

                $counts = [];
                foreach ($rows ?: [] as $row) {
                    $counts[(int)$row->variation_id] = (int)$row->row_count;
                }

                return $counts;
            },
            self::STOCK_DISPLAY_CACHE_GROUP,
            self::STOCK_DISPLAY_TTL
        );
    }

    public static function cachedAvailableCount(int $productId, int $variationId): int
    {
        if ($productId < 1 || $variationId < 1) return 0;
        $counts = self::cachedAvailableCountsForProduct($productId);
        return (int)($counts[$variationId] ?? 0);
    }
    private static function invalidateStockCache(int $productId, int $variationId): void
    {
        BTL_Cache::delete("stock_count_{$productId}_{$variationId}", self::STOCK_DISPLAY_CACHE_GROUP);
        BTL_Cache::delete("stock_counts_product_{$productId}", self::STOCK_DISPLAY_CACHE_GROUP);
        BTL_Cache::flushGroup(self::STOCK_DISPLAY_CACHE_GROUP);
        if (class_exists('BTL_GraphQL')) {
            BTL_GraphQL::invalidate_archive_pricing($productId);
        }
    }

    public static function reserveForOrder(int $orderId, array $requirements, string $token): bool
    {
        global $wpdb;
        if ($orderId < 1 || $token === '' || !$requirements) return false;
        usort($requirements, static fn($a, $b) => [$a['product_id'], $a['variation_id'], $a['item_id']] <=> [$b['product_id'], $b['variation_id'], $b['item_id']]);
        $reserved = [];
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($requirements as $req) {
                $productId = (int) $req['product_id']; $variationId = (int) $req['variation_id']; $itemId = (int) $req['item_id']; $quantity = max(1, (int) $req['quantity']);
                $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE product_id=%d AND variation_id=%d AND status='available' ORDER BY id ASC LIMIT %d FOR UPDATE", $productId, $variationId, $quantity));
                if (count($ids) !== $quantity) throw new RuntimeException('insufficient_stock');
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $sql = $wpdb->prepare("UPDATE " . self::table() . " SET status='reserved',order_id=%d,item_id=%d,reservation_token=%s,reserved_at=%s,failure_reason=NULL,failed_at=NULL WHERE id IN ({$placeholders}) AND status='available'", array_merge([$orderId, $itemId, $token, current_time('mysql', true)], array_map('intval', $ids)));
                if ((int) $wpdb->query($sql) !== $quantity) throw new RuntimeException('reservation_race');
                $reserved[] = [$productId, $variationId];
            }
            $wpdb->query('COMMIT');
            foreach ($reserved as [$p, $v]) self::invalidateStockCache($p, $v);
            return true;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    public static function reserveForItem(int $productId, int $variationId, int $orderId, int $itemId, int $quantity): array
    {
        $reservation = self::reserveForItemWithToken($productId, $variationId, $orderId, $itemId, $quantity);
        return $reservation['ids'];
    }

    public static function reserveForItemWithToken(int $productId, int $variationId, int $orderId, int $itemId, int $quantity): array
    {
        $token = wp_generate_password(40, false, false);
        $ok = self::reserveForOrder($orderId, [['product_id'=>$productId,'variation_id'=>$variationId,'item_id'=>$itemId,'quantity'=>$quantity]], $token);
        if (!$ok) return ['token' => '', 'ids' => []];
        global $wpdb;
        return [
            'token' => $token,
            'ids' => array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE reservation_token=%s AND order_id=%d AND item_id=%d AND status='reserved'", $token, $orderId, $itemId))),
        ];
    }

    public static function reservedCountForItem(int $orderId, int $itemId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE order_id=%d AND item_id=%d AND status='reserved'",
            $orderId,
            $itemId
        ));
    }

    public static function acquireOrderItemLock(int $orderId, int $itemId): ?string
    {
        global $wpdb;
        if ($orderId < 1 || $itemId < 1) return null;
        $name = 'btl_cdkey_item_' . $orderId . '_' . $itemId;
        $ok = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));
        return $ok === 1 ? $name : null;
    }

    public static function releaseOrderItemLock(?string $name): void
    {
        global $wpdb;
        if ($name) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    public static function releaseReservedForOrder(int $orderId, ?string $token = null): int
    {
        global $wpdb;
        $where = $wpdb->prepare('order_id=%d', $orderId);
        if ($token !== null) $where .= $wpdb->prepare(' AND reservation_token=%s', $token);
        $pairs = $wpdb->get_results("SELECT DISTINCT product_id,variation_id FROM " . self::table() . " WHERE {$where} AND status='reserved'");
        $updated = (int) $wpdb->query("UPDATE " . self::table() . " SET status='available',order_id=NULL,item_id=NULL,reservation_token=NULL,reserved_at=NULL WHERE {$where} AND status='reserved'");
        foreach ($pairs ?: [] as $pair) self::invalidateStockCache((int) $pair->product_id, (int) $pair->variation_id);
        return $updated;
    }

    public static function assignReservedForItem(int $orderId, int $itemId, ?string $reservationToken = null, ?int $max = null): int
    {
        global $wpdb;
        $where = "order_id=%d AND item_id=%d AND status IN ('reserved','used')";
        $args = [$orderId, $itemId];
        if ($reservationToken !== null) {
            $where .= ' AND reservation_token=%s';
            $args[] = $reservationToken;
        }
        $limit = $max !== null ? ' LIMIT ' . max(1, (int)$max) : '';
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE {$where} ORDER BY id ASC{$limit}", $args)));
        $assigned = 0;
        foreach ($ids as $id) {
            $wpdb->query('START TRANSACTION');
            try {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d FOR UPDATE", $id));
                if (!$row || (int) $row->order_id !== $orderId || (int) $row->item_id !== $itemId) { $wpdb->query('ROLLBACK'); continue; }
                $sourceKey = 'stock:' . $id;
                if ($row->status === 'used') {
                    $ok = BTL_Secure_Fields::existsSource($orderId, $itemId, 'cdkey', $sourceKey);
                    if (!$ok) {
                        $plain = BTL_Secure_Vault::decrypt((string)$row->ciphertext);
                        $ok = $plain !== null && BTL_Secure_Fields::store($orderId, $itemId, 'cdkey', $plain, $sourceKey);
                    }
                    $wpdb->query($ok ? 'COMMIT' : 'ROLLBACK');
                    if ($ok) $assigned++;
                    else BTL_Helpers::logger("CdKeyStock: used row {$id} is missing secure assignment and could not be repaired");
                    continue;
                }
                $plain = BTL_Secure_Vault::decrypt((string) $row->ciphertext);
                if ($plain === null) {
                    $wpdb->update(self::table(), ['status'=>'decrypt_failed','failure_reason'=>'decrypt_failed','failed_at'=>current_time('mysql', true),'assignment_attempts'=>(int)$row->assignment_attempts + 1], ['id'=>$id]);
                    $wpdb->query('COMMIT');
                    continue;
                }
                if (!BTL_Secure_Fields::store($orderId, $itemId, 'cdkey', $plain, $sourceKey)) throw new RuntimeException('secure_store_failed');
                $ok = $wpdb->update(self::table(), ['status'=>'used','used_at'=>current_time('mysql', true),'failure_reason'=>null,'failed_at'=>null,'assignment_attempts'=>(int)$row->assignment_attempts + 1], ['id'=>$id,'status'=>'reserved']);
                if ($ok === false || $ok === 0) throw new RuntimeException('stock_update_failed');
                $wpdb->query('COMMIT');
                $assigned++;
            } catch (Throwable $e) {
                $wpdb->query('ROLLBACK');
                BTL_Helpers::logger("CdKeyStock: assignment failed for stock row {$id}");
            }
        }
        return $assigned;
    }

    public static function ensureReservationsForItem(int $orderId, int $itemId, int $productId, int $variationId, int $quantity): bool
    {
        global $wpdb;
        $assigned = BTL_Secure_Fields::countByOrderItem($orderId, $itemId, 'cdkey');
        $reserved = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table() . " WHERE order_id=%d AND item_id=%d AND status='reserved'", $orderId, $itemId));
        $missing = max(0, $quantity - $assigned - $reserved);
        if ($missing === 0) return true;
        return self::reserveForOrder($orderId, [['product_id'=>$productId,'variation_id'=>$variationId,'item_id'=>$itemId,'quantity'=>$missing]], wp_generate_password(40, false, false));
    }

    public static function maybe_assign_on_status_change($orderId, $oldStatus, $newStatus, $order): void
    {
        $orderId = (int) $orderId;
        if (in_array($newStatus, ['cancelled','failed','refunded','expired'], true)) { self::releaseReservedForOrder($orderId); return; }
        if (!in_array($newStatus, ['processing','completed'], true)) return;
        $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);
        if (!$order) return;
        foreach ($order->get_items('line_item') as $itemId => $item) {
            if ($item->get_meta('روش تحویل') !== 'code') continue;
            $product = $item->get_product(); if (!$product) continue;
            $variationId = (int) $item->get_variation_id();
            $productId = $variationId ? (int) $product->get_parent_id() : (int) $item->get_product_id();
            $needed = max(1, (int) $item->get_quantity());
            for ($attempt = 0; $attempt < 3; $attempt++) {
                if (BTL_Secure_Fields::countByOrderItem($orderId, (int) $itemId, 'cdkey') >= $needed) break;
                if (!self::ensureReservationsForItem($orderId, (int) $itemId, $productId, $variationId, $needed)) break;
                self::assignReservedForItem($orderId, (int) $itemId);
            }
        }
    }

    public static function release_deleted_order(int $postId): void
    {
        if (get_post_type($postId) === 'shop_order') self::releaseReservedForOrder($postId);
    }

    public static function cleanupOrphanReservations(int $limit = 500): int
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT order_id FROM " . self::table() . " WHERE status='reserved' AND reserved_at < %s ORDER BY reserved_at ASC LIMIT %d", gmdate('Y-m-d H:i:s', time() - self::RESERVATION_TTL), $limit));
        $released = 0;
        foreach ($rows ?: [] as $row) {
            $orderId = (int) $row->order_id; $order = $orderId ? wc_get_order($orderId) : false;
            if (!$order || in_array($order->get_status(), ['cancelled','failed','refunded','expired','trash'], true)) $released += self::releaseReservedForOrder($orderId);
            elseif (in_array($order->get_status(), ['pending', 'on-hold', 'checkout-draft'], true) && strtotime((string)$order->get_date_created()) < time() - self::RESERVATION_TTL) $released += self::releaseReservedForOrder($orderId);
            elseif (in_array($order->get_status(), ['processing','completed'], true)) self::maybe_assign_on_status_change($orderId, $order->get_status(), $order->get_status(), $order);
        }
        return $released;
    }

    public static function backfillPendingOrders(int $productId, int $variationId, int $afterItemId = 0): void
    {
        global $wpdb;
        $itemIds = $wpdb->get_col($wpdb->prepare("SELECT oi.order_item_id FROM {$wpdb->prefix}woocommerce_order_items oi INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pm ON pm.order_item_id=oi.order_item_id AND pm.meta_key='_product_id' AND pm.meta_value=%d LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta vm ON vm.order_item_id=oi.order_item_id AND vm.meta_key='_variation_id' WHERE oi.order_item_type='line_item' AND COALESCE(vm.meta_value,0)=%d AND oi.order_item_id>%d ORDER BY oi.order_item_id ASC LIMIT %d", $productId, $variationId, $afterItemId, self::BACKFILL_LIMIT));
        $stockExhausted = false;
        foreach ($itemIds ?: [] as $itemId) {
            $item = btl_get_order_item_cached((int)$itemId);
            if (!$item || $item->get_meta('روش تحویل') !== 'code') continue;
            $order = wc_get_order($item->get_order_id());
            if (!$order || !in_array($order->get_status(), ['processing','completed'], true)) continue;
            $needed = max(1, (int)$item->get_quantity());
            if (BTL_Secure_Fields::countByOrderItem((int)$order->get_id(), (int)$itemId, 'cdkey') >= $needed) continue;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                if (BTL_Secure_Fields::countByOrderItem((int) $order->get_id(), (int) $itemId, 'cdkey') >= $needed) break;
                if (!self::ensureReservationsForItem((int) $order->get_id(), (int) $itemId, $productId, $variationId, $needed)) {
                    $stockExhausted = true;
                    break 2;
                }
                self::assignReservedForItem((int) $order->get_id(), (int) $itemId);
            }
        }
        if (!$stockExhausted && count($itemIds ?: []) === self::BACKFILL_LIMIT) {
            $next = (int)end($itemIds);
            $args = [$productId, $variationId, $next];
            if (function_exists('as_schedule_single_action')) {
                if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action('btl_cdkey_backfill', $args, 'btl')) {
                    as_schedule_single_action(time() + 1, 'btl_cdkey_backfill', $args, 'btl');
                }
            } elseif (!wp_next_scheduled('btl_cdkey_backfill', $args)) {
                wp_schedule_single_event(time() + 5, 'btl_cdkey_backfill', $args);
            }
        }
    }

    public static function storeManualAssignment(int $orderId, int $itemId, string $plaintext, bool $allowUnpaid = false): bool
    {
        $item = btl_get_order_item_cached($itemId);
        if (!$item || (int)$item->get_order_id() !== $orderId || $item->get_meta('روش تحویل') !== 'code') return false;
        $order = wc_get_order($orderId);
        if (!$order || (!$allowUnpaid && !in_array($order->get_status(), ['processing', 'completed'], true))) return false;
        $plaintext = trim($plaintext);
        if ($plaintext === '') return false;

        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $lockedItem = $wpdb->get_var($wpdb->prepare(
                "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id=%d FOR UPDATE",
                $itemId
            ));
            if ((int)$lockedItem !== $itemId) throw new RuntimeException('item_lock_failed');
            if (BTL_Secure_Fields::countByOrderItem($orderId, $itemId, 'cdkey') >= max(1, (int)$item->get_quantity())) {
                throw new RuntimeException('item_capacity_reached');
            }

            try { $fingerprint = BTL_Secure_Vault::fingerprint($plaintext); }
            catch (Throwable $e) { throw new RuntimeException('fingerprint_failed'); }

            $variationId = (int)$item->get_variation_id();
            $product = $item->get_product();
            $productId = $variationId ? (int)($product ? $product->get_parent_id() : 0) : (int)$item->get_product_id();
            if ($productId < 1) throw new RuntimeException('product_resolution_failed');

            $stock = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE key_fingerprint=%s LIMIT 1 FOR UPDATE",
                $fingerprint
            ));

            if ($stock) {
                if ((int)$stock->product_id !== $productId || (int)$stock->variation_id !== $variationId) {
                    throw new RuntimeException('key_belongs_to_another_product');
                }

                $sameAssignment = (int)$stock->order_id === $orderId && (int)$stock->item_id === $itemId;

                if ((string)$stock->status === 'used' && $sameAssignment) {
                    $wpdb->query('COMMIT');
                    return true;
                }

                if (!in_array((string)$stock->status, ['available', 'reserved'], true) || ((string)$stock->status === 'reserved' && !$sameAssignment)) {
                    throw new RuntimeException('key_already_consumed');
                }

                $source = 'stock:' . (int)$stock->id;
                if (!BTL_Secure_Fields::store($orderId, $itemId, 'cdkey', $plaintext, $source)) {
                    throw new RuntimeException('secure_store_failed');
                }
                $updated = $wpdb->update(
                    self::table(),
                    ['status'=>'used','order_id'=>$orderId,'item_id'=>$itemId,'reservation_token'=>null,'reserved_at'=>null,'used_at'=>current_time('mysql', true)],
                    ['id'=>(int)$stock->id]
                );
                if ($updated === false) throw new RuntimeException('stock_update_failed');
            } else {
                $ciphertext = BTL_Secure_Vault::encrypt($plaintext);
                $inserted = $wpdb->insert(self::table(), [
                    'product_id'      => $productId,
                    'variation_id'    => $variationId,
                    'ciphertext'      => $ciphertext,
                    'key_fingerprint' => $fingerprint,
                    'status'          => 'used',
                    'order_id'        => $orderId,
                    'item_id'         => $itemId,
                    'added_by'        => get_current_user_id() ?: null,
                    'used_at'         => current_time('mysql', true),
                ], ['%d','%d','%s','%s','%s','%d','%d','%d','%s']);

                if ($inserted === false) {

                    throw new RuntimeException('key_already_consumed');
                }

                $source = 'stock:' . (int)$wpdb->insert_id;
                if (!BTL_Secure_Fields::store($orderId, $itemId, 'cdkey', $plaintext, $source)) {
                    throw new RuntimeException('secure_store_failed');
                }
            }
            $wpdb->query('COMMIT');
            return true;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    public static function register(): void
    {
        register_graphql_field('OptimizedVariationItem', 'codeStockCount', ['type'=>'Int','resolve'=>static function($source) { $productId=(int)($source['productId']??0); $variationId=(int)($source['databaseId']??0); return self::cachedAvailableCount($productId,$variationId); }]);
        register_graphql_field('LineItem', 'cdkeyReady', ['type'=>'Boolean','resolve'=>static function($source) { $itemId=(int)($source->databaseId??0); $item=btl_get_order_item_cached($itemId); if(!$item)return false; return BTL_Secure_Fields::countByOrderItem((int)$item->get_order_id(),$itemId,'cdkey') >= max(1,(int)$item->get_quantity()); }]);
        register_graphql_field('LineItem', 'cdkeyAssignedCount', ['type'=>'Int','resolve'=>static function($source) { $itemId=(int)($source->databaseId??0); $item=btl_get_order_item_cached($itemId); return $item ? BTL_Secure_Fields::countByOrderItem((int)$item->get_order_id(),$itemId,'cdkey') : 0; }]);
    }
}