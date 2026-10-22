<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * One recipient line of an `age` header: `-> type arg arg`, then the body in base64 lines of 64 characters. The last line of a body is
 * shorter than 64 characters, and is empty when the body is a multiple of 48 bytes.
 */
final readonly class Stanza
{
    /**
     * @param list<string> $arguments
     * @param string       $body      raw bytes
     */
    public function __construct(public string $type, public array $arguments, public string $body)
    {
    }

    public function toText(): string
    {
        $text = '-> ' . implode(' ', [$this->type, ...$this->arguments]) . "\n";
        $encoded = Base64::encode($this->body);
        foreach (str_split($encoded, 64) as $line) {
            $text .= $line . "\n";
        }
        if ($encoded === '' || strlen($encoded) % 64 === 0) {
            $text .= "\n";
        }

        return $text;
    }
}
