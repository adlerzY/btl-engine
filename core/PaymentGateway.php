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
    public function create(WC_Order $order, int $amountToman, string $callbackUrl, int $timeout = 8): array;
    public function readCallback(WP_REST_Request $request): array;
    public function verify(string $trace, int $amountToman): array;
}

abstract class BTL_Payment_Provider_Base implements BTL_Payment_Provider
{
    protected const VERIFY_TIMEOUT = 15;
    protected const USER_AGENT = 'Arena2Battle-Payments/1.0';

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

    protected static function post(string $url, array $args, int $timeout, int $attempts = 1): array
    {
        $attempts = max(1, $attempts);
        $response = null;

        for ($i = 1; $i <= $attempts; $i++) {
            $response = wp_remote_post($url, array_merge([
                'timeout' => max(2, $timeout),
                'redirection' => 0,
                'user-agent' => self::USER_AGENT,
            ], $args));

            if (!is_wp_error($response)) {
                break;
            }

            if ($i < $attempts) {
                usleep(300000);
            }
        }

        if (is_wp_error($response)) {
            return [
                'transport' => false,
                'code' => 0,
                'json' => null,
                'raw' => '',
                'error' => $response->get_error_code() . ' ' . $response->get_error_message(),
            ];
        }

        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        return [
            'transport' => true,
            'code' => (int) wp_remote_retrieve_response_code($response),
            'json' => is_array($decoded) ? $decoded : null,
            'raw' => $raw,
            'error' => '',
        ];
    }

    protected static function snippet(string $raw): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($raw)));
        return $text === '' ? '(empty body)' : substr($text, 0, 200);
    }

    protected static function log(string $context, string $reason): void
    {
        BTL_Helpers::logger($context . ': ' . sanitize_text_field($reason));
    }
}

final class BTL_Aqayepardakht_Provider extends BTL_Payment_Provider_Base
{
    private const API_BASE = 'https://panel.aqayepardakht.ir/api/v2/';
    private const START_BASE = 'https://panel.aqayepardakht.ir/startpay/';
    private const START_SANDBOX_BASE = 'https://panel.aqayepardakht.ir/startpay/sandbox/';

    public function id(): string { return 'aqayepardakht'; }
    public function label(): string { return 'آقای پرداخت'; }
    public function isConfigured(): bool { return self::setting('BTL_AQAYEPARDAKHT_PIN') !== ''; }
    public function minToman(): int { return 1000; }
    public function maxToman(): int { return 400000000; }
    public function reuseSeconds(): int { return 1800; }

    public function create(WC_Order $order, int $amountToman, string $callbackUrl, int $timeout = 8): array
    {
        $pin = self::setting('BTL_AQAYEPARDAKHT_PIN');

        $body = [
            'pin' => $pin,
            'amount' => $amountToman,
            'callback' => $callbackUrl,
            'callback_method' => 'GET',
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

        $result = self::post(self::API_BASE . 'create', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ], $timeout);

        if (!$result['transport']) {
            self::log('Aqayepardakht create', 'transport_error order ' . $order->get_id() . ' — ' . $result['error']);
            throw new BTL_Payment_Exception('aqayepardakht_create_transport_error');
        }

        $decoded = $result['json'];
        if (!is_array($decoded)) {
            self::log('Aqayepardakht create', 'invalid_json_http_' . $result['code'] . ' order ' . $order->get_id() . ' — ' . self::snippet($result['raw']));
            throw new BTL_Payment_Exception('aqayepardakht_create_invalid_response');
        }

        $status = strtolower(trim((string) ($decoded['status'] ?? '')));
        $trace = trim((string) ($decoded['transid'] ?? ''));

        if ($result['code'] !== 200 || $status !== 'success' || $trace === '') {
            $message = trim((string) ($decoded['message'] ?? $decoded['code'] ?? ''));
            self::log('Aqayepardakht create', 'provider_error_http_' . $result['code'] . ' ' . ($message !== '' ? substr($message, 0, 180) : self::snippet($result['raw'])) . ' order ' . $order->get_id());
            throw new BTL_Payment_Exception('aqayepardakht_create_provider_error');
        }

        $startBase = strtolower($pin) === 'sandbox' ? self::START_SANDBOX_BASE : self::START_BASE;

        return [
            'trace' => $trace,
            'url' => $startBase . rawurlencode($trace),
        ];
    }

    public function readCallback(WP_REST_Request $request): array
    {
        $trace = self::param($request, 'transid');

        $order = self::param($request, 'oid');
        if ($order === '') {
            $order = self::param($request, 'invoice_id');
        }

        return [
            'order_id' => ctype_digit($order) ? (int) $order : 0,
            'trace' => $trace,
            'ok' => self::param($request, 'status') === '1',
        ];
    }

    public function verify(string $trace, int $amountToman): array
    {
        $result = self::post(self::API_BASE . 'verify', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode([
                'pin' => self::setting('BTL_AQAYEPARDAKHT_PIN'),
                'amount' => $amountToman,
                'transid' => $trace,
            ]),
        ], self::VERIFY_TIMEOUT, 2);

        if (!$result['transport']) {
            return [
                'ok' => false,
                'reference' => '',
                'reason' => 'verify_transport_error ' . $result['error'],
                'transient' => true,
            ];
        }

        $decoded = $result['json'];
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'reference' => '',
                'reason' => 'verify_invalid_json_http_' . $result['code'] . ' ' . self::snippet($result['raw']),
                'transient' => $result['code'] === 0 || $result['code'] === 429 || $result['code'] >= 500,
            ];
        }

        $status = strtolower(trim((string) ($decoded['status'] ?? '')));
        $code = trim((string) ($decoded['code'] ?? ''));

        if ($result['code'] === 200 && $status === 'success' && $code === '1') {
            return [
                'ok' => true,
                'reference' => '',
                'reason' => '',
                'transient' => false,
            ];
        }

        if ($status === 'error' && $code === '2') {
            return [
                'ok' => true,
                'reference' => '',
                'reason' => '',
                'transient' => false,
            ];
        }

        $message = trim((string) ($decoded['message'] ?? ''));

        return [
            'ok' => false,
            'reference' => '',
            'reason' => $message !== '' ? substr($message, 0, 180) : 'verify_provider_error_http_' . $result['code'] . '_code_' . $code,
            'transient' => $result['code'] === 0 || $result['code'] === 429 || $result['code'] >= 500,
        ];
    }
}

final class BTL_Zarinpal_Provider extends BTL_Payment_Provider_Base
{
    public function id(): string { return 'zarinpal'; }
    public function label(): string { return 'زرین‌پال'; }
    public function isConfigured(): bool { return (bool) preg_match('/^[A-Za-z0-9-]{36}$/', self::setting('BTL_ZARINPAL_MERCHANT_ID')); }
    public function minToman(): int { return 1500; }
    public function maxToman(): int { return 100000000; }
    public function reuseSeconds(): int { return 900; }

    private function host(): string
    {
        $sandbox = strtolower(self::setting('BTL_ZARINPAL_SANDBOX'));
        return in_array($sandbox, ['1', 'true', 'yes'], true)
            ? 'https://sandbox.zarinpal.com'
            : 'https://payment.zarinpal.com';
    }

    private function startPayBase(): string
    {
        $sandbox = strtolower(self::setting('BTL_ZARINPAL_SANDBOX'));
        if (in_array($sandbox, ['1', 'true', 'yes'], true)) {
            return 'https://sandbox.zarinpal.com';
        }

        $domain = strtolower(self::setting('BTL_ZARINPAL_PAY_DOMAIN'));
        if ($domain !== '' && preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            return 'https://' . $domain;
        }

        return $this->host();
    }

    public function create(WC_Order $order, int $amountToman, string $callbackUrl, int $timeout = 8): array
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
        if ($email !== '' && !str_ends_with(strtolower($email), '.local')) {
            $metadata['email'] = $email;
        }
        if ($metadata) {
            $payload['metadata'] = $metadata;
        }

        $result = self::post($this->host() . '/pg/v4/payment/request.json', [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode($payload),
        ], $timeout);

        if (!$result['transport']) {
            self::log('Zarinpal create', 'transport_error order ' . $order->get_id() . ' — ' . $result['error']);
            throw new BTL_Payment_Exception('zarinpal_create_transport_error');
        }

        $decoded = $result['json'];
        if (!is_array($decoded)) {
            self::log('Zarinpal create', 'invalid_json_http_' . $result['code'] . ' order ' . $order->get_id() . ' — ' . self::snippet($result['raw']));
            throw new BTL_Payment_Exception('zarinpal_create_invalid_response');
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $code = (int) ($data['code'] ?? 0);
        $authority = trim((string) ($data['authority'] ?? ''));

        if ($code !== 100 || $authority === '') {
            $errors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
            $errorCode = (string) ($errors['code'] ?? $code);
            $errorMessage = (string) ($errors['message'] ?? '');
            $validations = isset($errors['validations']) ? wp_json_encode($errors['validations'], JSON_UNESCAPED_UNICODE) : '';
            self::log('Zarinpal create', 'provider_error http_' . $result['code'] . ' code_' . $errorCode . ' ' . substr($errorMessage, 0, 120) . ' ' . substr((string) $validations, 0, 200) . ' order ' . $order->get_id());
            throw new BTL_Payment_Exception('zarinpal_create_provider_error_' . $errorCode);
        }

        return ['trace' => $authority, 'url' => $this->startPayBase() . '/pg/StartPay/' . rawurlencode($authority)];
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
        ], self::VERIFY_TIMEOUT, 2);

        if (!$result['transport']) {
            return [
                'ok' => false,
                'reference' => '',
                'reason' => 'verify_transport_error ' . $result['error'],
                'transient' => true,
            ];
        }

        $decoded = $result['json'];
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'reference' => '',
                'reason' => 'verify_invalid_json_http_' . $result['code'] . ' ' . self::snippet($result['raw']),
                'transient' => $result['code'] === 0 || $result['code'] === 429 || $result['code'] >= 500,
            ];
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $code = (int) ($data['code'] ?? 0);

        if ($result['code'] === 200 && ($code === 100 || $code === 101)) {
            return [
                'ok' => true,
                'reference' => trim((string) ($data['ref_id'] ?? '')),
                'reason' => '',
                'transient' => false,
            ];
        }

        $errors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
        $errorCode = (string) ($errors['code'] ?? $code);

        return [
            'ok' => false,
            'reference' => '',
            'reason' => 'verify_code_' . $errorCode,
            'transient' => $result['code'] === 0 || $result['code'] === 429 || $result['code'] >= 500,
        ];
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
    private const VERIFY_LOCK_TTL = 90;
    private const META_STATE = '_btl_pay_state';
    private const META_VERIFIED_TRACE = '_btl_pay_verified_trace';
    private const META_DUPLICATE_TRACES = '_btl_pay_duplicate_traces';
    private const META_RECONCILE_ALERTED = '_btl_pay_reconcile_alerted';
    private const META_COMPLETION_NOTE = '_btl_pay_completion_note';
    private const HOOK_RECONCILE = 'btl_payment_reconcile';
    private const HOOK_COMPLETE = 'btl_payment_complete';
    private const AS_GROUP = 'btl';
    private const RECONCILE_DELAYS = [90, 240, 600, 1200, 1800];
    private const COMPLETE_DELAYS = [30, 120, 300, 900, 1800, 3600];
    private const COMPLETE_LOCK_PREFIX = 'btl_pay_complete_';
    private const COMPLETE_LOCK_TTL = 180;
    private const NO_VERIFY_STATUSES = ['refunded', 'trash'];
    private const COMPLETABLE_STATUSES = ['pending', 'on-hold', 'checkout-draft', 'processing', 'completed'];
    private const MAX_TRACES_PER_PROVIDER = 10;
    private const CREATE_BUDGET = 9;

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

    private static function orderedProviders(): array
    {
        $enabled = self::enabled();
        $default = self::defaultId();
        $ordered = [];

        if ($default !== '' && isset($enabled[$default])) {
            $ordered[] = $enabled[$default];
        }

        foreach ($enabled as $id => $provider) {
            if ($id !== $default) {
                $ordered[] = $provider;
            }
        }

        return $ordered;
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
        $orderId = (int) $order->get_id();
        $base = rtrim(self::setting('BTL_PAYMENT_CALLBACK_BASE'), '/');

        if ($base === '') {
            $site = rtrim(self::setting('BTL_PUBLIC_SITE_URL'), '/');
            if ($site !== '') {
                $base = $site . '/api/payment/callback';
            }
        }

        if ($base !== '') {
            return $base . '/' . $provider->id() . '/' . $orderId;
        }

        return rest_url('btl/v1/pay/' . $provider->id() . '/' . $orderId . '/callback');
    }

    public static function ensurePayment(WC_Order $order, ?string $requested = null): string
    {
        if ($order->is_paid()) {
            if (self::verifiedTrace($order) !== '') {
                self::queueCompletion((int) $order->get_id());
            }
            return '';
        }

        if (self::verifiedTrace($order) !== '') {
            self::queueCompletion((int) $order->get_id());
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
            self::scheduleReconcile((int) $order->get_id(), 'aqayepardakht', trim((string) $order->get_meta(self::LEGACY_TRACE)));
            return $legacyUrl;
        }

        $preferred = $requested ?? ($storedProvider !== '' ? $storedProvider : null);
        $providers = $preferred !== null ? [self::resolve($preferred)] : self::orderedProviders();

        if (!$providers) {
            BTL_Helpers::logger('Payment: no enabled provider (check BTL_PAYMENT_GATEWAYS / merchant constants in wp-config.php before wp-settings.php) order ' . (int) $order->get_id());
            throw new BTL_Payment_Exception('payment_gateway_unavailable');
        }

        $started = microtime(true);
        $total = count($providers);
        $lastError = null;

        foreach ($providers as $index => $provider) {
            $remaining = (int) floor(self::CREATE_BUDGET - (microtime(true) - $started));
            if ($index > 0 && $remaining < 2) {
                break;
            }

            try {
                $amount = self::amountToman($order, $provider);
            } catch (BTL_Payment_Exception $e) {
                $lastError = $e;
                BTL_Helpers::logger(sprintf(
                    'Payment %s skipped for order %d: %s (total %s)',
                    $provider->id(),
                    (int) $order->get_id(),
                    $e->getMessage(),
                    (string) $order->get_total()
                ));
                continue;
            }

            $storedUrl = trim((string) $order->get_meta(self::META_URL));
            $createdAt = (int) $order->get_meta(self::META_CREATED);

            if (
                $storedProvider === $provider->id()
                && $storedUrl !== ''
                && (int) $order->get_meta(self::META_AMOUNT) === $amount
                && $createdAt > 0
                && (time() - $createdAt) < $provider->reuseSeconds()
            ) {
                $storedTrace = trim((string) $order->get_transaction_id());
                if ($storedTrace === '') {
                    $knownTraces = (array) (self::traces($order)[$provider->id()] ?? []);
                    $storedTrace = $knownTraces ? (string) end($knownTraces) : '';
                }
                self::scheduleReconcile((int) $order->get_id(), $provider->id(), $storedTrace);
                return $storedUrl;
            }

            $timeout = $index < $total - 1 ? min(5, max(2, $remaining)) : max(2, $remaining);

            try {
                $payment = $provider->create($order, $amount, self::callbackUrl($provider, $order), $timeout);
            } catch (BTL_Payment_Exception $e) {
                $lastError = $e;
                BTL_Helpers::logger(sprintf(
                    'Payment %s create failed for order %d: %s%s',
                    $provider->id(),
                    (int) $order->get_id(),
                    $e->getMessage(),
                    $index < $total - 1 ? ' — trying next provider' : ''
                ));
                continue;
            }

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

            self::scheduleReconcile((int) $order->get_id(), $provider->id(), (string) $payment['trace']);
            return $payment['url'];
        }

        throw $lastError ?? new BTL_Payment_Exception('payment_gateway_unavailable');
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
            $knownTrace = self::verifiedTrace($order);
            if ($knownTrace !== '' && hash_equals($knownTrace, $trace)) {
                self::queueCompletion($orderId);
                return self::redirectResult('success', $orderId);
            }
            if ($knownTrace === '' && self::isLegacyPaidTrace($order, $provider, $trace)) {
                self::markLegacyPaidTrace($order, $trace);
                return self::redirectResult('success', $orderId);
            }
        }

        $outcome = self::verifyAndRecord($order, $provider, $trace);
        if ($outcome === 'verified') {
            $fresh = wc_get_order($orderId);
            if ($fresh instanceof WC_Order && $fresh->is_paid()) return self::redirectResult('success', $orderId);
            return self::redirectResult('processing', $orderId);
        }
        if (in_array($outcome, ['busy', 'transient', 'duplicate'], true)) {
            return self::redirectResult('processing', $orderId);
        }
        return self::redirectResult($outcome === 'rejected' ? 'cancelled' : 'failed', $orderId);
    }

    private static function state(WC_Order $order): string
    {
        return trim((string) $order->get_meta(self::META_STATE));
    }

    private static function verifiedTrace(WC_Order $order): string
    {
        return trim((string) $order->get_meta(self::META_VERIFIED_TRACE));
    }

    private static function isLegacyPaidTrace(WC_Order $order, BTL_Payment_Provider $provider, string $trace): bool
    {
        return $order->is_paid()
            && $order->get_payment_method() === 'btl_' . $provider->id()
            && hash_equals(trim((string) $order->get_transaction_id()), $trace);
    }

    private static function markLegacyPaidTrace(WC_Order $order, string $trace): void
    {
        $order->update_meta_data(self::META_VERIFIED_TRACE, $trace);
        $order->update_meta_data(self::META_VERIFIED_AT, current_time('mysql', true));
        $order->update_meta_data(self::META_STATE, 'verified');
        $order->save();
        self::queueCompletion((int) $order->get_id());
    }

    private static function setState(WC_Order $order, string $state): void
    {
        $order->update_meta_data(self::META_STATE, $state);
        $order->save_meta_data();
    }

    private static function acquireLock(string $key, int $ttl): ?string
    {
        global $wpdb;
        $value = wp_generate_uuid4() . ':' . (time() + max(1, $ttl));
        if (add_option($key, $value, '', 'no')) return $value;

        $existing = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $key
        ));
        if ($existing === '') return null;

        $parts = explode(':', $existing, 2);
        $expires = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($expires > time()) return null;

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $key,
            $existing
        ));
        if ((int) $deleted !== 1) return null;

        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');

        return add_option($key, $value, '', 'no') ? $value : null;
    }

    private static function releaseLock(string $key, string $value): void
    {
        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $key,
            $value
        ));
        if ((int) $deleted === 1) {
            wp_cache_delete($key, 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }

    private static function scheduleSingle(string $hook, array $args, int $delay): void
    {
        $when = time() + max(1, $delay);
        if (function_exists('as_schedule_single_action')) {
            if (function_exists('as_has_scheduled_action') && as_has_scheduled_action($hook, $args, self::AS_GROUP)) return;
            $actionId = as_schedule_single_action($when, $hook, $args, self::AS_GROUP);
            if ($actionId) return;
        }
        if (!wp_next_scheduled($hook, $args)) wp_schedule_single_event($when, $hook, $args);
    }

    private static function scheduleReconcile(int $orderId, string $providerId, string $trace, int $attempt = 0): void
    {
        if ($orderId < 1 || $trace === '' || !isset(self::RECONCILE_DELAYS[$attempt])) return;
        self::scheduleSingle(self::HOOK_RECONCILE, [$orderId, $providerId, $trace, $attempt], self::RECONCILE_DELAYS[$attempt]);
    }

    private static function queueCompletion(int $orderId): void
    {
        if ($orderId < 1) return;
        $args = [$orderId, 0];
        if (function_exists('as_enqueue_async_action')) {
            $exists = function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::HOOK_COMPLETE, $args, self::AS_GROUP);
            if (!$exists) {
                $actionId = as_enqueue_async_action(self::HOOK_COMPLETE, $args, self::AS_GROUP);
                if (!$actionId) self::scheduleSingle(self::HOOK_COMPLETE, $args, 1);
            }
        } else {
            self::scheduleSingle(self::HOOK_COMPLETE, $args, 1);
        }
        // Keep the backup retry aligned with the first retry delay. A later
        // 120-second entry with identical arguments would block the 30-second retry.
        self::scheduleSingle(self::HOOK_COMPLETE, [$orderId, 1], self::COMPLETE_DELAYS[0]);
    }

    private static function retryCompletion(WC_Order $order, int $attempt): void
    {
        $next = $attempt + 1;
        if (!isset(self::COMPLETE_DELAYS[$next - 1])) {
            self::flagForReview($order, 'تکمیل خودکار سفارش پس از چند تلاش ناموفق ماند.');
            return;
        }
        self::scheduleSingle(self::HOOK_COMPLETE, [(int) $order->get_id(), $next], self::COMPLETE_DELAYS[$next - 1]);
    }

    private static function notifyStaff(WC_Order $order, string $title, string $body): void
    {
        if (class_exists('BTL_Admin_Notifications') && is_callable(['BTL_Admin_Notifications', 'pushToStaff'])) {
            BTL_Admin_Notifications::pushToStaff('order', $title, $body, '/admin/orders/' . (int) $order->get_id());
        }
    }

    private static function flagForReview(WC_Order $order, string $reason): void
    {
        if (self::state($order) === 'review') return;
        self::setState($order, 'review');
        $trace = self::verifiedTrace($order);
        $order->add_order_note('⚠️ پرداخت تأیید شده اما سفارش خودکار تکمیل نشد: ' . $reason . ' — نیاز به بررسی دستی. کد تراکنش: ' . ($trace !== '' ? $trace : '—'));
        BTL_Helpers::logger(sprintf('Payment review needed for order %d: %s', (int) $order->get_id(), $reason));
        self::notifyStaff($order, 'پرداخت تأییدشده نیازمند بررسی', 'سفارش #' . $order->get_order_number() . ' پرداخت شده اما خودکار تکمیل نشد.');
    }

    private static function flagReconcileExhausted(WC_Order $order): void
    {
        if ($order->get_meta(self::META_RECONCILE_ALERTED) === 'yes') return;
        $order->update_meta_data(self::META_RECONCILE_ALERTED, 'yes');
        $order->add_order_note('⚠️ وضعیت پرداخت از درگاه به دلیل خطای ارتباطی قابل تعیین نشد. سفارش لغو یا تکمیل خودکار نمی‌شود و نیاز به بررسی دارد.');
        $order->save();
        self::notifyStaff($order, 'بررسی وضعیت پرداخت ناموفق بود', 'وضعیت پرداخت سفارش #' . $order->get_order_number() . ' پس از چند تلاش قابل تعیین نشد.');
    }

    private static function isTransientResult(array $result): bool
    {
        if (array_key_exists('transient', $result)) return (bool) $result['transient'];
        $reason = (string) ($result['reason'] ?? '');
        return str_starts_with($reason, 'verify_transport_error')
            || str_starts_with($reason, 'verify_invalid_json_http_5')
            || str_starts_with($reason, 'verify_provider_error_http_5');
    }

    private static function recordDuplicatePayment(WC_Order $order, BTL_Payment_Provider $provider, string $trace): void
    {
        $duplicates = $order->get_meta(self::META_DUPLICATE_TRACES);
        $duplicates = is_array($duplicates) ? $duplicates : [];
        if (!in_array($trace, $duplicates, true)) {
            $duplicates[] = $trace;
            $order->update_meta_data(self::META_DUPLICATE_TRACES, $duplicates);
            $order->add_order_note('⚠️ پرداخت تکراری برای این سفارش تأیید شد. کد تراکنش اضافی: ' . $trace . ' — بررسی و در صورت لزوم استرداد دستی لازم است.');
            $order->save();
            self::notifyStaff($order, 'پرداخت تکراری تأیید شد', 'برای سفارش #' . $order->get_order_number() . ' بیش از یک تراکنش پرداخت تأیید شده است. بررسی استرداد لازم است.');
        }
    }

    private static function verifyAndRecord(WC_Order $order, BTL_Payment_Provider $provider, string $trace): string
    {
        $orderId = (int) $order->get_id();
        $knownTrace = self::verifiedTrace($order);
        if ($knownTrace !== '' && hash_equals($knownTrace, $trace)) {
            self::queueCompletion($orderId);
            return 'verified';
        }
        if ($knownTrace === '' && self::isLegacyPaidTrace($order, $provider, $trace)) {
            self::markLegacyPaidTrace($order, $trace);
            return 'verified';
        }
        if (in_array($order->get_status(), self::NO_VERIFY_STATUSES, true)) return 'rejected';
        if (!$provider->isConfigured()) return 'transient';

        $lockKey = self::VERIFY_LOCK_PREFIX . $orderId;
        $lockValue = self::acquireLock($lockKey, self::VERIFY_LOCK_TTL);
        if ($lockValue === null) return 'busy';

        try {
            $order = wc_get_order($orderId);
            if (!$order instanceof WC_Order) return 'rejected';
            $knownTrace = self::verifiedTrace($order);
            if ($knownTrace !== '' && hash_equals($knownTrace, $trace)) {
                self::queueCompletion($orderId);
                return 'verified';
            }
            if ($knownTrace === '' && self::isLegacyPaidTrace($order, $provider, $trace)) {
                self::markLegacyPaidTrace($order, $trace);
                return 'verified';
            }
            if (in_array($order->get_status(), self::NO_VERIFY_STATUSES, true)) return 'rejected';

            $stored = (int) $order->get_meta(self::META_AMOUNT);
            if ($stored < 1) $stored = (int) $order->get_meta(self::LEGACY_AMOUNT);
            try {
                $current = self::amountToman($order, $provider);
            } catch (BTL_Payment_Exception $e) {
                self::logOrder($orderId, $provider, 'amount_out_of_range');
                return 'rejected';
            }
            if ($stored < 1 || $stored !== $current) {
                self::logOrder($orderId, $provider, 'amount_mismatch');
                return 'rejected';
            }

            $result = $provider->verify($trace, $current);
            if (empty($result['ok'])) {
                self::logOrder($orderId, $provider, (string) ($result['reason'] ?? 'verify_failed'));
                return self::isTransientResult($result) ? 'transient' : 'unpaid';
            }

            if (($knownTrace !== '' && !hash_equals($knownTrace, $trace)) || $order->is_paid()) {
                self::recordDuplicatePayment($order, $provider, $trace);
                return 'duplicate';
            }

            $reference = (string) ($result['reference'] ?? '');
            if ($reference !== '') $order->update_meta_data(self::META_REFERENCE, $reference);
            $order->update_meta_data(self::META_VERIFIED_AT, current_time('mysql', true));
            $order->update_meta_data(self::META_VERIFIED_TRACE, $trace);
            $order->update_meta_data(self::META_STATE, 'verified');
            $order->delete_meta_data(self::META_RECONCILE_ALERTED);
            $order->set_payment_method('btl_' . $provider->id());
            $order->set_payment_method_title($provider->label());
            $order->set_transaction_id($trace);
            $order->save();

            self::queueCompletion($orderId);
            return 'verified';
        } finally {
            self::releaseLock($lockKey, $lockValue);
        }
    }

    private static function ensureCodeKeysAssigned(WC_Order $order): bool
    {
        foreach ($order->get_items('line_item') as $itemId => $item) {
            if ($item->get_meta('روش تحویل') !== 'code') continue;
            if (!class_exists('BTL_Secure_Fields') || !class_exists('BTL_CdKey_Stock')) return false;
            $needed = max(1, (int) $item->get_quantity());
            if (BTL_Secure_Fields::countByOrderItem((int) $order->get_id(), (int) $itemId, 'cdkey') >= $needed) continue;
            $status = $order->get_status();
            BTL_CdKey_Stock::maybe_assign_on_status_change((int) $order->get_id(), $status, $status, $order);
            if (BTL_Secure_Fields::countByOrderItem((int) $order->get_id(), (int) $itemId, 'cdkey') < $needed) return false;
        }
        return true;
    }

    public static function run_reconcile($orderId = 0, $providerId = '', $trace = '', $attempt = 0): void
    {
        $orderId = (int) $orderId;
        $attempt = max(0, (int) $attempt);
        $trace = (string) $trace;
        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        $provider = self::providers()[(string) $providerId] ?? null;
        if (!$order instanceof WC_Order || !$provider instanceof BTL_Payment_Provider || $trace === '') return;
        if (!self::traceBelongs($order, $provider->id(), $trace)) return;

        $knownTrace = self::verifiedTrace($order);
        if ($order->is_paid()) {
            if ($knownTrace !== '' && hash_equals($knownTrace, $trace)) return;
            if ($knownTrace === '' && self::isLegacyPaidTrace($order, $provider, $trace)) {
                self::markLegacyPaidTrace($order, $trace);
                return;
            }
        }
        if (self::state($order) === 'review' && $knownTrace !== '' && hash_equals($knownTrace, $trace)) return;

        $outcome = self::verifyAndRecord($order, $provider, $trace);
        if (in_array($outcome, ['verified', 'duplicate', 'rejected'], true)) return;

        if (isset(self::RECONCILE_DELAYS[$attempt + 1])) {
            self::scheduleReconcile($orderId, $provider->id(), $trace, $attempt + 1);
            return;
        }
        if (in_array($outcome, ['busy', 'transient'], true)) {
            $fresh = wc_get_order($orderId);
            if ($fresh instanceof WC_Order) self::flagReconcileExhausted($fresh);
        }
    }

    public static function run_complete($orderId = 0, $attempt = 0): void
    {
        $orderId = (int) $orderId;
        $attempt = max(0, (int) $attempt);
        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        if (!$order instanceof WC_Order) return;

        $trace = self::verifiedTrace($order);
        if ($trace === '' || in_array(self::state($order), ['review', 'completed'], true)) return;

        $status = $order->get_status();
        if (!in_array($status, self::COMPLETABLE_STATUSES, true)) {
            self::flagForReview($order, 'وضعیت سفارش (' . $status . ') اجازه تکمیل خودکار نمی‌دهد.');
            return;
        }

        $lockKey = self::COMPLETE_LOCK_PREFIX . $orderId;
        $lockValue = self::acquireLock($lockKey, self::COMPLETE_LOCK_TTL);
        if ($lockValue === null) {
            self::retryCompletion($order, $attempt);
            return;
        }

        try {
            $order = wc_get_order($orderId);
            if (!$order instanceof WC_Order || self::state($order) === 'review') return;
            $status = $order->get_status();
            if (!in_array($status, self::COMPLETABLE_STATUSES, true)) {
                self::flagForReview($order, 'وضعیت سفارش (' . $status . ') اجازه تکمیل خودکار نمی‌دهد.');
                return;
            }

            if (!$order->is_paid()) {
                self::setState($order, 'completing');
                $order->payment_complete($trace);
                $order = wc_get_order($orderId);
                if (!$order instanceof WC_Order) return;
            }

            if (!$order->is_paid()) {
                self::retryCompletion($order, $attempt);
                return;
            }

            if (!in_array($order->get_status(), ['processing', 'completed'], true)) {
                self::flagForReview($order, 'پرداخت ثبت شده اما وضعیت سفارش ' . $order->get_status() . ' است.');
                return;
            }

            if (!self::ensureCodeKeysAssigned($order)) {
                self::flagForReview($order, 'اختصاص کامل CD Keyهای سفارش پس از پرداخت انجام نشد.');
                return;
            }

            if ($order->get_meta(self::META_COMPLETION_NOTE) !== 'yes') {
                $reference = trim((string) $order->get_meta(self::META_REFERENCE));
                $order->add_order_note(sprintf(
                    'پرداخت %s با موفقیت تأیید شد. کد پیگیری: %s',
                    $order->get_payment_method_title() ?: 'درگاه',
                    $reference !== '' ? $reference : '—'
                ));
                $order->update_meta_data(self::META_COMPLETION_NOTE, 'yes');
                $order->save();
            }
            if ($order->get_meta('_btl_pay_notified') !== 'yes') {
                $customerId = (int) $order->get_customer_id();
                if ($customerId > 0) {
                    BTL_Notifications::push(
                        $customerId,
                        'پرداخت شما تأیید شد ✅',
                        'پرداخت سفارش #' . $order->get_order_number() . ' با موفقیت تأیید شد.',
                        '/my-account/orders',
                        'order'
                    );
                }
                $order->update_meta_data('_btl_pay_notified', 'yes');
            }
            self::setState($order, 'completed');
        } catch (Throwable $e) {
            BTL_Helpers::logger(sprintf('Payment completion failed for order %d: %s', $orderId, $e->getMessage()));
            $fresh = wc_get_order($orderId);
            if ($fresh instanceof WC_Order && $fresh->is_paid() && in_array($fresh->get_status(), ['processing', 'completed'], true)) {
                try {
                    if (self::ensureCodeKeysAssigned($fresh)) {
                        self::setState($fresh, 'completed');
                    } else {
                        self::flagForReview($fresh, 'اختصاص CD Key پس از ثبت پرداخت کامل نشد.');
                    }
                } catch (Throwable $recoveryError) {
                    BTL_Helpers::logger(sprintf('Payment delivery recovery failed for order %d: %s', $orderId, $recoveryError->getMessage()));
                    self::retryCompletion($fresh, $attempt);
                }
            } elseif ($fresh instanceof WC_Order) {
                self::retryCompletion($fresh, $attempt);
            }
        } finally {
            self::releaseLock($lockKey, $lockValue);
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