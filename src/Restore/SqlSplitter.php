<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use RuntimeException;

/**
 * Cuts a SQL file into statements, the way the `mysql` client does, with constant memory (the file is read in blocks, only
 * a statement is held completely).
 *
 * It understands `'...'` and `"..."` strings with backslash escapes and doubled quotes, `` `...` `` names, `-- `, `#` and
 * slash-star comments (so a delimiter inside any of them does not end a statement), and the client's `DELIMITER` command, which
 * the dump uses for triggers. It does not know SQL beyond that: a statement is passed on as it is.
 *
 * Resumable: every statement carries the offset after it and the delimiter in effect, and a splitter created with both
 * continues exactly there.
 *
 * Found with the spike `tools/spikes/sql-import.php` (docs/PHASE3.md, section 8): a token cut by the end of a block has to wait
 * for the next block (no stepping back, the position could then fall inside a string), `--` is a comment only before a blank,
 * and a string of several MB needs a high `pcre.backtrack_limit`.
 */
final class SqlSplitter
{
    public const int BLOCK = 1048576;

    /** @var resource */
    private $handle;

    private string $buffer = '';

    private int $position = 0;      // scan position in the buffer
    private int $start = 0;         // start of the current statement in the buffer
    private int $consumed;          // bytes of the file before the buffer
    private bool $eof = false;
    private string $pattern;
    private bool $noJit = false;

    /**
     * @throws RuntimeException the file cannot be opened
     */
    public function __construct(
        private readonly string $path,
        int $offset = 0,
        private string $delimiter = ';',
        private readonly int $block = self::BLOCK,
    ) {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open \"$path\".");
        }
        $this->handle = $handle;
        if ($offset > 0 && fseek($handle, $offset) !== 0) {
            fclose($handle);

            throw new RuntimeException("Cannot seek to byte $offset in \"$path\".");
        }
        $this->consumed = $offset;
        $this->pattern = self::ordinaryRun($delimiter[0] ?? ';');
        // A string literal with many escapes is a single long match; the default limit of 1,000,000 steps is far too low for it.
        @ini_set('pcre.backtrack_limit', '1000000000');
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /** The delimiter currently in effect. */
    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * The next statement, or null at the end of the file.
     *
     * @throws RuntimeException an unterminated string or comment at the end of the file, or the regular expression fails
     */
    public function next(): ?SqlStatement
    {
        while (true) {
            // a DELIMITER command at the start of a statement; the line it is on must be in the buffer completely
            if ($this->position === $this->start) {
                $head = ltrim(substr($this->buffer, $this->start, 300));
                if (!$this->eof && !str_contains($head, "\n") && ($head === '' || stripos('DELIMITER', $head) === 0 || stripos($head, 'DELIMITER') === 0)) {
                    $this->fill();
                    continue;
                }
                if (preg_match('~\G\s*DELIMITER[ \t]+(\S+)[^\n]*\n~i', $this->buffer, $m, 0, $this->start) === 1) {
                    $this->delimiter = $m[1];
                    $this->pattern = self::ordinaryRun($this->delimiter[0]);
                    $this->start = $this->position = $this->start + strlen($m[0]);
                    continue;
                }
            }

            $this->position += strlen($this->match($this->pattern, $this->buffer, $this->position));
            $length = strlen($this->delimiter);
            $size = strlen($this->buffer);

            if ($this->position + $length <= $size && substr($this->buffer, $this->position, $length) === $this->delimiter) {
                $text = trim(substr($this->buffer, $this->start, $this->position - $this->start));
                $this->position += $length;
                $this->start = $this->position;
                if ($text !== '' && !self::onlyComments($text)) {
                    return new SqlStatement($text, $this->consumed + $this->position, $this->delimiter);
                }
                continue;
            }
            if ($this->position < $size && $this->position + $length <= $size && $this->buffer[$this->position] === $this->delimiter[0]) {
                ++$this->position;    // the first character of the delimiter, but not the delimiter

                continue;
            }

            // the buffer is used up, or a token (string, comment, delimiter) is cut by its end
            if (!$this->eof) {
                $this->fill();

                continue;
            }
            if ($this->position < $size && !str_ends_with($this->buffer, "\n") && preg_match('~(?:--[ \t\r]?|#)[^\n]*\z~', substr($this->buffer, $this->position)) === 1) {
                $this->buffer .= "\n";    // a comment on the last line, without a line end

                continue;
            }
            if ($this->position < $size && in_array($this->buffer[$this->position], ['-', '/'], true)) {
                ++$this->position;    // a lone "-" or "/" at the very end is ordinary text

                continue;
            }
            if ($this->position < $size) {
                throw new RuntimeException(sprintf(
                    'Unterminated string or comment near byte %d of "%s": %s',
                    $this->consumed + $this->position,
                    $this->path,
                    substr($this->buffer, $this->position, 30),
                ));
            }
            $rest = trim(substr($this->buffer, $this->start));
            $this->start = $this->position = $size;
            if ($rest !== '' && !self::onlyComments($rest)) {
                return new SqlStatement($rest, $this->consumed + $size, $this->delimiter);
            }
            $this->close();

            return null;
        }
    }

    /** Reads the next block and drops what is behind the start of the current statement. */
    private function fill(): void
    {
        $chunk = (string) fread($this->handle, max(1, $this->block));
        $this->eof = $chunk === '' || feof($this->handle);
        $this->buffer = substr($this->buffer, $this->start) . $chunk;
        $this->consumed += $this->start;
        $this->position -= $this->start;
        $this->start = 0;
    }

    private function match(string $pattern, string $subject, int $offset): string
    {
        $result = preg_match($this->noJit ? '~(*NO_JIT)' . substr($pattern, 1) : $pattern, $subject, $m, 0, $offset);
        if ($result === false && !$this->noJit && preg_last_error() === PREG_JIT_STACKLIMIT_ERROR) {
            $this->noJit = true;    // a very long string overflows the JIT stack: the interpreter has no such limit

            return $this->match($pattern, $subject, $offset);
        }
        if ($result === false) {
            throw new RuntimeException('Cannot split the SQL file "' . $this->path . '": ' . preg_last_error_msg());
        }

        return $m[0];
    }

    /** A statement that consists of comments only is not a statement. */
    private static function onlyComments(string $text): bool
    {
        return preg_match('~^(?:--[^\n]*\n?|#[^\n]*\n?|/\*(?!!).*?\*/|\s+)*$~s', $text) === 1;
    }

    /**
     * The regular expression for a run of ordinary characters, whole string literals, quoted names and comments - up to the
     * first character of the delimiter, or the end of the data.
     */
    private static function ordinaryRun(string $delimiterFirst): string
    {
        $d = preg_quote($delimiterFirst, '~');

        return '~\G(?:[^\'"`#/\-' . $d . ']++|\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+"|`[^`]*+`|--(?:[ \t\r][^\n]*+)?\n|#[^\n]*+\n|/\*.*?\*/|--(?=[^ \t\r\n])|-(?=[^-])|/(?=[^*]))*+~s';
    }
}
