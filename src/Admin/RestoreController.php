<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use RuntimeException;
use ThreadSafe\App\CatalogService;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Core\Paths;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Storage\BackupEntry;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Storage\StorageException;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * The restore help (docs/PHASE4.md 3.8): the steps for one backup, `restore.php` built from this installation, and a key file with a
 * new key. The restore itself is not done here - it is done by the separate tool, on the other server, which deletes itself.
 */
final class RestoreController
{
    private ?CatalogService $catalog;

    /**
     * @param CatalogService|null $catalog for tests; by default the service on the data directory
     */
    public function __construct(private readonly Services $services, ?CatalogService $catalog = null)
    {
        $this->catalog = $catalog;
    }

    /**
     * @param array<string, string> $params `id` and `name`
     */
    public function help(Context $context, array $params): Response
    {
        $name = BackupName::parse($params['name'] ?? '');
        try {
            $entry = $name === null ? null : $this->service()->find($params['id'] ?? '', $name->base());
        } catch (ProfileException | StorageException) {
            $entry = null;
        }
        if ($entry === null || $entry->sidecar === null || $entry->condition === BackupEntry::UNFINISHED || $entry->condition === BackupEntry::UNKNOWN) {
            return View::page($context, 'Not found', '<p>There is no such backup. <a href="' . Html::e($context->urls->to('/profiles')) . '">Back to the profiles</a>.</p>', '/profiles', 404);
        }
        $profileId = $params['id'] ?? '';
        $base = $entry->name->base();

        $parts = '';
        foreach ($entry->sidecar->parts as $part) {
            $parts .= '<tr><td><code>' . Html::e($part->file) . '</code></td><td>' . Html::e(SpaceCheck::format($part->size)) . '</td><td><code>' . Html::e($part->sha256) . '</code></td></tr>';
        }
        $manifest = $entry->sidecar->manifest;
        $application = trim($manifest->application['name'] . ' ' . ($manifest->application['version'] ?? ''));

        $body = '<p>Restoring <strong>' . Html::e($base) . '</strong> (' . Html::e($application) . ', ' . Html::e(SpaceCheck::format($entry->sidecar->size())) . ') onto a server of your choice takes four steps. '
            . 'The restore is done by a separate tool, <code>restore.php</code>, which you put on the <em>target</em> server; it removes itself when it is done.</p>'
            . '<ol class="steps">'
            . '<li><strong>Get the tool and a key.</strong> ' . $this->tools($context) . '</li>'
            . '<li><strong>Upload</strong> <code>restore.php</code>, <code>restore-key.php</code> and ' . (count($entry->sidecar->parts) === 1 ? 'the archive' : 'all ' . count($entry->sidecar->parts) . ' parts of the backup') . ' into the (empty) directory of the new site on the target server.'
            . ' <a href="' . Html::e($context->urls->to("/profiles/$profileId/backups/$base/download")) . '">Download the backup</a>, or copy it from the storage directory by SFTP.</li>'
            . '<li><strong>Open</strong> <code>restore.php</code> in the browser of the target server and enter the key.' . ($entry->sidecar->encryption !== null ? ' The backup is <strong>encrypted</strong>: on the page where you choose the backup, enter its <strong>secret key</strong> (the line <code>AGE-SECRET-KEY-1...</code> of the key file)' . ($entry->sidecar->encryption['passphrase'] ? ' or the <strong>passphrase</strong> you gave when you made it' : '') . '. This server does not have it, and without it nobody can open the backup. The target PHP needs the <code>sodium</code> extension.' : '') . '</li>'
            . '<li><strong>Answer the questions</strong> (the new address, the new database) and let it run. It checks the target first and refuses to write into a directory or a database that is not empty.</li></ol>'
            . '<h2>The parts to upload</h2><div class="scroll"><table><thead><tr><th>File</th><th>Size</th><th>SHA-256</th></tr></thead><tbody>' . $parts . '</tbody></table></div>'
            . '<h2>What the target needs</h2><ul>'
            . '<li>PHP 8.4 or newer with <code>zlib</code>' . ($manifest->counts['tables'] > 0 ? ' and <code>pdo_mysql</code> or <code>mysqli</code>' : '') . '.</li>'
            . ($manifest->counts['tables'] > 0 ? '<li>An <strong>empty database</strong> and a user who may use it. The restore does not create it.</li>' : '')
            . '<li>An <strong>empty directory</strong> as the web root of the new site, with room for about ' . Html::e(SpaceCheck::format((int) ceil(($manifest->counts['fileBytes'] + $manifest->counts['databaseBytes']) * 1.1))) . '.</li></ul>'
            . '<p class="hint">With shell access the same restore runs on the command line: <code>php restore.php restore ARCHIVE --root=DIR ...</code> (no key needed' . ($entry->sidecar->encryption !== null ? ' for the tool; the encrypted backup needs <code>--identity-file=KEYFILE</code> or <code>--passphrase-file=FILE</code>' : '') . '). See docs/RESTORE.md for everything it checks and what to do when it refuses.</p>'
            . '<p><a href="' . Html::e($context->urls->to("/profiles/$profileId/backups/$base")) . '">Back to the backup</a></p>';

        return View::page($context, 'Restore ' . $base, $body, '/profiles');
    }

    private function tools(Context $context): string
    {
        return '<div class="actions">'
            . '<a class="button" href="' . Html::e($context->urls->to('/restore-php')) . '">Download restore.php</a> '
            . View::form($context, '/restore-key', '<button type="submit" name="mode" value="file">Download restore-key.php with a new key</button>', 'inline')
            . ' ' . View::form($context, '/restore-key', '<button type="submit" name="mode" value="show" class="secondary">Show a new key instead</button>', 'inline')
            . '</div><p class="hint">The key is not stored on this server. Keep it until the restore is done; then delete the key file.</p>';
    }

    /**
     * The tool itself, built from this installation.
     *
     * @param array<string, string> $params
     */
    public function tool(Context $context, array $params = []): Response
    {
        try {
            $path = $this->kit()->restorePhp();
        } catch (RuntimeException $e) {
            return View::page($context, 'The tool cannot be built', '<p class="flash error" role="alert">' . Html::e($e->getMessage()) . '</p>', '/profiles', 500);
        }
        $this->services->audit->record('restore.tool', $context->user() ?? '-', $context->request->remoteAddress);

        return Download::response($context->request, $path, 'restore.php', (string) hash_file('sha256', $path), contentType: 'application/octet-stream');
    }

    /**
     * A new key, as the file or on the page.
     *
     * @param array<string, string> $params
     */
    public function key(Context $context, array $params = []): Response
    {
        $key = RestoreKit::newKey();
        $this->services->audit->record('restore.key', $context->user() ?? '-', $context->request->remoteAddress);

        if ($context->request->post('mode') === 'show') {
            $body = '<p>Your new key (shown once; it is not stored here):</p><pre><code>' . Html::e($key) . '</code></pre>'
                . '<p>Put it into a file named <code>restore-key.php</code> next to <code>restore.php</code>, with exactly this content:</p>'
                . '<pre><code>' . Html::e(RestoreKit::keyFile($key)) . '</code></pre>'
                . '<p class="hint">It is a PHP file on purpose: a plain text file would be handed out to anyone who asks for it. Delete it when the restore is done.</p>'
                . '<p><a href="' . Html::e($context->urls->to('/profiles')) . '">Back to the profiles</a></p>';

            return View::page($context, 'A new restore key', $body, '/profiles');
        }

        return new Response(200, [
            'Content-Type: application/octet-stream',
            'Content-Disposition: attachment; filename="restore-key.php"',
            'Cache-Control: no-store',
        ], RestoreKit::keyFile($key));
    }

    private function kit(): RestoreKit
    {
        return new RestoreKit(Paths::program(), $this->services->data . '/cache');
    }

    private function service(): CatalogService
    {
        $data = $this->services->data;

        return $this->catalog ??= new CatalogService(new ProfileStore($data . '/profiles'), new RunStore($data . '/runs'), $this->services->budgets, $this->services->clock);
    }
}
