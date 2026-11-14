<?php

declare(strict_types=1);

namespace ArionPay;

/**
 * Client for the ArionPay merchant API. Use it on your server only: it holds your secret key.
 *
 *     $arionpay = new \ArionPay\Client(getenv('ARIONPAY_API_KEY'), getenv('ARIONPAY_API_SECRET'));
 *     $invoice  = $arionpay->createInvoice(['amount' => '49.90', 'currency' => 'USD', 'orderId' => '1001']);
 *     header('Location: ' . $invoice['invoiceUrl']);
 *
 * Invoices are arrays with the same field names as the webhook's data.invoice, so one function can handle both.
 */
final class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://merchant.arionpay.com';
    public const SCENARIOS = ['detected', 'confirm', 'exact', 'underpaid_1pct', 'underpaid_5pct', 'overpaid', 'partial_half', 'late'];

    /** Fields createInvoice() passes on (camelCase, as the API names them). */
    private const INVOICE_FIELDS = ['amount', 'currency', 'orderId', 'description', 'customerEmail', 'successUrl', 'cancelUrl', 'callbackUrl', 'asset', 'allowedAssets', 'expiresMin', 'metadata', 'idempotencyKey', 'test'];

    /** @var string */
    private $apiKey;
    /** @var string */
    private $apiSecret;
    /** @var string */
    private $webhookSecret;
    /** @var string scheme://host[:port] */
    private $origin;
    /** @var string path prefix without a trailing slash */
    private $basePath;
    /** @var int */
    private $timeout;
    /** @var int */
    private $maxAttempts;
    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body, int $timeout): array{0:int,1:string} */
    private $transport;
    /** @var string */
    private $userAgent;
    /** @var bool use PHP streams even when cURL is installed */
    private $useStreams;

    /**
     * Options: webhookSecret, baseUrl (default https://merchant.arionpay.com), timeout (seconds, default 20),
     * maxAttempts (default 3), http ('auto' = cURL when installed, or 'streams'), transport (callable, for tests), appInfo.
     *
     * @param array{webhookSecret?:string,baseUrl?:string,timeout?:int,maxAttempts?:int,http?:string,transport?:callable,appInfo?:string} $options
     */
    public function __construct(string $apiKey, string $apiSecret, array $options = [])
    {
        if (!preg_match('/^pk_[A-Za-z0-9_-]{8,64}$/', $apiKey)) {
            throw new \InvalidArgumentException('apiKey must be your store API key (it starts with pk_).');
        }
        if ($apiSecret === '') {
            throw new \InvalidArgumentException('apiSecret is required (your store API secret, it starts with sk_).');
        }
        $parts = parse_url((string) ($options['baseUrl'] ?? self::DEFAULT_BASE_URL));
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new \InvalidArgumentException('baseUrl must be an http(s) address.');
        }
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->webhookSecret = (string) ($options['webhookSecret'] ?? '');
        $this->origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->basePath = rtrim((string) ($parts['path'] ?? ''), '/');
        $this->timeout = max(1, (int) ($options['timeout'] ?? 20));
        $this->maxAttempts = max(1, min(5, (int) ($options['maxAttempts'] ?? 3)));
        $this->transport = $options['transport'] ?? null;
        $this->useStreams = ($options['http'] ?? 'auto') === 'streams';
        $appInfo = isset($options['appInfo']) ? ' ' . $options['appInfo'] : '';
        $this->userAgent = 'arionpay-php/' . self::VERSION . ' php/' . PHP_VERSION . $appInfo;
    }

    /**
     * Create an invoice and get the payment page address (invoiceUrl).
     *
     * Safe to retry: every call carries an idempotency key (a random one when you pass none), so a repeat after a
     * network error returns the same invoice. Pass your own idempotencyKey (e.g. "order-1001-1") to be safe across
     * page reloads. The customer coming back to successUrl does NOT prove payment: wait for the invoice.paid webhook.
     *
     * @param array<string,mixed> $params amount (required), currency, orderId, description, customerEmail, successUrl,
     *                                    cancelUrl, callbackUrl, asset, allowedAssets, expiresMin, metadata, idempotencyKey, test
     * @return array<string,mixed> id, invoiceUrl, status, amountFiat, currency, asset, address, amountCrypto, expiresAt, orderId, raw
     * @throws ApiException
     */
    public function createInvoice(array $params): array
    {
        if (!isset($params['amount']) || is_bool($params['amount']) || !is_scalar($params['amount'])) {
            throw new \InvalidArgumentException('amount is required.');
        }
        $unknown = array_diff(array_keys($params), self::INVOICE_FIELDS);
        if ($unknown) {
            throw new \InvalidArgumentException('Unknown invoice field(s): ' . implode(', ', $unknown) . '. Use camelCase names, e.g. orderId, successUrl.');
        }
        $body = [];
        foreach (self::INVOICE_FIELDS as $field) {
            if (array_key_exists($field, $params) && $params[$field] !== null) {
                $body[$field] = $params[$field];
            }
        }
        $body['amount'] = self::amountString($params['amount']);
        if (empty($body['idempotencyKey'])) {
            $body['idempotencyKey'] = 'sdk_' . bin2hex(random_bytes(16));
        }
        $r = $this->request('POST', '/api/v1/invoices', $body, true);
        $d = isset($r['data']) && is_array($r['data']) ? $r['data'] : $r;
        return [
            'id' => (string) ($d['id'] ?? $r['id'] ?? ''),
            'invoiceUrl' => (string) ($d['invoice_url'] ?? $r['invoice_url'] ?? ''),
            'status' => (string) ($d['status'] ?? 'new'),
            'amountFiat' => $d['amount_fiat'] ?? null,
            'currency' => (string) ($d['currency'] ?? ($body['currency'] ?? 'USD')),
            'asset' => $d['asset'] ?? null,
            'address' => $d['address'] ?? null,
            'amountCrypto' => $d['amount_crypto'] ?? null,
            'expiresAt' => $d['expires_at'] ?? null,
            'orderId' => $d['order_id'] ?? ($body['orderId'] ?? null),
            'raw' => $r,
        ];
    }

    /**
     * Read an invoice, signed, straight from ArionPay. Returns the webhook field names. Use it to double-check a
     * payment, or instead of webhooks (polling).
     *
     * @return array<string,mixed>
     * @throws ApiException
     */
    public function getInvoice(string $invoiceId): array
    {
        self::checkId($invoiceId);
        return self::normalizeInvoiceRead($this->request('GET', '/api/v1/invoices/' . $invoiceId, null, true));
    }

    /**
     * Sandbox: move a TEST invoice through a payment scenario; webhooks then fire as for a real payment.
     * Create the test invoice with 'asset' => 'USDT_TRC20' (or another code) so a coin is chosen.
     *
     * @return array<string,mixed>
     * @throws ApiException
     */
    public function simulatePayment(string $invoiceId, string $scenario = 'exact'): array
    {
        self::checkId($invoiceId);
        if (!in_array($scenario, self::SCENARIOS, true)) {
            throw new \InvalidArgumentException('scenario must be one of: ' . implode(', ', self::SCENARIOS));
        }
        return $this->request('POST', '/api/v1/invoices/' . $invoiceId . '/simulate', ['scenario' => $scenario], false);
    }

    /**
     * Ask ArionPay to send a signed sample event to your store's webhook address. It never describes a real order.
     *
     * @return array<string,mixed> delivered, httpStatus, eventId, ...
     * @throws ApiException
     */
    public function sendTestWebhook(string $eventType = 'invoice.paid'): array
    {
        return $this->request('POST', '/api/v1/webhooks/test', ['eventType' => $eventType], false);
    }

    /**
     * Coins you can pass as asset / allowedAssets (e.g. USDT_TRC20, BTC, LTC), with USD rates.
     *
     * @return array<int,array<string,mixed>>
     * @throws ApiException
     */
    public function currencies(): array
    {
        $r = $this->request('GET', '/api/v1/currencies', null, true, false);
        return isset($r['currencies']) && is_array($r['currencies']) ? $r['currencies'] : [];
    }

    /**
     * Current coin prices in one fiat currency.
     *
     * @return array<string,mixed>
     * @throws ApiException
     */
    public function rates(string $currency = 'USD'): array
    {
        return $this->request('GET', '/api/v1/rates?currency=' . rawurlencode($currency), null, true, false);
    }

    /**
     * Check a webhook with the webhookSecret given in the options. See Webhook::verify().
     *
     * @return array<string,mixed>
     * @throws SignatureException
     */
    public function verifyWebhook(string $rawBody, ?string $signatureHeader, int $toleranceSeconds = Signature::DEFAULT_TOLERANCE_SECONDS): array
    {
        return Webhook::verify($rawBody, $signatureHeader, $this->webhookSecret, $toleranceSeconds);
    }

    /**
     * Turn the signed read (GET /api/v1/invoices/<id>) into the webhook invoice field names.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function normalizeInvoiceRead(array $r): array
    {
        $out = [
            'id' => (string) ($r['invoiceId'] ?? $r['id'] ?? ''),
            'status' => strtolower((string) ($r['status'] ?? '')),
            'orderId' => $r['orderId'] ?? null,
            'amountFiat' => $r['amountFiat'] ?? null,
            'currency' => (string) ($r['currency'] ?? ''),
            'asset' => (string) ($r['settledCurrency'] ?? ''),
            'network' => (string) ($r['network'] ?? ''),
            'amountCrypto' => (string) ($r['amountExpected'] ?? ''),
            'paidCrypto' => (string) ($r['amountReceived'] ?? '0'),
            'confirmedCrypto' => (string) ($r['amountConfirmed'] ?? '0'),
            'overpaid' => ($r['overpaid'] ?? false) === true,
            'underpaidWithinTolerance' => ($r['underpaidWithinTolerance'] ?? false) === true,
            'late' => ($r['late'] ?? false) === true,
            'needsReview' => ($r['needsReview'] ?? false) === true,
            'manual' => ($r['manual'] ?? false) === true,
            'test' => ($r['test'] ?? false) === true,
            'transactions' => isset($r['transactions']) && is_array($r['transactions']) ? $r['transactions'] : [],
            'metadata' => isset($r['metadata']) && is_array($r['metadata']) ? $r['metadata'] : [],
            'paidAt' => $r['settledAt'] ?? null,
            'txHash' => $r['txHash'] ?? null,
            'confirmations' => $r['confirmations'] ?? 0,
            'requiredConfirmations' => $r['requiredConfirmations'] ?? 0,
            'raw' => $r,
        ];
        if (array_key_exists('paidVia', $r)) {
            $out['paidVia'] = $r['paidVia'];
        }
        return $out;
    }

    // -----------------------------------------------------------------------------------------------------------

    /** @param mixed $amount */
    private static function amountString($amount): string
    {
        if (is_int($amount)) {
            return (string) $amount;
        }
        if (is_float($amount)) {
            $s = rtrim(rtrim(number_format($amount, 8, '.', ''), '0'), '.');
            return $s === '' ? '0' : $s;
        }
        return trim((string) $amount);
    }

    /** @param resource|\CurlHandle $ch */
    private static function closeCurl($ch): void
    {
        // PHP 8 frees the handle by itself, and PHP 8.5 deprecates curl_close().
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
    }

    private static function checkId(string $id): void
    {
        if (!preg_match('/^[0-9a-fA-F]{24}$/', $id)) {
            throw new \InvalidArgumentException('Invoice id must be the 24-character id ArionPay returned.');
        }
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     * @throws ApiException
     */
    private function request(string $method, string $path, ?array $body, bool $retrySafe, bool $signed = true): array
    {
        $raw = null;
        if ($body !== null) {
            $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($raw === false) {
                throw new \InvalidArgumentException('The request could not be encoded as JSON: ' . json_last_error_msg());
            }
        }
        $replayRetryUsed = false;
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->send($method, $path, $raw, $signed);
            } catch (ApiException $e) {
                // Two identical signed requests in the same second have the same signature; only the first is accepted.
                if ($e->getStatus() === 401 && stripos($e->getMessage(), 'already used') !== false && !$replayRetryUsed) {
                    $replayRetryUsed = true;
                    usleep(1100000);
                    continue;
                }
                $retryAfter = (int) ($e->getBody()['retryAfterSec'] ?? 0);
                $temporary = in_array($e->getStatus(), [0, 502, 503, 504], true) || ($e->getStatus() === 429 && $retryAfter <= 5);
                if (!$retrySafe || !$temporary || $attempt >= $this->maxAttempts) {
                    throw $e;
                }
                usleep($e->getStatus() === 429 ? max(1, $retryAfter) * 1000000 : (int) (500000 * (3 ** ($attempt - 1))));
            }
        }
    }

    /**
     * @return array<string,mixed>
     * @throws ApiException
     */
    private function send(string $method, string $path, ?string $raw, bool $signed): array
    {
        $fullPath = $this->basePath . $path;
        $headers = ['Accept' => 'application/json', 'User-Agent' => $this->userAgent];
        if ($signed) {
            $ts = time();
            $headers['x-api-key'] = $this->apiKey;
            $headers['x-timestamp'] = (string) $ts;
            $headers['x-signature'] = Signature::signRequest($this->apiSecret, $ts, $method, $fullPath, (string) $raw);
        }
        if ($raw !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        [$status, $text, $retryAfter] = $this->transport !== null
            ? self::callCustom($this->transport, $method, $this->origin . $fullPath, $headers, $raw, $this->timeout)
            : self::httpRequest($method, $this->origin . $fullPath, $headers, $raw, $this->timeout, $this->useStreams);

        $decoded = $text !== '' ? json_decode($text, true) : [];
        $obj = is_array($decoded) ? $decoded : [];
        if ($status < 200 || $status >= 300) {
            $message = isset($obj['error']) && is_string($obj['error']) ? $obj['error'] : (isset($obj['message']) && is_string($obj['message']) ? $obj['message'] : 'HTTP ' . $status);
            if ($retryAfter > 0) {
                $obj['retryAfterSec'] = $retryAfter;
            }
            throw new ApiException('ArionPay API error (' . $status . '): ' . $message, $status, isset($obj['code']) && is_string($obj['code']) ? $obj['code'] : '', $obj);
        }
        return $obj;
    }

    /**
     * @param array<string,string> $headers
     * @return array{0:int,1:string,2:int}
     * @throws ApiException
     */
    private static function callCustom(callable $transport, string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        try {
            $r = $transport($method, $url, $headers, $body, $timeout);
        } catch (\Throwable $e) {
            throw new ApiException('Could not reach ArionPay: ' . $e->getMessage(), 0, 'network_error');
        }
        return [(int) $r[0], (string) $r[1], (int) ($r[2] ?? 0)];
    }

    /**
     * HTTP with cURL when available, otherwise PHP streams. Redirects are never followed.
     *
     * @param array<string,string> $headers
     * @return array{0:int,1:string,2:int} status, body, Retry-After seconds
     * @throws ApiException
     */
    private static function httpRequest(string $method, string $url, array $headers, ?string $body, int $timeout, bool $useStreams): array
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        if (!$useStreams && function_exists('curl_init')) {
            $retryAfter = 0;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                    if (stripos($line, 'retry-after:') === 0) {
                        $retryAfter = (int) trim(substr($line, 12));
                    }
                    return strlen($line);
                },
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $text = curl_exec($ch);
            if ($text === false) {
                $err = curl_error($ch);
                self::closeCurl($ch);
                throw new ApiException('Could not reach ArionPay: ' . $err, 0, 'network_error');
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            self::closeCurl($ch);
            return [$status, (string) $text, $retryAfter];
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $lines),
                'content' => $body ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);
        $text = @file_get_contents($url, false, $context);
        $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        if ($text === false || !$responseHeaders) {
            $err = error_get_last();
            throw new ApiException('Could not reach ArionPay: ' . ($err['message'] ?? 'connection failed'), 0, 'network_error');
        }
        $status = 0;
        $retryAfter = 0;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($line, 'retry-after:') === 0) {
                $retryAfter = (int) trim(substr($line, 12));
            }
        }
        return [$status, (string) $text, $retryAfter];
    }
}
