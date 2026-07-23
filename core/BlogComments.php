<?php
defined('ABSPATH') || exit;

final class BTL_Blog_Comments
{
    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
    }

    public static function register(): void
    {
        register_graphql_field('Post', 'commentsCount', [
            'type' => 'Int',
            'resolve' => static function ($post) {
                $id = (int)$post->databaseId;
                return BTL_Cache::remember("post_comments_count_{$id}", static function () use ($id) {
                    return (int) get_comments(['post_id' => $id, 'status' => 'approve', 'count' => true]);
                }, 'btl', 60);
            },
        ]);

        register_graphql_mutation('writeBlogComment', [
            'inputFields' => [
                'postId' => ['type' => ['non_null' => 'Int']],
                'content' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'approved' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');

                $postId = (int)$input['postId'];
                if (get_post_type($postId) !== 'post') throw new GraphQL\Error\UserError('پست یافت نشد.');

                $content = wp_kses_post(trim($input['content']));
                if ($content === '') throw new GraphQL\Error\UserError('متن نظر خالی است.');

                $isStaff = current_user_can('manage_woocommerce');

                $commentId = wp_insert_comment([
                    'comment_post_ID' => $postId,
                    'comment_content' => $content,
                    'user_id' => get_current_user_id(),
                    'comment_approved' => $isStaff ? 1 : 0,
                    'comment_type' => 'comment',
                ]);

                if (!$commentId) throw new GraphQL\Error\UserError('ثبت نظر با خطا مواجه شد.');

                BTL_Cache::delete("post_comments_count_{$postId}");

                $comment = get_comment($commentId);

                return [
                    'success' => true,
                    'approved' => $comment && (string)$comment->comment_approved === '1',
                ];
            },
        ]);

        register_graphql_mutation('replyToBlogComment', [
            'inputFields' => [
                'commentId' => ['type' => ['non_null' => 'Int']],
                'content' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'approved' => ['type' => 'Boolean'],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new GraphQL\Error\UserError('باید وارد حساب کاربری شوید.');

                $parent = get_comment((int)$input['commentId']);
                if (!$parent || get_post_type($parent->comment_post_ID) !== 'post') {
                    throw new GraphQL\Error\UserError('نظر مورد نظر یافت نشد.');
                }

                $content = wp_kses_post(trim($input['content']));
                if ($content === '') throw new GraphQL\Error\UserError('متن پاسخ خالی است.');

                $currentUserId = get_current_user_id();
                $isStaff = current_user_can('manage_woocommerce');

                $commentId = wp_insert_comment([
                    'comment_post_ID' => $parent->comment_post_ID,
                    'comment_parent' => $parent->comment_ID,
                    'comment_content' => $content,
                    'user_id' => $currentUserId,
                    'comment_approved' => $isStaff ? 1 : 0,
                    'comment_type' => 'comment',
                ]);

                if (!$commentId) throw new GraphQL\Error\UserError('ثبت پاسخ با خطا مواجه شد.');

                if ($isStaff) {
                    update_comment_meta($commentId, 'btl_is_staff_reply', 1);
                }

                BTL_Cache::delete("post_comments_count_{$parent->comment_post_ID}");

                $authorId = (int)$parent->user_id;
                if ($isStaff && $authorId && $authorId !== $currentUserId) {
                    BTL_Notifications::push(
                        $authorId,
                        'پاسخ جدید به نظر شما 💬',
                        sprintf('%s به نظر شما در «%s» پاسخ داد.', get_the_author_meta('display_name', $currentUserId), get_the_title($parent->comment_post_ID)),
                        '/blog/' . self::firstCategorySlug((int)$parent->comment_post_ID) . '/' . get_post_field('post_name', $parent->comment_post_ID),
                        'blog'
                    );
                }

                $comment = get_comment($commentId);

                return [
                    'success' => true,
                    'approved' => $comment && (string)$comment->comment_approved === '1',
                ];
            },
        ]);
    }

    private static function firstCategorySlug(int $postId): string
    {
        $cats = get_the_category($postId);
        return $cats[0]->slug ?? 'uncategorized';
    }
}