/**
 * DarkScrub unified order import generator
 *
 * Google Sheet tabs expected:
 * - SHOPTET_RAW
 * - EBAY_RAW
 * - MX_RAW
 * - DARKSCRUB_IMPORT
 *
 * Workflow:
 * 1. Replace RAW tab contents with complete CSV exports, INCLUDING the header row.
 *    Source columns are matched by header name; their order does not matter.
 *    Keep extra columns: source_raw_json preserves every named source field.
 * 2. Run generateDarkScrubImport().
 * 3. Export DARKSCRUB_IMPORT as CSV and upload it to ScrubProduction.
 */

const RAW_SHEETS = {
  SHOPTET: 'SHOPTET_RAW',
  EBAY: 'EBAY_RAW',
  MX_LOCKER: 'MX_RAW'
};

const OUTPUT_SHEET = 'DARKSCRUB_IMPORT';

const OUTPUT_HEADERS = [
  'source',
  'external_order_id',
  'order_date',
  'status',
  'currency',
  'exchange_rate',
  'email',
  'phone',
  'bill_name',
  'bill_company',
  'bill_street',
  'bill_house_number',
  'bill_city',
  'bill_zip',
  'bill_country',
  'bill_company_id',
  'bill_vat_id',
  'delivery_name',
  'delivery_company',
  'delivery_street',
  'delivery_house_number',
  'delivery_city',
  'delivery_zip',
  'delivery_country',
  'customer_ip',
  'customer_note',
  'internal_note',
  'package_number',
  'total_weight',
  'total_price_with_vat',
  'total_price_without_vat',
  'total_vat',
  'rounding',
  'price_to_pay',
  'amount_paid',
  'paid',
  'item_line_no',
  'item_name',
  'item_qty',
  'item_sku',
  'item_variant',
  'item_manufacturer',
  'item_unit',
  'item_weight',
  'item_status',
  'item_unit_price_with_vat',
  'item_unit_price_without_vat',
  'item_vat_rate',
  'item_total_price_with_vat',
  'item_total_price_without_vat',
  'item_ean',
  'item_plu',
  'item_supplier',
  'shipping_price',
  'shipping_method',
  'tracking_number',
  'payment_method',
  'sales_channel',
  'source_name',
  'category_info',
  'item_type_code',
  'custom_label',
  'options_json',
  'source_raw_json'
];

// Required header names, not required non-empty values in every row.
// Other fields are optional so shorter exports remain supported.
const SHOPTET_REQUIRED_HEADERS = ['code', 'date', 'itemName', 'itemAmount', 'itemCode'];

const SHOPTET_OPTION_FIELDS = [
  'Category Info',
  'stickers-gear-color',
  'graphics-parts-versions', // Preserve the selected graphics version for future use.
  'rim-tapes-color',
  'applyinggraphics',
  'spoke-coats-color',
  'enduro-pocket',
  'bike-mats-accent-color-4',
  'seat-cover',
  '4pcs-midfork-size',
  '4pcs-midfork-brand-logo',
  'mid-forks-color',
  'name',
  'name-color',
  'name-font',
  'side-brand-patches',
  'base-material',
  'stand-model',
  'graphics-finish',
  'waterproof-seams',
  'grip',
  'number-plate-color',
  'draft',
  'mid-forks',
  'rim-tapes-size-2',
  'mid-forks-size',
  'stickers-number-plate-color',
  'patch-style',
  'stickers-number-color',
  'bike-mats-accent-color-2',
  'bike-mats-accent-color-3',
  'bike-mats-accent-color-1',
  'number',
  'number-color',
  'number-font',
  'stickers-motorcycle-brand',
  'note',
  'file'
];

function onOpen() {
  const ui = SpreadsheetApp.getUi();

  ui.createMenu('Scrub Scripts')
    .addItem('Generate unified import', 'generateDarkScrubImport')
    .addItem('Vytvoriť MXLocker poznámky', 'createMXLockerNotesSheet')
    .addToUi();
}

function generateDarkScrubImport() {
  const ss = SpreadsheetApp.getActive();
  const rows = [];
  rows.push(OUTPUT_HEADERS);

  const countryLookup = getCountryLookup_();

  appendRows_(rows, readObjectsFromSheet_(ss, RAW_SHEETS.SHOPTET, SHOPTET_REQUIRED_HEADERS), (obj, i) => mapShoptetRow_(obj, i, countryLookup));
  appendRows_(rows, readObjectsFromSheet_(ss, RAW_SHEETS.EBAY), (obj, i) => mapEbayRow_(obj, i, countryLookup));
  appendRows_(rows, readObjectsFromSheet_(ss, RAW_SHEETS.MX_LOCKER), (obj, i) => mapMxLockerRow_(obj, i, countryLookup));

  // Validate and map all input first. A malformed header must not erase the last import.
  const output = getOrCreateSheet_(ss, OUTPUT_SHEET);
  output.clearContents();

  if (rows.length > 1) {
    output.getRange(1, 1, rows.length, OUTPUT_HEADERS.length).setValues(rows);
  } else {
    output.getRange(1, 1, 1, OUTPUT_HEADERS.length).setValues([OUTPUT_HEADERS]);
  }
const textColumns = [
  'phone',
  'bill_zip',
  'delivery_zip',
  'package_number',
  'tracking_number'
];

textColumns.forEach(header => {
  const col = OUTPUT_HEADERS.indexOf(header) + 1;
  if (col > 0) {
    output.getRange(1, col, Math.max(rows.length, 1), 1).setNumberFormat('@');
  }
});
  output.setFrozenRows(1);
  output.autoResizeColumns(1, OUTPUT_HEADERS.length);
}
function textCell_(value) {
  let v = String(value || '').trim();

  // odstráni =, apostrofy a medzery na začiatku
  v = v.replace(/^[='\s]+/, '');

  if (!v) return '';

  return "'" + v;
}
function appendRows_(targetRows, objects, mapper) {
  objects.forEach((obj, index) => {
    const mapped = mapper(obj, index + 1);
    if (!mapped) return;
    targetRows.push(OUTPUT_HEADERS.map(header => mapped[header] ?? ''));
  });
}

function readObjectsFromSheet_(ss, sheetName, requiredHeaders) {
  const sheet = ss.getSheetByName(sheetName);
  if (!sheet) return [];

  const values = sheet.getDataRange().getDisplayValues();
  if (values.length < 2) return [];

  const headers = values[0].map(cleanHeader_);
  const headerIndex = Object.create(null);
  headers.forEach((header, index) => {
    if (!header) return; // An entirely empty column is harmless.
    const key = header.toLowerCase();
    if (Object.prototype.hasOwnProperty.call(headerIndex, key)) {
      throw new Error(
        'Hárok ' + sheetName + ': duplicitná hlavička "' + header +
        '" v stĺpcoch ' + (headerIndex[key] + 1) + ' a ' + (index + 1) +
        '. Každý stĺpec musí mať jednoznačný názov.'
      );
    }
    headerIndex[key] = index;
  });

  const missing = (requiredHeaders || []).filter(
    header => !Object.prototype.hasOwnProperty.call(headerIndex, cleanHeader_(header).toLowerCase())
  );
  if (missing.length) {
    throw new Error(
      'Hárok ' + sheetName + ': chýbajú povinné hlavičky: ' + missing.join(', ') +
      '. Importuj celý CSV vrátane prvého riadka a skontroluj oddeľovač stĺpcov.'
    );
  }

  const objects = [];
  values.slice(1).forEach((row, rowIndex) => {
    if (!row.some(cell => String(cell ?? '').trim() !== '')) return;

    row.forEach((cell, columnIndex) => {
      if (!headers[columnIndex] && String(cell ?? '').trim() !== '') {
        throw new Error(
          'Hárok ' + sheetName + ': riadok ' + (rowIndex + 2) +
          ', stĺpec ' + (columnIndex + 1) + ' obsahuje údaj bez hlavičky.' +
          ' Nahraď obsah RAW hárka celým CSV vrátane aktuálnej hlavičky.'
        );
      }
    });

    // A dictionary keeps even previously unknown fields (including "__proto__").
    // Only the header determines meaning; no fixed source column numbers are used.
    const obj = Object.create(null);
    headers.forEach((header, index) => {
      if (header) obj[header] = row[index] ?? '';
    });
    objects.push(obj);
  });
  return objects;
}

function mapShoptetRow_(r, lineNo, countryLookup) {
  const sku = val_(r, 'itemCode');
  const itemName = val_(r, 'itemName');
  const categoryInfo = val_(r, 'Category Info');
  const options = pickNonEmpty_(r, SHOPTET_OPTION_FIELDS);

  return {
    source: 'SHOPTET',
    external_order_id: val_(r, 'code'),
    order_date: val_(r, 'date'),
    status: val_(r, 'statusName'),
    currency: val_(r, 'currency'),
    exchange_rate: val_(r, 'exchangeRate'),
    email: val_(r, 'email'),
    phone: textCell_(val_(r, 'phone')),
    bill_name: val_(r, 'billFullName'),
    bill_company: val_(r, 'billCompany'),
    bill_street: val_(r, 'billStreet'),
    bill_house_number: val_(r, 'billHouseNumber'),
    bill_city: val_(r, 'billCity'),
    bill_zip: val_(r, 'billZip'),
    bill_country: normalizeCountry_(val_(r, 'billCountryName'), countryLookup),
    bill_company_id: val_(r, 'companyId'),
    bill_vat_id: val_(r, 'vatId'),
    delivery_name: val_(r, 'deliveryFullName'),
    delivery_company: val_(r, 'deliveryCompany'),
    delivery_street: val_(r, 'deliveryStreet'),
    delivery_house_number: val_(r, 'deliveryHouseNumber'),
    delivery_city: val_(r, 'deliveryCity'),
    delivery_zip: val_(r, 'deliveryZip'),
    delivery_country: normalizeCountry_(val_(r, 'deliveryCountryName'), countryLookup),
    customer_ip: val_(r, 'customerIpAddress'),
    customer_note: val_(r, 'remark'),
    internal_note: val_(r, 'shopRemark'),
    package_number: val_(r, 'packageNumber'),
    total_weight: val_(r, 'weight'),
    total_price_with_vat: val_(r, 'totalPriceWithVat'),
    total_price_without_vat: val_(r, 'totalPriceWithoutVat'),
    total_vat: val_(r, 'totalPriceVat'),
    rounding: val_(r, 'rounding'),
    price_to_pay: val_(r, 'priceToPay'),
    amount_paid: val_(r, 'amountPaid'),
    paid: val_(r, 'paid'),
    item_line_no: lineNo,
    item_name: itemName,
    item_qty: val_(r, 'itemAmount'),
    item_sku: sku,
    item_variant: val_(r, 'itemVariantName'),
    item_manufacturer: val_(r, 'itemManufacturer'),
    item_unit: val_(r, 'itemUnit'),
    item_weight: val_(r, 'itemWeight'),
    item_status: val_(r, 'itemStatusName'),
    item_unit_price_with_vat: val_(r, 'itemUnitPriceWithVat'),
    item_unit_price_without_vat: val_(r, 'itemUnitPriceWithoutVat'),
    item_vat_rate: val_(r, 'itemVatRate'),
    item_total_price_with_vat: val_(r, 'itemTotalPriceWithVat'),
    item_total_price_without_vat: val_(r, 'itemTotalPriceWithoutVat'),
    item_ean: val_(r, 'itemEan'),
    item_plu: val_(r, 'itemPlu'),
    item_supplier: val_(r, 'itemSupplier'),
    shipping_price: '',
    shipping_method: '',
    tracking_number: val_(r, 'packageNumber'),
    payment_method: '',
    sales_channel: val_(r, 'salesChannelName'),
    source_name: val_(r, 'sourceName'),
    category_info: categoryInfo,
    item_type_code: detectItemType_(sku, itemName, categoryInfo),
    custom_label: sku,
    options_json: json_(options),
    source_raw_json: json_(r)
  };
}

function mapEbayRow_(r, lineNo, countryLookup) {
  const sku = val_(r, 'Custom label');
  const itemName = val_(r, 'Item title');
  const variation = val_(r, 'Variation details');
  const options = pickNonEmpty_(r, [
    'Buyer note',
    'My item note',
    'Variation details',
    'Item number',
    'Transaction ID',
    'Sales record number',
    'Tax status',
    'Included VAT rate',
    'eBay collected tax',
    'Seller collected tax',
    'Minimum estimated delivery date',
    'Maximum estimated delivery date',
    'Post by date',
    'Dispatched on date'
  ]);

  return {
    source: 'EBAY',
    external_order_id: val_(r, 'Order number'),
    order_date: val_(r, 'Sale date'),
    status: val_(r, 'Tax status') || 'imported',
    currency: detectCurrency_(val_(r, 'Sold for')),
    exchange_rate: '',
    email: val_(r, 'Buyer email'),
   phone: textCell_(val_(r, 'Post to phone')),
    bill_name: val_(r, 'Buyer name'),
    bill_company: '',
    bill_street: val_(r, 'Buyer address 1'),
    bill_house_number: '',
    bill_city: val_(r, 'Buyer city'),
    bill_zip: val_(r, 'Buyer postcode'),
   bill_country: normalizeCountry_(val_(r, 'Buyer country'), countryLookup),
    bill_company_id: '',
    bill_vat_id: val_(r, 'Buyer tax identifier value'),
    delivery_name: val_(r, 'Post to name'),
    delivery_company: '',
    delivery_street: joinNonEmpty_([val_(r, 'Post to address 1'), val_(r, 'Post to address 2')], ', '),
    delivery_house_number: '',
    delivery_city: val_(r, 'Post to city'),
    delivery_zip: val_(r, 'Post to postcode'),
    delivery_country: normalizeCountry_(val_(r, 'Post to country'), countryLookup),
    customer_ip: '',
    customer_note: val_(r, 'Buyer note'),
    internal_note: val_(r, 'My item note'),
    package_number: '',
    total_weight: '',
    total_price_with_vat: moneyNumber_(val_(r, 'Sold for')),
    total_price_without_vat: '',
    total_vat: moneyNumber_(val_(r, 'eBay collected tax')),
    rounding: '',
    price_to_pay: moneyNumber_(val_(r, 'Sold for')),
    amount_paid: val_(r, 'Paid on date') ? moneyNumber_(val_(r, 'Sold for')) : '',
    paid: val_(r, 'Paid on date') ? '1' : '0',
    item_line_no: lineNo,
    item_name: itemName,
    item_qty: val_(r, 'Quantity'),
    item_sku: sku,
    item_variant: variation,
    item_manufacturer: '',
    item_unit: 'pcs',
    item_weight: '',
    item_status: '',
    item_unit_price_with_vat: moneyNumber_(val_(r, 'Sold for')),
    item_unit_price_without_vat: '',
    item_vat_rate: val_(r, 'Included VAT rate'),
    item_total_price_with_vat: moneyNumber_(val_(r, 'Sold for')),
    item_total_price_without_vat: '',
    item_ean: '',
    item_plu: '',
    item_supplier: '',
    shipping_price: moneyNumber_(val_(r, 'Postage and packaging')),
    shipping_method: val_(r, 'Delivery service'),
    tracking_number: val_(r, 'Tracking number'),
    payment_method: val_(r, 'Payment method'),
    sales_channel: 'eBay',
    source_name: 'eBay',
    category_info: variation,
    item_type_code: detectItemType_(sku, itemName, variation),
    custom_label: sku,
    options_json: json_(options),
    source_raw_json: json_(r)
  };
}

function mapMxLockerRow_(r, lineNo, countryLookup) {
  const sku = val_(r, 'Sku');
  const itemName = val_(r, 'Item Title');
  const state = val_(r, 'Item State');
  const options = pickNonEmpty_(r, [
    'Processing',
    'Tax',
    'Tax Remitted',
    'Mx Locker Fee',
    'Expedited Shipping',
    'State Province'
  ]);

  return {
    source: 'MX_LOCKER',
    external_order_id: val_(r, 'Order Number'),
    order_date: val_(r, 'Sale Date'),
    status: state,
    currency: '',
    exchange_rate: '',
    email: val_(r, 'Buyer Email'),
    phone: textCell_(val_(r, 'Customer Phone Number')),
    bill_name: val_(r, 'Buyer Name'),
    bill_company: '',
    bill_street: val_(r, 'Address Line One'),
    bill_house_number: '',
    bill_city: val_(r, 'City'),
    bill_zip: val_(r, 'Postal Code'),
    bill_country: normalizeCountry_(val_(r, 'Country'), countryLookup),
    bill_company_id: '',
    bill_vat_id: '',
    delivery_name: val_(r, 'Buyer Name'),
    delivery_company: '',
    delivery_street: joinNonEmpty_([val_(r, 'Address Line One'), val_(r, 'Address Line Two')], ', '),
    delivery_house_number: '',
    delivery_city: val_(r, 'City'),
    delivery_zip: val_(r, 'Postal Code'),
    delivery_country: normalizeCountry_(val_(r, 'Country'), countryLookup),
    customer_ip: '',
    customer_note: '',
    internal_note: '',
    package_number: '',
    total_weight: '',
    total_price_with_vat: val_(r, 'Gross Selling Price'),
    total_price_without_vat: '',
    total_vat: val_(r, 'Tax'),
    rounding: '',
    price_to_pay: val_(r, 'Gross Selling Price'),
    amount_paid: val_(r, 'Gross Selling Price'),
    paid: state.toLowerCase() === 'completed' ? '1' : '',
    item_line_no: lineNo,
    item_name: itemName,
    item_qty: val_(r, 'Item Qty'),
    item_sku: sku,
    item_variant: '',
    item_manufacturer: '',
    item_unit: 'pcs',
    item_weight: '',
    item_status: state,
    item_unit_price_with_vat: val_(r, 'Gross Selling Price'),
    item_unit_price_without_vat: '',
    item_vat_rate: '',
    item_total_price_with_vat: val_(r, 'Gross Selling Price'),
    item_total_price_without_vat: '',
    item_ean: '',
    item_plu: '',
    item_supplier: '',
    shipping_price: val_(r, 'Shipping Price'),
    shipping_method: val_(r, 'Expedited Shipping') ? 'Expedited' : '',
    tracking_number: '',
    payment_method: '',
    sales_channel: 'MX Locker',
    source_name: 'MX Locker',
    category_info: '',
    item_type_code: detectItemType_(sku, itemName, ''),
    custom_label: sku,
    options_json: json_(options),
    source_raw_json: json_(r)
  };
}

function detectItemType_(sku, title, categoryInfo) {
  const haystack = `${sku || ''} ${title || ''} ${categoryInfo || ''}`.toLowerCase();
  const code = String(sku || '').trim().toUpperCase();

  if (code.startsWith('G_') || code.startsWith('G ')) return 'G';
  if (code.startsWith('P_') || code.startsWith('P ')) return 'P';
  if (code.startsWith('S_') || haystack.includes('seat cover') || haystack.includes('seatcover')) return 'S';
  if (code.startsWith('T_') || haystack.includes('trim kit')) return 'T';
  if (code.startsWith('M_') || haystack.includes('bike mat') || haystack.includes('bike-mat')) return 'M';
  if (code.startsWith('F_') || haystack.includes('fitting')) return 'F';

  return '';
}

function pickNonEmpty_(obj, keys) {
  const out = {};
  keys.forEach(key => {
    const value = val_(obj, key);
    if (value !== '') out[key] = value;
  });
  return out;
}

function json_(value) {
  return JSON.stringify(value || {});
}

function val_(obj, key) {
  if (!obj) return '';
  if (Object.prototype.hasOwnProperty.call(obj, key)) {
    return String(obj[key] ?? '').trim();
  }

  const wanted = cleanHeader_(key).toLowerCase();
  const foundKey = Object.keys(obj).find(k => cleanHeader_(k).toLowerCase() === wanted);
  return foundKey ? String(obj[foundKey] ?? '').trim() : '';
}

function cleanHeader_(header) {
  return String(header ?? '')
    .replace(/\uFEFF/g, '')
    .replace(/\u00A0/g, ' ')
    .trim()
    .replace(/^"+|"+$/g, '')
    .replace(/""/g, '"')
    .trim();
}

function joinNonEmpty_(values, separator) {
  return values.map(v => String(v || '').trim()).filter(Boolean).join(separator);
}

function detectCurrency_(moneyText) {
  const value = String(moneyText || '').trim();
  if (value.includes('US $')) return 'USD';
  if (value.includes('€')) return 'EUR';
  if (value.includes('£')) return 'GBP';
  return '';
}

function moneyNumber_(moneyText) {
  let value = String(moneyText || '').trim();
  value = value.replace(/US \$/g, '').replace(/[€£$]/g, '').replace(/\s/g, '').replace(',', '.');
  return value;
}

function getOrCreateSheet_(ss, name) {
  return ss.getSheetByName(name) || ss.insertSheet(name);
}

function getCountryLookup_() {
  const ss = SpreadsheetApp.getActive();
  const sheet = ss.getSheetByName('COUNTRIES');
  if (!sheet) return {};

  const values = sheet.getDataRange().getDisplayValues();
  if (values.length < 2) return {};

  const headers = values[0].map(h => cleanHeader_(h));
  const countryIdx = headers.indexOf('Country');
  const twoIdx = headers.indexOf('Two_Letters');

  const lookup = {};

  values.slice(1).forEach(row => {
    const country = String(row[countryIdx] || '').trim();
    const code = String(row[twoIdx] || '').trim().toUpperCase();

    if (!country || !code) return;

    lookup[normalizeCountryKey_(country)] = code;
    lookup[normalizeCountryKey_(code)] = code;
  });

  lookup['uk'] = 'GB';
  lookup['great britain'] = 'GB';
  lookup['united kingdom'] = 'GB';
  lookup['usa'] = 'US';
  lookup['united states of america'] = 'US';
  lookup['um'] = 'US';
  lookup['kx'] = 'XK';
  lookup['kosovo'] = 'XK';

  return lookup;
}

function normalizeCountryKey_(value) {
  return String(value || '')
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\./g, '')
    .replace(/\s+/g, ' ');
}

function normalizeCountry_(value, lookup) {
  const raw = String(value || '').trim();
  if (!raw) return '';

  // 🔥 1. AK JE TO UŽ 2-LETTER CODE → NECHAJ TAK
  if (/^[A-Za-z]{2}$/.test(raw)) {
    return raw.toUpperCase();
  }

  const key = normalizeCountryKey_(raw);

  // 🔥 2. lookup (Shoptet / eBay názvy)
  if (lookup && lookup[key]) {
    return lookup[key];
  }

  // fallback
  return raw;
}
/* poznámky pre MXLOcker */

function createMXLockerNotesSheet() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const outputName = 'MXLocker Notes';

  const active = ss.getActiveSheet();

  if (active.getName() === outputName) {
    throw new Error('Najprv klikni na pôvodný importovaný sheet, nie na MXLocker Notes.');
  }

  const source = active;

  let output = ss.getSheetByName(outputName);
  if (output) ss.deleteSheet(output);

  output = ss.insertSheet(outputName);

  const data = source.getDataRange().getValues();
  const headers = data[0].map(String);
  const rows = data.slice(1);

  const col = name => headers.indexOf(name);

  const values = [];
  const notes = [];

  rows.forEach(row => {
    const buyerName = get(row, col('Buyer Name'));
    if (!buyerName) return;

    values.push([buyerName]);
    notes.push([buildMXLockerNote(row, col)]);
  });

  output.getRange(1, 1).setValue('Buyer Name');

  if (values.length > 0) {
    output.getRange(2, 1, values.length, 1).setValues(values);
    output.getRange(2, 1, notes.length, 1).setNotes(notes);
  }

  output.setFrozenRows(1);
  output.autoResizeColumn(1);
}

function buildMXLockerNote(row, col) {
  const email = get(row, col('Buyer Email'));
  const name = get(row, col('Buyer Name'));
  const address1 = get(row, col('Address Line One'));
  const address2 = get(row, col('Address Line Two'));
  const city = get(row, col('City'));
  const postal = get(row, col('Postal Code'));
  const country = countryName(get(row, col('Country')));
  const state = get(row, col('State Province'));
  const phone = get(row, col('Customer Phone Number'));
  const orderNo = get(row, col('Order Number'));
  const gross = toNumber(get(row, col('Gross Selling Price')));
  const shipping = toNumber(get(row, col('Shipping Price')));
  const title = get(row, col('Item Title'));
  const sku = get(row, col('Sku'));
  const saleDate = formatDate(get(row, col('Sale Date')));

  const amounts = splitAmounts(title, sku, gross, shipping);

  const lines = [
  email,
  name,
  address1,
  address2,
  city,
  postal ? "'" + postal : '',
  country,
  state || 'Country Code',
  phone ? "'" + phone : '',
  'Economy',
  'Company',
  orderNo ? "'" + orderNo : '',
  'Invoice',
  formatNumber(gross),
  'R',
  'Insurance (Value)',
  'Payment Deposit: BA ' + saleDate,
  'Payment Total: BA ' + saleDate,
  'Deposit:0 EUR',
  amounts.graphics ? 'Graphics kit:' + moneyOrBlank(amounts.graphics) : '',
  amounts.plastics ? 'Plastics kit:' + moneyOrBlank(amounts.plastics) : '',
  'Applying graphics:',
  'Seat cover:',
  'Mid forks:',
  'Rim tapes:',
  'Spoke coats:',
  amounts.other ? 'Other:' + moneyOrBlank(amounts.other) : '',
  shipping ? 'Shipping:' + moneyOrBlank(shipping) : '',
  'TOTAL:' + formatNumber(gross) + ' EUR',
  'Deposit 2:',
  'VAT:'
];

// 🔥 toto odstráni prázdne riadky
return lines.filter(line => line && line.toString().trim() !== '').join('\n');
}

function splitAmounts(title, sku, gross, shipping) {
  const base = Math.max(gross - shipping, 0);
  const t = String(title || '').toLowerCase();
  const s = String(sku || '').toLowerCase();

  if (s.startsWith('p_') || t.includes('plastics kit') || t.includes('plastics')) {
    return { graphics: '', plastics: base, other: '' };
  }

  if (t.includes('graphics')) {
    return { graphics: base, plastics: '', other: '' };
  }

  return { graphics: '', plastics: '', other: base };
}

function get(row, index) {
  return index >= 0 && row[index] !== null && row[index] !== undefined
    ? String(row[index]).trim()
    : '';
}

function toNumber(value) {
  if (!value) return 0;
  return Number(String(value).replace(',', '.')) || 0;
}

function formatNumber(value) {
  return Number(value).toFixed(2).replace(/\.00$/, '').replace('.', ',');
}

function moneyOrBlank(value) {
  return value ? formatNumber(value) + ' EUR' : '';
}

function formatDate(value) {
  if (!value) return '';

  // ak je to už Date objekt
  if (value instanceof Date) {
    return Utilities.formatDate(value, Session.getScriptTimeZone(), 'dd.MM.yyyy');
  }

  // ak je to string → pokús sa ho parsovať
  const parsed = new Date(value);
  if (!isNaN(parsed)) {
    return Utilities.formatDate(parsed, Session.getScriptTimeZone(), 'dd.MM.yyyy');
  }

  return ''; // fallback
}

function countryName(code) {
  const map = {
    US: 'United States',
    AT: 'Austria',
    CA: 'Canada',
    GB: 'United Kingdom',
    DE: 'Germany'
  };
  return map[String(code).toUpperCase()] || code;
}
