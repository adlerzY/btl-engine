<?php

defined('ABSPATH') || exit;

interface BTL_Rate_Gateway
{
    public function fetchRates(): array;
}

final class BTL_Navasan_Rate_Gateway implements BTL_Rate_Gateway
{
    private const DEFAULT_BASE_URL = 'https://api.navasan.tech/latest/';

    private const ITEM_MAP = [
        'USD' => 'usd_sell',
        'EUR' => 'eur',
        'TRY' => 'try',
        'UAH' => 'uah',
    ];

    public function fetchRates(): array
    {
        $result = $this->test();
        return $result['healthy'] ? $result['rates'] : [];
    }

    /**
     * Real health test: transport, HTTP, JSON shape and every required rate.
     * Credentials and response bodies are deliberately excluded from results/logs.
     */
    public function test(): array
    {
        $key = defined('NAVASAN_API_KEY') ? trim((string) NAVASAN_API_KEY) : '';
        if ($key === '') return $this->failure('missing_credentials', 'NAVASAN_API_KEY is not configured.');

        $baseUrl = defined('NAVASAN_API_BASE_URL')
            ? rtrim((string) NAVASAN_API_BASE_URL, '/') . '/'
            : self::DEFAULT_BASE_URL;
        $timeout = defined('NAVASAN_API_TIMEOUT') ? max(2, min(30, (int) NAVASAN_API_TIMEOUT)) : 8;
        $attempts = defined('NAVASAN_API_MAX_ATTEMPTS') ? max(1, min(3, (int) NAVASAN_API_MAX_ATTEMPTS)) : 2;
        $lastError = 'connection_failed';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = wp_remote_get($baseUrl . '?api_key=' . rawurlencode($key), [
                'timeout' => $timeout,
                'redirection' => 2,
                'headers' => ['Accept' => 'application/json'],
            ]);

            if (is_wp_error($response)) {
                $lastError = $response->get_error_code() === 'http_request_failed' ? 'connection_failed' : 'transport_error';
                if ($attempt < $attempts) usleep(150000 * $attempt);
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status !== 200) {
                $lastError = 'http_' . $status;
                if ($status >= 500 && $attempt < $attempts) {
                    usleep(150000 * $attempt);
                    continue;
                }
                return $this->failure($lastError, 'Navasan returned a non-200 response.');
            }

            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) {
                return $this->failure('invalid_json', 'Navasan response is not valid JSON.');
            }

            $rates = [];
            $missing = [];
            foreach (self::ITEM_MAP as $currency => $item) {
                $raw = $body[$item]['value'] ?? null;
                $numeric = self::parsePositiveNumber($raw);
                if ($numeric === null) {
                    $missing[] = $currency;
                    continue;
                }
                $rates[$currency] = $numeric;
            }

            if ($missing && !$rates) {
                return [
                    'healthy' => false,
                    'status' => 'invalid_response',
                    'message' => 'Required rates are missing or invalid.',
                    'rates' => $rates,
                    'missing' => $missing,
                    'checked_at' => time(),
                ];
            }

            return [
                'healthy' => !empty($rates),
                'status' => $missing ? 'partial_success' : 'healthy',
                'message' => $missing ? 'Some rates are valid; missing currencies will use fallback.' : 'All required exchange rates are valid.',
                'rates' => $rates,
                'missing' => [],
                'checked_at' => time(),
            ];
        }

        return $this->failure($lastError, 'Navasan connection failed.');
    }

    private static function parsePositiveNumber($value): ?float
    {
        if (!is_scalar($value)) return null;
        $normalized = strtr(trim((string) $value), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '،'=>',',
        ]);
        $normalized = str_replace([',', ' '], '', $normalized);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $normalized)) return null;
        $number = (float) $normalized;
        return is_finite($number) && $number > 0 ? $number : null;
    }

    private function failure(string $status, string $message): array
    {
        return [
            'healthy' => false,
            'status' => $status,
            'message' => $message,
            'rates' => [],
            'missing' => array_keys(self::ITEM_MAP),
            'checked_at' => time(),
        ];
    }
}