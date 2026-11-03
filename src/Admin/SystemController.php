<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\App\TriggerHelp;
use ThreadSafe\App\TriggerStatus;
use ThreadSafe\App\WorkerLauncher;
use ThreadSafe\Core\Paths;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Schedule\CronSecret;
use ThreadSafe\Schedule\Heartbeat;
use ThreadSafe\Schedule\Scheduler;
use ThreadSafe\Schedule\ScheduleException;
use ThreadSafe\Schedule\ScheduleStore;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * The page "System" (docs/PHASE4.md 3.9, 3.10) in four sections, one at a time: general (the installation check, the trigger's state, the
 * question whether `data/` can be fetched from outside), cron (the command, the address, the secret), settings, and activity (the audit log).
 */
final class SystemController
{
    /** The sections of the page: id => name. The first is the one that opens without `?tab=`. */
    private const array TABS = ['general' => 'General', 'cron' => 'Cron', 'settings' => 'Settings', 'update' => 'Update', 'activity' => 'Activity'];

    private readonly UpdateController $update;

    public function __construct(private readonly Services $services)
    {
        $this->update = new UpdateController($services);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Context $context, array $params = []): Response
    {
        return $this->page($context, [], null);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(Context $context, array $params = []): Response
    {
        $request = $context->request;
        $values = [
            'idle' => trim((string) $request->post('idle')),
            'pretty' => $request->post('pretty') === '1' ? '1' : '0',
            'require_2fa' => $request->post('require_2fa') === '1' ? '1' : '0',
            'days' => trim((string) $request->post('days')),
            'warn_mb' => trim((string) $request->post('warn_mb')),
            'accel' => trim((string) $request->post('accel')),
            'prefix' => trim((string) $request->post('prefix')),
            'audit_mb' => trim((string) $request->post('audit_mb')),
        ];

        $config = $this->services->config;
        $errors = [];
        $set = function (string $key, mixed $value) use (&$config, &$errors): void {
            try {
                $config = $config->with($key, $value);
            } catch (ConfigException $e) {
                $errors[] = $e->getMessage();
            }
        };
        $set('web.sessionIdleMinutes', $values['idle']);
        $set('web.prettyUrls', $values['pretty'] === '1');
        $set('web.requireTwoFactor', $values['require_2fa'] === '1');
        $set('dashboard.warnAfterDays', $values['days']);
        $warn = self::megabytes($values['warn_mb'], 'Warn above', $errors);
        if ($warn !== null) {
            $set('download.warnAbove', $warn);
        }
        $set('download.accel', $values['accel']);
        $set('download.accelPrefix', $values['prefix']);
        $audit = self::megabytes($values['audit_mb'], 'Audit log size', $errors);
        if ($audit !== null) {
            $set('audit.maxBytes', $audit);
        }
        // the settings of the trigger are on the page since phase 5; a form that does not send them leaves them alone
        foreach (['tz' => 'schedule.timezone', 'grace' => 'schedule.graceMinutes', 'interval' => 'schedule.expectedIntervalMinutes', 'cron_mode' => 'cron.mode'] as $field => $key) {
            if ($request->post($field) !== null) {
                $values[$field] = trim((string) $request->post($field));
                $set($key, $values[$field]);
            }
        }

        if ($errors !== []) {
            return $this->page($context, array_values(array_unique($errors)), $values, 400, 'settings');
        }

        $changed = [];
        foreach ($config->all() as $key => $value) {
            if ($value !== $this->services->config->get($key)) {
                $changed[] = $key;
            }
        }
        try {
            $config->save($this->services->configFile());
        } catch (ConfigException $e) {
            return $this->page($context, [$e->getMessage()], $values, 500, 'settings');
        }
        if ($changed !== []) {
            $this->services->audit->record('settings.changed', $context->user() ?? '-', $context->request->remoteAddress, ['changed' => implode(', ', $changed)]);
        }
        $context->flash('ok', $changed === [] ? 'Nothing was changed.' : 'The settings were saved.');

        return Response::redirect($context->urls->to('/system', ['tab' => 'settings']));
    }

    // ------------------------------------------------------------------------------------------------- the page

    /**
     * @param list<string>               $errors
     * @param array<string, string>|null $values the form as it was sent (after a mistake); null = the stored settings
     * @param string|null                $tab    the section to show; null = the one the address asks for
     */
    private function page(Context $context, array $errors, ?array $values, int $status = 200, ?string $tab = null): Response
    {
        $tab ??= Tabs::current($context, self::TABS);
        $content = match ($tab) {
            'cron' => '<h2>Cron</h2>' . $this->cron($context),
            'settings' => '<h2>Settings</h2>' . $this->settings($context, $errors, $values),
            'update' => '<h2>Update</h2>' . $this->update->section($context),
            'activity' => '<h2>Recent activity</h2>' . View::audit($this->services->audit->recent(50)),
            default => $this->general($context),
        };

        return View::page($context, 'System', Tabs::render($context, '/system', self::TABS, $tab, 'Sections of the System page') . $content, '/system', $status);
    }

    /** The general section: the installation check, how the trigger is doing, and whether the data directory can be fetched. */
    private function general(Context $context): string
    {
        $request = $context->request;
        $checks = new SystemChecks($this->services);
        $probeWritten = $checks->writeProbe();
        $scriptDirectory = dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $storages = $checks->writeStorageProbes($checks->storagesBelow($scriptDirectory));

        return '<h2>Installation check</h2>' . View::checks($checks->run($request->https, $request->isLocal()))
            . '<h2>Trigger</h2>' . $this->trigger()
            . '<h2>Can the data directory and the backups be fetched from outside?</h2>' . $this->probe($context, $probeWritten, $storages, $scriptDirectory);
    }

    /**
     * Makes a new secret for the trigger address, or removes it.
     *
     * @param array<string, string> $params
     */
    public function cronSecret(Context $context, array $params = []): Response
    {
        $secret = new CronSecret($this->services->data . '/cron-secret');
        try {
            if ($context->request->post('action') === 'remove') {
                $secret->remove();
                $this->services->audit->record('cron.secret.removed', $context->user() ?? '-', $context->request->remoteAddress);
                $context->flash('ok', 'The secret is removed; the trigger address no longer exists.');
            } else {
                $secret->make();
                $this->services->audit->record('cron.secret.made', $context->user() ?? '-', $context->request->remoteAddress);
                $context->flash('ok', 'A new secret was made; an older one no longer works.');
            }
        } catch (ScheduleException $e) {
            $context->flash('error', $e->getMessage());
        }

        return Response::redirect($context->urls->to('/system', ['tab' => 'cron']));
    }

    /** How the trigger is doing: the last call, the warning, how many profiles have a schedule. */
    private function trigger(): string
    {
        $services = $this->services;
        $config = $services->config;
        $profiles = (new ProfileStore($services->data . '/profiles'))->all();
        $status = (new TriggerStatus(new Scheduler(new ScheduleStore($services->data . '/schedule'), $config->scheduleZone(), $config->scheduleGraceMinutes()), new Heartbeat($services->data . '/heartbeat.json'), $config->expectedIntervalMinutes(), $services->clock))->build($profiles);

        $html = '';
        if ($status['last'] === null) {
            $html .= '<p>The trigger has not called yet.</p>';
        } else {
            $html .= '<p>The trigger called ' . Html::e(View::age($status['last']['age'])) . ' (' . ($status['last']['via'] === 'web' ? 'by the address' : 'by the command') . '): ' . Html::e($status['last']['note']) . '</p>';
        }
        if ($status['warning'] !== null) {
            $html .= '<p class="flash warning">' . Html::e($status['warning']) . '</p>';
        }

        return $html . '<p class="hint">' . $status['scheduled'] . ' of ' . count($profiles) . ' profiles have a schedule. Time zone of the schedules: ' . Html::e($config->scheduleZone()->getName()) . '. This host '
            . (WorkerLauncher::available() ? 'can start a worker process, so a call of the address can run the backup outside the request' : 'cannot start a worker process, so a call of the address does one tick of work') . '. How to call the trigger is on the Cron tab.</p>';
    }

    /** A line of code with a button that copies it to the clipboard (the script of the pages does the copying). */
    private static function copyable(string $id, string $text): string
    {
        return '<p class="copyline"><code id="' . Html::e($id) . '">' . Html::e($text) . '</code> <button type="button" class="secondary small" data-copy="#' . Html::e($id) . '">Copy</button></p>';
    }

    /** The cron section: what to put into the crontab of the host (the command, or the address with its secret) and the secret itself. */
    private function cron(Context $context): string
    {
        $services = $this->services;
        $html = '<p class="hint">Scheduled backups happen only when something calls the trigger: a cron job that runs <code>threadsafe cron</code>, or one that fetches the address below. Either is enough; one call a day is enough for a daily schedule.</p>';

        $secret = (new CronSecret($services->data . '/cron-secret'))->get();
        $html .= '<h3>The command</h3>' . self::copyable('cron-command', TriggerHelp::commandLine()) . '<p class="hint">The line for a crontab. The command ends at once if another trigger is working, and ends with an error code when a backup failed.</p>';
        $html .= '<h3>The address</h3>';
        if ($secret === null) {
            $html .= '<p>There is no secret, so there is no address. Make one if the cron of the host can only fetch an address.</p>'
                . View::form($context, '/system/cron-secret', '<input type="hidden" name="action" value="make"><button type="submit">Make the secret</button>', 'inline');
        } else {
            $request = $context->request;
            $address = ($request->https ? 'https' : 'http') . '://' . ($request->header('host') ?? 'YOUR-HOST') . $context->urls->to(TriggerHelp::path($secret));
            $html .= self::copyable('cron-address', $address) . self::copyable('cron-address-line', TriggerHelp::addressLine($address))
                . '<p class="hint">The address works while the interface is switched off. The secret is in every access log of the web server that sees the address; it starts backups and nothing else, and a new one replaces it.</p>'
                . View::form($context, '/system/cron-secret', '<input type="hidden" name="action" value="make"><button type="submit">Make a new secret</button>', 'inline') . ' '
                . View::form($context, '/system/cron-secret', '<input type="hidden" name="action" value="remove"><button type="submit">Remove the secret</button>', 'inline');
        }

        return $html;
    }

    /**
     * The addresses where the data directory would be served if it could be fetched: below the directory of the program (a clone with its `public/` or the
     * whole program below the web root), and - for an installation from a release whose home lies **below the directory of the stub** (the weaker layout,
     * closed only by an `.htaccess`) - where the home really is.
     *
     * @param string      $base            the path of the directory `index.php` is in, without a trailing slash
     * @param string|null $home            the home of the installation from a release, or null
     * @param string      $scriptDirectory the directory on the disk that `index.php` is in
     *
     * @return list<string>
     */
    public static function probeUrls(string $base, ?string $home, string $scriptDirectory): array
    {
        $parent = $base === '' ? '' : substr($base, 0, (int) strrpos($base, '/'));
        $urls = [$parent . '/data/probe.txt', $base . '/data/probe.txt'];
        $directory = rtrim(str_replace('\\', '/', $scriptDirectory), '/');
        if ($home !== null && $directory !== '' && str_starts_with($home . '/', $directory . '/')) {
            $urls[] = $base . substr($home, strlen($directory)) . '/data/probe.txt';
        }

        return array_values(array_unique($urls));
    }

    /**
     * The addresses where a storage directory would be served, for the storages that lie below the directory of `index.php` (the document root, or below it):
     * the probe file `probe.txt` of the directory.
     *
     * @param string       $base            the path of the directory `index.php` is in, without a trailing slash
     * @param string       $scriptDirectory the directory on the disk that `index.php` is in
     * @param list<string> $storages        directories on the disk, as {@see SystemChecks::storagesBelow()} gives them
     *
     * @return list<string>
     */
    public static function storageProbeUrls(string $base, string $scriptDirectory, array $storages): array
    {
        $root = rtrim(str_replace('\\', '/', (string) (realpath($scriptDirectory) ?: $scriptDirectory)), '/');
        $urls = [];
        foreach ($storages as $storage) {
            if ($root === '' || !str_starts_with($storage . '/', $root . '/')) {
                continue;
            }
            $path = array_filter(explode('/', substr($storage, strlen($root))), static fn (string $part): bool => $part !== '');
            $urls[] = $base . ($path === [] ? '' : '/' . implode('/', array_map(rawurlencode(...), $path))) . '/probe.txt';
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param list<string> $storages the storage directories of the profiles that lie below the directory of `index.php`
     */
    private function probe(Context $context, bool $written, array $storages, string $scriptDirectory): string
    {
        if (!$written) {
            return '<p class="flash warning">The probe file could not be written to the data directory, so this cannot be tested.</p>';
        }
        $items = '';
        $urls = [...self::probeUrls($context->urls->base, Paths::home(), $scriptDirectory), ...self::storageProbeUrls($context->urls->base, $scriptDirectory, $storages)];
        foreach ($urls as $url) {
            $items .= '<li data-probe="' . Html::e($url) . '" data-expect="' . Html::e(SystemChecks::PROBE) . '"><code>' . Html::e($url) . '</code>: <span class="result">needs script to check</span></li>';
        }
        $backups = $storages === []
            ? ''
            : ' A storage directory of a profile lies below the web root, so it is tested too: a web server that does not read <code>.htaccess</code> files (nginx) hands out the backups in it unless a rule of its own closes it. Keep the storage outside the web root, or close it with such a rule.';

        return '<p class="hint">The page asks your browser for a small file in the data directory at the addresses where it would be served if this program lay below the web root. "Not reachable" is what you want to see. If the document root is the directory <code>public</code>, nothing in <code>data</code> can be fetched at all.' . $backups . '</p><ul class="plain">' . $items . '</ul>';
    }

    /**
     * @param list<string>               $errors
     * @param array<string, string>|null $values
     */
    private function settings(Context $context, array $errors, ?array $values): string
    {
        $config = $this->services->config;
        $values ??= [
            'idle' => (string) $config->get('web.sessionIdleMinutes'),
            'pretty' => $config->prettyUrls() ? '1' : '0',
            'require_2fa' => $config->requireTwoFactor() ? '1' : '0',
            'days' => (string) $config->get('dashboard.warnAfterDays'),
            'warn_mb' => self::trim((int) $config->get('download.warnAbove') / 1048576),
            'accel' => $config->downloadAccel(),
            'prefix' => $config->downloadAccelPrefix(),
            'audit_mb' => self::trim((int) $config->get('audit.maxBytes') / 1048576),
        ];
        // the form as it was sent has the trigger's settings only if the page that sent it had them
        $values += ['tz' => (string) $config->get('schedule.timezone'), 'grace' => (string) $config->get('schedule.graceMinutes'), 'interval' => (string) $config->get('schedule.expectedIntervalMinutes'), 'cron_mode' => $config->cronMode()];

        $inner = '';
        foreach ($errors as $error) {
            $inner .= '<p class="flash error" role="alert">' . Html::e($error) . '</p>';
        }
        $inner .= View::field('idle', 'Log out after this many idle minutes', 'text', $values['idle'], ['inputmode' => 'numeric'], '5 to 1440. A session also ends after 12 hours.')
            . View::field('days', 'Warn when there is no successful backup for this many days', 'text', $values['days'], ['inputmode' => 'numeric'])
            . View::field('warn_mb', 'Warn about downloads above (MB)', 'text', $values['warn_mb'], ['inputmode' => 'decimal'], 'The download page then recommends fetching the files from the server.')
            . View::select('accel', 'Let the web server send downloads', ['none' => 'No, PHP sends them', 'sendfile' => 'X-Sendfile (Apache mod_xsendfile)', 'nginx' => 'X-Accel-Redirect (nginx)'], $values['accel'], 'Only if the web server is set up for it; test with a small file.')
            . View::field('prefix', 'Internal location for X-Accel-Redirect', 'text', $values['prefix'], ['spellcheck' => 'false', 'placeholder' => '/ts-files/'], 'nginx: location /ts-files/ { internal; alias /; }')
            . View::field('audit_mb', 'Rotate the audit log at this size (MB)', 'text', $values['audit_mb'], ['inputmode' => 'decimal'])
            . View::field('tz', 'Time zone of the schedules', 'text', $values['tz'], ['spellcheck' => 'false', 'placeholder' => date_default_timezone_get()], 'For example Europe/Berlin. Empty: the time zone of PHP (' . date_default_timezone_get() . ').')
            . View::field('grace', 'How late a scheduled backup may still be made up (minutes)', 'text', $values['grace'], ['inputmode' => 'numeric'], '1 to 10080. A scheduled time older than this is given up.')
            . View::field('interval', 'The trigger is meant to come every (minutes)', 'text', $values['interval'], ['inputmode' => 'numeric'], '1 to 1440; 1440 for a cron job that runs once a day. The warning on the dashboard comes when it stays away twice as long.')
            . View::select('cron_mode', 'How the trigger address works', ['auto' => 'Automatic (a worker process where the host allows it, otherwise one tick per call)', 'worker' => 'Start a worker process (the backup runs outside the request)', 'ticks' => 'One tick of work per call'], $values['cron_mode'], 'Only the address is affected; the command line trigger always works in one process.')
            . View::checkbox('require_2fa', 'Require the second factor for every login', $values['require_2fa'] === '1', 'A login without a second factor then reaches only the Security page, where it can be set up, until one is on.')
            . View::checkbox('pretty', 'Use plain addresses (/profiles/shop)', $values['pretty'] === '1', 'Needs the rewrite rule of public/.htaccess (or the equivalent for nginx). If pages stop working after changing this, set "web.prettyUrls" to false in data/config.json.')
            . '<button type="submit">Save the settings</button>';

        return View::form($context, '/system/settings', $inner, 'wide');
    }

    /**
     * @param list<string> $errors
     */
    private static function megabytes(string $text, string $label, array &$errors): ?int
    {
        if (preg_match('/^\d{1,6}(\.\d{1,3})?$/', $text) !== 1) {
            $errors[] = "$label must be a number of megabytes.";

            return null;
        }

        return (int) round((float) $text * 1048576);
    }

    private static function trim(float $number): string
    {
        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }
}
