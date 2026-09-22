<?php
defined('ABSPATH') || exit;

final class BTL_Admin_Orders
{
    private const FULFILLMENT_STATUSES = [
        'queued' => true,
        'logging_in' => true,
        'processing' => true,
        'completed' => true,
    ];

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 12);
    }

    public static function register(): void
    {
        if (!btl_is_admin_graphql_request()) return;
        register_graphql_object_type('BtlAdminOrderItem', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'productId' => ['type' => 'Int'],
                'variationId' => ['type' => 'Int'],
                'productName' => ['type' => 'String'],
                'quantity' => ['type' => 'Int'],
                'deliveryMethod' => ['type' => 'String'],
                'deliveredQuantity' => ['type' => 'Int'],
                'fulfillmentStatus' => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('BtlAdminOrderNote', [
            'fields' => [
                'id' => ['type' => 'Int'],
                'content' => ['type' => 'String'],
                'date' => ['type' => 'String'],
                'author' => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('BtlAdminOrder', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'orderNumber' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'paymentStatus' => ['type' => 'String'],
                'fulfillmentStatus' => ['type' => 'String'],
                'total' => ['type' => 'String'],
                'currency' => ['type' => 'String'],
                'date' => ['type' => 'String'],
                'customerId' => ['type' => 'Int'],
                'customerName' => ['type' => 'String'],
                'customerEmail' => ['type' => 'String'],
                'items' => ['type' => ['list_of' => 'BtlAdminOrderItem']],
                'notes' => ['type' => ['list_of' => 'BtlAdminOrderNote']],
                'linkedTicketIds' => ['type' => ['list_of' => 'Int']],
            ],
        ]);

        register_graphql_object_type('BtlAdminOrderConnection', [
            'fields' => [
                'nodes' => ['type' => ['list_of' => 'BtlAdminOrder']],
                'pageInfo' => ['type' => 'BtlCursorPageInfo'],
            ],
        ]);

        register_graphql_field('RootQuery', 'adminOrders', [
            'type' => 'BtlAdminOrderConnection',
            'args' => [
                'first' => ['type' => 'Int'],
                'after' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'search' => ['type' => 'String'],
            ],
            'resolve' => static function ($root, array $args): array {
                self::assertPermission('orders.read');

                $first = min(max((int)($args['first'] ?? 20), 1), 50);
                $offset = BTL_Customer_Tickets::decodeCursor($args['after'] ?? null);
                $status = sanitize_key((string)($args['status'] ?? ''));
                $search = sanitize_text_field((string)($args['search'] ?? ''));

                $queryArgs = [
                    'limit' => $first + 1,
                    'offset' => $offset,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'return' => 'objects',
                    'status' => ['processing', 'completed'],
                ];

                if (in_array($status, ['processing', 'completed'], true)) {
                    $queryArgs['status'] = [$status];
                }
                if ($search !== '') {
                    $queryArgs['search'] = $search;
                }

                $orders = wc_get_orders($queryArgs);
                $hasNext = count($orders) > $first;
                if ($hasNext) {
                    $orders = array_slice($orders, 0, $first);
                }

                $orderIds = array_map(static fn(WC_Order $order): int => (int)$order->get_id(), $orders);
                $cdkeyCountsByOrder = BTL_Secure_Fields::countsByOrders($orderIds, 'cdkey');

                return [
                    'nodes' => array_map(static function (WC_Order $order) use ($cdkeyCountsByOrder): array {
                        $counts = $cdkeyCountsByOrder[(int)$order->get_id()] ?? [];
                        return self::orderPayload($order, false, $counts);
                    }, $orders),
                    'pageInfo' => [
                        'hasNextPage' => $hasNext,
                        'endCursor' => BTL_Customer_Tickets::encodeCursor($offset + count($orders)),
                    ],
                ];
            },
        ]);

        register_graphql_field('RootQuery', 'adminOrder', [
            'type' => 'BtlAdminOrder',
            'args' => ['id' => ['type' => ['non_null' => 'Int']]],
            'resolve' => static function ($root, array $args): ?array {
                self::assertPermission('orders.read');
                $order = wc_get_order((int)$args['id']);
                return $order ? self::orderPayload($order, true) : null;
            },
        ]);

        register_graphql_field('RootQuery', 'adminProcessingOrdersCount', [
            'type' => 'Int',
            'resolve' => static function (): int {
                if (!BTL_Admin_Permissions::can(get_current_user_id(), 'orders.read')) {
                    return 0;
                }

                return (int) BTL_Cache::remember('admin_processing_orders_count', static function (): int {
                    $result = wc_get_orders([
                        'status' => ['processing'],
                        'limit' => 1,
                        'return' => 'ids',
                        'paginate' => true,
                    ]);

                    return is_object($result) && isset($result->total) ? (int)$result->total : 0;
                }, 'btl', 10);
            },
        ]);

        register_graphql_mutation('adminAddOrderNote', [
            'inputFields' => [
                'orderId' => ['type' => ['non_null' => 'Int']],
                'content' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('orders.write');
                $order = wc_get_order((int)$input['orderId']);
                $content = wp_kses_post(trim((string)$input['content']));
                if (!$order || $content === '') {
                    throw new GraphQL\Error\UserError('سفارش یا متن یادداشت نامعتبر است.');
                }

                $order->add_order_note($content, false, true);
                BTL_Admin_Audit::record(get_current_user_id(), 'ORDER_NOTE_ADDED', 'order', (int)$order->get_id(), 'success', ['length' => mb_strlen(wp_strip_all_tags($content))]);
                return ['success' => true];
            },
        ]);

        register_graphql_mutation('adminUpdateOrderStatus', [
            'inputFields' => [
                'orderId' => ['type' => ['non_null' => 'Int']],
                'status' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'status' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('orders.write');
                $order = wc_get_order((int)$input['orderId']);
                $status = sanitize_key((string)$input['status']);
                $allowed = array_map(static fn($key) => str_replace('wc-', '', $key), array_keys(wc_get_order_statuses()));
                if (!$order || !in_array($status, $allowed, true)) {
                    throw new GraphQL\Error\UserError('سفارش یا وضعیت نامعتبر است.');
                }

                $old = $order->get_status();
                if ($status === 'completed') {
                    $payload = self::orderPayload($order, false);
                    if (($payload['fulfillmentStatus'] ?? 'queued') !== 'completed') {
                        throw new GraphQL\Error\UserError('سفارش تا تحویل کامل اقلام قابل Completed شدن نیست.');
                    }
                }
                if ($old !== $status) {
                    BTL_Cache::delete('admin_processing_orders_count');
                    $order->update_status($status, 'Admin Operations Center');
                    BTL_Admin_Audit::record(get_current_user_id(), 'ORDER_STATUS_CHANGE', 'order', (int)$order->get_id(), 'success', ['from' => $old, 'to' => $status]);
                }

                return ['success' => true, 'status' => $order->get_status()];
            },
        ]);

        register_graphql_mutation('adminUpdateOrderItemFulfillment', [
            'inputFields' => [
                'orderId' => ['type' => ['non_null' => 'Int']],
                'itemId' => ['type' => ['non_null' => 'Int']],
                'status' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'status' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('orders.fulfill');
                $order = wc_get_order((int)$input['orderId']);
                $itemId = (int)$input['itemId'];
                $status = sanitize_key((string)$input['status']);
                if (!$order || !isset(self::FULFILLMENT_STATUSES[$status])) {
                    throw new GraphQL\Error\UserError('سفارش یا وضعیت تحویل نامعتبر است.');
                }

                $item = $order->get_item($itemId);
                if (!$item instanceof WC_Order_Item_Product) {
                    throw new GraphQL\Error\UserError('آیتم سفارش یافت نشد.');
                }

                $delivery = sanitize_key((string)$item->get_meta('روش تحویل'));
                if (!in_array($delivery, ['direct', 'gift'], true)) {
                    throw new GraphQL\Error\UserError('تحویل این نوع آیتم در این فاز مدیریت نمی‌شود.');
                }

                $previous = sanitize_key((string)$item->get_meta('_fulfillment_status')) ?: 'queued';
                $rank = ['queued' => 0, 'logging_in' => 1, 'processing' => 2, 'completed' => 3];
                if ($rank[$status] < ($rank[$previous] ?? 0)) {
                    throw new GraphQL\Error\UserError('بازگرداندن وضعیت تحویل به عقب مجاز نیست.');
                }

                $item->update_meta_data('_fulfillment_status', $status);
                $item->save();
                BTL_Admin_Audit::record(get_current_user_id(), 'ORDER_FULFILLMENT', 'order_item', $itemId, 'success', ['order_id' => (int)$order->get_id(), 'from' => $previous, 'to' => $status]);

                if ($status === 'completed' && $previous !== 'completed' && ! $item->get_meta('_btl_completion_notification_sent', true)) {
                    $item->update_meta_data('_btl_completion_notification_sent', gmdate('c'));
                    $item->save();
                    $customerId = (int)$order->get_customer_id();
                    if ($customerId) {
                        BTL_Notifications::push($customerId, 'تحویل سفارش شما تکمیل شد ✅', sprintf('آیتم «%s» از سفارش شما آماده و تحویل داده شد.', $item->get_name()), '/my-account/orders', 'order');
                    }
                }

                return ['success' => true, 'status' => $status];
            },
        ]);
    }

    private static function assertPermission(string $permission): void
    {
        if (!BTL_Admin_Permissions::can(get_current_user_id(), $permission)) {
            throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
        }
    }

    public static function payloadForExternal(WC_Order $order, ?array $cdkeyCounts = null): array
    {
        return self::orderPayload($order, false, $cdkeyCounts);
    }

    private static function orderPayload(WC_Order $order, bool $detail = false, ?array $cdkeyCounts = null): array
    {
        $items = [];
        $allCompleted = true;
        $anyProgress = false;
        $customerId = (int)$order->get_customer_id();
        $cdkeyCounts ??= BTL_Secure_Fields::countsByOrder((int)$order->get_id(), 'cdkey');

        foreach ($order->get_items('line_item') as $itemId => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $quantity = max(0, (int)$item->get_quantity());
            $delivery = sanitize_key((string)$item->get_meta('روش تحویل'));
            $delivered = 0;
            $status = 'queued';

            if ($delivery === 'code') {
                $delivered = (int)($cdkeyCounts[(int)$itemId] ?? 0);
                $delivered = min($delivered, $quantity);
                $status = $delivered >= $quantity ? 'completed' : ($delivered > 0 ? 'processing' : 'queued');
            } else {
                $status = sanitize_key((string)$item->get_meta('_fulfillment_status')) ?: 'queued';
                $delivered = $status === 'completed' ? $quantity : 0;
            }

            if ($status !== 'completed') {
                $allCompleted = false;
                if ($status !== 'queued' || $delivered > 0) $anyProgress = true;
            } else {
                $anyProgress = true;
            }

            $items[] = [
                'databaseId' => (int)$itemId,
                'productId' => (int)$item->get_product_id(),
                'variationId' => (int)$item->get_variation_id(),
                'productName' => $item->get_name(),
                'quantity' => $quantity,
                'deliveryMethod' => $delivery ?: 'unknown',
                'deliveredQuantity' => $delivered,
                'fulfillmentStatus' => $status,
            ];
        }

        $payload = [
            'databaseId' => (int)$order->get_id(),
            'orderNumber' => (string)$order->get_order_number(),
            'status' => (string)$order->get_status(),
            'paymentStatus' => $order->is_paid() ? 'paid' : 'unpaid',
            'fulfillmentStatus' => !$items ? 'completed' : ($allCompleted ? 'completed' : ($anyProgress ? 'processing' : 'queued')),
            'total' => (string)$order->get_total(),
            'currency' => (string)$order->get_currency(),
            'date' => $order->get_date_created() ? $order->get_date_created()->date(DATE_ATOM) : null,
            'customerId' => $customerId ?: null,
            'customerName' => $customerId ? (string)$order->get_formatted_billing_full_name() : (string)$order->get_billing_first_name(),
            'customerEmail' => (string)$order->get_billing_email(),
            'items' => $items,
            'notes' => [],
            'linkedTicketIds' => [],
        ];

        if (!$detail) {
            return $payload;
        }

        $notes = function_exists('wc_get_order_notes') ? wc_get_order_notes(['order_id' => (int)$order->get_id(), 'type' => 'internal', 'limit' => 50]) : [];
        $payload['notes'] = array_map(static function ($note): array {
            return [
                'id' => (int)$note->id,
                'content' => (string)$note->content,
                'date' => isset($note->date_created) && $note->date_created ? $note->date_created->date(DATE_ATOM) : null,
                'author' => (string)($note->added_by ?? ''),
            ];
        }, $notes);

        global $wpdb;
        $ticketIds = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID WHERE p.post_type=%s AND pm.meta_key=%s AND pm.meta_value=%d ORDER BY p.ID DESC LIMIT 50",
            'support_ticket', 'linked_order_id', (int)$order->get_id()
        ));
        $payload['linkedTicketIds'] = array_map('intval', $ticketIds ?: []);

        return $payload;
    }
}
