<?php
defined('ABSPATH') || exit;

final class BTL_Customer_Orders
{
    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }

    public static function register(): void
    {
        register_graphql_input_type('BtlOrderLineItemMetaInput', [
            'fields' => [
                'key' => ['type' => ['non_null' => 'String']],
                'value' => ['type' => ['non_null' => 'String']],
            ],
        ]);

        register_graphql_input_type('BtlOrderLineItemInput', [
            'fields' => [
                'productId' => ['type' => ['non_null' => 'Int']],
                'variationId' => ['type' => 'Int'],
                'quantity' => ['type' => 'Int'],
                'metaData' => ['type' => ['list_of' => 'BtlOrderLineItemMetaInput']],
            ],
        ]);

        register_graphql_object_type('BtlCustomerOrderResult', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'orderKey' => ['type' => 'String'],
                'orderNumber' => ['type' => 'String'],
                'total' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'paymentUrl' => ['type' => 'String'],
            ],
        ]);

        register_graphql_mutation('submitCustomerOrder', [
            'inputFields' => [
                'lineItems' => ['type' => ['non_null' => ['list_of' => 'BtlOrderLineItemInput']]],
                'customerNote' => ['type' => 'String'],
            ],
            'outputFields' => [
                'order' => ['type' => 'BtlCustomerOrderResult'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('برای ثبت سفارش باید وارد حساب کاربری شوید.');
                }

                $lineItems = $input['lineItems'] ?? [];
                if (empty($lineItems)) {
                    throw new GraphQL\Error\UserError('سبد خرید شما خالی است.');
                }

                $userId = get_current_user_id();
                $order = wc_create_order(['customer_id' => $userId]);

                if (is_wp_error($order)) {
                    throw new GraphQL\Error\UserError('ایجاد سفارش با خطا مواجه شد.');
                }

                $addedAny = false;

                foreach ($lineItems as $li) {
                    $productId = (int)($li['productId'] ?? 0);
                    $variationId = (int)($li['variationId'] ?? 0);
                    $quantity = max(1, min(99, (int)($li['quantity'] ?? 1)));

                    $product = $variationId ? wc_get_product($variationId) : wc_get_product($productId);
                    if (!$product) {
                        continue;
                    }

                    $itemId = $order->add_product($product, $quantity);
                    if (!$itemId) {
                        continue;
                    }

                    $addedAny = true;

                    if (!empty($li['metaData'])) {
                        $item = $order->get_item($itemId);
                        foreach ($li['metaData'] as $meta) {
                            if (empty($meta['key'])) {
                                continue;
                            }
                            $item->add_meta_data(
                                sanitize_text_field($meta['key']),
                                sanitize_text_field($meta['value'] ?? '')
                            );
                        }
                        $item->save();
                    }
                }

                if (!$addedAny) {
                    $order->delete(true);
                    throw new GraphQL\Error\UserError('هیچ‌کدام از آیتم‌های سبد خرید معتبر نیستند.');
                }

                if (!empty($input['customerNote'])) {
                    $order->set_customer_note(sanitize_textarea_field($input['customerNote']));
                }

                $order->calculate_totals();
                $order->save();

                return [
                    'order' => [
                        'databaseId' => $order->get_id(),
                        'orderKey' => $order->get_order_key(),
                        'orderNumber' => $order->get_order_number(),
                        'total' => $order->get_total(),
                        'status' => strtoupper($order->get_status()),
                        'paymentUrl' => $order->get_checkout_payment_url(),
                    ],
                ];
            },
        ]);
    }
}