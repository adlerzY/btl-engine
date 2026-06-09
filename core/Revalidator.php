<?php

defined('ABSPATH') || exit;

final class BTL_Revalidator
{
    private const GROUP = 'btl';
    private const CACHE_KEY = 'btl_revalidate_tags';
    private const LOCK_KEY = 'btl_revalidate_lock';

    public static function boot(): void
    {
        add_action(
            'btl_revalidate_flush',
            [self::class, 'flush'],
            10
        );
    }

    public static function queue(
        array $tags
    ): void {
        if (!$tags) {
            return;
        }

        $current =
            get_transient(
                self::CACHE_KEY
            );

        if (!is_array($current)) {
            $current = [];
        }

        $merged =
            array_unique(
                array_merge(
                    $current,
                    $tags
                )
            );

        set_transient(
            self::CACHE_KEY,
            $merged,
            300
        );

        if (
            function_exists(
                'as_has_scheduled_action'
            ) &&
            !as_has_scheduled_action(
                'btl_revalidate_flush',
                [],
                self::GROUP
            )
        ) {
            as_schedule_single_action(
                time() + 15,
                'btl_revalidate_flush',
                [],
                self::GROUP
            );
        }
    }

    public static function flush(): void
    {
        if (
            get_transient(
                self::LOCK_KEY
            )
        ) {
            return;
        }

        set_transient(
            self::LOCK_KEY,
            1,
            30
        );

        try {
            $tags =
                get_transient(
                    self::CACHE_KEY
                );

            if (
                !is_array($tags) ||
                empty($tags)
            ) {
                return;
            }

            delete_transient(
                self::CACHE_KEY
            );

            self::send(
                array_values(
                    array_unique(
                        $tags
                    )
                )
            );

        } finally {
            delete_transient(
                self::LOCK_KEY
            );
        }
    }

    private static function send(
        array $tags
    ): void {
        $tags =
            array_slice(
                array_unique($tags),
                0,
                1000
            );

        if (!$tags) {
            return;
        }

        $endpoint =
            defined(
                'NEXTJS_API_URL'
            )
            ? NEXTJS_API_URL
            : '';

        $secret =
            defined(
                'NEXTJS_REVALIDATE_SECRET'
            )
            ? NEXTJS_REVALIDATE_SECRET
            : '';

        if (
            !$endpoint ||
            !$secret
        ) {
            return;
        }

        wp_remote_post(
            $endpoint,
            [
                'timeout' => 15,
                'blocking' => false,
                'headers' => [
                    'Content-Type' =>
                        'application/json',
                    'x-revalidate-secret' =>
                        $secret
                ],
                'body' =>
                    wp_json_encode([
                        'tag' => $tags
                    ])
            ]
        );
    }
}

function btl_queue_revalidation(
    array $tags
): void {
    BTL_Revalidator::queue(
        $tags
    );
}