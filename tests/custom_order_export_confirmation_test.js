const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../includes/custom_orders.php'), 'utf8');
const start = source.indexOf('    function customOrderHeaderSnapshot(form) {');
const end = source.indexOf('    function initializeCustomOfficialNumberModal()', start);
assert.ok(start >= 0 && end > start);
const code = source.slice(start, end);

function fixture({missing = [], total = '100', answer = true, lang = 'en'} = {}) {
  const alerts = [];
  const confirmations = [];
  const header = {dataset: {}, values: [['shipping_email', 'old@example.test'], ['shipping_phone', '+421900000001']]};
  const context = {
    customOrdersHelpLang: lang,
    FormData: class {constructor(form) {this.values = form.values;} entries() {return this.values[Symbol.iterator]();}},
    document: {getElementById(id) {assert.equal(id, 'custom-twin-header-form-123'); return header;}},
    window: {alert(text) {alerts.push(text);}, confirm(text) {confirmations.push(text); return answer;}}
  };
  vm.createContext(context);
  vm.runInContext(code, context);
  const root = {querySelectorAll() {return [header];}};
  context.initializeCustomExportSnapshots(root);
  const attrs = {'data-export-missing-fields': JSON.stringify(missing), 'data-export-total': total, 'data-export-currency': 'EUR', 'data-export-order-number': 'SO-TEST'};
  const form = {querySelector() {return {value: '123'};}, getAttribute(name) {return attrs[name] || null;}};
  return {alerts, confirmations, header, root, context, run: () => context.confirmCustomOrderProductionExport(form)};
}

const dirty = fixture();
dirty.header.values[1][1] = '';
assert.equal(dirty.run(), false, 'Deleting without saving must not export the old phone');
assert.equal(dirty.confirmations.length, 0);
assert.match(dirty.alerts[0], /Save changes/);
dirty.context.initializeCustomExportSnapshots(dirty.root);
assert.equal(dirty.run(), false, 'Reinitializing an existing form must not mark dirty values as saved');
dirty.header.values[1][1] = '+421900000001';
assert.equal(dirty.run(), true, 'Reverting to the saved values allows export');

for (const answer of [false, true]) {
  const saved = fixture({missing: ['Contact email', 'Contact phone number'], total: '0', answer});
  assert.equal(saved.run(), answer);
  assert.equal(saved.alerts.length, 0);
  assert.equal(saved.confirmations.length, 1);
  assert.match(saved.confirmations[0], /- Contact email\n- Contact phone number/);
  assert.match(saved.confirmations[0], /zero total value/);
  assert.match(saved.confirmations[0], /accept responsibility/);
}
const translated = fixture({missing: ['Kontaktné telefónne číslo'], lang: 'sk'});
assert.equal(translated.run(), true);
assert.match(translated.confirmations[0], /preberám zodpovednosť/);
const clean = fixture();
assert.equal(clean.run(), true);
assert.match(clean.confirmations[0], /After export it will enter/);
const missingSnapshot = fixture();
delete missingSnapshot.header.dataset.exportSavedSnapshot;
assert.equal(missingSnapshot.run(), false, 'Missing initialization must not export unchecked values');

for (const script of source.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)) {
  new vm.Script(script[1].replace(/<\?[\s\S]*?\?>/g, 'null'));
}
console.log('custom_order_export_confirmation: OK');
