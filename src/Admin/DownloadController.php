<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\App\CatalogService;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Storage\BackupEntry;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Storage\StorageException;
use ThreadSafe\Web\Download;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * Downloading a backup in the browser (docs/PHASE4.md 3.6). It is a convenience, not a reliable transport for large archives, and the
 * page says so: above a size it recommends the server's own file access and gives the path, a command and the SHA-256 to compare.
 *
 * The storage is never served by path. The page where a part is chosen makes a **token** (random, kept in the session, valid for two
 * hours for that one part, gone when the person logs out); the token is what the address of the file carries. It can be used again
 * and again, because a download that broke is resumed with a second request for the same file.
 */
final class DownloadController
{
    private const int LIFETIME = 7200;

    private const int MAX_TOKENS = 30;

    private ?CatalogService $catalog;

    /**
     * @param CatalogService|null $catalog for tests; by default the service on the data directory
     */
    public function __construct(private readonly Services $services, ?CatalogService $catalog = null)
    {
        $this->catalog = $catalog;
    }

    /**
     * The page where a part is chosen, with the warning for large files.
     *
     * @param array<string, string> $params
     */
    public function dialog(Context $context, array $params): Response
    {
        $entry = $this->entry($params);
        if ($entry === null || $entry->sidecar === null || $entry->condition === BackupEntry::UNFINISHED || $entry->condition === BackupEntry::UNKNOWN) {
            return $this->missing($context);
        }
        $profileId = $params['id'] ?? '';
        $base = $entry->name->base();
        $threshold = $this->services->config->downloadWarnAbove();
        $directory = $this->service()->storageDirectory($profileId);

        $rows = '';
        $large = false;
        foreach ($entry->sidecar->parts as $part) {
            $large = $large || $part->size > $threshold;
            $rows .= '<tr><td><code>' . Html::e($part->file) . '</code></td><td>' . Html::e(SpaceCheck::format($part->size)) . '</td><td><code>' . Html::e($part->sha256) . '</code></td>'
                . '<td>' . View::form($context, "/profiles/$profileId/backups/$base/download", '<input type="hidden" name="part" value="' . Html::e($part->file) . '"><button type="submit">Download</button>', 'inline') . '</td></tr>';
        }

        $body = '<p>Backup <strong>' . Html::e($base) . '</strong>, ' . Html::e(SpaceCheck::format($entry->sidecar->size())) . (count($entry->sidecar->parts) > 1 ? ' in ' . count($entry->sidecar->parts) . ' parts. Download every part and keep them together.' : '.') . '</p>'
            . ($large ? $this->warning($entry, $directory) : '')
            . '<div class="scroll"><table><thead><tr><th>File</th><th>Size</th><th>SHA-256</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . ($entry->sidecar->encryption !== null ? '<p class="hint"><strong>Encrypted.</strong> The files are sent as they are on the server: only the key (or the passphrase) opens them, here or anywhere else. Keep it apart from the backup.</p>' : '')
            . '<p class="hint">After a download, compare its checksum with the one above: <code>sha256sum FILE</code> (Linux, macOS), <code>certutil -hashfile FILE SHA256</code> (Windows). A download that broke is resumed by most browsers and by <code>curl -C -</code>.</p>'
            . '<p><a href="' . Html::e($context->urls->to("/profiles/$profileId/backups/$base")) . '">Back to the backup</a></p>';

        return View::page($context, 'Download ' . $base, $body, '/profiles');
    }

    /**
     * Makes the token for one part and sends the browser to the file.
     *
     * @param array<string, string> $params
     */
    public function issue(Context $context, array $params): Response
    {
        $profileId = $params['id'] ?? '';
        $name = BackupName::parse($params['name'] ?? '');
        $file = (string) $context->request->post('part');
        if ($name === null) {
            return $this->missing($context);
        }
        try {
            $this->service()->part($profileId, $name->base(), $file);
        } catch (ProfileException | StorageException) {
            return $this->missing($context);
        }

        $now = ($this->services->clock)();
        $tokens = [];
        foreach (is_array($context->session['downloads'] ?? null) ? $context->session['downloads'] : [] as $token => $data) {
            if (is_string($token) && is_array($data) && is_int($data['expires'] ?? null) && $data['expires'] > $now) {
                $tokens[$token] = $data;
            }
        }
        $tokens = array_slice($tokens, -(self::MAX_TOKENS - 1), null, true);
        $token = bin2hex(random_bytes(24));
        $tokens[$token] = ['profile' => $profileId, 'backup' => $name->base(), 'file' => $file, 'expires' => $now + self::LIFETIME];
        $context->session['downloads'] = $tokens;

        return Response::redirect($context->urls->to("/download/$token/$file"));
    }

    /**
     * The file itself.
     *
     * @param array<string, string> $params `hex` (the token) and `name` (the file)
     */
    public function file(Context $context, array $params): Response
    {
        $token = $params['hex'] ?? '';
        $file = $params['name'] ?? '';
        $data = $context->session['downloads'][$token] ?? null;
        if (!is_array($data) || ($data['expires'] ?? 0) <= ($this->services->clock)() || ($data['file'] ?? null) !== $file) {
            return new Response(404, ['Content-Type: text/plain; charset=utf-8'], "Not found.\n");
        }
        try {
            $part = $this->service()->part((string) $data['profile'], (string) $data['backup'], $file);
        } catch (ProfileException | StorageException) {
            return new Response(404, ['Content-Type: text/plain; charset=utf-8'], "Not found.\n");
        }

        $user = $context->user() ?? '-';
        $address = $context->request->remoteAddress;
        $profile = (string) $data['profile'];
        $backup = (string) $data['backup'];
        $audit = $this->services->audit;

        return Download::response(
            $context->request,
            $part['path'],
            $file,
            $part['sha256'],
            $this->services->config->downloadAccel(),
            $this->services->config->downloadAccelPrefix(),
            static function (string $event, array $details) use ($audit, $user, $address, $profile, $backup): void {
                $audit->record($event, $user, $address, ['profile' => $profile, 'backup' => $backup] + $details);
            },
            str_ends_with($file, '.age') ? 'application/octet-stream' : 'application/zip',
        );
    }

    // ------------------------------------------------------------------------------------------------- the warning

    private function warning(BackupEntry $entry, string $directory): string
    {
        $path = Html::e($directory);
        $first = $entry->sidecar?->parts[0]->file ?? 'FILE';
        $example = Html::e($directory . '/' . $first);

        return '<div class="box" role="note"><h2>Large files</h2>'
            . '<p>A download through the browser can fail for a large file: PHP and the web server have time limits, a proxy or CDN in front may cut long answers, the connection can drop, and the browser has its own limits. If it fails, or you would rather not wait, fetch the file from the server directly.</p>'
            . '<p>The files are in <code>' . $path . '</code>. For example, from your computer (replace USER and HOST):</p>'
            . '<pre><code>scp USER@HOST:' . $example . ' .</code></pre><pre><code>rsync -avP USER@HOST:' . $example . ' .</code></pre>'
            . '<p>Then compare the checksum with the one in the table below.</p></div>';
    }

    // ------------------------------------------------------------------------------------------------- helpers

    /**
     * @param array<string, string> $params `id` and `name`
     */
    private function entry(array $params): ?BackupEntry
    {
        $name = BackupName::parse($params['name'] ?? '');
        if ($name === null) {
            return null;
        }
        try {
            return $this->service()->find($params['id'] ?? '', $name->base());
        } catch (ProfileException | StorageException) {
            return null;
        }
    }

    private function missing(Context $context): Response
    {
        return View::page($context, 'Not found', '<p>There is no such backup or part. <a href="' . Html::e($context->urls->to('/profiles')) . '">Back to the profiles</a>.</p>', '/profiles', 404);
    }

    private function service(): CatalogService
    {
        $data = $this->services->data;

        return $this->catalog ??= new CatalogService(new ProfileStore($data . '/profiles'), new RunStore($data . '/runs'), $this->services->budgets, $this->services->clock);
    }
}
