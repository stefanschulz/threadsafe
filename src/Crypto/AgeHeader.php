<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * The header of an `age` file (age-encryption.org/v1): the line `age-encryption.org/v1`, one stanza per recipient, and the line
 * `--- <MAC>` whose MAC (HMAC-SHA-256 under a key made from the file key) covers everything before it, so that a header that was
 * changed does not open.
 */
final readonly class AgeHeader
{
    public const string MAGIC = 'age-encryption.org/v1';

    /** No header is longer than this; a file whose header goes on is not an `age` file. */
    public const int MAX_LENGTH = 1048576;

    /**
     * @param list<Stanza> $stanzas
     * @param string       $macInput the bytes the MAC covers
     * @param string       $mac      raw 32 bytes
     * @param int          $length   the length of the header in bytes, the last line break included
     */
    private function __construct(public array $stanzas, public string $macInput, public string $mac, public int $length)
    {
    }

    /**
     * The text of a header for a file key and its stanzas.
     *
     * @param list<Stanza> $stanzas
     */
    public static function build(array $stanzas, string $fileKey): string
    {
        $text = self::MAGIC . "\n";
        foreach ($stanzas as $stanza) {
            $text .= $stanza->toText();
        }
        $text .= '---';

        return $text . ' ' . Base64::encode(self::mac($fileKey, $text)) . "\n";
    }

    /** Whether the bytes hold a whole header (the line of the MAC and its line break are there). */
    public static function isComplete(string $data): bool
    {
        $end = strpos($data, "\n--- ");

        return $end !== false && strpos($data, "\n", $end + 1) !== false;
    }

    /**
     * @throws CryptoException the bytes do not start with a header of the format, or it is cut off
     */
    public static function parse(string $data): self
    {
        if (!str_starts_with($data, self::MAGIC . "\n")) {
            throw CryptoException::malformed('This is not a file of the age format (its first line is not "' . self::MAGIC . '").');
        }
        if (!self::isComplete($data)) {
            throw CryptoException::damaged('The file ends inside its header.');
        }
        $end = (int) strpos($data, "\n--- ");
        $lineEnd = (int) strpos($data, "\n", $end + 1);
        if (preg_match('/\A[\x20-\x7e\n]*\z/', substr($data, 0, $lineEnd + 1)) !== 1) {
            throw CryptoException::malformed('The header of the file holds a character that does not belong there.');
        }
        $between = $end > strlen(self::MAGIC) ? substr($data, strlen(self::MAGIC) + 1, $end - strlen(self::MAGIC) - 1) : '';   // everything between the first line and the line break before "---"
        $lines = $between === '' ? [] : explode("\n", $between);
        $footer = substr($data, $end + 5, $lineEnd - $end - 5);

        $stanzas = [];
        $current = null;
        $body = '';
        $open = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, '-> ')) {
                if ($open) {
                    throw CryptoException::malformed('The header of the file is malformed (a key line has no short last line).');
                }
                if ($current !== null) {
                    $stanzas[] = new Stanza($current[0], $current[1], Base64::decode($body));
                }
                $words = explode(' ', substr($line, 3));
                if (in_array('', $words, true) || preg_match('/[^\x21-\x7e]/', implode('', $words)) === 1) {
                    throw CryptoException::malformed('A line of the header of the file is malformed.');
                }
                $current = [array_shift($words), $words];
                $body = '';
                $open = true;

                continue;
            }
            if ($current === null) {
                if ($line === '') {
                    continue;
                }
                throw CryptoException::malformed('The header of the file has a line that belongs to no key.');
            }
            if (!$open || strlen($line) > 64) {
                throw CryptoException::malformed('The header of the file is malformed (a line of a key is too long).');
            }
            $body .= $line;
            if (strlen($line) < 64) {
                $open = false;
            }
        }
        if ($open) {
            throw CryptoException::malformed('The header of the file is malformed (a key line has no short last line).');
        }
        if ($current !== null) {
            $stanzas[] = new Stanza($current[0], $current[1], Base64::decode($body));
        }
        $mac = Base64::decode($footer);
        if (strlen($mac) !== 32) {
            throw CryptoException::malformed('The header of the file ends with a checksum of the wrong length.');
        }

        return new self($stanzas, substr($data, 0, $end + 4), $mac, $lineEnd + 1);
    }

    /**
     * @throws CryptoException the header was changed, or the file key is not the one it was made with
     */
    public function verify(string $fileKey): void
    {
        if (!hash_equals(self::mac($fileKey, $this->macInput), $this->mac)) {
            throw CryptoException::damaged('The header of the file does not match its checksum: the file was changed, or damaged.');
        }
    }

    private static function mac(string $fileKey, string $input): string
    {
        return hash_hmac('sha256', $input, hash_hkdf('sha256', $fileKey, 32, 'header'), true);
    }
}
