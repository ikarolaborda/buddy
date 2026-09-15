/* Buddy single-task dashboard. Plain JavaScript, no external assets, no inline handlers (CSP: default-src 'self'). */
(function () {
  "use strict";

  var PHASES = ["queued", "memory", "evaluation", "council.frame", "council.positions", "council.attacks", "council.verdict"];
  var PHASE_LABELS = {
    "queued": "Queued",
    "memory": "Memory",
    "evaluation": "Evaluation",
    "council.frame": "Council: frame",
    "council.positions": "Council: positions",
    "council.attacks": "Council: attacks",
    "council.verdict": "Council: verdict"
  };
  var DELAY_AFTER_MS = 2 * 60 * 1000;
  var MAX_SOCKET_FAILURES = 3;
  var MAX_EVENTS_SHOWN = 50;

  var state = {
    taskId: null,
    progress: null,
    events: [],
    lastSequence: 0,
    observedAt: null,
    lastEventAt: null,
    roster: null,
    socket: null,
    socketFailures: 0,
    reconnectTimer: null,
    pollTimer: null,
    mode: "connecting",
    phaseStarts: {},
    terminal: false
  };

  var el = {};
  [
    "status-text", "task-id", "task-status", "task-phase", "queue-wait", "model-time", "observed-at", "last-event-at",
    "connection-mode", "delay-notice", "recovery-notice", "polling-notice", "timeline", "timeline-empty",
    "council-table", "council-body", "council-empty", "events", "events-empty"
  ].forEach(function (id) { el[id] = document.getElementById(id); });

  function setText(id, value) { el[id].textContent = value; }
  function show(id, visible) { el[id].hidden = !visible; }

  function fmtTime(iso) {
    if (!iso) return "—";
    var d = new Date(iso);
    if (isNaN(d.getTime())) return "—";
    return d.toLocaleString();
  }

  function fmtDuration(ms) {
    if (ms === null || ms === undefined || isNaN(ms) || ms < 0) return "—";
    var s = Math.floor(ms / 1000);
    if (s < 60) return s + " s";
    var m = Math.floor(s / 60);
    var rs = s % 60;
    if (m < 60) return m + " min " + rs + " s";
    var h = Math.floor(m / 60);
    return h + " h " + (m % 60) + " min";
  }

  function parseTime(iso) {
    if (!iso) return null;
    var t = Date.parse(iso);
    return isNaN(t) ? null : t;
  }

  function readTicketFromFragment() {
    var hash = window.location.hash || "";
    if (hash.length < 2) return null;
    var raw = hash.slice(1);
    var ticket = raw.indexOf("ticket=") === 0 ? raw.slice("ticket=".length) : raw;
    // Clear the fragment immediately so the ticket never survives in history or referrers.
    history.replaceState(null, "", window.location.pathname + window.location.search);
    return ticket ? decodeURIComponent(ticket) : null;
  }

  function readTaskFromQuery() {
    var params = new URLSearchParams(window.location.search);
    return params.get("task");
  }

  function announce(text) {
    setText("status-text", text);
  }

  function apiFetch(path, options) {
    options = options || {};
    options.credentials = "same-origin";
    options.headers = options.headers || {};
    if (options.body) options.headers["Content-Type"] = "application/json";
    return fetch(path, options).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
        return { ok: res.ok, status: res.status, data: data };
      });
    });
  }

  // ------------------------------------------------------------------ rendering

  function statusLabel(progress) {
    if (!progress) return "Unknown";
    var s = progress.status || "unknown";
    var labels = { queued: "Queued", evaluating: "Evaluating", completed: "Completed", failed: "Failed", closed: "Closed", unknown: "Unknown" };
    return labels[s] || s;
  }

  function render() {
    var p = state.progress;
    setText("task-id", state.taskId || "—");
    setText("task-status", statusLabel(p));
    setText("task-phase", p && p.phase ? (PHASE_LABELS[p.phase] || p.phase) : "—");
    setText("observed-at", fmtTime(state.observedAt));
    setText("last-event-at", fmtTime(state.lastEventAt));
    setText("connection-mode", describeMode());

    var queuedAt = p ? parseTime(p.queued_at) : null;
    var workerAt = p ? parseTime(p.worker_started_at) : null;
    var now = Date.now();
    if (queuedAt !== null && workerAt !== null) setText("queue-wait", fmtDuration(workerAt - queuedAt));
    else if (queuedAt !== null) setText("queue-wait", fmtDuration(now - queuedAt) + " so far");
    else setText("queue-wait", "—");

    if (workerAt !== null) {
      var end = state.terminal && state.lastEventAt ? parseTime(state.lastEventAt) : now;
      setText("model-time", fmtDuration((end || now) - workerAt));
    } else {
      setText("model-time", "—");
    }

    var heartbeat = p ? (parseTime(p.heartbeat_at) || parseTime(p.phase_started_at) || workerAt) : null;
    var active = p && p.status === "evaluating";
    show("delay-notice", !!(active && heartbeat !== null && now - heartbeat > DELAY_AFTER_MS));

    if (p && p.recovery_task_id) {
      el["recovery-notice"].textContent = "A recovery task was linked: " + p.recovery_task_id + ".";
      show("recovery-notice", true);
    } else {
      show("recovery-notice", false);
    }

    renderTimeline();
    renderCouncil();
    renderEvents();
    renderStatusText();
  }

  function describeMode() {
    switch (state.mode) {
      case "live": return "Live (WebSocket)";
      case "polling": return "Periodic refresh";
      case "reconnecting": return "Reconnecting…";
      case "closed": return "Closed (task finished)";
      default: return "Connecting…";
    }
  }

  function renderStatusText() {
    var p = state.progress;
    if (!p) { announce("Waiting for the first authoritative snapshot."); return; }
    var label = statusLabel(p);
    if (p.status === "completed") announce("Task completed.");
    else if (p.status === "failed") announce("Task failed" + (p.failure_category ? " (" + p.failure_category + ")" : "") + ".");
    else if (p.status === "closed") announce("Task closed.");
    else if (p.phase) announce(label + ": " + (PHASE_LABELS[p.phase] || p.phase) + ".");
    else announce(label + ".");
  }

  function renderTimeline() {
    var list = el["timeline"];
    while (list.firstChild) list.removeChild(list.firstChild);
    var current = state.progress && state.progress.phase ? state.progress.phase : null;
    var currentIndex = current ? PHASES.indexOf(current) : -1;
    var any = false;
    PHASES.forEach(function (phase, index) {
      var started = state.phaseStarts[phase];
      var cls = "pending";
      var marker = "○";
      if (state.terminal && started) { cls = "done"; marker = "✓"; }
      else if (index < currentIndex || (started && index !== currentIndex)) { cls = "done"; marker = "✓"; }
      else if (index === currentIndex) { cls = "current"; marker = "●"; }
      if (cls !== "pending") any = true;
      var li = document.createElement("li");
      li.className = cls;
      li.tabIndex = 0;
      li.setAttribute("aria-label", PHASE_LABELS[phase] + ": " + (cls === "done" ? "done" : cls === "current" ? "in progress" : "not started"));
      var m = document.createElement("span"); m.className = "marker"; m.textContent = marker;
      var t = document.createElement("span"); t.textContent = PHASE_LABELS[phase];
      var w = document.createElement("span"); w.className = "when"; w.textContent = started ? fmtTime(started) : "";
      li.appendChild(m); li.appendChild(t); li.appendChild(w);
      list.appendChild(li);
    });
    show("timeline-empty", !any);
  }

  function renderCouncil() {
    var body = el["council-body"];
    while (body.firstChild) body.removeChild(body.firstChild);
    var roster = state.roster;
    if (!roster || !roster.length) { show("council-table", false); show("council-empty", true); return; }
    roster.forEach(function (seat) {
      var tr = document.createElement("tr");
      var role = document.createElement("th"); role.scope = "row"; role.textContent = seat.role || "—";
      var model = document.createElement("td"); model.textContent = seat.model || "—";
      tr.appendChild(role); tr.appendChild(model);
      body.appendChild(tr);
    });
    show("council-table", true);
    show("council-empty", false);
  }

  function describeEvent(ev) {
    var d = ev.data || {};
    switch (ev.type) {
      case "buddy.task.progress.v1": return "Phase " + (PHASE_LABELS[d.phase] || d.phase || "update") + (d.status ? " (" + d.status + ")" : "");
      case "buddy.task.terminal.v1": return "Task " + (d.status || "ended") + (d.failure_category ? " (" + d.failure_category + ")" : "");
      case "buddy.task.recovery.v1": return "Recovery task linked: " + (d.recovery_task_id || "—");
      case "buddy.task.artifact.available.v1": return "Artifact available" + (d.artifact_id ? ": " + d.artifact_id : "");
      case "buddy.task.artifact.processed.v1": return "Artifact processed" + (d.artifact_id ? ": " + d.artifact_id : "");
      case "buddy.task.export.completed.v1": return "Export completed";
      default: return ev.type;
    }
  }

  function renderEvents() {
    var list = el["events"];
    while (list.firstChild) list.removeChild(list.firstChild);
    var shown = state.events.slice(-MAX_EVENTS_SHOWN).reverse();
    shown.forEach(function (ev) {
      var li = document.createElement("li");
      li.tabIndex = 0;
      var when = document.createElement("span"); when.className = "when"; when.textContent = fmtTime(ev.occurred_at);
      var text = document.createElement("span"); text.textContent = "#" + ev.task_sequence + " " + describeEvent(ev);
      li.appendChild(when); li.appendChild(text);
      list.appendChild(li);
    });
    show("events-empty", shown.length === 0);
  }

  // ------------------------------------------------------------------ state updates

  function extractRoster(data) {
    var roster = data.roster || data.models || data.seats;
    if (!Array.isArray(roster)) return null;
    var out = [];
    roster.forEach(function (seat) {
      if (seat && typeof seat === "object" && (seat.model || seat.role)) out.push({ role: String(seat.role || ""), model: String(seat.model || seat.deployment || "") });
    });
    return out.length ? out : null;
  }

  function applyEvent(ev) {
    if (!ev || typeof ev.task_sequence !== "number") return false;
    if (ev.task_sequence <= state.lastSequence) return false;
    state.lastSequence = ev.task_sequence;
    state.events.push(ev);
    if (state.events.length > 200) state.events = state.events.slice(-200);
    state.lastEventAt = ev.occurred_at || new Date().toISOString();
    var d = ev.data || {};
    state.progress = state.progress || { status: "evaluating" };
    if (ev.type === "buddy.task.progress.v1") {
      if (d.phase) {
        state.progress.phase = d.phase;
        if (!state.phaseStarts[d.phase]) state.phaseStarts[d.phase] = d.phase_started_at || ev.occurred_at;
      }
      ["status", "phase_started_at", "phase_deadline_at", "queued_at", "worker_started_at", "heartbeat_at"].forEach(function (k) {
        if (typeof d[k] === "string") state.progress[k] = d[k];
      });
      if (!d.status) state.progress.status = "evaluating";
      var roster = extractRoster(d);
      if (roster) state.roster = roster;
    } else if (ev.type === "buddy.task.terminal.v1") {
      state.progress.status = d.status || state.progress.status;
      if (d.failure_category) state.progress.failure_category = d.failure_category;
      state.terminal = true;
    } else if (ev.type === "buddy.task.recovery.v1") {
      state.progress.recovery_task_id = d.recovery_task_id;
    }
    return true;
  }

  function applySnapshot(body) {
    if (!body) return;
    if (body.progress) {
      state.progress = body.progress;
      if (body.progress.phase && !state.phaseStarts[body.progress.phase]) {
        state.phaseStarts[body.progress.phase] = body.progress.phase_started_at || null;
      }
      if (body.progress.status === "completed" || body.progress.status === "failed" || body.progress.status === "closed") state.terminal = true;
      if (typeof body.progress.progress_sequence === "number" && body.progress.progress_sequence > state.lastSequence && (!body.events || !body.events.length)) {
        state.lastSequence = body.progress.progress_sequence;
      }
    }
    state.observedAt = body.observed_at || new Date().toISOString();
    (body.events || []).slice().sort(function (a, b) { return a.task_sequence - b.task_sequence; }).forEach(applyEvent);
    render();
  }

  // ------------------------------------------------------------------ transport

  function fetchSnapshot() {
    if (!state.taskId) return Promise.resolve();
    return apiFetch("/tasks/" + encodeURIComponent(state.taskId) + "?after=" + state.lastSequence).then(function (res) {
      if (res.status === 401) { stopEverything(); announce("Your view session expired. Ask for a new link to continue."); return; }
      if (!res.ok) { announce("Could not refresh the task. Will retry."); return; }
      applySnapshot(res.data);
    }).catch(function () { announce("Could not reach the server. Will retry."); });
  }

  function connectSocket() {
    if (state.terminal || !state.taskId) return;
    state.mode = state.socketFailures ? "reconnecting" : "connecting";
    render();
    apiFetch("/tasks/" + encodeURIComponent(state.taskId) + "/stream-tickets", { method: "POST", body: "{}" }).then(function (res) {
      if (res.status === 401) { stopEverything(); announce("Your view session expired. Ask for a new link to continue."); return; }
      if (!res.ok || !res.data || !res.data.ticket) { onSocketFailure(); return; }
      var proto = window.location.protocol === "https:" ? "wss://" : "ws://";
      var url = proto + window.location.host + "/tasks/" + encodeURIComponent(state.taskId) + "/events?ticket=" + encodeURIComponent(res.data.ticket);
      var ws;
      try { ws = new WebSocket(url); } catch (e) { onSocketFailure(); return; }
      state.socket = ws;
      ws.addEventListener("open", function () {
        state.socketFailures = 0;
        state.mode = "live";
        stopPolling();
        ws.send(JSON.stringify({ type: "resume", after_sequence: state.lastSequence }));
        render();
      });
      ws.addEventListener("message", function (msg) {
        var data;
        try { data = JSON.parse(msg.data); } catch (e) { return; }
        handleSocketMessage(data);
      });
      ws.addEventListener("close", function (evt) {
        state.socket = null;
        if (evt.code === 4001) { state.mode = "closed"; render(); fetchSnapshot(); return; }
        onSocketFailure();
      });
      ws.addEventListener("error", function () { /* close follows */ });
    }).catch(onSocketFailure);
  }

  function handleSocketMessage(data) {
    if (!data || typeof data !== "object") return;
    switch (data.type) {
      case "hello":
        if (data.progress && !state.progress) { state.progress = data.progress; }
        if (data.gap) fetchSnapshot();
        render();
        break;
      case "resume_ok":
        (data.events || []).forEach(applyEvent);
        render();
        break;
      case "event":
        if (applyEvent(data.envelope)) render();
        break;
      case "snapshot":
        state.observedAt = new Date().toISOString();
        if (data.progress) state.progress = data.progress;
        render();
        break;
      case "snapshot_required":
        fetchSnapshot();
        break;
      case "ping":
        if (state.socket && state.socket.readyState === 1) state.socket.send(JSON.stringify({ type: "pong" }));
        break;
      default:
        break;
    }
  }

  function onSocketFailure() {
    state.socket = null;
    state.socketFailures += 1;
    if (state.terminal) { state.mode = "closed"; render(); return; }
    if (state.socketFailures >= MAX_SOCKET_FAILURES) {
      startPolling();
      state.mode = "polling";
      render();
      // Keep trying the live path in the background at a slow cadence.
      scheduleReconnect(60000);
      return;
    }
    scheduleReconnect(Math.min(30000, 1000 * Math.pow(2, state.socketFailures)));
  }

  function scheduleReconnect(delay) {
    if (state.reconnectTimer) clearTimeout(state.reconnectTimer);
    state.reconnectTimer = setTimeout(function () { state.reconnectTimer = null; connectSocket(); }, delay);
  }

  function startPolling() {
    if (state.pollTimer) return;
    show("polling-notice", true);
    var tick = function () {
      fetchSnapshot().then(function () {
        if (state.terminal) { stopPolling(); state.mode = "closed"; render(); return; }
        var interval = state.progress && state.progress.next_poll_after_ms ? state.progress.next_poll_after_ms : 15000;
        state.pollTimer = setTimeout(tick, Math.max(3000, Math.min(120000, interval)));
      });
    };
    state.pollTimer = setTimeout(tick, 0);
  }

  function stopPolling() {
    if (state.pollTimer) { clearTimeout(state.pollTimer); state.pollTimer = null; }
    show("polling-notice", false);
  }

  function stopEverything() {
    stopPolling();
    if (state.reconnectTimer) { clearTimeout(state.reconnectTimer); state.reconnectTimer = null; }
    if (state.socket) { try { state.socket.close(1000, "session_expired"); } catch (e) { /* ignore */ } state.socket = null; }
    state.mode = "closed";
    render();
  }

  // ------------------------------------------------------------------ boot

  function boot() {
    var ticket = readTicketFromFragment();
    var taskFromQuery = readTaskFromQuery();
    render();
    var start = function () {
      history.replaceState(null, "", window.location.pathname + "?task=" + encodeURIComponent(state.taskId));
      fetchSnapshot().then(function () { connectSocket(); });
    };
    if (ticket) {
      announce("Opening your task view…");
      apiFetch("/session/exchange", { method: "POST", body: JSON.stringify({ ticket: ticket }) }).then(function (res) {
        if (res.status === 410) { announce("This link has already been used or has expired. Ask for a new link."); return; }
        if (!res.ok || !res.data || !res.data.task_id) { announce("Could not open the task view. Ask for a new link."); return; }
        state.taskId = res.data.task_id;
        start();
      }).catch(function () { announce("Could not reach the server to open the task view."); });
    } else if (taskFromQuery) {
      state.taskId = taskFromQuery;
      start();
    } else {
      announce("No task link provided. Open the link you received to view a task.");
    }
  }

  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible" && state.taskId && !state.terminal) fetchSnapshot();
  });

  window.addEventListener("beforeunload", function () {
    if (state.socket) { try { state.socket.close(1000, "navigation"); } catch (e) { /* ignore */ } }
  });

  boot();
})();
