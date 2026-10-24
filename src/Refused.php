<?php
declare(strict_types=1);

namespace MftfFast;

/** A request the instance answered with a failure: what was sent, and what came back. */
final class Refused extends \RuntimeException
{
    /** @param array<string, mixed> $exchange */
    public function __construct(string $message, public readonly array $exchange)
    {
        parent::__construct($message);
    }
}
