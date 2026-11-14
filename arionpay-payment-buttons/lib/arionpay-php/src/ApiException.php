<?php

declare(strict_types=1);

namespace ArionPay;

/** Any failure talking to the ArionPay API. The message never contains your secret key. */
class ApiException extends \RuntimeException
{
    /** @var int HTTP status, 0 when ArionPay could not be reached. */
    private $status;

    /** @var string Machine-readable code when the API sent one, e.g. "idempotency_key_reused", "rate_limited". */
    private $apiCode;

    /** @var array<string,mixed> Decoded error body. */
    private $body;

    /** @param array<string,mixed> $body */
    public function __construct(string $message, int $status = 0, string $apiCode = '', array $body = [])
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->apiCode = $apiCode;
        $this->body = $body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getApiCode(): string
    {
        return $this->apiCode;
    }

    /** @return array<string,mixed> */
    public function getBody(): array
    {
        return $this->body;
    }
}
