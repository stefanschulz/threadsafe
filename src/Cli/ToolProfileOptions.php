<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\App\ProfileService;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\EncryptionSettings;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Web\BackupTarget;
use ThreadSafe\Web\BackupWorkspace;

/**
 * The options that make the one profile of the backup tool in a shell: where the site is, what kind it is, where the archive goes, whom it is encrypted for,
 * what is left out, how big a part is, and a database that the site does not carry itself. `backup` ({@see ToolBackupCommand}) and `agent`
 * ({@see ToolAgentCommand}) read them the same way.
 */
final class ToolProfileOptions
{
    public function __construct(private readonly BackupWorkspace $workspace)
    {
    }

    /**
     * @throws UsageError
     */
    public function partSize(Input $input): ?int
    {
        if (!$input->has('part-size')) {
            return null;
        }
        $size = Budget::parseBytes((string) $input->value('part-size'));
        if ($size === null || $size < 1048576) {
            throw new UsageError('--part-size is a size such as 500M or 2G, at least 1M.');
        }

        return $size;
    }

    /**
     * The public keys of the options, checked.
     *
     * @return list<string>
     *
     * @throws UsageError a key is not a public key of the age format
     */
    public function recipients(Input $input): array
    {
        $recipients = array_values(array_unique($input->values('recipient')));
        foreach ($recipients as $recipient) {
            if (!EncryptionSettings::isRecipient($recipient)) {
                throw new UsageError('"' . $recipient . '" is not a public key of the age format (age1 and 58 more characters).');
            }
        }

        return $recipients;
    }

    /**
     * @param list<string> $recipients
     *
     * @return array{SiteProfile, string} the profile and the directory of the archive
     *
     * @throws UsageError
     */
    public function profile(Input $input, BackupTarget $target, PluginRegistry $plugins, string $data, array $recipients, ?int $partSize): array
    {
        try {
            $root = $target->resolve($input->value('root') ?? $target->defaultRoot());
            $service = new ProfileService(new ProfileStore($data . '/profiles'), $plugins, $data);
            $type = $input->value('type') ?? 'auto';
            [$type, $detection] = $type === 'auto' ? $service->autodetect($root) : [$type, $service->detect($type, $root)];
            $database = $detection->database === null ? $this->database($input) : null;
            $storage = $this->storage($input);

            return [
                $target->profile(
                    $root,
                    $type,
                    $storage,
                    $database,
                    $input->values('exclude'),
                    $partSize,
                    $recipients === [] ? null : $this->encryption($recipients),
                ),
                $storage,
            ];
        } catch (ProfileException $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<string> $recipients
     *
     * @throws ProfileException
     */
    private function encryption(array $recipients): EncryptionSettings
    {
        return EncryptionSettings::fromArray(['recipients' => $recipients]) ?? throw new ProfileException('No public key was given.');
    }

    /**
     * @throws ProfileException
     */
    private function storage(Input $input): string
    {
        $named = $input->value('storage');
        if ($named === null) {
            return $this->workspace->storage();
        }
        $normal = str_replace('\\', '/', $named);
        if (!str_starts_with($normal, '/') && preg_match('#^[A-Za-z]:/#', $normal) !== 1) {
            throw new ProfileException('--storage must be an absolute path.');
        }

        return rtrim($named, '/\\');
    }

    /**
     * The database of a website of no known kind, from the options; the password from a file or THREADSAFE_DB_PASSWORD, never from an option.
     *
     * @throws UsageError something is missing or wrong
     */
    private function database(Input $input): ?DatabaseSettings
    {
        $given = ['db-host', 'db-port', 'db-name', 'db-user', 'db-prefix', 'db-password-file'];
        if ($input->has('no-db')) {
            foreach ($given as $name) {
                if ($input->has($name)) {
                    throw new UsageError("--no-db and --$name do not go together.");
                }
            }

            return null;
        }
        if (!$input->has('db-name') || !$input->has('db-user')) {
            throw new UsageError('This site keeps its database access nowhere this tool can read it. Give --db-name and --db-user (and the password in a file: --db-password-file, or THREADSAFE_DB_PASSWORD), or --no-db if it has no database.');
        }
        $port = $input->value('db-port') ?? '3306';
        if (preg_match('/^\d{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            throw new UsageError('--db-port is a number from 1 to 65535.');
        }

        return new DatabaseSettings($input->value('db-host') ?? 'localhost', (int) $port, (string) $input->value('db-name'), (string) $input->value('db-user'), $this->databasePassword($input), $input->value('db-prefix') ?? '');
    }

    /**
     * @throws UsageError a file cannot be read
     */
    private function databasePassword(Input $input): string
    {
        if ($input->has('db-password-file')) {
            $file = (string) $input->value('db-password-file');
            $content = is_file($file) ? @file_get_contents($file) : false;
            if ($content === false) {
                throw new UsageError("Cannot read \"$file\".");
            }

            return rtrim($content, "\r\n");
        }
        $fromEnvironment = getenv('THREADSAFE_DB_PASSWORD');

        return is_string($fromEnvironment) ? $fromEnvironment : '';
    }
}
