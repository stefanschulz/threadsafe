<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use InvalidArgumentException;
use ThreadSafe\App\ProfileService;
use ThreadSafe\Core\Paths;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Profile\TestReport;
use ThreadSafe\Web\AgentPairing;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * The form of a site that an agent backs up (docs/PHASE13.md 3.3, M2): the pairing string that the agent showed, an id (the one in the string), a name, where the
 * fetched backups go, a schedule and retention like any other profile. What is backed up, the database, what is left out and the keys are set on the site, not here.
 * The secret in the string is not shown again after it is stored: the page shows the fingerprint, which the site shows too.
 */
final class RemoteProfileController
{
    private ?ProfileService $profiles;

    /**
     * @param ProfileService|null $profiles for tests; by default the service on the profiles of the data directory
     */
    public function __construct(private readonly Services $services, ?ProfileService $profiles = null)
    {
        $this->profiles = $profiles;
    }

    /**
     * @param array<string, string> $params
     */
    public function newForm(Context $context, array $params = []): Response
    {
        return $this->form($context, null, ProfileForm::defaults());
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Context $context, array $params = []): Response
    {
        return $this->submit($context, null);
    }

    public function edit(Context $context, SiteProfile $profile): Response
    {
        return $this->form($context, $profile, ProfileForm::fromProfile($profile));
    }

    public function update(Context $context, SiteProfile $profile): Response
    {
        return $this->submit($context, $profile);
    }

    // ------------------------------------------------------------------------------------------------- the form

    private function submit(Context $context, ?SiteProfile $current): Response
    {
        $request = $context->request;
        $values = ProfileForm::fromRequest($request);
        $action = (string) $request->post('action');
        $errors = [];

        $id = $current !== null ? $current->id : trim($values['id'] ?? '');
        $storage = trim($values['storage'] ?? '');
        if ($storage === '' && $id !== '') {
            $storage = $this->storageBase() . '/' . $id;
        }
        $remote = $current?->remote?->toArray();
        $pairing = trim((string) $request->post('pairing'));
        if ($pairing !== '') {
            try {
                $decoded = AgentPairing::decode($pairing);
                if ($id !== '' && $decoded['profile'] !== $id) {
                    $errors[] = 'The pairing string is for the profile "' . $decoded['profile'] . '", and this profile is "' . $id . '": the agent makes its backups under that id and the catalog finds a backup by it. Use the same id, or pair again on the site with the id "' . $id . '".';
                }
                $id = $id !== '' ? $id : $decoded['profile'];
                $remote = ['url' => $decoded['url'], 'secret' => $decoded['secret']];
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        } elseif ($remote === null) {
            $errors[] = 'Paste the pairing string that the agent on the site showed (it starts with ts1:).';
        }
        if ($remote !== null) {
            $remote['plainHttp'] = $request->post('plain_http') === '1';
        }

        $data = [
            'id' => $id,
            'name' => trim($values['name'] ?? '') !== '' ? trim($values['name']) : $id,
            'type' => $current !== null ? $current->type : SiteProfile::REMOTE_TYPE,
            'storage' => $storage,
            'note' => trim($values['note'] ?? ''),
            'remote' => $remote,
        ];
        $schedule = ScheduleFields::read($values, $current?->schedule);
        $errors = [...$errors, ...$schedule['errors']];
        $data['schedule'] = $schedule['settings']?->toArray();
        $data['retention'] = ProfileForm::retention($values, $errors);
        if ($data['schedule'] === null) {
            unset($data['schedule']);
        }
        if ($data['retention'] === null) {
            unset($data['retention']);
        }

        $profile = null;
        if ($errors === []) {
            try {
                if ($current === null) {
                    $this->service()->assertFreeId($id);
                }
                $profile = SiteProfile::fromArray($data);
            } catch (ProfileException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($profile === null) {
            return $this->form($context, $current, $values, $errors, null, 400);
        }
        if ($action === 'test') {
            return $this->form($context, $current, $values, [], $this->service()->test($profile));
        }

        $skip = $request->post('skip_test') === '1';
        $result = $this->service()->store($profile, test: !$skip);
        if ($result->rejectedByTest()) {
            return $this->form($context, $current, $values, ['The profile was not stored because the agent cannot be backed up as it is. Fix the problems below, or tick "Save without testing" to store it anyway.'], $result->report, 400);
        }
        if ($result->error !== null) {
            return $this->form($context, $current, $values, [$result->error], $result->report, 500);
        }
        $this->services->audit->record($current === null ? 'profile.created' : 'profile.changed', $context->user() ?? '-', $request->remoteAddress, ['profile' => $profile->id, 'type' => 'remote', 'tested' => !$skip]);
        $context->flash('ok', 'The profile "' . $profile->name . '" was ' . ($current === null ? 'created' : 'saved') . '.');

        return Response::redirect($context->urls->to('/profiles/' . $profile->id));
    }

    /**
     * @param array<string, string> $values
     * @param list<string>          $messages
     */
    private function form(Context $context, ?SiteProfile $current, array $values, array $messages = [], ?TestReport $report = null, int $status = 200): Response
    {
        $creating = $current === null;
        $inner = '';
        foreach ($messages as $message) {
            $inner .= '<p class="flash error" role="alert">' . Html::e($message) . '</p>';
        }
        if ($report !== null) {
            $inner .= '<h2>Test</h2>' . View::report($report);
        }
        $remote = $current?->remote;
        $plain = array_key_exists('schedule_every', $values) && $context->request->isPost() ? $context->request->post('plain_http') === '1' : ($remote !== null && $remote->plainHttp);
        $keeps = ProfileForm::automationSummary($values);

        $inner .= '<p class="hint required-note">Fields marked <span class="req" aria-hidden="true">*</span><span class="visually-hidden">with a star</span> are required.</p>'
            . '<section class="form-section"><h2>The site</h2>'
            . '<p class="hint">The site is on another machine, and a ThreadSafe agent on it (the tool <code>threadsafe-backup.php</code>, or its plugin) makes the backups. This machine asks the agent to make one, fetches it and keeps it. '
            . 'What is backed up, the database, what is left out and the keys are set on the site, on the page "The agent" of the tool.</p>'
            . ($creating ? View::field('id', 'Id', 'text', $values['id'], ['mark' => '1', 'required' => 'required', 'maxlength' => '40', 'autocapitalize' => 'none', 'spellcheck' => 'false'], 'The same id as on the site: the agent makes its backups under it, and the catalog finds them by it. It cannot be changed later. Empty: the id in the pairing string.')
                : '<p>Id: <code>' . Html::e($current->id) . '</code></p>')
            . View::field('name', 'Name', 'text', $values['name'], [], 'Shown in the lists; the id if left empty.')
            . View::field('note', 'Note', 'text', $values['note'])
            . '</section><section class="form-section"><h2>The agent</h2>'
            . ($remote !== null ? '<p>Paired with <code>' . Html::e($remote->url) . '</code>, fingerprint of the secret <code>' . Html::e(implode('-', str_split($remote->fingerprint(), 4))) . '</code> (the site shows the same). The secret is not shown again.</p>' : '')
            . View::textarea('pairing', $creating ? 'Pairing string' : 'New pairing string', '', 3, ($creating ? 'Copy it from the page "The agent" on the site, or from the output of <code>php threadsafe-backup.php agent --setup</code>. It starts with ts1: and holds the secret of the pairing: it is stored in the profile file (mode 0600) and never shown again.' : 'Only to pair again (the secret changed): paste the new string. Empty: the pairing stays.'))
            . View::checkbox('plain_http', 'Accept plain http', $plain, 'Only if the site has no https. The requests are signed and the backups are encrypted, but everything else travels in the clear.')
            . '</section><section class="form-section"><h2>Storage</h2>'
            . View::field('storage', 'Storage directory', 'text', $values['storage'], ['placeholder' => $this->storageBase() . '/' . ($values['id'] !== '' ? $values['id'] : ($creating ? 'id' : $current->id)), 'spellcheck' => 'false'], 'Where the backups that are fetched are kept on this machine: an absolute path, best outside the web root. Empty: the suggestion in grey.')
            . '</section><details class="form-section"' . ($messages !== [] ? ' open' : '') . '><summary><h2>Automation</h2><span class="summary-value">' . Html::e($keeps) . '</span></summary><div class="form-section-body">'
            . '<h3>Schedule</h3><p class="hint">Times are read in the time zone ' . Html::e($this->services->config->scheduleZone()->getName()) . ' (System page). Something must call the trigger, see the System page.</p>'
            . ScheduleFields::render($values)
            . '<h3>Retention</h3><p class="hint">What is kept in the storage on this machine. The newest usable backup and pinned backups are never deleted. How many backups the site itself keeps is set on the site.</p>'
            . View::field('keep', 'Keep at most this many backups', 'text', $values['keep'] ?? '', ['inputmode' => 'numeric'])
            . View::field('max_age_days', 'Delete backups older than this many days', 'text', $values['max_age_days'] ?? '', ['inputmode' => 'numeric'])
            . View::field('max_size', 'Keep the backups under this size together', 'text', $values['max_size'] ?? '', ['spellcheck' => 'false'], 'For example 20G or 500M. The oldest ones are deleted first.')
            . '</div></details>'
            . '<div class="form-bar"><button type="submit" name="action" value="save">' . ($creating ? 'Test and create' : 'Test and save') . '</button> '
            . '<button type="submit" name="action" value="test" class="secondary">Only test</button> '
            . '<a href="' . Html::e($context->urls->to($creating ? '/profiles' : '/profiles/' . $current->id)) . '">Cancel</a>'
            . '<label class="inline-check" title="A profile whose agent does not answer is not stored, unless you tick this."><input type="checkbox" name="skip_test" value="1"> Save without testing</label></div>';

        return View::page($context, $creating ? 'New site with an agent' : 'Edit ' . $current->name, View::form($context, $creating ? '/remote-profiles' : '/profiles/' . $current->id, $inner, 'wide'), '/profiles', $status);
    }

    private function storageBase(): string
    {
        return ProfileForm::storageBase(Paths::home(), $this->services->data);
    }

    private function service(): ProfileService
    {
        return $this->profiles ??= new ProfileService(new ProfileStore($this->services->data . '/profiles'), PluginRegistry::load(), $this->services->data);
    }
}
