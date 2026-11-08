/* Fleet web behaviour: the live action page and the live fleet view. Every page still works without this file. */
(function () {
  'use strict';

  var WORDS = { pending: 'Waiting', running: 'Running', ok: 'Done', failed: 'Failed', skipped: 'Already done' };

  function one(selector, root) { return (root || document).querySelector(selector); }
  function all(selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }
  function make(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined) { node.textContent = text; }
    return node;
  }
  function seconds(value) {
    value = Math.max(0, Math.round(value));
    return value < 60 ? value + 's' : Math.floor(value / 60) + 'm ' + (value % 60) + 's';
  }

  /* ---- action page: tasks tick off as the stream reports them, the log follows the end ---- */
  function initJob(root) {
    var jobId = root.getAttribute('data-job');
    var log = one('[data-log]', root) || one('#job-log', root);
    var filter = one('[data-log-filter]', root);
    var follow = one('[data-log-follow]', root);
    var meter = one('progress', root);
    var count = one('.job-count', root);
    var badge = one('.job-badge', root);
    var clock = one('.job-time', root);
    var title = root.getAttribute('data-title') || document.title;
    var lines = log.textContent === 'Waiting for the first step...' ? [] : log.textContent.split('\n');
    var total = all('[data-step]', root).length;

    function paintLog() {
      var needle = filter && filter.value.trim().toLowerCase();
      var shown = needle ? lines.filter(function (l) { return l.toLowerCase().indexOf(needle) !== -1; }) : lines;
      log.textContent = shown.length ? shown.join('\n') : (lines.length ? 'No log line matches the filter.' : 'Waiting for the first step...');
      if (follow && follow.checked) { log.scrollTop = log.scrollHeight; }
    }
    function counts() {
      var done = all('[data-step]', root).filter(function (li) { var s = li.getAttribute('data-state'); return s === 'ok' || s === 'skipped' || s === 'failed'; }).length;
      total = all('[data-step]', root).length;
      if (meter && total) { meter.max = total; meter.value = done; }
      if (count && total) { count.textContent = done + ' of ' + total + ' steps'; }
      return done;
    }
    function addSteps(steps) {
      var wrap = one('.tasks-wrap', root);
      if (!wrap) { wrap = make('div', 'tasks-wrap'); wrap.setAttribute('data-testid', 'job-tasks'); root.insertBefore(wrap, one('.log-tools', root)); }
      steps.forEach(function (st) {
        var name = st.node ? st.label.split(': ').slice(1).join(': ') : st.label;
        var node = st.node || 'Steps';
        var group = all('.task-group', wrap).filter(function (g) { return g.getAttribute('data-node') === node; })[0];
        if (!group) {
          group = make('section', 'task-group');
          group.setAttribute('data-node', node);
          group.appendChild(make('h3', '', node));
          group.appendChild(make('ol', 'tasks'));
          wrap.appendChild(group);
        }
        var li = make('li');
        li.setAttribute('data-step', st.id);
        li.setAttribute('data-state', 'pending');
        var mark = make('span', 'mark'); mark.setAttribute('aria-hidden', 'true');
        li.appendChild(mark);
        li.appendChild(make('span', 'task-name', name));
        li.appendChild(make('span', 'sr', WORDS.pending));
        li.appendChild(make('span', 'took', ''));
        one('.tasks', group).appendChild(li);
      });
      if (meter && !meter.hasAttribute('max')) { meter.setAttribute('value', '0'); }
    }
    function setStep(event) {
      var li = all('[data-step]', root).filter(function (n) { return n.getAttribute('data-step') === event.id; })[0];
      if (!li) { return; }
      li.setAttribute('data-state', event.state);
      one('.sr', li).textContent = WORDS[event.state];
      one('.took', li).textContent = event.seconds ? event.seconds + 's' : '';
      if (event.state === 'running') { li.scrollIntoView({ block: 'nearest' }); }
    }

    var started = Number(clock.getAttribute('data-since')) * 1000;
    var timer = setInterval(function () {
      if (root.getAttribute('data-state') === 'running') { clock.textContent = seconds((Date.now() - started) / 1000); }
    }, 1000);

    function finished(event) {
      clearInterval(timer);
      root.setAttribute('data-state', event.state);
      document.title = (event.state === 'ok' ? 'Finished: ' : 'Failed: ') + title;
      // The server page carries the final result, the closing log, and the history link.
      setTimeout(function () { window.location.reload(); }, 500);
    }

    var source = new EventSource('/jobs/' + encodeURIComponent(jobId) + '/events?after=' + root.getAttribute('data-after'));
    source.onmessage = function (message) {
      var event = JSON.parse(message.data);
      if (event.type === 'plan') { addSteps(event.steps); counts(); }
      else if (event.type === 'step') { setStep(event); var done = counts(); document.title = done + '/' + total + ' ' + title; }
      else if (event.type === 'line') { lines.push(event.text); paintLog(); }
      else if (event.type === 'done') { source.close(); finished(event); }
    };
    source.onerror = function () {
      // The stream ended or dropped: the server page shows where the action stands.
      source.close();
      setTimeout(function () { window.location.reload(); }, 2000);
    };
    if (filter) { filter.addEventListener('input', paintLog); }
    paintLog();
  }

  /* ---- filter on a finished action page ---- */
  function initStaticLog(root) {
    var log = one('#job-log', root);
    var filter = one('[data-log-filter]', root);
    var lines = log.textContent.split('\n');
    if (filter) {
      filter.addEventListener('input', function () {
        var needle = filter.value.trim().toLowerCase();
        var shown = needle ? lines.filter(function (l) { return l.toLowerCase().indexOf(needle) !== -1; }) : lines;
        log.textContent = shown.length ? shown.join('\n') : 'No log line matches the filter.';
      });
    }
    var follow = one('[data-log-follow]', root);
    if (follow) { follow.checked = false; }
  }

  var job = one('.job[data-job]');
  if (job) {
    if (job.getAttribute('data-state') === 'running') { initJob(job); } else { initStaticLog(job); }
  }
})();
