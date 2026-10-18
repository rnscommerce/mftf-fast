<?php
declare(strict_types=1);

namespace MftfFast;

/** A form post MFTF sent whose page did not match the operation's successRegex: what was sent, and the page that came back. */
final class NotCreated extends \RuntimeException
{
    /** @param array<string, mixed> $exchange */
    public function __construct(string $message, public readonly array $exchange)
    {
        parent::__construct($message);
    }
}
