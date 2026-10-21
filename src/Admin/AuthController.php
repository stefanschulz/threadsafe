<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

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
        // a new session for every login, so that a session id known beforehand is worth nothing
        if ($context->sessionToken !== null) {
            $this->services->sessions->destroy($context->sessionToken);
            $context->session = null; // the kernel must not save the old one again
        }
        [$token, $data] = $this->services->sessions->create();
        $name = $user === '' ? AdminStore::DEFAULT_USER : $user;
        $data['user'] = $name;
        $data['plain'] = self::plainHttp($context);
        $this->services->sessions->save($token, $data);
        $this->services->audit->record('login', $name, $request->remoteAddress);

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

    private function setupForm(Context $context, string $error, int $status): Response
    {
        $keyFile = Html::e(basename($this->services->setupKeyFile()));
        $inner = ($error !== '' ? '<p class="flash error" role="alert">' . Html::e($error) . '</p>' : '')
            . '<p>Choose the password of the administrator. The key is the secret in <code>' . $keyFile . '</code> in the data directory.</p>'
            . View::field('key', 'Key from ' . basename($this->services->setupKeyFile()), 'password', '', ['autocomplete' => 'off', 'required' => 'required'])
            . View::field('password', 'New password (at least ' . AdminStore::MIN_PASSWORD_LENGTH . ' characters)', 'password', '', ['autocomplete' => 'new-password', 'required' => 'required'])
            . View::field('repeat', 'The password again', 'password', '', ['autocomplete' => 'new-password', 'required' => 'required'])
            . $this->plainWarning($context) . '<button type="submit">Set up</button>';

        return View::bare($context, 'Set up the administrator', View::form($context, '/setup', $inner), $status);
    }
}
