<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use Closure;
use ThreadSafe\Auth\PasskeyStore;
use ThreadSafe\Web\Request;
use ThreadSafe\Auth\TwoFactorStore;
use ThreadSafe\Web\Sessions;

/**
 * The parts of the web interface that work on the data directory, built once from its location. The controllers get this and
 * need to know no paths.
 */
final readonly class Services
{
    public const int SESSION_LIFETIME = 43200;

    public Config $config;

    public AdminStore $admin;

    public TwoFactorStore $twoFactor;

    public PasskeyStore $passkeys;

    public AuditLog $audit;

    public Sessions $sessions;

    /** @var Closure(): int */
    public Closure $clock;

    /** @var (Closure(): \ThreadSafe\Engine\Budget)|null the budget of a tick; null = a share of the request time limit */
    public ?Closure $budgets;

    /**
     * @param Closure(): int|null                               $clock   for tests: the time in seconds
     * @param (Closure(): \ThreadSafe\Engine\Budget)|null           $budgets for tests: the budget of a tick
     *
     * @throws ConfigException the configuration file is damaged
     */
    public function __construct(public string $data, ?Closure $clock = null, ?Closure $budgets = null)
    {
        $this->budgets = $budgets;
        $this->clock = $clock ?? static fn (): int => time();
        $this->config = Config::load($data . '/config.json');
        $this->admin = new AdminStore($data . '/admin.json');
        $this->twoFactor = new TwoFactorStore($data . '/two-factor.json', $this->clock);
        $this->passkeys = new PasskeyStore($data . '/passkeys.json', $this->clock);
        $this->audit = new AuditLog($data . '/audit.log', $this->config->auditMaxBytes(), $this->clock);
        $this->sessions = new Sessions($data . '/sessions', $this->clock, $this->config->sessionIdleSeconds(), self::SESSION_LIFETIME);
    }

    /**
     * Where passkeys are bound: the origin of the setting, or the one that the request shows.
     *
     * @throws \ThreadSafe\Auth\PasskeyException the page cannot be used for passkeys
     */
    public function passkeyHost(Request $request): PasskeyHost
    {
        return PasskeyHost::fromRequest($request, $this->config->passkeyOrigin());
    }

    public function configFile(): string
    {
        return $this->data . '/config.json';
    }

    /** The file the person puts into the data directory to allow the setup in the browser. */
    public function setupKeyFile(): string
    {
        return $this->data . '/setup-key.php';
    }

    public function throttleDirectory(): string
    {
        return $this->data . '/throttle';
    }
}
