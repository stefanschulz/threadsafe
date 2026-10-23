// ThreadSafe web interface. One file, no framework, no inline code (the content security policy allows only this file).
// What it does: local time next to the UTC time, a confirmation before a form that asks for one, and the tick loop of a running
// backup (a page with a [data-tick-url] element asks for one tick after the other and shows how far the run is).
// Also: copy, save and print buttons for keys, a suggestion for a passphrase, and the two choices of the menu: folded to the icons or not,
// and the colour scheme (automatic, light, dark). Both are kept in cookies that the server reads, so that the next page is drawn right.
(function () {
  'use strict';

  // <time datetime="2026-10-21T17:55:00Z">...</time> shows the time of the browser, with the time as the server wrote it as the tooltip (UTC
  // if the datetime ends in Z); with the attribute data-minutes the seconds are left out
  document.querySelectorAll('time[datetime]').forEach(function (element) {
    var stamp = element.getAttribute('datetime');
    var date = new Date(stamp);
    if (!isNaN(date.getTime())) {
      element.title = element.textContent + (stamp.slice(-1) === 'Z' ? ' (UTC)' : '');
      element.textContent = element.hasAttribute('data-minutes')
        ? date.toLocaleString(undefined, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })
        : date.toLocaleString();
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

  // Keys and passphrases (docs/PHASE5.md 3.5). <button data-copy="#id"> copies the text of that element (or the value of that field),
  // <button data-save="#id" data-filename="..."> offers it as a file, <button data-print> prints the page (the print style shows only
  // the key), <button data-suggest="#field" data-alphabet="..."> writes a new passphrase of five groups of four characters into the
  // field. The randomness comes from the browser (crypto.getRandomValues); nothing is sent anywhere.
  var textOf = function (selector) {
    var element = document.querySelector(selector);
    if (!element) { return ''; }
    return typeof element.value === 'string' ? element.value : element.textContent;
  };
  var copyText = function (text, button) {
    var done = function (ok) {
      var old = button.getAttribute('data-label') || button.textContent;
      button.setAttribute('data-label', old);
      button.textContent = ok ? 'Copied' : 'Select it and copy by hand';
      window.setTimeout(function () { button.textContent = old; }, 2000);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
      return;
    }
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(area);
    done(ok);
  };
  var randomBelow = function (limit) {
    var range = Math.floor(0x100000000 / limit) * limit;
    var buffer = new Uint32Array(1);
    do { window.crypto.getRandomValues(buffer); } while (buffer[0] >= range);
    return buffer[0] % limit;
  };
  document.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest('button') : null;
    if (!button) { return; }
    if (button.hasAttribute('data-copy')) {
      event.preventDefault();
      copyText(textOf(button.getAttribute('data-copy')), button);
    } else if (button.hasAttribute('data-save')) {
      event.preventDefault();
      var blob = new Blob([textOf(button.getAttribute('data-save'))], { type: 'text/plain' });
      var link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = button.getAttribute('data-filename') || 'key.txt';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
    } else if (button.hasAttribute('data-print')) {
      event.preventDefault();
      window.print();
    } else if (button.hasAttribute('data-suggest')) {
      event.preventDefault();
      var alphabet = button.getAttribute('data-alphabet') || '';
      var field = document.querySelector(button.getAttribute('data-suggest'));
      if (!field || alphabet.length < 2 || !window.crypto || !window.crypto.getRandomValues) { return; }
      var groups = [];
      for (var g = 0; g < 5; g += 1) {
        var group = '';
        for (var i = 0; i < 4; i += 1) { group += alphabet.charAt(randomBelow(alphabet.length)); }
        groups.push(group);
      }
      field.value = groups.join('-');
      field.focus();
      field.select();
    }
  });

  // The menu: what the script offers is shown only when there is a script.
  var root = document.documentElement;
  root.classList.add('js');
  var side = document.querySelector('[data-cookie-path]');
  var cookiePath = side ? side.getAttribute('data-cookie-path') : '/';
  var keep = function (name, value) {
    // a year; no value removes the cookie (automatic, unfolded)
    document.cookie = name + '=' + (value || '') + '; path=' + cookiePath + '; max-age=' + (value ? 31536000 : 0) + '; samesite=lax' + (window.location.protocol === 'https:' ? '; secure' : '');
  };

  var toggle = document.querySelector('[data-nav-toggle]');
  if (toggle) {
    toggle.addEventListener('click', function () {
      var fold = root.getAttribute('data-nav') !== 'collapsed';
      if (fold) { root.setAttribute('data-nav', 'collapsed'); } else { root.removeAttribute('data-nav'); }
      toggle.setAttribute('aria-expanded', fold ? 'false' : 'true');
      toggle.title = fold ? 'Unfold the menu' : 'Fold the menu';
      var name = toggle.querySelector('.side-label');
      if (name) { name.textContent = toggle.title; }
      keep('threadsafe_nav', fold ? 'collapsed' : '');
    });
  }

  var schemes = document.querySelectorAll('[data-theme-set]');
  schemes.forEach(function (button) {
    button.addEventListener('click', function () {
      var mode = button.getAttribute('data-theme-set');
      if (mode === 'light' || mode === 'dark') { root.setAttribute('data-theme', mode); } else { root.removeAttribute('data-theme'); }
      keep('threadsafe_theme', mode === 'light' || mode === 'dark' ? mode : '');
      schemes.forEach(function (other) { other.setAttribute('aria-pressed', other === button ? 'true' : 'false'); });
    });
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
