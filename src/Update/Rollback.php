<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

use ThreadSafe\Core\Home;
use ThreadSafe\Engine\Lock;

/**
 * Goes back to the previous release by hand (docs/PHASE7.md 3.5): the pointer is written back. The data is not touched: a release must read
 * the data of the one before it (3.6), so there is nothing to put back, and what was stored since the update is kept.
 */
final class Rollback
{
    /**
     * @return array{from: string, to: string} the release that was active and the one that is active now
     *
     * @throws UpdateException there is nothing to go back to, or something is running
     */
    public static function run(string $home, string $data): array
    {
        $pointer = Home::readPointer($home) ?? throw new UpdateException('The pointer to the active release is missing or damaged; there is nothing to go back from.');
        $previous = $pointer['previous'] ?? throw new UpdateException('There is no previous release to go back to.');
        if (!Releases::usable($home, $previous)) {
            throw new UpdateException("The previous release $previous is not on this host any more.");
        }
        if (($busy = Busy::reason($data)) !== null) {
            throw new UpdateException($busy);
        }
        $lock = Lock::tryAcquire(rtrim($data, '/\\') . '/locks/update.lock', 'rollback, pid ' . getmypid());
        if ($lock === null) {
            throw new UpdateException('An update or a roll-back is working (' . (Lock::holder(rtrim($data, '/\\') . '/locks/update.lock') ?? 'holder unknown') . ').');
        }
        try {
            Home::writePointer($home, $previous, null);
        } finally {
            $lock->release();
        }

        return ['from' => $pointer['release'], 'to' => $previous];
    }

    private function __construct()
    {
    }
}
