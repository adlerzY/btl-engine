<?php
defined('ABSPATH') || exit;

final class BTL_Admin_Users
{
    private const PAID_STATUSES = ['wc-processing', 'wc-completed'];
    private const HIDDEN_EMAIL_SUFFIX = '@phone.arena2battle.local';

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 13);
    }

    public static function register(): void
    {
        if (!btl_is_admin_graphql_request()) return;

        register_graphql_object_type('BtlAdminUser', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'name' => ['type' => 'String'],
                'email' => ['type' => 'String'],
                'phone' => ['type' => 'String'],
                'registeredAt' => ['type' => 'String'],
                'isStaff' => ['type' => 'Boolean'],
                'paidOrdersCount' => ['type' => 'Int'],
                'ticketsCount' => ['type' => 'Int'],
                'reviewsCount' => ['type' => 'Int'],
                'lastActive' => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('BtlAdminUserConnection', [
            'fields' => [
                'nodes' => ['type' => ['list_of' => 'BtlAdminUser']],
                'pageInfo' => ['type' => 'BtlCursorPageInfo'],
            ],
        ]);

        register_graphql_object_type('BtlAdminUserOrder', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'orderNumber' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'total' => ['type' => 'String'],
                'currency' => ['type' => 'String'],
                'date' => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('BtlAdminUserTicket', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'title' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'date' => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('BtlAdminUserDetail', [
            'fields' => [
                'user' => ['type' => 'BtlAdminUser'],
                'recentOrders' => ['type' => ['list_of' => 'BtlAdminUserOrder']],
                'recentTickets' => ['type' => ['list_of' => 'BtlAdminUserTicket']],
            ],
        ]);

        register_graphql_field('RootQuery', 'adminUsers', [
            'type' => 'BtlAdminUserConnection',
            'args' => [
                'first' => ['type' => 'Int'],
                'after' => ['type' => 'String'],
                'search' => ['type' => 'String'],
                'segment' => ['type' => 'String'],
                'order' => ['type' => 'String'],
            ],
            'resolve' => static function ($root, array $args): array {
                self::assertPermission();

                $first = min(max((int)($args['first'] ?? 20), 1), 50);
                $offset = BTL_Customer_Tickets::decodeCursor($args['after'] ?? null);
                $search = trim(self::normalizeDigits(sanitize_text_field((string)($args['search'] ?? ''))));
                $segment = sanitize_key((string)($args['segment'] ?? 'all'));
                $direction = strtolower((string)($args['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
                $staffRoles = BTL_Admin_Permissions::staffRoles();

                $query = [
                    'number' => $first + 1,
                    'offset' => $offset,
                    'orderby' => 'ID',
                    'order' => $direction,
                    'count_total' => false,
                ];

                if ($segment === 'staff') {
                    if (!$staffRoles) return self::connection([], false, 0);
                    $query['role__in'] = $staffRoles;
                } elseif ($segment === 'customers' && $staffRoles) {
                    $query['role__not_in'] = $staffRoles;
                }

                if ($search !== '') {
                    if (ctype_digit($search)) {
                        $ids = get_users([
                            'fields' => 'ID',
                            'number' => 25,
                            'meta_query' => [[
                                'key' => 'btl_phone',
                                'value' => $search,
                                'compare' => 'LIKE',
                            ]],
                        ]);
                        $ids = array_map('intval', (array)$ids);
                        if (strlen($search) <= 9) $ids[] = (int)$search;
                        $ids = array_values(array_unique(array_filter($ids)));
                        if (!$ids) return self::connection([], false, 0);
                        $query['include'] = $ids;
                    } else {
                        $query['search'] = '*' . $search . '*';
                        $query['search_columns'] = ['user_login', 'user_email', 'user_nicename', 'display_name'];
                    }
                }

                $users = (new WP_User_Query($query))->get_results();
                $hasNext = count($users) > $first;
                if ($hasNext) $users = array_slice($users, 0, $first);

                return self::connection($users, $hasNext, $offset);
            },
        ]);

        register_graphql_field('RootQuery', 'adminUser', [
            'type' => 'BtlAdminUserDetail',
            'args' => ['id' => ['type' => ['non_null' => 'Int']]],
            'resolve' => static function ($root, array $args): ?array {
                self::assertPermission();

                $user = get_userdata((int)$args['id']);
                if (!$user instanceof WP_User) return null;

                $id = (int)$user->ID;
                update_meta_cache('user', [$id]);
                $viewer = get_current_user_id();

                return [
                    'user' => self::userPayload(
                        $user,
                        self::paidOrderCounts([$id])[$id] ?? 0,
                        self::ticketCounts([$id])[$id] ?? 0,
                        self::reviewCounts([$id])[$id] ?? 0,
                        self::lastActiveMap([$id])[$id] ?? null
                    ),
                    'recentOrders' => BTL_Admin_Permissions::can($viewer, 'orders.read') ? self::recentOrders($id) : [],
                    'recentTickets' => BTL_Admin_Permissions::can($viewer, 'tickets.read') ? self::recentTickets($id) : [],
                ];
            },
        ]);
    }

    private static function assertPermission(): void
    {
        if (!BTL_Admin_Permissions::can(get_current_user_id(), 'users.read')) {
            throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
        }
    }

    private static function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private static function connection(array $users, bool $hasNext, int $offset): array
    {
        $ids = array_map(static fn(WP_User $user): int => (int)$user->ID, $users);
        if ($ids) update_meta_cache('user', $ids);

        $orders = self::paidOrderCounts($ids);
        $tickets = self::ticketCounts($ids);
        $reviews = self::reviewCounts($ids);
        $active = self::lastActiveMap($ids);

        return [
            'nodes' => array_map(static fn(WP_User $user): array => self::userPayload(
                $user,
                $orders[(int)$user->ID] ?? 0,
                $tickets[(int)$user->ID] ?? 0,
                $reviews[(int)$user->ID] ?? 0,
                $active[(int)$user->ID] ?? null
            ), $users),
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'endCursor' => BTL_Customer_Tickets::encodeCursor($offset + count($users)),
            ],
        ];
    }

    private static function userPayload(WP_User $user, int $paid, int $tickets, int $reviews, ?string $lastActive): array
    {
        $email = (string)$user->user_email;
        if (str_ends_with(strtolower($email), self::HIDDEN_EMAIL_SUFFIX)) {
            $email = '';
        }

        $registered = strtotime((string)$user->user_registered . ' UTC');
        $active = $lastActive ? strtotime($lastActive . ' UTC') : false;
        $phone = (string)get_user_meta((int)$user->ID, 'btl_phone', true);

        return [
            'databaseId' => (int)$user->ID,
            'name' => (string)$user->display_name,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'registeredAt' => $registered ? gmdate(DATE_ATOM, $registered) : null,
            'isStaff' => user_can($user, 'manage_woocommerce'),
            'paidOrdersCount' => $paid,
            'ticketsCount' => $tickets,
            'reviewsCount' => $reviews,
            'lastActive' => $active ? gmdate(DATE_ATOM, $active) : null,
        ];
    }

    private static function countMap(string $sql, array $args): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int)$row->uid] = (int)$row->c;
        }
        return $map;
    }

    private static function paidOrderCounts(array $ids): array
    {
        global $wpdb;
        if (!$ids) return [];

        $idPlaceholders = implode(',', array_fill(0, count($ids), '%d'));
        $statusPlaceholders = implode(',', array_fill(0, count(self::PAID_STATUSES), '%s'));

        $hpos = class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($hpos) {
            $sql = "SELECT customer_id AS uid, COUNT(*) AS c
                    FROM {$wpdb->prefix}wc_orders
                    WHERE type='shop_order' AND status IN ({$statusPlaceholders}) AND customer_id IN ({$idPlaceholders})
                    GROUP BY customer_id";
        } else {
            $sql = "SELECT pm.meta_value AS uid, COUNT(*) AS c
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_customer_user'
                    WHERE p.post_type='shop_order' AND p.post_status IN ({$statusPlaceholders}) AND pm.meta_value IN ({$idPlaceholders})
                    GROUP BY pm.meta_value";
        }

        return self::countMap($sql, array_merge(self::PAID_STATUSES, $ids));
    }

    private static function ticketCounts(array $ids): array
    {
        global $wpdb;
        if (!$ids) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT pm.meta_value AS uid, COUNT(*) AS c
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='customer_id'
                WHERE p.post_type='support_ticket' AND p.post_status='publish' AND pm.meta_value IN ({$placeholders})
                GROUP BY pm.meta_value";

        return self::countMap($sql, $ids);
    }

    private static function reviewCounts(array $ids): array
    {
        global $wpdb;
        if (!$ids) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT user_id AS uid, COUNT(*) AS c
                FROM {$wpdb->comments}
                WHERE comment_type='review' AND comment_parent=0 AND user_id IN ({$placeholders})
                GROUP BY user_id";

        return self::countMap($sql, $ids);
    }

    private static function lastActiveMap(array $ids): array
    {
        global $wpdb;
        if (!$ids) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT user_id AS uid, MAX(last_active) AS la
                FROM " . BTL_Sessions::table() . "
                WHERE user_id IN ({$placeholders})
                GROUP BY user_id";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $ids));
        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int)$row->uid] = (string)$row->la;
        }
        return $map;
    }

    private static function recentOrders(int $userId): array
    {
        $statuses = array_map(
            static fn($key): string => str_replace('wc-', '', (string)$key),
            array_keys(wc_get_order_statuses())
        );

        $orders = wc_get_orders([
            'customer_id' => $userId,
            'limit' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => $statuses,
            'return' => 'objects',
        ]);

        return array_map(static fn(WC_Order $order): array => [
            'databaseId' => (int)$order->get_id(),
            'orderNumber' => (string)$order->get_order_number(),
            'status' => (string)$order->get_status(),
            'total' => (string)$order->get_total(),
            'currency' => (string)$order->get_currency(),
            'date' => $order->get_date_created() ? $order->get_date_created()->date(DATE_ATOM) : null,
        ], $orders ?: []);
    }

    private static function recentTickets(int $userId): array
    {
        $posts = (new WP_Query([
            'post_type' => 'support_ticket',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'update_post_term_cache' => false,
            'meta_query' => [[
                'key' => 'customer_id',
                'value' => $userId,
                'compare' => '=',
            ]],
        ]))->posts;

        return array_map(static function (WP_Post $post): array {
            $status = sanitize_key((string)get_post_meta($post->ID, 'ticket_status', true)) ?: 'open';
            return [
                'databaseId' => (int)$post->ID,
                'title' => get_the_title($post),
                'status' => $status === 'answered' ? 'waiting_user' : $status,
                'date' => get_post_time(DATE_ATOM, true, $post),
            ];
        }, $posts);
    }
}