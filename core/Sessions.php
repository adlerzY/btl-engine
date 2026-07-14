<?php
defined('ABSPATH') || exit;

final class BTL_Sessions
{
    private const READY_OPTION = 'btl_sessions_table_ready';

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'btl_sessions'; }

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
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
            user_id BIGINT UNSIGNED NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            device_label VARCHAR(190) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            last_active DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY user_session (user_id, session_id),
            KEY user_id (user_id)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function register(): void
    {
        register_graphql_object_type('UserSession', [
            'fields' => [
                'sessionId' => ['type' => 'String', 'resolve' => fn($s) => $s['session_id']],
                'deviceLabel' => ['type' => 'String', 'resolve' => fn($s) => $s['device_label']],
                'ipAddress' => ['type' => 'String', 'resolve' => fn($s) => $s['ip_address']],
                'lastActive' => ['type' => 'String', 'resolve' => fn($s) => $s['last_active']],
                'createdAt' => ['type' => 'String', 'resolve' => fn($s) => $s['created_at']],
            ],
        ]);

        register_graphql_field('User', 'sessions', [
            'type' => ['list_of' => 'UserSession'],
            'resolve' => static function ($user) {
                $currentUserId = get_current_user_id();
                if (!$currentUserId || $currentUserId !== (int)$user->databaseId) return [];
                return self::listSessions($currentUserId);
            },
        ]);

        register_graphql_field('User', 'activeSessionValid', [
            'type' => 'Boolean',
            'args' => ['sessionId' => ['type' => 'String']],
            'resolve' => static function ($user, $args) {
                $currentUserId = get_current_user_id();
                if (!$currentUserId || $currentUserId !== (int)$user->databaseId) return false;
                if (empty($args['sessionId'])) return true;
                return self::isValid($currentUserId, $args['sessionId']);
            },
        ]);

        register_graphql_mutation('registerSession', [
            'inputFields' => [
                'sessionId' => ['type' => ['non_null' => 'String']],
                'deviceLabel' => ['type' => 'String'],
                'ipAddress' => ['type' => 'String'],
                'userAgent' => ['type' => 'String'],
            ],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new GraphQL\Error\UserError('باید وارد شوید.');
                self::upsert(get_current_user_id(), $input);
                return ['success' => true];
            },
        ]);

        register_graphql_mutation('touchSession', [
            'inputFields' => ['sessionId' => ['type' => ['non_null' => 'String']]],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new GraphQL\Error\UserError('باید وارد شوید.');
                self::touch(get_current_user_id(), $input['sessionId']);
                return ['success' => true];
            },
        ]);

        register_graphql_mutation('revokeSession', [
            'inputFields' => ['sessionId' => ['type' => ['non_null' => 'String']]],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new GraphQL\Error\UserError('باید وارد شوید.');
                self::revoke(get_current_user_id(), $input['sessionId']);
                return ['success' => true];
            },
        ]);
    }

    public static function upsert(int $userId, array $input): void
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table() . " WHERE user_id=%d AND session_id=%s",
            $userId, $input['sessionId']
        ));

        $data = [
            'user_id' => $userId,
            'session_id' => $input['sessionId'],
            'device_label' => $input['deviceLabel'] ?? null,
            'ip_address' => $input['ipAddress'] ?? null,
            'user_agent' => isset($input['userAgent']) ? substr($input['userAgent'], 0, 255) : null,
            'revoked' => 0,
            'last_active' => $now,
        ];

        if ($existing) {
            $wpdb->update(self::table(), $data, ['id' => $existing]);
        } else {
            $data['created_at'] = $now;
            $wpdb->insert(self::table(), $data);
        }
    }

    public static function touch(int $userId, string $sessionId): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['last_active' => current_time('mysql', true)], [
            'user_id' => $userId, 'session_id' => $sessionId,
        ]);
    }

    public static function revoke(int $userId, string $sessionId): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['revoked' => 1], [
            'user_id' => $userId, 'session_id' => $sessionId,
        ]);
    }

    public static function isValid(int $userId, string $sessionId): bool
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT revoked FROM " . self::table() . " WHERE user_id=%d AND session_id=%s",
            $userId, $sessionId
        ));

        if ($wpdb->last_error) {
            BTL_Helpers::logger('Sessions::isValid DB error: ' . $wpdb->last_error);
            return true;
        }

        if (!$row) return true;
        return (int)$row->revoked === 0;
    }

    public static function listSessions(int $userId): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id=%d AND revoked=0 ORDER BY last_active DESC",
            $userId
        ), ARRAY_A);
    }
}