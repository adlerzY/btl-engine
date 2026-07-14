<?php

defined('ABSPATH') || exit;

final class BTL_Helpers
{
    public static function money(
        $value
    ): float {

        return (float)
            str_replace(
                [',', '،', ' '],
                '',
                (string)$value
            );
    }

    public static function sanitizeNumber(
        $value
    ): string {

        $value =
            trim(
                str_replace(
                    [',', '،', ' '],
                    '',
                    (string)$value
                )
            );

        if ($value === '') {
            return '';
        }

        if (
            strtolower($value)
            ===
            'disabled'
        ) {
            return 'disabled';
        }

        if (
            !is_numeric($value)
        ) {
            return '';
        }

        $value =
            (float)$value;

        if ($value < 0) {
            return '';
        }

        if (
            $value >
            999999999999
        ) {
            return '';
        }

        return (string)
            round($value);
    }

    public static function bool(
        $value
    ): bool {

        return filter_var(
            $value,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    public static function now(): int
    {
        return current_time(
            'timestamp',
            true
        );
    }

    public static function logger(
        string $message
    ): void {

        error_log(
            '[BTL] ' .
            $message
        );
    }

    public static function cacheKey(
        string $prefix,
        $id
    ): string {

        return sprintf(
            '%s_%s',
            $prefix,
            $id
        );
    }

    public static function ensureTable(
        string $readyOption,
        callable $installer
    ): void {
        if (get_option($readyOption) === '1') {
            return;
        }

        try {
            $installer();
            update_option($readyOption, '1', false);
        } catch (Throwable $e) {
            self::logger("ensureTable({$readyOption}) failed: " . $e->getMessage());
        }
    }
}