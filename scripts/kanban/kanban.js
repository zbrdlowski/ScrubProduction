/* global Sortable */
(() => {
  'use strict';
  const app = document.getElementById('kb-app');
  if (!app) return;
  const cfg = window.kanbanConfig || { language: 'sk', translations: {} };
  const t = (key, values = {}) => {
    const text = cfg.language === 'sk' ? (cfg.translations[key] ?? key) : key;
    return text.replace(/\{(\w+)\}/g, (match, name) => Object.hasOwn(values, name) ? String(values[name]) : match);
  };
  document.documentElement.lang = cfg.language;
  if (cfg.languageCookie) document.cookie = cfg.languageCookie + '=' + cfg.language +
    '; Max-Age=31536000; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  const $ = id => document.getElementById(id);
  const boardEl = $('kb-board');
  const state = { board: null, columns: [], busy: false, search: '', sequence: 0, sortables: [], dragging: false, choosingTemplate: false };
  const priorities = { low: t('Low'), normal: t('Normal'), high: t('High'), urgent: t('Urgent') };
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  const formField = (form, name) => form.elements.namedItem(name);
  const columnById = id => state.columns.find(c => c.id === Number(id));
  const cardById = id => state.columns.flatMap(c => c.cards).find(c => c.id === Number(id));
  let searchTimer;
  let lastDrag = 0;
  let pendingDelete = null;
  let pendingTemplate = null;

  const draftForms = [$('kb-card-form'), $('kb-column-form')];
  const draftSnapshot = form => JSON.stringify([...new FormData(form)]);
  const rememberDraft = form => { form.dataset.initialDraft = draftSnapshot(form); };
  const hasDraft = () => draftForms.some(form => form.closest('dialog').open &&
    form.dataset.initialDraft !== draftSnapshot(form));
  app.querySelectorAll('[data-kb-language]').forEach(link => link.addEventListener('click', event => {
    if (state.busy || state.dragging || link.dataset.kbLanguage === cfg.language) {
      event.preventDefault();
      return;
    }
    if (hasDraft() && !window.confirm(t('Switch language and discard unsaved changes?'))) event.preventDefault();
  }));
  // Browser validation uses the selected module language, independent of browser settings.
  app.addEventListener('invalid', event => {
    const input = event.target;
    if (!input.setCustomValidity || input.validity.customError) return;
    let text = t('Please enter a valid value.');
    if (input.validity.valueMissing) text = t(input.tagName === 'SELECT' ? 'Please select an option.' : 'Please fill out this field.');
    else if ((input.validity.rangeUnderflow || input.validity.rangeOverflow) && input.min && input.max) {
      text = t('Please enter a value between {min} and {max}.', { min: input.min, max: input.max });
    }
    input.setCustomValidity(text);
  }, true);
  for (const type of ['input', 'change']) app.addEventListener(type, event => {
    if (event.target.setCustomValidity) event.target.setCustomValidity('');
  }, true);
  draftForms.forEach(form => form.addEventListener('reset', () => {
    form.querySelectorAll('input, select, textarea').forEach(input => input.setCustomValidity(''));
  }));

  function message(text = '') {
    $('kb-message').textContent = text;
    $('kb-message').hidden = !text;
  }
  function setBusy(busy) {
    state.busy = busy;
    boardEl.setAttribute('aria-busy', String(busy));
    app.querySelectorAll('button, input, select, textarea').forEach(el => {
      if (el.id !== 'kb-search') el.disabled = busy;
    });
    state.sortables.forEach(s => s.option('disabled', busy || !!state.search));
    $('kb-save-status').textContent = busy ? t('Saving…') : '';
  }
  async function request(action = '', data = null, query = {}) {
    const url = new URL('scripts/kanban/api.php', document.baseURI);
    url.searchParams.set('kb_lang', cfg.language);
    if (action) url.searchParams.set('action', action);
    Object.entries(query).forEach(([key, value]) => url.searchParams.set(key, value));
    let response;
    try {
      response = await fetch(url, {
        method: data === null ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: data === null ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': app.dataset.csrf },
        body: data === null ? undefined : JSON.stringify(data)
      });
    } catch (cause) {
      throw new Error(t('The connection was interrupted. Refresh the board to check whether your change was saved.'));
    }
    let result;
    try { result = await response.json(); } catch (cause) {
      throw new Error(t('The server returned an invalid response. Refresh the page and try again.'));
    }
    if (!response.ok || !result.ok) {
      const error = new Error(result.error || t('The operation failed.'));
      error.status = response.status;
      throw error;
    }
    return result;
  }
  async function load() {
    const sequence = ++state.sequence;
    boardEl.setAttribute('aria-busy', 'true');
    const result = await request('', null, { search: state.search });
    if (sequence !== state.sequence) return;
    state.board = result.board;
    state.columns = result.columns;
    render();
    boardEl.setAttribute('aria-busy', String(state.busy));
  }
  async function refresh() {
    if (state.busy) return;
    message();
    setBusy(true);
    $('kb-save-status').textContent = t('Loading…');
    try { await load(); } catch (error) { message(error.message); }
    finally { setBusy(false); }
  }
  async function mutate(action, data, dialog = null) {
    if (state.busy) return;
    message();
    const formError = dialog?.querySelector('.kb-form-error');
    if (formError) formError.hidden = true;
    setBusy(true);
    let saved = false;
    try {
      await request(action, { ...data, version: state.board?.version });
      saved = true;
      if (action === 'apply_template') {
        state.choosingTemplate = false;
        clearTimeout(searchTimer);
        state.search = '';
        $('kb-search').value = '';
      }
      if (dialog) dialog.close();
      await load();
      $('kb-save-status').textContent = t('Saved');
    } catch (error) {
      // The last server snapshot restores DOM order after a rejected drag.
      render();
      const text = saved ? t('Your change was saved, but the board could not be refreshed. Use Refresh board.') : error.message;
      if (formError && dialog.open) { formError.textContent = text; formError.hidden = false; }
      else message(text);
      if (error.status === 409 && dialog?.open) {
        // Keep an open form's original revision: never silently overwrite a newer edit.
        message(text + ' ' + t('Your draft remains open. Close it and refresh the board before making another change.'));
      }
      return false;
    } finally {
      setBusy(false);
    }
    $('kb-save-status').textContent = t('Saved');
    return true;
  }
  function dateLabel(value) {
    if (!value) return '';
    const date = new Date(value.slice(0, 10) + 'T12:00:00');
    return Number.isNaN(date.valueOf()) ? value.slice(0, 10) : date.toLocaleDateString(cfg.language === 'en' ? 'en-GB' : 'sk-SK');
  }
  function localToday() {
    const now = new Date();
    return [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
  }
  function cardHTML(card, column) {
    const overdue = !column.is_done && card.due_date && card.due_date < localToday();
    const reference = card.reference || (card.reference_url ? t('Related record') : '');
    const url = /^https?:\/\//i.test(card.reference_url || '') ? card.reference_url : '';
    return '<article class="kb-card" data-id="' + card.id + '" aria-label="' + esc(card.title) + '">' +
      '<div class="kb-card-top"><span class="kb-tag">' + esc(card.label || '#' + card.id) + '</span>' +
      '<span class="kb-priority kb-priority-' + esc(card.priority) + '">' + esc(priorities[card.priority] || t('Normal')) + '</span></div>' +
      '<button type="button" class="kb-card-title" data-edit-card="' + card.id + '">' + esc(card.title) + '</button>' +
      (reference ? '<div class="kb-reference">' + (url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener noreferrer">' + esc(reference) + ' <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>' : esc(reference)) + '</div>' : '') +
      (card.description ? '<p class="kb-description">' + esc(card.description) + '</p>' : '') +
      '<div class="kb-card-bottom"><span class="' + (overdue ? 'kb-overdue' : '') + '">' +
      (column.is_done ? '<i class="fas fa-check" aria-hidden="true"></i> ' + dateLabel(card.completed_at) :
        (card.due_date ? '<i class="far fa-calendar-alt" aria-hidden="true"></i> ' + dateLabel(card.due_date) + (overdue ? ' · ' + t('overdue') : '') : t('No due date'))) +
      '</span><div class="kb-card-controls">' +
      (!column.is_done && !state.search ? '<button type="button" class="kb-btn" data-up-card="' + card.id + '" title="' + esc(t('Move card up')) + '" aria-label="' + esc(t('Move card up')) + '">↑</button><button type="button" class="kb-btn" data-down-card="' + card.id + '" title="' + esc(t('Move card down')) + '" aria-label="' + esc(t('Move card down')) + '">↓</button>' : '') +
      '<button type="button" class="kb-btn" data-edit-card="' + card.id + '" title="' + esc(t('Edit or move card')) + '" aria-label="' + esc(t('Edit or move card')) + '"><i class="fas fa-pen" aria-hidden="true"></i></button></div></div></article>';
  }
  function render() {
    const scrollX = boardEl.scrollLeft;
    const scrolls = new Map([...boardEl.querySelectorAll('.kb-list')].map(el => [Number(el.dataset.column), el.scrollTop]));
    state.sortables.forEach(s => s.destroy());
    state.sortables = [];
    const showBoard = !!state.board && !state.choosingTemplate;
    $('kb-toolbar').hidden = !showBoard;
    $('kb-onboarding').hidden = !!state.board && !state.choosingTemplate;
    $('kb-templates-open').hidden = !state.board;
    $('kb-template-back').hidden = !state.board;
    $('kb-template-notice').hidden = !state.board;
    $('kb-help').hidden = !showBoard;
    boardEl.hidden = !showBoard;
    $('kb-filter-note').hidden = !state.search || !showBoard;
    if (state.board) $('kb-done-limit').value = state.board.done_limit;
    boardEl.innerHTML = state.columns.map(col =>
      '<section class="kb-lane" data-id="' + col.id + '" style="--lane-color:' + esc(col.color) + '" aria-label="' + esc(col.title) + '">' +
      '<header class="kb-lane-header"><span class="kb-column-handle" title="' + esc(t('Drag to reorder columns')) + '" aria-hidden="true"><i class="fas fa-grip-vertical"></i></span>' +
      '<h2 class="kb-lane-title">' + esc(col.title) + '</h2><span class="kb-count">' + col.cards.length + (col.total > col.cards.length ? ' / ' + col.total : '') + '</span>' +
      '<button type="button" class="kb-btn" data-edit-column="' + col.id + '" title="' + esc(t('Column settings')) + '" aria-label="' + esc(t('Column settings')) + ' ' + esc(col.title) + '"><i class="fas fa-ellipsis-h" aria-hidden="true"></i></button></header>' +
      (col.is_done ? '<div class="kb-lane-note"><i class="fas fa-check-circle" aria-hidden="true"></i> ' + esc(t('Completed · newest first')) + '</div>' : '') +
      '<div class="kb-list" data-empty-label="' + esc(t('Drop a card here')) + '" data-column="' + col.id + '">' + col.cards.map(card => cardHTML(card, col)).join('') + '</div>' +
      '<footer class="kb-lane-footer">' + (col.has_more ? '<button type="button" class="kb-btn" data-more="' + col.id + '">' + (col.is_done ? t('Load older') : t('Load more')) + ' (' + (col.total - col.cards.length) + ')</button>' : '') +
      '<button type="button" class="kb-btn" data-add-card="' + col.id + '"><i class="fas fa-plus" aria-hidden="true"></i> ' + esc(t('Add card')) + '</button></footer></section>'
    ).join('');
    boardEl.scrollLeft = scrollX;
    boardEl.querySelectorAll('.kb-list').forEach(el => { el.scrollTop = scrolls.get(Number(el.dataset.column)) || 0; });
    if (!showBoard) return;
    if (typeof Sortable === 'undefined') {
      message(t('Drag and drop could not be loaded. Refresh the page; you can also move a card from its details.'));
      return;
    }
    const animation = matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 150;
    const common = {
      animation, ghostClass: 'kb-ghost', chosenClass: 'kb-chosen', disabled: state.busy || !!state.search,
      delay: 160, delayOnTouchOnly: true, touchStartThreshold: 4,
      onStart() { state.dragging = true; app.classList.add('kb-dragging'); },
      onMove(event) {
        app.querySelectorAll('.kb-drop-target').forEach(el => el.classList.remove('kb-drop-target'));
        event.to.classList.add('kb-drop-target');
      }
    };
    state.sortables.push(new Sortable(boardEl, {
      ...common, draggable: '.kb-lane', handle: '.kb-column-handle', direction: 'horizontal',
      onEnd(event) {
        endDrag();
        if (event.oldIndex === event.newIndex) return;
        const next = event.item.nextElementSibling;
        void mutate('move_column', { id: Number(event.item.dataset.id), before_id: Number(next?.dataset.id || 0) });
      }
    }));
    boardEl.querySelectorAll('.kb-list').forEach(list => {
      const col = columnById(list.dataset.column);
      state.sortables.push(new Sortable(list, {
        ...common, group: 'personal-kanban', draggable: '.kb-card', sort: !col.is_done,
        filter: 'button:not(.kb-card-title), a, input, select, textarea', preventOnFilter: false,
        emptyInsertThreshold: 30,
        onEnd(event) {
          endDrag();
          if (event.from === event.to && event.oldIndex === event.newIndex) return;
          const next = event.item.nextElementSibling;
          void mutate('move_card', {
            id: Number(event.item.dataset.id), column_id: Number(event.to.dataset.column),
            before_id: Number(next?.dataset.id || 0)
          });
        }
      }));
    });
  }
  function endDrag() {
    state.dragging = false;
    lastDrag = Date.now();
    app.classList.remove('kb-dragging');
    app.querySelectorAll('.kb-drop-target').forEach(el => el.classList.remove('kb-drop-target'));
  }
  function openCard(id = 0, columnId = 0) {
    if (state.busy || !state.board || Date.now() - lastDrag < 250) return;
    const card = id ? cardById(id) : null;
    const form = $('kb-card-form');
    form.reset();
    form.querySelector('.kb-form-error').hidden = true;
    formField(form, 'column_id').innerHTML = state.columns.map(c => '<option value="' + c.id + '">' + esc(c.title) + '</option>').join('');
    for (const key of ['id', 'title', 'description', 'priority', 'due_date', 'label', 'reference', 'reference_url']) {
      formField(form, key).value = card?.[key] ?? (key === 'id' ? 0 : key === 'priority' ? 'normal' : '');
    }
    formField(form, 'column_id').value = card?.column_id || columnId || state.columns[0].id;
    $('kb-card-dialog-title').textContent = card ? t('Card details #{id}', { id: card.id }) : t('New card');
    $('kb-delete-card').hidden = !card;
    $('kb-card-dates').textContent = card ? t('Created {date}', { date: dateLabel(card.created_at) }) + (card.completed_at ? ' · ' + t('Completed {date}', { date: dateLabel(card.completed_at) }) : '') : '';
    rememberDraft(form);
    $('kb-card-dialog').showModal();
    formField(form, 'title').focus();
  }
  function openColumn(id = 0) {
    if (state.busy || !state.board) return;
    const col = id ? columnById(id) : null;
    const form = $('kb-column-form');
    form.reset();
    form.querySelector('.kb-form-error').hidden = true;
    formField(form, 'id').value = col?.id || 0;
    formField(form, 'title').value = col?.title || '';
    formField(form, 'color').value = col?.color || '#60a5fa';
    formField(form, 'is_done').checked = !!col?.is_done;
    $('kb-column-dialog-title').textContent = col ? t('Column settings') : t('New column');
    $('kb-delete-column').hidden = !col;
    $('kb-column-order').hidden = !col;
    rememberDraft(form);
    $('kb-column-dialog').showModal();
    formField(form, 'title').focus();
  }
  async function more(id) {
    if (state.busy) return;
    const col = columnById(id);
    setBusy(true);
    message();
    try {
      const result = await request('', null, { column_id: id, version: state.board.version, offset: col.cards.length, search: state.search });
      col.cards.push(...result.cards); col.total = result.total; col.has_more = result.has_more;
      render();
    } catch (error) { message(error.message); } finally { setBusy(false); }
  }
  function stepCard(id, direction) {
    const card = cardById(id), col = columnById(card.column_id);
    const index = col.cards.findIndex(c => c.id === id);
    if (direction < 0 && index === 0) return;
    if (direction > 0 && index === col.cards.length - 1) {
      if (col.has_more) message(t('Load more cards in this column first.'));
      return;
    }
    const before = direction < 0 ? col.cards[index - 1].id : (col.cards[index + 2]?.id || 0);
    void mutate('move_card', { id, column_id: col.id, before_id: before });
  }
  function confirmDelete(action, id, text, dialog) {
    pendingDelete = { action, id, dialog };
    $('kb-confirm-text').textContent = text;
    $('kb-confirm-dialog').showModal();
  }
  $('kb-confirm-form').addEventListener('submit', event => {
    event.preventDefault();
    $('kb-confirm-dialog').close();
    if (pendingDelete) void mutate(pendingDelete.action, { id: pendingDelete.id }, pendingDelete.dialog);
    pendingDelete = null;
  });
  app.addEventListener('click', event => {
    const button = event.target.closest('button');
    if (!button || state.busy) return;
    const d = button.dataset;
    if ('close' in d) button.closest('dialog').close();
    else if (d.template) {
      if (!state.board) { void mutate('initialize', { template: d.template }); return; }
      pendingTemplate = d.template;
      const names = button.querySelector('span').textContent.split(' → ');
      $('kb-template-summary').textContent = t('Apply “{template}”? Active cards move to “{active}” and completed cards to “{done}”.', {
        template: button.querySelector('strong').textContent, active: names[0], done: names[names.length - 1]
      });
      $('kb-template-form').querySelector('.kb-form-error').hidden = true;
      $('kb-template-dialog').showModal();
    }
    else if (d.editCard) openCard(Number(d.editCard));
    else if (d.addCard) openCard(0, Number(d.addCard));
    else if (d.editColumn) openColumn(Number(d.editColumn));
    else if (d.more) void more(Number(d.more));
    else if (d.upCard) stepCard(Number(d.upCard), -1);
    else if (d.downCard) stepCard(Number(d.downCard), 1);
  });
  app.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('cancel', event => {
    if (state.busy) event.preventDefault();
  }));
  $('kb-guide-open').addEventListener('click', () => { if (!state.busy) $('kb-guide-dialog').showModal(); });
  $('kb-templates-open').addEventListener('click', () => {
    if (state.busy || state.dragging) return;
    state.choosingTemplate = true;
    message();
    render();
    app.querySelector('[data-template]').focus();
  });
  $('kb-template-back').addEventListener('click', () => {
    if (state.busy) return;
    state.choosingTemplate = false;
    render();
    $('kb-templates-open').focus();
  });
  $('kb-template-form').addEventListener('submit', event => {
    event.preventDefault();
    if (pendingTemplate) void mutate('apply_template', { template: pendingTemplate }, $('kb-template-dialog'));
  });
  $('kb-refresh').addEventListener('click', refresh);
  $('kb-add-card').addEventListener('click', () => openCard());
  $('kb-add-column').addEventListener('click', () => openColumn());
  $('kb-search').addEventListener('input', () => {
    clearTimeout(searchTimer);
    const applySearch = () => {
      if (state.busy) { searchTimer = setTimeout(applySearch, 100); return; }
      state.search = $('kb-search').value.trim();
      void refresh();
    };
    searchTimer = setTimeout(applySearch, 250);
  });
  $('kb-done-limit').addEventListener('change', () => {
    if ($('kb-done-limit').reportValidity()) void mutate('settings', { done_limit: Number($('kb-done-limit').value) });
  });
  $('kb-card-form').addEventListener('submit', event => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget));
    data.id = Number(data.id); data.column_id = Number(data.column_id);
    void mutate('save_card', data, $('kb-card-dialog'));
  });
  $('kb-column-form').addEventListener('submit', event => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget));
    data.id = Number(data.id);
    data.is_done = formField(event.currentTarget, 'is_done').checked ? 1 : 0;
    void mutate('save_column', data, $('kb-column-dialog'));
  });
  $('kb-delete-card').addEventListener('click', () => {
    const id = Number(formField($('kb-card-form'), 'id').value);
    confirmDelete('delete_card', id, t('Card “{title}” will be permanently deleted.', { title: cardById(id).title }), $('kb-card-dialog'));
  });
  $('kb-delete-column').addEventListener('click', () => {
    const id = Number(formField($('kb-column-form'), 'id').value);
    confirmDelete('delete_column', id, t('Delete column “{title}”? The column must be empty, including older cards.', { title: columnById(id).title }), $('kb-column-dialog'));
  });
  for (const [button, direction] of [['kb-column-left', -1], ['kb-column-right', 1]]) {
    $(button).addEventListener('click', () => {
      const id = Number(formField($('kb-column-form'), 'id').value);
      const index = state.columns.findIndex(c => c.id === id);
      if (index + direction < 0 || index + direction >= state.columns.length) return;
      const before = direction < 0 ? state.columns[index - 1].id : (state.columns[index + 2]?.id || 0);
      void mutate('move_column', { id, before_id: before }, $('kb-column-dialog'));
    });
  }
  void refresh();
})();
