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

  /* ---- live fleet view: numbers and states refresh while the tab is visible ---- */
  function relative(seconds) {
    return seconds < 90 ? Math.max(0, seconds) + ' seconds ago' : Math.round(seconds / 60) + ' minutes ago';
  }
  var STATE = {
    online: ['ok', 'Online', 'var(--success-dot)'], degraded: ['warn', 'Containers need attention', 'var(--warning-dot)'],
    offline: ['bad', 'Unreachable', 'var(--danger-dot)'], unknown: ['', 'Not probed', 'var(--subtle)']
  };
  function field(node, name) {
    return all('[data-live-node]').filter(function (e) { return e.getAttribute('data-live-node') === node && e.getAttribute('data-live-field') === name; });
  }
  function paintGauge(el, value) {
    if (value === null || value === undefined) { return; }
    var meter = one('meter', el), num = one('.gauge-n', el);
    if (meter) { meter.value = value; }
    if (num) { num.textContent = value + '%'; }
  }
  function initLive() {
    var status = one('[data-live-status]');
    var banner = one('[data-testid=running-banner]');
    var lastUpdate = status && Number(status.getAttribute('data-updated')) || 0;
    var interval = status ? Number(status.getAttribute('data-interval')) : 0;
    function paint(data) {
      Object.keys(data.nodes).forEach(function (name) {
        var n = data.nodes[name];
        var st = STATE[n.state] || STATE.unknown;
        field(name, 'state').forEach(function (el) {
          var word = one('.state', el);
          if (word) { word.className = 'state ' + st[0]; word.textContent = st[1]; }
        });
        field(name, 'dot').forEach(function (el) { el.setAttribute('fill', st[2]); });
        field(name, 'memory').forEach(function (el) { paintGauge(el, n.memory); });
        field(name, 'disk').forEach(function (el) { paintGauge(el, n.disk); });
      });
      if (data.updated) { lastUpdate = data.updated; }
    }
    function tick() {
      if (document.hidden) { return; }
      fetch('/api/live', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (data) {
        paint(data);
        if (data.job && !banner && !one('.job[data-job]') && !one('[data-testid=deploy-running]')) { window.location.reload(); }
        if (!data.job && banner) { window.location.reload(); }
      }).catch(function () { /* the next tick tries again */ });
    }
    setInterval(function () {
      if (status && lastUpdate) {
        var text = 'Probed ' + relative(Math.round(Date.now() / 1000 - lastUpdate)) + (interval ? ', every ' + interval + ' seconds.' : '.');
        status.textContent = text;
      }
    }, 1000);
    setInterval(tick, 10000);
    document.addEventListener('visibilitychange', tick);
  }

  /* ---- container logs: poll the tail while the box is ticked ---- */
  function initLogs(root) {
    var log = one('#job-log', root);
    var poll = one('[data-log-poll]', root);
    var filter = one('[data-log-filter]', root);
    var text = log.textContent;
    function paint() {
      var needle = filter && filter.value.trim().toLowerCase();
      var lines = text.split('\n');
      var shown = needle ? lines.filter(function (l) { return l.toLowerCase().indexOf(needle) !== -1; }) : lines;
      log.textContent = shown.join('\n') || 'No log line matches the filter.';
      log.scrollTop = log.scrollHeight;
    }
    if (filter) { filter.addEventListener('input', paint); }
    setInterval(function () {
      if (!poll.checked || document.hidden) { return; }
      fetch(root.getAttribute('data-logs-url'), { cache: 'no-store' }).then(function (r) { return r.text(); }).then(function (t) { text = t; paint(); });
    }, 5000);
    paint();
  }

  var logs = one('.job[data-logs-url]');
  if (logs) { initLogs(logs); }
  if (one('[data-live-node]')) { initLive(); }

  var job = one('.job[data-job]');
  if (job) {
    if (job.getAttribute('data-state') === 'running') { initJob(job); } else { initStaticLog(job); }
  }
})();
