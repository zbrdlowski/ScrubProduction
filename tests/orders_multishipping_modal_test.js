const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../includes/orders.php'), 'utf8');
const start = source.indexOf('    function loadMultishipping(orderId) {');
const end = source.indexOf("    $(document).on('click', '.js-open-multishipping'", start);
const actionsStart = source.indexOf("    $multishippingCancelBtn.on('click'");
const actionsEnd = source.indexOf("    $(document).on('click', '.js-ms-unlock-order'", actionsStart);
assert.ok(start >= 0 && end > start && actionsStart >= 0 && actionsEnd > actionsStart);

function fixture(response) {
  function element() {
    return {
      hidden: false, content: '', properties: {}, values: {}, handlers: {},
      empty() { this.content = ''; return this; },
      html(value) { this.content = value; return this; },
      text(value) { this.content = value; return this; },
      prop(key, value) { this.properties[key] = value; return this; },
      addClass() { this.hidden = true; return this; },
      removeClass() { this.hidden = false; return this; },
      toggleClass(name, hidden) { this.hidden = hidden; return this; },
      data(key, value) { if (arguments.length === 1) return this.values[key]; this.values[key] = value; return this; },
      on(event, selector, handler) { this.handlers[handler ? selector : event] = handler || selector; return this; }
    };
  }
  const requests = [];
  const alerts = [];
  const confirmations = [];
  let failure = null;
  let reloads = 0;
  let currentResponse = response;
  let selectedIds = [];
  let confirmationAllowed = true;
  const document = element();
  const $ = value => value === '.ms-release-check:checked' ? {
    length: selectedIds.length,
    map(fn) { return {get() { return selectedIds.map(id => fn.call({value: String(id)})); }}; }
  } : value;
  $.getJSON = () => ({done(fn) { fn(currentResponse); return this; }, fail() { return this; }});
  $.post = (url, payload) => {
    requests.push(payload);
    return {
      done(fn) { if (!failure) fn({ok: true}); return this; },
      fail(fn) { if (failure) fn({responseJSON: {error: failure}}); return this; }
    };
  };
  const context = {
    $, document, multishippingOrderId: 0,
    $multishippingMessage: element(), $multishippingCandidates: element(),
    $multishippingReleaseBtn: element(),
    $multishippingSaveBtn: element(), $multishippingCancelBtn: element(), $multishippingUnlockBtn: element(),
    escapeMultishippingHtml: value => String(value).replaceAll('<', '&lt;'),
    alert: message => alerts.push(message),
    confirm: message => { confirmations.push(message); return confirmationAllowed; },
    location: {reload() { reloads++; }}
  };
  vm.createContext(context);
  vm.runInContext(source.slice(start, end) + source.slice(actionsStart, actionsEnd), context);
  context.loadMultishipping(10);
  return {
    context, requests, alerts, confirmations,
    select(ids) { selectedIds = ids; document.handlers['.ms-release-check'](); },
    cancelConfirmation() { confirmationAllowed = false; },
    setResponse(value) { currentResponse = value; },
    failWith(message) { failure = message; },
    get reloads() { return reloads; },
    click(name) { const button = context[name]; button.handlers.click.call(button); }
  };
}

function response(status, canUnlock, editable = true) {
  return {
    ok: true, editable, requested_order_id: 10,
    group: {id: 5, status, can_unlock: canUnlock, unlock_error: 'Every member must be Ready for Label.'},
    candidates: [10, 11].map(id => ({id, selected: true, can_release: status === 'SHIPPED' || status === 'EXPORTED', master: id === 10, label: 'TEST-' + id, status: 'READY_TO_SHIP'}))
  };
}

const recovered = fixture(response('SHIPPED', true));
assert.equal(recovered.context.$multishippingUnlockBtn.hidden, false);
assert.equal(recovered.context.$multishippingUnlockBtn.content, 'Unlock group');
assert.equal(recovered.context.$multishippingCancelBtn.hidden, true);
assert.equal(recovered.context.$multishippingSaveBtn.hidden, true);
assert.match(recovered.context.$multishippingMessage.content, /All member orders are back in Ready for Label/);
recovered.setResponse(response('DRAFT', false));
recovered.click('$multishippingUnlockBtn');
assert.equal(recovered.requests[0].action, 'unlock_group');
assert.equal(recovered.requests[0].order_id, 10);
assert.match(recovered.confirmations[0], /does not cancel any FedEx label/);
assert.equal(recovered.context.$multishippingUnlockBtn.hidden, true);
assert.equal(recovered.context.$multishippingCancelBtn.hidden, false);
recovered.click('$multishippingCancelBtn');
assert.equal(recovered.requests[1].action, 'cancel');
assert.equal(recovered.reloads, 1);

const blocked = fixture(response('SHIPPED', false));
assert.equal(blocked.context.$multishippingUnlockBtn.hidden, true);
assert.equal(blocked.context.$multishippingCancelBtn.hidden, true);
assert.match(blocked.context.$multishippingMessage.content, /Every member must be Ready for Label/);
assert.doesNotMatch(blocked.context.$multishippingMessage.content, /Unlock the group before/);

for (const status of ['DRAFT', 'EXPORTED', 'SHIPPED']) {
  const readonly = fixture(response(status, true, false));
  assert.equal(readonly.context.$multishippingUnlockBtn.hidden, true);
  assert.equal(readonly.context.$multishippingCancelBtn.hidden, true);
  assert.match(readonly.context.$multishippingMessage.content, /do not have permission/);
}

const exported = fixture(response('EXPORTED', true));
assert.equal(exported.context.$multishippingUnlockBtn.hidden, false);
assert.equal(exported.context.$multishippingUnlockBtn.content, 'Unlock exported group');
exported.failWith('A member changed status; refresh the group.');
exported.click('$multishippingUnlockBtn');
assert.match(exported.confirmations[0], /generated CSV will not be used/);
assert.equal(exported.alerts[0], 'A member changed status; refresh the group.');

const failedRemove = fixture(response('DRAFT', false));
failedRemove.failWith('The group is no longer a draft.');
failedRemove.click('$multishippingCancelBtn');
assert.equal(failedRemove.alerts[0], 'The group is no longer a draft.');
assert.equal(failedRemove.reloads, 0);

const release = fixture(response('SHIPPED', false));
assert.equal(release.context.$multishippingReleaseBtn.hidden, false);
assert.equal(release.context.$multishippingReleaseBtn.properties.disabled, true);
assert.match(release.context.$multishippingCandidates.content, /ms-release-10/);
assert.doesNotMatch(release.context.$multishippingCandidates.content, /id="ms-release-10"[^>]*checked/);
release.select([11]);
assert.equal(release.context.$multishippingReleaseBtn.properties.disabled, false);
release.click('$multishippingReleaseBtn');
assert.equal(release.requests[0].action, 'release_orders');
assert.equal(release.requests[0].group_id, 5);
assert.equal(release.requests[0].order_id, 10);
assert.deepEqual(JSON.parse(release.requests[0].order_ids), [11]);
assert.match(release.confirmations[0], /tracking numbers will be removed/);
assert.equal(release.reloads, 1);

const cancelledRelease = fixture(response('SHIPPED', false));
cancelledRelease.click('$multishippingReleaseBtn');
assert.equal(cancelledRelease.requests.length, 0);
cancelledRelease.select([10, 11]);
cancelledRelease.cancelConfirmation();
cancelledRelease.click('$multishippingReleaseBtn');
assert.equal(cancelledRelease.requests.length, 0);

const failedRelease = fixture(response('SHIPPED', false));
failedRelease.select([10, 11]);
failedRelease.failWith('The multishipping group has changed.');
failedRelease.click('$multishippingReleaseBtn');
assert.equal(failedRelease.alerts[0], 'The multishipping group has changed.');
assert.equal(failedRelease.reloads, 0);
assert.equal(failedRelease.context.$multishippingReleaseBtn.properties.disabled, false);

for (const status of ['EXPORTED', 'SHIPPED']) {
  const readonlyRelease = fixture(response(status, false, false));
  assert.equal(readonlyRelease.context.$multishippingReleaseBtn.hidden, true);
  assert.doesNotMatch(readonlyRelease.context.$multishippingCandidates.content, /ms-release-check/);
}
const draftRelease = fixture(response('DRAFT', false));
assert.equal(draftRelease.context.$multishippingReleaseBtn.hidden, true);

const ineligible = response('SHIPPED', false);
ineligible.candidates.push({id: 12, selected: true, can_release: false, status: 'DELIVERED'});
ineligible.candidates.push({id: 13, selected: false, can_release: false, status: 'READY_TO_SHIP'});
const ineligibleUi = fixture(ineligible);
assert.doesNotMatch(ineligibleUi.context.$multishippingCandidates.content, /ms-release-(12|13)/);

const withoutPhp = source.replace(/<\?[\s\S]*?\?>/g, 'null');
for (const script of withoutPhp.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)) {
  new vm.Script(script[1]);
}
console.log('orders_multishipping_modal: OK');
