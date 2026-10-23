<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\App\BackupService;
use ThreadSafe\App\Overview;
use ThreadSafe\App\TriggerStatus;
use ThreadSafe\Engine\RunStore;
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

        if ($overview['cards'] === []) {
            $cards = '<div class="box"><p>There are no sites yet. A site profile says which website to back up and where its backups go.</p><p><a class="button" href="'
                . Html::e($context->urls->to('/new-profile')) . '">Create the first profile</a></p></div>';
        } else {
            $cards = '<div class="cards">';
            foreach ($overview['cards'] as $card) {
                $cards .= $this->card($context, $card, $trigger['next'][$card['profile']->id] ?? null);
            }
            $cards .= '</div><p><a href="' . Html::e($context->urls->to('/new-profile')) . '">New profile</a></p>';
        }

        $body = $header . (new CatalogController($this->services))->checksBox($context) . $cards
            . '<h2>Recent activity</h2>' . View::audit($this->services->audit->recent(8))
            . '<p class="hint"><a href="' . Html::e($context->urls->to('/system')) . '">More on the System page</a></p>';

        return View::page($context, 'Dashboard', $body);
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

        $html = '<article class="card ' . Html::e($level) . '"><header><h2><a href="' . Html::e($context->urls->to("/profiles/$id")) . '">' . Html::e($profile->name) . '</a></h2>'
            . '<span class="badge ' . Html::e($level) . '">' . Html::e($word) . '</span></header>'
            . '<p class="hint">' . Html::e($application !== '' ? $application : $profile->type) . '</p>';

        if ($last !== null && $last->sidecar !== null) {
            $time = $last->name->time->format('Y-m-d\TH:i:s\Z');
            $html .= '<p>Last backup: <a href="' . Html::e($context->urls->to("/profiles/$id/backups/" . $last->name->base())) . '"><time datetime="' . Html::e($time) . '">' . Html::e(str_replace('T', ' ', rtrim($time, 'Z'))) . '</time></a>'
                . ' (' . Html::e(View::age(($this->services->clock)() - $last->name->time->getTimestamp())) . '), ' . Html::e(SpaceCheck::format($last->sidecar->size())) . ', ' . Html::e($last->label()) . '</p>'
                . '<p class="hint">' . $card['backups'] . ' ' . ($card['backups'] === 1 ? 'backup' : 'backups') . ', ' . Html::e(SpaceCheck::format((int) $card['bytes'])) . '</p>';
        } else {
            $html .= '<p>No backup yet.</p>';
        }

        if ($next !== null) {
            $html .= '<p class="hint">Next scheduled backup: <time datetime="' . Html::e($next->format('c')) . '">' . Html::e($next->format('Y-m-d H:i T')) . '</time> (' . Html::e($profile->schedule !== null ? $profile->schedule->cron : '') . ')</p>';
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
        $actions .= '<a href="' . Html::e($context->urls->to("/profiles/$id")) . '">Backups</a> <a href="' . Html::e($context->urls->to("/profiles/$id/edit")) . '">Edit</a>';

        return $html . '<div class="actions">' . $actions . '</div></article>';
    }
}
