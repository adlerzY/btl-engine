<?php
defined('ABSPATH') || exit;

final class BTL_Admin_CdKeys
{
    private const MAX_PAGE = 50;
    private const MAX_IMPORT = 500;
    private const MAX_ASSIGN = 50;

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 13);
    }

    public static function register(): void
    {
        if (!btl_is_admin_graphql_request()) return;
        register_graphql_object_type('BtlAdminCdKeyStockItem', [
            'fields' => [
                'stockId' => ['type' => 'Int'],
                'productId' => ['type' => 'Int'],
                'variationId' => ['type' => 'Int'],
                'productName' => ['type' => 'String'],
                'variationName' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'orderId' => ['type' => 'Int'],
                'itemId' => ['type' => 'Int'],
                'addedBy' => ['type' => 'Int'],
                'createdAt' => ['type' => 'String'],
                'usedAt' => ['type' => 'String'],
                'failureReason' => ['type' => 'String'],
                'assignmentAttempts' => ['type' => 'Int'],
            ],
        ]);

        register_graphql_object_type('BtlAdminCdKeyStockSummary', [
            'fields' => [
                'available' => ['type' => 'Int'],
                'reserved' => ['type' => 'Int'],
                'used' => ['type' => 'Int'],
                'failed' => ['type' => 'Int'],
                'total' => ['type' => 'Int'],
            ],
        ]);

        register_graphql_object_type('BtlAdminCdKeyStockConnection', [
            'fields' => [
                'nodes' => ['type' => ['list_of' => 'BtlAdminCdKeyStockItem']],
                'pageInfo' => ['type' => 'BtlCursorPageInfo'],
                'summary' => ['type' => 'BtlAdminCdKeyStockSummary'],
            ],
        ]);

        register_graphql_field('RootQuery', 'adminCdKeyStock', [
            'type' => 'BtlAdminCdKeyStockConnection',
            'args' => [
                'first' => ['type' => 'Int'],
                'after' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'productId' => ['type' => 'Int'],
                'variationId' => ['type' => 'Int'],
            ],
            'resolve' => static function ($root, array $args): array {
                self::assertPermission('cdkeys.read');
                global $wpdb;

                $first = min(max((int)($args['first'] ?? 30), 1), self::MAX_PAGE);
                $offset = BTL_Customer_Tickets::decodeCursor($args['after'] ?? null);
                $status = sanitize_key((string)($args['status'] ?? ''));
                $productId = max(0, (int)($args['productId'] ?? 0));
                $variationId = max(0, (int)($args['variationId'] ?? 0));

                $conditions = ['1=1'];
                $params = [];
                if ($status !== '' && $status !== 'all') {
                    $conditions[] = 'status=%s';
                    $params[] = $status;
                }
                if ($productId > 0) {
                    $conditions[] = 'product_id=%d';
                    $params[] = $productId;
                }
                if ($variationId > 0) {
                    $conditions[] = 'variation_id=%d';
                    $params[] = $variationId;
                }

                $where = implode(' AND ', $conditions);
                $sql = "SELECT id,product_id,variation_id,status,order_id,item_id,added_by,created_at,used_at,failure_reason,assignment_attempts FROM " . BTL_CdKey_Stock::table() . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
                $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$first + 1, $offset])));
                $hasNext = count($rows) > $first;
                if ($hasNext) $rows = array_slice($rows, 0, $first);

                $summary = self::summary($where, $params);
                return [
                    'nodes' => array_map([self::class, 'stockPayload'], $rows ?: []),
                    'pageInfo' => [
                        'hasNextPage' => $hasNext,
                        'endCursor' => BTL_Customer_Tickets::encodeCursor($offset + count($rows)),
                    ],
                    'summary' => $summary,
                ];
            },
        ]);

        register_graphql_mutation('adminImportCdKeys', [
            'inputFields' => [
                'productId' => ['type' => ['non_null' => 'Int']],
                'variationId' => ['type' => ['non_null' => 'Int']],
                'keys' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'requested' => ['type' => 'Int'],
                'added' => ['type' => 'Int'],
                'rejected' => ['type' => 'Int'],
                'availableCount' => ['type' => 'Int'],
            ],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('cdkeys.write');
                [$productId, $variationId] = self::validateVariation((int)$input['productId'], (int)$input['variationId']);

                $lines = preg_split('/\R/u', (string)$input['keys']) ?: [];
                $keys = [];
                foreach ($lines as $line) {
                    $key = trim((string)$line);
                    if ($key === '') continue;
                    $keys[$key] = true;
                    if (count($keys) >= self::MAX_IMPORT) break;
                }
                $keys = array_keys($keys);
                if (!$keys) throw new GraphQL\Error\UserError('حداقل یک CD Key وارد کنید.');

                $added = BTL_CdKey_Stock::bulkAdd($productId, $variationId, $keys, get_current_user_id());
                $rejected = max(0, count($keys) - $added);

                BTL_Admin_Audit::record(get_current_user_id(), 'CD_KEY_IMPORT', 'cdkey_stock', $variationId, 'success', [
                    'product_id' => $productId,
                    'variation_id' => $variationId,
                    'requested' => count($keys),
                    'added' => $added,
                    'rejected' => $rejected,
                ]);

                return [
                    'success' => true,
                    'requested' => count($keys),
                    'added' => $added,
                    'rejected' => $rejected,
                    'availableCount' => BTL_CdKey_Stock::cachedAvailableCount($productId, $variationId),
                ];
            },
        ]);

        register_graphql_mutation('adminDeleteCdKey', [
            'inputFields' => ['stockId' => ['type' => ['non_null' => 'Int']]],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('cdkeys.write');
                $stockId = max(1, (int)$input['stockId']);
                if (!BTL_CdKey_Stock::deleteUnused($stockId)) {
                    throw new GraphQL\Error\UserError('فقط کلیدهای استفاده‌نشده و تخصیص‌نیافته قابل حذف هستند.');
                }
                BTL_Admin_Audit::record(get_current_user_id(), 'CD_KEY_DELETE', 'cdkey_stock', $stockId, 'success', []);
                return ['success' => true];
            },
        ]);

        register_graphql_mutation('adminAssignCdKeys', [
            'inputFields' => [
                'orderId' => ['type' => ['non_null' => 'Int']],
                'itemId' => ['type' => ['non_null' => 'Int']],
                'quantity' => ['type' => ['non_null' => 'Int']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'assigned' => ['type' => 'Int'],
                'deliveredQuantity' => ['type' => 'Int'],
                'remainingQuantity' => ['type' => 'Int'],
                'fulfillmentStatus' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('cdkeys.write');
                $orderId = (int)$input['orderId'];
                $itemId = (int)$input['itemId'];
                $requested = min(max((int)$input['quantity'], 1), self::MAX_ASSIGN);
                $item = self::validateCodeItem($orderId, $itemId, true);
                $order = wc_get_order($orderId);
                if (!$order) throw new GraphQL\Error\UserError('سفارش یافت نشد.');

                $quantity = max(1, (int)$item->get_quantity());
                $before = BTL_Secure_Fields::countByOrderItem($orderId, $itemId, 'cdkey');
                $remaining = max(0, $quantity - $before);
                if ($remaining === 0) throw new GraphQL\Error\UserError('تمام CD Keyهای این آیتم قبلاً تخصیص داده شده‌اند.');

                $product = $item->get_product();
                $variationId = (int)$item->get_variation_id();
                $productId = $variationId ? (int)($product ? $product->get_parent_id() : 0) : (int)$item->get_product_id();
                if ($productId < 1) throw new GraphQL\Error\UserError('محصول آیتم قابل شناسایی نیست.');

                $assignTarget = min($requested, $remaining, BTL_CdKey_Stock::availableCount($productId, $variationId));
                if ($assignTarget < 1) throw new GraphQL\Error\UserError('برای این آیتم CD Key قابل تخصیص در موجودی موجود نیست.');

                if (!BTL_CdKey_Stock::reserveForItem($productId, $variationId, $orderId, $itemId, $assignTarget)) {
                    throw new GraphQL\Error\UserError('رزرو CD Key انجام نشد. دوباره تلاش کنید.');
                }

                $newlyAssigned = BTL_CdKey_Stock::assignReservedForItem($orderId, $itemId);
                $delivered = BTL_Secure_Fields::countByOrderItem($orderId, $itemId, 'cdkey');
                $remainingAfter = max(0, $quantity - $delivered);
                $status = $delivered >= $quantity ? 'completed' : ($delivered > 0 ? 'processing' : 'queued');

                BTL_Admin_Audit::record(get_current_user_id(), 'CD_KEY_ASSIGN', 'order_item', $itemId, 'success', [
                    'order_id' => $orderId,
                    'requested' => $requested,
                    'assigned' => $newlyAssigned,
                    'delivered' => $delivered,
                    'remaining' => $remainingAfter,
                ]);

                return [
                    'success' => $newlyAssigned > 0,
                    'assigned' => $newlyAssigned,
                    'deliveredQuantity' => $delivered,
                    'remainingQuantity' => $remainingAfter,
                    'fulfillmentStatus' => $status,
                ];
            },
        ]);

        register_graphql_mutation('adminAssignCdKeyManually', [
            'inputFields' => [
                'orderId' => ['type' => ['non_null' => 'Int']],
                'itemId' => ['type' => ['non_null' => 'Int']],
                'key' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'deliveredQuantity' => ['type' => 'Int'],
                'remainingQuantity' => ['type' => 'Int'],
                'fulfillmentStatus' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('cdkeys.write');
                $orderId = (int)$input['orderId'];
                $itemId = (int)$input['itemId'];
                $item = self::validateCodeItem($orderId, $itemId, true);
                $ok = BTL_CdKey_Stock::storeManualAssignment($orderId, $itemId, trim((string)$input['key']), false);
                if (!$ok) throw new GraphQL\Error\UserError('CD Key دستی ثبت نشد. ممکن است سفارش پرداخت نشده باشد، کلید تکراری باشد یا ظرفیت تکمیل شده باشد.');

                $quantity = max(1, (int)$item->get_quantity());
                $delivered = BTL_Secure_Fields::countByOrderItem($orderId, $itemId, 'cdkey');
                $remaining = max(0, $quantity - $delivered);
                $status = $delivered >= $quantity ? 'completed' : ($delivered > 0 ? 'processing' : 'queued');

                BTL_Admin_Audit::record(get_current_user_id(), 'CD_KEY_MANUAL_ASSIGN', 'order_item', $itemId, 'success', [
                    'order_id' => $orderId,
                    'delivered' => $delivered,
                    'remaining' => $remaining,
                ]);

                return ['success' => true, 'deliveredQuantity' => $delivered, 'remainingQuantity' => $remaining, 'fulfillmentStatus' => $status];
            },
        ]);

        register_graphql_mutation('adminRevealCdKeys', [
            'inputFields' => [
                'orderId' => ['type' => ['non_null' => 'Int']],
                'itemId' => ['type' => ['non_null' => 'Int']],
            ],
            'outputFields' => ['values' => ['type' => ['list_of' => 'String']]],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('cdkeys.reveal');
                $orderId = (int)$input['orderId'];
                $itemId = (int)$input['itemId'];
                self::validateCodeItem($orderId, $itemId, false);
                $values = BTL_Secure_Fields::revealAllForStaffCdKey($orderId, $itemId, get_current_user_id());
                BTL_Admin_Audit::record(get_current_user_id(), 'CD_KEY_REVEAL', 'order_item', $itemId, 'success', ['order_id' => $orderId, 'count' => count($values)]);
                return ['values' => $values];
            },
        ]);
    }

    private static function validateVariation(int $productId, int $variationId): array
    {
        if ($productId < 1 || $variationId < 1) throw new GraphQL\Error\UserError('شناسه محصول و متغیر معتبر نیست.');
        $variation = wc_get_product($variationId);
        if (!$variation || !is_a($variation, 'WC_Product_Variation')) throw new GraphQL\Error\UserError('Variation یافت نشد.');
        if ((int)$variation->get_parent_id() !== $productId) throw new GraphQL\Error\UserError('Variation متعلق به محصول انتخاب‌شده نیست.');
        return [$productId, $variationId];
    }

    private static function validateCodeItem(int $orderId, int $itemId, bool $requirePaid): WC_Order_Item_Product
    {
        $order = wc_get_order($orderId);
        $item = $order ? $order->get_item($itemId) : false;
        if (!$order || !$item instanceof WC_Order_Item_Product) throw new GraphQL\Error\UserError('سفارش یا آیتم یافت نشد.');
        if ($item->get_meta('روش تحویل') !== 'code') throw new GraphQL\Error\UserError('این آیتم تحویل CD Key ندارد.');
        if ($requirePaid && !in_array($order->get_status(), ['processing', 'completed'], true)) throw new GraphQL\Error\UserError('سفارش هنوز در وضعیت مجاز برای تخصیص CD Key نیست.');
        return $item;
    }

    private static function summary(string $where, array $params): array
    {
        global $wpdb;
        $table = BTL_CdKey_Stock::table();
        $available = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where} AND status='available'", $params));
        $reserved = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where} AND status='reserved'", $params));
        $used = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where} AND status='used'", $params));
        $failed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where} AND status NOT IN ('available','reserved','used')", $params));
        return ['available' => $available, 'reserved' => $reserved, 'used' => $used, 'failed' => $failed, 'total' => $available + $reserved + $used + $failed];
    }

    private static function stockPayload(object $row): array
    {
        $product = wc_get_product((int)$row->variation_id);
        $parent = $product instanceof WC_Product_Variation ? wc_get_product((int)$row->product_id) : $product;
        $productName = $parent ? (string)$parent->get_name() : 'محصول #' . (int)$row->product_id;
        $variationName = $product instanceof WC_Product_Variation ? (string)$product->get_name() : '—';
        return [
            'stockId' => (int)$row->id,
            'productId' => (int)$row->product_id,
            'variationId' => (int)$row->variation_id,
            'productName' => $productName,
            'variationName' => $variationName,
            'status' => (string)$row->status,
            'orderId' => $row->order_id ? (int)$row->order_id : null,
            'itemId' => $row->item_id ? (int)$row->item_id : null,
            'addedBy' => $row->added_by ? (int)$row->added_by : null,
            'createdAt' => $row->created_at ? (string)$row->created_at : null,
            'usedAt' => $row->used_at ? (string)$row->used_at : null,
            'failureReason' => $row->failure_reason ? (string)$row->failure_reason : null,
            'assignmentAttempts' => (int)$row->assignment_attempts,
        ];
    }

    private static function assertPermission(string $permission): void
    {
        if (!BTL_Admin_Permissions::can(get_current_user_id(), $permission)) throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
    }
}
