<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use Throwable;
use ThreadSafe\App\RetentionService;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Response;

/**
 * Retention on the profile page (docs/PHASE5.md 3.4, 3.6): the rules, a preview of what they would delete now and why, the last run, and
 * the button that applies them - which is also the go-ahead that a rule needs before the trigger deletes more than one backup alone.
 */
final class RetentionController
{
    public function __construct(private readonly Services $services)
    {
    }

    /** The box for the profile page. */
    public function box(Context $context, SiteProfile $profile): string
    {
        $rules = $profile->retention;
        if ($rules === null) {
            return '<h2>Retention</h2><p class="hint">This profile has no retention rules: backups stay until you delete them. Set rules with "Edit".</p>';
        }
        $parts = [];
        if ($rules->keep !== null) {
            $parts[] = 'at most ' . $rules->keep . ' backups';
        }
        if ($rules->maxAgeDays !== null) {
            $parts[] = 'none older than ' . $rules->maxAgeDays . ' days';
        }
        if ($rules->maxBytes !== null) {
            $parts[] = 'together under ' . SpaceCheck::format($rules->maxBytes);
        }
        $html = '<h2>Retention</h2><p>Rules: ' . Html::e(implode(', ', $parts)) . '. The newest usable backup and pinned backups are never deleted.</p>';

        try {
            $service = RetentionService::create($this->services->data, $this->services->clock);
            $plan = $service->preview($profile);
            $state = $service->state($profile->id);
        } catch (Throwable $e) {
            return $html . '<p class="flash error">The rules cannot be looked at: ' . Html::e($e->getMessage()) . '</p>';
        }

        if ($plan->blocked) {
            return $html . '<p class="flash error">' . Html::e(implode(' ', $plan->notes)) . '</p>';
        }
        if ($state->waiting !== null) {
            $html .= '<p class="flash warning" role="alert">These rules have not been seen at work yet and would delete ' . $state->waiting . ' backups. The trigger waits for your go-ahead: check the list below, then apply the rules.</p>';
        }
        $all = [...$plan->delete, ...$plan->leftovers];
        if ($all === []) {
            $html .= '<p>If the rules were applied now, nothing would be deleted.</p>';
        } else {
            $items = '';
            foreach ($all as $action) {
                $items .= '<li><code>' . Html::e($action->base()) . '</code> (' . Html::e(SpaceCheck::format($action->bytes)) . '): ' . Html::e($action->reason) . '</li>';
            }
            $html .= '<p>If the rules were applied now, these would be deleted, the oldest first (' . Html::e(SpaceCheck::format($plan->freed())) . ' freed):</p><ul class="plain">' . $items . '</ul>'
                . View::form($context, '/profiles/' . $profile->id . '/retention', '<button type="submit" class="danger">Apply the rules now</button>', 'inline');
        }
        foreach ($plan->notes as $note) {
            $html .= '<p class="hint">' . Html::e($note) . '</p>';
        }
        if ($state->lastRun !== null) {
            $html .= '<p class="hint">Retention last ran ' . Html::e(View::age(($this->services->clock)() - $state->lastRun)) . ': ' . $state->lastDeleted . ' backup' . ($state->lastDeleted === 1 ? '' : 's') . ' deleted, ' . Html::e(SpaceCheck::format($state->lastFreed)) . ' freed. It runs after every backup and once an hour.</p>';
        }

        return $html;
    }

    /**
     * @param array<string, string> $params
     */
    public function apply(Context $context, array $params): Response
    {
        $id = $params['id'] ?? '';
        try {
            $profile = ProfileStore::validId($id) ? (new ProfileStore($this->services->data . '/profiles'))->load($id) : null;
        } catch (ProfileException) {
            $profile = null;
        }
        if ($profile === null) {
            return View::page($context, 'Not found', '<p>There is no such profile. <a href="' . Html::e($context->urls->to('/profiles')) . '">Back to the profiles</a>.</p>', '/profiles', 404);
        }
        $back = Response::redirect($context->urls->to('/profiles/' . $id, ['tab' => 'retention']));
        if ($profile->retention === null) {
            $context->flash('warning', 'The profile has no retention rules.');

            return $back;
        }
        try {
            $done = RetentionService::create($this->services->data, $this->services->clock)->apply($profile, $context->user() ?? '-', $context->request->remoteAddress);
        } catch (Throwable $e) {
            $context->flash('error', 'Retention could not be applied: ' . $e->getMessage());

            return $back;
        }
        $count = count($done->delete) + count($done->leftovers);
        $context->flash($done->blocked ? 'error' : 'ok', $done->blocked ? implode(' ', $done->notes) : ($count === 0 ? 'Nothing to delete.' : sprintf('%d backup%s deleted%s, %s freed.', count($done->delete), count($done->delete) === 1 ? '' : 's', $done->leftovers === [] ? '' : ' and ' . count($done->leftovers) . ' leftover' . (count($done->leftovers) === 1 ? '' : 's') . ' removed', SpaceCheck::format($done->freed()))));

        return $back;
    }
}
