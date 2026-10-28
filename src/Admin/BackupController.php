<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use Throwable;
use ThreadSafe\App\BackupService;
use ThreadSafe\App\RetentionService;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Crypto\Passphrases;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;
use ThreadSafe\Web\RunView;

/**
 * Backups started and followed in the browser (docs/PHASE4.md 3.4): starting a run, the page that shows it, the request that does
 * one tick, and continuing or giving up a run that was interrupted. The page asks for ticks one after the other (`app.js`); a tab
 * that is closed leaves a run that can be continued from its saved state.
 */
final class BackupController
{
    private ?BackupService $backups;

    /**
     * @param BackupService|null $backups for tests; by default the service on the data directory
     */
    public function __construct(private readonly Services $services, ?BackupService $backups = null)
    {
        $this->backups = $backups;
    }

    /**
     * @param array<string, string> $params
     */
    public function start(Context $context, array $params): Response
    {
        $id = $params['id'] ?? '';
        try {
            $run = $this->service()->startWeb($id, $context->request->post('force') === '1', trim((string) $context->request->post('passphrase')));
        } catch (GuardFailed $e) {
            $context->flash('error', $e->getMessage());
            $active = $this->service()->activeRun();

            return Response::redirect($context->urls->to($active !== null ? '/runs/' . $active->id : '/profiles/' . $id));
        }
        $this->audit($context, 'backup.started', ['profile' => $id, 'run' => $run->id, 'force' => $context->request->post('force') === '1']);

        return Response::redirect($context->urls->to('/runs/' . $run->id));
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Context $context, array $params): Response
    {
        $run = $this->find($params['run'] ?? '');
        if ($run === null) {
            return $this->missing($context);
        }
        $service = $this->service();
        $state = $service->state($run);
        $progress = $service->progress($run);
        $profile = is_string($run->state['profileId'] ?? null) ? $run->state['profileId'] : '?';
        $id = $run->id;
        $profileLink = '<a href="' . Html::e($context->urls->to('/profiles/' . $profile)) . '">' . Html::e($profile) . '</a>';

        $body = '';
        if ($state === 'active') {
            $body = '<div id="run" data-tick-url="' . Html::e($context->urls->to("/runs/$id/tick")) . '" data-status-url="' . Html::e($context->urls->to("/runs/$id/status")) . '">'
                . '<p>Backing up ' . $profileLink . '. You can leave this page open or close it: a backup that stops can be continued from where it was.</p>'
                . $this->progressBar($progress)
                . '<p id="run-label" class="hint" aria-live="polite">' . Html::e($progress['label']) . '</p>'
                . '<p id="run-activity" class="hint" aria-live="polite"></p>'
                . '<p id="run-clock" class="clock" data-elapsed="' . max(0, time() - $run->created) . '">All together: ' . RunView::clock(max(0, time() - $run->created)) . '</p>'
                . '<p id="run-note" class="hint"></p>'
                . '<div class="actions" id="run-actions">' . View::form($context, "/runs/$id/continue", '<button type="submit">Do the next step</button>', 'inline')
                . ' ' . View::form($context, "/runs/$id/abandon", '<button type="submit" class="secondary">Cancel the backup</button>', 'inline') . '</div>'
                . '<noscript><p class="hint">Without script this page does not go on by itself: press "Do the next step" again and again.</p></noscript></div>';
            $title = 'Backup running';
        } elseif ($state === 'interrupted') {
            $body = '<p>The backup of ' . $profileLink . ' was interrupted: nobody has worked on it for a while. It can go on from where it stopped, or be given up (what it wrote is removed).</p>'
                . $this->progressBar($progress) . '<p class="hint">' . Html::e($progress['label']) . '</p>'
                . '<div class="actions">' . View::form($context, "/runs/$id/continue", '<button type="submit">Continue the backup</button>', 'inline')
                . ' ' . View::form($context, "/runs/$id/abandon", '<button type="submit" class="danger">Give it up</button>', 'inline') . '</div>';
            $title = 'Backup interrupted';
        } elseif ($state === 'failed') {
            $body = '<p class="flash error" role="alert">The backup of ' . $profileLink . ' failed: ' . Html::e(self::reason($run)) . '</p>'
                . '<p>What it had written is removed. Fix the cause and start a new backup.</p>'
                . '<div class="actions">' . View::form($context, "/runs/$id/dismiss", '<button type="submit" class="secondary">Dismiss</button>', 'inline')
                . ' <a href="' . Html::e($context->urls->to('/profiles/' . $profile)) . '">Back to the profile</a></div>';
            $title = 'Backup failed';
        } else {
            $body = $this->resultBox($run, $profileLink) . '<div class="actions">' . View::form($context, "/runs/$id/dismiss", '<button type="submit" class="secondary">Dismiss</button>', 'inline')
                . ' <a href="' . Html::e($context->urls->to('/profiles/' . $profile)) . '">Back to the profile</a></div>';
            $title = 'Backup finished';
        }

        return View::page($context, $title, $body, '/profiles');
    }

    /**
     * What the working step does right now, for the script of the page (JSON).
     *
     * @param array<string, string> $params
     */
    public function status(Context $context, array $params): Response
    {
        $run = $this->find($params['run'] ?? '');

        return $run === null ? Response::json(['working' => false, 'note' => '', 'seconds' => 0, 'elapsed' => 0], 404) : Response::json($this->service()->live($run));
    }

    /**
     * One tick for the script of the page; the answer is JSON.
     *
     * @param array<string, string> $params
     */
    public function tick(Context $context, array $params): Response
    {
        $id = $params['run'] ?? '';
        $before = $this->find($id);
        if ($before === null) {
            return Response::json(['status' => 'missing', 'error' => 'This backup does not exist any more.'], 404);
        }

        try {
            $run = $this->service()->tick($id);
        } catch (EngineException $e) {
            return $e->isBusy() ? Response::json(['status' => 'busy', 'label' => 'Another request is working on the backup; waiting.']) : Response::json(['status' => 'missing', 'error' => $e->getMessage()], 404);
        } catch (Throwable $e) {
            $this->audit($context, 'backup.failed', ['run' => $id, 'profile' => (string) ($before->state['profileId'] ?? '')]);

            return Response::json(['status' => 'failed', 'error' => self::message($e)]);
        }

        if ($run->status === RunStatus::Finished && $before->status === RunStatus::Running) {
            $this->audit($context, 'backup.finished', ['run' => $id, 'profile' => (string) ($run->state['profileId'] ?? ''), 'backup' => (string) ($this->service()->result($run)['backup'] ?? '')]);
            $this->retention((string) ($run->state['profileId'] ?? ''));
        }
        $progress = $this->service()->progress($run);

        return Response::json(['status' => $run->status === RunStatus::Finished ? 'finished' : 'running', 'percent' => $progress['percent'], 'label' => $progress['label'], 'ticks' => $run->ticks]);
    }

    /**
     * One tick for a browser without script, then back to the run page.
     *
     * @param array<string, string> $params
     */
    public function next(Context $context, array $params): Response
    {
        $id = $params['run'] ?? '';
        $before = $this->find($id);
        if ($before === null) {
            return $this->missing($context);
        }
        try {
            $this->service()->assertNoActiveRun($id);
        } catch (GuardFailed $e) {
            $context->flash('warning', $e->getMessage());

            return Response::redirect($context->urls->to("/runs/$id"));
        }
        try {
            $run = $this->service()->tick($id);
            if ($run->status === RunStatus::Finished && $before->status === RunStatus::Running) {
                $this->audit($context, 'backup.finished', ['run' => $id, 'profile' => (string) ($run->state['profileId'] ?? ''), 'backup' => (string) ($this->service()->result($run)['backup'] ?? '')]);
                $this->retention((string) ($run->state['profileId'] ?? ''));
            }
        } catch (EngineException $e) {
            $context->flash('warning', $e->isBusy() ? 'Another request is working on the backup; try again in a moment.' : $e->getMessage());
        } catch (Throwable $e) {
            $this->audit($context, 'backup.failed', ['run' => $id, 'profile' => (string) ($before->state['profileId'] ?? '')]);
            $context->flash('error', self::message($e));
        }

        return Response::redirect($context->urls->to("/runs/$id"));
    }

    /**
     * @param array<string, string> $params
     */
    public function abandon(Context $context, array $params): Response
    {
        $id = $params['run'] ?? '';
        $run = $this->find($id);
        if ($run === null) {
            return $this->missing($context);
        }
        $profile = (string) ($run->state['profileId'] ?? '');
        try {
            $this->service()->abandon($id);
        } catch (GuardFailed $e) {
            $context->flash('warning', $e->getMessage());

            return Response::redirect($context->urls->to("/runs/$id"));
        }
        $this->audit($context, 'backup.abandoned', ['run' => $id, 'profile' => $profile]);
        $context->flash('ok', 'The backup was given up; what it had written is removed.');

        return Response::redirect($context->urls->to($profile !== '' && ProfileStore::validId($profile) ? '/profiles/' . $profile : '/'));
    }

    /**
     * @param array<string, string> $params
     */
    public function dismiss(Context $context, array $params): Response
    {
        $id = $params['run'] ?? '';
        $run = $this->find($id);
        if ($run === null) {
            return $this->missing($context);
        }
        $profile = (string) ($run->state['profileId'] ?? '');
        try {
            $this->service()->dismiss($id);
        } catch (GuardFailed $e) {
            $context->flash('warning', $e->getMessage());

            return Response::redirect($context->urls->to("/runs/$id"));
        }

        return Response::redirect($context->urls->to($profile !== '' && ProfileStore::validId($profile) ? '/profiles/' . $profile : '/'));
    }

    // ------------------------------------------------------------------------------------------------- pieces for other pages

    /**
     * A box for the dashboard and the profile page: the backups that are going, interrupted or failed.
     *
     * @param string|null $profile only the runs of this profile
     */
    public function runsBox(Context $context, ?string $profile = null): string
    {
        $items = '';
        foreach ($this->service()->runs() as $run) {
            $state = $this->service()->state($run);
            if ($state === 'finished' && $run->updated < time() - 3600) {
                continue;
            }
            $runProfile = is_string($run->state['profileId'] ?? null) ? $run->state['profileId'] : '?';
            if ($profile !== null && $runProfile !== $profile) {
                continue;
            }
            $label = ['active' => 'running', 'interrupted' => 'interrupted', 'failed' => 'failed', 'finished' => 'finished'][$state];
            $items .= '<li><a href="' . Html::e($context->urls->to('/runs/' . $run->id)) . '">Backup of ' . Html::e($runProfile) . '</a>: <strong>' . $label . '</strong>'
                . ($state === 'active' || $state === 'interrupted' ? ' - ' . Html::e($this->service()->progress($run)['label']) : '') . '</li>';
        }

        return $items === '' ? '' : '<div class="box"><h2>Backups</h2><ul class="plain">' . $items . '</ul></div>';
    }

    /**
     * The "back up now" form for a profile page.
     */
    public function startForm(Context $context, string $profileId): string
    {
        $active = $this->service()->activeRun();
        if ($active !== null) {
            return '<p class="hint">A backup is running: <a href="' . Html::e($context->urls->to('/runs/' . $active->id)) . '">follow it</a>. Only one backup runs at a time.</p>';
        }

        return View::form($context, "/profiles/$profileId/backup", '<button type="submit">Back up now</button> '
            . '<label class="inline-check"><input type="checkbox" name="force" value="1"> Go on although the storage seems to have too little room</label>'
            . '<details class="passphrase"><summary>Encrypt this backup with a passphrase</summary>'
            . '<div class="field"><label for="f-passphrase">Passphrase</label><input id="f-passphrase" name="passphrase" type="text" autocomplete="off" spellcheck="false" autocapitalize="none"> '
            . '<button type="button" class="secondary" data-suggest="#f-passphrase" data-alphabet="' . Html::e(Passphrases::ALPHABET) . '">Suggest one</button> '
            . '<button type="button" class="secondary" data-copy="#f-passphrase">Copy</button>'
            . '<p class="hint">Optional. This one backup is then encrypted for the passphrase only - not for the public keys of the profile - and only the passphrase opens it. It is not stored anywhere: write it down before you start, and keep it apart from the backup. If it is lost, the backup is lost. At least 12 characters; a suggested one is easy to read aloud and has about 99 bits.</p></div></details>'
            . '<p class="hint">The backup is made in steps while this page is open. The database is read table by table, not in one snapshot, as in a command line run.</p>');
    }

    // ------------------------------------------------------------------------------------------------- helpers

    /**
     * @param array{phase: string, percent: int|null, label: string} $progress
     */
    private function progressBar(array $progress): string
    {
        return '<progress id="run-progress" max="100"' . ($progress['percent'] !== null ? ' value="' . $progress['percent'] . '"' : '') . '>' . ($progress['percent'] ?? '') . '</progress>';
    }

    private function resultBox(Run $run, string $profileLink): string
    {
        $result = $this->service()->result($run) ?? [];
        $parts = is_array($result['parts'] ?? null) ? $result['parts'] : [];
        $rows = [
            'Backup' => (string) ($result['backup'] ?? '?'),
            'Size' => SpaceCheck::format((int) ($result['size'] ?? 0)),
            'Files' => (int) ($result['files'] ?? 0) . ' files, ' . (int) ($result['directories'] ?? 0) . ' directories',
            'Database' => (int) ($result['tables'] ?? 0) > 0 ? (int) $result['tables'] . ' tables, ' . (int) ($result['rows'] ?? 0) . ' rows' : 'none',
            'Duration' => (int) ($result['duration'] ?? 0) . ' s',
            'Archive' . (count($parts) === 1 ? '' : 's') => implode(', ', array_map('strval', $parts)),
            'Status' => (string) ($result['status'] ?? '?') . ', the database was read ' . ((string) ($result['consistency'] ?? 'table') === 'snapshot' ? 'in one consistent snapshot' : 'table by table'),
        ];
        $table = '<div class="scroll"><table class="facts"><tbody>';
        foreach ($rows as $label => $value) {
            $table .= '<tr><th scope="row">' . Html::e($label) . '</th><td>' . Html::e($value) . '</td></tr>';
        }
        $table .= '</tbody></table></div>';

        $warnings = [];
        foreach (is_array($run->state['warnings'] ?? null) ? $run->state['warnings'] : [] as $warning) {
            if (is_string($warning)) {
                $warnings[] = $warning;
            }
        }
        $list = '';
        foreach (array_slice($warnings, 0, 20) as $warning) {
            $list .= '<li>' . Html::e($warning) . '</li>';
        }

        return '<p class="flash ok" role="status">The backup of ' . $profileLink . ' is complete.</p>' . $table
            . ($warnings === [] ? '' : '<h2>Warnings (' . count($warnings) . ')</h2><ul>' . $list . (count($warnings) > 20 ? '<li>... and ' . (count($warnings) - 20) . ' more</li>' : '') . '</ul>');
    }

    private static function reason(Run $run): string
    {
        $error = $run->error ?? 'unknown reason';

        // "ThreadSafe\Core\Exception\GuardFailed: the message": the class name is for the logs, not for the person
        return preg_match('/^[A-Za-z\\\\]+: (.*)$/s', $error, $m) === 1 ? $m[1] : $error;
    }

    private static function message(Throwable $e): string
    {
        return $e->getMessage() !== '' ? $e->getMessage() : $e::class;
    }

    /**
     * Retention after a backup that has just finished (docs/PHASE5.md 3.4). It must never turn a good backup into an error page.
     */
    private function retention(string $profileId): void
    {
        try {
            $profile = (new ProfileStore($this->services->data . '/profiles'))->load($profileId);
            RetentionService::create($this->services->data, $this->services->clock)->automatic($profile);
        } catch (Throwable) {
            // a profile that is gone or rules that cannot be applied: the backup stands; the profile page shows the rest
        }
    }

    /**
     * @param array<string, scalar|null> $details
     */
    private function audit(Context $context, string $event, array $details): void
    {
        $this->services->audit->record($event, $context->user() ?? '-', $context->request->remoteAddress, $details);
    }

    private function find(string $runId): ?Run
    {
        try {
            return $this->service()->find($runId);
        } catch (EngineException) {
            return null;
        }
    }

    private function missing(Context $context): Response
    {
        return View::page($context, 'Not found', '<p>There is no such backup run (it may have been dismissed). <a href="' . Html::e($context->urls->to('/profiles')) . '">Back to the profiles</a>.</p>', '/profiles', 404);
    }

    private function service(): BackupService
    {
        $data = $this->services->data;

        return $this->backups ??= new BackupService(new ProfileStore($data . '/profiles'), PluginRegistry::load(), new RunStore($data . '/runs'), $data, $this->services->budgets, $this->services->clock);
    }
}
