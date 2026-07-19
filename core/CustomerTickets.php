<?php
defined('ABSPATH') || exit;

final class BTL_Customer_Tickets
{
    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 9);
    }

    public static function register(): void
    {
        register_graphql_object_type('BtlCursorPageInfo', [
            'fields' => [
                'hasNextPage' => ['type' => 'Boolean'],
                'endCursor' => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('MyTicketsConnection', [
            'fields' => [
                'nodes' => ['type' => ['list_of' => 'SupportTicket']],
                'pageInfo' => ['type' => 'BtlCursorPageInfo'],
            ],
        ]);

        register_graphql_field('RootQuery', 'myTickets', [
            'type' => 'MyTicketsConnection',
            'args' => [
                'first' => ['type' => 'Int'],
                'after' => ['type' => 'String'],
                'search' => ['type' => 'String'],
                'status' => ['type' => 'String'],
            ],
            'resolve' => static function ($root, $args, $context) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                }

                $userId = get_current_user_id();
                $first = min(max((int)($args['first'] ?? 20), 1), 50);
                $offset = self::decodeCursor($args['after'] ?? null);

                $metaQuery = [[
                    'key' => 'customer_id',
                    'value' => $userId,
                    'compare' => '=',
                ]];

                $status = trim((string)($args['status'] ?? ''));
                if ($status !== '' && $status !== 'ALL') {
                    $metaQuery[] = [
                        'key' => 'ticket_status',
                        'value' => sanitize_key($status),
                        'compare' => '=',
                    ];
                }

                $queryArgs = [
                    'post_type' => 'support_ticket',
                    'post_status' => 'publish',
                    'posts_per_page' => $first + 1,
                    'offset' => $offset,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'meta_query' => $metaQuery,
                ];

                $search = trim((string)($args['search'] ?? ''));
                if ($search !== '') {
                    $queryArgs['s'] = sanitize_text_field($search);
                }

                $query = new WP_Query($queryArgs);
                $posts = $query->posts;
                $hasNextPage = count($posts) > $first;
                $posts = array_slice($posts, 0, $first);

                $nodes = array_map(
                    static fn($post) => WPGraphQL\Data\DataSource::resolve_post_object($post->ID, $context),
                    $posts
                );

                return [
                    'nodes' => $nodes,
                    'pageInfo' => [
                        'hasNextPage' => $hasNextPage,
                        'endCursor' => self::encodeCursor($offset + count($posts)),
                    ],
                ];
            },
        ]);

        register_graphql_field('RootQuery', 'adminOpenTickets', [
            'type' => ['list_of' => 'SupportTicket'],
            'args' => [
                'first' => ['type' => 'Int'],
            ],
            'resolve' => static function ($root, $args, $context) {
                if (!current_user_can('manage_woocommerce')) {
                    throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
                }

                $first = min(max((int)($args['first'] ?? 10), 1), 50);

                $posts = BTL_Cache::remember("admin_open_tickets_{$first}", static function () use ($first) {
                    $query = new WP_Query([
                        'post_type' => 'support_ticket',
                        'post_status' => 'publish',
                        'posts_per_page' => $first,
                        'orderby' => 'date',
                        'order' => 'ASC',
                        'meta_query' => [[
                            'key' => 'ticket_status',
                            'value' => 'open',
                            'compare' => '=',
                        ]],
                    ]);

                    return $query->posts;
                }, 'btl', 60);

                return array_map(
                    static fn($post) => WPGraphQL\Data\DataSource::resolve_post_object($post->ID, $context),
                    $posts
                );
            },
        ]);

        register_graphql_field('RootQuery', 'adminOpenTicketsCount', [
            'type' => 'Int',
            'resolve' => static function () {
                if (!current_user_can('manage_woocommerce')) {
                    return 0;
                }

                return (int) BTL_Cache::remember('admin_open_tickets_count', static function () {
                    global $wpdb;

                    return (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->posts} p
                         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                         WHERE p.post_type = %s AND p.post_status = %s
                           AND pm.meta_key = %s AND pm.meta_value = %s",
                        'support_ticket', 'publish', 'ticket_status', 'open'
                    ));
                }, 'btl', 60);
            },
        ]);
    }

    public static function encodeCursor(int $offset): string
    {
        return base64_encode('btl_offset:' . $offset);
    }

    public static function decodeCursor(?string $cursor): int
    {
        if (!$cursor) return 0;
        $decoded = base64_decode($cursor, true);
        if ($decoded === false || strpos($decoded, 'btl_offset:') !== 0) return 0;
        return max(0, (int)substr($decoded, strlen('btl_offset:')));
    }
}