<?php

declare(strict_types=1);

namespace ArionPay;

/**
 * Verify webhooks from ArionPay.
 *
 *     $event = \ArionPay\Webhook::verify(file_get_contents('php://input'), $_SERVER['HTTP_ARIONPAY_SIGNATURE'] ?? '', $webhookSecret);
 */
final class Webhook
{
    public const EVENT_TYPES = ['invoice.pending', 'invoice.partial', 'invoice.paid', 'invoice.expired', 'invoice.needs_review'];

    /**
     * Check a webhook and return the event. Throws SignatureException when it must not be trusted.
     *
     * $rawBody must be the exact bytes ArionPay sent (file_get_contents('php://input')), not a decoded and re-encoded array.
     *
     * @return array<string,mixed> The event: id, type, created, livemode, test (only on test events), data.invoice.
     * @throws SignatureException
     */
    public static function verify(string $rawBody, ?string $signatureHeader, string $webhookSecret, int $toleranceSeconds = Signature::DEFAULT_TOLERANCE_SECONDS, ?int $now = null): array
    {
        if ($webhookSecret === '') {
            throw new SignatureException('The webhook secret is empty. Copy it from the Webhooks page of your ArionPay dashboard (it starts with whsec_).');
        }
        if (Signature::parseHeader($signatureHeader) === null) {
            throw new SignatureException('Missing or malformed ArionPay-Signature header.');
        }
        if (!Signature::isValidWebhook($rawBody, $signatureHeader, $webhookSecret, $toleranceSeconds, $now)) {
            throw new SignatureException('Invalid webhook signature or stale timestamp. Check the webhook secret and your server clock.');
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event) || !isset($event['id'], $event['type'], $event['data']) || !is_string($event['id']) || $event['id'] === '' || !is_string($event['type'])
            || !is_array($event['data']) || !isset($event['data']['invoice']) || !is_array($event['data']['invoice'])) {
            throw new SignatureException('The webhook body is not an ArionPay event.');
        }
        return $event;
    }

    /**
     * Verify the webhook of the current PHP request (reads php://input and the ArionPay-Signature header).
     *
     * @return array<string,mixed>
     * @throws SignatureException
     */
    public static function fromGlobals(string $webhookSecret, int $maxBytes = 262144): array
    {
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false || strlen($raw) > $maxBytes) {
            throw new SignatureException('The webhook body could not be read or is too large.');
        }
        $header = isset($_SERVER['HTTP_ARIONPAY_SIGNATURE']) ? (string) $_SERVER['HTTP_ARIONPAY_SIGNATURE'] : '';
        return self::verify($raw, $header, $webhookSecret);
    }

    /** True for events made by "Send test webhook": a made-up invoice that must never change an order. */
    public static function isTestEvent(array $event): bool
    {
        $invoiceId = isset($event['data']['invoice']['id']) && is_scalar($event['data']['invoice']['id']) ? (string) $event['data']['invoice']['id'] : '';
        $eventId = isset($event['id']) && is_scalar($event['id']) ? (string) $event['id'] : '';
        return !empty($event['test']) || strpos($eventId, 'evt_test_') === 0 || strpos($invoiceId, 'inv_test_') === 0;
    }
}
