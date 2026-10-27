<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use Closure;
use Throwable;
use ThreadSafe\Core\Home;
use ThreadSafe\Core\Paths;
use ThreadSafe\Core\Version;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Update\Busy;
use ThreadSafe\Update\Diagnosis;
use ThreadSafe\Update\HealthResult;
use ThreadSafe\Update\HttpHealthCheck;
use ThreadSafe\Update\Releases;
use ThreadSafe\Update\Rollback;
use ThreadSafe\Update\UpdateException;
use ThreadSafe\Update\UpdateRun;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * The section "Update" of the System page (docs/PHASE7.md 3.5): the installed version and the layout, the upload of a package with the
 * checksum from the release page (or a package put into `data/updates/` by FTP), the update in steps with its progress, the result - an
 * update that was taken back says why - and the roll-back to the previous release. It makes no request to anyone but the installation's
 * own address (the health check), and nothing on its own.
 */
final class UpdateController
{
    public const int UPLOAD_LIMIT = 33554432;

    /** @var Closure(string, string): bool moves an uploaded file to where it is kept */
    private readonly Closure $move;

    /**
     * @param string|null                 $home    the home of the installation; default: the one the program runs in (null in a clone)
     * @param Closure(string, string): bool|null $move for tests: moves an uploaded file; default: move_uploaded_file
     * @param (Closure(string, string, string): HealthResult)|null $health for tests: the health check; default: the request to the own address
     */
    public function __construct(private readonly Services $services, private readonly ?string $home = null, ?Closure $move = null, private readonly ?Closure $health = null)
    {
        $this->move = $move ?? move_uploaded_file(...);
    }

    private function home(): ?string
    {
        return $this->home ?? Paths::home();
    }

    private function updates(): string
    {
        return $this->services->data . '/updates';
    }

    private function store(): RunStore
    {
        return new RunStore($this->services->data . '/runs');
    }

    // ------------------------------------------------------------------------------------------------- the section

    public function section(Context $context): string
    {
        $home = $this->home();
        if ($home === null) {
            return '<p>This ThreadSafe is installed from a clone of the repository (version <code>' . Html::e(Version::CURRENT) . '</code>). Update it with <code>git pull</code> and <code>composer install</code>; the update from a package is for an installation from a release.</p>';
        }
        $pointer = Home::readPointer($home);
        $html = '<p>Installed: version <strong>' . Html::e($pointer['release'] ?? Version::CURRENT) . '</strong>, home <code>' . Html::e($home) . '</code>.'
            . ($pointer !== null && $pointer['previous'] !== null ? ' The previous release <strong>' . Html::e($pointer['previous']) . '</strong> is kept for a roll-back.' : '') . '</p>';

        $run = $this->latest();
        if ($run !== null) {
            return $html . $this->runPanel($context, $run);
        }
        if (!is_writable($home) || !is_writable($home . '/releases')) {
            return $html . '<p class="flash error" role="alert">PHP cannot write in <code>' . Html::e($home) . '</code>. For an update the web server\'s user must be able to write there; until then use the command <code>php ' . Html::e($home) . '/bin/threadsafe update</code>, or unpack the package by hand.</p>';
        }
        if (($busy = Busy::reason($this->services->data)) !== null) {
            $html .= '<p class="flash warning">' . Html::e($busy) . ' An update waits until nothing runs.</p>';
        }

        $html .= '<h3>Update from a package</h3><p>Download <code>threadsafe-X.Y.Z.zip</code> and note its SHA-256 from the release page (<code>SHA256SUMS</code>). ThreadSafe makes no request to find a new version, and installs nothing unless the checksum you give is the one of the file. It changes nothing before the package is checked, and goes back by itself if the new version does not start.</p>'
            . '<form method="post" enctype="multipart/form-data" action="' . Html::e($context->urls->to('/system/update/upload')) . '"><input type="hidden" name="' . Csrf::FIELD . '" value="' . Html::e($context->csrf()) . '">'
            . '<input type="hidden" name="MAX_FILE_SIZE" value="' . self::UPLOAD_LIMIT . '">'
            . View::field('package', 'Package (threadsafe-X.Y.Z.zip)', 'file', '', ['required' => 'required', 'accept' => '.zip'])
            . View::field('sha256', 'SHA-256 of the package', 'text', '', ['required' => 'required', 'autocomplete' => 'off', 'spellcheck' => 'false', 'pattern' => '[0-9A-Fa-f]{64}'], 'The 64 characters from the release page.')
            . $this->options()
            . '<button type="submit">Upload and check</button></form>';

        $waiting = glob($this->updates() . '/*.zip') ?: [];
        if ($waiting !== []) {
            $html .= '<h3>A package that is on the server already</h3><p>For a package larger than the upload limit of the host, or put there by FTP: <code>' . Html::e($this->updates()) . '</code>.</p>'
                . View::form($context, '/system/update/start', View::select('file', 'File', array_combine(array_map('basename', $waiting), array_map(static fn (string $p): string => basename($p) . ' (' . SpaceCheck::format((int) filesize($p)) . ')', $waiting)), basename($waiting[0]))
                    . View::field('sha256', 'SHA-256 of the package', 'text', '', ['required' => 'required', 'autocomplete' => 'off', 'spellcheck' => 'false'])
                    . $this->options() . '<button type="submit">Check and update</button>');
        } else {
            $html .= '<p class="hint">A package larger than the upload limit of this host (' . Html::e((string) ini_get('upload_max_filesize')) . ') can be put into <code>' . Html::e($this->updates()) . '</code> by FTP; it is then offered here.</p>';
        }
        if ($pointer !== null && $pointer['previous'] !== null && Releases::usable($home, $pointer['previous'])) {
            $html .= '<h3>Go back</h3><p>Makes version ' . Html::e($pointer['previous']) . ' the active one again. The data stays as it is.</p>'
                . View::form($context, '/system/update/rollback', '<button type="submit" class="danger">Go back to ' . Html::e($pointer['previous']) . '</button>', 'inline');
        }

        return $html;
    }

    /** The two choices of an update: an older version, and no request to the own address to check the new one. */
    private function options(): string
    {
        return '<div class="field check"><label><input type="checkbox" name="downgrade" value="1"> Allow an older version than the installed one</label></div>'
            . '<div class="field check"><label><input type="checkbox" name="skipcheck" value="1"> Do not start the new version to check it</label>'
            . '<p class="hint">Normally the new version is asked once, through this site\'s own address, and the update is taken back if it does not answer. Tick this on a host that cannot reach its own address (the update then reports "no answer"). Nothing is taken back by itself afterwards: if this page does not open, go back with <code>php bin/threadsafe update --rollback</code> or by writing the previous release into <code>current.php</code>.</p></div>';
    }

    private function runPanel(Context $context, Run $run): string
    {
        $state = $run->state;
        if ($run->status === RunStatus::Running) {
            return '<div id="run" data-tick-url="' . Html::e($context->urls->to('/system/update/tick')) . '">'
                . '<p>Updating to version <strong>' . Html::e((string) ($state['version'] ?: '?')) . '</strong>. Keep this page open; the update works in steps. Nothing is switched before the package is unpacked and checked.</p>'
                . '<progress id="run-progress" max="100" value="' . $this->percent($run) . '">' . $this->percent($run) . ' %</progress>'
                . '<p id="run-label" class="hint" aria-live="polite">' . Html::e((string) $run->progress) . '</p><p id="run-note" class="hint"></p>'
                . '<div class="actions" id="run-actions">' . View::form($context, '/system/update/tick', '<button type="submit">Do the next step</button>', 'inline') . '</div></div>';
        }
        if ($run->status === RunStatus::Failed) {
            return '<p class="flash error" role="alert">The update did not go on: ' . Html::e((string) $run->error) . '</p>'
                . '<p>The installation is as it was. Fix the cause, and try again or give it up.</p><div class="actions">'
                . View::form($context, '/system/update/retry', '<button type="submit">Try again</button>', 'inline') . ' '
                . View::form($context, '/system/update/discard', '<button type="submit" class="secondary">Give it up</button>', 'inline') . '</div>';
        }
        $result = (array) ($state['result'] ?? []);
        $body = ($result['rolledBack'] ?? false)
            ? $this->takenBack($result)
            : '<p class="flash ok">Updated from <strong>' . Html::e((string) ($result['from'] ?? '?')) . '</strong> to <strong>' . Html::e((string) ($result['to'] ?? '?')) . '</strong>.</p><p>' . Html::e((string) ($result['message'] ?? '')) . '</p>'
                . (($result['health'] ?? '') === HealthResult::SKIPPED ? '<p class="flash warning">The new version could not be started to check it. If this page does not open after you leave it, go back with <code>php bin/threadsafe update --rollback</code>, or write the previous release into <code>current.php</code>.</p>' : '')
                . '<p>The previous release is kept for a roll-back. If you use restore.php, make a new one from the page "Restore".</p>';

        return $body . '<div class="actions">' . View::form($context, '/system/update/discard', '<button type="submit" class="secondary">Close</button>', 'inline') . '</div>';
    }

    /**
     * An update that was taken back, as one card: what happened and that nothing is lost, what the check reported (set apart, as it was said), the cause in
     * plain words, and the way on in a part that opens on request.
     *
     * @param array<string, mixed> $result
     */
    private function takenBack(array $result): string
    {
        $message = (string) ($result['message'] ?? '');
        $diagnosis = Diagnosis::explain($message);
        $steps = '';
        foreach ($diagnosis['steps'] as $step) {
            $steps .= '<li>' . self::rich($step) . '</li>';
        }

        return '<section class="failure" role="alert"><div class="failure-head">' . Html::symbol('error') . '<div>'
            . '<h3>Version <strong>' . Html::e((string) ($result['to'] ?? '?')) . '</strong> did not start, so it was taken back</h3>'
            . '<p>Version <strong>' . Html::e((string) ($result['from'] ?? '?')) . '</strong> is active again, with the state files as they were. Nothing is lost.</p></div></div>'
            . '<div class="failure-reason"><span class="failure-label">What the check reported</span><code>' . Html::e($message) . '</code></div>'
            . '<p class="failure-cause">' . Html::e($diagnosis['cause']) . '</p>'
            . '<details class="failure-help"><summary>What you can do</summary><ol>' . $steps . '</ol></details></section>';
    }

    /** Text of the diagnosis as HTML: escaped, with `code` and **bold** from their marks. */
    private static function rich(string $text): string
    {
        $html = Html::e($text);
        $html = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

        return (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html);
    }

    // ------------------------------------------------------------------------------------------------- actions

    /**
     * @param array<string, string> $params
     */
    public function upload(Context $context, array $params = []): Response
    {
        $file = $context->request->files['package'] ?? null;
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return $this->back($context, 'error', $file === null ? 'No file arrived.' : $this->uploadError($file['error']));
        }
        if ($file['size'] > self::UPLOAD_LIMIT) {
            return $this->back($context, 'error', 'The file is larger than ' . SpaceCheck::format(self::UPLOAD_LIMIT) . '; put it into ' . $this->updates() . ' by FTP.');
        }
        if (!is_dir($this->updates()) && !@mkdir($this->updates(), 0700, true) && !is_dir($this->updates())) {
            return $this->back($context, 'error', 'Cannot create the directory ' . $this->updates() . '.');
        }
        $target = $this->updates() . '/upload-' . bin2hex(random_bytes(6)) . '.zip';
        if (!($this->move)($file['tmp_name'], $target)) {
            return $this->back($context, 'error', 'The uploaded file cannot be stored in ' . $this->updates() . '.');
        }
        @chmod($target, 0600);

        return $this->begin($context, $target);
    }

    /**
     * @param array<string, string> $params
     */
    public function start(Context $context, array $params = []): Response
    {
        $name = basename((string) $context->request->post('file'));
        $path = $this->updates() . '/' . $name;
        if (preg_match('/^[A-Za-z0-9._-]+\.zip$/', $name) !== 1 || !is_file($path)) {
            return $this->back($context, 'error', 'Choose one of the packages in ' . $this->updates() . '.');
        }

        return $this->begin($context, $path);
    }

    private function begin(Context $context, string $path): Response
    {
        $home = $this->home();
        if ($home === null) {
            return $this->back($context, 'error', 'This installation is not from a release; there is nothing to update from a package.');
        }
        if ($this->latest() !== null) {
            return $this->back($context, 'error', 'An update is open already. Finish or give it up first.');
        }
        $request = $context->request;
        $task = new UpdateRun();
        try {
            $run = $this->store()->create($task->id(), $task->initialState([
                'file' => $path,
                'sha256' => (string) $request->post('sha256'),
                'home' => $home,
                'data' => $this->services->data,
                'allowDowngrade' => $request->post('downgrade') === '1',
                'skipHealthCheck' => $request->post('skipcheck') === '1',
            ]));
        } catch (Throwable $e) {
            return $this->back($context, 'error', $e->getMessage());
        }
        $this->audit($context, 'update.started', ['run' => $run->id, 'file' => basename($path)]);

        return Response::redirect($context->urls->to('/system', ['tab' => 'update']));
    }

    /**
     * One tick for the script of the page, as JSON.
     *
     * @param array<string, string> $params
     */
    public function tick(Context $context, array $params = []): Response
    {
        $run = $this->latest();
        $json = $context->request->post('format') === 'json' || $context->request->header('x-csrf-token') !== null;
        if ($run === null || $run->status !== RunStatus::Running) {
            return $json ? Response::json(['status' => 'finished']) : Response::redirect($context->urls->to('/system', ['tab' => 'update']));
        }
        $answer = ['status' => 'running'];
        try {
            $run = $this->executor($context)->execute($run->id, 1);
        } catch (EngineException $e) {
            $answer = $e->isBusy() ? ['status' => 'busy', 'label' => 'Another request is working on the update; waiting.'] : ['status' => 'failed', 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            $this->audit($context, 'update.failed', ['run' => $run->id, 'reason' => $e->getMessage()]);
            $answer = ['status' => 'failed', 'error' => $e->getMessage()];
        }
        $run = $this->store()->load($run->id);
        if ($run->status === RunStatus::Finished) {
            $result = (array) ($run->state['result'] ?? []);
            $this->audit($context, ($result['rolledBack'] ?? false) ? 'update.rolled_back' : 'update.applied', ['from' => (string) ($result['from'] ?? ''), 'to' => (string) ($result['to'] ?? ''), 'health' => (string) ($result['health'] ?? '')]);
            $answer = ['status' => 'finished'];
        }
        $answer += ['percent' => $this->percent($run), 'label' => (string) $run->progress];

        return $json ? Response::json($answer) : Response::redirect($context->urls->to('/system', ['tab' => 'update']));
    }

    /**
     * @param array<string, string> $params
     */
    public function retry(Context $context, array $params = []): Response
    {
        $run = $this->latest();
        if ($run !== null && $run->status === RunStatus::Failed) {
            $this->executor($context)->retry($run->id);
        }

        return Response::redirect($context->urls->to('/system', ['tab' => 'update']));
    }

    /**
     * @param array<string, string> $params
     */
    public function discard(Context $context, array $params = []): Response
    {
        $run = $this->latest();
        $home = $this->home();
        if ($run !== null && $run->status !== RunStatus::Running) {
            $this->store()->delete($run->id);
            if ($home !== null && $run->status === RunStatus::Failed) {
                foreach (Releases::all($home) as $name) {
                    if (str_ends_with($name, '.partial')) {
                        Releases::remove($home, $name);
                    }
                }
            }
            if (is_string($run->state['file'] ?? null) && $run->status === RunStatus::Failed && str_starts_with((string) $run->state['file'], $this->updates() . '/upload-')) {
                @unlink((string) $run->state['file']);
            }
        }

        return Response::redirect($context->urls->to('/system', ['tab' => 'update']));
    }

    /**
     * @param array<string, string> $params
     */
    public function rollback(Context $context, array $params = []): Response
    {
        $home = $this->home();
        if ($home === null) {
            return $this->back($context, 'error', 'This installation is not from a release.');
        }
        try {
            $done = Rollback::run($home, $this->services->data);
        } catch (UpdateException $e) {
            return $this->back($context, 'error', $e->getMessage());
        }
        $this->audit($context, 'update.rolled_back', ['from' => $done['from'], 'to' => $done['to'], 'by' => 'hand']);

        return $this->back($context, 'ok', 'Version ' . $done['to'] . ' is active again.');
    }

    // ------------------------------------------------------------------------------------------------- helpers

    private function executor(Context $context): Executor
    {
        $request = $context->request;
        $host = $request->header('host') ?? '';
        $address = ($request->https ? 'https' : 'http') . '://' . $host . $context->urls->base;
        $health = $this->health ?? static function (string $home, string $data, string $version) use ($address, $host): HealthResult {
            return $host === '' ? HealthResult::skipped('The address of this site is not known.') : (new HttpHealthCheck($address, $host))->check($data, $version);
        };

        return new Executor($this->store(), new UpdateRun($health, $this->services->clock), $this->services->budgets ?? Budget::forThisProcess(...));
    }

    private function latest(): ?Run
    {
        $runs = $this->store()->all('update');

        return $runs === [] ? null : $runs[array_key_last($runs)];
    }

    private function percent(Run $run): int
    {
        if ($run->status === RunStatus::Finished) {
            return 100;
        }
        $state = $run->state;
        $ratio = (int) ($state['expected'] ?? 0) > 0 ? min(1.0, (int) ($state['files'] ?? 0) / (int) $state['expected']) : 0.0;

        return match ($state['phase'] ?? 'verify') {
            'extract' => (int) round(5 + 85 * $ratio),
            'switch' => 95,
            default => 2,
        };
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the host allows to upload (' . ini_get('upload_max_filesize') . '). Put it into ' . $this->updates() . ' by FTP.',
            UPLOAD_ERR_NO_FILE => 'No file was chosen.',
            UPLOAD_ERR_PARTIAL => 'The file arrived only in part; try again.',
            default => 'The upload failed (PHP error ' . $code . ').',
        };
    }

    private function back(Context $context, string $type, string $message): Response
    {
        $context->flash($type, $message);

        return Response::redirect($context->urls->to('/system', ['tab' => 'update']));
    }

    /**
     * @param array<string, string> $details
     */
    private function audit(Context $context, string $event, array $details): void
    {
        $this->services->audit->record($event, $context->user() ?? '-', $context->request->remoteAddress, $details);
    }
}
