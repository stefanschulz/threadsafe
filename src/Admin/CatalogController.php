<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use Throwable;
use ThreadSafe\App\CatalogService;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\Sidecar;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Storage\BackupEntry;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Storage\StorageException;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;
use ThreadSafe\Web\RunView;

/**
 * The backups that exist (docs/PHASE4.md 3.7): the table on the profile page, the details of one, checking that it is intact (in
 * ticks, like a backup run) and deleting it.
 */
final class CatalogController
{
    private ?CatalogService $catalog;

    /**
     * @param CatalogService|null $catalog for tests; by default the service on the data directory
     */
    public function __construct(private readonly Services $services, ?CatalogService $catalog = null)
    {
        $this->catalog = $catalog;
    }

    // ------------------------------------------------------------------------------------------------- the table

    /**
     * The table of the backups of a profile, for the profile page.
     */
    public function table(Context $context, string $profileId): string
    {
        try {
            $entries = $this->service()->backups($profileId);
        } catch (ProfileException | StorageException $e) {
            return '<p class="flash error">The backups cannot be listed: ' . Html::e($e->getMessage()) . '</p>';
        }
        if ($entries === []) {
            return '<p class="hint">There are no backups of this site yet.</p>';
        }

        $rows = '';
        foreach ($entries as $entry) {
            $base = $entry->name->base();
            $details = $context->urls->to("/profiles/$profileId/backups/$base");
            $sidecar = $entry->sidecar;
            $manifest = $sidecar?->manifest;
            $time = $entry->name->time->format('Y-m-d\TH:i:s\Z');
            $rows .= '<tr><td><a href="' . Html::e($details) . '"><time datetime="' . Html::e($time) . '">' . Html::e(str_replace('T', ' ', rtrim($time, 'Z'))) . '</time></a></td>'
                . '<td>' . ($sidecar === null ? '-' : Html::e(SpaceCheck::format($sidecar->size())) . (count($sidecar->parts) > 1 ? ' in ' . count($sidecar->parts) . ' parts' : '')) . '</td>'
                . '<td>' . ($manifest === null ? '-' : Html::e($manifest->duration() . ' s')) . '</td>'
                . '<td>' . ($manifest === null ? '-' : Html::e(trim($manifest->application['name'] . ' ' . ($manifest->application['version'] ?? '')))) . '</td>'
                . '<td>' . $this->statusWord($entry) . '</td>'
                . '<td class="actions">' . $this->rowActions($context, $profileId, $entry) . '</td></tr>';
        }

        return '<div class="scroll"><table><thead><tr><th>Made (UTC)</th><th>Size</th><th>Took</th><th>Application</th><th>State</th><th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * The checks that are going, for the profile page and the dashboard.
     */
    public function checksBox(Context $context, ?string $profile = null): string
    {
        $items = '';
        foreach ($this->service()->verifications() as $run) {
            $state = $this->service()->state($run);
            if ($state === 'finished' && $run->updated < time() - 3600) {
                continue;
            }
            $runProfile = is_string($run->state['profileId'] ?? null) ? $run->state['profileId'] : '?';
            if ($profile !== null && $runProfile !== $profile) {
                continue;
            }
            $word = ['active' => 'running', 'interrupted' => 'interrupted', 'failed' => 'failed', 'finished' => 'finished'][$state];
            $items .= '<li><a href="' . Html::e($context->urls->to('/checks/' . $run->id)) . '">Check of ' . Html::e((string) ($run->state['backup'] ?? '?')) . '</a>: <strong>' . $word . '</strong></li>';
        }

        return $items === '' ? '' : '<div class="box"><h2>Checks</h2><ul class="plain">' . $items . '</ul></div>';
    }

    private function encryptionText(Sidecar $sidecar): string
    {
        $e = $sidecar->encryption;
        if ($e === null) {
            return 'none';
        }
        $for = $e['recipients'] === [] ? '' : 'for ' . count($e['recipients']) . ' public key' . (count($e['recipients']) === 1 ? '' : 's') . ' (' . implode(', ', $e['recipients']) . ')';

        return 'format age: ' . ($e['passphrase'] ? 'for a passphrase only; only the passphrase opens it' : $for . '; only the matching secret key opens it') . ', the server cannot';
    }

    private function statusWord(BackupEntry $entry): string
    {
        $word = $entry->label();
        $text = ['ok' => 'ok', 'ok-with-warnings' => 'ok, with warnings', 'unverified' => 'not checked', 'faulty' => 'FAULTY', 'incomplete' => 'INCOMPLETE', 'unfinished' => 'unfinished', 'unknown' => 'unknown'][$word] ?? $word;

        return '<strong>' . Html::e($text) . '</strong>' . ($entry->sidecar !== null && $entry->sidecar->pinned ? ' (pinned)' : '') . ($entry->sidecar?->encryption !== null ? ' (encrypted)' : '') . ($entry->problem !== null ? '<br><span class="hint">' . Html::e($entry->problem) . '</span>' : '');
    }

    private function rowActions(Context $context, string $profileId, BackupEntry $entry): string
    {
        $base = $entry->name->base();
        $actions = '<a href="' . Html::e($context->urls->to("/profiles/$profileId/backups/$base")) . '">Details</a>';
        if ($entry->condition !== BackupEntry::UNFINISHED && $entry->condition !== BackupEntry::UNKNOWN) {
            $actions .= ' <a href="' . Html::e($context->urls->to("/profiles/$profileId/backups/$base/download")) . '">Download</a>';
            $actions .= ' <a href="' . Html::e($context->urls->to("/profiles/$profileId/backups/$base/restore-help")) . '">Restore</a>';
            $actions .= ' ' . View::form($context, "/profiles/$profileId/backups/$base/verify", '<button type="submit" class="link">Check</button>', 'inline');
        }

        return $actions . ' <a href="' . Html::e($context->urls->to("/profiles/$profileId/backups/$base/delete")) . '">Delete</a>';
    }

    // ------------------------------------------------------------------------------------------------- one backup

    /**
     * @param array<string, string> $params
     */
    public function show(Context $context, array $params): Response
    {
        $entry = $this->entry($params);
        if ($entry === null) {
            return $this->missing($context);
        }
        $profileId = $params['id'] ?? '';
        $base = $entry->name->base();

        $body = '<div class="actions">';
        if ($entry->condition !== BackupEntry::UNFINISHED && $entry->condition !== BackupEntry::UNKNOWN) {
            $body .= '<a class="button" href="' . Html::e($context->urls->to("/profiles/$profileId/backups/$base/download")) . '">Download</a> '
                . '<a class="button secondary" href="' . Html::e($context->urls->to("/profiles/$profileId/backups/$base/restore-help")) . '">How to restore it</a> '
                . View::form($context, "/profiles/$profileId/backups/$base/verify", '<button type="submit">Check that it is intact</button>', 'inline');
        }
        if ($entry->sidecar !== null) {
            $pinned = $entry->sidecar->pinned;
            $body .= ' ' . View::form($context, "/profiles/$profileId/backups/$base/pin", '<input type="hidden" name="pinned" value="' . ($pinned ? '0' : '1') . '"><button type="submit" class="secondary">' . ($pinned ? 'Unpin' : 'Pin it') . '</button>', 'inline');
        }
        $body .= ' <a class="button danger" href="' . Html::e($context->urls->to("/profiles/$profileId/backups/$base/delete")) . '">Delete</a>'
            . ' <a href="' . Html::e($context->urls->to("/profiles/$profileId")) . '">Back to the profile</a></div>';

        $rows = ['State' => strip_tags($this->statusWord($entry))];
        $sidecar = $entry->sidecar;
        if ($sidecar === null) {
            $body .= $this->facts($rows + ['Problem' => $entry->problem ?? 'nothing usable found']);

            return View::page($context, 'Backup ' . $base, $body, '/profiles');
        }
        $m = $sidecar->manifest;
        $rows += [
            'Encryption' => $this->encryptionText($sidecar),
            'Kept' => $sidecar->pinned ? 'pinned: retention never deletes it' : 'by the retention rules of the profile (if it has any)',
            'Asked for by' => ['manual' => 'a person (the command or the browser)', 'schedule' => 'the schedule'][$sidecar->source] ?? 'not recorded (made before this was noted)',
            'Made' => $m->started->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' UTC, took ' . $m->duration() . ' s',
            'Application' => trim($m->application['name'] . ' ' . ($m->application['version'] ?? '')) . ' (website type ' . $m->type . ')',
            'Made with' => 'ThreadSafe ' . $m->toolVersion . ', PHP ' . $m->php,
            'Files' => $m->counts['files'] . ' files, ' . $m->counts['directories'] . ' directories, ' . SpaceCheck::format($m->counts['fileBytes']),
            'Database' => $m->counts['tables'] > 0 ? $m->counts['tables'] . ' tables, ' . $m->counts['rows'] . ' rows, ' . SpaceCheck::format($m->counts['databaseBytes']) . ' (read ' . ($m->consistency === 'snapshot' ? 'in one consistent snapshot' : ($m->consistency === 'none' ? 'not at all' : 'table by table')) . ')' : 'none',
            'Left out' => $m->filters['excludePaths'] === [] && $m->filters['structureOnlyTables'] === [] ? '-' : count($m->filters['excludePaths']) . ' path patterns, structure only of ' . count($m->filters['structureOnlyTables']) . ' tables',
        ];
        $body .= $this->facts($rows);

        $parts = '';
        foreach ($sidecar->parts as $part) {
            $parts .= '<tr><td><code>' . Html::e($part->file) . '</code></td><td>' . Html::e(SpaceCheck::format($part->size)) . '</td><td>' . $part->entries . '</td><td><code>' . Html::e($part->sha256) . '</code></td></tr>';
        }
        $body .= '<h2>Parts</h2><div class="scroll"><table><thead><tr><th>File</th><th>Size</th><th>Entries</th><th>SHA-256</th></tr></thead><tbody>' . $parts . '</tbody></table></div>';

        $domains = $this->domains($m->extras);
        if ($domains !== []) {
            $body .= '<h2>Shop addresses</h2><ul>' . implode('', array_map(static fn (string $d): string => '<li><code>' . Html::e($d) . '</code></li>', $domains)) . '</ul>';
        }
        if ($m->filters['excludePaths'] !== []) {
            $body .= '<h2>Left out of the files</h2><p><code>' . implode('</code> <code>', array_map(Html::e(...), array_slice($m->filters['excludePaths'], 0, 40))) . '</code></p>';
        }
        if ($m->filters['structureOnlyTables'] !== []) {
            $body .= '<h2>Tables kept without their rows</h2><p><code>' . implode('</code> <code>', array_map(Html::e(...), array_slice($m->filters['structureOnlyTables'], 0, 40))) . '</code></p>';
        }
        if ($m->warnings !== []) {
            $items = '';
            foreach (array_slice($m->warnings, 0, 30) as $warning) {
                $items .= '<li>' . Html::e($warning) . '</li>';
            }
            $body .= '<h2>Warnings (' . count($m->warnings) . ')</h2><ul>' . $items . (count($m->warnings) > 30 ? '<li>... and ' . (count($m->warnings) - 30) . ' more</li>' : '') . '</ul>';
        }
        if ($m->unsupportedObjects !== []) {
            $items = '';
            foreach (array_slice($m->unsupportedObjects, 0, 30) as $object) {
                $items .= '<li>' . Html::e($object['type'] . ' ' . $object['name']) . '</li>';
            }
            $body .= '<h2>Not part of this backup</h2><p class="hint">Stored routines, events and sequences of the database are not backed up.</p><ul>' . $items . '</ul>';
        }

        return View::page($context, 'Backup ' . $base, $body, '/profiles');
    }

    /**
     * @param array<string, string> $rows
     */
    private function facts(array $rows): string
    {
        $table = '<div class="scroll"><table class="facts"><tbody>';
        foreach ($rows as $label => $value) {
            $table .= '<tr><th scope="row">' . Html::e($label) . '</th><td>' . Html::e($value) . '</td></tr>';
        }

        return $table . '</tbody></table></div>';
    }

    /**
     * The addresses of the shop, if the website type noted them.
     *
     * @param array<string, mixed> $extras
     *
     * @return list<string>
     */
    private function domains(array $extras): array
    {
        $domains = [];
        foreach (is_array($extras['shopUrls'] ?? null) ? $extras['shopUrls'] : [] as $row) {
            if (is_array($row)) {
                foreach (['domain', 'domain_ssl'] as $key) {
                    if (is_string($row[$key] ?? null) && $row[$key] !== '') {
                        $domains[] = $row[$key] . (is_string($row['physical_uri'] ?? null) ? $row['physical_uri'] : '');
                    }
                }
            }
        }

        return array_values(array_unique($domains));
    }

    // ------------------------------------------------------------------------------------------------- delete

    /**
     * @param array<string, string> $params
     */
    public function confirmDelete(Context $context, array $params): Response
    {
        $entry = $this->entry($params);
        if ($entry === null) {
            return $this->missing($context);
        }
        $profileId = $params['id'] ?? '';
        $base = $entry->name->base();
        $files = 'all files of this backup';
        if ($entry->sidecar !== null) {
            $parts = count($entry->sidecar->parts);
            $files = ($parts === 1 ? 'the archive' : "the $parts archive parts") . ' (' . SpaceCheck::format($entry->sidecar->size()) . ') and the record next to it';
        }

        $body = '<p>Delete the backup <strong>' . Html::e($base) . '</strong>?</p>'
            . '<p>This removes ' . Html::e($files) . ' from the storage directory. <strong>It cannot be undone.</strong> The site itself is not touched.</p>'
            . View::form($context, "/profiles/$profileId/backups/$base/delete", '<button type="submit" class="danger">Delete the backup</button> <a href="' . Html::e($context->urls->to("/profiles/$profileId")) . '">Cancel</a>');

        return View::page($context, 'Delete ' . $base, $body, '/profiles');
    }

    /**
     * Pins a backup or lets it go again (docs/PHASE5.md 3.4).
     *
     * @param array<string, string> $params
     */
    public function pin(Context $context, array $params): Response
    {
        $entry = $this->entry($params);
        if ($entry === null) {
            return $this->missing($context);
        }
        $profileId = $params['id'] ?? '';
        $base = $entry->name->base();
        $pinned = $context->request->post('pinned') === '1';
        try {
            $this->service()->pin($profileId, $base, $pinned);
        } catch (GuardFailed | ProfileException | StorageException $e) {
            $context->flash('error', $e->getMessage());

            return Response::redirect($context->urls->to("/profiles/$profileId/backups/$base"));
        }
        $this->audit($context, $pinned ? 'backup.pinned' : 'backup.unpinned', ['profile' => $profileId, 'backup' => $base]);
        $context->flash('ok', $pinned ? 'The backup is pinned: retention never deletes it.' : 'The backup is not pinned any more.');

        return Response::redirect($context->urls->to("/profiles/$profileId/backups/$base"));
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Context $context, array $params): Response
    {
        $entry = $this->entry($params);
        if ($entry === null) {
            return $this->missing($context);
        }
        $profileId = $params['id'] ?? '';
        $base = $entry->name->base();
        try {
            $this->service()->delete($profileId, $base);
        } catch (GuardFailed $e) {
            $context->flash('error', $e->getMessage());

            return Response::redirect($context->urls->to("/profiles/$profileId"));
        }
        $this->audit($context, 'backup.deleted', ['profile' => $profileId, 'backup' => $base]);
        $context->flash('ok', 'The backup ' . $base . ' was deleted.');

        return Response::redirect($context->urls->to("/profiles/$profileId"));
    }

    // ------------------------------------------------------------------------------------------------- checking

    /**
     * @param array<string, string> $params
     */
    public function startCheck(Context $context, array $params): Response
    {
        $profileId = $params['id'] ?? '';
        $base = $params['name'] ?? '';
        try {
            $run = $this->service()->startVerify($profileId, $base);
        } catch (GuardFailed $e) {
            $context->flash('error', $e->getMessage());

            return Response::redirect($context->urls->to("/profiles/$profileId"));
        } catch (ProfileException | StorageException) {
            return $this->missing($context);
        }
        $this->audit($context, 'backup.check', ['profile' => $profileId, 'backup' => $base, 'run' => $run->id]);

        return Response::redirect($context->urls->to('/checks/' . $run->id));
    }

    /**
     * @param array<string, string> $params
     */
    public function showCheck(Context $context, array $params): Response
    {
        $run = $this->findCheck($params['run'] ?? '');
        if ($run === null) {
            return $this->missing($context);
        }
        $service = $this->service();
        $state = $service->state($run);
        $profile = is_string($run->state['profileId'] ?? null) ? $run->state['profileId'] : '?';
        $backup = is_string($run->state['backup'] ?? null) ? $run->state['backup'] : '?';
        $id = $run->id;
        $back = '<a href="' . Html::e($context->urls->to('/profiles/' . $profile)) . '">Back to the profile</a>';
        $progress = $service->progress($run);

        if ($state === 'active') {
            $body = '<div id="run" data-tick-url="' . Html::e($context->urls->to("/checks/$id/tick")) . '" data-status-url="' . Html::e($context->urls->to("/checks/$id/status")) . '">'
                . '<p>Checking <code>' . Html::e($backup) . '</code>: every part is read and compared with its checksum' . (($run->state['full'] ?? true) === true ? ', and read as an archive' : '') . '. This takes as long as reading the files.</p>'
                . '<progress id="run-progress" max="100"' . ($progress['percent'] !== null ? ' value="' . $progress['percent'] . '"' : '') . '></progress>'
                . '<p id="run-label" class="hint" aria-live="polite">' . Html::e($progress['label']) . '</p><p id="run-live" class="hint" data-elapsed="' . max(0, time() - $run->created) . '"><span id="run-activity"></span> <span id="run-clock">All together: ' . RunView::clock(max(0, time() - $run->created)) . '</span></p><p id="run-note" class="hint"></p>'
                . '<div class="actions" id="run-actions">' . View::form($context, "/checks/$id/continue", '<button type="submit">Do the next step</button>', 'inline')
                . ' ' . View::form($context, "/checks/$id/discard", '<button type="submit" class="secondary">Stop the check</button>', 'inline') . '</div></div>';
            $title = 'Check running';
        } elseif ($state === 'interrupted') {
            $body = '<p>The check of <code>' . Html::e($backup) . '</code> was interrupted. It can go on from where it stopped.</p><progress max="100"' . ($progress['percent'] !== null ? ' value="' . $progress['percent'] . '"' : '') . '></progress>'
                . '<div class="actions">' . View::form($context, "/checks/$id/continue", '<button type="submit">Continue the check</button>', 'inline')
                . ' ' . View::form($context, "/checks/$id/discard", '<button type="submit" class="secondary">Stop the check</button>', 'inline') . '</div>';
            $title = 'Check interrupted';
        } elseif ($state === 'failed') {
            $error = $run->error ?? 'unknown reason';
            $error = preg_match('/^[A-Za-z\\\\]+: (.*)$/s', $error, $m) === 1 ? $m[1] : $error;
            $body = '<p class="flash error" role="alert">The check could not be done: ' . Html::e($error) . '</p><div class="actions">'
                . View::form($context, "/checks/$id/discard", '<button type="submit" class="secondary">Dismiss</button>', 'inline') . ' ' . $back . '</div>';
            $title = 'Check failed';
        } else {
            $report = $service->result($run) ?? [];
            $problems = is_array($report['problems'] ?? null) ? $report['problems'] : [];
            $notes = is_array($report['notes'] ?? null) ? $report['notes'] : [];
            $list = static function (array $items): string {
                $html = '';
                foreach (array_slice($items, 0, 50) as $item) {
                    $html .= '<li>' . Html::e((string) (is_scalar($item) ? $item : '')) . '</li>';
                }

                return '<ul>' . $html . '</ul>';
            };
            $body = ($problems === []
                ? '<p class="flash ok" role="status">The backup <code>' . Html::e($backup) . '</code> is intact (' . (($report['level'] ?? 'full') === 'full' ? 'full' : 'quick') . ' check: ' . (int) ($report['parts'] ?? 0) . ' part(s), ' . Html::e(SpaceCheck::format((int) ($report['bytes'] ?? 0))) . ' read). State: ' . Html::e((string) ($report['status'] ?? '?')) . '.</p>'
                : '<p class="flash error" role="alert">The backup <code>' . Html::e($backup) . '</code> is NOT intact:</p>' . $list($problems))
                . ($notes === [] ? '' : '<h2>Notes</h2>' . $list($notes))
                . '<div class="actions">' . View::form($context, "/checks/$id/discard", '<button type="submit" class="secondary">Dismiss</button>', 'inline') . ' ' . $back . '</div>';
            $title = $problems === [] ? 'Check finished: intact' : 'Check finished: NOT intact';
        }

        return View::page($context, $title, $body, '/profiles');
    }

    /**
     * What the working step of a check does right now, for the script of the page (JSON).
     *
     * @param array<string, string> $params
     */
    public function statusCheck(Context $context, array $params): Response
    {
        $run = $this->findCheck($params['run'] ?? '');

        return $run === null ? Response::json(['working' => false, 'note' => '', 'seconds' => 0, 'elapsed' => 0], 404) : Response::json($this->service()->live($run));
    }

    /**
     * One tick of a check for the script of the page (JSON).
     *
     * @param array<string, string> $params
     */
    public function tickCheck(Context $context, array $params): Response
    {
        $id = $params['run'] ?? '';
        $before = $this->findCheck($id);
        if ($before === null) {
            return Response::json(['status' => 'missing', 'error' => 'This check does not exist any more.'], 404);
        }
        try {
            $run = $this->service()->tick($id);
        } catch (EngineException $e) {
            return $e->isBusy() ? Response::json(['status' => 'busy', 'label' => 'Another request is working on the check; waiting.']) : Response::json(['status' => 'missing', 'error' => $e->getMessage()], 404);
        } catch (Throwable $e) {
            return Response::json(['status' => 'failed', 'error' => $e->getMessage() !== '' ? $e->getMessage() : $e::class]);
        }
        if ($run->status === RunStatus::Finished && $before->status === RunStatus::Running) {
            $this->audit($context, 'backup.checked', ['run' => $id, 'backup' => (string) ($run->state['backup'] ?? ''), 'intact' => ($this->service()->result($run)['ok'] ?? false) === true]);
        }
        $progress = $this->service()->progress($run);

        return Response::json(['status' => $run->status === RunStatus::Finished ? 'finished' : 'running', 'percent' => $progress['percent'], 'label' => $progress['label'], 'ticks' => $run->ticks]);
    }

    /**
     * One tick for a browser without script, then back to the page of the check.
     *
     * @param array<string, string> $params
     */
    public function nextCheck(Context $context, array $params): Response
    {
        $id = $params['run'] ?? '';
        $before = $this->findCheck($id);
        if ($before === null) {
            return $this->missing($context);
        }
        try {
            $run = $this->service()->tick($id);
            if ($run->status === RunStatus::Finished && $before->status === RunStatus::Running) {
                $this->audit($context, 'backup.checked', ['run' => $id, 'backup' => (string) ($run->state['backup'] ?? ''), 'intact' => ($this->service()->result($run)['ok'] ?? false) === true]);
            }
        } catch (EngineException $e) {
            $context->flash('warning', $e->isBusy() ? 'Another request is working on the check; try again in a moment.' : $e->getMessage());
        } catch (Throwable $e) {
            $context->flash('error', $e->getMessage());
        }

        return Response::redirect($context->urls->to("/checks/$id"));
    }

    /**
     * @param array<string, string> $params
     */
    public function discardCheck(Context $context, array $params): Response
    {
        $id = $params['run'] ?? '';
        $run = $this->findCheck($id);
        if ($run === null) {
            return $this->missing($context);
        }
        $profile = (string) ($run->state['profileId'] ?? '');
        try {
            $this->service()->discard($id);
        } catch (GuardFailed $e) {
            $context->flash('warning', $e->getMessage());

            return Response::redirect($context->urls->to("/checks/$id"));
        }

        return Response::redirect($context->urls->to($profile !== '' && ProfileStore::validId($profile) ? '/profiles/' . $profile : '/'));
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

    private function findCheck(string $runId): ?Run
    {
        try {
            return $this->service()->findVerification($runId);
        } catch (EngineException) {
            return null;
        }
    }

    private function missing(Context $context): Response
    {
        return View::page($context, 'Not found', '<p>There is no such backup or check. <a href="' . Html::e($context->urls->to('/profiles')) . '">Back to the profiles</a>.</p>', '/profiles', 404);
    }

    /**
     * @param array<string, scalar|null> $details
     */
    private function audit(Context $context, string $event, array $details): void
    {
        $this->services->audit->record($event, $context->user() ?? '-', $context->request->remoteAddress, $details);
    }

    public function service(): CatalogService
    {
        $data = $this->services->data;

        return $this->catalog ??= new CatalogService(new ProfileStore($data . '/profiles'), new RunStore($data . '/runs'), $this->services->budgets, $this->services->clock);
    }
}
