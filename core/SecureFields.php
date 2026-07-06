<?php
defined('ABSPATH') || exit;

final class BTL_Secure_Fields
{
    private const CDKEY_TYPE = 'cdkey';
    private const CREDENTIAL_TYPES = ['email', 'password', 'battletag'];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'btl_secure_fields';
    }

    public static function install(): void
    {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            field_type VARCHAR(20) NOT NULL,
            ciphertext TEXT NOT NULL,
            revealed_at DATETIME NULL,
            revealed_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY order_id (order_id),
            KEY item_id (item_id)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function store(int $orderId, int $itemId, string $fieldType, string $plaintext): void
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'order_id'   => $orderId,
            'item_id'    => $itemId,
            'field_type' => $fieldType,
            'ciphertext' => BTL_Secure_Vault::encrypt($plaintext),
        ]);
    }

    public static function exists(int $orderId, int $itemId, string $fieldType): bool
    {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE order_id=%d AND item_id=%d AND field_type=%s",
            $orderId, $itemId, $fieldType
        ));
        return (int)$count > 0;
    }

 
    public static function revealForCustomerCdKey(int $orderId, int $itemId, int $userId): ?string
    {
        return self::revealInternal($orderId, $itemId, self::CDKEY_TYPE, $userId);
    }


    public static function revealForStaff(int $orderId, int $itemId, string $fieldType, int $staffUserId): ?string
    {
        if (!in_array($fieldType, self::CREDENTIAL_TYPES, true)) return null;
        return self::revealInternal($orderId, $itemId, $fieldType, $staffUserId);
    }

    private static function revealInternal(int $orderId, int $itemId, string $fieldType, int $userId): ?string
    {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id=%d AND item_id=%d AND field_type=%s ORDER BY id DESC LIMIT 1",
            $orderId, $itemId, $fieldType
        ));
        if (!$row) return null;

        $plain = BTL_Secure_Vault::decrypt($row->ciphertext);
        if ($plain === null) return null;

        $wpdb->update($table, [
            'revealed_at' => current_time('mysql', true),
            'revealed_by' => $userId,
        ], ['id' => $row->id]);

        return $plain;
    }


    public static function wipeCredentialsByOrder(int $orderId): int
    {
        global $wpdb;
        $table = self::table();
        $placeholders = implode(',', array_fill(0, count(self::CREDENTIAL_TYPES), '%s'));
        $sql = $wpdb->prepare(
            "DELETE FROM {$table} WHERE order_id=%d AND field_type IN ({$placeholders})",
            array_merge([$orderId], self::CREDENTIAL_TYPES)
        );
        return (int)$wpdb->query($sql);
    }
}