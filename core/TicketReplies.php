<?php
defined('ABSPATH') || exit;

final class BTL_Ticket_Replies
{
    private const READY_OPTION = 'btl_ticket_replies_table_ready';

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_ticket_replies'; }

    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_install'], 5);
    }

    public static function maybe_install(): void
    {
        BTL_Helpers::ensureTable(self::READY_OPTION, [self::class, 'install']);
    }

    public static function install(): void
    {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL,
            author_role VARCHAR(20) NOT NULL DEFAULT 'customer',
            content TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ticket_id (ticket_id)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function add(int $ticketId, int $authorId, string $role, string $content): int
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'ticket_id' => $ticketId,
            'author_id' => $authorId,
            'author_role' => $role,
            'content' => $content,
        ]);
        return (int)$wpdb->insert_id;
    }

    public static function forTicket(int $ticketId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE ticket_id=%d ORDER BY id ASC",
            $ticketId
        ), ARRAY_A);
    }
}