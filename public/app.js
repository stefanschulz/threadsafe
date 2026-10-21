// ThreadSafe web interface. One file, no framework, no inline code (the content security policy allows only this file).
// What it does so far: local time next to the UTC time, and a confirmation before a form that asks for one.
// Later work packages add the tick loop of a running backup and the download dialog here.
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

  // <form data-confirm="Delete this backup?"> asks first
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var question = form.getAttribute && form.getAttribute('data-confirm');
    if (question && !window.confirm(question)) {
      event.preventDefault();
    }
  });
})();
