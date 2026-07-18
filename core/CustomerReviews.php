<?php
defined('ABSPATH') || exit;

final class BTL_Customer_Reviews
{
    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
        add_action('wp_insert_comment', [self::class, 'notify_on_review_reply'], 10, 2);
    }

    public static function register(): void
    {
        register_graphql_object_type('MyReviewReply', [
            'fields' => [
                'content' => ['type' => 'String'],
                'date' => ['type' => 'String'],
                'authorName' => ['type' => 'String'],
            ],
        ]);

        register_graphql_object_type('MyReviewItem', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'content' => ['type' => 'String'],
                'rating' => ['type' => 'Int'],
                'date' => ['type' => 'String'],
                'approved' => ['type' => 'Boolean'],
                'productId' => ['type' => 'Int'],
                'productName' => ['type' => 'String'],
                'productSlug' => ['type' => 'String'],
                'replies' => ['type' => ['list_of' => 'MyReviewReply']],
            ],
        ]);

        register_graphql_object_type('MyReviewsConnection', [
            'fields' => [
                'nodes' => ['type' => ['list_of' => 'MyReviewItem']],
                'pageInfo' => ['type' => 'BtlCursorPageInfo'],
                'totalCount' => ['type' => 'Int'],
            ],
        ]);

        register_graphql_field('RootQuery', 'myReviews', [
            'type' => 'MyReviewsConnection',
            'args' => [
                'first' => ['type' => 'Int'],
                'after' => ['type' => 'String'],
            ],
            'resolve' => static function ($root, $args) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                }

                $userId = get_current_user_id();
                $first = min(max((int)($args['first'] ?? 20), 0), 50);
                $offset = BTL_Customer_Tickets::decodeCursor($args['after'] ?? null);

                $totalCount = (int) get_comments([
                    'user_id' => $userId,
                    'type' => 'review',
                    'status' => 'all',
                    'parent' => 0,
                    'count' => true,
                ]);

                if ($first === 0) {
                    return [
                        'nodes' => [],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        'totalCount' => $totalCount,
                    ];
                }

                $comments = get_comments([
                    'user_id' => $userId,
                    'type' => 'review',
                    'status' => 'all',
                    'parent' => 0,
                    'number' => $first + 1,
                    'offset' => $offset,
                    'orderby' => 'comment_date',
                    'order' => 'DESC',
                ]);

                $comments = array_values(array_filter($comments, static function ($c) {
                    return in_array((string)$c->comment_approved, ['0', '1'], true);
                }));

                $hasNextPage = count($comments) > $first;
                $comments = array_slice($comments, 0, $first);

                $nodes = array_map(static function ($comment) {
                    $product = wc_get_product((int)$comment->comment_post_ID);

                    $replyComments = get_comments([
                        'parent' => $comment->comment_ID,
                        'status' => 'approve',
                        'orderby' => 'comment_date',
                        'order' => 'ASC',
                    ]);

                    return [
                        'databaseId' => (int)$comment->comment_ID,
                        'content' => $comment->comment_content,
                        'rating' => (int)get_comment_meta($comment->comment_ID, 'rating', true),
                        'date' => $comment->comment_date,
                        'approved' => (string)$comment->comment_approved === '1',
                        'productId' => (int)$comment->comment_post_ID,
                        'productName' => $product ? $product->get_name() : '',
                        'productSlug' => $product ? $product->get_slug() : '',
                        'replies' => array_map(static function ($reply) {
                            return [
                                'content' => $reply->comment_content,
                                'date' => $reply->comment_date,
                                'authorName' => 'پشتیبانی',
                            ];
                        }, $replyComments),
                    ];
                }, $comments);

                return [
                    'nodes' => $nodes,
                    'pageInfo' => [
                        'hasNextPage' => $hasNextPage,
                        'endCursor' => BTL_Customer_Tickets::encodeCursor($offset + count($comments)),
                    ],
                    'totalCount' => $totalCount,
                ];
            },
        ]);

        register_graphql_mutation('deleteMyReview', [
            'inputFields' => [
                'reviewId' => ['type' => ['non_null' => 'Int']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                }

                $userId = get_current_user_id();
                $comment = get_comment((int) $input['reviewId']);

                if (!$comment || $comment->comment_type !== 'review') {
                    throw new GraphQL\Error\UserError('نظر یافت نشد.');
                }

                if ((int) $comment->user_id !== $userId) {
                    throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
                }

                if ((string) $comment->comment_approved === '1') {
                    throw new GraphQL\Error\UserError('نظر تأییدشده قابل حذف نیست.');
                }

                $deleted = wp_delete_comment($comment->comment_ID, true);

                if (!$deleted) {
                    throw new GraphQL\Error\UserError('حذف نظر با خطا مواجه شد.');
                }

                return ['success' => true];
            },
        ]);

        register_graphql_mutation('editMyReview', [
            'inputFields' => [
                'reviewId' => ['type' => ['non_null' => 'Int']],
                'content' => ['type' => ['non_null' => 'String']],
                'rating' => ['type' => ['non_null' => 'Int']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) {
                    throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');
                }

                $userId = get_current_user_id();
                $comment = get_comment((int) $input['reviewId']);

                if (!$comment || $comment->comment_type !== 'review') {
                    throw new GraphQL\Error\UserError('نظر یافت نشد.');
                }

                if ((int) $comment->user_id !== $userId) {
                    throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
                }

                if ((string) $comment->comment_approved === '1') {
                    throw new GraphQL\Error\UserError('نظر تأییدشده قابل ویرایش نیست.');
                }

                $rating = (int) $input['rating'];
                if ($rating < 1 || $rating > 5) {
                    throw new GraphQL\Error\UserError('امتیاز نامعتبر است.');
                }

                $content = wp_kses_post(trim($input['content']));
                if ($content === '') {
                    throw new GraphQL\Error\UserError('متن نظر خالی است.');
                }

                wp_update_comment([
                    'comment_ID' => $comment->comment_ID,
                    'comment_content' => $content,
                ]);

                update_comment_meta($comment->comment_ID, 'rating', $rating);

                return ['success' => true];
            },
        ]);
    }

    public static function notify_on_review_reply($commentId, $comment): void
    {
        if ((int)$comment->comment_parent === 0) return;
        if ($comment->comment_type !== 'review') return;

        $replierId = (int)$comment->user_id;
        if (!$replierId || !user_can($replierId, 'manage_woocommerce')) return;

        $parent = get_comment($comment->comment_parent);
        if (!$parent) return;

        $authorId = (int)$parent->user_id;
        if (!$authorId || $authorId === $replierId) return;

        $product = wc_get_product((int)$comment->comment_post_ID);
        $productName = $product ? $product->get_name() : 'محصول';

        BTL_Notifications::push(
            $authorId,
            'پاسخ جدید به دیدگاه شما 💬',
            sprintf('پشتیبانی به دیدگاه شما درباره «%s» پاسخ داد.', $productName),
            '/my-account/reviews'
        );
    }
}