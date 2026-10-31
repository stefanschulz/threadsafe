<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use InvalidArgumentException;
use ThreadSafe\Auth\QrCode;
use ThreadSafe\Auth\RecoveryCodes;
use ThreadSafe\Auth\Totp;
use ThreadSafe\Auth\TwoFactorException;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;
use ThreadSafe\Web\Throttle;

/**
 * The page *Security* (docs/PHASE11.md 3.4): the second factor of the login in the browser. Switching on takes three steps (the password
 * again, scanning or typing the key, one code from the app), switching off and new recovery codes take the password and a code. The
 * recovery codes are shown once, in the answer to the request that made them; the server keeps only their hashes, and the session
 * keeps nothing of them. A new secret waits in the session, not in the file, until a code from the app has proved it.
 *
 * Wrong passwords on this page count in the throttle of the login, wrong codes in the throttle of the codes.
 */
final class SecurityController
{
    /** How long a secret that is not confirmed yet waits in the session. */
    private const int SETUP_SECONDS = 900;

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Context $context, array $params = []): Response
    {
        return $this->render($context);
    }

    /**
     * Step 1: the password again, then a secret is made and kept in the session.
     *
     * @param array<string, string> $params
     */
    public function start(Context $context, array $params = []): Response
    {
        $user = $context->user() ?? '';
        try {
            if ($this->services->twoFactor->isOn($user)) {
                return Response::redirect($context->urls->to('/security'));
            }
        } catch (TwoFactorException $e) {
            return $this->render($context, $e->getMessage(), 500);
        }
        $refused = $this->checkPassword($context, $user);
        if ($refused !== null) {
            return $this->render($context, $refused[0], $refused[1]);
        }

        $context->session['twofactor'] = ['setup' => ['secret' => Totp::newSecret(), 'since' => ($this->services->clock)()]];

        return Response::redirect($context->urls->to('/security'));
    }

    /**
     * Step 2: a code from the app proves that it holds the secret; the factor is on from here, and the recovery codes are shown.
     *
     * @param array<string, string> $params
     */
    public function confirm(Context $context, array $params = []): Response
    {
        $user = $context->user() ?? '';
        $setup = $this->setup($context);
        if ($setup === null) {
            return Response::redirect($context->urls->to('/security'));
        }
        $now = ($this->services->clock)();
        $typed = trim((string) $context->request->post('code'));
        if (Totp::verify($setup['secret'], $typed, $now) === null) {
            return $this->render($context, 'That code is not right. Check that the app shows the entry that you just added, and that its clock is right (the time here is ' . gmdate('Y-m-d H:i:s', $now) . ' UTC).', 403);
        }

        $codes = RecoveryCodes::generate();
        try {
            $this->services->twoFactor->enable($user, $setup['secret'], $codes);
            // the code that proved the secret is spent: it must not log in a second time
            $this->services->twoFactor->acceptCode($user, $typed, $now);
        } catch (TwoFactorException $e) {
            return $this->render($context, $e->getMessage(), 500);
        }
        unset($context->session['twofactor']);
        $ended = $context->sessionToken === null ? 0 : $this->services->sessions->destroyExcept($context->sessionToken);
        $this->services->audit->record('twofactor.enabled', $user, $context->request->remoteAddress, ['sessionsEnded' => $ended]);

        return $this->codes($context, $codes, 'The second factor is on', 'From now on the login asks for a code after the password.');
    }

    /**
     * @param array<string, string> $params
     */
    public function cancel(Context $context, array $params = []): Response
    {
        unset($context->session['twofactor']);

        return Response::redirect($context->urls->to('/security'));
    }

    /**
     * New recovery codes replace all of the old ones.
     *
     * @param array<string, string> $params
     */
    public function recovery(Context $context, array $params = []): Response
    {
        $user = $context->user() ?? '';
        $refused = $this->checkPasswordAndCode($context, $user, "new_");
        if ($refused !== null) {
            return $this->render($context, $refused[0], $refused[1]);
        }
        $codes = RecoveryCodes::generate();
        try {
            $this->services->twoFactor->replaceRecoveryCodes($user, $codes);
        } catch (TwoFactorException $e) {
            return $this->render($context, $e->getMessage(), 500);
        }
        $this->services->audit->record('twofactor.recovery.made', $user, $context->request->remoteAddress);

        return $this->codes($context, $codes, 'New recovery codes', 'The old ones no longer work.');
    }

    /**
     * @param array<string, string> $params
     */
    public function disable(Context $context, array $params = []): Response
    {
        $user = $context->user() ?? '';
        $refused = $this->checkPasswordAndCode($context, $user, "off_");
        if ($refused !== null) {
            return $this->render($context, $refused[0], $refused[1]);
        }
        try {
            $this->services->twoFactor->disable($user);
        } catch (TwoFactorException $e) {
            return $this->render($context, $e->getMessage(), 500);
        }
        $ended = $context->sessionToken === null ? 0 : $this->services->sessions->destroyExcept($context->sessionToken);
        $this->services->audit->record('twofactor.disabled', $user, $context->request->remoteAddress, ['sessionsEnded' => $ended]);
        $context->flash('ok', 'The second factor is off. The login asks for the password only.');

        return Response::redirect($context->urls->to('/security'));
    }

    // ------------------------------------------------------------------------------------------------- the page

    private function render(Context $context, string $error = '', int $status = 200): Response
    {
        $user = $context->user() ?? '';
        $heading = ($error !== '' ? '<p class="flash error" role="alert">' . Html::e($error) . '</p>' : '');
        try {
            $on = $this->services->twoFactor->isOn($user);
            $enrolled = $on ? $this->services->twoFactor->enrolled($user) : null;
            $left = $on ? $this->services->twoFactor->recoveryLeft($user) : 0;
        } catch (TwoFactorException $e) {
            return View::page($context, 'Security', '<p class="flash error" role="alert">' . Html::e($e->getMessage()) . ' The login is refused until it is repaired.</p>' . $this->wayBack(), '/security', 500);
        }

        if ($on) {
            $body = $heading . $this->statusBody($context, (string) $enrolled, $left);
        } elseif (($setup = $this->setup($context)) !== null) {
            $body = $heading . $this->setupBody($context, $setup['secret']);
        } else {
            $body = $heading . $this->offBody($context);
        }

        return View::page($context, 'Security', $body, '/security', $status);
    }

    private function offBody(Context $context): string
    {
        return '<p>The login asks for a password only. A <strong>second factor</strong> adds a code from an authenticator app on your phone (Aegis, 2FAS, FreeOTP, Google Authenticator, Microsoft Authenticator, a password manager): a stolen or guessed password is then not enough.</p>'
            . '<p>Three steps: enter your password, add the key to the app, enter one code. You get ten <strong>recovery codes</strong> for the day the phone is gone.</p>'
            . View::form($context, '/security/start', View::field('password', 'Your password', 'password', '', ['autocomplete' => 'current-password', 'required' => 'required'], 'Asked again here on purpose: a stranger at an open session cannot switch the factor on.')
                . '<button type="submit">Set up the second factor</button>')
            . '<p class="hint">Without a working clock the codes do not fit: the time of this server is ' . Html::e(gmdate('Y-m-d H:i:s', ($this->services->clock)())) . ' UTC.</p>';
    }

    private function setupBody(Context $context, string $secret): string
    {
        $key = implode(' ', str_split($secret, 4));
        $address = Totp::uri($secret, $context->user() ?? 'admin', self::host($context));

        try {
            $picture = QrCode::svg($address, 'QR code that holds the key for the authenticator app');
        } catch (InvalidArgumentException) {
            $picture = ''; // an address that is too long for the code: the key as text is always there
        }

        return '<h2>Add the key to your app</h2>'
            . ($picture !== '' ? '<p>In the app choose to add an account and scan this code:</p>' . $picture . '<p>Or choose to enter a key by hand (a "time-based" key, six digits, 30 seconds):</p>'
                : '<p>In the app choose to add an account and enter this key (a "time-based" key, six digits, 30 seconds):</p>')
            . '<p><code id="totp-key" class="secret">' . Html::e($key) . '</code> <button type="button" class="secondary js-only" data-copy="#totp-key">Copy</button></p>'
            . '<p class="hint">On the phone itself, <a href="' . Html::e($address) . '">this link</a> opens the app with the key filled in.</p>'
            . '<h2>Enter a code</h2>'
            . View::form($context, '/security/confirm', View::field('code', 'The code that the app shows now', 'text', '', ['autocomplete' => 'off', 'inputmode' => 'numeric', 'autofocus' => 'autofocus', 'required' => 'required'], 'Six digits. The time of this server is ' . gmdate('Y-m-d H:i:s', ($this->services->clock)()) . ' UTC.')
                . '<button type="submit">Switch it on</button>')
            . View::form($context, '/security/cancel', '<button type="submit" class="secondary">Cancel</button>');
    }

    private function statusBody(Context $context, string $enrolled, int $left): string
    {
        $lowWarning = $left <= 3 ? '<p class="flash warning">Only ' . $left . ' recovery ' . ($left === 1 ? 'code is' : 'codes are') . ' left. Make new ones below.</p>' : '';

        return '<p>The second factor is <strong>on</strong>' . ($enrolled !== '' ? ' since ' . Html::e($enrolled) : '') . '. The login asks for a code from the app after the password.</p>'
            . '<p>Recovery codes left: <strong>' . $left . '</strong>.</p>' . $lowWarning
            . '<h2>New recovery codes</h2><p>Replaces all ten. The new ones are shown once.</p>'
            . View::form($context, '/security/recovery', $this->passwordAndCode("new_") . '<button type="submit">Make new recovery codes</button>')
            . '<h2>Switch it off</h2><p>The login asks for the password only again. Every other login of this interface ends.</p>'
            . View::form($context, '/security/disable', $this->passwordAndCode("off_") . '<button type="submit" class="danger">Switch the second factor off</button>')
            . $this->wayBack();
    }

    private function passwordAndCode(string $prefix): string
    {
        return View::field($prefix . 'password', 'Your password', 'password', '', ['autocomplete' => 'current-password', 'required' => 'required'])
            . View::field($prefix . 'code', 'A code from the app, or a recovery code', 'text', '', ['autocomplete' => 'off', 'spellcheck' => 'false', 'required' => 'required'], 'A code that was used for the login a moment ago is not accepted twice: wait for the next one.');
    }

    private function wayBack(): string
    {
        return '<h2>If the phone is lost</h2><ol>'
            . '<li>Use a <strong>recovery code</strong> on the second step of the login (the link "Lost the phone?").</li>'
            . '<li>With a shell on the server: <code>php bin/threadsafe admin two-factor --disable</code>.</li>'
            . '<li>With FTP or the file manager: delete the file <code>two-factor.json</code> in the data directory.</li></ol>'
            . '<p class="hint">Whoever can do the second or the third already holds the data of this installation, so they open no new door.</p>';
    }

    /**
     * The recovery codes, once: with the means to keep them, as on the page of a new key pair.
     *
     * @param list<string> $codes
     */
    private function codes(Context $context, array $codes, string $title, string $lead): Response
    {
        $text = implode("\n", $codes) . "\n";
        $body = '<div class="box keypair">'
            . '<p class="print-only">ThreadSafe recovery codes - keep this sheet safe. Each code logs in once, in place of the code of the app.</p>'
            . '<p>' . Html::e($lead) . ' <strong>These codes are shown once.</strong> Store them somewhere else than on this server and apart from your phone: a password manager, a printout. Each works once, in place of the code of the app.</p>'
            . '<pre id="recovery-codes" class="secret"><code>' . Html::e($text) . '</code></pre>'
            . '<p class="actions"><button type="button" data-copy="#recovery-codes">Copy</button> '
            . '<button type="button" data-save="#recovery-codes" data-filename="threadsafe-recovery-codes.txt" class="secondary">Save as a file</button> '
            . '<button type="button" data-print class="secondary">Print</button></p>'
            . '</div>'
            . '<p><a class="button" href="' . Html::e($context->urls->to('/security')) . '">Done, I have stored them</a></p>';

        return View::page($context, $title, $body, '/security');
    }

    // ------------------------------------------------------------------------------------------------- the checks

    /**
     * The password again. A wrong one counts in the throttle of the login.
     *
     * @return array{string, int}|null the message and the status of a refusal, or null
     */
    private function checkPassword(Context $context, string $user, string $field = 'password'): ?array
    {
        $throttle = $this->throttle('login');
        $wait = $throttle->waitSeconds();
        if ($wait > 0) {
            return ["Too many wrong attempts. Wait $wait seconds before trying again.", 429];
        }
        if (!$this->services->admin->verify($user, (string) $context->request->post($field))) {
            $throttle->fail($context->request->remoteAddress, 'wrong password on the security page');
            $this->services->audit->record('security.password.failed', $user, $context->request->remoteAddress);

            return ['The password is wrong.', 403];
        }
        $throttle->reset();

        return null;
    }

    /**
     * The password and a code of the app (or a recovery code). A wrong code counts in the throttle of the codes.
     *
     * @return array{string, int}|null the message and the status of a refusal, or null
     */
    private function checkPasswordAndCode(Context $context, string $user, string $prefix): ?array
    {
        $codes = $this->throttle('login-code');
        $wait = $codes->waitSeconds();
        if ($wait > 0) {
            return ["Too many wrong codes. Wait $wait seconds before trying again.", 429];
        }
        $refused = $this->checkPassword($context, $user, $prefix . 'password');
        if ($refused !== null) {
            return $refused;
        }

        $typed = trim((string) $context->request->post($prefix . 'code'));
        try {
            $right = RecoveryCodes::looksLike($typed)
                ? $this->services->twoFactor->useRecoveryCode($user, $typed)
                : $this->services->twoFactor->acceptCode($user, $typed, ($this->services->clock)());
        } catch (TwoFactorException $e) {
            return [$e->getMessage(), 500];
        }
        if (!$right) {
            $codes->fail($context->request->remoteAddress, 'wrong code on the security page');
            $this->services->audit->record('security.code.failed', $user, $context->request->remoteAddress);

            return ['The code is wrong, or it was used a moment ago: wait for the next one from the app. The time here is ' . gmdate('Y-m-d H:i:s', ($this->services->clock)()) . ' UTC.', 403];
        }
        $codes->reset();

        return null;
    }

    /**
     * The secret that waits for its code, or null (none, or too old).
     *
     * @return array{secret: string, since: int}|null
     */
    private function setup(Context $context): ?array
    {
        $setup = $context->session['twofactor']['setup'] ?? null;
        if (!is_array($setup) || !is_string($setup['secret'] ?? null) || !is_int($setup['since'] ?? null)) {
            return null;
        }
        if (($this->services->clock)() - $setup['since'] > self::SETUP_SECONDS) {
            unset($context->session['twofactor']);

            return null;
        }

        return ['secret' => $setup['secret'], 'since' => $setup['since']];
    }

    private function throttle(string $name): Throttle
    {
        return new Throttle($this->services->throttleDirectory() . '/' . $name . '.json', null, $this->services->clock);
    }

    /** The host name of the request, as a label for the entry in the app (it only tells installations apart). */
    private static function host(Context $context): string
    {
        // at most 30 characters: the address (and so the QR code) holds the label twice, and a code has room for 213 bytes
        return substr(preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) $context->request->header('host')) ?? '', 0, 30);
    }
}
