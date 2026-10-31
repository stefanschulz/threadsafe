<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\Admin\AdminStore;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Admin\Config;
use ThreadSafe\Admin\ConfigException;
use ThreadSafe\Auth\TwoFactorException;
use ThreadSafe\Auth\TwoFactorStore;
use ThreadSafe\App\TriggerHelp;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Paths;
use ThreadSafe\Schedule\CronSecret;
use ThreadSafe\Schedule\ScheduleException;
use ThreadSafe\Web\StateDirectory;

/**
 * `threadsafe admin password | enable | disable | status`: the administrator of the web interface and whether the interface is
 * switched on (docs/PHASE4.md 3.1, 3.2). The password is never an option: it comes from a prompt, `--password-file=FILE` (`-` reads
 * the standard input) or the environment variable THREADSAFE_ADMIN_PASSWORD.
 */
final class AdminCommand implements Command
{
    /**
     * @param string|null $dataDirectory default: the data directory of the program
     */
    public function __construct(private readonly ?string $dataDirectory = null)
    {
    }

    public function name(): string
    {
        return 'admin';
    }

    public function summary(): string
    {
        return 'The web interface and the installation: set the administrator password, switch the interface on or off, show or change the settings';
    }

    public function usage(): string
    {
        return 'admin password [--password-file=FILE] | enable | disable | status | set KEY VALUE | cron-secret [--show | --remove] | two-factor [status | --disable]';
    }

    public function options(): array
    {
        return [
            new OptionSpec('password-file', 'password: file holding the new password (- = standard input; or set THREADSAFE_ADMIN_PASSWORD)', takesValue: true, valueName: 'FILE'),
            new OptionSpec('json', 'status: output as JSON'),
            new OptionSpec('show', 'cron-secret: show the secret of the trigger address again, make none'),
            new OptionSpec('remove', 'cron-secret: remove the secret, so that the trigger address no longer exists'),
            new OptionSpec('disable', 'two-factor: switch the second factor of the login off (the way back when the phone is lost)'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $action = $input->argument(0) ?? throw new UsageError('Say what to do: password, enable, disable, status or set.');
        $allowed = match ($action) {
            'set' => 3,
            'two-factor' => 2,
            default => 1,
        };
        if (count($input->arguments()) > $allowed) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[$allowed] . '".');
        }
        $data = $this->dataDirectory ?? Paths::data();

        try {
            return match ($action) {
                'password' => $this->password($data, $input, $output),
                'enable' => $this->enable($data, true, $output),
                'disable' => $this->enable($data, false, $output),
                'status' => $this->status($data, $input, $output),
                'cron-secret' => $this->cronSecret($data, $input, $output),
                'two-factor' => $this->twoFactor($data, $input, $output),
                'set' => $this->set($data, $input->argument(1) ?? throw new UsageError('admin set needs a setting and a value, e.g. schedule.timezone Europe/Berlin ("admin status" lists the settings).'), $input->argument(2) ?? throw new UsageError('admin set needs a value after the setting (an empty one is written as "").'), $output),
                default => throw new UsageError("Unknown action \"$action\" (password, enable, disable, status, set, cron-secret, two-factor)."),
            };
        } catch (ConfigException $e) {
            throw new GuardFailed($e->getMessage(), 0, $e);
        }
    }

    private function password(string $data, Input $input, Output $output): ExitCode
    {
        $password = $this->readPassword($input, $output);
        StateDirectory::prepare($data);
        (new AdminStore($data . '/admin.json'))->setPassword(AdminStore::DEFAULT_USER, $password);
        $config = Config::load($data . '/config.json');
        (new AuditLog($data . '/audit.log', $config->auditMaxBytes()))->record('password.changed', AdminStore::DEFAULT_USER, '', ['source' => 'command line']);

        $output->writeln('The password of "' . AdminStore::DEFAULT_USER . '" is set.');
        if (!$config->enabled()) {
            $output->writeln('The web interface is still switched off: "admin enable" turns it on.');
        }

        return ExitCode::Ok;
    }

    private function enable(string $data, bool $enabled, Output $output): ExitCode
    {
        $admin = new AdminStore($data . '/admin.json');
        if ($enabled && !$admin->exists()) {
            throw new GuardFailed('There is no administrator yet: set a password first with "admin password".');
        }
        StateDirectory::prepare($data);
        $config = Config::load($data . '/config.json');
        $config->with('web.enabled', $enabled)->save($data . '/config.json');
        (new AuditLog($data . '/audit.log', $config->auditMaxBytes()))->record($enabled ? 'web.enabled' : 'web.disabled', '-', '', ['source' => 'command line']);
        $output->writeln($enabled ? 'The web interface is switched on.' : 'The web interface is switched off.');

        return ExitCode::Ok;
    }

    /**
     * Makes, shows or removes the secret of the trigger address (docs/PHASE5.md 3.3). Making a new one replaces the old one, so the
     * old address stops working. The secret is printed here and on the System page, and nowhere else (no log, no audit entry).
     */
    private function cronSecret(string $data, Input $input, Output $output): ExitCode
    {
        if ($input->has('show') && $input->has('remove')) {
            throw new UsageError('--show and --remove contradict each other.');
        }
        $store = new CronSecret($data . '/cron-secret');
        $audit = new AuditLog($data . '/audit.log', Config::load($data . '/config.json')->auditMaxBytes());
        if ($input->has('remove')) {
            $store->remove();
            $audit->record('cron.secret.removed', '-', '', ['source' => 'command line']);
            $output->writeln('The secret is removed; the trigger address no longer exists.');

            return ExitCode::Ok;
        }
        if ($input->has('show')) {
            $secret = $store->get() ?? throw new GuardFailed('There is no secret yet: run "admin cron-secret" to make one.');
        } else {
            StateDirectory::prepare($data);
            try {
                $secret = $store->make();
            } catch (ScheduleException $e) {
                throw new GuardFailed($e->getMessage(), 0, $e);
            }
            $audit->record('cron.secret.made', '-', '', ['source' => 'command line']);
            $output->writeln('A new secret was made; an older one no longer works.');
        }
        $output->writeln('');
        $output->writeln('Address (below the directory that holds index.php; works while the interface is switched off):');
        $output->writeln('  ' . TriggerHelp::path($secret));
        $output->writeln('A crontab line that calls the command (preferred where cron can start a program):');
        $output->writeln('  ' . TriggerHelp::commandLine());
        $output->writeln('A crontab line that fetches the address (where cron can only fetch one; use your own host and path):');
        $output->writeln('  ' . TriggerHelp::addressLine('https://YOUR-HOST/PATH-TO-PUBLIC' . TriggerHelp::path($secret)));
        $output->writeln('The secret is in every access log that sees the address. It starts backups and nothing else; make a new one to replace it.');

        return ExitCode::Ok;
    }

    /**
     * `admin two-factor [status]` tells whether the second factor of the login is on; `--disable` switches it off, which is the way back when
     * the phone is lost and the recovery codes are gone (docs/PHASE11.md 3.5). Switching it on is done in the browser only, so that the key
     * is never on a terminal or in a log. A damaged file does not stop `--disable`: it is the thing that is removed.
     */
    private function twoFactor(string $data, Input $input, Output $output): ExitCode
    {
        $word = $input->argument(1);
        if ($word !== null && $word !== 'status') {
            throw new UsageError('two-factor takes "status" or --disable (switching it on is done on the Security page).');
        }
        if ($input->has('disable') && $word !== null) {
            throw new UsageError('--disable and "status" contradict each other.');
        }
        $file = $data . '/two-factor.json';
        $store = new TwoFactorStore($file);

        if (!$input->has('disable')) {
            $state = $this->twoFactorState($store);
            $output->writeln('Second factor:  ' . $state['text']);
            if ($state['damaged']) {
                throw new GuardFailed('The file of the second factor cannot be read, so the web login is refused. Repair it, or run "admin two-factor --disable".');
            }

            return ExitCode::Ok;
        }

        try {
            $was = $store->disable(AdminStore::DEFAULT_USER);
            $damaged = false;
        } catch (TwoFactorException) {
            // a file that cannot be read is what is in the way: it goes
            if (!@unlink($file) && is_file($file)) {
                throw new GuardFailed("Cannot remove the file \"$file\". Delete it by hand.");
            }
            $was = true;
            $damaged = true;
        }
        if (!$was) {
            $output->writeln('The second factor was not on.');

            return ExitCode::Ok;
        }
        StateDirectory::prepare($data);
        (new AuditLog($data . '/audit.log', Config::load($data . '/config.json')->auditMaxBytes()))->record('twofactor.disabled', AdminStore::DEFAULT_USER, '', ['source' => 'command line']);
        $output->writeln($damaged ? 'The file of the second factor was damaged and is removed. The second factor is off.' : 'The second factor is off. The login asks for the password only.');

        return ExitCode::Ok;
    }

    /**
     * @return array{on: bool, damaged: bool, text: string, enrolled: string|null, recoveryLeft: int}
     */
    private function twoFactorState(TwoFactorStore $store): array
    {
        try {
            if (!$store->isOn(AdminStore::DEFAULT_USER)) {
                return ['on' => false, 'damaged' => false, 'text' => 'off', 'enrolled' => null, 'recoveryLeft' => 0];
            }
            $enrolled = $store->enrolled(AdminStore::DEFAULT_USER);
            $left = $store->recoveryLeft(AdminStore::DEFAULT_USER);

            return ['on' => true, 'damaged' => false, 'text' => 'on' . ($enrolled !== null && $enrolled !== '' ? " since $enrolled" : '') . " ($left recovery " . ($left === 1 ? 'code' : 'codes') . ' left)', 'enrolled' => $enrolled, 'recoveryLeft' => $left];
        } catch (TwoFactorException $e) {
            return ['on' => false, 'damaged' => true, 'text' => 'DAMAGED (' . $e->getMessage() . ')', 'enrolled' => null, 'recoveryLeft' => 0];
        }
    }

    private function set(string $data, string $key, string $value, Output $output): ExitCode
    {
        StateDirectory::prepare($data);
        $config = Config::load($data . '/config.json')->with($key, $value);
        $config->save($data . '/config.json');
        (new AuditLog($data . '/audit.log', $config->auditMaxBytes()))->record('settings.changed', '-', '', ['source' => 'command line', 'setting' => $key]);
        $output->writeln("$key is now " . var_export($config->get($key), true) . '.');

        return ExitCode::Ok;
    }

    private function status(string $data, Input $input, Output $output): ExitCode
    {
        $config = Config::load($data . '/config.json');
        $second = $this->twoFactorState(new TwoFactorStore($data . '/two-factor.json'));
        $state = [
            'enabled' => $config->enabled(),
            'administrator' => (new AdminStore($data . '/admin.json'))->exists(),
            'twoFactor' => ['on' => $second['on'], 'damaged' => $second['damaged'], 'enrolled' => $second['enrolled'], 'recoveryLeft' => $second['recoveryLeft']],
            'setupKeyFile' => is_file($data . '/setup-key.php'),
            'passwordAlgorithm' => AdminStore::algorithmName(),
            'settings' => $config->all(),
        ];
        if ($input->has('json')) {
            $output->writeln((string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ExitCode::Ok;
        }
        $output->writeln('Web interface:  ' . ($state['enabled'] ? 'on' : 'off'));
        $output->writeln('Administrator:  ' . ($state['administrator'] ? 'set (' . $state['passwordAlgorithm'] . ')' : 'not set'));
        $output->writeln('Second factor:  ' . $second['text']);
        if ($state['setupKeyFile']) {
            $output->writeln('Setup key file: present (the browser setup is open until an administrator exists; delete the file to close it)');
        }
        foreach ($state['settings'] as $key => $value) {
            $output->writeln(sprintf('  %-26s %s', $key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
        }

        return ExitCode::Ok;
    }

    private function readPassword(Input $input, Output $output): string
    {
        if ($input->has('password-file')) {
            $file = (string) $input->value('password-file');
            $content = $file === '-' ? stream_get_contents(STDIN) : (is_file($file) ? @file_get_contents($file) : false);
            if ($content === false) {
                throw new UsageError("Cannot read the password from \"$file\".");
            }

            return rtrim($content, "\r\n");
        }
        $environment = getenv('THREADSAFE_ADMIN_PASSWORD');
        if (is_string($environment) && $environment !== '') {
            return $environment;
        }
        if (!self::canPrompt()) {
            throw new UsageError('Give the password in a file (--password-file=FILE, - for the standard input) or in THREADSAFE_ADMIN_PASSWORD; there is no terminal to ask on.');
        }

        $first = self::prompt('New password: ');
        if (self::prompt('Again: ') !== $first) {
            throw new GuardFailed('The two passwords are not the same.');
        }

        return $first;
    }

    private static function canPrompt(): bool
    {
        return PHP_OS_FAMILY !== 'Windows' && defined('STDIN') && function_exists('posix_isatty') && posix_isatty(STDIN) && function_exists('shell_exec');
    }

    /** Asks for a line without showing it (`stty -echo`, and echo is switched on again whatever happens). */
    private static function prompt(string $question): string
    {
        fwrite(STDERR, $question);
        shell_exec('stty -echo 2>/dev/null');
        try {
            $line = fgets(STDIN);
        } finally {
            shell_exec('stty echo 2>/dev/null');
            fwrite(STDERR, "\n");
        }

        return rtrim($line === false ? '' : $line, "\r\n");
    }
}
