'use strict';

// Run: node tests/darkscrub_import_spec_test.js
// Optional real exports: --raw <shoptet_raw.csv> --expected <Darkscrub.csv>
// Optional eBay unified export: --ebay-unified <Darkscrubfix.csv>
// Optional old script: --original <darkscrub_import_spec.js>
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../scripts/google_apps_script/darkscrub_import_spec.js'), 'utf8');

function makeSheet(data) {
  return {
    data,
    clears: 0,
    writes: [],
    getDataRange() { return { getDisplayValues: () => this.data }; },
    clearContents() { this.clears++; this.data = []; },
    getRange(row, column, height, width) {
      return {
        setValues: values => {
          this.writes.push({ type: 'values' });
          assert.equal(values.length, height);
          values.forEach(value => assert.equal(value.length, width));
          this.data = values.map(value => Array.from(value));
        },
        setNumberFormat: format => { this.writes.push({ type: 'format', column, format }); }
      };
    },
    setFrozenRows() {},
    autoResizeColumns() {}
  };
}

function load(tables = {}, script = source) {
  const sheets = Object.fromEntries(Object.entries(tables).map(([name, data]) => [name, makeSheet(data)]));
  const ss = {
    getSheetByName: name => sheets[name] || null,
    insertSheet(name) { return (sheets[name] = makeSheet([])); }
  };
  const context = vm.createContext({ SpreadsheetApp: { getActive: () => ss } });
  vm.runInContext(script, context);
  return { context, ss, sheets };
}

const plain = value => JSON.parse(JSON.stringify(value));
const headers = ['code', 'date', 'itemName', 'itemAmount', 'itemCode',
  'Category Info', 'graphics-parts-versions', 'itemHsCode', 'itemUnitPriceWithVat', 'phone', 'billZip'];
const row = ['ORDER-001', '2026-11-12 12:00:00', 'Graphics Kit', '2', 'G_TEST',
  'Test brand | Test model', 'Full kit', '391990', '49,90', '+421900000000', '00123'];

function generate(table, extras = {}, script = source) {
  const loaded = load({ SHOPTET_RAW: table, ...extras }, script);
  loaded.context.generateDarkScrubImport();
  const data = plain(loaded.sheets.DARKSCRUB_IMPORT.data);
  return data.slice(1).map(values => Object.fromEntries(data[0].map((header, i) => [header, values[i]])));
}

function comparable(rows) {
  return rows.map(row => ({
    ...row,
    options_json: JSON.parse(row.options_json),
    source_raw_json: JSON.parse(row.source_raw_json)
  }));
}

let passed = 0;
function test(name, fn) {
  fn();
  passed++;
  console.log('PASS ' + name);
}

const baseline = comparable(generate([headers, row]));

test('maps values by name and preserves graphics versions / HS code', () => {
  const actual = baseline[0];
  assert.equal(actual.external_order_id, 'ORDER-001');
  assert.equal(actual.item_sku, 'G_TEST');
  assert.equal(actual.item_qty, '2');
  assert.equal(actual.item_unit_price_with_vat, '49,90');
  assert.equal(actual.phone, "'+421900000000");
  assert.equal(actual.bill_zip, '00123');
  assert.equal(actual.options_json['graphics-parts-versions'], 'Full kit');
  assert.equal(actual.source_raw_json.itemHsCode, '391990');
  assert.equal(actual.source_raw_json['graphics-parts-versions'], 'Full kit');
});

test('25 reproducible column permutations leave all output values unchanged', () => {
  let seed = 42;
  for (let trial = 0; trial < 25; trial++) {
    const order = headers.map((_, i) => i);
    for (let i = order.length - 1; i > 0; i--) {
      seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0;
      const j = seed % (i + 1);
      [order[i], order[j]] = [order[j], order[i]];
    }
    assert.deepEqual(comparable(generate([order.map(i => headers[i]), order.map(i => row[i])])), baseline);
  }
});

test('new columns at the start, middle and end only extend raw JSON', () => {
  for (const position of [0, 6, headers.length]) {
    const h = headers.slice();
    const r = row.slice();
    h.splice(position, 0, 'future-shoptet-field');
    r.splice(position, 0, 'Keep "quotes", commas;\nand newlines');
    const actual = comparable(generate([h, r]));
    assert.equal(actual[0].source_raw_json['future-shoptet-field'], r[position]);
    delete actual[0].source_raw_json['future-shoptet-field'];
    assert.deepEqual(actual, baseline);
  }
});

test('older exports without either extra column still work', () => {
  const keep = headers.map((_, i) => i).filter(i => ![6, 7].includes(i));
  const actual = comparable(generate([keep.map(i => headers[i]), keep.map(i => row[i])]));
  const expected = plain(baseline);
  delete expected[0].options_json['graphics-parts-versions'];
  delete expected[0].source_raw_json['graphics-parts-versions'];
  delete expected[0].source_raw_json.itemHsCode;
  assert.deepEqual(actual, expected);
});

test('header BOM, wrapping quotes, whitespace, NBSP and case differences are accepted', () => {
  const h = headers.map(value => ' "' + value.toUpperCase() + '" ');
  h[0] = '\uFEFF"""CODE"""';
  h[5] = '" CATEGORY\u00A0INFO "';
  const actual = comparable(generate([h, row]))[0];
  assert.equal(actual.external_order_id, 'ORDER-001');
  assert.equal(actual.category_info, row[5]);
  assert.equal(actual.item_sku, 'G_TEST');
  assert.equal(actual.options_json['graphics-parts-versions'], 'Full kit');
});

function expectInputError(table, pattern) {
  const previous = [['previous import'], ['keep me']];
  const loaded = load({ SHOPTET_RAW: table, DARKSCRUB_IMPORT: plain(previous) });
  assert.throws(() => loaded.context.generateDarkScrubImport(), pattern);
  assert.equal(loaded.sheets.DARKSCRUB_IMPORT.clears, 0);
  assert.deepEqual(loaded.sheets.DARKSCRUB_IMPORT.data, previous);
}

test('duplicate names, including normalized duplicates, fail before clearing output', () => {
  expectInputError([[...headers, ' "ITEMCODE" '], [...row, 'OTHER']], /duplicitná hlavička.*stĺpcoch 5 a 12/);
});

test('missing required header fails before clearing output', () => {
  expectInputError([headers.slice(1), row.slice(1)], /chýbajú povinné hlavičky: code/);
});

test('unsplit CSV header fails with actionable separator guidance', () => {
  expectInputError([[headers.join(',')], [row.join(',')]], /oddeľovač stĺpcov/);
});

test('data without header and a stale shorter header fail before clearing output', () => {
  expectInputError([[...headers, ''], [...row, 'unexpected']], /riadok 2, stĺpec 12/);
  expectInputError([headers, [...row, 'unexpected']], /údaj bez hlavičky/);
});

test('blank columns / rows are ignored, zeroes and optional blank values survive', () => {
  const h = [...headers, '', 'future-zero'];
  const r = [...row, '', '0'];
  const actual = comparable(generate([h, Array(h.length).fill(''), r]));
  assert.equal(actual.length, 1);
  assert.equal(actual[0].item_line_no, 1);
  assert.equal(actual[0].source_raw_json['future-zero'], '0');
  assert.ok(!Object.hasOwn(actual[0].source_raw_json, ''));
  const minimal = comparable(generate([headers.slice(0, 5), row.slice(0, 5)]))[0];
  assert.equal(minimal.phone, '');
  assert.equal(minimal.item_sku, 'G_TEST');
});

test('unknown dictionary-like header names are preserved safely', () => {
  const actual = comparable(generate([[...headers, '__proto__', 'constructor'], [...row, 'stored', 'also stored']]))[0];
  assert.equal(actual.source_raw_json.__proto__, 'stored');
  assert.equal(actual.source_raw_json.constructor, 'also stored');
});

test('missing, empty and header-only input sheets keep the original no-data behavior', () => {
  for (const tables of [{}, { SHOPTET_RAW: [['']] }, { SHOPTET_RAW: [headers] }]) {
    const loaded = load(tables);
    loaded.context.generateDarkScrubImport();
    assert.equal(loaded.sheets.DARKSCRUB_IMPORT.data.length, 1);
    assert.equal(loaded.sheets.DARKSCRUB_IMPORT.data[0].length, 64);
  }
});

test('country lookup and output schema are preserved', () => {
  const actual = generate([[...headers, 'billCountryName'], [...row, 'Slovakia']], {
    COUNTRIES: [['Country', 'Two_Letters'], ['Slovakia', 'SK']]
  })[0];
  assert.equal(actual.bill_country, 'SK');
  assert.equal(Object.keys(actual).length, 64);
});

const ebayLegacy = {
  'Order number': 'EB-ALIAS-1',
  'Sale date': '2026-11-16',
  'Buyer name': 'Billing Customer',
  'Buyer postcode': '00123',
  'Buyer country': 'US',
  'Post to name': 'Shipping Recipient',
  'Post to phone': '+12020000000',
  'Post to address 1': '12 Example Street',
  'Post to address 2': 'Unit 4',
  'Post to city': 'Example City',
  'Post to postcode': '00501-0123',
  'Post to country': 'US',
  'Item title': 'Test Graphics',
  'Custom label': 'G_EBAY',
  'Quantity': '1',
  'Sold for': 'US $99.90',
  'Postage and packaging': 'US $14.90',
  'Delivery service': 'Example Shipping'
};
const ebayModernNames = {
  'Buyer postcode': 'Buyer Zip',
  'Post to name': 'Ship To Name',
  'Post to phone': 'Ship To Phone',
  'Post to address 1': 'Ship To Address 1',
  'Post to address 2': 'Ship To Address 2',
  'Post to city': 'Ship To City',
  'Post to postcode': 'Ship To Zip',
  'Post to country': 'Ship To Country',
  'Postage and packaging': 'Shipping And Handling',
  'Delivery service': 'Shipping Service'
};
const ebayModern = Object.fromEntries(Object.entries(ebayLegacy).map(([key, value]) => [
  ebayModernNames[key] || key.replace(/\b\w/g, letter => letter.toUpperCase()), value
]));
function generateEbay(record) {
  return comparable(generate([headers], { EBAY_RAW: [Object.keys(record), Object.values(record)] }))[0];
}

test('eBay Ship To / Zip export keeps phone, both postal codes and complete shipping address', () => {
  const actual = generateEbay(ebayModern);
  assert.equal(actual.phone, "'+12020000000");
  assert.equal(actual.bill_zip, '00123');
  assert.equal(actual.delivery_zip, '00501-0123');
  assert.equal(actual.delivery_name, 'Shipping Recipient');
  assert.equal(actual.delivery_street, '12 Example Street, Unit 4');
  assert.equal(actual.delivery_city, 'Example City');
  assert.equal(actual.delivery_country, 'US');
  assert.equal(actual.shipping_price, '14.90');
  assert.equal(actual.shipping_method, 'Example Shipping');
  assert.deepEqual(actual.source_raw_json, ebayModern);
});

test('eBay old and new header names produce the same mapped values', () => {
  const legacy = generateEbay(ebayLegacy);
  const modern = generateEbay(ebayModern);
  delete legacy.source_raw_json;
  delete modern.source_raw_json;
  assert.deepEqual(modern, legacy);
});

test('eBay falls back from blank legacy aliases and preserves non-empty legacy values', () => {
  const mixed = {
    ...ebayModern,
    'Post to phone': '  ',
    'Buyer postcode': '',
    'Post to postcode': '',
    'Post to name': 'Preferred Recipient',
    'Postage and packaging': '0'
  };
  const actual = generateEbay(mixed);
  assert.equal(actual.phone, "'+12020000000");
  assert.equal(actual.bill_zip, '00123');
  assert.equal(actual.delivery_zip, '00501-0123');
  assert.equal(actual.delivery_name, 'Preferred Recipient');
  assert.equal(actual.shipping_price, '0');
  assert.deepEqual(actual.source_raw_json, mixed);
});

test('eBay aliases tolerate reversed order, header case changes and new columns', () => {
  const shuffled = Object.fromEntries(Object.entries(ebayModern).reverse().map(([k,v]) => [k.toLowerCase(),v]));
  shuffled['future column'] = 'preserved';
  const actual = generateEbay(shuffled);
  const expected = generateEbay(ebayModern);
  assert.equal(actual.source_raw_json['future column'], 'preserved');
  delete actual.source_raw_json;
  delete expected.source_raw_json;
  assert.deepEqual(actual, expected);
});

test('eBay phone leading zeroes and alphanumeric postal codes remain text', () => {
  const actual = generateEbay({ ...ebayModern, 'Ship To Phone': '0612345678', 'Buyer Zip': 'SW1A 1AA', 'Ship To Zip': 'K1A 0B1' });
  assert.equal(actual.phone, "'0612345678");
  assert.equal(actual.bill_zip, 'SW1A 1AA');
  assert.equal(actual.delivery_zip, 'K1A 0B1');
});

test('eBay missing contact fields stay empty instead of using tax or item postal codes', () => {
  const actual = generateEbay({
    'Order Number': 'EB-NO-CONTACT',
    'Buyer Zip': '00123',
    'Item Zip Code': '99999',
    'Tax Zip': '88888'
  });
  assert.equal(actual.bill_zip, '00123');
  assert.equal(actual.delivery_zip, '');
  assert.equal(actual.phone, '');
  assert.equal(actual.delivery_street, '');
});

test('output contact columns receive text formatting before values are written', () => {
  const loaded = load({ EBAY_RAW: [Object.keys(ebayModern), Object.values(ebayModern)] });
  loaded.context.generateDarkScrubImport();
  const output = loaded.sheets.DARKSCRUB_IMPORT;
  assert.equal(output.writes.at(-1).type, 'values');
  const formats = output.writes.slice(0, -1);
  assert.equal(formats.length, 5);
  ['phone', 'bill_zip', 'delivery_zip', 'package_number', 'tracking_number'].forEach(header => {
    assert.ok(formats.some(f => f.type === 'format' && f.format === '@' && f.column === output.data[0].indexOf(header) + 1));
  });
});

function parseCsv(text) {
  const rows = [];
  let row = [], cell = '', quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (c === '"') {
      if (quoted && text[i + 1] === '"') { cell += '"'; i++; }
      else quoted = !quoted;
    } else if (c === ',' && !quoted) {
      row.push(cell); cell = '';
    } else if ((c === '\n' || c === '\r') && !quoted) {
      if (c === '\r' && text[i + 1] === '\n') i++;
      row.push(cell); rows.push(row); row = []; cell = '';
    } else cell += c;
  }
  assert.equal(quoted, false, 'CSV has unclosed quotes');
  if (row.length || cell) { row.push(cell); rows.push(row); }
  return rows;
}

const args = process.argv.slice(2);
const option = name => {
  const index = args.indexOf(name);
  return index >= 0 ? args[index + 1] : null;
};
const rawPath = option('--raw');
const expectedPath = option('--expected');
const originalPath = option('--original');

if (originalPath) {
  const original = fs.readFileSync(originalPath, 'utf8');
  test('eBay and MX Locker generation stays identical to the supplied original script', () => {
    const marketplaces = {
      EBAY_RAW: [
        ['Order number', 'Sale date', 'Item title', 'Quantity', 'Custom label', 'Sold for', 'Post to phone'],
        ['EB-1', '2026-11-12', 'Seat cover', '1', 'S_TEST', 'US $49.90', '+12020000000']
      ],
      MX_RAW: [
        ['Order Number', 'Sale Date', 'Item Title', 'Item Qty', 'Sku', 'Gross Selling Price', 'Postal Code', 'Item State'],
        ['MX-1', '2026-11-12', 'Graphics kit', '2', 'G_TEST', '99.90', '00123', 'completed']
      ]
    };
    assert.deepEqual(comparable(generate([headers, row], marketplaces)).slice(1),
      comparable(generate([headers, row], marketplaces, original)).slice(1));
  });
}

if (rawPath && expectedPath) {
  const raw = parseCsv(fs.readFileSync(rawPath, 'utf8'));
  const expectedTable = parseCsv(fs.readFileSync(expectedPath, 'utf8'));
  const expected = expectedTable.slice(1).map(r => Object.fromEntries(expectedTable[0].map((h, i) => [h, r[i]])));
  assert.ok(expected.every(r => r.source === 'SHOPTET'), 'Reference must contain SHOPTET rows only');
  raw.slice(1).forEach(r => assert.equal(r.length, raw[0].length));
  // COUNTRIES was not supplied. These mappings are taken from the expected CSV,
  // allowing this test to isolate header mapping from the unchanged lookup function.
  const countries = new Map();
  expected.forEach(r => {
    const src = JSON.parse(r.source_raw_json);
    countries.set(src.billCountryName, r.bill_country);
    countries.set(src.deliveryCountryName, r.delivery_country);
  });
  const extras = { COUNTRIES: [['Country', 'Two_Letters'], ...countries] };
  test('all supplied rows match the reference, with extra raw fields preserved', () => {
    const actual = generate(raw, extras);
    assert.equal(actual.length, expected.length);
    actual.forEach((r, i) => {
      Object.keys(expected[i]).forEach(key => {
        if (key === 'source_raw_json') {
          const newRaw = JSON.parse(r[key]);
          const oldRaw = JSON.parse(expected[i][key]);
          assert.deepEqual(Object.keys(newRaw).filter(k => !Object.hasOwn(oldRaw, k)).sort(),
            ['graphics-parts-versions', 'itemHsCode']);
          delete newRaw.itemHsCode;
          delete newRaw['graphics-parts-versions'];
          assert.deepEqual(newRaw, oldRaw, 'source_raw_json at data row ' + (i + 1));
        } else if (key === 'options_json') {
          assert.deepEqual(JSON.parse(r[key]), JSON.parse(expected[i][key]));
        } else {
          // Google Sheets exports the displayed phone without its leading text apostrophe.
          let actualValue = key === 'phone' ? String(r[key]).replace(/^'/, '') : String(r[key]);
          let expectedValue = expected[i][key];
          if (key === 'order_date') {
            // Sheets may display an hour without the leading zero (04:55 -> 4:55).
            const normalizeHour = value => value.replace(/ (\d):/, ' 0$1:');
            actualValue = normalizeHour(actualValue);
            expectedValue = normalizeHour(expectedValue);
          }
          assert.equal(actualValue, expectedValue, key + ' at data row ' + (i + 1));
        }
      });
    });
    assert.deepEqual(comparable(generate(raw.map(r => r.slice().reverse()), extras)), comparable(actual));
  });
  if (originalPath) {
    test('the supplied original already maps this complete raw CSV correctly by headers', () => {
      const original = comparable(generate(raw, extras, fs.readFileSync(originalPath, 'utf8')));
      assert.deepEqual(comparable(generate(raw, extras)), original);
    });
  }
}

const ebayPath = option('--ebay-unified');
if (ebayPath) {
  const table = parseCsv(fs.readFileSync(ebayPath, 'utf8'));
  const records = table.slice(1).map(values => Object.fromEntries(table[0].map((key,i) => [key, values[i]])));
  const ebayRows = records.filter(r => r.source === 'EBAY');
  assert.ok(ebayRows.length > 0, 'Expected eBay rows in reference export');
  const countryPairs = new Map(ebayRows.map(r => [JSON.parse(r.source_raw_json)['Buyer Country'], r.bill_country]));
  const rawRows = ebayRows.map(r => JSON.parse(r.source_raw_json));
  const rawHeaders = [...new Set(rawRows.flatMap(Object.keys))];
  const tables = {
    EBAY_RAW: [rawHeaders, ...rawRows.map(r => rawHeaders.map(key => r[key] ?? ''))],
    COUNTRIES: [['Country', 'Two_Letters'], ...countryPairs]
  };
  test('real eBay export recovers every phone, postal code and shipping address from raw JSON', () => {
    const mapped = generate([headers], tables);
    assert.equal(mapped.length, ebayRows.length);
    mapped.forEach((r, index) => {
      const raw = rawRows[index];
      assert.equal(r.external_order_id, ebayRows[index].external_order_id);
      assert.equal(r.phone, "'" + raw['Ship To Phone'].trim());
      assert.equal(r.bill_zip, raw['Buyer Zip'].trim());
      assert.equal(r.delivery_zip, raw['Ship To Zip'].trim());
      assert.equal(r.delivery_name, raw['Ship To Name'].trim());
      assert.equal(r.delivery_street, [raw['Ship To Address 1'], raw['Ship To Address 2']].map(v => (v || '').trim()).filter(Boolean).join(', '));
      assert.equal(r.delivery_city, raw['Ship To City'].trim());
      assert.equal(r.delivery_country, countryPairs.get(raw['Ship To Country']) || raw['Ship To Country']);
      assert.equal(r.shipping_method, raw['Shipping Service'].trim());
      assert.deepEqual(JSON.parse(r.source_raw_json), raw);
    });
    const orderCount = new Set(mapped.map(r => r.external_order_id)).size;
    console.log('Verified eBay contacts: ' + mapped.length + ' rows / ' + orderCount + ' orders.');
  });
  if (originalPath) {
    test('original script reproduces missing eBay phone and both postal codes in this export', () => {
      const old = generate([headers], tables, fs.readFileSync(originalPath, 'utf8'));
      assert.ok(old.every(r => r.phone === '' && r.bill_zip === '' && r.delivery_zip === ''));
    });
  }
}

console.log('Passed ' + passed + ' tests.');
