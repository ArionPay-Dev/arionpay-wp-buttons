<?php

declare(strict_types=1);

namespace ArionPay;

/**
 * Request and webhook signatures, matching the ArionPay backend.
 *
 * Signed API requests:
 *   POST: x-signature = hex HMAC-SHA256(secret key, "<timestamp>.<raw body>")
 *   GET:  x-signature = hex HMAC-SHA256(secret key, "<timestamp>.GET <path and query>")
 *
 * Webhooks:
 *   ArionPay-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(webhook secret, "<t>.<raw body>")>
 */
final class Signature
{
    /** Webhooks signed more than this many seconds ago (or ahead) are refused. ArionPay signs again on every retry. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    public static function signRequest(string $secretKey, int $timestamp, string $method, string $pathAndQuery, string $rawBody = ''): string
    {
        $data = strtoupper($method) === 'GET' ? $timestamp . '.GET ' . $pathAndQuery : $timestamp . '.' . $rawBody;
        return hash_hmac('sha256', $data, $secretKey);
    }

    /**
     * Read an ArionPay-Signature header.
     *
     * @return array{t:int,v1:string}|null Null when the header is malformed.
     */
    public static function parseHeader(?string $header): ?array
    {
        $values = [];
        foreach (explode(',', (string) $header) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $values[trim($pair[0])] = trim($pair[1]);
            }
        }
        $t = $values['t'] ?? '';
        $v1 = $values['v1'] ?? '';
        if (!preg_match('/^[0-9]{1,12}$/', $t) || !preg_match('/^[0-9a-fA-F]{64}$/', $v1)) {
            return null;
        }
        return ['t' => (int) $t, 'v1' => strtolower($v1)];
    }

    /** True when the header is a valid, fresh signature of exactly this body. Never throws. */
    public static function isValidWebhook(string $rawBody, ?string $header, string $webhookSecret, int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS, ?int $now = null): bool
    {
        if ($webhookSecret === '') {
            return false;
        }
        $parsed = self::parseHeader($header);
        if ($parsed === null) {
            return false;
        }
        $now = $now ?? time();
        if (abs($now - $parsed['t']) > $toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $parsed['t'] . '.' . $rawBody, $webhookSecret);
        return hash_equals($expected, $parsed['v1']);
    }
}
