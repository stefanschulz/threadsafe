<?php

declare(strict_types=1);

namespace ThreadSafe\Storage;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What a {@see Postproc} did with a finished backup.
 */
final readonly class TransferResult
{
    public function __construct(public string $target, public bool $success, public string $message, public DateTimeImmutable $time)
    {
    }

    public static function now(string $target, bool $success, string $message): self
    {
        return new self($target, $success, $message, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * The record for the `transfers` list of the sidecar file.
     *
     * @return array{target: string, time: string, success: bool, message: string}
     */
    public function toArray(): array
    {
        return ['target' => $this->target, 'time' => $this->time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'success' => $this->success, 'message' => $this->message];
    }
}
