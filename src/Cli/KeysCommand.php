<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\AgeReader;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Identity;
use ThreadSafe\Crypto\Passphrases;
use ThreadSafe\Crypto\PassphraseIdentity;
use ThreadSafe\Crypto\X25519Identity;
use ThreadSafe\Storage\SpaceCheck;

/**
 * `threadsafe keys generate | passphrase | check`: the keys of encrypted backups (docs/PHASE5.md 3.5). A key pair or a passphrase is made
 * here and shown once; the server keeps neither. The key file is the file of `age-keygen`, so the real `age` tool opens a backup with it.
 */
final class KeysCommand implements Command
{
    public function name(): string
    {
        return 'keys';
    }

    public function summary(): string
    {
        return 'Keys for encrypted backups: make a key pair or a passphrase, check that a key opens a file';
    }

    public function usage(): string
    {
        return 'keys generate [--output=FILE] | keys passphrase [--output=FILE] | keys check <file.age> --identity-file=FILE | --passphrase-file=FILE';
    }

    public function options(): array
    {
        return [
            new OptionSpec('output', 'generate, passphrase: write to this file (mode 0600, it must not exist) instead of the screen', takesValue: true, valueName: 'FILE'),
            ...KeyOptions::specs('check'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $action = $input->argument(0) ?? throw new UsageError('Say what to do: generate, passphrase or check.');
        $allowed = $action === 'check' ? 2 : 1;
        if (count($input->arguments()) > $allowed) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[$allowed] . '".');
        }

        try {
            return match ($action) {
                'generate' => $this->generate($input, $output),
                'passphrase' => $this->passphrase($input, $output),
                'check' => $this->check($input->argument(1) ?? throw new UsageError('keys check needs the encrypted file.'), $input, $output),
                default => throw new UsageError("Unknown action \"$action\" (generate, passphrase, check)."),
            };
        } catch (CryptoException $e) {
            throw new GuardFailed($e->getMessage(), 0, $e);
        }
    }

    private function generate(Input $input, Output $output): ExitCode
    {
        $pair = AgeKeys::generate();
        $content = AgeKeys::keyFile($pair['secret'], $pair['public']);
        if ($input->has('output')) {
            $file = $this->write((string) $input->value('output'), $content);
            $output->writeln("The key pair was written to $file (mode 0600).");
        } else {
            $output->writeln(rtrim($content));
        }
        $output->writeln('');
        $output->writeln('Public key (goes into the profile: profile edit <id> --recipient=' . $pair['public'] . '):');
        $output->writeln('  ' . $pair['public']);
        $output->writeln('');
        $output->writeln('The secret key opens the encrypted backups, and nothing else does. This server does not keep it: store it somewhere else (a password manager, a printout,');
        $output->writeln('another machine). If it is lost, the backups made for this public key are lost - nobody, not even the author of this program, can open them.');

        return ExitCode::Ok;
    }

    private function passphrase(Input $input, Output $output): ExitCode
    {
        $passphrase = Passphrases::generate();
        if ($input->has('output')) {
            $file = $this->write((string) $input->value('output'), $passphrase . "\n");
            $output->writeln("The passphrase was written to $file (mode 0600).");
        } else {
            $output->writeln($passphrase);
        }
        $output->writeln('');
        $output->writeln('About 99 bits; easy to read aloud and type. It is not kept on this server: store it somewhere else. If it is lost, the backups made with it are lost.');

        return ExitCode::Ok;
    }

    private function check(string $file, Input $input, Output $output): ExitCode
    {
        $handle = is_file($file) ? @fopen($file, 'rb') : false;
        if ($handle === false) {
            throw new UsageError("Cannot read \"$file\".");
        }
        try {
            $reader = AgeReader::open($handle, $this->identities($input));
            $reader->chunk(0);
        } finally {
            fclose($handle);
        }
        $output->writeln(sprintf('The key opens this file: %s of data in %d block%s; the first block checks out.', SpaceCheck::format($reader->size()), $reader->chunkCount(), $reader->chunkCount() === 1 ? '' : 's'));
        $output->writeln('(A full check of a backup, "verify --identity-file=...", reads every block.)');

        return ExitCode::Ok;
    }

    /**
     * @return list<Identity>
     */
    private function identities(Input $input): array
    {
        return KeyOptions::identities($input) ?? throw new UsageError('Say what opens the file: --identity-file=FILE (a key file), --passphrase-file=FILE, or the environment variable THREADSAFE_PASSPHRASE.');
    }

    /**
     * Writes a new file with the mode 0600 from the start, and never over an existing one.
     */
    private function write(string $file, string $content): string
    {
        $old = umask(0177);
        try {
            $handle = @fopen($file, 'xb');
        } finally {
            umask($old);
        }
        if ($handle === false) {
            throw new GuardFailed("Cannot write \"$file\": the file exists already, or the directory does not allow it. An existing key file is never overwritten.");
        }
        $written = fwrite($handle, $content);
        fclose($handle);
        if ($written !== strlen($content)) {
            @unlink($file);

            throw new GuardFailed("Cannot write \"$file\" (disk full?).");
        }

        return $file;
    }
}
