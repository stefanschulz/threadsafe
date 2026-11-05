<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;

/**
 * The page of a run that the person watches in the two wizards (the restore and the installation): the bar, what the last step did, what the
 * step that is working does now, the clock, and the two buttons. A step of a web request can take half a minute during which the page would
 * show nothing new; the clock and the note ({@see \ThreadSafe\Engine\Budget::note()}, read from the run's live file) are the signs of life.
 *
 * The buttons say what is possible: "Continue" only when nothing is working and the run has not failed, "Try the failed step again" only when
 * it has. With script the page presses them itself; without script "Continue" is the way on.
 */
final class RunView
{
    /**
     * What the page asks for while a step is working.
     *
     * @return array{working: bool, note: string, seconds: int, elapsed: int} whether a step is working now, its last note, how long it has
     *                                                                         been working, and how long the run has existed (seconds)
     */
    public static function live(RunStore $store, Run $run): array
    {
        $live = $run->status === RunStatus::Running && Lock::isHeld($store->lockPath($run->id)) ? $store->live($run->id) : null;

        return [
            'working' => $live !== null,
            'note' => $live['note'] ?? '',
            'seconds' => $live !== null ? max(0, (int) floor(microtime(true) - $live['started'])) : 0,
            'elapsed' => max(0, time() - $run->created),
        ];
    }

    /** The bar, the line of the last finished step, the line of what is going on now and the place for an error. */
    public static function panel(Run $run, int $percent, bool $failed): string
    {
        $elapsed = max(0, time() - $run->created);

        return '<div class="bar"><div id="bar" data-percent="' . $percent . '"></div></div>'
            . '<p id="status">' . Html::e((string) $run->progress) . '</p>'
            . '<p id="note" class="help" aria-live="polite"></p>'
            . '<p id="clock" class="clock" data-elapsed="' . $elapsed . '">All together: ' . self::clock($elapsed) . '</p>'
            . '<p id="error" class="fail">' . ($failed ? Html::e((string) $run->error) : '') . '</p>';
    }

    /** The two buttons, to go inside the form of the page. */
    public static function buttons(bool $failed): string
    {
        return '<button name="action" value="tick" id="go"' . ($failed ? ' disabled' : '') . '>Continue</button>'
            . '<button name="action" value="retry" class="secondary" id="retry"' . ($failed ? '' : ' disabled') . '>Try the failed step again</button>';
    }

    /**
     * The script of the page: one step after the other, the clock, the note from the status address while a step works.
     *
     * @param string $noun      what is going on ("restore", "installation"), for the messages
     * @param string $hint403   what to do if the web server refuses the next step with HTTP 403 (a rule of the host, not of the tool)
     * @param string $statusUrl the address of the live status, answering JSON {working, note, seconds, elapsed}
     */
    public static function script(string $csrf, bool $failed, string $noun, string $hint403, string $statusUrl = '?step=status'): string
    {
        $token = json_encode($csrf, JSON_THROW_ON_ERROR);
        $failedJs = $failed ? 'true' : 'false';
        $nounJs = json_encode($noun, JSON_THROW_ON_ERROR);
        $hintJs = json_encode($hint403, JSON_THROW_ON_ERROR);
        $statusJs = json_encode($statusUrl, JSON_THROW_ON_ERROR);

        return <<<JS
(function () {
  var csrf = $token, noun = $nounJs, hint403 = $hintJs, statusUrl = $statusJs;
  var bar = document.getElementById('bar'), status = document.getElementById('status'), error = document.getElementById('error');
  var go = document.getElementById('go'), retry = document.getElementById('retry'), note = document.getElementById('note'), clock = document.getElementById('clock');
  var giveUp = document.getElementById('giveup'), cancelling = false;
  var stopped = $failedJs, lost = 0, working = false, stepStart = 0, pageStart = Date.now(), base = parseInt(clock.getAttribute('data-elapsed'), 10) || 0, poll = 0;
  bar.style.width = bar.getAttribute('data-percent') + '%';
  function two(n) { return (n < 10 ? '0' : '') + n; }
  function span(s) { s = Math.max(0, Math.floor(s)); return s >= 3600 ? Math.floor(s / 3600) + ':' + two(Math.floor(s / 60) % 60) + ':' + two(s % 60) : Math.floor(s / 60) + ':' + two(s % 60); }
  // what can be pressed: "Continue" when nothing works and the run has not failed, "Try the failed step again" when it has failed
  function buttons(failed) { go.disabled = cancelling || working || failed; retry.disabled = cancelling || !failed || working; }
  function tickClock() {
    var total = base + (Date.now() - pageStart) / 1000;
    clock.textContent = (working ? 'This step: ' + span((Date.now() - stepStart) / 1000) + ' \u00b7 ' : '') + 'All together: ' + span(total);
  }
  setInterval(tickClock, 1000);
  // while a step is working the page asks what it is doing: the step itself can take half a minute
  function watch() {
    fetch(statusUrl, {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}}).then(function (r) { return r.json(); }).then(function (d) {
      if (working && !cancelling && d.working && d.note) { note.textContent = d.note + '.'; }
    }).catch(function () {});
  }
  function begin() { working = true; stepStart = Date.now(); buttons(false); note.textContent = 'Working ...'; tickClock(); clearInterval(poll); poll = setInterval(watch, 2000); }
  function end(failed) { working = false; clearInterval(poll); note.textContent = ''; buttons(failed); tickClock(); }
  // the answer of a step is data; anything else (a page of the web server, HTTP 403, 500) is said out loud and stops the loop: it will not get better by itself
  function post(action) {
    return fetch('?', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'csrf=' + encodeURIComponent(csrf) + '&action=' + action + '&format=json'}).then(function (r) {
      var type = r.headers.get('Content-Type') || '';
      if (!r.ok || type.indexOf('json') === -1) { var e = new Error('HTTP ' + r.status); e.http = r.status; throw e; }
      return r.json();
    });
  }
  function refused(code) {
    stopped = true;
    end(false);
    status.textContent = 'The ' + noun + ' stopped: the web server answered HTTP ' + code + ' instead of the next step.';
    error.textContent = 'Nothing is lost: the state is saved and the ' + noun + ' goes on where it was. ' + (code === 403 ? hint403 : 'Look at the error log of the host. Then press "Continue".');
  }
  function show(d) {
    lost = 0;
    if (cancelling) { settle(); return; }
    if (d.percent !== undefined) { bar.style.width = d.percent + '%'; }
    if (d.progress) { status.textContent = d.progress; }
    if (d.status === 'finished') { window.location = '?step=done'; return; }
    if (d.status === 'failed') { error.textContent = d.error; stopped = true; end(true); return; }
    if (d.status === 'busy') { note.textContent = 'Another request is working on it; waiting ...'; setTimeout(tick, 2000); return; }
    setTimeout(tick, 50);
  }
  function tick() {
    if (stopped) { return; }
    begin();
    post('tick').then(show).catch(function (e) {
      if (cancelling) { settle(); return; }
      if (e && e.http) { refused(e.http); return; }
      lost += 1;
      end(false);
      note.textContent = 'No answer from the server (' + lost + '); trying again ...';
      setTimeout(tick, 3000);
    });
  }
  // giving up: no new step is started; the step that works now ends within its time budget, then the server can remove the run. The page says so and asks again until it can.
  function settle() { working = false; clearInterval(poll); buttons(false); tickClock(); }
  function giveUpNow() {
    post('abandon').then(function (d) {
      if (d.status === 'busy') { setTimeout(giveUpNow, 1500); return; }
      window.location = d.next;
    }).catch(function (e) {
      if (e && e.http) {
        cancelling = false; giveUp.disabled = false; note.textContent = ''; stopped = true; buttons(false);
        error.textContent = 'Giving up did not work: the web server answered HTTP ' + e.http + '. Nothing is lost; press "Continue" to go on, or try again.';
        return;
      }
      note.textContent = 'No answer from the server; trying again to give up ...';
      setTimeout(giveUpNow, 3000);
    });
  }
  if (giveUp) {
    giveUp.addEventListener('click', function (e) {
      e.preventDefault();
      if (cancelling) { return; }
      cancelling = true; stopped = true; giveUp.disabled = true; buttons(false); error.textContent = '';
      note.textContent = working ? 'Giving up ... waiting for the step that is working to end (up to half a minute), then the backup is removed.' : 'Giving up ...';
      giveUpNow();
    });
  }
  go.addEventListener('click', function (e) { e.preventDefault(); if (working) { return; } error.textContent = ''; stopped = false; lost = 0; tick(); });
  retry.addEventListener('click', function (e) { e.preventDefault(); if (working) { return; } retry.disabled = true; error.textContent = ''; stopped = false; post('retry').then(function () { tick(); }); });
  buttons(stopped);
  tickClock();
  if (!stopped) { tick(); }
})();
JS;
    }

    /** Seconds as `m:ss` (`h:mm:ss` from an hour on). */
    public static function clock(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    private function __construct()
    {
    }
}
