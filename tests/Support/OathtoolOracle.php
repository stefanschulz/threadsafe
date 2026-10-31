<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

/**
 * `oathtool` (the package oathtool of the tools image, the OATH Toolkit) as the oracle of the one-time codes: an implementation of RFC 6238
 * that is not ours, so that a code of ours and a code of an app are known to be the same thing.
 */
final class OathtoolOracle
{
    public static function available(): bool
    {
        exec('oathtool --version 2>&1', $output, $code);

        return $code === 0;
    }

    /**
     * The six-digit code of a key (Base32) at a moment.
     *
     * @param int|null $time seconds since 1970; null = now
     */
    public static function code(string $key, ?int $time = null): string
    {
        $command = 'oathtool --totp -b' . ($time === null ? '' : ' --now=' . escapeshellarg('@' . $time)) . ' ' . escapeshellarg($key) . ' 2>&1';
        exec($command, $output, $code);

        return $code === 0 ? trim(implode('', $output)) : throw new \RuntimeException('oathtool failed: ' . implode(' ', $output));
    }
}
