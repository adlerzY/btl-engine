<?php
defined('ABSPATH') || exit;

final class BTL_Payment_Exception extends RuntimeException {}

final class BTL_Payment_Gateway
{
    private const API_BASE = 'https://api.aqayepardakht.ir/v3/';
    private const START_BASE = 'https://api.aqayepardakht.ir/startpay/';
    private const GATEWAY = 'aqayepardakht';
    private const PAYMENT_METHOD_ID = 'btl_aqayepardakht';
    private const PAYMENT_METHOD_TITLE = 'آقای پرداخت';
    private const META_TRACE = '_btl_aqayepardakht_trace_code';
    private const META_URL = '_btl_aqayepardakht_payment_url';
    private const META_AMOUNT = '_btl_aqayepardakht_amount_toman';
    private const META_INVOICE_ID = '_btl_aqayepardakht_invoice_id';
    private const META_VERIFIED_AT = '_btl_aqayepardakht_verified_at';
    private const META_TRACKING_NUMBER = '_btl_aqayepardakht_tracking_number';
    private const VERIFY_LOCK_PREFIX = 'btl_aqayepardakht_verify_';
    private const VERIFY_LOCK_TTL = 60;
    private const MIN_AMOUNT_TOMAN = 1001;
    private const MAX_AMOUNT_TOMAN = 99999999;
    private const TIMEOUT = 15;

    public static function register_rest_routes(): void
    {
        register_rest_route('btl/v1', '/aqayepardakht/callback', [
            'methods' => ['GET', 'POST'],
            'callback' => [self::class, 'handle_callback'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function ensurePayment(WC_Order $order): string
    {
        if ($order->is_paid()) {
            return '';
        }

        $storedUrl = self::storedPaymentUrl($order);
        $storedTrace = trim((string)$order->get_meta(self::META_TRACE));
        $storedAmount = (int)$order->get_meta(self::META_AMOUNT);
        $currentAmount = self::amountToman($order);

        if ($storedUrl !== '' && $storedTrace !== '' && $storedAmount === $currentAmount) {
            return $storedUrl;
        }

        $pin = self::pin();
        if ($pin === '') {
            throw new BTL_Payment_Exception('aqayepardakht_pin_missing');
        }

        $callback = rest_url('btl/v1/aqayepardakht/callback');
        $invoiceId = (string)$order->get_id();
        $description = 'Arena2Battle - Order ' . $order->get_order_number();

        $body = [
            'pin' => $pin,
            'amount' => $currentAmount,
            'callback' => $callback,
            'invoice_id' => $invoiceId,
            'description' => $description,
        ];

        $mobile = self::sanitizePhone((string)$order->get_billing_phone());
        if ($mobile !== '') {
            $body['mobile'] = $mobile;
        }

        $email = sanitize_email((string)$order->get_billing_email());
        if ($email !== '') {
            $body['email'] = $email;
        }

        $response = wp_remote_post(self::API_BASE . 'pay', [
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            self::logCreateFailure($order, $response->get_error_message());
            throw new BTL_Payment_Exception('aqayepardakht_create_http_error');
        }

        $statusCode = (int)wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string)wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            self::logCreateFailure($order, 'invalid_json_' . $statusCode);
            throw new BTL_Payment_Exception('aqayepardakht_create_invalid_response');
        }

        $status = strtolower(trim((string)($decoded['status'] ?? '')));
        $trace = trim((string)($decoded['tracking_code'] ?? ''));

        if ($status === 'error' || $trace === '') {
            $providerMessage = trim((string)($decoded['message'] ?? $decoded['error'] ?? ''));
            self::logCreateFailure($order, $providerMessage !== '' ? substr($providerMessage, 0, 180) : 'provider_error_' . $statusCode);
            throw new BTL_Payment_Exception('aqayepardakht_create_provider_error');
        }

        $paymentUrl = self::START_BASE . rawurlencode($trace);

        $order->set_payment_method(self::PAYMENT_METHOD_ID);
        $order->set_payment_method_title(self::PAYMENT_METHOD_TITLE);
        $order->set_transaction_id($trace);
        $order->update_meta_data(self::META_TRACE, $trace);
        $order->update_meta_data(self::META_URL, $paymentUrl);
        $order->update_meta_data(self::META_AMOUNT, (string)$currentAmount);
        $order->update_meta_data(self::META_INVOICE_ID, $invoiceId);
        $order->save();

        return $paymentUrl;
    }

    public static function storedPaymentUrl(WC_Order $order): string
    {
        return trim((string)$order->get_meta(self::META_URL));
    }

    public static function handle_callback(WP_REST_Request $request)
    {
        nocache_headers();

        $trace = self::readParam($request, 'transid');
        if ($trace === '') {
            $trace = self::readParam($request, 'tracking_number');
        }
        if ($trace === '') {
            $trace = self::readParam($request, 'tracking_code');
        }

        $invoiceId = self::readParam($request, 'invoice_id');

        $order = self::findOrder($trace, $invoiceId);
        if (!$order) {
            return self::redirectResult('cancelled', 0);
        }

        if ($order->is_paid()) {
            return self::redirectResult('success', (int)$order->get_id());
        }

        $storedTrace = trim((string)$order->get_meta(self::META_TRACE));
        if ($trace === '' || $storedTrace === '' || !hash_equals($storedTrace, $trace)) {
            self::logCallbackFailure($order, 'trace_mismatch');
            return self::redirectResult('cancelled', (int)$order->get_id());
        }

        if (!self::acquireVerifyLock((int)$order->get_id())) {
            return self::redirectResult('processing', (int)$order->get_id());
        }

        try {
            $amount = (int)$order->get_meta(self::META_AMOUNT);
            try {
                $currentAmount = self::amountToman($order);
            } catch (BTL_Payment_Exception $e) {
                self::logCallbackFailure($order, 'amount_out_of_range');
                return self::redirectResult('failed', (int)$order->get_id());
            }
            if ($amount < 1 || $amount !== $currentAmount) {
                self::logCallbackFailure($order, 'amount_mismatch');
                return self::redirectResult('failed', (int)$order->get_id());
            }

            $pin = self::pin();
            if ($pin === '') {
                self::logCallbackFailure($order, 'pin_missing');
                return self::redirectResult('failed', (int)$order->get_id());
            }

            $response = wp_remote_post(self::API_BASE . 'verify', [
                'timeout' => self::TIMEOUT,
                'redirection' => 0,
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'body' => [
                    'pin' => $pin,
                    'tracking_code' => $trace,
                    'amount' => $amount,
                ],
            ]);

            if (is_wp_error($response)) {
                self::logCallbackFailure($order, 'verify_http_error');
                return self::redirectResult('failed', (int)$order->get_id());
            }

            $statusCode = (int)wp_remote_retrieve_response_code($response);
            $decoded = json_decode((string)wp_remote_retrieve_body($response), true);
            if (!is_array($decoded)) {
                self::logCallbackFailure($order, 'verify_invalid_json_' . $statusCode);
                return self::redirectResult('failed', (int)$order->get_id());
            }

            $status = strtolower(trim((string)($decoded['status'] ?? '')));
            if ($status === 'error') {
                $providerMessage = trim((string)($decoded['message'] ?? $decoded['error'] ?? ''));
                self::logCallbackFailure($order, $providerMessage !== '' ? substr($providerMessage, 0, 180) : 'verify_provider_error_' . $statusCode);
                return self::redirectResult('failed', (int)$order->get_id());
            }

            $trackingNumber = trim((string)($decoded['tracking_number'] ?? $decoded['trackingNumber'] ?? $decoded['receipt'] ?? ''));
            if ($trackingNumber !== '') {
                $order->update_meta_data(self::META_TRACKING_NUMBER, $trackingNumber);
            }
            $order->update_meta_data(self::META_VERIFIED_AT, current_time('mysql', true));
            if (!$order->get_transaction_id()) {
                $order->set_transaction_id($trace);
            }
            $order->save();
            $order->payment_complete($trace);
            $order->add_order_note('پرداخت آقای پرداخت با موفقیت تأیید شد.');
            $order->save();

            return self::redirectResult('success', (int)$order->get_id());
        } finally {
            self::releaseVerifyLock((int)$order->get_id());
        }
    }

    private static function findOrder(string $trace, string $invoiceId): ?WC_Order
    {
        if ($invoiceId !== '' && ctype_digit($invoiceId)) {
            $candidate = wc_get_order((int)$invoiceId);
            if ($candidate instanceof WC_Order) {
                $candidateTrace = trim((string)$candidate->get_meta(self::META_TRACE));
                if ($trace !== '' && $candidateTrace !== '' && hash_equals($candidateTrace, $trace)) {
                    return $candidate;
                }
            }
        }

        if ($trace === '') {
            return null;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'return' => 'objects',
            'meta_query' => [[
                'key' => self::META_TRACE,
                'value' => $trace,
                'compare' => '=',
            ]],
        ]);

        $order = $orders[0] ?? null;
        return $order instanceof WC_Order ? $order : null;
    }

    private static function readParam(WP_REST_Request $request, string $key): string
    {
        $value = $request->get_param($key);
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = trim((string)$value);
        return strlen($value) <= 255 ? $value : substr($value, 0, 255);
    }

    private static function amountToman(WC_Order $order): int
    {
        $amount = (int)round((float)$order->get_total());
        if ($amount < self::MIN_AMOUNT_TOMAN || $amount > self::MAX_AMOUNT_TOMAN) {
            throw new BTL_Payment_Exception('aqayepardakht_amount_out_of_range');
        }
        return $amount;
    }

    private static function pin(): string
    {
        $pin = defined('BTL_AQAYEPARDAKHT_PIN') ? (string)BTL_AQAYEPARDAKHT_PIN : '';
        if ($pin === '') {
            $env = getenv('BTL_AQAYEPARDAKHT_PIN');
            $pin = is_string($env) ? $env : '';
        }
        return trim($pin);
    }

    private static function sanitizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        $digits = preg_replace('/[^0-9+]/', '', $phone);
        return is_string($digits) && strlen($digits) <= 32 ? $digits : '';
    }

    private static function acquireVerifyLock(int $orderId): bool
    {
        $key = self::VERIFY_LOCK_PREFIX . $orderId;
        $now = time();
        $existing = get_option($key, null);

        if ($existing !== null && (int)$existing > 0 && ($now - (int)$existing) < self::VERIFY_LOCK_TTL) {
            return false;
        }

        if ($existing !== null) {
            delete_option($key);
        }

        return add_option($key, (string)$now, '', 'no');
    }

    private static function releaseVerifyLock(int $orderId): void
    {
        delete_option(self::VERIFY_LOCK_PREFIX . $orderId);
    }

    private static function publicSiteUrl(): string
    {
        $url = defined('BTL_PUBLIC_SITE_URL') ? (string)BTL_PUBLIC_SITE_URL : '';
        if ($url === '') {
            $env = getenv('BTL_PUBLIC_SITE_URL');
            $url = is_string($env) ? $env : '';
        }
        $url = trim($url);
        if ($url === '') {
            $url = home_url('/');
        }
        return rtrim($url, '/');
    }

    private static function redirectResult(string $result, int $orderId): WP_REST_Response
    {
        $url = self::publicSiteUrl() . '/my-account/orders';
        $args = ['payment' => $result];
        if ($orderId > 0) {
            $args['order'] = $orderId;
        }
        $url = add_query_arg($args, $url);

        return new WP_REST_Response(null, 303, [
            'Location' => $url,
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private static function logCreateFailure(WC_Order $order, string $reason): void
    {
        BTL_Helpers::logger(sprintf('AqayePardakht create failed for order %d: %s', (int)$order->get_id(), sanitize_text_field($reason)));
    }

    private static function logCallbackFailure(WC_Order $order, string $reason): void
    {
        BTL_Helpers::logger(sprintf('AqayePardakht callback failed for order %d: %s', (int)$order->get_id(), sanitize_text_field($reason)));
    }
}
