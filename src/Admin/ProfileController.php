<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\App\Detection;
use ThreadSafe\App\ProfileService;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Profile\TestReport;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * The pages for the site profiles (docs/PHASE4.md 3.5): list, create, edit, look at, test, remove. Everything that touches a
 * profile goes through {@see ProfileService}, which the command `profile` uses as well.
 */
final class ProfileController
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
    public function index(Context $context, array $params = []): Response
    {
        ['profiles' => $profiles, 'damaged' => $damaged] = $this->service()->listing();

        $rows = '';
        foreach ($profiles as $profile) {
            $rows .= '<tr><td><a href="' . Html::e($context->urls->to('/profiles/' . $profile->id)) . '">' . Html::e($profile->name) . '</a>'
                . ($profile->name !== $profile->id ? ' <span class="hint">' . Html::e($profile->id) . '</span>' : '') . '</td>'
                . '<td>' . Html::e($profile->type) . '</td><td><code>' . Html::e($profile->path) . '</code></td><td><code>' . Html::e($profile->storage) . '</code></td></tr>';
        }
        foreach ($damaged as $id) {
            $rows .= '<tr><td>' . Html::e($id) . '</td><td colspan="3"><span class="flash error">The file of this profile is damaged: ' . Html::e($this->damage($id)) . '</span></td></tr>';
        }

        $body = '<p><a class="button" href="' . Html::e($context->urls->to('/new-profile')) . '">New profile</a></p>'
            . ($rows === '' ? '<p class="hint">There are no site profiles yet. A profile says which site to back up and where its backups go.</p>'
                : '<div class="scroll"><table><thead><tr><th>Name</th><th>Type</th><th>Site</th><th>Backups go to</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');

        return View::page($context, 'Profiles', $body, '/profiles');
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Context $context, array $params): Response
    {
        $profile = $this->find($params['id'] ?? '');
        if ($profile === null) {
            return $this->missing($context);
        }

        $database = $profile->database === null
            ? 'read from the site\'s own configuration (or none)'
            : sprintf('%s@%s:%d/%s, prefix "%s", PHP interface %s, password hidden', $profile->database->user, $profile->database->host, $profile->database->port, $profile->database->name, $profile->database->prefix, $profile->database->driver);
        $rows = [
            'Type' => $profile->type,
            'Site' => $profile->path,
            'Backups go to' => $profile->storage,
            'Database' => $database,
            'Filters' => ($profile->useDefaultFilters ? 'the defaults of the type' : 'no defaults')
                . ($profile->excludePaths === [] ? '' : ', also leaving out ' . implode(', ', $profile->excludePaths)),
            'Structure only' => $profile->structureOnlyTables === [] ? '-' : implode(', ', $profile->structureOnlyTables),
            'Encryption' => $profile->encryption === null ? 'none' : 'for ' . count($profile->encryption->recipients) . ' public key' . (count($profile->encryption->recipients) === 1 ? '' : 's') . ' (' . implode(', ', array_map(static fn (string $key): string => substr($key, 0, 12) . '...' . substr($key, -6), $profile->encryption->recipients)) . ')',
            'Archives' => 'compression ' . $profile->compression . ($profile->partSize === null ? ', one archive' : ', parts of ' . round($profile->partSize / 1048576) . ' MB'),
            'Note' => $profile->note === '' ? '-' : $profile->note,
        ];
        $table = '<div class="scroll"><table class="facts"><tbody>';
        foreach ($rows as $label => $value) {
            $table .= '<tr><th scope="row">' . Html::e($label) . '</th><td>' . Html::e($value) . '</td></tr>';
        }
        $table .= '</tbody></table></div>';

        $id = $profile->id;
        $actions = '<div class="actions"><a class="button" href="' . Html::e($context->urls->to("/profiles/$id/edit")) . '">Edit</a> '
            . View::form($context, "/profiles/$id/test", '<button type="submit">Test the connection</button>', 'inline')
            . ' <a class="button secondary" href="' . Html::e($context->urls->to("/profiles/$id/keys")) . '">Keys</a>'
            . ' <a class="button danger" href="' . Html::e($context->urls->to("/profiles/$id/delete")) . '">Remove</a></div>';

        $backups = new BackupController($this->services);
        $catalog = new CatalogController($this->services);

        return View::page($context, $profile->name, $actions . $backups->runsBox($context, $id) . $catalog->checksBox($context, $id) . '<h2>Back up</h2>' . $backups->startForm($context, $id)
            . '<h2>Backups</h2>' . $catalog->table($context, $id) . (new RetentionController($this->services))->box($context, $profile) . '<h2>Settings</h2>' . $table, '/profiles');
    }

    /**
     * @param array<string, string> $params
     */
    public function newForm(Context $context, array $params = []): Response
    {
        $types = $this->service()->types();

        return $this->form($context, null, ProfileForm::defaults((string) array_key_first($types)));
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Context $context, array $params = []): Response
    {
        return $this->submit($context, null);
    }

    /**
     * @param array<string, string> $params
     */
    public function editForm(Context $context, array $params): Response
    {
        $profile = $this->find($params['id'] ?? '');

        return $profile === null ? $this->missing($context) : $this->form($context, $profile, ProfileForm::fromProfile($profile));
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Context $context, array $params): Response
    {
        $profile = $this->find($params['id'] ?? '');

        return $profile === null ? $this->missing($context) : $this->submit($context, $profile);
    }

    /**
     * @param array<string, string> $params
     */
    public function test(Context $context, array $params): Response
    {
        $profile = $this->find($params['id'] ?? '');
        if ($profile === null) {
            return $this->missing($context);
        }
        $report = $this->service()->test($profile);
        $this->services->audit->record('profile.tested', $context->user() ?? '-', $context->request->remoteAddress, ['profile' => $profile->id, 'passed' => $report->passed()]);

        return View::page($context, 'Test of ' . $profile->name, View::report($report) . '<p><a href="' . Html::e($context->urls->to('/profiles/' . $profile->id)) . '">Back to the profile</a></p>', '/profiles');
    }

    /**
     * @param array<string, string> $params
     */
    public function confirmRemove(Context $context, array $params): Response
    {
        $profile = $this->find($params['id'] ?? '');
        if ($profile === null) {
            return $this->missing($context);
        }

        try {
            $entries = (new CatalogController($this->services))->service()->backups($profile->id);
        } catch (\Throwable) {
            $entries = [];   // the storage cannot be read: the profile can still be removed, its backups stay
        }
        $bytes = 0;
        foreach ($entries as $entry) {
            $bytes += $entry->sidecar?->size() ?? 0;
        }
        $choice = $entries === []
            ? ''
            : '<div class="field check"><label><input type="checkbox" name="delete_backups" value="1"> Also delete its ' . count($entries) . ' backup' . (count($entries) === 1 ? '' : 's') . ' (' . Html::e(SpaceCheck::format($bytes)) . ')</label>'
                . '<p class="hint">This cannot be undone. Without the tick the backups stay where they are.</p></div>';

        $body = '<p>Remove the profile <strong>' . Html::e($profile->name) . '</strong>?</p>'
            . '<p>The site <code>' . Html::e($profile->path) . '</code> is not touched. Its backups in <code>' . Html::e($profile->storage) . '</code> stay where they are unless you tick the box.</p>'
            . View::form($context, '/profiles/' . $profile->id . '/delete', $choice . '<button type="submit" class="danger">Remove the profile</button> <a href="' . Html::e($context->urls->to('/profiles/' . $profile->id)) . '">Cancel</a>');

        return View::page($context, 'Remove ' . $profile->name, $body, '/profiles');
    }

    /**
     * @param array<string, string> $params
     */
    public function remove(Context $context, array $params): Response
    {
        $profile = $this->find($params['id'] ?? '');
        if ($profile === null) {
            return $this->missing($context);
        }
        $deleted = null;
        if ($context->request->post('delete_backups') === '1') {
            try {
                $deleted = (new CatalogController($this->services))->service()->deleteAll($profile->id);
            } catch (GuardFailed $e) {
                $context->flash('error', $e->getMessage());

                return Response::redirect($context->urls->to('/profiles/' . $profile->id . '/delete'));
            }
        }
        $this->service()->remove($profile->id);
        $this->services->audit->record('profile.removed', $context->user() ?? '-', $context->request->remoteAddress, ['profile' => $profile->id, 'backupsDeleted' => $deleted]);
        $context->flash('ok', 'The profile "' . $profile->name . '" was removed. ' . ($deleted === null ? 'Its backups stay where they are.' : ($deleted . ' backup' . ($deleted === 1 ? '' : 's') . ' deleted.')));

        return Response::redirect($context->urls->to('/profiles'));
    }

    // ------------------------------------------------------------------------------------------------- the form

    /**
     * The three buttons of the form: look at the site, test, save.
     */
    private function submit(Context $context, ?SiteProfile $current): Response
    {
        $request = $context->request;
        $values = ProfileForm::fromRequest($request);
        $action = (string) $request->post('action');
        $password = (string) $request->post('db_password');

        $detection = null;
        $report = null;
        $messages = [];

        if ($action === 'detect') {
            try {
                $detection = $this->service()->detect($values['type'], trim($values['path']));
                $values = $this->proposals($values, $detection, $current);
            } catch (ProfileException $e) {
                $messages[] = $e->getMessage();
            }

            return $this->form($context, $current, $values, $messages, $detection, null, $messages === [] ? 200 : 400);
        }

        ['data' => $data, 'errors' => $errors] = ProfileForm::toData($values, $password, $current);
        $profile = null;
        if ($errors === []) {
            try {
                if ($current === null) {
                    $this->service()->assertFreeId((string) $data['id']);
                }
                $profile = SiteProfile::fromArray($data);
            } catch (ProfileException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($profile === null) {
            return $this->form($context, $current, $values, $errors, null, null, 400);
        }

        if ($action === 'test') {
            return $this->form($context, $current, $values, [], null, $this->service()->test($profile));
        }

        $skip = $request->post('skip_test') === '1';
        $result = $this->service()->store($profile, test: !$skip);
        $report = $result->report;
        if ($result->rejectedByTest()) {
            return $this->form($context, $current, $values, ['The profile was not stored because it cannot be backed up as it is. Fix the problems below, or tick "Save without testing" to store it anyway.'], null, $report, 400);
        }
        if ($result->error !== null) {
            return $this->form($context, $current, $values, [$result->error], null, $report, 500);
        }

        $this->services->audit->record($current === null ? 'profile.created' : 'profile.changed', $context->user() ?? '-', $request->remoteAddress, ['profile' => $profile->id, 'type' => $profile->type, 'tested' => !$skip]);
        $context->flash('ok', 'The profile "' . $profile->name . '" was ' . ($current === null ? 'created' : 'saved') . '.');

        return Response::redirect($context->urls->to('/profiles/' . $profile->id));
    }

    /**
     * Suggestions after a look at the site: an id and a name from the directory name, if the person has not chosen any.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function proposals(array $values, Detection $detection, ?SiteProfile $current): array
    {
        if ($current === null && trim($values['id']) === '') {
            $slug = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower(basename($detection->root))), '-_');
            $values['id'] = substr($slug === '' ? 'site' : $slug, 0, 40);
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @param list<string>          $messages
     */
    private function form(Context $context, ?SiteProfile $current, array $values, array $messages = [], ?Detection $detection = null, ?TestReport $report = null, int $status = 200): Response
    {
        $types = $this->service()->types();
        $creating = $current === null;
        $currentId = $current === null ? '' : $current->id;
        $currentName = $current === null ? '' : $current->name;

        $inner = '';
        foreach ($messages as $message) {
            $inner .= '<p class="flash error" role="alert">' . Html::e($message) . '</p>';
        }
        if ($detection !== null) {
            $inner .= $this->detectionBox($detection);
        }
        if ($report !== null) {
            $inner .= '<h2>Test</h2>' . View::report($report);
        }

        $inner .= '<h2>The site</h2>'
            . ($creating ? View::field('id', 'Id', 'text', $values['id'], ['required' => 'required', 'maxlength' => '40', 'autocapitalize' => 'none', 'spellcheck' => 'false'], 'Lower-case letters, digits, hyphens and underscores; part of the names of the backup files. It cannot be changed later.')
                : '<p>Id: <code>' . Html::e($currentId) . '</code></p>')
            . View::field('name', 'Name', 'text', $values['name'], [], 'Shown in the lists; the id if left empty.')
            . View::select('type', 'Website type', $types, $values['type'])
            . View::field('path', 'Site directory', 'text', $values['path'], ['required' => 'required', 'spellcheck' => 'false'], 'The absolute path of the application on this server.')
            . '<p><button type="submit" name="action" value="detect" class="secondary" formnovalidate>Look at the site</button></p>'
            . '<h2>Backups</h2>'
            . View::field('storage', 'Storage directory', 'text', $values['storage'], ['required' => 'required', 'spellcheck' => 'false'], 'Where the backups of this site are kept: an absolute path, best outside the web root, e.g. /var/backups/threadsafe/shop.')
            . View::field('compression', 'Compression', 'text', $values['compression'], ['inputmode' => 'numeric', 'maxlength' => '1'], '0 (store) to 9; 6 is a good balance.')
            . View::field('part_size', 'Part size', 'text', $values['part_size'], ['spellcheck' => 'false'], 'Split archives into parts of this size, e.g. 500M or 2G. Empty = one archive.')
            . '<h2>Database</h2>'
            . View::select('db_mode', 'Access', ['site' => 'Read it from the site\'s own configuration (or the site has none)', 'manual' => 'Enter it here'], $values['db_mode'], 'PrestaShop keeps its database access in app/config/parameters.php; reading it from there means no second copy of the password. Types without such a file need the entries below.')
            . View::field('db_host', 'Host', 'text', $values['db_host'], ['spellcheck' => 'false'])
            . View::field('db_port', 'Port', 'text', $values['db_port'], ['inputmode' => 'numeric'])
            . View::field('db_name', 'Database', 'text', $values['db_name'], ['spellcheck' => 'false'])
            . View::field('db_user', 'User', 'text', $values['db_user'], ['autocomplete' => 'off', 'spellcheck' => 'false'])
            . View::field('db_password', 'Password', 'password', '', ['autocomplete' => 'new-password'], $current?->database !== null ? 'Leave empty to keep the stored password. It is never shown.' : 'Stored in the profile file (mode 0600).')
            . View::field('db_prefix', 'Table prefix', 'text', $values['db_prefix'], ['spellcheck' => 'false'])
            . View::select('db_driver', 'PHP interface', array_combine(DatabaseSettings::DRIVERS, DatabaseSettings::DRIVERS), $values['db_driver'])
            . '<h2>Filters</h2>'
            . View::checkbox('default_filters', 'Use the filters of the website type', $values['default_filters'] === '1', 'Caches, logs and the tables of statistics are left out; the type knows which.')
            . View::textarea('exclude', 'Leave out also', $values['exclude'], 4, 'Paths relative to the site directory, one per line.')
            . View::textarea('structure_only', 'Tables of which only the structure is kept', $values['structure_only'], 3, 'One per line, with or without the table prefix.')
            . '<h2>Schedule</h2>'
            . View::field('schedule', 'Back up by itself at', 'text', $values['schedule'] ?? '', ['spellcheck' => 'false', 'placeholder' => '30 3 * * *'], 'A cron expression: minute hour day-of-month month day-of-week, e.g. 30 3 * * * for 03:30 every day, or @daily. Read in the time zone ' . $this->services->config->scheduleZone()->getName() . ' (System page). Empty: no scheduled backups. Something must call the trigger, see the System page.')
            . '<h2>Retention</h2><p class="hint">What is kept in the storage. The newest usable backup and pinned backups are never deleted. Every rule is optional.</p>'
            . View::field('keep', 'Keep at most this many backups', 'text', $values['keep'] ?? '', ['inputmode' => 'numeric'])
            . View::field('max_age_days', 'Delete backups older than this many days', 'text', $values['max_age_days'] ?? '', ['inputmode' => 'numeric'])
            . View::field('max_size', 'Keep the backups under this size together', 'text', $values['max_size'] ?? '', ['spellcheck' => 'false'], 'For example 20G or 500M. The oldest ones are deleted first.')
            . '<h2>Encryption</h2>'
            . View::textarea('recipients', 'Public keys', $values['recipients'] ?? '', 3, 'One per line (age1...). Backups are encrypted for these keys; only the matching secret keys open them, this server cannot. Empty: not encrypted.' . ($creating ? ' Make a key pair on the profile page after saving, or with the command "threadsafe keys generate".' : ''))
            . ($creating ? '' : '<p><a href="' . Html::e($context->urls->to('/profiles/' . $currentId . '/keys')) . '">Make a key pair</a> (the secret key is shown once; the profile is not saved by following this link)</p>')
            . '<h2>Note</h2>'
            . View::field('note', 'Note', 'text', $values['note'])
            . '<div class="field check"><label><input type="checkbox" name="skip_test" value="1"> Save without testing</label><p class="hint">A profile that fails its test is not stored, unless you tick this.</p></div>'
            . '<p><button type="submit" name="action" value="save">' . ($creating ? 'Test and create' : 'Test and save') . '</button> '
            . '<button type="submit" name="action" value="test" class="secondary">Only test</button> '
            . '<a href="' . Html::e($context->urls->to($creating ? '/profiles' : '/profiles/' . $currentId)) . '">Cancel</a></p>';

        $route = $creating ? '/profiles' : '/profiles/' . $currentId;

        return View::page($context, $creating ? 'New profile' : 'Edit ' . $currentName, View::form($context, $route, $inner, 'wide'), '/profiles', $status);
    }

    private function detectionBox(Detection $detection): string
    {
        $database = $detection->database === null
            ? 'The type finds no database access in the site; enter it below if the site has a database.'
            : sprintf('The database access is read from the site: "%s" on %s:%d, prefix "%s" (password not shown).', $detection->database->name, $detection->database->host, $detection->database->port, $detection->database->prefix);
        $admin = $detection->site->detail('adminDirectory');

        return '<div class="box"><p><strong>' . Html::e($detection->site->describe()) . '</strong> found in <code>' . Html::e($detection->root) . '</code>'
            . ($admin !== null ? '; back office folder <code>' . Html::e($admin) . '</code>' : '') . '.</p>'
            . '<p>' . Html::e($database) . '</p>'
            . '<p>The type leaves out ' . $detection->excludedPaths . ' paths and keeps only the structure of ' . $detection->structureOnlyTables . ' tables by default.</p></div>';
    }

    // ------------------------------------------------------------------------------------------------- helpers

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

    private function damage(string $id): string
    {
        try {
            $this->service()->load($id);
        } catch (ProfileException $e) {
            return $e->getMessage();
        }

        return 'unknown reason';
    }

    private function missing(Context $context): Response
    {
        return View::page($context, 'Not found', '<p>There is no such profile. <a href="' . Html::e($context->urls->to('/profiles')) . '">Back to the profiles</a>.</p>', '/profiles', 404);
    }
}
