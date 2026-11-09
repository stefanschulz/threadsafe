<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use InvalidArgumentException;
use RuntimeException;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Web\AgentConfig;
use ThreadSafe\Web\AgentPairing;
use ThreadSafe\Web\AgentSetup;
use ThreadSafe\Web\BackupTarget;
use ThreadSafe\Web\BackupWizard;
use ThreadSafe\Web\BackupWorkspace;

/**
 * `php threadsafe-backup.php agent` (docs/PHASE13.md 9.3, M1): the agent of the backup tool from a shell, for a host that has one. What the page "The agent"
 * of the wizard does on a host without: make the profile of the agent from the options of the site (under the id of the central profile), pair it and
 * print the pairing string, switch the agent on and off, and set who may ask and how many backups the host keeps.
 *
 * There is no key file: whoever has a shell is trusted already. The state is in the workspace next to the tool, or in the one named with `--workspace`
 * (a plugin keeps it elsewhere and the shell must look in the same place).
 */
final class ToolAgentCommand implements Command
{
    private readonly ?PluginRegistry $plugins;

    /**
     * @param string $baseDirectory the directory the tool is in
     * @param string $script        the file name of the tool
     */
    public function __construct(
        private readonly string $baseDirectory,
        private readonly string $script = 'threadsafe-backup.php',
        ?PluginRegistry $plugins = null,
    ) {
        $this->plugins = $plugins;
    }

    public function name(): string
    {
        return 'agent';
    }

    public function summary(): string
    {
        return 'Pair this tool with a central ThreadSafe and switch its agent on or off';
    }

    public function usage(): string
    {
        return 'agent [--setup --profile-id=ID --url=URL --recipient=KEY ...] | --on | --off | --unpair | [--allow=ADDRESS ...] [--keep-on-host=N] [--plain-http=yes|no]';
    }

    public function options(): array
    {
        return [
            new OptionSpec('setup', 'Make the profile of the agent from the options below, pair it and print the pairing string (a new secret every time)'),
            new OptionSpec('profile-id', 'The id of the profile on the central ThreadSafe; the backups are made under it', takesValue: true, valueName: 'ID'),
            new OptionSpec('url', 'The address of this tool as the central ThreadSafe reaches it (https://host/path/threadsafe-backup.php)', takesValue: true, valueName: 'URL'),
            new OptionSpec('on', 'Switch the agent on (it must be paired)'),
            new OptionSpec('off', 'Switch the agent off: it then answers nobody'),
            new OptionSpec('unpair', 'Forget the pairing and the profile of the agent and switch it off; the backups on this host stay'),
            new OptionSpec('allow', 'An address or range that may ask (203.0.113.7, 198.51.100.0/24, 2001:db8::/32); may be given more than once; replaces the list; --allow= with nothing lets every address ask', takesValue: true, valueName: 'ADDRESS', repeatable: true),
            new OptionSpec('keep-on-host', 'How many backups the host keeps after one is safe at the central ThreadSafe (0 to 20, default 1)', takesValue: true, valueName: 'N'),
            new OptionSpec('plain-http', 'Answer a request that does not come over https too: yes or no (default no)', takesValue: true, valueName: 'yes|no'),
            new OptionSpec('workspace', 'The workspace of the tool, if it is not the one next to it (a plugin keeps it elsewhere)', takesValue: true, valueName: 'DIR'),
            new OptionSpec('root', 'With --setup: the directory of the site: the one the tool is in (the default) or one above it', takesValue: true, valueName: 'DIR'),
            new OptionSpec('storage', 'With --setup: where the archives go (default: a directory with a random name in the workspace)', takesValue: true, valueName: 'DIR'),
            new OptionSpec('type', 'With --setup: the website type (default: detect it): prestashop9, joomla, wordpress or generic', takesValue: true, valueName: 'TYPE'),
            new OptionSpec('recipient', 'With --setup: encrypt for this public key (age1...); at least one; may be given more than once', takesValue: true, valueName: 'KEY', repeatable: true),
            new OptionSpec('exclude', 'With --setup: leave out what matches this pattern (relative to the site; a slash at the end: directories only)', takesValue: true, valueName: 'PATTERN', repeatable: true),
            new OptionSpec('part-size', 'With --setup: split the archive into parts of this size, e.g. 500M (default) or 2G', takesValue: true, valueName: 'SIZE'),
            new OptionSpec('no-db', 'With --setup: the site has no database (for a website of no known kind)'),
            new OptionSpec('db-host', 'With --setup: the database host, for a website of no known kind (default: localhost)', takesValue: true, valueName: 'HOST'),
            new OptionSpec('db-port', 'With --setup: the database port (default: 3306)', takesValue: true, valueName: 'PORT'),
            new OptionSpec('db-name', 'With --setup: the database name', takesValue: true, valueName: 'NAME'),
            new OptionSpec('db-user', 'With --setup: the database user', takesValue: true, valueName: 'USER'),
            new OptionSpec('db-prefix', 'With --setup: only the tables that begin with this belong to the site (default: all)', takesValue: true, valueName: 'PREFIX'),
            new OptionSpec('db-password-file', 'With --setup: a file holding the database password (or set THREADSAFE_DB_PASSWORD)', takesValue: true, valueName: 'FILE'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        if ($input->arguments() !== []) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[0] . '".');
        }
        $actions = array_values(array_filter(['setup', 'on', 'off', 'unpair'], static fn (string $name): bool => $input->has($name)));
        if (count($actions) > 1) {
            throw new UsageError('Give one of --setup, --on, --off and --unpair.');
        }
        $workspace = $this->workspace($input);
        $setup = new AgentSetup($workspace);
        $config = $setup->config();

        try {
            $this->applySettings($config, $input);
            match ($actions[0] ?? 'status') {
                'setup' => $this->pair($input, $output, $workspace, $setup),
                'on' => $this->switchOn($config, $output),
                'off' => $this->switchOff($config, $output),
                'unpair' => $this->unpair($setup, $output),
                default => $this->status($setup->config(), $workspace, $output),
            };
        } catch (InvalidArgumentException | ProfileException | RuntimeException $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }

        return ExitCode::Ok;
    }

    /**
     * @throws UsageError the path cannot be the workspace of this tool
     */
    private function workspace(Input $input): BackupWorkspace
    {
        $named = $input->value('workspace');
        if ($named === null) {
            return BackupWorkspace::in($this->baseDirectory);
        }
        if (($problem = BackupWorkspace::customProblem($named, $this->baseDirectory)) !== null) {
            throw new UsageError("--workspace cannot be used: $problem.");
        }

        return BackupWorkspace::at($named);
    }

    /**
     * @throws InvalidArgumentException a value is not valid
     */
    private function applySettings(AgentConfig $config, Input $input): void
    {
        if ($input->has('allow')) {
            $config->setAllow($input->values('allow'));
        }
        if ($input->has('keep-on-host')) {
            $keep = (string) $input->value('keep-on-host');
            if (preg_match('/^\d{1,2}$/D', $keep) !== 1) {
                throw new InvalidArgumentException('--keep-on-host is a whole number from 0 to 20.');
            }
            $config->setKeepOnHost((int) $keep);
        }
        if ($input->has('plain-http')) {
            $plain = strtolower((string) $input->value('plain-http'));
            if (!in_array($plain, ['yes', 'no'], true)) {
                throw new InvalidArgumentException('--plain-http is yes or no.');
            }
            $config->setPlainAllowed($plain === 'yes');
        }
    }

    private function pair(Input $input, Output $output, BackupWorkspace $workspace, AgentSetup $setup): void
    {
        foreach (['profile-id', 'url'] as $required) {
            if (!$input->has($required)) {
                throw new UsageError("--setup needs --$required.");
            }
        }
        $shared = new ToolProfileOptions($workspace);
        $recipients = $shared->recipients($input);
        if ($recipients === []) {
            throw new UsageError('--setup needs --recipient=KEY (a public key, age1...): the agent makes encrypted backups only, and this host never holds the secret key.');
        }
        $target = new BackupTarget($this->baseDirectory, $this->script, BackupWizard::KEY_FILE, $workspace->isCustom() ? $workspace->directory() : null);
        [$profile, $storage] = $shared->profile($input, $target, $this->plugins ?? PluginRegistry::load(), $workspace->data(), $recipients, $shared->partSize($input));

        $string = $setup->pair($profile, (string) $input->value('profile-id'), (string) $input->value('url'));
        $secret = $setup->config()->secret();

        $output->writeln('Site: ' . $profile->path . ' (' . $profile->type . '); archives in ' . $storage);
        $output->writeln('Paired with the profile "' . $setup->config()->profile() . '". Fingerprint of the secret: ' . AgentPairing::readable(AgentPairing::fingerprint($secret)));
        $output->writeln('');
        $output->writeln('The pairing string (it holds the secret: treat it like a password and give it to the central ThreadSafe):');
        $output->writeln($string);
        $output->writeln('');
        $output->writeln('The agent is OFF. Switch it on when the central ThreadSafe has the string: php ' . $this->script . ' agent --on');
    }

    private function switchOn(AgentConfig $config, Output $output): void
    {
        if (!$config->isPaired()) {
            throw new UsageError('The agent is not paired: run --setup first.');
        }
        $config->enable(true);
        $output->writeln('The agent is ON. It answers signed requests of the central ThreadSafe and nothing else.');
    }

    private function switchOff(AgentConfig $config, Output $output): void
    {
        $config->enable(false);
        $output->writeln('The agent is OFF. It answers nobody.');
    }

    private function unpair(AgentSetup $setup, Output $output): void
    {
        $setup->unpair();
        $output->writeln('The pairing is forgotten and the agent is off. The backups that lie on this host stay.');
    }

    private function status(AgentConfig $config, BackupWorkspace $workspace, Output $output): void
    {
        $output->writeln('Agent: ' . ($config->isOn() ? 'ON' : 'off') . ($config->isPaired() ? ', paired with the profile "' . $config->profile() . '" (fingerprint ' . AgentPairing::readable(AgentPairing::fingerprint($config->secret())) . ')' : ', not paired'));
        $output->writeln('Addresses that may ask: ' . ($config->allow() === [] ? 'every address' : implode(', ', $config->allow())));
        $output->writeln('Backups kept on this host after one is safe elsewhere: ' . $config->keepOnHost());
        $output->writeln('Plain http: ' . ($config->plainAllowed() ? 'answered' : 'not answered'));
        $output->writeln('Workspace: ' . $workspace->directory() . ($workspace->isCustom() ? ' (named)' : ''));
    }
}
