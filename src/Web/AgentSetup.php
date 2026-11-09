<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use InvalidArgumentException;
use RuntimeException;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\SiteProfile;

/**
 * What a person on the host does to give the agent a profile and a pairing (docs/PHASE13.md 3.2): the page "Agent" of the wizard, the shell
 * (`threadsafe-backup.php agent`) and, later, the plugin all do it through this class, so that the rules are one.
 *
 * The profile of the agent is a copy of the profile `site` that the wizard or the shell has made (the site, its database, what is left out, the
 * public keys, the part size), **under the id of the central profile** (decision 3): the files of a backup carry that id, and the central catalog finds a
 * backup by it. The agent makes **encrypted backups only**: an archive that lies on a web host for a few hours, and travels over the web to another
 * server, is not left readable, and the host holds no secret key (it needs only the public key).
 */
final class AgentSetup
{
    public function __construct(private readonly BackupWorkspace $workspace)
    {
    }

    public function config(): AgentConfig
    {
        return AgentConfig::in($this->workspace->data());
    }

    /** Why the profile cannot be the profile of the agent, or null. */
    public static function problem(SiteProfile $source): ?string
    {
        if ($source->encryption === null) {   // a profile with keys has at least one: EncryptionSettings refuses an empty list
            return 'The agent makes encrypted backups only: choose "a public key" (or make a key pair) in the options of this tool, so that the archive is encrypted for it. The secret key stays with you; this host never holds it.';
        }

        return null;
    }

    /** The profile of the agent: the settings of `$source` under the id of the central profile. */
    public static function copyOf(SiteProfile $source, string $id): SiteProfile
    {
        return SiteProfile::fromArray([...$source->toArray(), 'id' => $id, 'name' => $id]);
    }

    /**
     * Makes the profile of the agent from `$source`, makes a new secret (an earlier pairing stops working) and returns the pairing string. The agent
     * stays as switched as it was: a person switches it on when the central side has the string.
     *
     * @param string $url the address of the tool as the central side reaches it (https://host/path/threadsafe-backup.php)
     *
     * @throws ProfileException         the profile cannot be the profile of the agent (the message says why)
     * @throws InvalidArgumentException the id of the central profile or the address is not valid
     * @throws RuntimeException         a file cannot be written
     */
    public function pair(SiteProfile $source, string $centralId, string $url): string
    {
        if (($problem = self::problem($source)) !== null) {
            throw new ProfileException($problem);
        }
        if (preg_match(SiteProfile::ID_PATTERN, $centralId) !== 1) {
            throw new InvalidArgumentException("\"$centralId\" is not a valid profile id (lower-case letters, digits, - and _; up to 40 characters).");
        }
        AgentPairing::checkAddress($url);   // before a secret is made: a pairing that cannot be given is not made
        $config = $this->config();
        $previous = $config->profile();
        $copy = self::copyOf($source, $centralId);
        $secret = $config->pair($centralId);   // validates the id before anything else is written
        $this->workspace->profiles()->save($copy);
        if ($previous !== '' && $previous !== $centralId && $this->workspace->profiles()->exists($previous)) {
            $this->workspace->profiles()->delete($previous);
        }

        return AgentPairing::encode($url, $secret, $centralId);
    }

    /**
     * Copies the settings of `$source` into the profile of the agent again (the person changed the site, the keys or what is left out), without a
     * new secret.
     *
     * @throws ProfileException the agent is not paired, or the profile cannot be the profile of the agent
     */
    public function sync(SiteProfile $source): void
    {
        $config = $this->config();
        if (!$config->isPaired()) {
            throw new ProfileException('The agent is not paired yet.');
        }
        if (($problem = self::problem($source)) !== null) {
            throw new ProfileException($problem);
        }
        $this->workspace->profiles()->save(self::copyOf($source, $config->profile()));
    }

    /** Forgets the pairing, switches the agent off and removes its profile (the backups that are on the host stay). */
    public function unpair(): void
    {
        $config = $this->config();
        $profile = $config->profile();
        $config->unpair();
        if ($profile !== '' && $this->workspace->profiles()->exists($profile)) {
            $this->workspace->profiles()->delete($profile);
        }
    }

    /**
     * The address of the tool for the pairing string, from what the request says: the scheme, the host and the path of the script.
     *
     * @return string|null null if the host header is not a host name (then the person gives the address)
     */
    public static function addressOf(Request $request, string $host): ?string
    {
        if ($host === '' || $request->path === '' || !str_starts_with($request->path, '/')) {
            return null;
        }

        return ($request->https ? 'https' : 'http') . '://' . $host . $request->path;
    }
}
