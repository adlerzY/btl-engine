<?php
defined('ABSPATH') || exit;

final class BTL_Admin_Tickets
{
    private const READY_OPTION = 'btl_admin_ticket_tables_ready_v1';
    private const CLAIM_MINUTES = 15;
    private const STATUSES = ['open', 'claimed', 'waiting_user', 'waiting_staff', 'resolved', 'closed'];

    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 12);
    }

    private static function eventsTable(): string { global $wpdb; return $wpdb->prefix . 'btl_ticket_admin_events'; }
    private static function claimsTable(): string { global $wpdb; return $wpdb->prefix . 'btl_ticket_claims'; }

    public static function maybe_install(): void
    {
        BTL_Helpers::ensureTable(self::READY_OPTION, [self::class, 'install']);
    }

    public static function install(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $events = "CREATE TABLE " . self::eventsTable() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            content TEXT NULL,
            from_status VARCHAR(32) NULL,
            to_status VARCHAR(32) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ticket_id (ticket_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};";
        $claims = "CREATE TABLE " . self::claimsTable() . " (
            ticket_id BIGINT UNSIGNED NOT NULL,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            claimed_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (ticket_id),
            KEY admin_user_id (admin_user_id),
            KEY expires_at (expires_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($events);
        dbDelta($claims);
    }

    public static function register(): void
    {
        if (!btl_is_admin_graphql_request()) return;
        register_graphql_object_type('BtlAdminTicket', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'title' => ['type' => 'String'],
                'date' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'priority' => ['type' => 'String'],
                'customerId' => ['type' => 'Int'],
                'customerName' => ['type' => 'String'],
                'customerEmail' => ['type' => 'String'],
                'linkedOrderId' => ['type' => 'Int'],
                'assigneeId' => ['type' => 'Int'],
                'assigneeName' => ['type' => 'String'],
                'claimExpiresAt' => ['type' => 'String'],
            ],
        ]);
        register_graphql_object_type('BtlAdminTicketReply', [
            'fields' => [
                'id' => ['type' => 'Int'],
                'authorName' => ['type' => 'String'],
                'authorRole' => ['type' => 'String'],
                'content' => ['type' => 'String'],
                'createdAt' => ['type' => 'String'],
            ],
        ]);
        register_graphql_object_type('BtlAdminTicketEvent', [
            'fields' => [
                'id' => ['type' => 'Int'],
                'type' => ['type' => 'String'],
                'content' => ['type' => 'String'],
                'fromStatus' => ['type' => 'String'],
                'toStatus' => ['type' => 'String'],
                'adminName' => ['type' => 'String'],
                'createdAt' => ['type' => 'String'],
            ],
        ]);
        register_graphql_object_type('BtlAdminTicketDetail', [
            'fields' => [
                'ticket' => ['type' => 'BtlAdminTicket'],
                'replies' => ['type' => ['list_of' => 'BtlAdminTicketReply']],
                'events' => ['type' => ['list_of' => 'BtlAdminTicketEvent']],
                'internalNotes' => ['type' => ['list_of' => 'BtlAdminTicketEvent']],
            ],
        ]);
        register_graphql_object_type('BtlAdminTicketConnection', [
            'fields' => [
                'nodes' => ['type' => ['list_of' => 'BtlAdminTicket']],
                'pageInfo' => ['type' => 'BtlCursorPageInfo'],
            ],
        ]);

        register_graphql_field('RootQuery', 'adminTickets', [
            'type' => 'BtlAdminTicketConnection',
            'args' => [
                'first' => ['type' => 'Int'],
                'after' => ['type' => 'String'],
                'status' => ['type' => 'String'],
                'search' => ['type' => 'String'],
                'mineOnly' => ['type' => 'Boolean'],
            ],
            'resolve' => static function ($root, array $args): array {
                self::assertPermission('tickets.read');
                $first = min(max((int)($args['first'] ?? 20), 1), 50);
                $offset = BTL_Customer_Tickets::decodeCursor($args['after'] ?? null);
                $status = sanitize_key((string)($args['status'] ?? ''));
                $search = sanitize_text_field((string)($args['search'] ?? ''));
                $mineOnly = !empty($args['mineOnly']);

                $metaQuery = [];
                if ($status !== '' && $status !== 'all') {
                    $values = $status === 'open' ? ['open', 'claimed', 'waiting_staff', 'answered'] : ($status === 'waiting_user' ? ['waiting_user', 'answered'] : [$status]);
                    $metaQuery[] = ['key' => 'ticket_status', 'value' => $values, 'compare' => 'IN'];
                }
                if ($mineOnly) {
                    $metaQuery[] = ['key' => 'assigned_admin_id', 'value' => get_current_user_id(), 'compare' => '='];
                }

                $queryArgs = [
                    'post_type' => 'support_ticket',
                    'post_status' => 'publish',
                    'posts_per_page' => $first + 1,
                    'offset' => $offset,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ];
                if ($metaQuery) $queryArgs['meta_query'] = $metaQuery;
                if ($search !== '') $queryArgs['s'] = $search;

                $posts = (new WP_Query($queryArgs))->posts;
                $hasNext = count($posts) > $first;
                if ($hasNext) $posts = array_slice($posts, 0, $first);

                $claims = self::claimsForTickets(array_map(static fn(WP_Post $post): int => (int)$post->ID, $posts));
                return [
                    'nodes' => array_map(static fn(WP_Post $post): array => self::ticketPayload($post, $claims), $posts),
                    'pageInfo' => [
                        'hasNextPage' => $hasNext,
                        'endCursor' => BTL_Customer_Tickets::encodeCursor($offset + count($posts)),
                    ],
                ];
            },
        ]);

        register_graphql_object_type('BtlAdminStaffUser', [
            'fields' => [
                'databaseId' => ['type' => 'Int'],
                'name' => ['type' => 'String'],
                'email' => ['type' => 'String'],
            ],
        ]);

        register_graphql_field('RootQuery', 'adminStaffUsers', [
            'type' => ['list_of' => 'BtlAdminStaffUser'],
            'resolve' => static function (): array {
                self::assertPermission('tickets.write');
                $users = get_users(['fields' => 'all']);
                $mapped = array_map(static function (WP_User $user): ?array {
                    $id = (int)$user->ID;
                    if (!user_can($id, 'manage_woocommerce')) return null;
                    return [
                        'databaseId' => $id,
                        'name' => (string)$user->display_name,
                        'email' => (string)$user->user_email,
                    ];
                }, $users ?: []);
                return array_values(array_filter($mapped));
            },
        ]);

        register_graphql_field('RootQuery', 'adminTicket', [
            'type' => 'BtlAdminTicketDetail',
            'args' => ['id' => ['type' => ['non_null' => 'Int']]],
            'resolve' => static function ($root, array $args): ?array {
                self::assertPermission('tickets.read');
                $post = get_post((int)$args['id']);
                if (!$post || $post->post_type !== 'support_ticket') return null;
                $replies = BTL_Ticket_Replies::forTicket((int)$post->ID);
                $events = self::events((int)$post->ID, false);
                $notes = self::events((int)$post->ID, true);
                return [
                    'ticket' => self::ticketPayload($post),
                    'replies' => array_map([self::class, 'replyPayload'], $replies),
                    'events' => $events,
                    'internalNotes' => $notes,
                ];
            },
        ]);

        register_graphql_mutation('adminClaimTicket', [
            'inputFields' => ['ticketId' => ['type' => ['non_null' => 'Int']]],
            'outputFields' => ['success' => ['type' => 'Boolean'], 'expiresAt' => ['type' => 'String']],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('tickets.claim');
                $ticketId = (int)$input['ticketId'];
                $post = self::assertTicket($ticketId);
                $now = gmdate('Y-m-d H:i:s');
                global $wpdb;
                $wpdb->query($wpdb->prepare("DELETE FROM " . self::claimsTable() . " WHERE expires_at <= %s", $now));
                $expires = gmdate('Y-m-d H:i:s', time() + self::CLAIM_MINUTES * 60);
                $inserted = $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO " . self::claimsTable() . " (ticket_id, admin_user_id, claimed_at, expires_at) VALUES (%d,%d,%s,%s)",
                    $ticketId, get_current_user_id(), $now, $expires
                ));
                if ((int)$inserted !== 1) {
                    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::claimsTable() . " WHERE ticket_id=%d", $ticketId));
                    if (!$existing || (int)$existing->admin_user_id !== get_current_user_id()) {
                        throw new GraphQL\Error\UserError('این تیکت در حال حاضر توسط ادمین دیگری Claim شده است.');
                    }
                    $wpdb->update(self::claimsTable(), ['expires_at' => $expires], ['ticket_id' => $ticketId]);
                }

                update_post_meta($ticketId, 'assigned_admin_id', get_current_user_id());
                self::transition($ticketId, 'claimed', 'CLAIMED');
                BTL_Admin_Audit::record(get_current_user_id(), 'TICKET_CLAIM', 'ticket', $ticketId, 'success', ['expires_at' => $expires]);
                return ['success' => true, 'expiresAt' => $expires];
            },
        ]);

        register_graphql_mutation('adminReplyToTicket', [
            'inputFields' => [
                'ticketId' => ['type' => ['non_null' => 'Int']],
                'content' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => ['success' => ['type' => 'Boolean'], 'status' => ['type' => 'String']],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('tickets.write');
                $ticketId = (int)$input['ticketId'];
                $post = self::assertTicket($ticketId);
                $content = wp_kses_post(trim((string)$input['content']));
                if ($content === '') throw new GraphQL\Error\UserError('متن پاسخ خالی است.');
                BTL_Ticket_Replies::add($ticketId, get_current_user_id(), 'staff', $content);
                self::transition($ticketId, 'waiting_user', 'REPLY');
                $ownerId = (int)get_post_meta($ticketId, 'customer_id', true);
                if ($ownerId) BTL_Notifications::push($ownerId, 'پاسخ جدید در تیکت شما', 'تیکت «' . get_the_title($ticketId) . '» پاسخ داده شد.', '/my-account/tickets/' . $ticketId, 'support');
                BTL_Admin_Audit::record(get_current_user_id(), 'TICKET_REPLY', 'ticket', $ticketId);
                return ['success' => true, 'status' => 'waiting_user'];
            },
        ]);

        register_graphql_mutation('adminAddTicketNote', [
            'inputFields' => [
                'ticketId' => ['type' => ['non_null' => 'Int']],
                'content' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('tickets.write');
                $ticketId = (int)$input['ticketId'];
                self::assertTicket($ticketId);
                $content = wp_kses_post(trim((string)$input['content']));
                if ($content === '') throw new GraphQL\Error\UserError('یادداشت خالی است.');
                self::recordEvent($ticketId, 'note', $content, null, null);
                BTL_Admin_Audit::record(get_current_user_id(), 'TICKET_INTERNAL_NOTE', 'ticket', $ticketId);
                return ['success' => true];
            },
        ]);

        register_graphql_mutation('adminSetTicketStatus', [
            'inputFields' => [
                'ticketId' => ['type' => ['non_null' => 'Int']],
                'status' => ['type' => ['non_null' => 'String']],
            ],
            'outputFields' => ['success' => ['type' => 'Boolean'], 'status' => ['type' => 'String']],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('tickets.write');
                $status = sanitize_key((string)$input['status']);
                if (!in_array($status, self::STATUSES, true)) throw new GraphQL\Error\UserError('وضعیت تیکت نامعتبر است.');
                $ticketId = (int)$input['ticketId'];
                self::assertTicket($ticketId);
                self::transition($ticketId, $status, 'STATUS_CHANGE');
                BTL_Admin_Audit::record(get_current_user_id(), 'TICKET_STATUS_CHANGE', 'ticket', $ticketId, 'success', ['to' => $status]);
                return ['success' => true, 'status' => $status];
            },
        ]);

        register_graphql_mutation('adminReassignTicket', [
            'inputFields' => [
                'ticketId' => ['type' => ['non_null' => 'Int']],
                'adminUserId' => ['type' => ['non_null' => 'Int']],
            ],
            'outputFields' => ['success' => ['type' => 'Boolean']],
            'mutateAndGetPayload' => static function (array $input): array {
                self::assertPermission('tickets.write');
                $ticketId = (int)$input['ticketId'];
                $adminUserId = (int)$input['adminUserId'];
                self::assertTicket($ticketId);
                if ($adminUserId < 1 || !user_can($adminUserId, 'manage_woocommerce')) throw new GraphQL\Error\UserError('ادمین انتخاب‌شده معتبر نیست.');
                update_post_meta($ticketId, 'assigned_admin_id', $adminUserId);
                global $wpdb;
                $wpdb->delete(self::claimsTable(), ['ticket_id' => $ticketId], ['%d']);
                self::recordEvent($ticketId, 'reassign', null, null, null);
                BTL_Admin_Audit::record(get_current_user_id(), 'TICKET_REASSIGN', 'ticket', $ticketId, 'success', ['admin_user_id' => $adminUserId]);
                return ['success' => true];
            },
        ]);
    }

    private static function assertPermission(string $permission): void
    {
        if (!BTL_Admin_Permissions::can(get_current_user_id(), $permission)) throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
    }

    private static function assertTicket(int $ticketId): WP_Post
    {
        $post = get_post($ticketId);
        if (!$post || $post->post_type !== 'support_ticket') throw new GraphQL\Error\UserError('تیکت یافت نشد.');
        return $post;
    }

    public static function payloadForExternal(WP_Post $post): array
    {
        return self::ticketPayload($post);
    }

    private static function claimsForTickets(array $ticketIds): array
    {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ticketIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $params = array_merge($ids, [gmdate('Y-m-d H:i:s')]);
        $sql = $wpdb->prepare(
            "SELECT ticket_id, expires_at FROM " . self::claimsTable() . " WHERE ticket_id IN ({$placeholders}) AND expires_at > %s",
            $params
        );
        $rows = $wpdb->get_results($sql);
        $claims = [];
        foreach ($rows ?: [] as $row) {
            $claims[(int)$row->ticket_id] = (string)$row->expires_at;
        }
        return $claims;
    }

    private static function ticketPayload(WP_Post $post, ?array $claims = null): array
    {
        $ticketId = (int)$post->ID;
        $customerId = (int)get_post_meta($ticketId, 'customer_id', true);
        $assigneeId = (int)get_post_meta($ticketId, 'assigned_admin_id', true);
        $customer = $customerId ? get_userdata($customerId) : null;
        $assignee = $assigneeId ? get_userdata($assigneeId) : null;
        global $wpdb;
        $claimExpiresAt = $claims !== null ? ($claims[$ticketId] ?? null) : (($wpdb->get_var($wpdb->prepare("SELECT expires_at FROM " . self::claimsTable() . " WHERE ticket_id=%d AND expires_at > %s", $ticketId, gmdate('Y-m-d H:i:s')))));
        return [
            'databaseId' => $ticketId,
            'title' => get_the_title($ticketId),
            'date' => get_post_time(DATE_ATOM, true, $post),
            'status' => self::normalizedStatus($ticketId),
            'priority' => sanitize_key((string)get_post_meta($ticketId, 'ticket_priority', true)) ?: 'normal',
            'customerId' => $customerId ?: null,
            'customerName' => $customer ? $customer->display_name : null,
            'customerEmail' => $customer ? $customer->user_email : null,
            'linkedOrderId' => (int)get_post_meta($ticketId, 'linked_order_id', true) ?: null,
            'assigneeId' => $assigneeId ?: null,
            'assigneeName' => $assignee ? $assignee->display_name : null,
            'claimExpiresAt' => $claimExpiresAt ? gmdate(DATE_ATOM, strtotime($claimExpiresAt)) : null,
        ];
    }


    private static function normalizedStatus(int $ticketId): string
    {
        $status = sanitize_key((string)get_post_meta($ticketId, 'ticket_status', true)) ?: 'open';
        return $status === 'answered' ? 'waiting_user' : $status;
    }

    private static function replyPayload(array $reply): array
    {
        $user = get_userdata((int)$reply['author_id']);
        return [
            'id' => (int)$reply['id'],
            'authorName' => $user ? $user->display_name : 'کاربر',
            'authorRole' => (string)$reply['author_role'],
            'content' => (string)$reply['content'],
            'createdAt' => (string)$reply['created_at'],
        ];
    }

    private static function events(int $ticketId, bool $notesOnly): array
    {
        global $wpdb;
        $where = $notesOnly ? "event_type='note'" : "event_type<>'note'";
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::eventsTable() . " WHERE ticket_id=%d AND {$where} ORDER BY id DESC LIMIT 100", $ticketId), ARRAY_A) ?: [];
        return array_map(static function (array $row): array {
            $user = get_userdata((int)$row['admin_user_id']);
            return [
                'id' => (int)$row['id'],
                'type' => (string)$row['event_type'],
                'content' => $row['content'] !== null ? (string)$row['content'] : null,
                'fromStatus' => $row['from_status'] !== null ? (string)$row['from_status'] : null,
                'toStatus' => $row['to_status'] !== null ? (string)$row['to_status'] : null,
                'adminName' => $user ? $user->display_name : 'ادمین',
                'createdAt' => (string)$row['created_at'],
            ];
        }, $rows);
    }

    private static function transition(int $ticketId, string $status, string $eventType): void
    {
        $previous = sanitize_key((string)get_post_meta($ticketId, 'ticket_status', true)) ?: 'open';
        update_post_meta($ticketId, 'ticket_status', $status);
        self::recordEvent($ticketId, $eventType, null, $previous, $status);
        foreach ([10, 20, 50] as $limit) {
            BTL_Cache::delete('admin_open_tickets_' . $limit);
        }
        BTL_Cache::delete('admin_open_tickets_count');
        if (in_array($status, ['resolved', 'closed'], true)) {
            global $wpdb;
            $wpdb->delete(self::claimsTable(), ['ticket_id' => $ticketId], ['%d']);
        }
    }

    private static function recordEvent(int $ticketId, string $eventType, ?string $content, ?string $from, ?string $to): void
    {
        global $wpdb;
        $wpdb->insert(self::eventsTable(), [
            'ticket_id' => $ticketId,
            'admin_user_id' => get_current_user_id(),
            'event_type' => sanitize_key($eventType),
            'content' => $content,
            'from_status' => $from,
            'to_status' => $to,
            'created_at' => current_time('mysql', true),
        ]);
    }
}
