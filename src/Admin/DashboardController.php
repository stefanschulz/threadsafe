<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * The start page. At this stage of the interface it shows how many site profiles there are and what was done lately; the cards
 * with the state of the backups come with the profile pages (docs/PHASE4.md 3.5, work packages 3 to 7).
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
        $profiles = count((new ProfileStore($this->services->data . '/profiles'))->ids());

        $rows = '';
        foreach ($this->services->audit->recent(10) as $entry) {
            $detail = array_diff_key($entry, ['time' => 1, 'event' => 1, 'user' => 1, 'address' => 1]);
            $text = [];
            foreach ($detail as $name => $value) {
                $text[] = $name . ': ' . (is_scalar($value) ? (string) $value : '');
            }
            $rows .= '<tr><td><time datetime="' . Html::e((string) ($entry['time'] ?? '')) . '">' . Html::e((string) ($entry['time'] ?? '')) . '</time></td><td>' . Html::e((string) ($entry['event'] ?? '')) . '</td>'
                . '<td>' . Html::e((string) ($entry['user'] ?? '')) . '</td><td>' . Html::e(implode(', ', $text)) . '</td></tr>';
        }

        $body = '<p class="summary"><strong>' . $profiles . '</strong> site ' . ($profiles === 1 ? 'profile' : 'profiles') . '.'
            . ($profiles === 0 ? ' Create one with <code>php bin/threadsafe profile add</code>; the browser form comes next.' : '') . '</p>'
            . '<h2>Recent activity</h2>'
            . ($rows === '' ? '<p class="hint">Nothing yet.</p>' : '<div class="scroll"><table><thead><tr><th>Time (UTC)</th><th>Event</th><th>User</th><th>Details</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');

        return View::page($context, 'Dashboard', $body);
    }
}
