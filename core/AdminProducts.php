<?php
defined('ABSPATH') || exit;

final class BTL_Admin_Products
{
    private const STATUSES = ['publish', 'draft', 'private', 'pending'];

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 13);
    }

    public static function register(): void
    {
        if (!btl_is_admin_graphql_request()) return;

        register_graphql_object_type('BtlAdminProduct', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'name' => ['type' => 'String'],
                'slug' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'imageUrl' => ['type' => 'String'],
                'variationCount' => ['type' => 'Int'],
                'codeAvailable' => ['type' => 'Int'],
                'codeReserved' => ['type' => 'Int'],
                'editUrl' => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('BtlAdminVariation', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'name' => ['type' => 'String'],
                'region' => ['type' => 'String'],
                'currency' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'baseForeignPrice' => ['type' => 'String'],
                'directPrice' => ['type' => 'String'],
                'giftPrice' => ['type' => 'String'],
                'codePrice' => ['type' => 'String'],
                'codeAvailable' => ['type' => 'Int'],
                'codeReserved' => ['type' => 'Int'],
                'codeUsed' => ['type' => 'Int'],
            ],
        ]);

        register_graphql_object_type('BtlAdminProductConnection', [
            'fields' => [
                'nodes' => ['type' => ['list_of' => 'BtlAdminProduct']],
                'pageInfo' => ['type' => 'BtlCursorPageInfo'],
            ],
        ]);

        register_graphql_object_type('BtlAdminProductDetail', [
            'fields' => [
                'product' => ['type' => 'BtlAdminProduct'],
                'variations' => ['type' => ['list_of' => 'BtlAdminVariation']],
                'categories' => ['type' => ['list_of' => 'String']],
            ],
        ]);

        register_graphql_field('RootQuery', 'adminProducts', [
            'type' => 'BtlAdminProductConnection',
            'args' => [
                'first' => ['type' => 'Int'],
                'after' => ['type' => 'String'],
                'search' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'sort' => ['type' => 'String'],
                'order' => ['type' => 'String'],
            ],
            'resolve' => static function ($root, array $args): array {
                self::assertPermission();

                $first = min(max((int)($args['first'] ?? 20), 1), 50);
                $offset = BTL_Customer_Tickets::decodeCursor($args['after'] ?? null);
                $search = trim(self::normalizeDigits(sanitize_text_field((string)($args['search'] ?? ''))));
                $status = sanitize_key((string)($args['status'] ?? 'all'));
                $sort = sanitize_key((string)($args['sort'] ?? 'date'));
                $direction = strtolower((string)($args['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

                $queryArgs = [
                    'post_type' => 'product',
                    'post_status' => in_array($status, self::STATUSES, true) ? [$status] : self::STATUSES,
                    'posts_per_page' => $first + 1,
                    'offset' => $offset,
                    'orderby' => $sort === 'title' ? ['title' => $direction, 'ID' => $direction] : ['date' => $direction, 'ID' => $direction],
                    'no_found_rows' => true,
                    'ignore_sticky_posts' => true,
                    'update_post_term_cache' => false,
                ];

                if ($search !== '') {
                    if (preg_match('/^#\s*(\d+)$/', $search, $match)) {
                        $queryArgs['post__in'] = [(int)$match[1]];
                    } else {
                        $queryArgs['s'] = $search;
                    }
                }

                $query = new WP_Query($queryArgs);
                if ($query->posts) update_post_thumbnail_cache($query);

                $posts = $query->posts;
                $hasNext = count($posts) > $first;
                if ($hasNext) $posts = array_slice($posts, 0, $first);

                $ids = array_map(static fn(WP_Post $post): int => (int)$post->ID, $posts);
                $variationCounts = self::variationCounts($ids);
                $codes = self::codeCounts($ids);

                return [
                    'nodes' => array_map(static fn(WP_Post $post): array => self::productPayload(
                        $post,
                        $variationCounts[(int)$post->ID] ?? 0,
                        $codes[(int)$post->ID] ?? []
                    ), $posts),
                    'pageInfo' => [
                        'hasNextPage' => $hasNext,
                        'endCursor' => BTL_Customer_Tickets::encodeCursor($offset + count($posts)),
                    ],
                ];
            },
        ]);

        register_graphql_field('RootQuery', 'adminProduct', [
            'type' => 'BtlAdminProductDetail',
            'args' => ['id' => ['type' => ['non_null' => 'Int']]],
            'resolve' => static function ($root, array $args): ?array {
                self::assertPermission();

                $post = get_post((int)$args['id']);
                if (!$post instanceof WP_Post || $post->post_type !== 'product') return null;

                $id = (int)$post->ID;
                update_post_thumbnail_cache(new WP_Query(['post__in' => [$id], 'post_type' => 'product', 'post_status' => 'any', 'no_found_rows' => true]));

                $terms = wp_get_post_terms($id, 'product_cat', ['fields' => 'names']);

                return [
                    'product' => self::productPayload(
                        $post,
                        self::variationCounts([$id])[$id] ?? 0,
                        self::codeCounts([$id])[$id] ?? []
                    ),
                    'variations' => self::variationsFor($id),
                    'categories' => is_wp_error($terms) ? [] : array_values(array_map('strval', $terms)),
                ];
            },
        ]);
    }

    private static function assertPermission(): void
    {
        $userId = get_current_user_id();
        if (!BTL_Admin_Permissions::can($userId, 'products.read') && !BTL_Admin_Permissions::can($userId, 'cdkeys.read')) {
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

    private static function productPayload(WP_Post $post, int $variationCount, array $codes): array
    {
        $image = get_the_post_thumbnail_url((int)$post->ID, 'thumbnail');
        $editUrl = get_edit_post_link((int)$post->ID, 'raw');

        return [
            'databaseId' => (int)$post->ID,
            'name' => get_the_title($post),
            'slug' => (string)$post->post_name,
            'status' => (string)$post->post_status,
            'imageUrl' => $image ?: null,
            'variationCount' => $variationCount,
            'codeAvailable' => (int)($codes['available'] ?? 0),
            'codeReserved' => (int)($codes['reserved'] ?? 0),
            'editUrl' => $editUrl ?: null,
        ];
    }

    private static function variationCounts(array $ids): array
    {
        global $wpdb;
        if (!$ids) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_parent AS pid, COUNT(*) AS c
             FROM {$wpdb->posts}
             WHERE post_type='product_variation' AND post_status IN ('publish','private') AND post_parent IN ({$placeholders})
             GROUP BY post_parent",
            $ids
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int)$row->pid] = (int)$row->c;
        }
        return $map;
    }

    private static function codeCounts(array $ids): array
    {
        global $wpdb;
        if (!$ids) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id AS pid, status, COUNT(*) AS c
             FROM " . BTL_CdKey_Stock::table() . "
             WHERE product_id IN ({$placeholders}) AND status IN ('available','reserved')
             GROUP BY product_id, status",
            $ids
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int)$row->pid][(string)$row->status] = (int)$row->c;
        }
        return $map;
    }

    private static function variationStock(int $productId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT variation_id AS vid, status, COUNT(*) AS c
             FROM " . BTL_CdKey_Stock::table() . "
             WHERE product_id=%d
             GROUP BY variation_id, status",
            $productId
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int)$row->vid][(string)$row->status] = (int)$row->c;
        }
        return $map;
    }

    private static function variationLabel(WC_Product_Variation $variation): string
    {
        $parts = [];
        foreach ((array)$variation->get_variation_attributes() as $key => $value) {
            $value = trim((string)$value);
            if ($value === '') continue;

            $taxonomy = str_replace('attribute_', '', (string)$key);
            if (taxonomy_exists($taxonomy)) {
                $term = get_term_by('slug', $value, $taxonomy);
                $parts[] = $term && !is_wp_error($term) ? (string)$term->name : $value;
            } else {
                $parts[] = $value;
            }
        }

        return implode(' - ', $parts);
    }

    private static function variationsFor(int $productId): array
    {
        $ids = get_posts([
            'post_type' => 'product_variation',
            'post_parent' => $productId,
            'post_status' => ['publish', 'private'],
            'fields' => 'ids',
            'numberposts' => 200,
            'orderby' => ['menu_order' => 'ASC', 'ID' => 'ASC'],
            'no_found_rows' => true,
        ]);

        if (!$ids) return [];

        $ids = array_map('intval', $ids);
        update_meta_cache('post', $ids);
        $stock = self::variationStock($productId);
        $items = [];

        foreach ($ids as $variationId) {
            $variation = wc_get_product($variationId);
            if (!$variation instanceof WC_Product_Variation) continue;

            $region = BTL_Region_Registry::fromVariation($variation);
            $counts = $stock[$variationId] ?? [];
            $direct = (string)$variation->get_price();
            $gift = (string)$variation->get_meta('_btl_gift_final_price');
            $code = (string)$variation->get_meta('_btl_code_final_price');
            $base = (string)$variation->get_meta('base_foreign_price');

            $items[] = [
                'databaseId' => $variationId,
                'name' => self::variationLabel($variation),
                'region' => $region['region'] ?? null,
                'currency' => $region['currency'] ?? null,
                'status' => (string)get_post_status($variationId),
                'baseForeignPrice' => $base !== '' ? $base : null,
                'directPrice' => $direct !== '' ? $direct : null,
                'giftPrice' => $gift !== '' ? $gift : null,
                'codePrice' => $code !== '' ? $code : null,
                'codeAvailable' => (int)($counts['available'] ?? 0),
                'codeReserved' => (int)($counts['reserved'] ?? 0),
                'codeUsed' => (int)($counts['used'] ?? 0),
            ];
        }

        return $items;
    }
}