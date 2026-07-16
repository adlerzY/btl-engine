<?php
defined('ABSPATH') || exit;

final class BTL_CdKey_Stock
{
    private const READY_OPTION = 'btl_cdkey_stock_table_ready';
    private const AUTO_ASSIGN_STATUSES = ['processing', 'completed'];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'btl_cdkey_stock';
    }

    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_install'], 5);
        add_action('graphql_register_types', [self::class, 'register'], 20);
        add_action('woocommerce_order_status_changed', [self::class, 'maybe_assign_on_status_change'], 20, 4);
    }

    public static function maybe_install(): void
    {
        BTL_Helpers::ensureTable(self::READY_OPTION, [self::class, 'install']);
    }

    public static function install(): void
    {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ciphertext TEXT NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'available',
            order_id BIGINT UNSIGNED NULL,
            item_id BIGINT UNSIGNED NULL,
            added_by BIGINT UNSIGNED NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY product_variation_status (product_id, variation_id, status)
        ) {$charset} ENGINE=InnoDB;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function add(int $productId, int $variationId, string $plaintext, int $staffUserId): void
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'product_id' => $productId,
            'variation_id' => $variationId,
            'ciphertext' => BTL_Secure_Vault::encrypt($plaintext),
            'status' => 'available',
            'added_by' => $staffUserId ?: null,
        ]);
    }

    public static function bulkAdd(int $productId, int $variationId, array $plaintextKeys, int $staffUserId): int
    {
        $count = 0;
        foreach ($plaintextKeys as $key) {
            $key = trim((string)$key);
            if ($key === '') continue;
            self::add($productId, $variationId, $key, $staffUserId);
            $count++;
        }
        return $count;
    }

    public static function availableCount(int $productId, int $variationId): int
    {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE product_id=%d AND variation_id=%d AND status='available'",
            $productId, $variationId
        ));
    }

    public static function assignNext(int $productId, int $variationId, int $orderId, int $itemId): bool
    {
        global $wpdb;
        $table = self::table();

        $wpdb->query('START TRANSACTION');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, ciphertext FROM {$table} WHERE product_id=%d AND variation_id=%d AND status='available' ORDER BY id ASC LIMIT 1 FOR UPDATE",
            $productId, $variationId
        ));

        if (!$row) {
            $wpdb->query('COMMIT');
            return false;
        }

        $updated = $wpdb->update($table, [
            'status' => 'used',
            'order_id' => $orderId,
            'item_id' => $itemId,
            'used_at' => current_time('mysql', true),
        ], ['id' => $row->id]);

        $wpdb->query('COMMIT');

        if (!$updated) {
            return false;
        }

        $plaintext = BTL_Secure_Vault::decrypt($row->ciphertext);
        if ($plaintext === null) {
            BTL_Helpers::logger("CdKeyStock: decrypt failed for stock row {$row->id}");
            return false;
        }

        BTL_Secure_Fields::store($orderId, $itemId, 'cdkey', $plaintext);

        return true;
    }

    public static function maybe_assign_on_status_change($orderId, $oldStatus, $newStatus, $order): void
    {
        if (!in_array($newStatus, self::AUTO_ASSIGN_STATUSES, true)) return;
        if (!$order instanceof WC_Order) return;

        foreach ($order->get_items() as $itemId => $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;
            if ($item->get_meta('روش تحویل') !== 'code') continue;
            if (BTL_Secure_Fields::exists((int)$orderId, (int)$itemId, 'cdkey')) continue;

            $productId = $item->get_product_id();
            $variationId = $item->get_variation_id() ?: 0;

            if (!self::assignNext($productId, $variationId, (int)$orderId, (int)$itemId)) {
                BTL_Helpers::logger("CdKeyStock: no available key for product {$productId} variation {$variationId} (order {$orderId}, item {$itemId})");
            }
        }
    }

    public static function register(): void
    {
        register_graphql_field('OptimizedVariationItem', 'codeStockCount', [
            'type' => 'Int',
            'resolve' => static function ($variation) {
                $variationId = (int)($variation['databaseId'] ?? 0);
                if (!$variationId) return 0;

                $product = wc_get_product($variationId);
                if (!$product) return 0;

                $productId = $product->is_type('variation') ? $product->get_parent_id() : $variationId;

                return BTL_CdKey_Stock::availableCount($productId, $variationId);
            },
        ]);

        register_graphql_field('LineItem', 'cdkeyReady', [
            'type' => 'Boolean',
            'resolve' => static function ($item) {
                $itemId = (int)($item->databaseId ?? 0);
                if (!$itemId) return false;

                $orderItem = WC_Order_Factory::get_order_item($itemId);
                if (!$orderItem) return false;

                $orderId = (int)$orderItem->get_order_id();

                return BTL_Secure_Fields::exists($orderId, $itemId, 'cdkey');
            },
        ]);
    }
}