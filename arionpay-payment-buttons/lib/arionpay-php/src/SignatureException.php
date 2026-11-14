<?php

declare(strict_types=1);

namespace ArionPay;

/** A webhook that must not be trusted: bad or missing signature, stale timestamp, or a body that is not an event. */
class SignatureException extends \RuntimeException
{
}
