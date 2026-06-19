<?php

defined('ABSPATH') || exit;

final class BTL_Cache
{
    public static function get(
        string $key,
        string $group = 'btl'
    ) {
        return wp_cache_get(
            $key,
            $group
        );
    }

    public static function set(
        string $key,
        $value,
        string $group = 'btl',
        int $ttl = 3600 // زمان را به همان ۳۶۰۰ برگردان تا دیتای جدید اشتباهاً همیشه قفل نشود
    ): bool {

        return wp_cache_set(
            $key,
            $value,
            $group,
            $ttl
        );
    }

    public static function delete(
        string $key,
        string $group = 'btl'
    ): bool {

        return wp_cache_delete(
            $key,
            $group
        );
    }

    public static function remember(
        string $key,
        callable $callback,
        string $group = 'btl',
        int $ttl = 3600
    ) {
        // ❌ کش را موقتاً کاملاً بای‌پاس (Bypass) می‌کنیم
        // با این کار مستقیماً دیتای اصلی از دیتابیس لود می‌شود
        return $callback();

        /* کدهای قبلی را موقتاً کامنت می‌کنیم:
        $cached = self::get(
            $key,
            $group
        );

        if ($cached !== false) {
            return $cached;
        }

        $value = $callback();

        self::set(
            $key,
            $value,
            $group,
            $ttl
        );

        return $value;
        */
    }

    public static function forgetMany(
        array $keys,
        string $group = 'btl'
    ): void {

        foreach (
            $keys as $key
        ) {

            wp_cache_delete(
                $key,
                $group
            );
        }
    }

    public static function flushGroup(
        string $group
    ): void {

        if (
            function_exists(
                'wp_cache_flush_group'
            )
        ) {

            wp_cache_flush_group(
                $group
            );
        }
    }
}