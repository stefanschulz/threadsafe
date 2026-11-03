<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use Closure;
use Throwable;
use ThreadSafe\App\TriggerService;
use ThreadSafe\Auth\TwoFactorException;
use ThreadSafe\Core\Paths;
use ThreadSafe\Core\Version;
use ThreadSafe\Schedule\CronSecret;
use ThreadSafe\Update\HttpHealthCheck;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;
use ThreadSafe\Web\StateDirectory;
use ThreadSafe\Web\Throttle;

/**
 * The web interface as a function from a request to a response (docs/PHASE4.md 3): decides whether the interface exists at all,
 * who is logged in, whether a request that changes something is allowed, and hands it to the page that answers it. Every response
 * leaves with the security headers.
 *
 * Three states of the installation decide what a visitor can see:
 *
 *   - **off** (the default; or on without an administrator and without a setup key): every address is "not found" and nothing is
 *     written. An unpacked archive on a web server must not offer a login.
 *   - **setup** (no administrator, and the person has put `setup-key.php` into the data directory): the setup page and nothing else.
 *   - **on** (an administrator exists and `web.enabled` is true): the login and the pages behind it. With `web.enabled` true and
 *     no administrator and no setup key, a page says how to set a password.
 */
final class Kernel
{
    private const string CSP = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'";

    /**
     * @param string              $data  the data directory
     * @param string              $base  the path of the directory `index.php` is in ("" or "/threadsafe/public")
     * @param Closure(): int|null                     $clock   for tests: the time in seconds
     * @param (Closure(): \ThreadSafe\Engine\Budget)|null $budgets for tests: the budget of a tick
     */
    public function __construct(private readonly string $data, private readonly string $base = '', private readonly ?Closure $clock = null, private readonly ?Closure $budgets = null)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->secure($this->dispatch($request));
        } catch (Throwable $e) {
            // the message of an exception can name paths; only a logged-in person gets it
            $shown = $this->loggedIn($request) ? $e->getMessage() : 'Please try again later.';

            return $this->secure(View::error('Something went wrong', $shown, 500));
        }
    }

    private function dispatch(Request $request): Response
    {
        try {
            $services = new Services($this->data, $this->clock, $this->budgets);
        } catch (ConfigException $e) {
            return View::error('The configuration cannot be read', $e->getMessage(), 500);
        }
        $urls = new Urls($this->base, $services->config->prettyUrls(), Version::CURRENT, Paths::home() !== null);
        $route = $urls->route($request);
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;

        if (str_starts_with($route, '/cron/')) {
            // the address of the trigger exists whether or not the interface is switched on, and only while it has a secret
            $answer = $this->trigger($services, $request, $method, substr($route, strlen('/cron/')));
            if ($answer !== null) {
                return $answer;
            }
        }

        if ($method === 'GET' && str_starts_with($route, '/health/')) {
            // the question of the update after the switch (docs/PHASE7.md 3.5): answered to its own token only, whether or not the interface is on
            $answer = HttpHealthCheck::answer($this->data, substr($route, strlen('/health/')), Version::CURRENT, ($services->clock)());
            if ($answer !== null) {
                return Response::json($answer)->withHeader('Cache-Control: no-store');
            }
        }

        $auth = new AuthController($services);
        if (!$services->admin->exists()) {
            if (is_file($services->setupKeyFile())) {
                StateDirectory::prepare($this->data);
                $router = new Router();
                $router->add('GET', '/setup', $auth->setupPage(...), public: true);
                $router->add('POST', '/setup', $auth->setup(...), public: true);
                $context = new Context($request, $urls);

                return $route === '/setup' ? $this->run($router, $method, $route, $context, null) : Response::redirect($urls->to('/setup'));
            }
            if (!$services->config->enabled()) {
                return $this->notFound();
            }
            StateDirectory::prepare($this->data);

            return $auth->noAdministrator(new Context($request, $urls));
        }
        if (!$services->config->enabled()) {
            return $this->notFound();
        }
        StateDirectory::prepare($this->data);

        $token = $request->cookie(AuthController::COOKIE);
        $session = $services->sessions->load($token);
        $context = new Context($request, $urls, $session, $session === null ? null : $token);

        return $this->run($this->router($services, $auth), $method, $route, $context, $services);
    }

    /**
     * `GET /cron/<secret>` (docs/PHASE5.md 3.3): the trigger. A wrong secret gets the same answer as any address that does not exist and
     * is slowed down like a wrong password; nothing is revealed about whether there is a secret at all.
     *
     * @return Response|null null: there is no secret, so there is no such address and the request is handled like any other
     */
    private function trigger(Services $services, Request $request, string $method, string $candidate): ?Response
    {
        $secret = new CronSecret($services->data . '/cron-secret');
        if (!$secret->exists()) {
            return null;
        }
        if ($method !== 'GET') {
            return $this->notFound();
        }
        StateDirectory::prepare($this->data);
        $throttle = new Throttle($services->throttleDirectory() . '/cron.json', null, $services->clock);
        if ($throttle->begin() > 0) {
            return $this->notFound();
        }
        if (!$secret->matches($candidate)) {
            $throttle->failed($request->remoteAddress, 'wrong trigger secret');

            return $this->notFound();
        }
        $throttle->reset();

        $answer = TriggerService::create($this->data, $this->clock, $this->budgets)->call();

        return new Response(200, ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store'], $answer['status'] . "\n" . implode("\n", $answer['lines']) . ($answer['lines'] === [] ? '' : "\n"));
    }

    private function router(Services $services, AuthController $auth): Router
    {
        $dashboard = new DashboardController($services);
        $profiles = new ProfileController($services);
        $backups = new BackupController($services);
        $catalog = new CatalogController($services);
        $download = new DownloadController($services);
        $restore = new RestoreController($services);
        $system = new SystemController($services);
        $retention = new RetentionController($services);
        $keys = new KeysController($services);
        $schedule = new ScheduleController($services);
        $router = new Router();
        $router->add('GET', '/login', $auth->loginPage(...), public: true);
        $router->add('POST', '/login', $auth->login(...), public: true);
        $router->add('GET', '/login/code', $auth->codePage(...), public: true);
        $router->add('POST', '/login/code', $auth->code(...), public: true);
        $router->add('POST', '/logout', $auth->logout(...));
        $router->add('GET', '/', $dashboard->index(...));
        $router->add('GET', '/profiles', $profiles->index(...));
        $router->add('POST', '/profiles', $profiles->create(...));
        $router->add('GET', '/new-profile', $profiles->newForm(...));
        $router->add('GET', '/profiles/{id}', $profiles->show(...));
        $router->add('POST', '/profiles/{id}', $profiles->update(...));
        $router->add('GET', '/profiles/{id}/edit', $profiles->editForm(...));
        $router->add('POST', '/profiles/{id}/test', $profiles->test(...));
        $router->add('GET', '/profiles/{id}/delete', $profiles->confirmRemove(...));
        $router->add('POST', '/profiles/{id}/delete', $profiles->remove(...));
        $router->add('POST', '/profiles/{id}/backup', $backups->start(...));
        $router->add('GET', '/profiles/{id}/backups/{name}', $catalog->show(...));
        $router->add('POST', '/profiles/{id}/backups/{name}/verify', $catalog->startCheck(...));
        $router->add('GET', '/profiles/{id}/backups/{name}/delete', $catalog->confirmDelete(...));
        $router->add('POST', '/profiles/{id}/backups/{name}/delete', $catalog->delete(...));
        $router->add('POST', '/profiles/{id}/backups/{name}/pin', $catalog->pin(...));
        $router->add('POST', '/profiles/{id}/retention', $retention->apply(...));
        $router->add('GET', '/profiles/{id}/keys', $keys->index(...));
        $router->add('POST', '/profiles/{id}/keys/new', $keys->generate(...));
        $router->add('POST', '/profiles/{id}/keys/use', $keys->use(...));
        $router->add('GET', '/profiles/{id}/backups/{name}/download', $download->dialog(...));
        $router->add('POST', '/profiles/{id}/backups/{name}/download', $download->issue(...));
        $router->add('GET', '/download/{hex}/{name}', $download->file(...));
        $router->add('GET', '/profiles/{id}/backups/{name}/restore-help', $restore->help(...));
        $router->add('GET', '/restore-php', $restore->tool(...));
        $router->add('POST', '/restore-key', $restore->key(...));
        $router->add('GET', '/schedule', $schedule->index(...));
        $router->add('GET', '/schedule/edit', $schedule->editForm(...));
        $router->add('POST', '/schedule/edit', $schedule->edit(...));
        $router->add('POST', '/schedule/action', $schedule->act(...));
        $security = new SecurityController($services);
        $router->add('GET', '/security', $security->index(...));
        $router->add('POST', '/security/start', $security->start(...));
        $router->add('POST', '/security/confirm', $security->confirm(...));
        $router->add('POST', '/security/cancel', $security->cancel(...));
        $router->add('POST', '/security/recovery', $security->recovery(...));
        $router->add('POST', '/security/disable', $security->disable(...));
        $about = new AboutController();
        $router->add('GET', '/about', $about->index(...));
        $router->add('GET', '/about/license', $about->license(...));
        $router->add('GET', '/system', $system->index(...));
        $router->add('POST', '/system/settings', $system->save(...));
        $router->add('POST', '/system/cron-secret', $system->cronSecret(...));
        $update = new UpdateController($services);
        $router->add('POST', '/system/update/upload', $update->upload(...));
        $router->add('POST', '/system/update/start', $update->start(...));
        $router->add('POST', '/system/update/tick', $update->tick(...));
        $router->add('POST', '/system/update/retry', $update->retry(...));
        $router->add('POST', '/system/update/discard', $update->discard(...));
        $router->add('POST', '/system/update/rollback', $update->rollback(...));
        $router->add('GET', '/checks/{run}', $catalog->showCheck(...));
        $router->add('GET', '/checks/{run}/status', $catalog->statusCheck(...));
        $router->add('POST', '/checks/{run}/tick', $catalog->tickCheck(...));
        $router->add('POST', '/checks/{run}/continue', $catalog->nextCheck(...));
        $router->add('POST', '/checks/{run}/discard', $catalog->discardCheck(...));
        $router->add('GET', '/runs/{run}', $backups->show(...));
        $router->add('GET', '/runs/{run}/status', $backups->status(...));
        $router->add('POST', '/runs/{run}/tick', $backups->tick(...));
        $router->add('POST', '/runs/{run}/continue', $backups->next(...));
        $router->add('POST', '/runs/{run}/abandon', $backups->abandon(...));
        $router->add('POST', '/runs/{run}/dismiss', $backups->dismiss(...));

        return $router;
    }

    private function run(Router $router, string $method, string $route, Context $context, ?Services $services): Response
    {
        $request = $context->request;
        $isScript = $request->header(Csrf::HEADER) !== null;

        $notAllowed = null;
        try {
            $match = $router->match($method, $route);
        } catch (MethodNotAllowed $e) {
            $match = null;
            $notAllowed = $e;
        }
        $public = $match !== null && $match['public'];
        // a session without a user is not a login: it is the half of one that waits for the code of the second factor (docs/PHASE11.md 3.3)
        if (!$public && $context->user() === null) {
            // nothing behind the login is revealed, not even whether the address exists (a 405 with its Allow header would say that it does)
            return $isScript ? Response::json(['status' => 'login', 'error' => 'The session has ended. Log in again.'], 401) : Response::redirect($context->urls->to('/login'));
        }
        if ($notAllowed !== null) {
            return new Response(405, ['Allow: ' . implode(', ', $notAllowed->allowed), 'Content-Type: text/plain; charset=utf-8'], $notAllowed->getMessage() . "\n");
        }
        if ($match === null) {
            return View::page($context, 'Not found', '<p>There is no such page.</p>', '', 404);
        }
        if (!$public && $services !== null) {
            $blocked = $this->secondFactorRequired($services, $context, $method, $route, $isScript);
            if ($blocked !== null) {
                return $blocked;
            }
        }
        if ($method === 'POST') {
            if (!Csrf::sameOrigin($request)) {
                return $this->refuse($context, 'The request came from another site.', $isScript);
            }
            if (!$public && !Csrf::valid($request, $context->csrf())) {
                return $this->refuse($context, 'The page has expired (wrong or missing token). Reload it and try again.', $isScript);
            }
        }

        $response = ($match['handler'])($context, $match['params']);

        if ($services !== null && $context->session !== null && $context->sessionToken !== null) {
            // saving moves the idle clock of the session on
            $services->sessions->save($context->sessionToken, $context->session);
        }

        return $response;
    }

    /**
     * `web.requireTwoFactor` (docs/PHASE11.md 3.7): a login without a second factor reaches the page where one is set up, the way out, and
     * nothing else. A file of the factor that cannot be read does not block here (the login refuses it already).
     */
    private function secondFactorRequired(Services $services, Context $context, string $method, string $route, bool $isScript): ?Response
    {
        if (!$services->config->requireTwoFactor() || in_array($method . ' ' . $route, ['GET /security', 'POST /security/start', 'POST /security/confirm', 'POST /security/cancel', 'POST /logout'], true)) {
            return null;
        }
        try {
            if ($services->twoFactor->isOn($context->user() ?? '')) {
                return null;
            }
        } catch (TwoFactorException) {
            return null;
        }
        $message = 'This installation requires a second factor for the login. Set it up here first.';
        if ($isScript) {
            return Response::json(['status' => 'refused', 'error' => $message], 403);
        }
        $context->flash('warning', $message);
        if ($context->sessionToken !== null && $context->session !== null) {
            $services->sessions->save($context->sessionToken, $context->session); // this answer leaves before the save of the end of run()
        }

        return Response::redirect($context->urls->to('/security'));
    }

    private function refuse(Context $context, string $message, bool $isScript): Response
    {
        if ($isScript) {
            return Response::json(['status' => 'refused', 'error' => $message], 403);
        }

        return $context->user() !== null
            ? View::page($context, 'Request refused', '<p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', '', 403)
            : View::bare($context, 'Request refused', '<p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', 403);
    }

    private function notFound(): Response
    {
        return View::error('Not found', 'There is nothing here.', 404);
    }

    private function loggedIn(Request $request): bool
    {
        try {
            $services = new Services($this->data, $this->clock, $this->budgets);

            $session = $services->sessions->load($request->cookie(AuthController::COOKIE));

            return is_array($session) && is_string($session['user'] ?? null);
        } catch (Throwable) {
            return false;
        }
    }

    private function secure(Response $response): Response
    {
        return new Response($response->status, [...$response->headers, 'X-Content-Type-Options: nosniff', 'X-Frame-Options: DENY', 'Referrer-Policy: same-origin',
            'Content-Security-Policy: ' . self::CSP, ...($response->header('Cache-Control') === null ? ['Cache-Control: no-store'] : [])], $response->body, $response->stream);
    }
}
