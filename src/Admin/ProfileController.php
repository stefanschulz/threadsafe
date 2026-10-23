<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\App\Detection;
use ThreadSafe\App\BackupService;
use ThreadSafe\App\Overview;
use ThreadSafe\App\ProfileService;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Engine\RunStore;
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
    /** The sections of the page of a profile: id => name. The first is the one that opens without `?tab=`. */
    private const array TABS = ['backups' => 'Backups', 'retention' => 'Retention', 'settings' => 'Settings'];

    /** The longest name that the list shows whole. */
    private const int NAME_WIDTH = 80;

    private ?ProfileService $profiles;

    /**
     * @param ProfileService|null $profiles for tests; by default the service on the profiles of the data directory
     */
    public function __construct(private readonly Services $services, ?ProfileService $profiles = null)
    {
        $this->profiles = $profiles;
    }

    /**
     * The profiles for looking after them, the technical side: a card for each, one under the other, with the name, the way to the page of
     * the profile and to the form, the type, the two paths and the options it has (schedule, retention, encryption). The state of the sites
     * and of their backups is on the dashboard.
     *
     * @param array<string, string> $params
     */
    public function index(Context $context, array $params = []): Response
    {
        ['profiles' => $profiles, 'damaged' => $damaged] = $this->service()->listing();
        $profiles = SiteProfile::sortedByName($profiles);

        $byType = TypeFilter::count($profiles);
        $selected = TypeFilter::selected($context, $byType);
        $plugins = PluginRegistry::load();
        $cards = '';
        foreach ($profiles as $profile) {
            if ($selected === null || $profile->type === $selected) {
                $cards .= $this->card($context, $profile, TypeFilter::title($plugins, $profile->type));
            }
        }
        foreach ($damaged as $id) {
            $cards .= '<article class="card profile problem"><header><div class="card-title"><h2>' . Html::e($id) . '</h2></div></header>'
                . '<p class="flash error">The file of this profile is damaged: ' . Html::e($this->damage($id)) . '</p></article>';
        }

        $body = '';
        if ($profiles === [] && $damaged === []) {
            $body .= '<p class="hint">There are no site profiles yet. A profile says which site to back up and where its backups go.</p>'
                . '<div class="cards"><a class="card new" href="' . Html::e($context->urls->to('/new-profile')) . '">' . Icons::svg('plus') . '<span>New profile</span></a></div>';
        } else {
            $body .= ($profiles === [] ? '' : TypeFilter::render($context, '/profiles', $byType, count($profiles), $selected))
                . '<div class="profile-list">' . $cards . '</div>';
        }

        return View::page($context, 'Profiles', $body, '/profiles', 200, '<a class="button" href="' . Html::e($context->urls->to('/new-profile')) . '">New profile</a>');
    }

    /** A path as HTML that may break after a slash, so that a long one wraps between its parts and not in the middle of a name. */
    private static function breakable(string $path): string
    {
        return str_replace('/', '/<wbr>', Html::e($path));
    }

    private function card(Context $context, SiteProfile $profile, string $typeTitle): string
    {
        $id = $profile->id;
        // a name wraps where the card is narrow; only an extreme one is cut (the whole name is the tooltip)
        $name = mb_strimwidth($profile->name, 0, self::NAME_WIDTH, '…');
        $icon = static fn (string $route, string $icon, string $title): string => '<a class="icon-button" href="' . Html::e($route) . '" title="' . $title . '" aria-label="' . $title . ' ' . Html::e($profile->name) . '">' . Icons::svg($icon) . '</a>';
        // a paths with its label, an option with its icon (a tooltip and a hidden text name it); label or icon and value stand in one line
        $option = static fn (string $icon, string $label, string $value): string => '<div class="fact option"><dt title="' . Html::e($label) . '">' . Icons::svg($icon) . '<span class="visually-hidden">' . Html::e($label) . '</span></dt><dd>' . $value . '</dd></div>';

        $facts = self::fact('Site', '<code class="path">' . self::breakable($profile->path) . '</code>')
            . self::fact('Backups', '<code class="path">' . self::breakable($profile->storage) . '</code>');
        if ($profile->schedule !== null) {
            $facts .= $option('clock', 'Schedule', '<code>' . Html::e($profile->schedule->cron) . '</code>');
        }
        if ($profile->retention !== null) {
            $keep = array_filter([
                $profile->retention->keep === null ? null : 'the last ' . $profile->retention->keep,
                $profile->retention->maxAgeDays === null ? null : 'up to ' . $profile->retention->maxAgeDays . ' days',
                $profile->retention->maxBytes === null ? null : 'up to ' . SpaceCheck::format($profile->retention->maxBytes),
            ]);
            $facts .= $option('archive', 'Retention', Html::e('keeps ' . implode(', ', $keep)));
        }
        if ($profile->encryption !== null) {
            $facts .= $option('lock', 'Encryption', 'Encrypted');
        }

        return '<article class="card profile"><header><div class="card-title"><h2' . ($name !== $profile->name ? ' title="' . Html::e($profile->name) . '"' : '') . '>' . Html::e($name) . '</h2>'
            . $icon($context->urls->to("/profiles/$id"), 'view', 'View profile') . $icon($context->urls->to("/profiles/$id/edit"), 'edit', 'Edit profile') . '</div>'
            . '<span class="badge">' . Html::e($typeTitle) . '</span></header><dl class="facts">' . $facts . '</dl></article>';
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
            'Database' => $database,
            'Filters' => ($profile->useDefaultFilters ? 'the defaults of the type' : 'no defaults')
                . ($profile->excludePaths === [] ? '' : ', also leaving out ' . implode(', ', $profile->excludePaths)),
            'Structure only' => $profile->structureOnlyTables === [] ? '-' : implode(', ', $profile->structureOnlyTables),
            'Encryption' => $profile->encryption === null ? 'none' : 'for ' . count($profile->encryption->recipients) . ' public key' . (count($profile->encryption->recipients) === 1 ? '' : 's') . ' (' . implode(', ', array_map(static fn (string $key): string => substr($key, 0, 12) . '...' . substr($key, -6), $profile->encryption->recipients)) . ')',
            'Archives' => 'compression ' . $profile->compression . ($profile->partSize === null ? ', one archive' : ', parts of ' . round($profile->partSize / 1048576) . ' MB'),
            'Note' => $profile->note === '' ? '-' : $profile->note,
        ];
        $id = $profile->id;
        $card = $this->overview()->forProfile($profile);
        $level = (string) $card['level'];

        // the head, always there: the type and the state, the paths and what needs a look
        $warnings = '';
        if ($card['warnings'] !== []) {
            $warnings = '<ul class="warnings">';
            foreach ($card['warnings'] as $warning) {
                $warnings .= '<li class="' . Html::e((string) $warning['level']) . '"><strong>' . ($warning['level'] === Overview::PROBLEM ? 'Problem' : 'Warning') . '</strong> ' . Html::e((string) $warning['text']) . '</li>';
            }
            $warnings .= '</ul>';
        }
        $summary = '<section class="card profile-summary ' . Html::e($level) . '"><div class="badges"><span class="badge">' . Html::e(TypeFilter::title(PluginRegistry::load(), $profile->type)) . '</span>'
            . '<span class="badge ' . Html::e($level) . '">' . Html::e(Overview::label($level)) . '</span></div>'
            . '<dl class="facts">' . self::fact('Site', '<code class="path">' . self::breakable($profile->path) . '</code>') . self::fact('Backups', '<code class="path">' . self::breakable($profile->storage) . '</code>') . '</dl>'
            . $warnings . '</section>';

        // the one main action: back up now, or follow the run that is going
        /** @var \ThreadSafe\Engine\Run|null $run */
        $run = $card['run'] ?? null;
        $state = $card['runState'] ?? null;
        $backUp = $run !== null && ($state === 'active' || $state === 'interrupted')
            ? '<a class="button" href="' . Html::e($context->urls->to('/runs/' . $run->id)) . '">' . ($state === 'active' ? 'Follow the backup' : 'Continue the backup') . '</a>'
            : View::form($context, "/profiles/$id/backup", '<button type="submit">Back up now</button>', 'inline');
        $pencil = '<a class="icon-button" href="' . Html::e($context->urls->to("/profiles/$id/edit")) . '" title="Edit profile" aria-label="Edit profile ' . Html::e($profile->name) . '">' . Icons::svg('edit') . '</a>';

        $tab = Tabs::current($context, self::TABS);
        $backups = new BackupController($this->services);
        $catalog = new CatalogController($this->services);
        if ($tab === 'retention') {
            $content = (new RetentionController($this->services))->box($context, $profile);
        } elseif ($tab === 'settings') {
            $facts = '';
            foreach ($rows as $label => $value) {
                $facts .= self::fact($label, Html::e($value));
            }
            $content = '<h2>Settings</h2><dl class="facts roomy">' . $facts . '</dl>'
                . '<div class="actions"><a class="button secondary" href="' . Html::e($context->urls->to("/profiles/$id/edit")) . '">Edit</a> '
                . View::form($context, "/profiles/$id/test", '<button type="submit" class="secondary">Test the connection</button>', 'inline')
                . ' <a class="button secondary" href="' . Html::e($context->urls->to("/profiles/$id/keys")) . '">Keys</a></div>'
                . '<h2>Remove</h2><p class="hint">Takes the profile out of ThreadSafe; its backups stay on disk (the next page asks what to do with them).</p>'
                . '<p><a class="button danger" href="' . Html::e($context->urls->to("/profiles/$id/delete")) . '">Remove the profile</a></p>';
        } else {
            $content = $backups->runsBox($context, $id) . $catalog->checksBox($context, $id)
                . '<details class="start-options"><summary>Back up with options</summary>' . $backups->startForm($context, $id) . '</details>'
                . '<h2>Backups</h2>' . $catalog->table($context, $id);
        }

        return View::page($context, $profile->name, $summary . Tabs::render($context, "/profiles/$id", self::TABS, $tab, 'Sections of the profile') . $content, '/profiles', 200, $backUp, $pencil);
    }

    /** A label and its value in one line (the facts of a card and of the head of a profile). */
    private static function fact(string $label, string $value): string
    {
        return '<div class="fact wide"><dt>' . Html::e($label) . '</dt><dd>' . $value . '</dd></div>';
    }

    private function overview(): Overview
    {
        $data = $this->services->data;
        $store = new ProfileStore($data . '/profiles');
        $runs = new RunStore($data . '/runs');

        return new Overview($store, $runs, new BackupService($store, PluginRegistry::load(), $runs, $data, $this->services->budgets, $this->services->clock), (int) $this->services->config->get('dashboard.warnAfterDays'), $this->services->clock);
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

        // an error shows every section, so that nothing that may have caused it stays folded
        $open = $messages !== [];
        $summary = self::summaries($values);
        $inner .= '<section class="form-section"><h2>Site</h2>'
            . ($creating ? View::field('id', 'Id', 'text', $values['id'], ['required' => 'required', 'maxlength' => '40', 'autocapitalize' => 'none', 'spellcheck' => 'false'], 'Lower-case letters, digits, hyphens and underscores; part of the names of the backup files. It cannot be changed later.')
                : '<p>Id: <code>' . Html::e($currentId) . '</code></p>')
            . View::field('name', 'Name', 'text', $values['name'], [], 'Shown in the lists; the id if left empty.')
            . View::select('type', 'Website type', $types, $values['type'], 'PrestaShop 9 and Joomla (5 and 6) know their own files and read their database from their configuration. Other website is any site: the files of its directory and, if you enter one, a database.')
            . View::field('path', 'Site directory', 'text', $values['path'], ['required' => 'required', 'spellcheck' => 'false'], 'The absolute path of the application on this server.')
            . '<p><button type="submit" name="action" value="detect" class="secondary" formnovalidate>Look at the site</button></p>'
            . View::field('note', 'Note', 'text', $values['note'])
            . '</section><section class="form-section"><h2>Storage</h2>'
            . View::field('storage', 'Storage directory', 'text', $values['storage'], ['required' => 'required', 'spellcheck' => 'false'], 'Where the backups of this site are kept: an absolute path, best outside the web root, e.g. /var/backups/threadsafe/shop.')
            . View::field('compression', 'Compression', 'text', $values['compression'], ['inputmode' => 'numeric', 'maxlength' => '1'], '0 (store) to 9; 6 is a good balance.')
            . View::field('part_size', 'Part size', 'text', $values['part_size'], ['spellcheck' => 'false'], 'Split archives into parts of this size, e.g. 500M or 2G. Empty = one archive.')
            . '</section>' . self::fold('Database', $summary['database'], $open)
            . View::select('db_mode', 'Access', ['site' => 'Read it from the site\'s own configuration (or the site has none)', 'manual' => 'Enter it here'], $values['db_mode'], 'PrestaShop keeps its database access in app/config/parameters.php; reading it from there means no second copy of the password. Types without such a file need the entries below.')
            . View::field('db_host', 'Host', 'text', $values['db_host'], ['spellcheck' => 'false'])
            . View::field('db_port', 'Port', 'text', $values['db_port'], ['inputmode' => 'numeric'])
            . View::field('db_name', 'Database', 'text', $values['db_name'], ['spellcheck' => 'false'])
            . View::field('db_user', 'User', 'text', $values['db_user'], ['autocomplete' => 'off', 'spellcheck' => 'false'])
            . View::field('db_password', 'Password', 'password', '', ['autocomplete' => 'new-password'], $current?->database !== null ? 'Leave empty to keep the stored password. It is never shown.' : 'Stored in the profile file (mode 0600).')
            . View::field('db_prefix', 'Table prefix', 'text', $values['db_prefix'], ['spellcheck' => 'false'])
            . View::select('db_driver', 'PHP interface', array_combine(DatabaseSettings::DRIVERS, DatabaseSettings::DRIVERS), $values['db_driver'])
            . '</div></details>' . self::fold('What to back up', $summary['filters'], $open)
            . View::checkbox('default_filters', 'Use the filters of the website type', $values['default_filters'] === '1', 'Caches, logs and the tables of statistics are left out; the type knows which.')
            . View::textarea('exclude', 'Leave out also', $values['exclude'], 4, 'Paths relative to the site directory, one per line.')
            . View::textarea('structure_only', 'Tables of which only the structure is kept', $values['structure_only'], 3, 'One per line, with or without the table prefix.')
            . '</div></details>' . self::fold('Automation', $summary['automation'], $open)
            . '<h3>Schedule</h3>'
            . View::field('schedule', 'Back up by itself at', 'text', $values['schedule'] ?? '', ['spellcheck' => 'false', 'placeholder' => '30 3 * * *'], 'A cron expression: minute hour day-of-month month day-of-week, e.g. 30 3 * * * for 03:30 every day, or @daily. Read in the time zone ' . $this->services->config->scheduleZone()->getName() . ' (System page). Empty: no scheduled backups. Something must call the trigger, see the System page.')
            . '<h3>Retention</h3><p class="hint">What is kept in the storage. The newest usable backup and pinned backups are never deleted. Every rule is optional.</p>'
            . View::field('keep', 'Keep at most this many backups', 'text', $values['keep'] ?? '', ['inputmode' => 'numeric'])
            . View::field('max_age_days', 'Delete backups older than this many days', 'text', $values['max_age_days'] ?? '', ['inputmode' => 'numeric'])
            . View::field('max_size', 'Keep the backups under this size together', 'text', $values['max_size'] ?? '', ['spellcheck' => 'false'], 'For example 20G or 500M. The oldest ones are deleted first.')
            . '</div></details>' . self::fold('Encryption', $summary['encryption'], $open)
            . View::textarea('recipients', 'Public keys', $values['recipients'] ?? '', 3, 'One per line (age1...). Backups are encrypted for these keys; only the matching secret keys open them, this server cannot. Empty: not encrypted.' . ($creating ? ' Make a key pair on the profile page after saving, or with the command "threadsafe keys generate".' : ''))
            . ($creating ? '' : '<p><a href="' . Html::e($context->urls->to('/profiles/' . $currentId . '/keys')) . '">Make a key pair</a> (the secret key is shown once; the profile is not saved by following this link)</p>')
            . '</div></details>'
            . '<div class="form-bar"><button type="submit" name="action" value="save">' . ($creating ? 'Test and create' : 'Test and save') . '</button> '
            . '<button type="submit" name="action" value="test" class="secondary">Only test</button> '
            . '<a href="' . Html::e($context->urls->to($creating ? '/profiles' : '/profiles/' . $currentId)) . '">Cancel</a>'
            . '<label class="inline-check" title="A profile that fails its test is not stored, unless you tick this."><input type="checkbox" name="skip_test" value="1"> Save without testing</label></div>';

        $route = $creating ? '/profiles' : '/profiles/' . $currentId;

        return View::page($context, $creating ? 'New profile' : 'Edit ' . $currentName, View::form($context, $route, $inner, 'wide'), '/profiles', $status);
    }

    /**
     * A section of the form that is folded: its name and, in the same line, what it is set to now (so that one sees what is set without
     * opening it). It is closed by `</div></details>`. An error opens it.
     */
    private static function fold(string $title, string $summary, bool $open): string
    {
        return '<details class="form-section"' . ($open ? ' open' : '') . '><summary><h2>' . Html::e($title) . '</h2><span class="summary-value">' . Html::e($summary) . '</span></summary><div class="form-section-body">';
    }

    /**
     * What the folded sections are set to, in a few words, from the values of the form.
     *
     * @param array<string, string> $values
     *
     * @return array{database: string, filters: string, automation: string, encryption: string}
     */
    private static function summaries(array $values): array
    {
        $lines = static fn (string $text): int => count(array_filter(array_map('trim', explode("\n", $text)), static fn (string $line): bool => $line !== ''));

        $database = ($values['db_mode'] ?? 'site') === 'manual'
            ? 'Entered here' . (trim($values['db_name'] ?? '') !== '' ? ': ' . trim($values['db_name']) : '')
            : 'Read from the site';

        $filters = [($values['default_filters'] ?? '1') === '1' ? 'defaults of the type' : 'no defaults'];
        if ($lines($values['exclude'] ?? '') > 0) {
            $filters[] = $lines($values['exclude']) . ' more left out';
        }
        if ($lines($values['structure_only'] ?? '') > 0) {
            $filters[] = $lines($values['structure_only']) . ' tables structure only';
        }

        $keeps = array_filter([
            trim($values['keep'] ?? '') === '' ? null : 'keeps the last ' . trim($values['keep']),
            trim($values['max_age_days'] ?? '') === '' ? null : 'up to ' . trim($values['max_age_days']) . ' days',
            trim($values['max_size'] ?? '') === '' ? null : 'up to ' . trim($values['max_size']),
        ]);
        $automation = (trim($values['schedule'] ?? '') === '' ? 'no schedule' : 'schedule ' . trim($values['schedule'])) . ', ' . ($keeps === [] ? 'keeps everything' : implode(', ', $keeps));

        $keys = $lines($values['recipients'] ?? '');

        return [
            'database' => $database,
            'filters' => implode(', ', $filters),
            'automation' => $automation,
            'encryption' => $keys === 0 ? 'off' : 'for ' . $keys . ' public key' . ($keys === 1 ? '' : 's'),
        ];
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
