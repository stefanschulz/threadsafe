<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Auth\RecoveryCodes;
use ThreadSafe\Auth\TwoFactorException;
use ThreadSafe\Web\AccessKey;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;
use ThreadSafe\Web\Throttle;

/**
 * Login, logout, and the first setup of the administrator in the browser (docs/PHASE4.md 3.2).
 */
final class AuthController
{
    public const string COOKIE = 'threadsafe_session';

    /** How long the code may take after the password, and how many wrong codes end the wait (docs/PHASE11.md 3.3). */
    private const int PENDING_SECONDS = 300;

    private const int PENDING_TRIES = 5;

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function loginPage(Context $context, array $params = []): Response
    {
        if ($context->user() !== null) {
            return Response::redirect($context->urls->to('/'));
        }

        return $this->loginForm($context, '', 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function login(Context $context, array $params = []): Response
    {
        $request = $context->request;
        $user = trim((string) $request->post('user'));
        $throttle = $this->loginThrottle();

        $wait = $throttle->waitSeconds();
        if ($wait > 0) {
            return $this->loginForm($context, "Too many wrong attempts. Wait $wait seconds before trying again.", 429)->withHeader('Retry-After: ' . $wait);
        }
        if (self::plainHttp($context) && $request->post('plain') !== '1') {
            return $this->loginForm($context, 'Confirm that you accept the unencrypted connection, or use https.', 403);
        }
        if (!$this->services->admin->verify($user === '' ? AdminStore::DEFAULT_USER : $user, (string) $request->post('password'))) {
            $throttle->fail($request->remoteAddress, 'wrong login');
            $this->services->audit->record('login.failed', $user, $request->remoteAddress);

            return $this->loginForm($context, 'The user name or the password is wrong.', 403);
        }

        $throttle->reset();
        $name = $user === '' ? AdminStore::DEFAULT_USER : $user;
        try {
            $secondFactor = $this->services->twoFactor->isOn($name);
        } catch (TwoFactorException) {
            // a file of the second factor that cannot be read must not let the password alone in
            $this->services->audit->record('login.refused', $name, $request->remoteAddress, ['reason' => 'the file of the second factor cannot be read']);

            return $this->loginForm($context, 'The password is right, but the second factor cannot be checked, so the login is refused. The file two-factor.json in the data directory is damaged or cannot be read: repair it, or switch the second factor off with "php bin/threadsafe admin two-factor --disable".', 500);
        }

        $token = $this->newSession($context);
        $data = $this->services->sessions->load($token) ?? [];
        $data['plain'] = self::plainHttp($context);
        if ($secondFactor) {
            // not a login yet: a session without a user, which only the page of the code answers to
            $data['pending'] = ['user' => $name, 'since' => ($this->services->clock)(), 'tries' => 0];
            $this->services->sessions->save($token, $data);

            return Response::redirect($context->urls->to('/login/code'))->withHeader(self::cookie($token, $context));
        }
        $data['user'] = $name;
        $this->services->sessions->save($token, $data);
        $this->services->audit->record('login', $name, $request->remoteAddress);

        return Response::redirect($context->urls->to('/'))->withHeader(self::cookie($token, $context));
    }

    /**
     * @param array<string, string> $params
     */
    public function codePage(Context $context, array $params = []): Response
    {
        if ($context->user() !== null) {
            return Response::redirect($context->urls->to('/'));
        }
        $pending = $this->waiting($context);
        if ($pending instanceof Response) {
            return $pending;
        }

        return $this->codeForm($context, '', 200);
    }

    /**
     * The second step of the login (docs/PHASE11.md 3.3): the code of the app, or a recovery code. The password was right, so there is a
     * session without a user; it ends after five minutes and after five wrong codes, and the codes have a throttle of their own.
     *
     * @param array<string, string> $params
     */
    public function code(Context $context, array $params = []): Response
    {
        $request = $context->request;
        $pending = $this->waiting($context);
        if ($pending instanceof Response) {
            return $pending;
        }
        $name = $pending['user'];
        $now = ($this->services->clock)();

        $throttle = $this->codeThrottle();
        $wait = $throttle->waitSeconds();
        if ($wait > 0) {
            return $this->codeForm($context, "Too many wrong codes. Wait $wait seconds before trying again.", 429)->withHeader('Retry-After: ' . $wait);
        }

        $typed = trim((string) $request->post('code'));
        $recovery = RecoveryCodes::looksLike($typed);
        try {
            $right = $recovery
                ? $this->services->twoFactor->useRecoveryCode($name, $typed)
                : $this->services->twoFactor->acceptCode($name, $typed, $now);
            $left = $right && $recovery ? $this->services->twoFactor->recoveryLeft($name) : 0;
        } catch (TwoFactorException) {
            $this->endPending($context);
            $this->services->audit->record('login.refused', $name, $request->remoteAddress, ['reason' => 'the file of the second factor cannot be read']);

            return $this->loginForm($context, 'The second factor cannot be checked, so the login is refused. Repair the file two-factor.json in the data directory, or switch the second factor off with "php bin/threadsafe admin two-factor --disable".', 500)->withHeader(self::cookie('', $context, expire: true));
        }

        if (!$right) {
            $throttle->fail($request->remoteAddress, 'wrong code');
            $this->services->audit->record('login.code.failed', $name, $request->remoteAddress);
            $tries = $pending['tries'] + 1;
            if ($tries >= self::PENDING_TRIES) {
                $this->endPending($context);

                return $this->loginForm($context, 'Too many wrong codes. Enter the password again.', 403)->withHeader(self::cookie('', $context, expire: true));
            }
            $context->session['pending']['tries'] = $tries;

            return $this->codeForm($context, 'The code is wrong. If it is right on your phone, check that the clocks of the phone and of this server agree (the time here is ' . gmdate('Y-m-d H:i:s', $now) . ' UTC).', 403);
        }

        $throttle->reset();
        $plain = ($context->session['plain'] ?? false) === true;
        $this->endPending($context);
        $token = $this->newSession($context);
        $data = $this->services->sessions->load($token) ?? [];
        $data['user'] = $name;
        $data['plain'] = $plain;
        $data['mfa'] = $recovery ? 'recovery' : 'totp';
        $this->services->sessions->save($token, $data);
        if ($recovery) {
            $this->services->audit->record('login.recovery', $name, $request->remoteAddress, ['left' => $left]);
        }
        $this->services->audit->record('login', $name, $request->remoteAddress, ['mfa' => $recovery ? 'recovery' : 'totp']);

        return Response::redirect($context->urls->to('/'))->withHeader(self::cookie($token, $context));
    }

    /**
     * @param array<string, string> $params
     */
    public function logout(Context $context, array $params = []): Response
    {
        if ($context->sessionToken !== null) {
            $this->services->sessions->destroy($context->sessionToken);
        }
        $this->services->audit->record('logout', $context->user() ?? '-', $context->request->remoteAddress);
        $context->session = null; // the kernel must not save it again

        return Response::redirect($context->urls->to('/login'))->withHeader(self::cookie('', $context, expire: true));
    }

    /** What to say when the interface is on but nobody can log in yet. */
    public function noAdministrator(Context $context): Response
    {
        $key = Html::e(basename($this->services->setupKeyFile()));

        return View::bare($context, 'No administrator yet', '<p>The interface is switched on, but nobody can log in: no password has been set.</p>'
            . '<p>On the command line, set one:</p><pre><code>php bin/threadsafe admin password</code></pre>'
            . '<p>Or set it here in the browser: create a file named <code>' . $key . '</code> in the data directory <code>' . Html::e($this->services->data) . '</code> with this content (choose your own secret of at least ' . AccessKey::MIN_LENGTH . ' characters), then reload this page:</p>'
            . '<pre><code>&lt;?php return \'a secret you choose\';</code></pre>'
            . '<p class="hint">It is a PHP file on purpose: a plain text file would be served to anyone who asks for it.</p>', 403);
    }

    /**
     * @param array<string, string> $params
     */
    public function setupPage(Context $context, array $params = []): Response
    {
        return $this->setupForm($context, '', 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function setup(Context $context, array $params = []): Response
    {
        $request = $context->request;
        // the installer hands over with the key only (in the body of a request, never in an address): the form comes back with the key filled in
        if ($request->post('key') !== null && $request->post('password') === null) {
            return $this->setupForm($context, '', 200, (string) $request->post('key'));
        }
        if (self::plainHttp($context) && $request->post('plain') !== '1') {
            return $this->setupForm($context, 'Confirm that you accept the unencrypted connection, or use https.', 403);
        }
        $password = (string) $request->post('password');
        if ($password !== (string) $request->post('repeat')) {
            return $this->setupForm($context, 'The two passwords are not the same.', 400);
        }
        // the password is checked before the key is spent on a try, so a typo costs no attempt
        if (strlen($password) < AdminStore::MIN_PASSWORD_LENGTH) {
            return $this->setupForm($context, 'The password must have at least ' . AdminStore::MIN_PASSWORD_LENGTH . ' characters.', 400);
        }

        $waiting = $this->setupKey()->waitSeconds() > 0;
        $wrong = $this->setupKey()->check((string) $request->post('key'), $request->remoteAddress);
        if ($wrong !== null) {
            $this->services->audit->record('setup.failed', '-', $request->remoteAddress);

            return $this->setupForm($context, $wrong, $waiting ? 429 : 403);
        }

        try {
            $this->services->admin->setPassword(AdminStore::DEFAULT_USER, $password);
            $this->services->config->with('web.enabled', true)->save($this->services->configFile());
        } catch (ConfigException $e) {
            return $this->setupForm($context, $e->getMessage(), 500);
        }
        $removed = @unlink($this->services->setupKeyFile());
        $this->services->audit->record('setup', AdminStore::DEFAULT_USER, $request->remoteAddress);

        return View::bare($context, 'Setup done', '<p>The administrator is set and the interface is switched on.</p>'
            . ($removed ? '' : '<p class="flash warning">The file <code>' . Html::e(basename($this->services->setupKeyFile())) . '</code> could not be removed. Delete it by hand.</p>')
            . '<p><a class="button" href="' . Html::e($context->urls->to('/login')) . '">Log in</a></p>');
    }

    public static function plainHttp(Context $context): bool
    {
        return !$context->request->https && !$context->request->isLocal();
    }

    public static function cookie(string $token, Context $context, bool $expire = false): string
    {
        return 'Set-Cookie: ' . self::COOKIE . '=' . $token . '; Path=' . $context->urls->cookiePath() . '; HttpOnly; SameSite=Strict'
            . ($context->request->https ? '; Secure' : '') . ($expire ? '; Max-Age=0' : '');
    }

    private function loginThrottle(): Throttle
    {
        return new Throttle($this->services->throttleDirectory() . '/login.json', null, $this->services->clock);
    }

    /** The codes have a throttle of their own, so that wrong codes do not use up the tries of the password and the other way round. */
    private function codeThrottle(): Throttle
    {
        return new Throttle($this->services->throttleDirectory() . '/login-code.json', null, $this->services->clock);
    }

    /**
     * Ends the session the request came with (if any) and makes a new one: every login and every step of it gets an id that nobody knew
     * before.
     *
     * @return string the token of the new session
     */
    private function newSession(Context $context): string
    {
        if ($context->sessionToken !== null) {
            $this->services->sessions->destroy($context->sessionToken);
        }
        $context->session = null; // the kernel must not save the old one again

        return $this->services->sessions->create()[0];
    }

    private function endPending(Context $context): void
    {
        if ($context->sessionToken !== null) {
            $this->services->sessions->destroy($context->sessionToken);
        }
        $context->session = null;
    }

    /**
     * The half of a login that waits for its code.
     *
     * @return array{user: string, since: int, tries: int}|null null: the session is none, or a full login, or not waiting for a code
     */
    private function pending(Context $context): ?array
    {
        $pending = $context->session['pending'] ?? null;
        if ($context->user() !== null || !is_array($pending) || !is_string($pending['user'] ?? null) || !is_int($pending['since'] ?? null)) {
            return null;
        }

        return ['user' => $pending['user'], 'since' => $pending['since'], 'tries' => is_int($pending['tries'] ?? null) ? $pending['tries'] : 0];
    }

    /**
     * @return array{user: string, since: int, tries: int}|Response the waiting login, or the answer for a request that has none or whose time is up
     */
    private function waiting(Context $context): array|Response
    {
        $pending = $this->pending($context);
        if ($pending === null) {
            return Response::redirect($context->urls->to('/login'));
        }
        if (($this->services->clock)() - $pending['since'] > self::PENDING_SECONDS) {
            $this->endPending($context);

            return $this->loginForm($context, 'The time for the code is up. Enter the password again.', 403)->withHeader(self::cookie('', $context, expire: true));
        }

        return $pending;
    }

    private function codeForm(Context $context, string $error, int $status): Response
    {
        $request = $context->request;
        $recovery = $request->query('recovery') === '1' || $request->post('recovery') === '1';
        $inner = ($error !== '' ? '<p class="flash error" role="alert">' . Html::e($error) . '</p>' : '')
            . ($recovery
                ? '<input type="hidden" name="recovery" value="1">'
                    . View::field('code', 'Recovery code', 'text', '', ['autocomplete' => 'off', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'autofocus' => 'autofocus', 'required' => 'required'], 'One of the ten codes you were shown when you set up the second factor. Each works once.')
                : View::field('code', 'Code from your authenticator app', 'text', '', ['autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'autofocus' => 'autofocus', 'required' => 'required'], 'Six digits.'))
            . '<button type="submit">Log in</button>'
            . '<p class="hint">' . ($recovery
                ? '<a href="' . Html::e($context->urls->to('/login/code')) . '">Use the code from the app</a>'
                : '<a href="' . Html::e($context->urls->to('/login/code', ['recovery' => '1'])) . '">Lost the phone? Use a recovery code</a>')
            . ' &middot; <a href="' . Html::e($context->urls->to('/login')) . '">Back to the password</a></p>';

        return View::bare($context, 'Second step', View::form($context, '/login/code', $inner), $status);
    }

    private function setupKey(): AccessKey
    {
        $directory = $this->services->throttleDirectory();

        return new AccessKey($this->services->setupKeyFile(), $directory . '/setup.json', $directory . '/setup.log', $this->services->clock);
    }

    private function plainWarning(Context $context): string
    {
        return self::plainHttp($context)
            ? '<p class="flash error"><strong>This connection is not encrypted.</strong> The password crosses the network in clear text. Use https if the host offers it; otherwise confirm below that you accept this.</p>'
                . '<div class="field check"><label><input type="checkbox" name="plain" value="1"> I understand and accept this.</label></div>'
            : '';
    }

    private function loginForm(Context $context, string $error, int $status): Response
    {
        $request = $context->request;
        $inner = ($error !== '' ? '<p class="flash error" role="alert">' . Html::e($error) . '</p>' : '')
            . View::field('user', 'User', 'text', $request->post('user') ?? '', ['autocomplete' => 'username', 'placeholder' => AdminStore::DEFAULT_USER])
            . View::field('password', 'Password', 'password', '', ['autocomplete' => 'current-password', 'autofocus' => 'autofocus', 'required' => 'required'])
            . $this->plainWarning($context) . '<button type="submit">Log in</button>';

        return View::bare($context, 'Log in', View::form($context, '/login', $inner), $status);
    }

    private function setupForm(Context $context, string $error, int $status, string $key = ''): Response
    {
        $keyFile = Html::e(basename($this->services->setupKeyFile()));
        $inner = ($error !== '' ? '<p class="flash error" role="alert">' . Html::e($error) . '</p>' : '')
            . '<p>Choose the password of the administrator. The key is the secret in <code>' . $keyFile . '</code> in the data directory.</p>'
            . ($key !== ''
                ? '<input type="hidden" name="key" value="' . Html::e($key) . '"><p class="hint">The key from the installer is filled in.</p>'
                : View::field('key', 'Key from ' . basename($this->services->setupKeyFile()), 'password', '', ['autocomplete' => 'off', 'required' => 'required']))
            . View::field('password', 'New password (at least ' . AdminStore::MIN_PASSWORD_LENGTH . ' characters)', 'password', '', ['autocomplete' => 'new-password', 'required' => 'required'])
            . View::field('repeat', 'The password again', 'password', '', ['autocomplete' => 'new-password', 'required' => 'required'])
            . $this->plainWarning($context) . '<button type="submit">Set up</button>';

        return View::bare($context, 'Set up the administrator', View::form($context, '/setup', $inner), $status);
    }
}
