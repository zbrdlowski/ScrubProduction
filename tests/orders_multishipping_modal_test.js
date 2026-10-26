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
      on(event, handler) { this.handlers[event] = handler; return this; }
    };
  }
  const requests = [];
  const alerts = [];
  const confirmations = [];
  let failure = null;
  let reloads = 0;
  let currentResponse = response;
  const $ = value => value;
  $.getJSON = () => ({done(fn) { fn(currentResponse); return this; }, fail() { return this; }});
  $.post = (url, payload) => {
    requests.push(payload);
    return {
      done(fn) { if (!failure) fn({ok: true}); return this; },
      fail(fn) { if (failure) fn({responseJSON: {error: failure}}); return this; }
    };
  };
  const context = {
    $, multishippingOrderId: 0,
    $multishippingMessage: element(), $multishippingCandidates: element(),
    $multishippingSaveBtn: element(), $multishippingCancelBtn: element(), $multishippingUnlockBtn: element(),
    escapeMultishippingHtml: value => String(value).replaceAll('<', '&lt;'),
    alert: message => alerts.push(message),
    confirm: message => { confirmations.push(message); return true; },
    location: {reload() { reloads++; }}
  };
  vm.createContext(context);
  vm.runInContext(source.slice(start, end) + source.slice(actionsStart, actionsEnd), context);
  context.loadMultishipping(10);
  return {
    context, requests, alerts, confirmations,
    setResponse(value) { currentResponse = value; },
    failWith(message) { failure = message; },
    get reloads() { return reloads; },
    click(name) { const button = context[name]; button.handlers.click.call(button); }
  };
}

function response(status, canUnlock, editable = true) {
  return {
    ok: true, editable, requested_order_id: 10,
    group: {status, can_unlock: canUnlock, unlock_error: 'Every member must be Ready for Label.'},
    candidates: [10, 11].map(id => ({id, selected: true, master: id === 10, label: 'TEST-' + id, status: 'READY_TO_SHIP'}))
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

const withoutPhp = source.replace(/<\?[\s\S]*?\?>/g, 'null');
for (const script of withoutPhp.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)) {
  new vm.Script(script[1]);
}
console.log('orders_multishipping_modal: OK');
