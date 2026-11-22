<?php
defined('ABSPATH') || exit;

final class BTL_Order_Retry
{
    private const MAX_PAYMENT_SESSIONS = 8;
    private const REOPEN_WINDOW = DAY_IN_SECONDS;
    private const MAX_OPEN_UNPAID = 3;
    private const CANCEL_GUARD_SECONDS = 900;
    private const META_RETRIES = '_btl_retry_count';
    private const META_AUTO_CANCELLED = '_btl_auto_cancelled_at';
    private const META_USER_CANCELLED = '_btl_user_cancelled_at';

    public static function register(): void
    {
        register_graphql_field('Order', 'paymentState', [
            'type' => 'String',
            'resolve' => static function ($source): ?string {
                $order = self::orderFor($source);
                return $order ? self::paymentState($order) : null;
            },
        ]);

        register_graphql_field('Order', 'fulfillmentSummary', [
            'type' => 'String',
            'resolve' => static function ($source): ?string {
                $order = self::orderFor($source);
                return $order ? self::fulfillmentSummary($order) : null;
            },
        ]);

        register_graphql_field('Order', 'retryMode', [
            'type' => 'String',
            'resolve' => static function ($source): string {
                $order = self::orderFor($source);
                return $order ? self::retryMode($order) : 'none';
            },
        ]);

        register_graphql_field('Order', 'payDeadline', [
            'type' => 'String',
            'resolve' => static function ($source): ?string {
                $order = self::orderFor($source);
                return $order ? self::payDeadline($order) : null;
            },
        ]);

        register_graphql_field('LineItem', 'btlProductId', [
            'type' => 'Int',
            'resolve' => static function ($source): int {
                $item = btl_get_order_item_cached((int) ($source->databaseId ?? 0));
                return $item instanceof WC_Order_Item_Product ? (int) $item->get_product_id() : 0;
            },
        ]);

        register_graphql_field('LineItem', 'btlVariationId', [
            'type' => 'Int',
            'resolve' => static function ($source): int {
                $item = btl_get_order_item_cached((int) ($source->databaseId ?? 0));
                return $item instanceof WC_Order_Item_Product ? (int) $item->get_variation_id() : 0;
            },
        ]);

        register_graphql_field('LineItem', 'btlUnitPrice', [
            'type' => 'Float',
            'resolve' => static function ($source): float {
                $item = btl_get_order_item_cached((int) ($source->databaseId ?? 0));
                if (!$item instanceof WC_Order_Item_Product) return 0.0;
                $quantity = max(1, (int) $item->get_quantity());
                return round((float) $item->get_total() / $quantity, 2);
            },
        ]);

        register_graphql_mutation('retryOrderPayment', [
            'inputFields' => ['orderId' => ['type' => ['non_null' => 'Int']]],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'mode' => ['type' => 'String'],
                'paymentUrl' => ['type' => 'String'],
                'status' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => static fn($input) => self::retry((int) $input['orderId']),
        ]);

        register_graphql_mutation('cancelMyOrder', [
            'inputFields' => ['orderId' => ['type' => ['non_null' => 'Int']]],
            'outputFields' => [
                'success' => ['type' => 'Boolean'],
                'status' => ['type' => 'String'],
            ],
            'mutateAndGetPayload' => static fn($input) => self::cancel((int) $input['orderId']),
        ]);
    }

    private static function orderFor($source): ?WC_Order
    {
        $id = (int) ($source->databaseId ?? 0);
        if ($id < 1) return null;
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order) return null;
        $viewer = get_current_user_id();
        if (!$viewer || ((int) $order->get_customer_id() !== $viewer && !current_user_can('manage_woocommerce'))) return null;
        return $order;
    }

    private static function verified(WC_Order $order): bool
    {
        return trim((string) $order->get_meta('_btl_pay_verified_trace')) !== '';
    }

    private static function paymentState(WC_Order $order): string
    {
        if ($order->is_paid()) return 'paid';
        if (self::verified($order)) {
            return trim((string) $order->get_meta('_btl_pay_state')) === 'review' ? 'review' : 'verified';
        }
        return 'unpaid';
    }

    private static function fulfillmentSummary(WC_Order $order): string
    {
        $counts = BTL_Secure_Fields::countsByOrder((int) $order->get_id(), 'cdkey');
        $hasItems = false;
        $allDone = true;
        $anyProgress = false;

        foreach ($order->get_items('line_item') as $itemId => $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;
            $hasItems = true;
            $quantity = max(1, (int) $item->get_quantity());
            $delivery = sanitize_key((string) $item->get_meta('روش تحویل'));

            if ($delivery === 'code') {
                $delivered = min((int) ($counts[(int) $itemId] ?? 0), $quantity);
                $done = $delivered >= $quantity;
                $progress = $delivered > 0;
            } else {
                $status = sanitize_key((string) $item->get_meta('_fulfillment_status')) ?: 'queued';
                $done = $status === 'completed';
                $progress = $status !== 'queued';
            }

            if (!$done) $allDone = false;
            if ($progress) $anyProgress = true;
        }

        if (!$hasItems || $allDone) return 'completed';
        return $anyProgress ? 'processing' : 'queued';
    }

    private static function onlyCodeItems(WC_Order $order): bool
    {
        foreach ($order->get_items('line_item') as $item) {
            if (sanitize_key((string) $item->get_meta('روش تحویل')) !== 'code') return false;
        }
        return true;
    }

    private static function cancelTimestamp(WC_Order $order): int
    {
        foreach ([self::META_USER_CANCELLED, self::META_AUTO_CANCELLED] as $key) {
            $raw = (string) $order->get_meta($key);
            if ($raw !== '') {
                $timestamp = strtotime($raw);
                if ($timestamp) return (int) $timestamp;
            }
        }
        $modified = $order->get_date_modified();
        return $modified ? (int) $modified->getTimestamp() : 0;
    }

    private static function retryMode(WC_Order $order): string
    {
        if ($order->is_paid() || self::verified($order) || $order->get_created_via() !== 'btl_graphql') return 'none';

        $status = $order->get_status();
        $tries = (int) $order->get_meta(self::META_RETRIES);

        if (in_array($status, ['pending', 'checkout-draft'], true)) {
            return $tries >= self::MAX_PAYMENT_SESSIONS ? 'none' : 'pay';
        }

        if (!in_array($status, ['cancelled', 'failed'], true)) return 'none';
        if (!$order->get_items('line_item')) return 'none';

        $flagged = $status === 'failed'
            || (string) $order->get_meta(self::META_AUTO_CANCELLED) !== ''
            || (string) $order->get_meta(self::META_USER_CANCELLED) !== '';
        $timestamp = self::cancelTimestamp($order);

        if ($flagged && $timestamp > 0 && (time() - $timestamp) <= self::REOPEN_WINDOW && $tries < self::MAX_PAYMENT_SESSIONS && self::onlyCodeItems($order)) {
            return 'reopen';
        }

        return 'reorder';
    }

    private static function payDeadline(WC_Order $order): ?string
    {
        $mode = self::retryMode($order);

        if ($mode === 'pay') {
            $created = $order->get_date_created();
            return $created ? gmdate('c', (int) $created->getTimestamp() + BTL_Customer_Orders::UNPAID_ORDER_TTL) : null;
        }

        if ($mode === 'reopen') {
            $timestamp = self::cancelTimestamp($order);
            return $timestamp > 0 ? gmdate('c', $timestamp + self::REOPEN_WINDOW) : null;
        }

        return null;
    }

    private static function lock(int $orderId): ?string
    {
        global $wpdb;
        $name = 'btl_order_op_' . $orderId;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name)) === 1 ? $name : null;
    }

    private static function unlock(?string $name): void
    {
        if (!$name) return;
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    private static function ownedOrder(int $orderId, int $userId): WC_Order
    {
        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        if (!$order instanceof WC_Order || (int) $order->get_customer_id() !== $userId || $order->get_created_via() !== 'btl_graphql') {
            throw new GraphQL\Error\UserError('سفارش یافت نشد.');
        }
        return $order;
    }

    private static function assertOpenSlot(int $userId): void
    {
        $open = wc_get_orders([
            'customer_id' => $userId,
            'status' => ['pending', 'checkout-draft'],
            'created_via' => 'btl_graphql',
            'limit' => self::MAX_OPEN_UNPAID,
            'return' => 'ids',
        ]);
        if (count($open) >= self::MAX_OPEN_UNPAID) {
            throw new GraphQL\Error\UserError('شما چند سفارش پرداخت‌نشده دارید. ابتدا آن‌ها را پرداخت یا لغو کنید.');
        }
    }

    private static function assertLinesStillValid(WC_Order $order): void
    {
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;

            $product = $item->get_product();
            if (!$product || !$product->exists()) {
                throw new GraphQL\Error\UserError('یکی از اقلام این سفارش دیگر در دسترس نیست. سفارش را دوباره ثبت کنید.');
            }

            $variationId = (int) $item->get_variation_id();
            $parentId = $variationId ? (int) $product->get_parent_id() : (int) $item->get_product_id();

            if (get_post_status($parentId) !== 'publish'
                || ($variationId && get_post_status($variationId) !== 'publish')
                || !$product->is_purchasable()) {
                throw new GraphQL\Error\UserError('یکی از اقلام این سفارش دیگر در دسترس نیست. سفارش را دوباره ثبت کنید.');
            }

            $delivery = sanitize_key((string) $item->get_meta('روش تحویل'));
            $current = BTL_Price_Engine::resolveDeliveryPrice($product, $delivery);
            $quantity = max(1, (int) $item->get_quantity());
            $paid = (float) $item->get_total() / $quantity;

            if ($current === null || abs($current - $paid) > 0.5) {
                throw new GraphQL\Error\UserError('مبلغ یکی از اقلام تغییر کرده است. سفارش را دوباره ثبت کنید.');
            }
        }
    }

    private static function reserveCodeKeys(WC_Order $order, bool $releaseOnFailure = false): void
    {
        $orderId = (int) $order->get_id();

        foreach ($order->get_items('line_item') as $itemId => $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;
            if (sanitize_key((string) $item->get_meta('روش تحویل')) !== 'code') continue;

            $product = $item->get_product();
            $variationId = (int) $item->get_variation_id();
            $productId = $variationId ? (int) ($product ? $product->get_parent_id() : 0) : (int) $item->get_product_id();
            $quantity = max(1, (int) $item->get_quantity());

            if ($productId < 1 || !BTL_CdKey_Stock::ensureReservationsForItem($orderId, (int) $itemId, $productId, $variationId, $quantity)) {
                if ($releaseOnFailure) BTL_CdKey_Stock::releaseReservedForOrder($orderId);
                throw new GraphQL\Error\UserError('کد کافی برای این سفارش در انبار نیست. کمی بعد دوباره تلاش کنید یا سفارش جدید ثبت کنید.');
            }
        }
    }

    private static function openGateway(WC_Order $order): string
    {
        $before = (string) $order->get_meta('_btl_pay_created_at');
        $stored = trim((string) $order->get_meta('_btl_pay_provider'));
        $attempts = [null];
        foreach (array_keys(BTL_Payment_Gateway::enabled()) as $id) {
            if ($id !== $stored) $attempts[] = $id;
        }

        $url = '';
        foreach ($attempts as $providerId) {
            try {
                $url = BTL_Payment_Gateway::ensurePayment($order, $providerId);
                if ($url !== '') break;
            } catch (BTL_Payment_Exception $e) {
                continue;
            }
        }

        if ($url === '') {
            throw new GraphQL\Error\UserError('درگاه پرداخت موقتاً در دسترس نیست. چند دقیقه بعد دوباره تلاش کنید.');
        }

        if ((string) $order->get_meta('_btl_pay_created_at') !== $before) {
            $order->update_meta_data(self::META_RETRIES, (string) ((int) $order->get_meta(self::META_RETRIES) + 1));
            $order->save_meta_data();
        }

        return $url;
    }

    private static function retry(int $orderId): array
    {
        $userId = get_current_user_id();
        if (!$userId) throw new GraphQL\Error\UserError('برای ادامه باید وارد حساب کاربری شوید.');

        $lock = self::lock($orderId);
        if (!$lock) throw new GraphQL\Error\UserError('این سفارش در حال پردازش است. چند لحظه بعد دوباره تلاش کنید.');

        try {
            $order = self::ownedOrder($orderId, $userId);

            if ($order->is_paid() || self::verified($order)) {
                try {
                    BTL_Payment_Gateway::ensurePayment($order);
                } catch (Throwable $e) {
                }
                return ['success' => true, 'mode' => 'verified', 'paymentUrl' => '', 'status' => strtoupper($order->get_status())];
            }

            $mode = self::retryMode($order);

            if ($mode === 'pay') {
                self::reserveCodeKeys($order);
            } elseif ($mode === 'reopen') {
                self::assertOpenSlot($userId);
                self::assertLinesStillValid($order);
                self::reserveCodeKeys($order, true);
                $order->delete_meta_data(self::META_AUTO_CANCELLED);
                $order->delete_meta_data(self::META_USER_CANCELLED);
                $order->save();
                $order->update_status('pending', 'فعال‌سازی مجدد سفارش برای پرداخت توسط مشتری.');
            } else {
                throw new GraphQL\Error\UserError('این سفارش دیگر قابل پرداخت مجدد نیست. می‌توانید اقلام را دوباره به سبد اضافه کنید.');
            }

            $url = self::openGateway($order);

            return ['success' => true, 'mode' => $mode, 'paymentUrl' => $url, 'status' => strtoupper($order->get_status())];
        } finally {
            self::unlock($lock);
        }
    }

    private static function cancel(int $orderId): array
    {
        $userId = get_current_user_id();
        if (!$userId) throw new GraphQL\Error\UserError('برای ادامه باید وارد حساب کاربری شوید.');

        $lock = self::lock($orderId);
        if (!$lock) throw new GraphQL\Error\UserError('این سفارش در حال پردازش است. چند لحظه بعد دوباره تلاش کنید.');

        try {
            $order = self::ownedOrder($orderId, $userId);

            if ($order->is_paid() || self::verified($order)) {
                throw new GraphQL\Error\UserError('این سفارش پرداخت شده و قابل لغو نیست.');
            }
            if (!in_array($order->get_status(), ['pending', 'checkout-draft'], true)) {
                throw new GraphQL\Error\UserError('فقط سفارش‌های پرداخت‌نشده قابل لغو هستند.');
            }

            $createdAt = (int) $order->get_meta('_btl_pay_created_at');
            if ($createdAt > 0 && (time() - $createdAt) < self::CANCEL_GUARD_SECONDS) {
                throw new GraphQL\Error\UserError('به‌تازگی به درگاه پرداخت منتقل شده‌اید. اگر پرداخت را انجام نداده‌اید چند دقیقه بعد دوباره تلاش کنید.');
            }

            BTL_CdKey_Stock::releaseReservedForOrder($orderId);
            $order->update_meta_data(self::META_USER_CANCELLED, gmdate('c'));
            $order->save();
            $order->update_status('cancelled', 'لغو سفارش توسط مشتری.');

            return ['success' => true, 'status' => strtoupper($order->get_status())];
        } finally {
            self::unlock($lock);
        }
    }
}