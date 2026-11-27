(function () {
  'use strict';
  const config = window.darkScrubProjectNotifications;
  const list = document.getElementById('projectNotificationItems');
  const badge = document.getElementById('projectNotificationBadge');
  const error = document.getElementById('projectNotificationError');
  if (!config || !config.userId || !list || !badge) return;
  const labels = { task_ready: 'Parent complete — you can start', task_review: 'Completion awaiting your review',
    task_done: 'Task completed', task_rejected: 'Returned for changes' };
  let busy = false;
  let lastSignature = '';
  let lastData = null;
  let preferences = { toast_mode: 'persistent', revision: -1 };
  const timers = new Map();
  const preferencesKey = 'darkscrub.project-preferences.' + config.userId;
  function isOwnerNotice(kind) { return kind === 'task_review' || kind === 'task_done'; }
  const shown = new Set();
  let dismissed = new Set();
  const storageKey = 'darkscrub.project-notices.' + config.userId;
  try { dismissed = new Set(JSON.parse(sessionStorage.getItem(storageKey) || '[]')); } catch (_) { /* Storage is optional. */ }
  const toastHost = document.createElement('div');
  toastHost.id = 'projectNotificationToasts';
  toastHost.setAttribute('aria-live', 'polite');
  Object.assign(toastHost.style, { position: 'fixed', right: '16px', bottom: '16px', zIndex: '1060', width: '350px', maxWidth: '92vw' });
  document.body.appendChild(toastHost);
  function element(tag, text, className) {
    const el = document.createElement(tag);
    if (text) el.textContent = text;
    if (className) el.className = className;
    return el;
  }
  function removeToast(wrapper) {
    const id = wrapper.dataset.notificationId;
    clearTimeout(timers.get(id));
    timers.delete(id);
    wrapper.remove();
  }
  function dismissToast(wrapper) {
    dismissed.add(wrapper.dataset.notificationId);
    try { sessionStorage.setItem(storageKey, JSON.stringify([...dismissed].slice(-300))); } catch (_) { /* Optional. */ }
    removeToast(wrapper);
  }
  function startTimer(wrapper) {
    const id = wrapper.dataset.notificationId;
    clearTimeout(timers.get(id));
    timers.delete(id);
    if (isOwnerNotice(wrapper.dataset.kind) && preferences.toast_mode === 'brief') {
      timers.set(id, setTimeout(() => dismissToast(wrapper), 10000));
    }
  }
  function applyPreferences(next, broadcast = false) {
    if (!next || !['persistent', 'brief', 'badge'].includes(next.toast_mode)
        || !Number.isSafeInteger(next.revision) || next.revision < preferences.revision) return;
    if (next.toast_mode === preferences.toast_mode && next.revision === preferences.revision) return;
    const modeChanged = preferences.toast_mode !== next.toast_mode;
    preferences = { toast_mode: next.toast_mode, revision: next.revision };
    if (modeChanged) {
      toastHost.querySelectorAll('[data-notification-id]').forEach(wrapper => {
        if (!isOwnerNotice(wrapper.dataset.kind)) return;
        if (preferences.toast_mode === 'badge') {
          shown.delete(wrapper.dataset.notificationId);
          removeToast(wrapper);
        } else startTimer(wrapper);
      });
      if (lastData) renderToasts(lastData.items || []);
    }
    if (broadcast) {
      try { localStorage.setItem(preferencesKey, JSON.stringify(preferences)); } catch (_) { /* Polling also synchronizes preferences. */ }
    }
    document.dispatchEvent(new CustomEvent('darkscrub:project-preferences', { detail: { ...preferences } }));
  }
  async function savePreferences(mode) {
    const response = await fetch(config.endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
      body: new URLSearchParams({ action: 'save_preferences', toast_mode: mode, csrf_token: config.csrfToken }) });
    const data = await response.json();
    if (!response.ok || data.error || !data.preferences) throw new Error(data.error || 'Nastavenia sa nepodarilo uložiť.');
    applyPreferences(data.preferences, true);
    return { ...preferences };
  }
  function taskUrl(item) { return 'index.php?page=projects&view=' + Number(item.project_id) + '#project-task-' + Number(item.task_id); }
  function button(label, action) {
    const el = element('button', label, 'btn btn-xs btn-outline-info mr-1 mt-1');
    el.type = 'button';
    el.addEventListener('click', async event => {
      event.preventDefault(); event.stopPropagation();
      if (busy) return;
      el.disabled = true;
      await update(action);
      el.disabled = false;
    });
    return el;
  }
  function card(item, toast) {
    const wrapper = element('div', '', toast ? 'card bg-dark shadow mb-2 p-3' : 'px-3 py-2 border-bottom');
    wrapper.dataset.notificationId = item.id;
    wrapper.dataset.kind = item.kind;
    wrapper.appendChild(element('small', labels[item.kind] || 'Project update', 'text-warning d-block'));
    const link = element('a', item.task_title, 'd-block font-weight-bold');
    link.href = taskUrl(item);
    wrapper.appendChild(link);
    wrapper.appendChild(element('small', item.project_title, 'text-muted d-block'));
    if (item.kind === 'task_review') {
      wrapper.appendChild(button('Approve', { action: 'approve_task', project_id: item.project_id, task_id: item.task_id }));
      wrapper.appendChild(button('Return for changes', { action: 'reject_task', project_id: item.project_id, task_id: item.task_id }));
    } else wrapper.appendChild(button('Mark read', { action: 'mark_read', id: item.id }));
    if (toast) {
      const close = element('button', '×', 'close');
      close.type = 'button'; close.setAttribute('aria-label', 'Hide popup; keep notification');
      Object.assign(close.style, { position: 'absolute', right: '8px', top: '6px' });
      close.addEventListener('click', () => dismissToast(wrapper));
      wrapper.appendChild(close);
    }
    return wrapper;
  }
  function render(data) {
    lastData = data;
    applyPreferences(data.preferences);
    const items = data.items || [];
    badge.textContent = data.count || '';
    badge.hidden = !data.count;
    const signature = JSON.stringify(items);
    if (signature !== lastSignature) {
      list.replaceChildren();
      if (!items.length) list.appendChild(element('div', 'No new project notifications.', 'px-3 py-2 text-muted small'));
      items.forEach(item => list.appendChild(card(item, false)));
      lastSignature = signature;
    }
    renderToasts(items);
  }
  function renderToasts(items) {
    const ids = new Set(items.map(item => String(item.id)));
    toastHost.querySelectorAll('[data-notification-id]').forEach(el => { if (!ids.has(el.dataset.notificationId)) removeToast(el); });
    const eligible = items.filter(item => !isOwnerNotice(item.kind) || preferences.toast_mode !== 'badge');
    eligible.forEach(item => {
      const id = String(item.id);
      if (!shown.has(id) && !dismissed.has(id) && toastHost.querySelectorAll('[data-notification-id]').length < 3) {
        const wrapper = card(item, true);
        toastHost.appendChild(wrapper);
        shown.add(id);
        startTimer(wrapper);
      }
    });
  }
  async function update(action) {
    if (busy) return;
    busy = true;
    try {
      const options = { credentials: 'same-origin', cache: 'no-store' };
      if (action) { options.method = 'POST'; options.body = new URLSearchParams({ ...action, csrf_token: config.csrfToken }); }
      const response = await fetch(config.endpoint, options);
      if (response.status === 401) { toastHost.replaceChildren(); list.replaceChildren(); badge.hidden = true; return; }
      const data = await response.json();
      if (!response.ok || data.error) throw new Error(data.error || 'Could not load project notifications.');
      error.hidden = true;
      toastHost.querySelectorAll('.project-notice-error').forEach(el => el.remove());
      render(data);
      const moveForm = document.getElementById('projectTaskMoveForm');
      if (action && ['approve_task', 'reject_task'].includes(action.action) && moveForm
          && Number(moveForm.elements.project_id.value) === Number(action.project_id)) window.location.reload();
    } catch (err) {
      error.textContent = err.message;
      error.hidden = false;
      if (action) {
        toastHost.querySelectorAll('.project-notice-error').forEach(el => el.remove());
        const message = element('div', err.message, 'alert alert-danger project-notice-error');
        message.setAttribute('role', 'alert');
        toastHost.prepend(message);
      }
    } finally { busy = false; }
  }
  window.darkScrubProjectNotificationService = { getPreferences: () => ({ ...preferences }), savePreferences };
  applyPreferences(config.preferences || { toast_mode: 'persistent', revision: 0 });
  window.addEventListener('storage', event => {
    if (event.key !== preferencesKey) return;
    try { applyPreferences(JSON.parse(event.newValue)); } catch (_) { /* Ignore invalid cached values. */ }
  });
  window.addEventListener('focus', () => update());
  document.addEventListener('visibilitychange', () => { if (!document.hidden) update(); });
  update();
  setInterval(() => update(), 15000);
})();
