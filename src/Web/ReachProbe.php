<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

/**
 * The test that a directory of a single-file tool cannot be fetched through the web server (docs/PHASE12.md 3.4): a small file with a random name
 * and a token is put into each directory, and a few lines of script in the page ask the web server for them. A web server that hands one out would
 * hand out what else is in that directory (the archive, the unpacked database, the profile with its password). The server cannot ask for itself
 * (the host may forbid it, and its own name may not lead back to it), so the browser of the person does, and tells the tool what it found.
 */
final class ReachProbe
{
    public const array RESULTS = ['closed', 'reachable', 'unknown'];

    /**
     * @param string $baseDirectory the directory of the tool: the addresses are written relative to it
     */
    public function __construct(private readonly string $baseDirectory)
    {
    }

    /**
     * What the session remembers about the probe, with the files in place: the record of the session when all of them are still there, otherwise
     * a new record (a new name and token, no result) after the old files are removed.
     *
     * @param array<string, mixed> $record      what the session remembered: file, token, result
     * @param list<string>         $directories the directories that get a file
     *
     * @return array{file: string, token: string, result: string}
     */
    public function prepare(array $record, array $directories): array
    {
        $file = is_string($record['file'] ?? null) ? $record['file'] : '';
        $token = is_string($record['token'] ?? null) ? $record['token'] : '';
        $complete = $file !== '' && $token !== '';
        foreach ($directories as $directory) {
            $complete = $complete && is_file($directory . '/' . $file);
        }
        if ($complete) {
            return ['file' => $file, 'token' => $token, 'result' => is_string($record['result'] ?? null) ? $record['result'] : ''];
        }

        $this->remove($record, $directories);
        $file = 'probe-' . bin2hex(random_bytes(8)) . '.txt';
        $token = bin2hex(random_bytes(16));
        foreach ($directories as $directory) {
            file_put_contents($directory . '/' . $file, 'ThreadSafe probe ' . $token);
        }

        return ['file' => $file, 'token' => $token, 'result' => ''];
    }

    /**
     * Deletes the files of a record (only a name that this class made).
     *
     * @param array<string, mixed> $record
     * @param list<string>         $directories
     */
    public function remove(array $record, array $directories): void
    {
        $file = $record['file'] ?? null;
        if (!is_string($file) || preg_match('/^probe-[0-9a-f]{16}\.txt$/', $file) !== 1) {
            return;
        }
        foreach ($directories as $directory) {
            @unlink($directory . '/' . $file);
        }
    }

    /**
     * The addresses of the files as the page of the tool would write them.
     *
     * @param list<string> $directories
     *
     * @return list<string>
     */
    public function urls(string $file, array $directories): array
    {
        return array_map(fn (string $directory): string => self::relativeUrl($this->baseDirectory, $directory . '/' . $file), $directories);
    }

    /**
     * The address of a file as the page of the tool would write it: relative to the directory of the tool, with `..` where the file is above it.
     */
    public static function relativeUrl(string $fromDirectory, string $file): string
    {
        $from = array_values(array_filter(explode('/', str_replace('\\', '/', realpath($fromDirectory) ?: $fromDirectory)), static fn (string $s): bool => $s !== ''));
        $to = array_values(array_filter(explode('/', str_replace('\\', '/', (realpath(dirname($file)) ?: dirname($file)) . '/' . basename($file))), static fn (string $s): bool => $s !== ''));
        $common = 0;
        while ($common < count($from) && $common < count($to) - 1 && $from[$common] === $to[$common]) {
            ++$common;
        }
        $parts = [...array_fill(0, count($from) - $common, '..'), ...array_map(rawurlencode(...), array_slice($to, $common))];

        return implode('/', $parts);
    }

    /**
     * The script of the page: it asks for every address, shows the answer in the element `probe-result`, hides the element `probe-tick` (if the page has one)
     * when all are closed, switches the button `start` off when one is open, and tells the tool (a POST of `action=probe`).
     *
     * @param list<string>                                              $urls
     * @param array{closed: string, reachable: string, unknown: string} $texts what the page says for each result
     */
    public function script(string $csrf, array $urls, string $token, array $texts): string
    {
        $urls = json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?: '[]';
        $token = json_encode($token) ?: '""';
        $csrf = json_encode($csrf) ?: '""';
        $texts = json_encode($texts, JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}';

        return "(function () {\n"
            . "  var out = document.getElementById('probe-result'), tick = document.getElementById('probe-tick'), start = document.getElementById('start');\n"
            . "  var texts = $texts, csrf = $csrf, token = $token, urls = $urls;\n"
            . "  function report(result) {\n"
            . "    out.textContent = texts[result]; out.className = result === 'reachable' ? 'fail' : '';\n"
            . "    if (tick) { tick.hidden = result === 'closed'; }\n"
            . "    start.disabled = result === 'reachable';\n"
            . "    fetch('?', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'csrf=' + encodeURIComponent(csrf) + '&action=probe&result=' + result}).catch(function () {});\n"
            . "  }\n"
            . "  Promise.all(urls.map(function (url) {\n"
            . "    return fetch(url, {credentials: 'omit', cache: 'no-store'}).then(function (r) { return r.ok ? r.text() : ''; })\n"
            . "      .then(function (t) { return t.indexOf(token) >= 0; }).catch(function () { return false; });\n"
            . "  })).then(function (found) { report(found.indexOf(true) >= 0 ? 'reachable' : 'closed'); });\n"
            . '})();';
    }
}
