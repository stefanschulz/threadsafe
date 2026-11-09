<?php

declare(strict_types=1);

namespace ThreadSafe\Remote;

use Closure;
use ThreadSafe\Profile\Check;
use ThreadSafe\Profile\ConnectionTest;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\RemoteSettings;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Profile\TestReport;
use ThreadSafe\Web\AgentConfig;

/**
 * The test of a profile that is remote ({@see ConnectionTest} hands it over).
 */
final class RemoteConnectionTest
{
    /**
     * @param (Closure(RemoteSettings): AgentClient)|null $clients how to reach an agent (for tests; default: curl, or streams)
     */
    public function __construct(private readonly ConnectionTest $base, private readonly ?Closure $clients = null)
    {
    }

    /**
     * A remote profile (docs/PHASE13.md 3.3): there is no site here to look at; the agent on it is asked (`status`), and the storage and the PHP of this machine
     * are looked at as for any profile. What the agent says is checked for what a backup needs: that it answers, that its API is the one this machine speaks, that it
     * has a profile ready under the id of this one, and that the clocks are within the window of the signature.
     */
    public function run(SiteProfile $profile): TestReport
    {
        $remote = $profile->remote ?? throw new ProfileException('The profile is not a remote one.');
        $checks = [];
        $fingerprint = implode('-', str_split($remote->fingerprint(), 4));
        $checks[] = Check::info('Agent', 'address ' . $remote->url . ', fingerprint of the secret ' . $fingerprint . ' (compare it with the one on the site)');
        if (!$remote->isHttps()) {
            $checks[] = Check::warning('Agent', 'The address is plain http: the requests are signed and the archives are encrypted, but everything else travels in the clear.');
        }
        $client = $this->clients !== null ? ($this->clients)($remote) : new AgentClient($remote, function_exists('curl_init') ? new CurlTransport() : new StreamTransport());
        try {
            $status = $client->status();
        } catch (AgentException $e) {
            $advice = match ($e->kind) {
                AgentException::REFUSED => ' Look at the page "The agent" on the site, and pair again if the secret changed.',
                AgentException::CLOCK => ' The time of the site is ' . (isset($e->details['hostTime']) ? gmdate('Y-m-d H:i:s', (int) $e->details['hostTime']) . ' UTC' : 'not told') . ' and this machine\'s is ' . gmdate('Y-m-d H:i:s') . ' UTC: correct the clock of one of them.',
                default => '',
            };
            array_push($checks, Check::failure('Agent', $e->getMessage() . $advice));
            array_push($checks, ...$this->base->storage($profile, null), ...$this->base->environment(false));

            return new TestReport($profile->id, $checks);
        }

        $api = $status['api'] ?? null;
        $version = AgentConfig::API_VERSION;
        $checks[] = $api === $version
            ? Check::ok('Agent', 'answers: ThreadSafe ' . (is_string($status['tool'] ?? null) ? $status['tool'] : '?') . ' on PHP ' . (is_string($status['php'] ?? null) ? $status['php'] : '?') . ' (' . (is_string($status['sapi'] ?? null) ? $status['sapi'] : '?') . ')')
            : Check::failure('Agent', 'speaks version ' . var_export($api, true) . ' of its API and this ThreadSafe speaks version ' . $version . ': update the older side (the plugin or the tool on the site, or this ThreadSafe).');
        $remoteProfile = (array) ($status['profile'] ?? []);
        if (($remoteProfile['id'] ?? null) !== $profile->id) {
            $checks[] = Check::failure('Agent', 'The agent makes its backups under the profile id "' . (is_string($remoteProfile['id'] ?? null) ? $remoteProfile['id'] : '?') . '" and this profile is "' . $profile->id . '": the catalog finds a backup by that id. Pair again with the same id.');
        } elseif (($remoteProfile['ready'] ?? false) !== true) {
            $checks[] = Check::failure('Agent', 'The agent has no backup profile ready: finish the setup on the site (the page "The agent" of the tool, or the plugin).');
        } else {
            $checks[] = Check::ok('Agent', 'the profile "' . $profile->id . '" is ready on the site');
        }
        $time = $status['time'] ?? null;
        if (is_int($time)) {
            $difference = $time - time();
            $checks[] = abs($difference) <= 60
                ? Check::ok('Clock', 'the clock of the site differs by ' . $difference . ' s (the signature has a window of 120 s)')
                : (abs($difference) <= 100 ? Check::warning('Clock', 'the clock of the site differs by ' . $difference . ' s; the window of the signature is 120 s: correct one of the clocks soon') : Check::failure('Clock', 'the clock of the site differs by ' . $difference . ' s: every request would be refused'));
        }
        $limits = (array) ($status['limits'] ?? []);
        $checks[] = Check::info('Site', sprintf('max_execution_time %s, memory_limit %s', (string) ($limits['max_execution_time'] ?? '?'), (string) ($limits['memory_limit'] ?? '?')));
        $space = (array) ($status['space'] ?? []);
        if (is_int($space['free'] ?? null) || is_float($space['free'] ?? null)) {
            $checks[] = Check::info('Site', 'free space of the volume of the site: ' . self::megabytes((int) $space['free']) . ' (the quota of the account may be smaller: a full disk ends the backup on the site with a message)');
        }
        $checks[] = Check::info('Site', sprintf('%s backup(s) on the site; it keeps %s after one is safe here%s', is_int($status['backups'] ?? null) ? (string) $status['backups'] : '?', (string) ($status['keepOnHost'] ?? '?'), is_array($status['run'] ?? null) ? '; a backup is running there now' : ''));

        array_push($checks, ...$this->base->storage($profile, null), ...$this->base->environment(false));

        return new TestReport($profile->id, $checks);
    }

    private static function megabytes(int $bytes): string
    {
        return $bytes >= 1073741824 ? sprintf('%.1f GB', $bytes / 1073741824) : sprintf('%.0f MB', $bytes / 1048576);
    }
}
