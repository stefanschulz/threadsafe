<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\Admin\AdminStore;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Admin\Config;
use ThreadSafe\Admin\ConfigException;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Paths;
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
        return 'The web interface: set the administrator password, switch the interface on or off, show its state';
    }

    public function usage(): string
    {
        return 'admin password [--password-file=FILE] | enable | disable | status';
    }

    public function options(): array
    {
        return [
            new OptionSpec('password-file', 'password: file holding the new password (- = standard input; or set THREADSAFE_ADMIN_PASSWORD)', takesValue: true, valueName: 'FILE'),
            new OptionSpec('json', 'status: output as JSON'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $action = $input->argument(0) ?? throw new UsageError('Say what to do: password, enable, disable or status.');
        if (count($input->arguments()) > 1) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[1] . '".');
        }
        $data = $this->dataDirectory ?? Paths::data();

        try {
            return match ($action) {
                'password' => $this->password($data, $input, $output),
                'enable' => $this->enable($data, true, $output),
                'disable' => $this->enable($data, false, $output),
                'status' => $this->status($data, $input, $output),
                default => throw new UsageError("Unknown action \"$action\" (password, enable, disable, status)."),
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

    private function status(string $data, Input $input, Output $output): ExitCode
    {
        $config = Config::load($data . '/config.json');
        $state = [
            'enabled' => $config->enabled(),
            'administrator' => (new AdminStore($data . '/admin.json'))->exists(),
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
