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