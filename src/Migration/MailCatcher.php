<?php

declare(strict_types=1);

namespace ThreadSafe\Migration;

use InvalidArgumentException;

/**
 * The SMTP server that receives all mail of a dev copy instead of the real recipients (e.g. Mailpit, MailHog).
 */
final readonly class MailCatcher
{
    private const int DEFAULT_PORT = 1025;

    public function __construct(public string $host, public int $port = self::DEFAULT_PORT)
    {
        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?$/', $host) !== 1) {
            throw new InvalidArgumentException("\"$host\" is not a valid mail catcher host name.");
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The mail catcher port must be between 1 and 65535.');
        }
    }

    /**
     * Reads "host" or "host:port" (default port 1025).
     */
    public static function parse(string $value): self
    {
        if (!str_contains($value, ':')) {
            return new self($value);
        }
        [$host, $port] = explode(':', $value, 2);
        if (!ctype_digit($port)) {
            throw new InvalidArgumentException('The mail catcher must be given as HOST or HOST:PORT.');
        }

        return new self($host, (int) $port);
    }

    public function __toString(): string
    {
        return $this->host . ':' . $this->port;
    }
}
