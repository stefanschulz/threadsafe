<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use DateTimeImmutable;
use DateTimeZone;
use ThreadSafe\App\ProfileService;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\EncryptionSettings;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * The keys of encrypted backups in the browser (docs/PHASE5.md 3.5, decision 9): a key pair is made here, **shown once** with the means
 * to keep it (copy, save as a file, print) and the server keeps nothing of the secret half: not in the profile, not in the session, not in
 * the audit log. Only after the person has ticked that the secret key is stored elsewhere is the public key put into the profile.
 *
 * What the page shows is the key file of `age-keygen`, so the real `age` tool opens a backup with it too.
 */
final class KeysController
{
    private ?ProfileService $profiles;

    /**
     * @param ProfileService|null $profiles for tests; by default the service on the data directory
     */
    public function __construct(private readonly Services $services, ?ProfileService $profiles = null)
    {
        $this->profiles = $profiles;
    }

    /**
     * The keys of a profile and the way to make a pair.
     *
     * @param array<string, string> $params
     */
    public function index(Context $context, array $params): Response
    {
        $profile = $this->find($params['id'] ?? '');
        if ($profile === null) {
            return $this->missing($context);
        }
        $id = $profile->id;
        $keys = '';
        foreach ($profile->encryption->recipients ?? [] as $key) {
            $keys .= '<li><code>' . Html::e($key) . '</code></li>';
        }
        $body = '<p>Backups can be encrypted for one or more <strong>public keys</strong>. Whoever holds the matching <strong>secret key</strong> can open them - and nobody else, not this server, not the author of this program. '
            . 'The format is <a href="https://age-encryption.org/v1">age</a>, so the real <code>age</code> tool opens the files as well.</p>'
            . '<h2>Public keys of this profile</h2>'
            . ($keys === '' ? '<p>None: the backups of this profile are not encrypted.</p>' : '<ul class="plain">' . $keys . '</ul>')
            . '<h2>Make a key pair</h2>'
            . '<p>The secret key is shown <strong>once</strong>, on the next page. Be ready to store it somewhere else than this server: a password manager, a printout, another machine. '
            . 'If it is lost, the backups made for its public key are lost for good.</p>'
            . View::form($context, "/profiles/$id/keys/new", '<button type="submit">Make a key pair</button> <a href="' . Html::e($context->urls->to("/profiles/$id")) . '">Back to the profile</a>')
            . '<p class="hint">You may also make the pair on your own computer (<code>age-keygen</code>, or <code>threadsafe keys generate</code>) and enter only the public key in the profile; then the secret key never touches this server.</p>';

        return View::page($context, 'Keys of ' . $profile->name, $body, '/profiles');
    }

    /**
     * Makes the pair and shows it. The page is never cached ({@see Kernel}), and nothing of the secret half is stored.
     *
     * @param array<string, string> $params
     */
    public function generate(Context $context, array $params): Response
    {
        $profile = $this->find($params['id'] ?? '');
        if ($profile === null) {
            return $this->missing($context);
        }
        $id = $profile->id;
        $pair = AgeKeys::generate();
        $file = AgeKeys::keyFile($pair['secret'], $pair['public'], new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->services->audit->record('keys.generated', $context->user() ?? '-', $context->request->remoteAddress, ['profile' => $id, 'public' => $pair['public']]);

        $body = '<div class="box keypair">'
            . '<p class="print-only">ThreadSafe key for the backups of "' . Html::e($profile->name) . '" - keep this sheet safe. It opens the encrypted backups; with the real age tool: <code>age --decrypt -i KEYFILE -o backup.zip backup.zip.age</code></p>'
            . '<p><strong>This is the only time the secret key is shown.</strong> ThreadSafe does not keep it. Store it now, somewhere else than this server.</p>'
            . '<h2>Secret key</h2><pre id="secret-key" class="secret"><code>' . Html::e($file) . '</code></pre>'
            . '<p class="actions"><button type="button" data-copy="#secret-key">Copy</button> '
            . '<button type="button" data-save="#secret-key" data-filename="' . Html::e('threadsafe-' . $id . '-key.txt') . '" class="secondary">Save as a file</button> '
            . '<button type="button" data-print class="secondary">Print</button></p>'
            . '<h2>Public key</h2><p><code id="public-key">' . Html::e($pair['public']) . '</code> <button type="button" data-copy="#public-key" class="secondary">Copy</button></p>'
            . '<p class="hint">The public key is not secret. It is in the file above too (the line <code># public key:</code>).</p>'
            . '</div>'
            . View::form(
                $context,
                "/profiles/$id/keys/use",
                '<input type="hidden" name="public" value="' . Html::e($pair['public']) . '">'
                . '<div class="field check"><label><input type="checkbox" name="stored" value="1" required> I have stored the secret key somewhere else than on this server.</label>'
                . '<p class="hint">Without the secret key the backups made from now on cannot be opened. This page cannot be shown again.</p></div>'
                . '<p><button type="submit">Use this public key for the backups of ' . Html::e($profile->name) . '</button> '
                . '<a href="' . Html::e($context->urls->to("/profiles/$id/keys")) . '">Do not use it</a></p>',
                'wide',
            );

        return View::page($context, 'New key pair for ' . $profile->name, $body, '/profiles');
    }

    /**
     * Puts the public key into the profile, once the person said the secret key is safe.
     *
     * @param array<string, string> $params
     */
    public function use(Context $context, array $params): Response
    {
        $profile = $this->find($params['id'] ?? '');
        if ($profile === null) {
            return $this->missing($context);
        }
        $id = $profile->id;
        $public = trim((string) $context->request->post('public'));
        if ($context->request->post('stored') !== '1' || !EncryptionSettings::isRecipient($public)) {
            $context->flash('error', 'The public key was not added to the profile: the secret key must be stored first (tick the box). The key pair is gone; make a new one.');

            return Response::redirect($context->urls->to("/profiles/$id/keys"));
        }

        try {
            $data = $profile->toArray();
            $data['encryption'] = ['recipients' => [...($profile->encryption->recipients ?? []), $public]];
            $result = $this->service()->store(SiteProfile::fromArray($data), test: false);
        } catch (ProfileException $e) {
            $context->flash('error', $e->getMessage());

            return Response::redirect($context->urls->to("/profiles/$id/keys"));
        }
        if ($result->error !== null) {
            $context->flash('error', $result->error);

            return Response::redirect($context->urls->to("/profiles/$id/keys"));
        }
        $this->services->audit->record('profile.encryption', $context->user() ?? '-', $context->request->remoteAddress, ['profile' => $id, 'public' => $public]);
        $context->flash('ok', 'The public key is in the profile. New backups of "' . $profile->name . '" are encrypted for it.');

        return Response::redirect($context->urls->to("/profiles/$id"));
    }

    private function service(): ProfileService
    {
        return $this->profiles ??= new ProfileService(new ProfileStore($this->services->data . '/profiles'), PluginRegistry::load(), $this->services->data);
    }

    private function find(string $id): ?SiteProfile
    {
        try {
            return $this->service()->load($id);
        } catch (ProfileException) {
            return null;
        }
    }

    private function missing(Context $context): Response
    {
        return View::page($context, 'Not found', '<p>There is no such profile. <a href="' . Html::e($context->urls->to('/profiles')) . '">Back to the profiles</a>.</p>', '/profiles', 404);
    }
}
