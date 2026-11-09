<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Crypto\Passphrases;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Web\BackupTarget;
use ThreadSafe\Web\BackupWizard;
use ThreadSafe\Web\BackupWorkspace;
use ThreadSafe\Web\Cleanup;

/**
 * `php threadsafe-backup.php backup` (docs/PHASE12.md 3.7): the backup tool in a shell. It makes the one profile of the tool from its options in a data
 * directory of its own that is removed afterwards, and hands over to {@see BackupCommand}, so that the run is the one of the app: in one process, with no time
 * limit and the database in one consistent snapshot.
 *
 * There is no key file: whoever has a shell is trusted already (as with `threadsafe-restore.php restore`). The rest of the rules of the web tool stand: only the
 * directory of the tool or one above it, the tool's own files left out, an encrypted archive unless the person says otherwise, no password as an option.
 */
final class ToolBackupCommand implements Command
{
    private readonly ?PluginRegistry $plugins;

    /**
     * @param string      $baseDirectory the directory the tool is in
     * @param string      $script        the file name of the tool
     * @param string|null $temporary     where the temporary data directory is made; default: the temporary directory of the system
     */
    public function __construct(
        private readonly string $baseDirectory,
        private readonly string $script = 'threadsafe-backup.php',
        ?PluginRegistry $plugins = null,
        private readonly ?string $temporary = null,
    ) {
        $this->plugins = $plugins;
    }

    public function name(): string
    {
        return 'backup';
    }

    public function summary(): string
    {
        return 'Back up the site this tool lies in (or one above it) into an archive';
    }

    public function usage(): string
    {
        return 'backup (--recipient=KEY | --passphrase-file=FILE | --no-encryption) [--root=DIR] [--storage=DIR] [options]';
    }

    public function options(): array
    {
        return [
            new OptionSpec('root', 'The directory of the site: the one the tool is in (the default) or one above it', takesValue: true, valueName: 'DIR'),
            new OptionSpec('storage', 'Where the archive goes (default: a directory with a random name next to the tool)', takesValue: true, valueName: 'DIR'),
            new OptionSpec('type', 'The website type (default: detect it): prestashop9, joomla, wordpress or generic', takesValue: true, valueName: 'TYPE'),
            new OptionSpec('recipient', 'Encrypt for this public key (age1...); may be given more than once', takesValue: true, valueName: 'KEY', repeatable: true),
            new OptionSpec('passphrase-file', 'Encrypt with the passphrase in this file, for the passphrase only (or set THREADSAFE_PASSPHRASE)', takesValue: true, valueName: 'FILE'),
            new OptionSpec('no-encryption', 'Make an archive that is not encrypted: anyone who finds it can read the site and its database'),
            new OptionSpec('exclude', 'Leave out what matches this pattern (relative to the site; a slash at the end: directories only); may be given more than once', takesValue: true, valueName: 'PATTERN', repeatable: true),
            new OptionSpec('part-size', 'Split the archive into parts of this size, e.g. 500M (default) or 2G', takesValue: true, valueName: 'SIZE'),
            new OptionSpec('no-db', 'The site has no database (for a website of no known kind)'),
            new OptionSpec('db-host', 'The database host, for a website of no known kind (default: localhost)', takesValue: true, valueName: 'HOST'),
            new OptionSpec('db-port', 'The database port (default: 3306)', takesValue: true, valueName: 'PORT'),
            new OptionSpec('db-name', 'The database name', takesValue: true, valueName: 'NAME'),
            new OptionSpec('db-user', 'The database user', takesValue: true, valueName: 'USER'),
            new OptionSpec('db-prefix', 'Only the tables that begin with this belong to the site (default: all)', takesValue: true, valueName: 'PREFIX'),
            new OptionSpec('db-password-file', 'A file holding the database password (or set THREADSAFE_DB_PASSWORD)', takesValue: true, valueName: 'FILE'),
            new OptionSpec('force', 'Go on although the storage seems to have too little room'),
            new OptionSpec('report', 'Write the result as JSON to this file', takesValue: true, valueName: 'FILE'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        if ($input->arguments() !== []) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[0] . '".');
        }
        $plugins = $this->plugins ?? PluginRegistry::load();
        $target = new BackupTarget($this->baseDirectory, $this->script, BackupWizard::KEY_FILE);
        $recipients = $this->encryption($input);
        $shared = $this->shared();
        $partSize = $shared->partSize($input);

        $data = rtrim($this->temporary ?? sys_get_temp_dir(), '/\\') . '/threadsafe-backup-' . bin2hex(random_bytes(8));
        if (!@mkdir($data . '/profiles', 0700, true) && !is_dir($data . '/profiles')) {
            throw new GuardFailed("Cannot make the temporary directory \"$data\".");
        }
        try {
            [$profile, $storage] = $shared->profile($input, $target, $plugins, $data, $recipients, $partSize);
            (new ProfileStore($data . '/profiles'))->save($profile);
            $output->writeln('Site: ' . $profile->path . ' (' . $profile->type . '); archive in ' . $storage);

            $passed = ['site'];
            if ($input->has('force')) {
                $passed[] = '--force';
            }
            foreach (['report', 'passphrase-file'] as $name) {
                if ($input->has($name)) {
                    $passed[] = '--' . $name . '=' . (string) $input->value($name);
                }
            }
            $backup = new BackupCommand($data, $plugins);

            return $backup->run(Input::parse($passed, $backup->options()), $output);
        } finally {
            Cleanup::run([], $data);
        }
    }

    /**
     * What the archive is encrypted for. One of the three ways must be chosen: an archive that anyone can read is a decision, not a default.
     *
     * @return list<string> the public keys; empty for a passphrase or no encryption
     *
     * @throws UsageError
     */
    private function encryption(Input $input): array
    {
        $recipients = array_values(array_unique($input->values('recipient')));
        $passphrase = KeyOptions::passphrase($input);
        $chosen = (int) ($recipients !== []) + (int) ($passphrase !== null) + (int) $input->has('no-encryption');
        if ($chosen === 0) {
            throw new UsageError('Say who may open the backup: --recipient=KEY (a public key, age1...), --passphrase-file=FILE, or --no-encryption. The archive holds the database access and the customer data of the site.');
        }
        if ($chosen > 1) {
            throw new UsageError('Give one of --recipient, --passphrase-file (THREADSAFE_PASSPHRASE counts) and --no-encryption.');
        }
        $this->shared()->recipients($input);   // the form of every key
        if ($passphrase !== null && ($weak = Passphrases::weakness($passphrase)) !== null) {
            throw new UsageError($weak);
        }

        return $recipients;
    }

    private function shared(): ToolProfileOptions
    {
        return new ToolProfileOptions(BackupWorkspace::in($this->baseDirectory));
    }
}
