<?php
defined('ABSPATH') || exit;

use GraphQL\Error\UserError;

final class BTL_Sessions
{
    private const REGISTER_SESSION_CANONICAL = 'mutation RegisterSession($sessionId:String!,$deviceLabel:String,$ipAddress:String,$userAgent:String){registerSession(input:{sessionId:$sessionId,deviceLabel:$deviceLabel,ipAddress:$ipAddress,userAgent:$userAgent}){success isStaff}}';
    private const TOUCH_SESSION_CANONICAL = 'mutation TouchSession($sessionId:String!){touchSession(input:{sessionId:$sessionId}){success}}';
    private const REVOKE_CURRENT_SESSION_CANONICAL = 'mutation RevokeCurrentSession{revokeCurrentSession{success}}';
    private const READY_OPTION = 'btl_sessions_table_ready_v2';
    private const SESSION_INACTIVITY_DAYS = 30;
    /** @var array<string,bool> */
    private static array $requestSessionCache = [];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'btl_sessions';
    }

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 10);
        // Reject bearer-token GraphQL requests unless the token is bound to a live session.
        add_filter('graphql_request_data', [self::class, 'authorizeGraphqlRequest'], 5, 2);
        add_filter('graphql_jwt_auth_signed_token', [self::class, 'bindRefreshedToken'], 10, 2);
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
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            device_label VARCHAR(190) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            token_hash CHAR(64) NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            last_active DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_session (user_id, session_id),
            KEY user_id (user_id),
            KEY last_active (last_active),
            UNIQUE KEY token_hash (token_hash)
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
                if (empty($args['sessionId'])) return false;
                return self::isValid($currentUserId, (string)$args['sessionId']);
            },
        ]);

        register_graphql_mutation('registerSession', [
            'inputFields' => [
                'sessionId' => ['type' => ['non_null' => 'String']],
                'deviceLabel' => ['type' => 'String'],
                'ipAddress' => ['type' => 'String'],
                'userAgent' => ['type' => 'String'],
            ],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'isStaff' => [
                    'type' => 'Boolean',
                    'resolve' => static function () {
                        $userId = get_current_user_id();
                        return $userId ? user_can($userId, 'manage_woocommerce') : false;
                    },
                ],
            ],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new UserError('باید وارد شوید.');
                try {
                    self::upsert(get_current_user_id(), $input);
                    return ['success' => true];
                } catch (\Throwable $e) {
                    BTL_Helpers::logger('registerSession error: ' . $e->getMessage());
                    throw new UserError('خطا در ثبت نشست کاربری.');
                }
            },
        ]);

        register_graphql_mutation('touchSession', [
            'inputFields' => ['sessionId' => ['type' => ['non_null' => 'String']]],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new UserError('باید وارد شوید.');
                try {
                    self::touch(get_current_user_id(), (string)$input['sessionId']);
                    return ['success' => true];
                } catch (\Throwable $e) {
                    return ['success' => false];
                }
            },
        ]);

        register_graphql_mutation('revokeCurrentSession', [
            'inputFields' => [],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => function () {
                if (!is_user_logged_in()) throw new UserError('باید وارد شوید.');
                try {
                    $success = self::revokeCurrentToken(get_current_user_id());
                    return ['success' => $success];
                } catch (Throwable $e) {
                    BTL_Helpers::logger('revokeCurrentSession error: ' . $e->getMessage());
                    return ['success' => false];
                }
            },
        ]);

        register_graphql_mutation('revokeSession', [
            'inputFields' => ['sessionId' => ['type' => ['non_null' => 'String']]],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => function ($input) {
                if (!is_user_logged_in()) throw new UserError('باید وارد شوید.');
                try {
                    self::revoke(get_current_user_id(), (string)$input['sessionId']);
                    return ['success' => true];
                } catch (\Throwable $e) {
                    BTL_Helpers::logger('revokeSession error: ' . $e->getMessage());
                    throw new UserError('خطا در لغو نشست.');
                }
            },
        ]);
    }

    public static function upsert(int $userId, array $input): void
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $sessionId = sanitize_text_field((string)($input['sessionId'] ?? ''));
        $tokenHash = self::currentTokenHash();
        if ($userId < 1 || !preg_match('/^[A-Za-z0-9_-]{32,128}$/', $sessionId) || $tokenHash === '' || !self::validBootstrapProof($sessionId, $tokenHash)) {
            throw new RuntimeException('session_binding_required');
        }

        // A revoked token must never be able to recreate a session. A new token
        // may bootstrap exactly one session during the post-login handshake.
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id,session_id,revoked FROM " . self::table() . " WHERE token_hash=%s LIMIT 1",
            $tokenHash
        ));
        if ($wpdb->last_error) throw new RuntimeException('session_lookup_failed');
        if ($existing && ((int)$existing->revoked !== 0
            || (int)$existing->user_id !== $userId
            || (string)$existing->session_id !== $sessionId)) {
            throw new RuntimeException('session_binding_rejected');
        }

        $deviceLabel = isset($input['deviceLabel']) ? mb_substr(sanitize_text_field((string)$input['deviceLabel']), 0, 190) : null;
        $ipAddress = isset($input['ipAddress']) ? mb_substr(sanitize_text_field((string)$input['ipAddress']), 0, 45) : null;
        $userAgent = isset($input['userAgent']) ? mb_substr(sanitize_text_field((string)$input['userAgent']), 0, 255) : null;
        $table = self::table();
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (user_id,session_id,device_label,ip_address,user_agent,token_hash,revoked,last_active,created_at)
             VALUES (%d,%s,%s,%s,%s,%s,0,%s,%s)
             ON DUPLICATE KEY UPDATE
                device_label=VALUES(device_label), ip_address=VALUES(ip_address),
                user_agent=VALUES(user_agent), token_hash=VALUES(token_hash),
                revoked=0, last_active=VALUES(last_active)",
            $userId, $sessionId, $deviceLabel, $ipAddress, $userAgent, $tokenHash, $now, $now
        ));
        if ($result === false) throw new RuntimeException('session_write_failed');
        self::clearRequestSessionCache();
    }

    public static function touch(int $userId, string $sessionId): void
    {
        global $wpdb;
        $tokenHash = self::currentTokenHash();
        $previousTokenHash = self::previousTokenHash();
        $now = current_time('mysql', true);
        if ($userId < 1 || $tokenHash === '' || $previousTokenHash === '' || !self::sessionExists($userId, $sessionId, true, $previousTokenHash)) {
            throw new RuntimeException('session_binding_rejected');
        }
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET token_hash=%s,last_active=%s
             WHERE user_id=%d AND session_id=%s AND revoked=0",
            $tokenHash, $now, $userId, $sessionId
        ));
        if ($updated === false || $updated < 1) throw new RuntimeException('session_touch_failed');
        self::clearRequestSessionCache();
    }

    private static function revokeCurrentToken(int $userId): bool
    {
        global $wpdb;
        $tokenHash = self::currentTokenHash();
        if ($userId < 1 || $tokenHash === '') return false;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET revoked=1 WHERE user_id=%d AND token_hash=%s AND revoked=0",
            $userId, $tokenHash
        ));
        self::clearRequestSessionCache();
        return $updated !== false && $updated > 0;
    }

    public static function revoke(int $userId, string $sessionId): void
    {
        global $wpdb;
        $wpdb->update(
            self::table(),
            ['revoked' => 1],
            ['user_id' => $userId, 'session_id' => $sessionId],
            ['%d'],
            ['%d', '%s']
        );
        self::clearRequestSessionCache();
    }

    public static function revokeAll(int $userId): void
    {
        global $wpdb;
        $wpdb->update(
            self::table(),
            ['revoked' => 1],
            ['user_id' => $userId],
            ['%d'],
            ['%d']
        );
        self::clearRequestSessionCache();
    }

    public static function revokeAllExcept(int $userId, ?string $exceptSessionId): void
    {
        if (!$exceptSessionId) {
            self::revokeAll($userId);
            return;
        }

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET revoked=1 WHERE user_id=%d AND session_id != %s",
            $userId,
            $exceptSessionId
        ));
        self::clearRequestSessionCache();
    }

    public static function isValid(int $userId, string $sessionId): bool
    {
        return self::sessionExists($userId, $sessionId, true);
    }

    private static function sessionExists(int $userId, string $sessionId, bool $requireToken, ?string $expectedTokenHash = null): bool
    {
        if ($userId < 1 || $sessionId === '') return false;

        $tokenHash = $requireToken ? ($expectedTokenHash ?: self::currentTokenHash()) : '';
        $cacheKey = $userId . '|' . $sessionId . '|' . ($requireToken ? '1' : '0') . '|' . $tokenHash;
        if (array_key_exists($cacheKey, self::$requestSessionCache)) {
            return self::$requestSessionCache[$cacheKey];
        }

        global $wpdb;
        $table = self::table();
        if ($requireToken) {
            if ($tokenHash === '') return self::$requestSessionCache[$cacheKey] = false;
            $sql = "SELECT 1 FROM {$table} WHERE user_id=%d AND session_id=%s AND token_hash=%s AND revoked=0 LIMIT 1";
            $row = $wpdb->get_var($wpdb->prepare($sql, $userId, $sessionId, $tokenHash));
        } else {
            $sql = "SELECT 1 FROM {$table} WHERE user_id=%d AND session_id=%s AND revoked=0 LIMIT 1";
            $row = $wpdb->get_var($wpdb->prepare($sql, $userId, $sessionId));
        }

        if ($wpdb->last_error) {
            return self::$requestSessionCache[$cacheKey] = false;
        }
        return self::$requestSessionCache[$cacheKey] = ((string)$row === '1');
    }

    private static function clearRequestSessionCache(): void
    {
        self::$requestSessionCache = [];
    }

    private static function currentTokenHash(): string
    {
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $m)) return '';
        $token = trim($m[1]);
        return $token === '' ? '' : hash('sha256', $token);
    }

    private static function requestSessionId(): string
    {
        return sanitize_text_field((string)($_SERVER['HTTP_X_BTL_SESSION_ID'] ?? ''));
    }

    private static function previousTokenHash(): string
    {
        $authorization = (string)($_SERVER['HTTP_X_BTL_PREVIOUS_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $m)) return '';
        $token = trim($m[1]);
        return $token === '' ? '' : hash('sha256', $token);
    }

    public static function bindRefreshedToken($token, $userId)
    {
        if (!is_string($token) || $token === '' || (int)$userId < 1) return $token;
        if ((string)($_SERVER['HTTP_X_BTL_SESSION_REFRESH'] ?? '') !== '1') return $token;

        $sessionId = self::requestSessionId();
        $previousTokenHash = self::previousTokenHash();
        if ($sessionId === '' || $previousTokenHash === '') return null;

        global $wpdb;
        $currentTokenHash = hash('sha256', $token);
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET token_hash=%s,last_active=%s WHERE user_id=%d AND session_id=%s AND token_hash=%s AND revoked=0',
            $currentTokenHash,
            current_time('mysql', true),
            (int)$userId,
            $sessionId,
            $previousTokenHash
        ));
        if ($updated !== 1) {
            self::clearRequestSessionCache();
            return null;
        }

        self::clearRequestSessionCache();
        return $token;
    }

    private static function validBootstrapProof(string $sessionId, string $tokenHash): bool
    {
        if (!defined('BTL_SESSION_BINDING_SECRET') || BTL_SESSION_BINDING_SECRET === '') return false;
        $provided = (string)($_SERVER['HTTP_X_BTL_SESSION_BOOTSTRAP'] ?? '');
        if ($provided === '') return false;
        $expected = hash_hmac('sha256', $sessionId . '.' . $tokenHash, BTL_SESSION_BINDING_SECRET);
        return hash_equals($expected, $provided);
    }


    private static function canonicalizeBootstrapMutation(string $query): string
    {
        $query = preg_replace('/#[^\r\n]*/', '', $query) ?? $query;
        $query = preg_replace('/\s+/', ' ', trim($query)) ?? trim($query);
        $query = preg_replace('/\s*([!$():=@\[\]{}{},])\s*/', '$1', $query) ?? $query;
        return trim($query);
    }
    public static function authorizeGraphqlRequest($requestData, $request = null)
    {
        if (!is_array($requestData)) return $requestData;
        $query = (string)($requestData['query'] ?? '');
        $tokenHash = self::currentTokenHash();
        if ($tokenHash === '' || trim($query) === '') return $requestData;

        // Bootstrap and refresh are the only authenticated requests that may
        // establish a session binding before normal authorization. Keep these
        // checks exact and cheap; the previous implementation scanned a very
        // large resolver-name blacklist on every authenticated GraphQL request.
        $hasBootstrapProof = isset($_SERVER['HTTP_X_BTL_SESSION_BOOTSTRAP'])
            && $_SERVER['HTTP_X_BTL_SESSION_BOOTSTRAP'] !== '';
        $hasPreviousAuthorization = isset($_SERVER['HTTP_X_BTL_PREVIOUS_AUTHORIZATION'])
            && $_SERVER['HTTP_X_BTL_PREVIOUS_AUTHORIZATION'] !== '';

        if ($hasBootstrapProof) {
            $canonicalQuery = self::canonicalizeBootstrapMutation($query);
            if (hash_equals(self::REGISTER_SESSION_CANONICAL, $canonicalQuery)) {
                return $requestData;
            }
        }

        if ($hasPreviousAuthorization) {
            $canonicalQuery = self::canonicalizeBootstrapMutation($query);
            if (hash_equals(self::TOUCH_SESSION_CANONICAL, $canonicalQuery)) {
                return $requestData;
            }
        }

        if (self::currentTokenHash() !== '' && hash_equals(self::REVOKE_CURRENT_SESSION_CANONICAL, trim($query))) {
            return $requestData;
        }

        if (!self::sessionExists(get_current_user_id(), self::requestSessionId(), true)) {
            // Keep the request syntactically valid but guaranteed to fail GraphQL
            // validation, so no resolver (including customer/order resolvers) runs.
            $requestData['query'] = 'query BtlSessionDenied { __btl_session_denied__ }';
        }
        return $requestData;
    }

    public static function listSessions(int $userId): array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $days = self::SESSION_INACTIVITY_DAYS;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " 
             WHERE user_id = %d 
               AND revoked = 0 
               AND last_active >= DATE_SUB(%s, INTERVAL %d DAY) 
             ORDER BY last_active DESC",
            $userId,
            $now,
            $days
        ), ARRAY_A);
    }
}