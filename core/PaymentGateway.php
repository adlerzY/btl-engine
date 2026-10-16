<?php
defined('ABSPATH') || exit;

final class BTL_Payment_Exception extends RuntimeException {}

interface BTL_Payment_Provider
{
    public function id(): string;
    public function label(): string;
    public function isConfigured(): bool;
    public function minToman(): int;
    public function maxToman(): int;
    public function reuseSeconds(): int;
    public function create(WC_Order $order, int $amountToman, string $callbackUrl): array;
    public function readCallback(WP_REST_Request $request): array;
    public function verify(string $trace, int $amountToman): array;
}

abstract class BTL_Payment_Provider_Base implements BTL_Payment_Provider
{
    protected const TIMEOUT = 15;

    protected static function setting(string $name): string
    {
        $value = defined($name) ? (string) constant($name) : '';
        if ($value === '') {
            $env = getenv($name);
            $value = is_string($env) ? $env : '';
        }
        return trim($value);
    }

    protected static function param(WP_REST_Request $request, string $key): string
    {
        $value = $request->get_param($key);
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim((string) $value);
        return strlen($value) <= 255 ? $value : substr($value, 0, 255);
    }

    protected static function post(string $url, array $args): ?array
    {
        $response = wp_remote_post($url, array_merge([
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
        ], $args));

        if (is_wp_error($response)) {
            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        return [
            'code' => (int) wp_remote_retrieve_response_code($response),
            'json' => is_array($decoded) ? $decoded : null,
        ];
    }

    protected static function log(string $context, string $reason): void
    {
        BTL_Helpers::logger($context . ': ' . sanitize_text_field($reason));
    }
}

final class BTL_Aqayepardakht_Provider extends BTL_Payment_Provider_Base
{
    private const API_BASE = 'https://api.aqayepardakht.ir/v3/';
    private const START_BASE = 'https://api.aqayepardakht.ir/startpay/';

    public function id(): string { return 'aqayepardakht'; }
    public function label(): string { return 'آقای پرداخت'; }
    public function isConfigured(): bool { return self::setting('BTL_AQAYEPARDAKHT_PIN') !== ''; }
    public function minToman(): int { return 1001; }
    public function maxToman(): int { return 99999999; }
    public function reuseSeconds(): int { return 1800; }

    public function create(WC_Order $order, int $amountToman, string $callbackUrl): array
    {
        $body = [
            'pin' => self::setting('BTL_AQAYEPARDAKHT_PIN'),
            'amount' => $amountToman,
            'callback' => $callbackUrl,
            'invoice_id' => (string) $order->get_id(),
            'description' => 'Arena2Battle - Order ' . $order->get_order_number(),
        ];

        $mobile = preg_replace('/[^0-9+]/', '', (string) $order->get_billing_phone());
        if (is_string($mobile) && $mobile !== '' && strlen($mobile) <= 32) {
            $body['mobile'] = $mobile;
        }

        $email = sanitize_email((string) $order->get_billing_email());
        if ($email !== '') {
            $body['email'] = $email;
        }

        $result = self::post(self::API_BASE . 'pay', [
            'headers' => ['Accept' => 'application/json'],
            'body' => $body,
        ]);

        if ($result === null) {
            self::log('Aqayepardakht create', 'http_error order ' . $order->get_id());
            throw new BTL_Payment_Exception('aqayepardakht_create_http_error');
        }

        $decoded = $result['json'];
        if (!is_array($decoded)) {
            self::log('Aqayepardakht create', 'invalid_json_' . $result['code'] . ' order ' . $order->get_id());
            throw new BTL_Payment_Exception('aqayepardakht_create_invalid_response');
        }

        $status = strtolower(trim((string) ($decoded['status'] ?? '')));
        $trace = trim((string) ($decoded['tracking_code'] ?? ''));

        if ($status === 'error' || $trace === '') {
            $message = trim((string) ($decoded['message'] ?? $decoded['error'] ?? ''));
            self::log('Aqayepardakht create', ($message !== '' ? substr($message, 0, 180) : 'provider_error_' . $result['code']) . ' order ' . $order->get_id());
            throw new BTL_Payment_Exception('aqayepardakht_create_provider_error');
        }

        return ['trace' => $trace, 'url' => self::START_BASE . rawurlencode($trace)];
    }

    public function readCallback(WP_REST_Request $request): array
    {
        $trace = self::param($request, 'transid');
        if ($trace === '') {
            $trace = self::param($request, 'tracking_number');
        }
        if ($trace === '') {
            $trace = self::param($request, 'tracking_code');
        }

        $order = self::param($request, 'oid');
        if ($order === '') {
            $order = self::param($request, 'invoice_id');
        }

        return [
            'order_id' => ctype_digit($order) ? (int) $order : 0,
            'trace' => $trace,
            'ok' => true,
        ];
    }

    public function verify(string $trace, int $amountToman): array
    {
        $result = self::post(self::API_BASE . 'verify', [
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'pin' => self::setting('BTL_AQAYEPARDAKHT_PIN'),
                'tracking_code' => $trace,
                'amount' => $amountToman,
            ],
        ]);

        if ($result === null) {
            return ['ok' => false, 'reference' => '', 'reason' => 'verify_http_error'];
        }

        $decoded = $result['json'];
        if (!is_array($decoded)) {
            return ['ok' => false, 'reference' => '', 'reason' => 'verify_invalid_json_' . $result['code']];
        }

        $status = strtolower(trim((string) ($decoded['status'] ?? '')));
        if ($status === 'error') {
            $message = trim((string) ($decoded['message'] ?? $decoded['error'] ?? ''));
            return ['ok' => false, 'reference' => '', 'reason' => $message !== '' ? substr($message, 0, 180) : 'verify_provider_error_' . $result['code']];
        }

        $reference = trim((string) ($decoded['tracking_number'] ?? $decoded['trackingNumber'] ?? $decoded['receipt'] ?? ''));

        return ['ok' => true, 'reference' => $reference, 'reason' => ''];
    }
}

final class BTL_Zarinpal_Provider extends BTL_Payment_Provider_Base
{
    public function id(): string { return 'zarinpal'; }
    public function label(): string { return 'زرین‌پال'; }
    public function isConfigured(): bool { return (bool) preg_match('/^[A-Za-z0-9-]{36}$/', self::setting('BTL_ZARINPAL_MERCHANT_ID')); }
    public function minToman(): int { return 1000; }
    public function maxToman(): int { return 100000000; }
    public function reuseSeconds(): int { return 900; }

    private function host(): string
    {
        $sandbox = strtolower(self::setting('BTL_ZARINPAL_SANDBOX'));
        return in_array($sandbox, ['1', 'true', 'yes'], true)
            ? 'https://sandbox.zarinpal.com'
            : 'https://payment.zarinpal.com';
    }

    public function create(WC_Order $order, int $amountToman, string $callbackUrl): array
    {
        $payload = [
            'merchant_id' => self::setting('BTL_ZARINPAL_MERCHANT_ID'),
            'amount' => $amountToman * 10,
            'callback_url' => $callbackUrl,
            'description' => 'Arena2Battle - Order ' . $order->get_order_number(),
        ];

        $metadata = [];
        $mobile = preg_replace('/\D/', '', (string) $order->get_billing_phone());
        if (is_string($mobile) && preg_match('/^09\d{9}$/', $mobile)) {
            $metadata['mobile'] = $mobile;
        }
        $email = sanitize_email((string) $order->get_billing_email());
        if ($email !== '') {
            $metadata['email'] = $email;
        }
        if ($metadata) {
            $payload['metadata'] = $metadata;
        }

        $result = self::post($this->host() . '/pg/v4/payment/request.json', [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);

        if ($result === null) {
            self::log('Zarinpal create', 'http_error order ' . $order->get_id());
            throw new BTL_Payment_Exception('zarinpal_create_http_error');
        }

        $decoded = $result['json'];
        if (!is_array($decoded)) {
            self::log('Zarinpal create', 'invalid_json_' . $result['code'] . ' order ' . $order->get_id());
            throw new BTL_Payment_Exception('zarinpal_create_invalid_response');
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $code = (int) ($data['code'] ?? 0);
        $authority = trim((string) ($data['authority'] ?? ''));

        if ($code !== 100 || $authority === '') {
            $errors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
            $errorCode = (string) ($errors['code'] ?? $code);
            $errorMessage = (string) ($errors['message'] ?? '');
            self::log('Zarinpal create', 'code_' . $errorCode . ' ' . substr($errorMessage, 0, 120) . ' order ' . $order->get_id());
            throw new BTL_Payment_Exception('zarinpal_create_provider_error');
        }

        return ['trace' => $authority, 'url' => $this->host() . '/pg/StartPay/' . rawurlencode($authority)];
    }

    public function readCallback(WP_REST_Request $request): array
    {
        $order = self::param($request, 'oid');

        return [
            'order_id' => ctype_digit($order) ? (int) $order : 0,
            'trace' => self::param($request, 'Authority'),
            'ok' => strtoupper(self::param($request, 'Status')) === 'OK',
        ];
    }

    public function verify(string $trace, int $amountToman): array
    {
        $result = self::post($this->host() . '/pg/v4/payment/verify.json', [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode([
                'merchant_id' => self::setting('BTL_ZARINPAL_MERCHANT_ID'),
                'amount' => $amountToman * 10,
                'authority' => $trace,
            ]),
        ]);

        if ($result === null) {
            return ['ok' => false, 'reference' => '', 'reason' => 'verify_http_error'];
        }

        $decoded = $result['json'];
        if (!is_array($decoded)) {
            return ['ok' => false, 'reference' => '', 'reason' => 'verify_invalid_json_' . $result['code']];
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $code = (int) ($data['code'] ?? 0);

        if ($code === 100 || $code === 101) {
            return ['ok' => true, 'reference' => trim((string) ($data['ref_id'] ?? '')), 'reason' => ''];
        }

        $errors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
        $errorCode = (string) ($errors['code'] ?? $code);

        return ['ok' => false, 'reference' => '', 'reason' => 'verify_code_' . $errorCode];
    }
}

final class BTL_Payment_Gateway
{
    private const META_PROVIDER = '_btl_pay_provider';
    private const META_URL = '_btl_pay_url';
    private const META_AMOUNT = '_btl_pay_amount';
    private const META_CREATED = '_btl_pay_created_at';
    private const META_TRACES = '_btl_pay_traces';
    private const META_REFERENCE = '_btl_pay_reference';
    private const META_VERIFIED_AT = '_btl_pay_verified_at';
    private const LEGACY_TRACE = '_btl_aqayepardakht_trace_code';
    private const LEGACY_URL = '_btl_aqayepardakht_payment_url';
    private const LEGACY_AMOUNT = '_btl_aqayepardakht_amount_toman';
    private const VERIFY_LOCK_PREFIX = 'btl_pay_verify_';
    private const VERIFY_LOCK_TTL = 60;
    private const DEAD_STATUSES = ['cancelled', 'refunded', 'trash'];
    private const MAX_TRACES_PER_PROVIDER = 10;

    private static ?array $providers = null;

    private static function setting(string $name): string
    {
        $value = defined($name) ? (string) constant($name) : '';
        if ($value === '') {
            $env = getenv($name);
            $value = is_string($env) ? $env : '';
        }
        return trim($value);
    }

    public static function providers(): array
    {
        if (self::$providers === null) {
            self::$providers = [];
            foreach ([new BTL_Zarinpal_Provider(), new BTL_Aqayepardakht_Provider()] as $provider) {
                self::$providers[$provider->id()] = $provider;
            }
        }

        return self::$providers;
    }

    public static function enabled(): array
    {
        $allowed = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string) $value)),
            explode(',', self::setting('BTL_PAYMENT_GATEWAYS'))
        )));

        $enabled = [];
        foreach (self::providers() as $id => $provider) {
            if (!$provider->isConfigured()) {
                continue;
            }
            if ($allowed && !in_array($id, $allowed, true)) {
                continue;
            }
            $enabled[$id] = $provider;
        }

        return $enabled;
    }

    public static function defaultId(): string
    {
        $enabled = self::enabled();
        $preferred = strtolower(self::setting('BTL_PAYMENT_DEFAULT_GATEWAY'));

        if ($preferred !== '' && isset($enabled[$preferred])) {
            return $preferred;
        }

        $keys = array_keys($enabled);

        return $keys[0] ?? '';
    }

    public static function resolve(?string $requested = null): BTL_Payment_Provider
    {
        $enabled = self::enabled();
        $id = $requested !== null && $requested !== '' ? $requested : self::defaultId();

        if ($id === '' || !isset($enabled[$id])) {
            throw new BTL_Payment_Exception('payment_gateway_unavailable');
        }

        return $enabled[$id];
    }

    public static function register_rest_routes(): void
    {
        $handler = static fn(WP_REST_Request $request) => self::handle_callback($request, (string) $request->get_param('provider'));

        register_rest_route('btl/v1', '/pay/(?P<provider>[a-z_]+)/(?P<oid>\d+)/callback', [
            'methods' => ['GET', 'POST'],
            'callback' => $handler,
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('btl/v1', '/pay/(?P<provider>[a-z_]+)/callback', [
            'methods' => ['GET', 'POST'],
            'callback' => $handler,
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('btl/v1', '/aqayepardakht/callback', [
            'methods' => ['GET', 'POST'],
            'callback' => static fn(WP_REST_Request $request) => self::handle_callback($request, 'aqayepardakht'),
            'permission_callback' => '__return_true',
        ]);
    }

    public static function register_graphql(): void
    {
        register_graphql_object_type('BtlPaymentGatewayOption', [
            'fields' => [
                'id' => ['type' => 'String'],
                'label' => ['type' => 'String'],
                'isDefault' => ['type' => 'Boolean'],
            ],
        ]);

        register_graphql_field('RootQuery', 'paymentGateways', [
            'type' => ['list_of' => 'BtlPaymentGatewayOption'],
            'resolve' => static function (): array {
                $default = self::defaultId();
                $items = [];
                foreach (self::enabled() as $id => $provider) {
                    $items[] = ['id' => $id, 'label' => $provider->label(), 'isDefault' => $id === $default];
                }
                return $items;
            },
        ]);
    }

    private static function callbackUrl(BTL_Payment_Provider $provider, WC_Order $order): string
    {
        $base = rtrim(self::setting('BTL_PAYMENT_CALLBACK_BASE'), '/');
        $orderId = (int) $order->get_id();

        if ($base !== '') {
            return $base . '/' . $provider->id() . '/' . $orderId;
        }

        return rest_url('btl/v1/pay/' . $provider->id() . '/' . $orderId . '/callback');
    }

    public static function ensurePayment(WC_Order $order, ?string $requested = null): string
    {
        if ($order->is_paid()) {
            return '';
        }

        $requested = $requested !== null && $requested !== '' ? $requested : null;
        $storedProvider = trim((string) $order->get_meta(self::META_PROVIDER));
        $legacyUrl = trim((string) $order->get_meta(self::LEGACY_URL));

        if (
            $storedProvider === ''
            && $legacyUrl !== ''
            && ($requested === null || $requested === 'aqayepardakht')
            && trim((string) $order->get_meta(self::LEGACY_TRACE)) !== ''
            && (int) $order->get_meta(self::LEGACY_AMOUNT) === (int) round((float) $order->get_total())
        ) {
            return $legacyUrl;
        }

        $provider = self::resolve($requested ?? ($storedProvider !== '' ? $storedProvider : null));
        $amount = self::amountToman($order, $provider);

        $storedUrl = trim((string) $order->get_meta(self::META_URL));
        $createdAt = (int) $order->get_meta(self::META_CREATED);

        if (
            $storedProvider === $provider->id()
            && $storedUrl !== ''
            && (int) $order->get_meta(self::META_AMOUNT) === $amount
            && $createdAt > 0
            && (time() - $createdAt) < $provider->reuseSeconds()
        ) {
            return $storedUrl;
        }

        $payment = $provider->create($order, $amount, self::callbackUrl($provider, $order));

        $traces = self::traces($order);
        $list = $traces[$provider->id()] ?? [];
        $list[] = $payment['trace'];
        $traces[$provider->id()] = array_values(array_unique(array_slice($list, -self::MAX_TRACES_PER_PROVIDER)));

        $order->set_payment_method('btl_' . $provider->id());
        $order->set_payment_method_title($provider->label());
        $order->set_transaction_id($payment['trace']);
        $order->update_meta_data(self::META_PROVIDER, $provider->id());
        $order->update_meta_data(self::META_URL, $payment['url']);
        $order->update_meta_data(self::META_AMOUNT, (string) $amount);
        $order->update_meta_data(self::META_CREATED, (string) time());
        $order->update_meta_data(self::META_TRACES, $traces);
        $order->save();

        return $payment['url'];
    }

    public static function storedPaymentUrl(WC_Order $order): string
    {
        $url = trim((string) $order->get_meta(self::META_URL));
        if ($url !== '') {
            return $url;
        }

        return trim((string) $order->get_meta(self::LEGACY_URL));
    }

    public static function handle_callback(WP_REST_Request $request, string $providerId)
    {
        nocache_headers();

        $provider = self::providers()[$providerId] ?? null;
        if (!$provider instanceof BTL_Payment_Provider) {
            return self::redirectResult('cancelled', 0);
        }

        $data = $provider->readCallback($request);
        $trace = (string) $data['trace'];
        $order = (int) $data['order_id'] > 0 ? wc_get_order((int) $data['order_id']) : false;

        if (!$order instanceof WC_Order) {
            return self::redirectResult('cancelled', 0);
        }

        $orderId = (int) $order->get_id();

        if (!self::traceBelongs($order, $provider->id(), $trace)) {
            self::logOrder($orderId, $provider, 'trace_mismatch');
            return self::redirectResult('cancelled', $orderId);
        }

        if ($order->is_paid()) {
            return self::redirectResult('success', $orderId);
        }

        if (!$data['ok']) {
            return self::redirectResult('cancelled', $orderId);
        }

        if (in_array($order->get_status(), self::DEAD_STATUSES, true)) {
            self::logOrder($orderId, $provider, 'order_not_payable_' . $order->get_status());
            return self::redirectResult('failed', $orderId);
        }

        if (!$provider->isConfigured()) {
            self::logOrder($orderId, $provider, 'provider_not_configured');
            return self::redirectResult('failed', $orderId);
        }

        if (!self::acquireVerifyLock($orderId)) {
            return self::redirectResult('processing', $orderId);
        }

        try {
            $stored = (int) $order->get_meta(self::META_AMOUNT);
            if ($stored < 1) {
                $stored = (int) $order->get_meta(self::LEGACY_AMOUNT);
            }

            try {
                $current = self::amountToman($order, $provider);
            } catch (BTL_Payment_Exception $e) {
                self::logOrder($orderId, $provider, 'amount_out_of_range');
                return self::redirectResult('failed', $orderId);
            }

            if ($stored < 1 || $stored !== $current) {
                self::logOrder($orderId, $provider, 'amount_mismatch');
                return self::redirectResult('failed', $orderId);
            }

            $result = $provider->verify($trace, $current);
            if (!$result['ok']) {
                self::logOrder($orderId, $provider, (string) $result['reason']);
                return self::redirectResult('failed', $orderId);
            }

            $reference = (string) $result['reference'];
            if ($reference !== '') {
                $order->update_meta_data(self::META_REFERENCE, $reference);
            }
            $order->update_meta_data(self::META_VERIFIED_AT, current_time('mysql', true));
            $order->set_payment_method('btl_' . $provider->id());
            $order->set_payment_method_title($provider->label());
            $order->set_transaction_id($trace);
            $order->save();
            $order->payment_complete($trace);
            $order->add_order_note(sprintf(
                'پرداخت %s با موفقیت تأیید شد. کد پیگیری: %s',
                $provider->label(),
                $reference !== '' ? $reference : '—'
            ));
            $order->save();

            return self::redirectResult('success', $orderId);
        } finally {
            self::releaseVerifyLock($orderId);
        }
    }

    private static function traces(WC_Order $order): array
    {
        $raw = $order->get_meta(self::META_TRACES);

        return is_array($raw) ? $raw : [];
    }

    private static function traceBelongs(WC_Order $order, string $providerId, string $trace): bool
    {
        if ($trace === '') {
            return false;
        }

        foreach ((array) (self::traces($order)[$providerId] ?? []) as $known) {
            if (hash_equals((string) $known, $trace)) {
                return true;
            }
        }

        if ($providerId === 'aqayepardakht') {
            $legacy = trim((string) $order->get_meta(self::LEGACY_TRACE));
            if ($legacy !== '' && hash_equals($legacy, $trace)) {
                return true;
            }
        }

        return false;
    }

    private static function amountToman(WC_Order $order, BTL_Payment_Provider $provider): int
    {
        $amount = (int) round((float) $order->get_total());
        if ($amount < $provider->minToman() || $amount > $provider->maxToman()) {
            throw new BTL_Payment_Exception('payment_amount_out_of_range');
        }

        return $amount;
    }

    private static function acquireVerifyLock(int $orderId): bool
    {
        $key = self::VERIFY_LOCK_PREFIX . $orderId;
        $now = time();
        $existing = get_option($key, null);

        if ($existing !== null && (int) $existing > 0 && ($now - (int) $existing) < self::VERIFY_LOCK_TTL) {
            return false;
        }

        if ($existing !== null) {
            delete_option($key);
        }

        return add_option($key, (string) $now, '', 'no');
    }

    private static function releaseVerifyLock(int $orderId): void
    {
        delete_option(self::VERIFY_LOCK_PREFIX . $orderId);
    }

    private static function publicSiteUrl(): string
    {
        $url = self::setting('BTL_PUBLIC_SITE_URL');
        if ($url === '') {
            $url = home_url('/');
        }

        return rtrim($url, '/');
    }

    private static function redirectResult(string $result, int $orderId): WP_REST_Response
    {
        $args = ['payment' => $result];
        if ($orderId > 0) {
            $args['order'] = $orderId;
        }

        return new WP_REST_Response(null, 303, [
            'Location' => add_query_arg($args, self::publicSiteUrl() . '/my-account/orders'),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private static function logOrder(int $orderId, BTL_Payment_Provider $provider, string $reason): void
    {
        BTL_Helpers::logger(sprintf('Payment %s callback failed for order %d: %s', $provider->id(), $orderId, sanitize_text_field($reason)));
    }
}