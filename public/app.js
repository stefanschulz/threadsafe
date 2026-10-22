// ThreadSafe web interface. One file, no framework, no inline code (the content security policy allows only this file).
// What it does: local time next to the UTC time, a confirmation before a form that asks for one, and the tick loop of a running
// backup (a page with a [data-tick-url] element asks for one tick after the other and shows how far the run is).
// The download dialog is added here by a later work package.
(function () {
  'use strict';

  // <time datetime="2026-10-21T17:55:00Z">...</time> shows the time of the browser, with the UTC time as the tooltip
  document.querySelectorAll('time[datetime]').forEach(function (element) {
    var date = new Date(element.getAttribute('datetime'));
    if (!isNaN(date.getTime())) {
      element.title = element.textContent + ' (UTC)';
      element.textContent = date.toLocaleString();
    }
  });

  // The page of a running backup: every request does one tick on the server (about 60 % of its time limit). The server decides
  // when the run is done; a closed tab only stops the asking, and the run can be continued from its saved state.
  var run = document.getElementById('run');
  if (run) {
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var bar = document.getElementById('run-progress');
    var label = document.getElementById('run-label');
    var note = document.getElementById('run-note');
    var actions = document.getElementById('run-actions');
    var failures = 0;

    // with script the "next step" button is not needed
    var next = actions ? actions.querySelector('form') : null;
    if (next) { next.hidden = true; }

    var show = function (text) { if (note) { note.textContent = text; } };
    var again = function (milliseconds) { window.setTimeout(tick, milliseconds); };

    var tick = function () {
      fetch(run.getAttribute('data-tick-url'), { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': token } })
        .then(function (response) {
          return response.json().then(function (data) { return { http: response.status, data: data }; });
        })
        .then(function (answer) {
          var data = answer.data;
          failures = 0;
          if (data.status === 'busy') { show(data.label || 'Waiting ...'); again(1500); return; }
          if (data.status === 'running') {
            show('');
            if (label && data.label) { label.textContent = data.label; }
            if (bar) {
              if (typeof data.percent === 'number') { bar.value = data.percent; bar.textContent = data.percent + ' %'; } else { bar.removeAttribute('value'); }
            }
            again(100);
            return;
          }
          if (data.status === 'login') { show('The session has ended. Log in again; the backup stays where it is and can be continued.'); return; }
          // finished, failed or gone: the page shows the result
          window.location.reload();
        })
        .catch(function () {
          failures += 1;
          if (failures > 20) { show('The connection is lost. Reload the page to go on.'); return; }
          show('The connection was lost; trying again ...');
          again(Math.min(15000, 1000 * failures));
        });
    };
    again(200);
  }

  // The System page: is the data directory served by the web server? <li data-probe="/x/data/probe.txt" data-expect="..."> asks the
  // browser for the file; the content coming back means that anyone could fetch it, a refusal or a 404 is what is wanted.
  document.querySelectorAll('[data-probe]').forEach(function (item) {
    var result = item.querySelector('.result');
    var say = function (text, kind) { if (result) { result.textContent = text; result.className = 'result ' + kind; } };
    fetch(item.getAttribute('data-probe'), { cache: 'no-store', credentials: 'omit' })
      .then(function (response) {
        if (!response.ok) { say('not reachable (HTTP ' + response.status + ')', 'ok'); return null; }
        return response.text();
      })
      .then(function (text) {
        if (text === null) { return; }
        if (text.indexOf(item.getAttribute('data-expect')) !== -1) { say('REACHABLE FROM OUTSIDE: anyone can fetch the files in the data directory. Close it at once.', 'bad'); } else { say('answers, but not with the probe file (nothing of the data directory is served here)', 'ok'); }
      })
      .catch(function () { say('could not be tested', 'warn'); });
  });

  // <form data-confirm="Delete this backup?"> asks first
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var question = form.getAttribute && form.getAttribute('data-confirm');
    if (question && !window.confirm(question)) {
      event.preventDefault();
    }
  });
})();
