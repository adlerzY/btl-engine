<?php
defined('ABSPATH') || exit;

final class BTL_Gold_Market
{
    private const BUY_READY = 'btl_gold_buy_orders_ready_v1';
    private const PROPOSAL_READY = 'btl_gold_proposals_ready_v1';
    private const DEAL_READY = 'btl_gold_deals_ready_v1';
    private const PAYOUT_READY = 'btl_gold_payouts_ready_v1';
    private const MAX_AMOUNT = 1000000000000;
    private const CLAIM_MINUTES = 15;

    public static function buyTable(): string { global $wpdb; return $wpdb->prefix . 'btl_gold_buy_orders'; }
    public static function proposalTable(): string { global $wpdb; return $wpdb->prefix . 'btl_gold_proposals'; }
    public static function dealTable(): string { global $wpdb; return $wpdb->prefix . 'btl_gold_deals'; }
    public static function payoutTable(): string { global $wpdb; return $wpdb->prefix . 'btl_gold_payouts'; }

    public static function maybe_install(): void
    {
        BTL_Helpers::ensureTable(self::BUY_READY, [self::class, 'installBuyOrders']);
        BTL_Helpers::ensureTable(self::PROPOSAL_READY, [self::class, 'installProposals']);
        BTL_Helpers::ensureTable(self::DEAL_READY, [self::class, 'installDeals']);
        BTL_Helpers::ensureTable(self::PAYOUT_READY, [self::class, 'installPayouts']);
    }

    public static function installBuyOrders(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE " . self::buyTable() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            game_slug VARCHAR(100) NOT NULL,
            game_name VARCHAR(190) NOT NULL,
            region VARCHAR(50) NOT NULL,
            amount BIGINT UNSIGNED NOT NULL,
            offer_amount DECIMAL(20,0) NOT NULL,
            rate_per_1k DECIMAL(20,2) NOT NULL DEFAULT 0,
            timer_minutes INT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            closed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY status_created (status, created_at),
            KEY game_region (game_slug, region)
        ) {$charset} ENGINE=InnoDB;";
        dbDelta($sql);
    }

    public static function installProposals(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE " . self::proposalTable() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            buy_order_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            profile_id VARCHAR(100) NULL,
            profile_name VARCHAR(190) NOT NULL,
            amount BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            claimed_by BIGINT UNSIGNED NULL,
            claimed_at DATETIME NULL,
            claim_expires_at DATETIME NULL,
            suspended_reason TEXT NULL,
            completed_at DATETIME NULL,
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_status (buy_order_id, status, id),
            KEY user_status (user_id, status, id),
            KEY claim_expiry (status, claim_expires_at)
        ) {$charset} ENGINE=InnoDB;";
        dbDelta($sql);
    }

    public static function installDeals(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE " . self::dealTable() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            buy_order_id BIGINT UNSIGNED NOT NULL,
            proposal_id BIGINT UNSIGNED NOT NULL,
            seller_user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'timer',
            timer_expires_at DATETIME NULL,
            delivered_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
            delivery_confirmed_at DATETIME NULL,
            suspended_reason TEXT NULL,
            started_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY proposal_unique (proposal_id),
            KEY status_updated (status, updated_at),
            KEY seller_status (seller_user_id, status)
        ) {$charset} ENGINE=InnoDB;";
        dbDelta($sql);
    }

    public static function installPayouts(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE " . self::payoutTable() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            deal_id BIGINT UNSIGNED NOT NULL,
            proposal_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(20,0) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'paid',
            admin_user_id BIGINT UNSIGNED NOT NULL,
            note TEXT NULL,
            paid_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY deal_once (deal_id),
            KEY user_date (user_id, paid_at)
        ) {$charset} ENGINE=InnoDB;";
        dbDelta($sql);
    }

    public static function createBuyOrder(array $input): int
    {
        global $wpdb;
        $gameSlug = sanitize_key((string)($input['gameSlug'] ?? ''));
        $gameName = sanitize_text_field((string)($input['gameName'] ?? ''));
        $region = sanitize_text_field((string)($input['region'] ?? ''));
        $amount = self::normalizeInt($input['amount'] ?? 0);
        $offer = self::normalizeDecimal($input['offerAmount'] ?? 0);
        $rate = self::normalizeDecimal($input['ratePer1k'] ?? 0);
        $timerMinutes = isset($input['timerMinutes']) && $input['timerMinutes'] !== null && $input['timerMinutes'] !== '' ? min(max((int)$input['timerMinutes'], 0), 1440) : null;
        if ($gameSlug === '' || $gameName === '' || $region === '' || $amount < 1 || $offer < 0 || $rate < 0) throw new GraphQL\Error\UserError('اطلاعات خرید طلا نامعتبر است.');
        $ok = $wpdb->insert(self::buyTable(), [
            'game_slug' => $gameSlug,
            'game_name' => $gameName,
            'region' => $region,
            'amount' => $amount,
            'offer_amount' => $offer,
            'rate_per_1k' => $rate,
            'timer_minutes' => $timerMinutes,
            'status' => 'active',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['%s','%s','%s','%d','%f','%f','%d','%s','%d','%s','%s']);
        if (!$ok) throw new GraphQL\Error\UserError('ایجاد درخواست خرید طلا انجام نشد.');
        $id = (int)$wpdb->insert_id;
        BTL_Admin_Audit::record(get_current_user_id(), 'GOLD_ORDER_CREATE', 'gold_order', $id, 'success', ['amount' => $amount, 'offer_amount' => $offer]);
        return $id;
    }

    public static function activeBuyOrdersForUsers(int $limit = 20): array
    {
        global $wpdb;
        $limit = min(max($limit, 1), 50);
        return $wpdb->get_results($wpdb->prepare("SELECT id,game_slug,game_name,region,amount,offer_amount,rate_per_1k,timer_minutes,status,created_at FROM " . self::buyTable() . " WHERE status='active' ORDER BY id DESC LIMIT %d", $limit), ARRAY_A) ?: [];
    }

    public static function submitProposal(int $buyOrderId, string $profileName, int $amount, ?string $profileId = null): int
    {
        $userId = get_current_user_id();
        if ($userId < 1) throw new GraphQL\Error\UserError('برای ارسال پیشنهاد باید وارد حساب شوید.');
        if (!self::isVerifiedGamer($userId)) throw new GraphQL\Error\UserError('این حساب هنوز برای فروش Gold تأیید نشده است.');
        $order = self::getBuyOrder($buyOrderId);
        if (!$order || $order['status'] !== 'active') throw new GraphQL\Error\UserError('درخواست خرید فعال نیست.');
        $amount = min(max($amount, 1), self::MAX_AMOUNT);
        if ($amount > (int)$order['amount']) throw new GraphQL\Error\UserError('مقدار Gold از نیاز این درخواست بیشتر است.');
        if (trim($profileName) === '') throw new GraphQL\Error\UserError('پروفایل بازی الزامی است.');
        $profileOk = self::hasOwnGameProfile($userId, (string)$order['game_slug'], $profileId, $profileName);
        if (!$profileOk) throw new GraphQL\Error\UserError('پروفایل بازی انتخاب‌شده برای این Game متعلق به خودتان نیست.');
        global $wpdb;
        $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::proposalTable() . " WHERE buy_order_id=%d AND user_id=%d AND status IN ('pending','claimed','timer') LIMIT 1", $buyOrderId, $userId));
        if ($existing > 0) throw new GraphQL\Error\UserError('برای این درخواست یک پیشنهاد فعال از قبل دارید.');
        $ok = $wpdb->insert(self::proposalTable(), [
            'buy_order_id' => $buyOrderId,
            'user_id' => $userId,
            'profile_id' => $profileId !== null ? sanitize_text_field($profileId) : null,
            'profile_name' => sanitize_text_field($profileName),
            'amount' => $amount,
            'status' => 'pending',
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ], ['%d','%d','%s','%s','%d','%s','%s','%s']);
        if (!$ok) throw new GraphQL\Error\UserError('ثبت پیشنهاد انجام نشد.');
        $id = (int)$wpdb->insert_id;
        BTL_Admin_Notifications::pushToStaff('gold', 'پیشنهاد جدید Gold', sprintf('کاربر %s یک پیشنهاد %s Gold برای %s ثبت کرد.', wp_get_current_user()->display_name ?: 'کاربر', number_format_i18n($amount), $order['game_name']), '/admin/gold');
        return $id;
    }

    public static function claimProposal(int $proposalId): array
    {
        global $wpdb;
        $adminId = get_current_user_id();
        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + self::CLAIM_MINUTES * 60);
        $sql = "UPDATE " . self::proposalTable() . " SET status='claimed', claimed_by=%d, claimed_at=%s, claim_expires_at=%s, updated_at=%s WHERE id=%d AND (status='pending' OR (status='claimed' AND claim_expires_at IS NOT NULL AND claim_expires_at < %s))";
        $updated = $wpdb->query($wpdb->prepare($sql, $adminId, $now, $expires, $now, $proposalId, $now));
        if ((int)$updated !== 1) throw new GraphQL\Error\UserError('این پیشنهاد قبلاً توسط مدیر دیگری Claim شده یا دیگر قابل Claim نیست.');
        BTL_Admin_Audit::record($adminId, 'GOLD_CLAIM', 'gold_proposal', $proposalId, 'success', ['expires_at' => $expires]);
        return self::getProposal($proposalId) ?: [];
    }

    public static function startDeal(int $proposalId): array
    {
        global $wpdb;
        $adminId = get_current_user_id();
        if ($proposalId < 1 || $adminId < 1) throw new GraphQL\Error\UserError('اطلاعات شروع معامله نامعتبر است.');
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new GraphQL\Error\UserError('شروع معامله در حال حاضر ممکن نیست.');
        }
        try {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT p.*, b.timer_minutes AS buy_timer_minutes
                 FROM ' . self::proposalTable() . ' p
                 INNER JOIN ' . self::buyTable() . ' b ON b.id=p.buy_order_id
                 WHERE p.id=%d
                 LIMIT 1
                 FOR UPDATE',
                $proposalId
            ), ARRAY_A);
            if (!$row) throw new GraphQL\Error\UserError('پیشنهاد پیدا نشد.');
            if ((string)$row['status'] !== 'claimed') throw new GraphQL\Error\UserError('این پیشنهاد برای شروع معامله آماده نیست.');
            if ((int)$row['claimed_by'] !== $adminId) throw new GraphQL\Error\UserError('این پیشنهاد توسط مدیر دیگری Claim شده است.');
            if (!empty($row['claim_expires_at']) && strtotime((string)$row['claim_expires_at']) < time()) throw new GraphQL\Error\UserError('زمان Claim این پیشنهاد تمام شده است.');

            $now = current_time('mysql', true);
            $timerMinutes = max(0, (int)$row['buy_timer_minutes']);
            $expires = $timerMinutes > 0 ? gmdate('Y-m-d H:i:s', time() + $timerMinutes * 60) : null;

            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE " . self::proposalTable() . " SET status='timer', updated_at=%s WHERE id=%d AND status='claimed' AND claimed_by=%d",
                $now,
                $proposalId,
                $adminId
            ));
            if ((int)$updated !== 1) throw new GraphQL\Error\UserError('وضعیت پیشنهاد برای شروع معامله تغییر کرده است.');

            $inserted = $wpdb->insert(self::dealTable(), [
                'buy_order_id' => (int)$row['buy_order_id'],
                'proposal_id' => $proposalId,
                'seller_user_id' => (int)$row['user_id'],
                'status' => 'timer',
                'timer_expires_at' => $expires,
                'started_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%d','%d','%d','%s','%s','%d','%s','%s']);
            if ($inserted === false) throw new GraphQL\Error\UserError('ثبت معامله انجام نشد.');
            $dealId = (int)$wpdb->insert_id;
            if ($dealId < 1) throw new GraphQL\Error\UserError('شناسه معامله ایجاد نشد.');

            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                throw new GraphQL\Error\UserError('ثبت نهایی معامله انجام نشد.');
            }
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($e instanceof GraphQL\Error\UserError) throw $e;
            BTL_Helpers::logger('Gold startDeal failed: ' . $e->getMessage());
            throw new GraphQL\Error\UserError('شروع معامله انجام نشد.');
        }

        BTL_Admin_Audit::record($adminId, 'GOLD_DEAL_START', 'gold_deal', $dealId, 'success', ['proposal_id' => $proposalId]);
        return self::getDeal($dealId) ?: [];
    }

    public static function updateDealStatus(int $dealId, string $status, ?string $reason = null): array
    {
        global $wpdb;
        $allowed = ['timer','active','suspended','cancelled','completed'];
        if (!in_array($status, $allowed, true)) throw new GraphQL\Error\UserError('وضعیت معامله نامعتبر است.');
        $deal = self::getDeal($dealId);
        if (!$deal) throw new GraphQL\Error\UserError('معامله پیدا نشد.');
        if ($status === 'completed') throw new GraphQL\Error\UserError('برای تکمیل معامله از Confirm Received استفاده کنید.');
        $transitions = [
            'timer' => ['active', 'suspended', 'cancelled'],
            'active' => ['suspended', 'cancelled'],
            'suspended' => ['active', 'cancelled'],
        ];
        if (!in_array($status, $transitions[$deal['status']] ?? [], true)) {
            throw new GraphQL\Error\UserError('تغییر وضعیت فعلی معامله مجاز نیست.');
        }
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::dealTable() . " SET status=%s, suspended_reason=%s, updated_at=%s WHERE id=%d AND status=%s",
            $status,
            $reason !== null ? sanitize_textarea_field($reason) : null,
            current_time('mysql', true),
            $dealId,
            $deal['status']
        ));
        if ((int)$updated !== 1) throw new GraphQL\Error\UserError('تغییر وضعیت معامله انجام نشد یا وضعیت معامله عوض شده است.');
        BTL_Admin_Audit::record(get_current_user_id(), 'GOLD_STATUS_CHANGE', 'gold_deal', $dealId, 'success', ['to' => $status, 'reason' => $reason]);
        return self::getDeal($dealId) ?: [];
    }

    public static function confirmReceived(int $dealId, int $amount): array
    {
        global $wpdb;
        $deal = self::getDeal($dealId);
        if (!$deal) throw new GraphQL\Error\UserError('معامله پیدا نشد.');
        $proposal = self::getProposal((int)$deal['proposalId']);
        if (!$proposal) throw new GraphQL\Error\UserError('پیشنهاد مرتبط پیدا نشد.');
        $requestedAmount = (int)$proposal['amount'];
        $amount = $amount > 0 ? $amount : $requestedAmount;
        if ($amount !== $requestedAmount) {
            throw new GraphQL\Error\UserError('تحویل ناقص Gold قابل تأیید نیست؛ مقدار تحویل باید دقیقاً برابر مقدار پیشنهاد باشد.');
        }
        $now = current_time('mysql', true);
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new GraphQL\Error\UserError('شروع تأیید تحویل در حال حاضر ممکن نیست.');
        }
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::dealTable() . " SET status='completed', delivered_amount=%d, delivery_confirmed_at=%s, updated_at=%s WHERE id=%d AND status IN ('timer','active','suspended')",
            $amount,
            $now,
            $now,
            $dealId
        ));
        if ((int)$updated !== 1) {
            $wpdb->query('ROLLBACK');
            throw new GraphQL\Error\UserError('تأیید تحویل انجام نشد یا معامله قبلاً تکمیل شده است.');
        }
        $proposalUpdated = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::proposalTable() . " SET status='completed', completed_at=%s, updated_at=%s WHERE id=%d AND status IN ('claimed','timer')",
            $now,
            $now,
            (int)$deal['proposalId']
        ));
        if ((int)$proposalUpdated !== 1) {
            $wpdb->query('ROLLBACK');
            throw new GraphQL\Error\UserError('وضعیت پیشنهاد مرتبط برای تأیید تحویل معتبر نیست.');
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            throw new GraphQL\Error\UserError('ثبت نهایی تحویل انجام نشد.');
        }
        self::maybeCompleteBuyOrder((int)$deal['buyOrderId']);
        BTL_Notifications::push((int)$proposal['userId'], 'تحویل Gold تأیید شد', 'تحویل Gold شما تأیید شد و معامله وارد مرحله پرداخت شد.', '/my-account', 'gold');
        BTL_Admin_Audit::record(get_current_user_id(), 'GOLD_CONFIRM_RECEIVED', 'gold_deal', $dealId, 'success', ['delivered_amount' => $amount]);
        return self::getDeal($dealId) ?: [];
    }

    public static function recordPayout(int $dealId, string $amount, ?string $note = null): array
    {
        global $wpdb;
        $deal = self::getDeal($dealId);
        if (!$deal || $deal['status'] !== 'completed') throw new GraphQL\Error\UserError('معامله هنوز قابل پرداخت نیست.');
        $proposal = self::getProposal((int)$deal['proposalId']);
        if (!$proposal) throw new GraphQL\Error\UserError('پیشنهاد مرتبط پیدا نشد.');
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::payoutTable() . ' WHERE deal_id=%d LIMIT 1', $dealId));
        if ($existing) throw new GraphQL\Error\UserError('پرداخت این معامله قبلاً ثبت شده است.');
        $amountDecimal = self::normalizeDecimalString($amount);
        if ($amountDecimal === '0') throw new GraphQL\Error\UserError('مبلغ پرداخت نامعتبر است.');

        $offerAmount = self::normalizeDecimalString((string)($proposal['offerAmount'] ?? '0'));
        if ($offerAmount === '0' || self::compareDecimalStrings($amountDecimal, $offerAmount) > 0) {
            throw new GraphQL\Error\UserError('مبلغ پرداخت نمی‌تواند بیشتر از سقف مبلغ این خرید باشد.');
        }

        $buyAmount = (int)($proposal['buyAmount'] ?? 0);
        $proposalAmount = (int)($proposal['amount'] ?? 0);
        if ($buyAmount < 1 || $proposalAmount < 1 || $proposalAmount > $buyAmount) {
            throw new GraphQL\Error\UserError('مبنای محاسبه پرداخت این معامله نامعتبر است.');
        }
        $expectedPayout = self::divideDecimalByInt(
            self::multiplyDecimalByInt($offerAmount, $proposalAmount),
            $buyAmount
        );
        if (self::compareDecimalStrings($amountDecimal, $expectedPayout) > 0) {
            throw new GraphQL\Error\UserError('مبلغ پرداخت بیشتر از مبلغ مجاز این معامله است.');
        }

        $now = current_time('mysql', true);
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new GraphQL\Error\UserError('شروع ثبت پرداخت در حال حاضر ممکن نیست.');
        }
        $ok = $wpdb->insert(self::payoutTable(), [
            'deal_id' => $dealId,
            'proposal_id' => (int)$proposal['databaseId'],
            'user_id' => (int)$proposal['userId'],
            'amount' => $amountDecimal,
            'status' => 'paid',
            'admin_user_id' => get_current_user_id(),
            'note' => $note !== null ? sanitize_textarea_field($note) : null,
            'paid_at' => $now,
            'created_at' => $now,
        ], ['%d','%d','%d','%s','%s','%d','%s','%s','%s']);
        if (!$ok) {
            $wpdb->query('ROLLBACK');
            throw new GraphQL\Error\UserError('ثبت پرداخت انجام نشد.');
        }
        $proposalUpdated = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::proposalTable() . " SET status='paid', paid_at=%s, updated_at=%s WHERE id=%d AND status='completed'",
            $now,
            $now,
            (int)$proposal['databaseId']
        ));
        if ((int)$proposalUpdated !== 1) {
            $wpdb->query('ROLLBACK');
            throw new GraphQL\Error\UserError('وضعیت پیشنهاد برای ثبت پرداخت معتبر نیست.');
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            throw new GraphQL\Error\UserError('ثبت نهایی پرداخت انجام نشد.');
        }
        BTL_Notifications::push((int)$proposal['userId'], 'پرداخت Gold ثبت شد', 'پرداخت دستی معامله Gold شما ثبت شد.', '/my-account', 'gold');
        BTL_Admin_Audit::record(get_current_user_id(), 'GOLD_PAYOUT', 'gold_deal', $dealId, 'success', ['amount' => $amountDecimal]);
        return self::getPayout($dealId) ?: [];
    }

    public static function addStrike(int $userId, string $reason): int
    {
        $reason = trim(sanitize_textarea_field($reason));
        if ($userId < 1 || $reason === '') throw new GraphQL\Error\UserError('کاربر و دلیل Strike الزامی است.');
        $strikes = (int)get_user_meta($userId, 'btl_user_strikes', true) + 1;
        update_user_meta($userId, 'btl_user_strikes', $strikes);
        update_user_meta($userId, 'btl_last_gold_strike_reason', $reason);
        BTL_Notifications::push($userId, 'Strike ثبت شد', 'برای حساب شما یک Strike ثبت شد. دلیل: ' . $reason, '/my-account', 'gold');
        BTL_Admin_Audit::record(get_current_user_id(), 'STRIKE_ADDED', 'user', $userId, 'success', ['count' => $strikes, 'reason' => $reason]);
        return $strikes;
    }

    public static function listAdmin(array $args = []): array
    {
        global $wpdb;
        $limit = min(max((int)($args['first'] ?? 30), 1), 50);
        $status = sanitize_key((string)($args['status'] ?? 'all'));
        $gameSlug = sanitize_key((string)($args['gameSlug'] ?? ''));
        $where = ['1=1']; $params = [];
        if ($status !== '' && $status !== 'all') { $where[] = 'b.status=%s'; $params[] = $status; }
        if ($gameSlug !== '') { $where[] = 'b.game_slug=%s'; $params[] = $gameSlug; }
        $sql = "SELECT b.*, COALESCE(p.proposal_count,0) proposal_count, COALESCE(p.pending_count,0) pending_count FROM " . self::buyTable() . " b LEFT JOIN (SELECT buy_order_id, COUNT(*) proposal_count, SUM(status='pending') pending_count FROM " . self::proposalTable() . " GROUP BY buy_order_id) p ON p.buy_order_id=b.id WHERE " . implode(' AND ', $where) . " ORDER BY b.id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$limit])), ARRAY_A) ?: [];
        return array_map([self::class, 'buyPayload'], $rows);
    }

    public static function proposalQueue(int $buyOrderId = 0, string $status = 'all', int $limit = 30): array
    {
        global $wpdb;
        $where = ['1=1']; $params = [];
        if ($buyOrderId > 0) { $where[] = 'p.buy_order_id=%d'; $params[] = $buyOrderId; }
        if ($status !== '' && $status !== 'all') { $where[] = 'p.status=%s'; $params[] = $status; }
        $sql = "SELECT p.*, b.game_name,b.game_slug,b.region,b.amount buy_amount,b.offer_amount FROM " . self::proposalTable() . " p INNER JOIN " . self::buyTable() . " b ON b.id=p.buy_order_id WHERE " . implode(' AND ', $where) . " ORDER BY p.id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [min(max($limit,1),50)])), ARRAY_A) ?: [];
        return array_map([self::class, 'proposalPayload'], $rows);
    }

    public static function getBuyOrder(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::buyTable() . ' WHERE id=%d LIMIT 1', $id), ARRAY_A);
        return $row ? self::buyPayload($row) : null;
    }

    public static function getProposal(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT p.*, b.game_name,b.game_slug,b.region,b.amount buy_amount,b.offer_amount FROM ' . self::proposalTable() . ' p INNER JOIN ' . self::buyTable() . ' b ON b.id=p.buy_order_id WHERE p.id=%d LIMIT 1', $id), ARRAY_A);
        return $row ? self::proposalPayload($row) : null;
    }

    public static function listDeals(string $status = 'active', int $limit = 20): array
    {
        global $wpdb;
        $where = 'd.status=%s';
        $params = [$status];
        if ($status === 'all') { $where = '1=1'; $params = []; }
        $sql = "SELECT d.*, p.profile_name,p.amount proposal_amount,u.display_name seller_name,u.user_email seller_email,b.game_name,b.game_slug,b.region,b.offer_amount FROM " . self::dealTable() . " d INNER JOIN " . self::proposalTable() . " p ON p.id=d.proposal_id INNER JOIN " . self::buyTable() . " b ON b.id=d.buy_order_id LEFT JOIN " . $wpdb->users . " u ON u.ID=d.seller_user_id WHERE {$where} ORDER BY d.id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [min(max($limit,1),50)])), ARRAY_A) ?: [];
        return array_map(static function(array $row): array {
            return [
                'databaseId'=>(int)$row['id'],'buyOrderId'=>(int)$row['buy_order_id'],'proposalId'=>(int)$row['proposal_id'],'sellerUserId'=>(int)$row['seller_user_id'],'sellerName'=>(string)($row['seller_name']??''),'sellerEmail'=>(string)($row['seller_email']??''),'profileName'=>(string)$row['profile_name'],'proposalAmount'=>(int)$row['proposal_amount'],'status'=>(string)$row['status'],'timerExpiresAt'=>$row['timer_expires_at']??null,'deliveredAmount'=>(int)$row['delivered_amount'],'deliveryConfirmedAt'=>$row['delivery_confirmed_at']??null,'suspendedReason'=>$row['suspended_reason']??null,'gameName'=>(string)$row['game_name'],'gameSlug'=>(string)$row['game_slug'],'region'=>(string)$row['region'],'offerAmount'=>(string)$row['offer_amount'],
            ];
        }, $rows);
    }

    public static function getDeal(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT d.*, p.profile_name,p.amount proposal_amount,p.status proposal_status,u.display_name seller_name,u.user_email seller_email,b.game_name,b.game_slug,b.region,b.offer_amount FROM ' . self::dealTable() . ' d INNER JOIN ' . self::proposalTable() . ' p ON p.id=d.proposal_id INNER JOIN ' . self::buyTable() . ' b ON b.id=d.buy_order_id LEFT JOIN ' . $wpdb->users . ' u ON u.ID=d.seller_user_id WHERE d.id=%d LIMIT 1', $id), ARRAY_A);
        if (!$row) return null;
        return [
            'databaseId'=>(int)$row['id'],'buyOrderId'=>(int)$row['buy_order_id'],'proposalId'=>(int)$row['proposal_id'],'sellerUserId'=>(int)$row['seller_user_id'],'sellerName'=>(string)($row['seller_name']??''),'sellerEmail'=>(string)($row['seller_email']??''),'profileName'=>(string)$row['profile_name'],'proposalAmount'=>(int)$row['proposal_amount'],'status'=>(string)$row['status'],'timerExpiresAt'=>$row['timer_expires_at']??null,'deliveredAmount'=>(int)$row['delivered_amount'],'deliveryConfirmedAt'=>$row['delivery_confirmed_at']??null,'suspendedReason'=>$row['suspended_reason']??null,'gameName'=>(string)$row['game_name'],'gameSlug'=>(string)$row['game_slug'],'region'=>(string)$row['region'],'offerAmount'=>(string)$row['offer_amount'],
        ];
    }

    public static function getPayout(int $dealId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::payoutTable() . ' WHERE deal_id=%d LIMIT 1', $dealId), ARRAY_A);
        if (!$row) return null;
        return ['databaseId'=>(int)$row['id'],'dealId'=>(int)$row['deal_id'],'proposalId'=>(int)$row['proposal_id'],'userId'=>(int)$row['user_id'],'amount'=>(string)$row['amount'],'status'=>(string)$row['status'],'adminUserId'=>(int)$row['admin_user_id'],'note'=>$row['note']??null,'paidAt'=>$row['paid_at']??null];
    }

    private static function maybeCompleteBuyOrder(int $buyOrderId): void
    {
        global $wpdb;
        $order = self::getBuyOrder($buyOrderId);
        if (!$order) return;
        $delivered = (int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(d.delivered_amount),0) FROM ' . self::dealTable() . ' d WHERE d.buy_order_id=%d AND d.status=\'completed\'', $buyOrderId));
        if ($delivered >= (int)$order['amount']) {
            $now = current_time('mysql', true);
            $wpdb->update(self::buyTable(), ['status'=>'completed','completed_at'=>$now,'updated_at'=>$now], ['id'=>$buyOrderId], ['%s','%s','%s'], ['%d']);
        }
    }

    private static function isVerifiedGamer(int $userId): bool
    {
        if ($userId < 1) return false;
        $flag = get_user_meta($userId, 'btl_verified_gamer', true);
        if ($flag === '1' || $flag === 1 || $flag === true || $flag === 'yes') return true;
        $user = get_userdata($userId);
        return $user && in_array('verified_gamer', (array)$user->roles, true);
    }

    private static function hasOwnGameProfile(int $userId, string $gameSlug, ?string $profileId, string $profileName): bool
    {
        $profiles = get_user_meta($userId, 'btl_game_profiles', true);
        if (is_string($profiles)) $profiles = json_decode($profiles, true);
        if (!is_array($profiles)) return false;
        foreach ($profiles as $profile) {
            if (!is_array($profile)) continue;
            if ((string)($profile['gameSlug'] ?? $profile['game_slug'] ?? '') !== $gameSlug) continue;
            $sameId = $profileId !== null && $profileId !== '' && (string)($profile['id'] ?? '') === $profileId;
            $sameName = mb_strtolower((string)($profile['name'] ?? '')) === mb_strtolower($profileName);
            if ($sameId || $sameName) return true;
        }
        return false;
    }

    private static function buyPayload(array $row): array
    {
        return [
            'databaseId'=>(int)$row['id'],'gameSlug'=>$row['game_slug'],'gameName'=>$row['game_name'],'region'=>$row['region'],'amount'=>(int)$row['amount'],'offerAmount'=>(string)$row['offer_amount'],'ratePer1k'=>(string)$row['rate_per_1k'],'timerMinutes'=>$row['timer_minutes']!==null?(int)$row['timer_minutes']:null,'status'=>$row['status'],'createdBy'=>(int)$row['created_by'],'createdAt'=>$row['created_at']??null,'updatedAt'=>$row['updated_at']??null,'proposalCount'=>isset($row['proposal_count'])?(int)$row['proposal_count']:0,'pendingProposalCount'=>isset($row['pending_count'])?(int)$row['pending_count']:0,
        ];
    }

    private static function proposalPayload(array $row): array
    {
        return [
            'databaseId'=>(int)$row['id'],'buyOrderId'=>(int)$row['buy_order_id'],'userId'=>(int)$row['user_id'],'profileId'=>$row['profile_id']??null,'profileName'=>$row['profile_name'],'amount'=>(int)$row['amount'],'status'=>$row['status'],'claimedBy'=>$row['claimed_by']!==null?(int)$row['claimed_by']:null,'claimedAt'=>$row['claimed_at']??null,'claimExpiresAt'=>$row['claim_expires_at']??null,'suspendedReason'=>$row['suspended_reason']??null,'completedAt'=>$row['completed_at']??null,'paidAt'=>$row['paid_at']??null,'createdAt'=>$row['created_at']??null,'updatedAt'=>$row['updated_at']??null,'gameName'=>$row['game_name'],'gameSlug'=>$row['game_slug'],'region'=>$row['region'],'buyAmount'=>(int)$row['buy_amount'],'offerAmount'=>(string)$row['offer_amount'],
        ];
    }

    private static function normalizeInt($value): int
    {
        $value = preg_replace('/[^0-9]/', '', (string)$value);
        return min((int)$value, self::MAX_AMOUNT);
    }

    private static function normalizeDecimal($value): float
    {
        $normalized = str_replace([',', ' '], '', (string)$value);
        return max(0.0, (float)$normalized);
    }

    private static function normalizeDecimalString($value): string
    {
        $normalized = str_replace([',', ' '], '', trim((string)$value));
        if ($normalized === '') return '0';
        if (!preg_match('/^\d+(?:\.0+)?$/', $normalized)) {
            throw new GraphQL\Error\UserError('مبلغ باید یک عدد صحیح معتبر باشد.');
        }
        $integer = explode('.', $normalized, 2)[0];
        $integer = ltrim($integer, '0');
        return $integer === '' ? '0' : $integer;
    }

    private static function compareDecimalStrings(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if (strlen($left) !== strlen($right)) return strlen($left) <=> strlen($right);
        return strcmp($left, $right) <=> 0;
    }

    private static function multiplyDecimalByInt(string $value, int $multiplier): string
    {
        if ($multiplier < 0) throw new GraphQL\Error\UserError('مقدار محاسبه نامعتبر است.');
        if ($value === '0' || $multiplier === 0) return '0';
        $carry = 0;
        $out = '';
        for ($i = strlen($value) - 1; $i >= 0; $i--) {
            $digit = ord($value[$i]) - 48;
            $product = ($digit * $multiplier) + $carry;
            $out = (string)($product % 10) . $out;
            $carry = intdiv($product, 10);
        }
        while ($carry > 0) {
            $out = (string)($carry % 10) . $out;
            $carry = intdiv($carry, 10);
        }
        return ltrim($out, '0') ?: '0';
    }

    private static function divideDecimalByInt(string $value, int $divisor): string
    {
        if ($divisor < 1) throw new GraphQL\Error\UserError('مبنای محاسبه پرداخت نامعتبر است.');
        $remainder = 0;
        $quotient = '';
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $remainder = ($remainder * 10) + (ord($value[$i]) - 48);
            if ($quotient !== '' || $remainder >= $divisor) {
                $quotient .= (string)intdiv($remainder, $divisor);
                $remainder %= $divisor;
            }
        }
        return ltrim($quotient, '0') ?: '0';
    }
}
