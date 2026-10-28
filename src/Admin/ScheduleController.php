<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use DateTimeImmutable;
use DateTimeZone;
use ThreadSafe\App\ProfileService;
use ThreadSafe\App\ScheduleChange;
use ThreadSafe\App\ScheduleRow;
use ThreadSafe\App\ScheduleService;
use ThreadSafe\App\TriggerStatus;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\ScheduleSettings;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Schedule\Heartbeat;
use ThreadSafe\Schedule\Scheduler;
use ThreadSafe\Schedule\ScheduleState;
use ThreadSafe\Schedule\ScheduleStore;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * The page of all schedules (docs/PHASE8.md 3.3, 3.4): a table with a line for every profile - when it runs, when it runs next, what became of
 * the last run - that can be sorted by name, website type and time, and the forms that change the schedule of one or of several profiles at
 * once, pause them or resume them.
 */
final class ScheduleController
{
    /** The best-known way to name the rows that are ticked: `p_<id>` is "1" for each. */
    private const string TICK = 'p_';

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Context $context, array $params = []): Response
    {
        $zone = $this->services->config->scheduleZone();
        $now = $this->now($zone);
        $sort = $context->request->query('sort');
        $sort = is_string($sort) && in_array($sort, ScheduleService::SORTS, true) ? $sort : 'time';
        $direction = $context->request->query('dir') === 'desc' ? 'desc' : 'asc';
        $rows = $this->scheduleService()->rows($now, $sort, $direction);

        $profiles = array_map(static fn (ScheduleRow $row): SiteProfile => $row->profile, $rows);
        $byType = TypeFilter::count($profiles);
        $selected = TypeFilter::selected($context, $byType);
        $shown = array_values(array_filter($rows, static fn (ScheduleRow $row): bool => $selected === null || $row->profile->type === $selected));

        $body = '<p class="hint">Times are in the time zone <strong>' . Html::e($zone->getName()) . '</strong>, which is a setting of the <a href="' . Html::e($context->urls->to('/system', ['tab' => 'settings'])) . '">System page</a>. '
            . 'A schedule is only a promise: something has to call the trigger, and the <a href="' . Html::e($context->urls->to('/system', ['tab' => 'cron'])) . '">System page</a> says how.</p>';
        $trigger = $this->trigger($profiles, $now);
        if ($trigger !== null) {
            $body .= '<p class="flash warning" role="alert">' . Html::e($trigger) . '</p>';
        }
        $damaged = (new ProfileService(new ProfileStore($this->services->data . '/profiles'), PluginRegistry::load(), $this->services->data))->listing()['damaged'];
        if ($damaged !== []) {
            $body .= '<p class="flash error" role="alert">The files of these profiles cannot be read, so they are not in the table: ' . Html::e(implode(', ', $damaged)) . '. <a href="' . Html::e($context->urls->to('/profiles')) . '">The profiles page</a> says what is wrong with each.</p>';
        }
        if ($rows === []) {
            return View::page($context, 'Schedule', $body . '<p>There are no site profiles yet. A schedule belongs to a profile: <a href="' . Html::e($context->urls->to('/new-profile')) . '">make a profile</a> first.</p>', '/schedule');
        }
        if (array_filter($rows, static fn (ScheduleRow $row): bool => $row->schedule !== null) === []) {
            $body .= '<p>No profile has a schedule yet. Tick the profiles that are to be backed up by themselves and press <strong>Change the selected</strong>, or use <strong>Change</strong> in a line.</p>';
        }
        $body .= TypeFilter::render($context, '/schedule', $byType, count($rows), $selected)
            . View::form($context, '/schedule/action', $this->hiddenView($context, ['sort' => $sort, 'dir' => $direction] + ($selected === null ? [] : ['type' => $selected])) . $this->bulkButtons() . $this->table($context, $shown, $zone, $now, $sort, $direction, $selected));

        return View::page($context, 'Schedule', $body, '/schedule');
    }

    /**
     * The form for one profile (a link from a line of the table).
     *
     * @param array<string, string> $params
     */
    public function editForm(Context $context, array $params = []): Response
    {
        $id = $context->request->query('profile');
        if (!is_string($id) || !ProfileStore::validId($id)) {
            return $this->missing($context);
        }

        return $this->form($context, [$id], null, []);
    }

    /**
     * What the buttons of the table do: *Change the selected* shows the form for the ticked profiles, *Pause* and *Resume* act on the ticked
     * ones or on the one of the line.
     *
     * @param array<string, string> $params
     */
    public function act(Context $context, array $params = []): Response
    {
        $do = (string) ($context->request->post('do') ?? '');
        $ids = $this->ticked($context);
        if (preg_match('/^(pause|resume):(.+)$/', $do, $m) === 1) {
            $do = $m[1];
            $ids = ProfileStore::validId($m[2]) ? [$m[2]] : [];
        }
        $back = Response::redirect($context->urls->to('/schedule', $this->keep($context)));
        if (!in_array($do, ['edit', 'pause', 'resume'], true)) {
            return $back;
        }
        if ($ids === []) {
            $context->flash('warning', 'Tick at least one profile first.');

            return $back;
        }
        if ($do === 'edit') {
            return $this->form($context, $ids, null, []);
        }
        $changes = $this->scheduleService()->pause($ids, $do === 'pause');
        $this->report($context, $changes, $do === 'pause' ? 'schedule.paused' : 'schedule.resumed');

        return $back;
    }

    /**
     * The form of one or several profiles: a preview of the next runs, or the change itself.
     *
     * @param array<string, string> $params
     */
    public function edit(Context $context, array $params = []): Response
    {
        $ids = $this->ticked($context);
        $action = (string) ($context->request->post('action') ?? '');
        if ($ids === []) {
            $context->flash('warning', 'Tick at least one profile first.');

            return Response::redirect($context->urls->to('/schedule'));
        }
        $values = ScheduleFields::fromRequest($context->request);
        if ($values === [] || !in_array($action, ['save', 'preview'], true)) {
            // no fields, or no wish to save: only the form (a request that lacks the fields must never take a schedule away)
            return $this->form($context, $ids, $values === [] ? null : $values, []);
        }
        $profiles = $this->profilesOf($ids);
        $read = ScheduleFields::read($values, count($profiles) === 1 ? $profiles[0]->schedule : null);
        if ($read['errors'] !== []) {
            return $this->form($context, $ids, $values, $read['errors'], 400);
        }
        if ($action === 'preview') {
            return $this->form($context, $ids, $values, [], 200, $this->preview($read['settings'], $context));
        }

        $keep = ($values['schedule_every'] ?? '') === ScheduleFields::KEEP;
        $changes = $keep ? [] : $this->scheduleService()->set(array_map(static fn (SiteProfile $p): string => $p->id, $profiles), $read['settings']);
        if ($keep) {
            $context->flash('warning', 'Nothing was changed: "Leave as it is" is chosen.');
        } else {
            $this->report($context, $changes, 'schedule.changed');
        }

        return Response::redirect($context->urls->to('/schedule', $this->keep($context)));
    }

    // ------------------------------------------------------------------------------------------------- the table

    /**
     * The sort and the filter of the table as hidden fields, so that a change brings the person back to the same view.
     *
     * @param array<string, string> $view
     */
    private function hiddenView(Context $context, array $view): string
    {
        $html = '';
        foreach ($view as $name => $value) {
            $html .= '<input type="hidden" name="' . Html::e($name) . '" value="' . Html::e($value) . '">';
        }

        return $html;
    }

    private function bulkButtons(): string
    {
        return '<div class="actions bulk" role="group" aria-label="For the ticked profiles">'
            . '<button type="submit" name="do" value="edit">Change the selected</button>'
            . '<button type="submit" name="do" value="pause" class="secondary">Pause</button>'
            . '<button type="submit" name="do" value="resume" class="secondary">Resume</button>'
            . '</div>';
    }

    /**
     * @param list<ScheduleRow> $rows
     */
    private function table(Context $context, array $rows, DateTimeZone $zone, DateTimeImmutable $now, string $sort, string $direction, ?string $type): string
    {
        $header = static function (string $key, string $label) use ($context, $sort, $direction, $type): string {
            $current = $sort === $key;
            $next = $current && $direction === 'asc' ? 'desc' : 'asc';
            $query = ['sort' => $key, 'dir' => $next] + ($type === null ? [] : ['type' => $type]);

            return '<th scope="col" aria-sort="' . ($current ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none') . '"><a class="sort" href="' . Html::e($context->urls->to('/schedule', $query)) . '">' . Html::e($label)
                . ($current ? '<span aria-hidden="true">' . ($direction === 'asc' ? ' &#9650;' : ' &#9660;') . '</span>' : '') . '</a></th>';
        };
        $view = ['sort' => $sort, 'dir' => $direction] + ($type === null ? [] : ['type' => $type]);
        $html = '<div class="scroll"><table class="schedule"><caption class="visually-hidden">The schedules of all profiles</caption><thead><tr>'
            . '<th scope="col" class="select"><input type="checkbox" data-select-all aria-label="Tick all profiles"></th>'
            . $header('name', 'Profile') . $header('type', 'Type') . $header('time', 'Runs')
            . '<th scope="col">Next run</th><th scope="col">Last scheduled run</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = $row->profile->id;
            $name = Html::e($row->profile->name);
            $runs = $row->schedule === null
                ? '<span class="hint">Not scheduled</span>'
                : Html::e($row->schedule->describe()) . ($row->paused() ? ' <span class="badge paused">Paused</span>' : '');
            $next = $row->next === null ? '<span class="hint">-</span>' : $this->when($row->next->setTimezone($zone), $now)
                . ($row->overlaps === [] ? '' : '<p class="hint">Starts with ' . Html::e($this->names($row->overlaps)) . ': they run one after the other.</p>');
            $pause = $row->schedule === null ? '' : ($row->paused()
                ? '<button type="submit" name="do" value="resume:' . Html::e($id) . '" class="secondary small">Resume</button>'
                : '<button type="submit" name="do" value="pause:' . Html::e($id) . '" class="secondary small">Pause</button>');
            $html .= '<tr><td class="select"><input type="checkbox" name="' . self::TICK . Html::e($id) . '" value="1" aria-label="Tick ' . $name . '"></td>'
                . '<th scope="row"><a href="' . Html::e($context->urls->to('/profiles/' . $id)) . '">' . $name . '</a></th>'
                . '<td>' . Html::e($row->typeTitle) . '</td><td>' . $runs . '</td><td>' . $next . '</td><td>' . $this->last($row->last, $zone) . '</td>'
                . '<td class="row-actions"><a class="button secondary small" href="' . Html::e($context->urls->to('/schedule/edit', ['profile' => $id] + $view)) . '">Change</a> ' . $pause . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /** A moment in the time zone of the installation with how far away it is: "Thu 29 Oct 03:30 (in 15 hours)". */
    private function when(DateTimeImmutable $at, DateTimeImmutable $now): string
    {
        $seconds = $at->getTimestamp() - $now->getTimestamp();
        $far = match (true) {
            $seconds < 90 => 'in a minute',
            $seconds < 5400 => 'in ' . max(2, intdiv($seconds + 30, 60)) . ' minutes',
            $seconds < 172800 => 'in ' . intdiv($seconds + 1800, 3600) . ' hours',
            default => 'in ' . intdiv($seconds + 43200, 86400) . ' days',
        };

        // not a <time> element: the script of the pages shows those in the time zone of the browser, and these are in the one of the installation
        return '<span class="when" title="' . Html::e($at->format('Y-m-d H:i T')) . '">' . Html::e($at->format('D j M H:i')) . '</span> <span class="hint">(' . $far . ')</span>';
    }

    private function last(?ScheduleState $state, DateTimeZone $zone): string
    {
        if ($state === null || $state->instant === null) {
            return '<span class="hint">-</span>';
        }
        $at = (new DateTimeImmutable('@' . $state->instant))->setTimezone($zone)->format('j M H:i');

        return Html::e(match ($state->status) {
            ScheduleState::DONE => 'made, scheduled for ' . $at,
            ScheduleState::RUNNING => 'running now (scheduled for ' . $at . ')',
            ScheduleState::SKIPPED => 'skipped: the trigger came too late for ' . $at,
            ScheduleState::GAVE_UP => 'failed, given up (' . $at . ')',
            default => 'failed, to be tried again (' . $at . ')',
        });
    }

    /**
     * @param list<string> $names
     */
    private function names(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' and ' . $last;
    }

    /**
     * The warning about the trigger, if the profiles with a schedule have reason for one; null if there is none.
     *
     * @param list<SiteProfile> $profiles
     */
    private function trigger(array $profiles, DateTimeImmutable $now): ?string
    {
        $config = $this->services->config;
        $data = $this->services->data;
        $status = (new TriggerStatus(new Scheduler(new ScheduleStore($data . '/schedule'), $config->scheduleZone(), $config->scheduleGraceMinutes()), new Heartbeat($data . '/heartbeat.json'), $config->expectedIntervalMinutes(), $this->services->clock))->build($profiles);

        return $status['warning'];
    }

    // ------------------------------------------------------------------------------------------------- the form

    /**
     * @param list<string>              $ids
     * @param array<string, string>|null $values  the values of the fields as they were sent; null: those of the profile (one profile) or none
     * @param list<string>              $errors
     */
    private function form(Context $context, array $ids, ?array $values, array $errors, int $status = 200, string $preview = ''): Response
    {
        $profiles = $this->profilesOf($ids);
        if ($profiles === []) {
            return $this->missing($context);
        }
        $multiple = count($profiles) > 1;
        // for several profiles the form starts with "leave as it is"
        $values ??= $multiple ? [ScheduleFields::EVERY => ScheduleFields::KEEP] + ScheduleFields::values(null) : ScheduleFields::values($profiles[0]->schedule);
        $names = implode(', ', array_map(static fn (SiteProfile $profile): string => Html::e($profile->name), $profiles));
        $hidden = '';
        foreach ($profiles as $profile) {
            $hidden .= '<input type="hidden" name="' . self::TICK . Html::e($profile->id) . '" value="1">';
        }
        $hidden .= $this->hiddenView($context, $this->keep($context));
        $problems = '';
        foreach ($errors as $error) {
            $problems .= '<p class="flash error" role="alert">' . Html::e($error) . '</p>';
        }
        $zone = $this->services->config->scheduleZone()->getName();
        $body = '<p>' . ($multiple ? 'The schedule of these profiles is changed together: ' : 'The schedule of ') . '<strong>' . $names . '</strong>.</p>' . $problems
            . View::form(
                $context,
                '/schedule/edit',
                $hidden . ScheduleFields::render($values, $multiple)
                . '<p class="hint">Times are read in the time zone ' . Html::e($zone) . '. Something must call the trigger, see the System page.</p>' . $preview
                . '<div class="actions"><button type="submit" name="action" value="save">Save</button> <button type="submit" name="action" value="preview" class="secondary">Show the next runs</button> '
                . '<a href="' . Html::e($context->urls->to('/schedule')) . '">Cancel</a></div>',
                'wide',
            );

        return View::page($context, 'Change the schedule', $body, '/schedule', $status);
    }

    /** The next three runs of what is entered, so that the person sees what the choice means before saving. */
    private function preview(?ScheduleSettings $settings, Context $context): string
    {
        if ($settings === null) {
            return '<p class="flash ok" role="status">Not scheduled: no backups are made by themselves.</p>';
        }
        $zone = $this->services->config->scheduleZone();
        $at = $this->now($zone);
        $runs = [];
        for ($i = 0; $i < 3; ++$i) {
            $at = $settings->expression()->next($at);
            $runs[] = '<li>' . Html::e($at->format('l j F Y, H:i')) . '</li>';
        }

        return '<div class="flash ok" role="status"><p><strong>' . Html::e($settings->describe()) . '</strong>' . ($settings->paused ? ' (paused: no backup is made until it is resumed)' : '') . '. The next runs would be:</p><ul class="plain">' . implode('', $runs) . '</ul></div>';
    }

    // ------------------------------------------------------------------------------------------------- helpers

    /**
     * Tells the person what was done, one message for all profiles, and writes the audit log.
     *
     * @param list<ScheduleChange> $changes
     */
    private function report(Context $context, array $changes, string $event): void
    {
        $zone = $this->services->config->scheduleZone();
        $now = $this->now($zone);
        $done = [];
        $rest = [];
        foreach ($changes as $change) {
            if ($change->status === ScheduleChange::CHANGED) {
                $name = $change->name;
                $text = $change->after === null ? $name . ': no schedule any more' : $name . ': ' . lcfirst($change->after->describe()) . ($change->after->paused ? ', paused' : '');
                if ($change->after !== null && !$change->after->paused) {
                    $text .= ' (next run ' . $change->after->expression()->next($now)->format('D j M H:i') . ')';
                }
                $done[] = $text;
                $this->services->audit->record($event, $context->user() ?? '-', $context->request->remoteAddress, ['profile' => $change->id, 'before' => $change->before?->text(), 'after' => $change->after?->text(), 'paused' => $change->after !== null && $change->after->paused]);
            } elseif ($change->status === ScheduleChange::UNCHANGED) {
                $rest[] = $change->name . ' was already so';
            } else {
                $rest[] = $change->name . ' ' . ($change->message !== '' ? $change->message : $change->status);
            }
        }
        if ($done !== []) {
            $context->flash('ok', implode('; ', $done) . '.');
        }
        if ($rest !== []) {
            $context->flash($done === [] && count(array_filter($changes, static fn (ScheduleChange $c): bool => $c->status === ScheduleChange::UNCHANGED)) === count($changes) ? 'ok' : 'warning', 'Not changed: ' . implode('; ', $rest) . '.');
        }
    }

    /**
     * The ids of the profiles that are ticked in the request (`p_<id>`), in the order of the request.
     *
     * @return list<string>
     */
    private function ticked(Context $context): array
    {
        $ids = [];
        foreach (array_keys($context->request->post) as $name) {
            if (str_starts_with($name, self::TICK) && $context->request->post($name) === '1' && ProfileStore::validId(substr($name, strlen(self::TICK)))) {
                $ids[] = substr($name, strlen(self::TICK));
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<SiteProfile>
     */
    private function profilesOf(array $ids): array
    {
        $store = new ProfileStore($this->services->data . '/profiles');
        $profiles = [];
        foreach ($ids as $id) {
            try {
                $profiles[] = $store->load($id);
            } catch (ProfileException) {
                continue;
            }
        }

        return SiteProfile::sortedByName($profiles);
    }

    /**
     * What the address keeps of the view (the sort and the filter), so that a change does not throw the order away.
     *
     * @return array<string, string>
     */
    private function keep(Context $context): array
    {
        $query = [];
        foreach (['sort', 'dir', 'type'] as $name) {
            $value = $context->request->post($name) ?? $context->request->query($name);
            if (is_string($value) && $value !== '') {
                $query[$name] = $value;
            }
        }

        return $query;
    }

    private function scheduleService(): ScheduleService
    {
        $data = $this->services->data;
        $config = $this->services->config;
        $plugins = PluginRegistry::load();
        $states = new ScheduleStore($data . '/schedule');

        return new ScheduleService(
            new ProfileService(new ProfileStore($data . '/profiles'), $plugins, $data),
            new Scheduler($states, $config->scheduleZone(), $config->scheduleGraceMinutes()),
            $states,
            $plugins,
        );
    }

    private function now(DateTimeZone $zone): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . ($this->services->clock)()))->setTimezone($zone);
    }

    private function missing(Context $context): Response
    {
        return View::page($context, 'Not found', '<p>There is no such profile. <a href="' . Html::e($context->urls->to('/schedule')) . '">Back to the schedules</a>.</p>', '/schedule', 404);
    }
}
