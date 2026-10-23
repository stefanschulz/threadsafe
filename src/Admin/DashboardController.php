<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\App\BackupService;
use ThreadSafe\App\Overview;
use ThreadSafe\App\TriggerStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Schedule\Heartbeat;
use ThreadSafe\Schedule\Scheduler;
use ThreadSafe\Schedule\ScheduleStore;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * The start page (docs/PHASE4.md 3.5, 3.9): a header with the totals, one card for every site with its newest backup, its state and
 * its warnings, the checks that are going, and the last entries of the audit log. Everything on it is cheap to read (see {@see Overview}).
 */
final class DashboardController
{
    public function __construct(private readonly Services $services)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Context $context, array $params = []): Response
    {
        $data = $this->services->data;
        $profiles = new ProfileStore($data . '/profiles');
        $runs = new RunStore($data . '/runs');
        $backups = new BackupService($profiles, PluginRegistry::load(), $runs, $data, $this->services->budgets, $this->services->clock);
        $overview = (new Overview($profiles, $runs, $backups, (int) $this->services->config->get('dashboard.warnAfterDays'), $this->services->clock))->build();
        $totals = $overview['totals'];
        $config = $this->services->config;
        $trigger = (new TriggerStatus(new Scheduler(new ScheduleStore($data . '/schedule'), $config->scheduleZone(), $config->scheduleGraceMinutes()), new Heartbeat($data . '/heartbeat.json'), $config->expectedIntervalMinutes(), $this->services->clock))->build($profiles->all());

        $header = ($trigger['warning'] === null ? '' : '<p class="flash warning" role="alert">' . Html::e($trigger['warning']) . ' <a href="' . Html::e($context->urls->to('/system')) . '">System</a></p>') . '<p class="summary"><strong>' . $totals['profiles'] . '</strong> ' . ($totals['profiles'] === 1 ? 'site' : 'sites') . ' &middot; <strong>' . $totals['backups'] . '</strong> '
            . ($totals['backups'] === 1 ? 'backup' : 'backups') . ' &middot; <strong>' . Html::e(SpaceCheck::format($totals['bytes'])) . '</strong> in storage'
            . ($totals['running'] > 0 ? ' &middot; <strong>' . $totals['running'] . '</strong> running' : '') . '</p>';

        // the sites can be filtered by website type (`?type=...`; an address that works without script); the tile for a new profile is always there
        $byType = [];
        foreach ($overview['cards'] as $card) {
            $byType[$card['profile']->type] = ($byType[$card['profile']->type] ?? 0) + 1;
        }
        ksort($byType);
        $wanted = $context->request->query('type');
        $selected = is_string($wanted) && isset($byType[$wanted]) ? $wanted : null;

        $cards = '';
        if ($overview['cards'] === []) {
            $cards .= '<div class="box"><p>There are no sites yet. A site profile says which website to back up and where its backups go.</p></div>';
        } else {
            $cards .= $this->filter($context, $byType, count($overview['cards']), $selected);
        }
        $cards .= '<div class="cards">';
        foreach ($overview['cards'] as $card) {
            if ($selected === null || $card['profile']->type === $selected) {
                $cards .= $this->card($context, $card, $trigger['next'][$card['profile']->id] ?? null);
            }
        }
        // the last tile is an empty card: the way to the next profile, the whole surface is the link
        $cards .= '<a class="card new" href="' . Html::e($context->urls->to('/new-profile')) . '">' . Icons::svg('plus') . '<span>New profile</span></a></div>';

        $body = $header . (new CatalogController($this->services))->checksBox($context) . $cards
            . '<h2>Recent activity</h2>' . View::audit($this->services->audit->recent(8))
            . '<p class="hint"><a href="' . Html::e($context->urls->to('/system')) . '">More on the System page</a></p>';

        return View::page($context, 'Dashboard', $body);
    }

    /**
     * The filter above the tiles: all, and one entry per website type that the profiles have, each with its number. The chosen one is marked
     * (`aria-current`); the entries are links, so the choice is in the address and works without script.
     *
     * @param array<string, int> $byType  website type => number of profiles
     * @param string|null        $selected the type that is shown, or null for all
     */
    private function filter(Context $context, array $byType, int $all, ?string $selected): string
    {
        $plugins = PluginRegistry::load();
        $chip = static fn (string $label, int $count, string $href, bool $current): string => '<a href="' . Html::e($href) . '"' . ($current ? ' aria-current="true"' : '') . '>'
            . Html::e($label) . ' <span class="count">' . $count . '</span></a>';
        $html = $chip('All', $all, $context->urls->to('/'), $selected === null);
        foreach ($byType as $type => $count) {
            try {
                $label = $plugins->get($type)->title();
            } catch (PluginError) {
                $label = $type;
            }
            $html .= $chip($label, $count, $context->urls->to('/', ['type' => $type]), $selected === $type);
        }

        return '<nav class="filter" aria-label="Filter the sites by website type"><span class="filter-label">Website type</span>' . $html . '</nav>';
    }

    /**
     * @param array<string, mixed> $card
     */
    private function card(Context $context, array $card, ?\DateTimeImmutable $next = null): string
    {
        /** @var \ThreadSafe\Profile\SiteProfile $profile */
        $profile = $card['profile'];
        /** @var \ThreadSafe\Storage\BackupEntry|null $last */
        $last = $card['last'];
        /** @var \ThreadSafe\Storage\BackupEntry|null $good */
        $good = $card['lastGood'];
        $id = $profile->id;
        $level = (string) $card['level'];
        $word = ['ok' => 'OK', 'warning' => 'Needs a look', 'problem' => 'Problem'][$level] ?? $level;

        $application = '';
        $reference = $good ?? $last;
        if ($reference?->sidecar !== null) {
            $m = $reference->sidecar->manifest;
            $application = trim($m->application['name'] . ' ' . ($m->application['version'] ?? ''));
        }

        // the title is text, with a pencil beside it (a link with a tooltip and a name, no label); the way into the profile is the button below
        $html = '<article class="card ' . Html::e($level) . '"><header><div class="card-title"><h2>' . Html::e($profile->name) . '</h2>'
            . '<a class="icon-button" href="' . Html::e($context->urls->to("/profiles/$id/edit")) . '" title="Edit profile" aria-label="Edit ' . Html::e($profile->name) . '">' . Icons::svg('edit') . '</a></div>'
            . '<span class="badge ' . Html::e($level) . '">' . Html::e($word) . '</span></header>'
            . '<p class="hint">' . Html::e($application !== '' ? $application : $profile->type) . '</p>';

        if ($last !== null && $last->sidecar !== null) {
            // date, time and state; how long ago and how big are on the pages of the backup
            $time = $last->name->time->format('Y-m-d\TH:i:s\Z');
            $html .= '<p>Last backup: <a href="' . Html::e($context->urls->to("/profiles/$id/backups/" . $last->name->base())) . '"><time datetime="' . Html::e($time) . '" data-minutes>' . Html::e(substr(str_replace('T', ' ', $time), 0, 16)) . '</time></a> &middot; ' . Html::e($last->label()) . '</p>';
        } else {
            $html .= '<p>No backup yet.</p>';
        }

        if ($next !== null) {
            $html .= '<p>Next backup: <time datetime="' . Html::e($next->format('c')) . '" data-minutes>' . Html::e($next->format('Y-m-d H:i T')) . '</time></p>';
        }

        if ($card['warnings'] !== []) {
            $html .= '<ul class="warnings">';
            foreach ($card['warnings'] as $warning) {
                $html .= '<li class="' . Html::e((string) $warning['level']) . '"><strong>' . ($warning['level'] === Overview::PROBLEM ? 'Problem' : 'Warning') . '</strong> ' . Html::e((string) $warning['text']) . '</li>';
            }
            $html .= '</ul>';
        }

        $actions = '';
        if ($card['runState'] === 'active' || $card['runState'] === 'interrupted') {
            $actions .= '<a class="button" href="' . Html::e($context->urls->to('/runs/' . $card['run']->id)) . '">' . ($card['runState'] === 'active' ? 'Follow the backup' : 'Continue the backup') . '</a> ';
        } else {
            $actions .= View::form($context, "/profiles/$id/backup", '<button type="submit">Back up now</button>', 'inline') . ' ';
        }
        // the main action is the filled button; the way into the profile (its backups, settings, retention) is a secondary button
        $actions .= '<a class="button secondary" href="' . Html::e($context->urls->to("/profiles/$id")) . '">Profile</a>';

        return $html . '<div class="actions">' . $actions . '</div></article>';
    }
}
