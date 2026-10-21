<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use JsonException;

/**
 * An HTTP response as data: status, headers, body. {@see send()} puts it on the wire.
 */
final readonly class Response
{
    /**
     * @param list<string> $headers complete header lines, e.g. "Content-Type: text/html"
     */
    public function __construct(public int $status, public array $headers, public string $body)
    {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type: text/html; charset=utf-8'], $body);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        try {
            $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $body = '{"status":"failed","error":"The answer cannot be encoded."}';
        }

        return new self($status, ['Content-Type: application/json; charset=utf-8'], $body);
    }

    public static function redirect(string $location): self
    {
        return new self(303, ['Location: ' . $location], '');
    }

    public function withHeader(string $line): self
    {
        return new self($this->status, [...$this->headers, $line], $this->body);
    }

    /** The value of the first header of this name, or null. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $line) {
            if (strncasecmp($line, $name . ':', strlen($name) + 1) === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }

    /**
     * Values of all headers of this name (a response may set several cookies).
     *
     * @return list<string>
     */
    public function headers(string $name): array
    {
        $values = [];
        foreach ($this->headers as $line) {
            if (strncasecmp($line, $name . ':', strlen($name) + 1) === 0) {
                $values[] = trim(substr($line, strlen($name) + 1));
            }
        }

        return $values;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $line) {
            header($line, false);
        }
        echo $this->body;
    }
}
