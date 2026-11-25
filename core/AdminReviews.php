<?php
defined('ABSPATH') || exit;

final class BTL_Admin_Reviews
{
    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 12);
    }

    public static function register(): void
    {
        if (!btl_is_admin_graphql_request()) return;
        register_graphql_object_type('BtlAdminReview', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'content' => ['type' => 'String'],
                'rating' => ['type' => 'Int'],
                'date' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'moderationState' => ['type' => 'String'],
                'userId' => ['type' => 'Int'],
                'userName' => ['type' => 'String'],
                'userEmail' => ['type' => 'String'],
                'productId' => ['type' => 'Int'],
                'productName' => ['type' => 'String'],
                'productSlug' => ['type' => 'String'],
            ],
        ]);
        register_graphql_object_type('BtlAdminReviewConnection', [
            'fields' => [
                'nodes' => ['type' => ['list_of' => 'BtlAdminReview']],
                'pageInfo' => ['type' => 'BtlCursorPageInfo'],
            ],
        ]);

        register_graphql_field('RootQuery', 'adminReviews', [
            'type' => 'BtlAdminReviewConnection',
            'args' => [
                'first' => ['type' => 'Int'],
                'after' => ['type' => 'String'],
                'state' => ['type' => 'String'],
                'order' => ['type' => 'String'],
            ],
            'resolve' => static function ($root, array $args): array {
                self::assertPermission();
                $first = min(max((int)($args['first'] ?? 20), 1), 50);
                $offset = BTL_Customer_Tickets::decodeCursor($args['after'] ?? null);
                $state = sanitize_key((string)($args['state'] ?? 'pending'));
                $direction = strtolower((string)($args['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

                $metaQuery = [];
                if ($state === 'flagged') $metaQuery[] = ['key' => '_btl_admin_review_state', 'value' => 'flagged', 'compare' => '='];
                if ($state === 'hidden') $metaQuery[] = ['key' => '_btl_admin_review_state', 'value' => 'hidden', 'compare' => '='];
                if ($state === 'pending') {
                    $metaQuery = [
                        'relation' => 'AND',
                        ['key' => '_btl_admin_review_state', 'compare' => 'NOT EXISTS'],
                    ];
                }

                $queryArgs = [
                    'type' => 'review',
                    'parent' => 0,
                    'status' => $state === 'published' ? 'approve' : ($state === 'flagged' || $state === 'hidden' || $state === 'pending' ? 'hold' : 'all'),
                    'number' => $first + 1,
                    'offset' => $offset,
                    'orderby' => 'comment_date_gmt',
                    'order' => $direction,
                    'meta_query' => $metaQuery,
                ];
                if ($state === 'published') $queryArgs['meta_query'] = [['key' => '_btl_admin_review_state', 'compare' => 'NOT EXISTS']];

                $comments = get_comments($queryArgs);
                $hasNext = count($comments) > $first;
                if ($hasNext) $comments = array_slice($comments, 0, $first);

                $productIds = array_values(array_unique(array_map(static fn($comment): int => (int)$comment->comment_post_ID, $comments)));
                if ($productIds) {
                    _prime_post_caches($productIds, false, true);
                }

                return [
                    'nodes' => array_map([self::class, 'payloadForExternal'], $comments ?: []),
                    'pageInfo' => [
                        'hasNextPage' => $hasNext,
                        'endCursor' => BTL_Customer_Tickets::encodeCursor($offset + count($comments)),
                    ],
                ];
            },
        ]);

        register_graphql_mutation('adminModerateReview', [
            'inputFields' => [
                'reviewId' => ['type' => ['non_null' => 'Int']],
                'action' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => ['success' => ['type' => 'Boolean'], 'state' => ['type' => 'String']],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission();
                $review = get_comment((int)$input['reviewId']);
                $action = sanitize_key((string)$input['action']);
                if (!$review || $review->comment_type !== 'review') throw new GraphQL\Error\UserError('نظر یافت نشد.');
                if (!in_array($action, ['approve', 'hide', 'flag'], true)) throw new GraphQL\Error\UserError('عملیات نامعتبر است.');

                if ($action === 'approve') {
                    wp_set_comment_status($review->comment_ID, 'approve');
                    delete_comment_meta($review->comment_ID, '_btl_admin_review_state');
                    $state = 'published';
                } else {
                    wp_set_comment_status($review->comment_ID, 'hold');
                    update_comment_meta($review->comment_ID, '_btl_admin_review_state', $action === 'flag' ? 'flagged' : 'hidden');
                    $state = $action === 'flag' ? 'flagged' : 'hidden';
                }

                BTL_Admin_Audit::record(get_current_user_id(), 'REVIEW_MODERATION', 'review', $review->comment_ID, 'success', ['action' => $action]);
                BTL_Cache::delete('pending_reviews_count');
                return ['success' => true, 'state' => $state];
            },
        ]);

        register_graphql_mutation('adminReplyToReview', [
            'inputFields' => [
                'reviewId' => ['type' => ['non_null' => 'Int']],
                'content' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission();
                $review = get_comment((int)$input['reviewId']);
                $content = wp_kses_post(trim((string)$input['content']));
                if (!$review || $review->comment_type !== 'review') throw new GraphQL\Error\UserError('نظر یافت نشد.');
                if ((int)$review->comment_parent !== 0) throw new GraphQL\Error\UserError('فقط می‌توان به نظر اصلی پاسخ داد.');
                if ($content === '') throw new GraphQL\Error\UserError('متن پاسخ خالی است.');
                $commentId = wp_insert_comment([
                    'comment_post_ID' => $review->comment_post_ID,
                    'comment_parent' => $review->comment_ID,
                    'comment_content' => $content,
                    'user_id' => get_current_user_id(),
                    'comment_approved' => 1,
                    'comment_type' => 'review',
                ]);
                if (!$commentId) throw new GraphQL\Error\UserError('ثبت پاسخ با خطا مواجه شد.');
                update_comment_meta($commentId, 'btl_is_staff_reply', 1);
                BTL_Admin_Audit::record(get_current_user_id(), 'REVIEW_REPLY', 'review', $review->comment_ID);

                $product = wc_get_product((int)$review->comment_post_ID);
                if ($product && function_exists('btl_queue_revalidation')) {
                    btl_queue_revalidation(["product-{$product->get_slug()}"]);
                }

                return ['success' => true];
            },
        ]);
    }

    public static function payloadForExternal(WP_Comment $comment): array
    {
        $product = wc_get_product((int)$comment->comment_post_ID);
        $metaState = sanitize_key((string)get_comment_meta($comment->comment_ID, '_btl_admin_review_state', true));
        return [
            'databaseId' => (int)$comment->comment_ID,
            'content' => (string)$comment->comment_content,
            'rating' => (int)get_comment_meta($comment->comment_ID, 'rating', true),
            'date' => (string)$comment->comment_date_gmt,
            'status' => (string)$comment->comment_approved === '1' ? 'published' : 'pending',
            'moderationState' => $metaState ?: ((string)$comment->comment_approved === '1' ? 'published' : 'pending'),
            'userId' => (int)$comment->user_id ?: null,
            'userName' => (string)$comment->comment_author,
            'userEmail' => (string)$comment->comment_author_email,
            'productId' => (int)$comment->comment_post_ID,
            'productName' => $product ? $product->get_name() : null,
            'productSlug' => $product ? $product->get_slug() : null,
        ];
    }

    private static function assertPermission(): void
    {
        if (!BTL_Admin_Permissions::can(get_current_user_id(), 'reviews.moderate')) throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
    }
}