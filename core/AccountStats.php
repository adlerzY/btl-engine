<?php
defined('ABSPATH') || exit;

final class BTL_Account_Stats
{
    public static function register(): void
    {
        register_graphql_object_type('BtlAccountStats', [
            'fields' => [
                'successfulOrdersCount' => ['type' => 'Int'],
                'openTicketsCount' => ['type' => 'Int'],
            ],
        ]);

        register_graphql_field('RootQuery', 'myAccountStats', [
            'type' => 'BtlAccountStats',
            'resolve' => static function (): array {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                }

                $userId = get_current_user_id();

                return [
                    'successfulOrdersCount' => self::successfulOrders($userId),
                    'openTicketsCount' => self::openTickets($userId),
                ];
            },
        ]);
    }

    private static function successfulOrders(int $userId): int
    {
        return (int) BTL_Cache::remember("account_orders_count_{$userId}", static function () use ($userId): int {
            $result = wc_get_orders([
                'customer_id' => $userId,
                'status' => ['processing', 'completed'],
                'limit' => 1,
                'paginate' => true,
                'return' => 'ids',
            ]);

            return is_object($result) && isset($result->total) ? (int) $result->total : 0;
        }, 'btl', 15);
    }

    private static function openTickets(int $userId): int
    {
        return (int) BTL_Cache::remember("account_tickets_count_{$userId}", static function () use ($userId): int {
            global $wpdb;

            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = 'customer_id' AND c.meta_value = %s
                 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = 'ticket_status'
                 WHERE p.post_type = 'support_ticket'
                   AND p.post_status = 'publish'
                   AND (s.meta_value IS NULL OR s.meta_value NOT IN ('closed', 'resolved'))",
                (string) $userId
            ));
        }, 'btl', 15);
    }
}