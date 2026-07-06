<?php
defined('ABSPATH') || exit;

final class BTL_Order_Security_Hooks
{
    public static function boot(): void
    {
        add_action('woocommerce_new_order_item', [self::class, 'encrypt_secure_meta'], 20, 3);
        add_action('woocommerce_update_order_item', [self::class, 'encrypt_secure_meta'], 20, 3);

        add_action('woocommerce_order_status_changed', [self::class, 'wipe_on_terminal_status'], 10, 4);
    }

    public static function encrypt_secure_meta($item_id, $item, $order_id): void
    {
        if (!$item instanceof WC_Order_Item) return;

        $changed = false;
        foreach ($item->get_meta_data() as $entry) {
            $data = $entry->get_data();
            $key = $data['key'];
            if (strpos($key, '_secure_') !== 0) continue;

            $value = (string)$data['value'];
            $fieldType = str_replace('_secure_', '', $key);

            if ($value !== '') {
                BTL_Secure_Fields::store((int)$order_id, (int)$item_id, $fieldType, $value);
            }

            $item->delete_meta_data($key);
            $item->add_meta_data('🔒 ' . self::secureLabel($fieldType), 'رمزنگاری‌شده', true);
            $changed = true;
        }

       
        if ($changed) $item->save_meta_data();
    }

    public static function wipe_on_terminal_status($orderId, $oldStatus, $newStatus, $order): void
    {
        $terminalStatuses = ['completed', 'cancelled', 'failed', 'refunded'];
        if (!in_array($newStatus, $terminalStatuses, true)) return;

        $deleted = BTL_Secure_Fields::wipeCredentialsByOrder((int)$orderId);
        if ($deleted > 0) {
            BTL_Helpers::logger("Order #{$orderId}: {$deleted} credential field(s) wiped after status → {$newStatus}");
        }
    }

    private static function secureLabel(string $type): string
    {
        return match ($type) {
            'email' => 'ایمیل اکانت',
            'password' => 'پسورد اکانت',
            'battletag' => 'بتل‌تگ',
            'cdkey' => 'کد سی‌دی‌کی',
            default => $type,
        };
    }
}