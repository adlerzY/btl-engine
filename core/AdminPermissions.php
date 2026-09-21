<?php
defined('ABSPATH') || exit;

final class BTL_Admin_Permissions
{
    /** @var array<int, array<int, string>> */
    private static array $requestCache = [];

    private const META_KEY = 'btl_admin_permissions';

    public const ALL = [
        'orders.read', 'orders.write', 'orders.fulfill',
        'tickets.read', 'tickets.write', 'tickets.claim',
        'reviews.moderate',
        'cdkeys.read', 'cdkeys.write', 'cdkeys.reveal',
    ];

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register_graphql'], 11);
    }

    public static function all(): array
    {
        return self::ALL;
    }

    public static function get(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        if (isset(self::$requestCache[$userId])) {
            return self::$requestCache[$userId];
        }

        $saved = get_user_meta($userId, self::META_KEY, true);
        if (is_array($saved)) {
            return self::$requestCache[$userId] = self::sanitize($saved);
        }

        // Compatibility mode for the existing WooCommerce staff model.
        // Explicit user-level permissions override this fallback once configured.
        if (user_can($userId, 'manage_woocommerce')) {
            return self::$requestCache[$userId] = self::ALL;
        }

        return self::$requestCache[$userId] = [];
    }

    public static function can(int $userId, string $permission): bool
    {
        return in_array($permission, self::get($userId), true);
    }

    public static function set(int $userId, array $permissions): bool
    {
        if ($userId < 1) {
            return false;
        }

        $clean = self::sanitize($permissions);
        $updated = update_user_meta($userId, self::META_KEY, $clean) !== false;
        if ($updated) {
            self::$requestCache[$userId] = $clean;
        }
        return $updated;
    }

    public static function register_graphql(): void
    {
        if (!btl_is_admin_graphql_request()) return;
        register_graphql_field('User', 'adminPermissions', [
            'type' => ['list_of' => 'String'],
            'resolve' => static function ($user): array {
                $currentUserId = get_current_user_id();
                $userId = (int)($user->databaseId ?? 0);
                if (!$currentUserId || $currentUserId !== $userId) {
                    return [];
                }
                return self::get($userId);
            },
        ]);

        register_graphql_field('User', 'adminCan', [
            'type' => 'Boolean',
            'args' => [
                'permission' => ['type' => ['non_null' => 'String']],
            ],
            'resolve' => static function ($user, array $args): bool {
                $currentUserId = get_current_user_id();
                $userId = (int)($user->databaseId ?? 0);
                if (!$currentUserId || $currentUserId !== $userId) {
                    return false;
                }
                return self::can($userId, sanitize_text_field((string)$args['permission']));
            },
        ]);
    }

    private static function sanitize(array $permissions): array
    {
        $allowed = array_fill_keys(self::ALL, true);
        $clean = [];

        foreach ($permissions as $permission) {
            $permission = sanitize_text_field((string)$permission);
            if ($permission !== '' && isset($allowed[$permission])) {
                $clean[$permission] = true;
            }
        }

        return array_keys($clean);
    }
}
