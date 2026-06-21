<?php
declare(strict_types=1);

require_once __DIR__ . '/conn.php';
require_once dirname(__DIR__) . '/scripts/custom_orders/helpers.php';

$flash = customOrdersTakeFlash();
$statuses = customOrdersOrderStatuses();
$allowedTypes = customOrdersAllowedItemTypes();
$paymentKinds = customOrdersPaymentKinds();
$assignableEmployees = customOrdersAssignableEmployees($conn);
$invalidFields = [];
if (is_array($flash['meta']['invalid_fields'] ?? null)) {
  $invalidFields = array_fill_keys($flash['meta']['invalid_fields'], true);
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
$query = trim((string) ($_GET['q'] ?? ''));
$selectedOrderId = (int) ($_GET['custom_order_id'] ?? 0);
$editItemId = (int) ($_GET['edit_item_id'] ?? 0);

$listRows = [];
$selectedOrder = null;
$editItem = null;
$moduleLoadError = null;

$where = [];
$sequences = ['SO' => 0, 'GO' => 0, 'SC' => 0];
$suggestions = [];

try {
  if ($statusFilter !== '' && isset($statuses[$statusFilter])) {
    $where[] = "co.status = '" . $conn->real_escape_string($statusFilter) . "'";
  }
  if ($query !== '') {
    $safe = '%' . $conn->real_escape_string($query) . '%';
    $where[] = "(co.internal_code LIKE '$safe' OR co.official_order_number LIKE '$safe' OR co.customer_name LIKE '$safe' OR co.social_handle LIKE '$safe')";
  }

  $sql = "
    SELECT
      co.*,
      TRIM(CONCAT_WS(' ', eo.firstname, eo.lastname)) AS owner_name,
      COUNT(DISTINCT coi.id) AS item_count,
      SUM(CASE WHEN coi.is_upsell = 1 THEN coi.qty * coi.unit_price ELSE 0 END) AS upsell_total,
      SUM(CASE WHEN cop.payment_kind IN ('DEPOSIT', 'EXTRA_DEPOSIT') THEN cop.amount ELSE 0 END) AS deposit_total
    FROM custom_orders co
    LEFT JOIN employees eo ON eo.id = co.owner_employee_id
    LEFT JOIN custom_order_items coi ON coi.custom_order_id = co.id
    LEFT JOIN custom_order_payments cop ON cop.custom_order_id = co.id
  ";
  if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
  }
  $sql .= ' GROUP BY co.id ORDER BY co.updated_at DESC, co.id DESC LIMIT 300';
  $res = $conn->query($sql);
  if (!$res) {
    throw new RuntimeException('Custom orders list query failed: ' . $conn->error);
  }
  while ($row = $res->fetch_assoc()) {
    $listRows[] = $row;
  }

  if ($selectedOrderId <= 0 && !empty($listRows)) {
    $selectedOrderId = (int) $listRows[0]['id'];
  }

  $selectedOrder = $selectedOrderId > 0 ? customOrdersGetOrder($conn, $selectedOrderId) : null;
  if ($selectedOrder && $editItemId > 0) {
    foreach ($selectedOrder['items'] as $item) {
      if ((int) $item['id'] === $editItemId) {
        $editItem = $item;
        break;
      }
    }
  }

  $res = $conn->query('SELECT prefix_code, current_value FROM custom_order_number_sequences');
  if (!$res) {
    throw new RuntimeException('Custom order sequences query failed: ' . $conn->error);
  }
  while ($row = $res->fetch_assoc()) {
    $sequences[(string) $row['prefix_code']] = (int) $row['current_value'];
  }

  $res = $conn->query("
    SELECT display_name AS name, social_handle, email, phone, country
    FROM custom_order_contacts
    ORDER BY last_used_at DESC, updated_at DESC
    LIMIT 250
  ");
  if (!$res) {
    throw new RuntimeException('Custom order contacts query failed: ' . $conn->error);
  }
  while ($row = $res->fetch_assoc()) {
    $suggestions[] = $row;
  }

  $res = $conn->query("
    SELECT name, '' AS social_handle, email, phone, '' AS country
    FROM customers
    ORDER BY created_at DESC
    LIMIT 150
  ");
  if (!$res) {
    throw new RuntimeException('Customers suggestion query failed: ' . $conn->error);
  }
  while ($row = $res->fetch_assoc()) {
    $suggestions[] = $row;
  }
} catch (Throwable $e) {
  $moduleLoadError = $e->getMessage();
}

function h($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function selectedText(array $map, string $key): string
{
  return $map[$key] ?? $key;
}

function customOrderResolveHelpLanguage(): string
{
  $allowed = ['sk', 'en'];

  if (isset($_GET['help_lang'])) {
    $requested = strtolower(trim((string) $_GET['help_lang']));
    if (in_array($requested, $allowed, true)) {
      $_SESSION['custom_orders_help_lang'] = $requested;
      return $requested;
    }
  }

  $sessionLang = strtolower(trim((string) ($_SESSION['custom_orders_help_lang'] ?? '')));
  if (in_array($sessionLang, $allowed, true)) {
    return $sessionLang;
  }

  return 'sk';
}

function customOrderHelpMap(string $lang = 'sk'): array
{
  $mapSk = [
    'search' => 'Hladaj podla internal code, official order number, mena zakaznika alebo social handle.',
    'status_filter' => 'Filtrovanie leadov podla pipeline stavu.',
    'seq_so' => 'Nastav posledne pouzite SO cislo. Dalsia SO objednavka dostane nasledujuce cislo.',
    'seq_go' => 'Nastav posledne pouzite GO cislo pre GrenzGaenger objednavky.',
    'seq_sc' => 'Nastav posledne pouzite SC cislo pre seat cover objednavky.',
    'owner' => 'Customer service clovek, ktory lead aktivne riesi a komunikuje so zakaznikom.',
    'official_prefix' => 'SO pre Scrub custom, GO pre GrenzGaenger, SC pre seat cover custom.',
    'status' => 'Lead = novy kontakt, Deposit Pending = cakame na deposit, Deposit Paid/In Progress = aktivne doplname, Ready To Export = pripraveny, Exported = uz preklopene.',
    'complexity_level' => 'Interna narocnost 1 az 10. Vyssie cislo = viac detailov, viac produktov, komplikovanejsia komunikacia.',
    'source_channel' => 'Odkial prisiel kontakt. Drzte sa konzistentnych nazvov ako Instagram, WhatsApp, Messenger, Facebook, TikTok, Email.',
    'social_platform' => 'Platforma, cez ktoru prebieha komunikacia.',
    'social_handle' => 'Nick alebo identifikator zakaznika na socialnej platforme.',
    'customer_name' => 'Realne meno zakaznika, ak je zname. Pri zalozeni leadu moze ostat prazdne.',
    'customer_email' => 'Email zakaznika. Pred exportom musi byt vyplneny email alebo telefon.',
    'customer_phone' => 'Telefon zakaznika. Pred exportom musi byt vyplneny email alebo telefon.',
    'customer_country' => 'Pouzivaj 2-letter kod krajiny, napr. DE, FR, US, CA, GB.',
    'bike_brand' => 'Znacka motorky, napr. KTM, Husqvarna, Yamaha.',
    'bike_model' => 'Model motorky, napr. SX 250, TE 300, CRF 450R.',
    'bike_year' => 'Rocnik motorky. Ak treba, dopln presnejsi popis do Bike details.',
    'bike_details' => 'Dolezite doplnky k motorke: restyle plasty, specialna generacia, netypicky fitment, cierny ram a podobne.',
    'rider_name' => 'Meno na grafike, ak sa pouziva.',
    'rider_number' => 'Race number / startovne cislo na grafike.',
    'currency' => 'Odporucane 3-letter kody meny: EUR, USD, GBP.',
    'shipping_name' => 'Meno prijemcu. Povinne pre export do production orders.',
    'shipping_company' => 'Volitelna firma prijemcu.',
    'shipping_street' => 'Ulica a cislo. Povinne pre export.',
    'shipping_city' => 'Mesto prijemcu. Povinne pre export.',
    'shipping_zip' => 'PSC / ZIP. Povinne pre export.',
    'shipping_country' => '2-letter kod krajiny prijemcu. Povinne pre export.',
    'shipping_email' => 'Volitelny email pre dorucenie, odporucany.',
    'shipping_phone' => 'Volitelny telefon pre dorucenie, odporucany.',
    'shipping_method' => 'Sposob dopravy, napr. FedEx Economy, FedEx Express, DHL.',
    'shipping_price' => 'Cena dopravy bez meny, napr. 14.90.',
    'deposit_revision_limit' => 'Kolko uprav dizajnu je zahrnutych v aktualnom deposite. Standardne 3.',
    'deposit_revision_used' => 'Kolko uprav uz zakaznik minul. Pri prekroceni treba dalsi extra deposit.',
    'last_contact_at' => 'Kedy prebehla posledna komunikacia so zakaznikom.',
    'next_followup_at' => 'Kedy sa ma support k leadu znovu vratit.',
    'dead_order_flag' => 'Oznac lead ako mrtvy, ak neodpoveda alebo z neho realne nebude objednavka.',
    'graphics_brief' => 'Ludsky citatelny brief pre grafika. Co chce zakaznik, styl, farby, smer dizajnu.',
    'bike_photo_urls' => 'Linky na fotky motorky. Idealne jeden link na riadok.',
    'reference_urls' => 'Inspiracie, screenshoty, cloud folder, predosle designy. Jeden zaznam na riadok.',
    'customer_notes' => 'Dolezite dohodnute body, ktore maju zmysel aj po exporte.',
    'internal_notes' => 'Interne poznamky teamu, co nemusite tahat do production poznamky.',
    'payment_kind' => 'DEPOSIT = prvy deposit, EXTRA_DEPOSIT = dalsi deposit, BALANCE = doplatok, REFUND = vratka.',
    'paypal_transaction_id' => 'Presne PayPal transaction ID pre spatne dohladanie platby.',
    'payment_amount' => 'Prijata alebo vracana suma bez meny.',
    'payment_currency' => 'Mena danej platby, najcastejsie EUR.',
    'payment_received_at' => 'Datum a cas prijatia platby.',
    'payment_note' => 'Krátka poznamka, napr. first design deposit alebo extra deposit after 3 revisions.',
    'followup_contacted_at' => 'Datum a cas kontaktovania zakaznika.',
    'followup_channel' => 'Kanal komunikacie, napr. Instagram, WhatsApp, Messenger, Email.',
    'followup_note' => 'Co ste od zakaznika pytali alebo co ste vyriesili.',
    'item_type_code' => 'G = graphics, P = plastics, S = seat cover, F = fitting, T = accessories, M = misc.',
    'item_sku' => 'Produktove oznacenie, ak je zname. Inak moze ostat MANUAL.',
    'item_qty' => 'Pocet kusov. Minimum je 1.',
    'item_unit_price' => 'Cena za 1 kus bez meny.',
    'item_title' => 'Najdolezitejsi nazov polozky. Musi byt jasne, co sa realne predava.',
    'item_custom_label' => 'Volitelny doplnkovy interny alebo customer-facing nazov.',
    'item_category_info' => 'Kompatibilita / orientacia, napr. KTM | SX 125 | 2023 | Restyle plastics.',
    'item_option_name' => 'Rider name pre konkretny graphics item.',
    'item_option_number' => 'Rider number pre konkretny graphics item.',
    'item_option_material' => 'Material graphics, napr. Standard, Chrome, More Layers.',
    'item_option_finish' => 'Finish graphics, napr. Gloss, Matte, Frozen.',
    'item_option_grip' => 'Grip parameter, ak dany item dava zmysel.',
    'item_option_tr_swingarms' => 'Doplnkovy transfer / swingarm detail pre item.',
    'item_option_patch_style' => 'Patch style pri seat cover itemoch, napr. No Patch.',
    'item_option_waterproof_seams' => 'Pouzi hlavne pri seat cover itemoch. Typicky Yes alebo No.',
    'item_option_enduro_pocket' => 'Pouzi hlavne pri seat cover itemoch. Typicky Yes alebo No.',
    'item_option_side_brand_patches' => 'Pouzi hlavne pri seat cover itemoch. Typicky Yes/No alebo styl patchov.',
    'item_upsell_source' => 'Preco je tato polozka upsell. Napr. converted from graphics-only.',
    'item_is_upsell' => 'Oznac, ak support dopredal dalsi produkt navyse oproti povodnemu zaujmu.',
    'item_option_note' => 'Volna poznamka ku konkretnemu itemu.',
  ];

  $mapEn = [
    'search' => 'Search by internal code, official order number, customer name, or social handle.',
    'status_filter' => 'Filter leads by pipeline status.',
    'seq_so' => 'Set the last used SO number. The next SO order will use the following number.',
    'seq_go' => 'Set the last used GO number for GrenzGaenger orders.',
    'seq_sc' => 'Set the last used SC number for seat cover orders.',
    'owner' => 'Customer service person currently handling this lead and communicating with the customer.',
    'official_prefix' => 'SO for Scrub custom, GO for GrenzGaenger, SC for seat cover custom.',
    'status' => 'Lead = new contact, Deposit Pending = waiting for deposit, Deposit Paid/In Progress = details being collected, Ready To Export = ready, Exported = already moved to production.',
    'complexity_level' => 'Internal complexity from 1 to 10. Higher number means more detail, more products, and more demanding communication.',
    'source_channel' => 'Where the contact came from. Use consistent names like Instagram, WhatsApp, Messenger, Facebook, TikTok, Email.',
    'social_platform' => 'Platform used for communication.',
    'social_handle' => 'Customer nickname or identifier on the social platform.',
    'customer_name' => 'Customer real name if known. It can stay empty when the lead is first created.',
    'customer_email' => 'Customer email. Email or phone must be filled before export.',
    'customer_phone' => 'Customer phone number. Email or phone must be filled before export.',
    'customer_country' => 'Use a 2-letter country code, for example DE, FR, US, CA, GB.',
    'bike_brand' => 'Bike brand, for example KTM, Husqvarna, Yamaha.',
    'bike_model' => 'Bike model, for example SX 250, TE 300, CRF 450R.',
    'bike_year' => 'Bike year. Add a more precise explanation in Bike details if needed.',
    'bike_details' => 'Important extra bike information such as restyle plastics, special generation, unusual fitment, black frame, and similar notes.',
    'rider_name' => 'Name to appear on the graphics if used.',
    'rider_number' => 'Race number to appear on the graphics.',
    'currency' => 'Recommended 3-letter currency codes: EUR, USD, GBP.',
    'shipping_name' => 'Recipient name. Required for export to production orders.',
    'shipping_company' => 'Optional recipient company.',
    'shipping_street' => 'Street and house number. Required for export.',
    'shipping_city' => 'Recipient city. Required for export.',
    'shipping_zip' => 'Postal code / ZIP. Required for export.',
    'shipping_country' => '2-letter recipient country code. Required for export.',
    'shipping_email' => 'Optional delivery email, recommended.',
    'shipping_phone' => 'Optional delivery phone number, recommended.',
    'shipping_method' => 'Shipping method, for example FedEx Economy, FedEx Express, DHL.',
    'shipping_price' => 'Shipping price without currency, for example 14.90.',
    'deposit_revision_limit' => 'How many design revisions are included in the current deposit. Standard is 3.',
    'deposit_revision_used' => 'How many revisions the customer has already used. If exceeded, an extra deposit is required.',
    'last_contact_at' => 'When the last communication with the customer happened.',
    'next_followup_at' => 'When support should come back to this lead again.',
    'dead_order_flag' => 'Mark the lead as dead if the customer is unresponsive or the order is no longer realistic.',
    'graphics_brief' => 'Human-readable brief for the designer: what the customer wants, style, colors, and design direction.',
    'bike_photo_urls' => 'Links to bike photos. Ideally one link per line.',
    'reference_urls' => 'Inspiration, screenshots, cloud folder, previous designs. One entry per line.',
    'customer_notes' => 'Important agreed points that should still matter after export.',
    'internal_notes' => 'Internal team notes that do not need to be pushed into production notes.',
    'payment_kind' => 'DEPOSIT = first deposit, EXTRA_DEPOSIT = another deposit, BALANCE = final payment, REFUND = refund.',
    'paypal_transaction_id' => 'Exact PayPal transaction ID for payment lookup.',
    'payment_amount' => 'Received or refunded amount without currency.',
    'payment_currency' => 'Currency of this payment, most often EUR.',
    'payment_received_at' => 'Date and time when the payment was received.',
    'payment_note' => 'Short note, for example first design deposit or extra deposit after 3 revisions.',
    'followup_contacted_at' => 'Date and time when the customer was contacted.',
    'followup_channel' => 'Communication channel, for example Instagram, WhatsApp, Messenger, Email.',
    'followup_note' => 'What you asked the customer for or what was clarified.',
    'item_type_code' => 'G = graphics, P = plastics, S = seat cover, F = fitting, T = accessories, M = misc.',
    'item_sku' => 'Product SKU if known. Otherwise it can stay MANUAL.',
    'item_qty' => 'Quantity. Minimum is 1.',
    'item_unit_price' => 'Price for one piece without currency.',
    'item_title' => 'Most important item name. It must be clear what is actually being sold.',
    'item_custom_label' => 'Optional additional internal or customer-facing label.',
    'item_category_info' => 'Compatibility / orientation, for example KTM | SX 125 | 2023 | Restyle plastics.',
    'item_option_name' => 'Rider name for this specific graphics item.',
    'item_option_number' => 'Rider number for this specific graphics item.',
    'item_option_material' => 'Graphics material, for example Standard, Chrome, More Layers.',
    'item_option_finish' => 'Graphics finish, for example Gloss, Matte, Frozen.',
    'item_option_grip' => 'Grip parameter if relevant for the item.',
    'item_option_tr_swingarms' => 'Additional transfer / swingarm detail for the item.',
    'item_option_patch_style' => 'Patch style for seat cover items, for example No Patch.',
    'item_option_waterproof_seams' => 'Mainly for seat cover items. Typically Yes or No.',
    'item_option_enduro_pocket' => 'Mainly for seat cover items. Typically Yes or No.',
    'item_option_side_brand_patches' => 'Mainly for seat cover items. Typically Yes/No or patch style.',
    'item_upsell_source' => 'Why this item is considered an upsell. For example converted from graphics-only.',
    'item_is_upsell' => 'Mark this if support sold an additional product beyond the customer original interest.',
    'item_option_note' => 'Free-form note for this specific item.',
  ];

  return $lang === 'en' ? $mapEn : $mapSk;
}

function customOrderHelp(string $key): string
{
  $map = customOrderHelpMap(customOrderResolveHelpLanguage());
  if (empty($map[$key])) {
    return '';
  }

  $text = htmlspecialchars($map[$key], ENT_QUOTES, 'UTF-8');
  return ' <span class="custom-help-icon" data-help="' . $text . '" aria-label="Help" tabindex="0">i</span>';
}

function customOrderInvalid(array $invalidFields, string $key): string
{
  return isset($invalidFields[$key]) ? ' custom-field-invalid' : '';
}

$customOrderHelpLang = customOrderResolveHelpLanguage();
?>
<style>
  .custom-orders-grid { display: grid; grid-template-columns: 420px 1fr; gap: 16px; }
  /*pozadie fieldsetov */
  .custom-orders-panel { border: 1px solid #495057; border-radius: 8px; background: #20252b; }
  .custom-orders-panel .panel-body { padding: 14px; }
  .custom-order-list-row { border-bottom: 1px solid #343a40; padding: 10px 12px; display: block; color: #f8f9fa; }
  /*bočné karty*/
  .custom-order-list-row:hover, .custom-order-list-row.active { background: #2d343c; color: #fff; text-decoration: none; }
  .custom-order-meta { font-size: 12px; color: #adb5bd; }
  .custom-order-section-title { font-size: 13px; letter-spacing: .05em; color: #adb5bd; text-transform: uppercase; margin-bottom: 10px; }
  .custom-kpi { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
  /*vrchné karty*/
  .custom-kpi-card { border: 1px solid #495057; border-radius: 8px; padding: 10px; background: #252c33; }
  .custom-form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
  .custom-form-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  .custom-form-grid-4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
  .custom-form-full { grid-column: 1 / -1; }
  .custom-mini-table td, .custom-mini-table th { padding: 6px 8px; font-size: 13px; }
  .custom-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .custom-field-invalid {
    border-color: #dc3545 !important;
    box-shadow: 0 0 0 .12rem rgba(220, 53, 69, .25) !important;

    background: rgba(220, 53, 69, .10) !important;
  }
  label.custom-field-invalid,
  .form-check-label.custom-field-invalid {
    color: #ff9ea7 !important;
  }
  .custom-panel-invalid {
    border-color: rgba(220, 53, 69, .8) !important;
    box-shadow: inset 0 0 0 1px rgba(220, 53, 69, .25);
  }
  .custom-help-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    margin-left: 5px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,.35);
    color: #9ed6ff;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
    cursor: help;
    vertical-align: middle;
    background: rgba(60, 141, 188, .16);
  }
  .custom-help-icon::before {
    content: "";
    position: absolute;
    left: 50%;
    bottom: calc(100% + 3px);
    transform: translateX(-50%);
    border: 6px solid transparent;
    border-top-color: rgba(17, 24, 39, .96);
    opacity: 0;
    visibility: hidden;
    transition: opacity .12s ease, visibility .12s ease;
    pointer-events: none;
    z-index: 1080;
  }
  .custom-help-icon::after {
    content: attr(data-help);
    position: absolute;
    left: 50%;
    bottom: calc(100% + 14px);
    transform: translateX(-50%);
    min-width: 220px;
    max-width: 320px;
    padding: 8px 10px;
    border-radius: 8px;
    background: rgba(17, 24, 39, .96);
    color: #f8f9fa;
    font-size: 12px;
    font-weight: 500;
    line-height: 1.35;
    text-align: left;
    white-space: normal;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .35);
    opacity: 0;
    visibility: hidden;
    transition: opacity .12s ease, visibility .12s ease;
    pointer-events: none;
    z-index: 1080;
  }
  .custom-help-icon:hover,
  .custom-help-icon:focus {
    color: #fff;
    border-color: rgba(255,255,255,.55);
    background: rgba(60, 141, 188, .42);
    outline: none;
  }
  .custom-help-icon:hover::before,
  .custom-help-icon:hover::after,
  .custom-help-icon:focus::before,
  .custom-help-icon:focus::after {
    opacity: 1;
    visibility: visible;
  }
  @media (max-width: 1200px) {
    .custom-orders-grid { grid-template-columns: 1fr; }
    .custom-form-grid, .custom-form-grid-2, .custom-form-grid-4, .custom-kpi { grid-template-columns: 1fr; }
  }
</style>

<div class="container-fluid">
  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($moduleLoadError !== null): ?>
    <div class="alert alert-danger">
      Custom Orders module could not load database data.<br>
      <strong>Database error:</strong> <?= h($moduleLoadError) ?><br>
      Please run the latest custom orders SQL migration on this environment.
    </div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center">
      <h3 class="mb-0 mr-3">Custom Orders</h3>
      <div class="btn-group btn-group-sm" role="group" aria-label="Tooltip language">
        <a href="index.php?page=custom_orders<?= $selectedOrderId > 0 ? '&custom_order_id=' . (int) $selectedOrderId : '' ?><?= $editItemId > 0 ? '&edit_item_id=' . (int) $editItemId : '' ?><?= $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : '' ?><?= $query !== '' ? '&q=' . urlencode($query) : '' ?>&help_lang=sk" class="btn <?= $customOrderHelpLang === 'sk' ? 'btn-info' : 'btn-outline-light' ?>">SK Help</a>
        <a href="index.php?page=custom_orders<?= $selectedOrderId > 0 ? '&custom_order_id=' . (int) $selectedOrderId : '' ?><?= $editItemId > 0 ? '&edit_item_id=' . (int) $editItemId : '' ?><?= $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : '' ?><?= $query !== '' ? '&q=' . urlencode($query) : '' ?>&help_lang=en" class="btn <?= $customOrderHelpLang === 'en' ? 'btn-info' : 'btn-outline-light' ?>">EN Help</a>
      </div>
    </div>
    <form method="post" action="scripts/custom_orders/create_order.php" class="mb-0">
      <button type="submit" class="btn btn-success">New Custom Lead</button>
    </form>
  </div>

  <div class="custom-orders-grid">
    <div class="custom-orders-panel">
      <div class="panel-body">
        <form method="get" class="mb-3">
          <input type="hidden" name="page" value="custom_orders">
          <div class="form-group">
            <label>Search<?= customOrderHelp('search') ?></label>
            <input type="text" name="q" class="form-control form-control-sm" value="<?= h($query) ?>" placeholder="Internal code, official no., customer, handle">
          </div>
          <div class="form-group">
            <label>Status<?= customOrderHelp('status_filter') ?></label>
            <select name="status" class="form-control form-control-sm">
              <option value="">All statuses</option>
              <?php foreach ($statuses as $code => $label): ?>
                <option value="<?= h($code) ?>" <?= $statusFilter === $code ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        </form>

        <div class="custom-order-section-title">Order Seeds</div>
        <form method="post" action="scripts/custom_orders/update_sequences.php" class="mb-3">
          <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrderId ?>">
          <div class="custom-form-grid-4">
            <div><label>SO next seed<?= customOrderHelp('seq_so') ?></label><input type="number" name="seq_so" class="form-control form-control-sm" value="<?= (int) $sequences['SO'] ?>"></div>
            <div><label>GO next seed<?= customOrderHelp('seq_go') ?></label><input type="number" name="seq_go" class="form-control form-control-sm" value="<?= (int) $sequences['GO'] ?>"></div>
            <div><label>SC next seed<?= customOrderHelp('seq_sc') ?></label><input type="number" name="seq_sc" class="form-control form-control-sm" value="<?= (int) $sequences['SC'] ?>"></div>
            <div class="d-flex align-items-end"><button type="submit" class="btn btn-outline-light btn-sm w-100">Save Seeds</button></div>
          </div>
        </form>

        <div class="custom-order-section-title">Pipeline</div>
        <div style="max-height: 70vh; overflow:auto;">
          <?php foreach ($listRows as $row): ?>
            <?php $isActive = (int) $row['id'] === $selectedOrderId; ?>
            <a class="custom-order-list-row <?= $isActive ? 'active' : '' ?>" href="index.php?page=custom_orders&custom_order_id=<?= (int) $row['id'] ?>">
              <div class="d-flex justify-content-between">
                <strong><?= h($row['official_order_number'] ?: $row['internal_code']) ?></strong>
                <span class="badge badge-<?= $row['status'] === 'EXPORTED' ? 'success' : ($row['status'] === 'DEAD' ? 'danger' : 'warning') ?>"><?= h(selectedText($statuses, (string) $row['status'])) ?></span>
              </div>
              <div><?= h($row['customer_name'] ?: $row['social_handle'] ?: 'Unnamed lead') ?></div>
              <div class="custom-order-meta">
                <?= h($row['source_channel'] ?: '-') ?> | <?= h($row['social_platform'] ?: '-') ?> | C<?= (int) $row['complexity_level'] ?> | Owner <?= h($row['owner_name'] ?: '-') ?>
              </div>
              <div class="custom-order-meta">
                Items <?= (int) $row['item_count'] ?> | Deposits <?= number_format((float) ($row['deposit_total'] ?? 0), 2) ?> | Upsell <?= number_format((float) ($row['upsell_total'] ?? 0), 2) ?>
              </div>
            </a>
          <?php endforeach; ?>
          <?php if (!$listRows): ?>
            <div class="text-muted">No custom orders yet.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div>
      <?php if (!$selectedOrder): ?>
        <div class="alert alert-secondary">Create a custom lead to start.</div>
      <?php else: ?>
        <?php $summary = $selectedOrder['summary']; ?>
        <div class="custom-kpi">
          <div class="custom-kpi-card"><div class="text-muted">Internal</div><strong><?= h($selectedOrder['internal_code']) ?></strong></div>
          <div class="custom-kpi-card"><div class="text-muted">Official</div><strong><?= h($selectedOrder['official_order_number'] ?: 'Not assigned') ?></strong></div>
          <div class="custom-kpi-card"><div class="text-muted">Owner<?= customOrderHelp('owner') ?></div><strong><?= h($selectedOrder['owner_name'] ?: 'Unassigned') ?></strong></div>
          <div class="custom-kpi-card"><div class="text-muted">Gross Total</div><strong><?= number_format((float) $summary['gross_total'], 2) ?> <?= h($selectedOrder['currency']) ?></strong></div>
          <div class="custom-kpi-card"><div class="text-muted">Deposits</div><strong><?= number_format((float) $summary['deposit_total'], 2) ?> <?= h($selectedOrder['currency']) ?></strong></div>
        </div>

        <div class="custom-actions mb-3">
          <form method="post" action="scripts/custom_orders/assign_owner.php" class="form-inline">
            <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
            <select name="owner_employee_id" class="form-control form-control-sm mr-2<?= customOrderInvalid($invalidFields, 'owner') ?>">
              <?php foreach ($assignableEmployees as $employee): ?>
                <?php $employeeName = trim(((string) ($employee['firstname'] ?? '')) . ' ' . ((string) ($employee['lastname'] ?? ''))); ?>
                <option value="<?= (int) $employee['id'] ?>" <?= (int) ($selectedOrder['owner_employee_id'] ?? 0) === (int) $employee['id'] ? 'selected' : '' ?>>
                  <?= h($employeeName) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-info btn-sm">Assign Owner</button>
          </form>
          <form method="post" action="scripts/custom_orders/assign_official_number.php" class="form-inline">
            <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
            <select name="official_prefix" class="form-control form-control-sm mr-2<?= customOrderInvalid($invalidFields, 'official_prefix') ?>" title="Official order prefix">
              <option value="SO" <?= ($selectedOrder['official_prefix'] ?? 'SO') === 'SO' ? 'selected' : '' ?>>SO</option>
              <option value="GO" <?= ($selectedOrder['official_prefix'] ?? '') === 'GO' ? 'selected' : '' ?>>GO</option>
              <option value="SC" <?= ($selectedOrder['official_prefix'] ?? '') === 'SC' ? 'selected' : '' ?>>SC</option>
            </select>
            <button type="submit" class="btn btn-warning btn-sm">Assign Official Number</button>
          </form>
          <form method="post" action="scripts/custom_orders/export_order.php">
            <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
            <button type="submit" class="btn btn-primary btn-sm" <?= (int) ($selectedOrder['production_order_id'] ?? 0) > 0 ? 'disabled' : '' ?>>Export To Production</button>
          </form>
          <?php if ((int) ($selectedOrder['production_order_id'] ?? 0) > 0): ?>
            <a class="btn btn-outline-success btn-sm" href="index.php?page=orders&q=<?= urlencode((string) $selectedOrder['official_order_number']) ?>">Open Production Order</a>
          <?php endif; ?>
        </div>

        <datalist id="custom-contact-suggestions">
          <?php foreach ($suggestions as $sg): ?>
            <?php $label = trim(implode(' | ', array_filter([(string) ($sg['name'] ?? ''), (string) ($sg['social_handle'] ?? ''), (string) ($sg['email'] ?? ''), (string) ($sg['phone'] ?? '')]))); ?>
            <?php if ($label !== ''): ?><option value="<?= h($label) ?>"></option><?php endif; ?>
          <?php endforeach; ?>
        </datalist>

        <div class="custom-orders-panel mb-3<?= isset($invalidFields['customer_name']) || isset($invalidFields['social_handle']) || isset($invalidFields['shipping_name']) || isset($invalidFields['shipping_street']) || isset($invalidFields['shipping_city']) || isset($invalidFields['shipping_zip']) || isset($invalidFields['shipping_country']) || isset($invalidFields['customer_email']) || isset($invalidFields['customer_phone']) || isset($invalidFields['shipping_email']) || isset($invalidFields['shipping_phone']) || isset($invalidFields['shipping_price']) ? ' custom-panel-invalid' : '' ?>">
          <div class="panel-body">
            <div class="custom-order-section-title">Header And Customer Snapshot</div>
            <div class="mb-3 text-muted">
              Owner:
              <strong><?= h($selectedOrder['owner_name'] ?: 'Unassigned') ?></strong>
              <?php if (!empty($selectedOrder['owner_assigned_at'])): ?>
                | assigned at <?= h($selectedOrder['owner_assigned_at']) ?>
              <?php endif; ?>
              <?php if (!empty($selectedOrder['owner_assigned_by_name'])): ?>
                | by <?= h($selectedOrder['owner_assigned_by_name']) ?>
              <?php endif; ?>
            </div>
            <form method="post" action="scripts/custom_orders/save_order.php">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <div class="custom-form-grid">
                <div><label>Status<?= customOrderHelp('status') ?></label><select name="status" class="form-control form-control-sm"><?php foreach ($statuses as $code => $label): ?><option value="<?= h($code) ?>" <?= $selectedOrder['status'] === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
                <div><label>Complexity<?= customOrderHelp('complexity_level') ?></label><input type="number" min="1" max="10" name="complexity_level" class="form-control form-control-sm" value="<?= (int) $selectedOrder['complexity_level'] ?>"></div>
                <div><label>Source channel<?= customOrderHelp('source_channel') ?></label><input type="text" name="source_channel" class="form-control form-control-sm" value="<?= h($selectedOrder['source_channel']) ?>" placeholder="Instagram, WhatsApp, Email"></div>
                <div><label>Social platform<?= customOrderHelp('social_platform') ?></label><input type="text" name="social_platform" class="form-control form-control-sm" value="<?= h($selectedOrder['social_platform']) ?>"></div>
                <div><label class="<?= trim(customOrderInvalid($invalidFields, 'social_handle')) ?>">Social handle<?= customOrderHelp('social_handle') ?></label><input type="text" name="social_handle" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'social_handle') ?>" value="<?= h($selectedOrder['social_handle']) ?>" list="custom-contact-suggestions"></div>
                <div><label class="<?= trim(customOrderInvalid($invalidFields, 'customer_name')) ?>">Customer name<?= customOrderHelp('customer_name') ?></label><input type="text" name="customer_name" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'customer_name') ?>" value="<?= h($selectedOrder['customer_name']) ?>" list="custom-contact-suggestions"></div>
                <div><label class="<?= trim(customOrderInvalid($invalidFields, 'customer_email')) ?>">Customer email<?= customOrderHelp('customer_email') ?></label><input type="text" name="customer_email" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'customer_email') ?>" value="<?= h($selectedOrder['customer_email']) ?>"></div>
                <div><label class="<?= trim(customOrderInvalid($invalidFields, 'customer_phone')) ?>">Customer phone<?= customOrderHelp('customer_phone') ?></label><input type="text" name="customer_phone" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'customer_phone') ?>" value="<?= h($selectedOrder['customer_phone']) ?>"></div>
                <div><label>Customer country<?= customOrderHelp('customer_country') ?></label><input type="text" name="customer_country" class="form-control form-control-sm" value="<?= h($selectedOrder['customer_country']) ?>"></div>
                <div><label>Bike brand<?= customOrderHelp('bike_brand') ?></label><input type="text" name="bike_brand" class="form-control form-control-sm" value="<?= h($selectedOrder['bike_brand']) ?>"></div>
                <div><label>Bike model<?= customOrderHelp('bike_model') ?></label><input type="text" name="bike_model" class="form-control form-control-sm" value="<?= h($selectedOrder['bike_model']) ?>"></div>
                <div><label>Bike year<?= customOrderHelp('bike_year') ?></label><input type="text" name="bike_year" class="form-control form-control-sm" value="<?= h($selectedOrder['bike_year']) ?>"></div>
                <div class="custom-form-full"><label>Bike details<?= customOrderHelp('bike_details') ?></label><input type="text" name="bike_details" class="form-control form-control-sm" value="<?= h($selectedOrder['bike_details']) ?>" placeholder="Engine, generation, plastics note, unusual fitment"></div>
                <div><label>Rider name<?= customOrderHelp('rider_name') ?></label><input type="text" name="rider_name" class="form-control form-control-sm" value="<?= h($selectedOrder['rider_name']) ?>"></div>
                <div><label>Rider number<?= customOrderHelp('rider_number') ?></label><input type="text" name="rider_number" class="form-control form-control-sm" value="<?= h($selectedOrder['rider_number']) ?>"></div>
                <div><label>Currency<?= customOrderHelp('currency') ?></label><input type="text" name="currency" class="form-control form-control-sm" value="<?= h($selectedOrder['currency']) ?>"></div>
                <div><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_name')) ?>">Shipping name<?= customOrderHelp('shipping_name') ?></label><input type="text" name="shipping_name" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_name') ?>" value="<?= h($selectedOrder['shipping_name']) ?>"></div>
                <div><label>Shipping company<?= customOrderHelp('shipping_company') ?></label><input type="text" name="shipping_company" class="form-control form-control-sm" value="<?= h($selectedOrder['shipping_company']) ?>"></div>
                <div><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_street')) ?>">Shipping street<?= customOrderHelp('shipping_street') ?></label><input type="text" name="shipping_street" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_street') ?>" value="<?= h($selectedOrder['shipping_street']) ?>"></div>
                <div><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_city')) ?>">Shipping city<?= customOrderHelp('shipping_city') ?></label><input type="text" name="shipping_city" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_city') ?>" value="<?= h($selectedOrder['shipping_city']) ?>"></div>
                <div><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_zip')) ?>">Shipping ZIP<?= customOrderHelp('shipping_zip') ?></label><input type="text" name="shipping_zip" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_zip') ?>" value="<?= h($selectedOrder['shipping_zip']) ?>"></div>
                <div><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_country')) ?>">Shipping country<?= customOrderHelp('shipping_country') ?></label><input type="text" name="shipping_country" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_country') ?>" value="<?= h($selectedOrder['shipping_country']) ?>"></div>
                <div><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_email')) ?>">Shipping email<?= customOrderHelp('shipping_email') ?></label><input type="text" name="shipping_email" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_email') ?>" value="<?= h($selectedOrder['shipping_email']) ?>"></div>
                <div><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_phone')) ?>">Shipping phone<?= customOrderHelp('shipping_phone') ?></label><input type="text" name="shipping_phone" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_phone') ?>" value="<?= h($selectedOrder['shipping_phone']) ?>"></div>
                <div><label>Shipping method<?= customOrderHelp('shipping_method') ?></label><input type="text" name="shipping_method" class="form-control form-control-sm" value="<?= h($selectedOrder['shipping_method']) ?>"></div>
                <div><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_price')) ?>">Shipping price<?= customOrderHelp('shipping_price') ?></label><input type="number" step="0.01" name="shipping_price" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_price') ?>" value="<?= h($selectedOrder['shipping_price']) ?>"></div>
                <div><label>Revisions included<?= customOrderHelp('deposit_revision_limit') ?></label><input type="number" name="deposit_revision_limit" class="form-control form-control-sm" value="<?= (int) $selectedOrder['deposit_revision_limit'] ?>"></div>
                <div><label>Revisions used<?= customOrderHelp('deposit_revision_used') ?></label><input type="number" name="deposit_revision_used" class="form-control form-control-sm" value="<?= (int) $selectedOrder['deposit_revision_used'] ?>"></div>
                <div><label>Last contact<?= customOrderHelp('last_contact_at') ?></label><input type="datetime-local" name="last_contact_at" class="form-control form-control-sm" value="<?= h($selectedOrder['last_contact_at'] ? date('Y-m-d\TH:i', strtotime((string) $selectedOrder['last_contact_at'])) : '') ?>"></div>
                <div><label>Next follow-up<?= customOrderHelp('next_followup_at') ?></label><input type="datetime-local" name="next_followup_at" class="form-control form-control-sm" value="<?= h($selectedOrder['next_followup_at'] ? date('Y-m-d\TH:i', strtotime((string) $selectedOrder['next_followup_at'])) : '') ?>"></div>
                <div class="d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="dead_order_flag" id="dead_order_flag" <?= (int) $selectedOrder['dead_order_flag'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="dead_order_flag">Dead order<?= customOrderHelp('dead_order_flag') ?></label></div></div>
                <div class="custom-form-full"><label>Graphics brief<?= customOrderHelp('graphics_brief') ?></label><textarea name="graphics_brief" rows="3" class="form-control form-control-sm"><?= h($selectedOrder['graphics_brief']) ?></textarea></div>
                <div class="custom-form-full"><label>Bike photo URLs<?= customOrderHelp('bike_photo_urls') ?></label><textarea name="bike_photo_urls" rows="2" class="form-control form-control-sm" placeholder="One URL per line"><?= h($selectedOrder['bike_photo_urls']) ?></textarea></div>
                <div class="custom-form-full"><label>Reference URLs / files<?= customOrderHelp('reference_urls') ?></label><textarea name="reference_urls" rows="2" class="form-control form-control-sm" placeholder="One URL or note per line"><?= h($selectedOrder['reference_urls']) ?></textarea></div>
                <div class="custom-form-full"><label>Customer notes<?= customOrderHelp('customer_notes') ?></label><textarea name="customer_notes" rows="3" class="form-control form-control-sm"><?= h($selectedOrder['customer_notes']) ?></textarea></div>
                <div class="custom-form-full"><label>Internal notes<?= customOrderHelp('internal_notes') ?></label><textarea name="internal_notes" rows="3" class="form-control form-control-sm"><?= h($selectedOrder['internal_notes']) ?></textarea></div>
              </div>
              <button type="submit" class="btn btn-success btn-sm mt-3">Save Header</button>
            </form>
          </div>
        </div>

        <div class="custom-form-grid-2 mb-3">
          <div class="custom-orders-panel">
            <div class="panel-body">
              <div class="custom-order-section-title">Payments And Deposits</div>
              <form method="post" action="scripts/custom_orders/save_payment.php">
                <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                <div class="custom-form-grid-4">
                  <div><label>Kind<?= customOrderHelp('payment_kind') ?></label><select name="payment_kind" class="form-control form-control-sm"><?php foreach ($paymentKinds as $code => $label): ?><option value="<?= h($code) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
                  <div><label>PayPal tx ID<?= customOrderHelp('paypal_transaction_id') ?></label><input type="text" name="paypal_transaction_id" class="form-control form-control-sm"></div>
                  <div><label>Amount<?= customOrderHelp('payment_amount') ?></label><input type="number" step="0.01" name="amount" class="form-control form-control-sm" required></div>
                  <div><label>Currency<?= customOrderHelp('payment_currency') ?></label><input type="text" name="currency" class="form-control form-control-sm" value="<?= h($selectedOrder['currency']) ?>"></div>
                  <div><label>Received at<?= customOrderHelp('payment_received_at') ?></label><input type="datetime-local" name="received_at" class="form-control form-control-sm"></div>
                  <div class="custom-form-full"><label>Note<?= customOrderHelp('payment_note') ?></label><input type="text" name="note" class="form-control form-control-sm"></div>
                </div>
                <button type="submit" class="btn btn-outline-light btn-sm mt-2">Add Payment</button>
              </form>
              <table class="table table-sm table-dark table-striped custom-mini-table mt-3">
                <thead><tr><th>Kind</th><th>Amount</th><th>PayPal</th><th>At</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($selectedOrder['payments'] as $payment): ?>
                    <tr>
                      <td><?= h($payment['payment_kind']) ?></td>
                      <td><?= number_format((float) $payment['amount'], 2) ?></td>
                      <td><?= h($payment['paypal_transaction_id']) ?></td>
                      <td><?= h($payment['received_at']) ?></td>
                      <td>
                        <form method="post" action="scripts/custom_orders/delete_payment.php">
                          <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                          <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                          <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="custom-orders-panel">
            <div class="panel-body">
              <div class="custom-order-section-title">Contact Attempts</div>
              <form method="post" action="scripts/custom_orders/save_followup.php">
                <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                <div class="custom-form-grid-4">
                  <div><label>Contacted at<?= customOrderHelp('followup_contacted_at') ?></label><input type="datetime-local" name="contacted_at" class="form-control form-control-sm"></div>
                  <div><label>Channel<?= customOrderHelp('followup_channel') ?></label><input type="text" name="channel" class="form-control form-control-sm" placeholder="IG / WhatsApp / Email"></div>
                  <div class="custom-form-full"><label>Note<?= customOrderHelp('followup_note') ?></label><input type="text" name="note" class="form-control form-control-sm" placeholder="Requested address, clarified plastics generation, etc."></div>
                </div>
                <button type="submit" class="btn btn-outline-light btn-sm mt-2">Add Follow-up</button>
              </form>
              <table class="table table-sm table-dark table-striped custom-mini-table mt-3">
                <thead><tr><th>When</th><th>Channel</th><th>Note</th></tr></thead>
                <tbody>
                  <?php foreach ($selectedOrder['followups'] as $followup): ?>
                    <tr>
                      <td><?= h($followup['contacted_at']) ?></td>
                      <td><?= h($followup['channel']) ?></td>
                      <td><?= h($followup['note']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="custom-orders-panel mb-3<?= isset($invalidFields['items']) ? ' custom-panel-invalid' : '' ?>">
          <div class="panel-body">
            <div class="custom-order-section-title"><?= $editItem ? 'Edit Item' : 'Add Item / Upsell' ?></div>
            <?php $editOptions = $editItem ? (json_decode((string) $editItem['options_json'], true) ?: []) : []; ?>
            <form method="post" action="scripts/custom_orders/save_item.php">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <input type="hidden" name="custom_item_id" value="<?= (int) ($editItem['id'] ?? 0) ?>">
              <div class="custom-form-grid-4">
                <div><label>Type<?= customOrderHelp('item_type_code') ?></label><select name="item_type_code" class="form-control form-control-sm"><?php foreach ($allowedTypes as $code => $label): ?><option value="<?= h($code) ?>" <?= (($editItem['item_type_code'] ?? 'G') === $code) ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
                <div><label>SKU<?= customOrderHelp('item_sku') ?></label><input type="text" name="sku" class="form-control form-control-sm" value="<?= h($editItem['sku'] ?? '') ?>"></div>
                <div><label>Qty<?= customOrderHelp('item_qty') ?></label><input type="number" min="1" name="qty" class="form-control form-control-sm" value="<?= h($editItem['qty'] ?? 1) ?>"></div>
                <div><label>Unit price<?= customOrderHelp('item_unit_price') ?></label><input type="number" step="0.01" name="unit_price" class="form-control form-control-sm" value="<?= h($editItem['unit_price'] ?? 0) ?>"></div>
                <div class="custom-form-full"><label>Title<?= customOrderHelp('item_title') ?></label><input type="text" name="title" class="form-control form-control-sm" value="<?= h($editItem['title'] ?? '') ?>" required></div>
                <div><label>Custom label<?= customOrderHelp('item_custom_label') ?></label><input type="text" name="custom_label" class="form-control form-control-sm" value="<?= h($editItem['custom_label'] ?? '') ?>"></div>
                <div><label>Category info<?= customOrderHelp('item_category_info') ?></label><input type="text" name="category_info" class="form-control form-control-sm" value="<?= h($editOptions['category_info'] ?? '') ?>" placeholder="Brand | Model | Year"></div>
                <div><label>Rider name<?= customOrderHelp('item_option_name') ?></label><input type="text" name="option_name" class="form-control form-control-sm" value="<?= h($editOptions['name'] ?? $selectedOrder['rider_name']) ?>"></div>
                <div><label>Rider number<?= customOrderHelp('item_option_number') ?></label><input type="text" name="option_number" class="form-control form-control-sm" value="<?= h($editOptions['number'] ?? $selectedOrder['rider_number']) ?>"></div>
                <div><label>Material<?= customOrderHelp('item_option_material') ?></label><input type="text" name="option_material" class="form-control form-control-sm" value="<?= h($editOptions['base-material'] ?? '') ?>"></div>
                <div><label>Finish<?= customOrderHelp('item_option_finish') ?></label><input type="text" name="option_finish" class="form-control form-control-sm" value="<?= h($editOptions['graphics-finish'] ?? '') ?>"></div>
                <div><label>Grip<?= customOrderHelp('item_option_grip') ?></label><input type="text" name="option_grip" class="form-control form-control-sm" value="<?= h($editOptions['grip'] ?? '') ?>"></div>
                <div><label>Tr. swingarms<?= customOrderHelp('item_option_tr_swingarms') ?></label><input type="text" name="option_tr_swingarms" class="form-control form-control-sm" value="<?= h($editOptions['tr-swingarms'] ?? '') ?>"></div>
                <div><label>Patch style<?= customOrderHelp('item_option_patch_style') ?></label><input type="text" name="option_patch_style" class="form-control form-control-sm" value="<?= h($editOptions['patch-style'] ?? '') ?>"></div>
                <div><label>Waterproof seams<?= customOrderHelp('item_option_waterproof_seams') ?></label><input type="text" name="option_waterproof_seams" class="form-control form-control-sm" value="<?= h($editOptions['waterproof-seams'] ?? '') ?>"></div>
                <div><label>Enduro pocket<?= customOrderHelp('item_option_enduro_pocket') ?></label><input type="text" name="option_enduro_pocket" class="form-control form-control-sm" value="<?= h($editOptions['enduro-pocket'] ?? '') ?>"></div>
                <div><label>Side brand patches<?= customOrderHelp('item_option_side_brand_patches') ?></label><input type="text" name="option_side_brand_patches" class="form-control form-control-sm" value="<?= h($editOptions['side-brand-patches'] ?? '') ?>"></div>
                <div><label>Upsell source<?= customOrderHelp('item_upsell_source') ?></label><input type="text" name="upsell_source" class="form-control form-control-sm" value="<?= h($editItem['upsell_source'] ?? '') ?>" placeholder="Converted from graphics-only"></div>
                <div class="d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_upsell" id="is_upsell" <?= (int) ($editItem['is_upsell'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="is_upsell">Mark as upsell<?= customOrderHelp('item_is_upsell') ?></label></div></div>
                <div class="custom-form-full"><label>Item note<?= customOrderHelp('item_option_note') ?></label><textarea name="option_note" rows="2" class="form-control form-control-sm"><?= h($editOptions['note'] ?? '') ?></textarea></div>
              </div>
              <button type="submit" class="btn btn-success btn-sm mt-2"><?= $editItem ? 'Update Item' : 'Add Item' ?></button>
              <?php if ($editItem): ?>
                <a href="index.php?page=custom_orders&custom_order_id=<?= (int) $selectedOrder['id'] ?>" class="btn btn-secondary btn-sm mt-2">Cancel Edit</a>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <div class="custom-orders-panel mb-3">
          <div class="panel-body">
            <div class="custom-order-section-title">Items And Upsells</div>
            <table class="table table-sm table-dark table-striped custom-mini-table">
              <thead>
                <tr><th>#</th><th>Type</th><th>Title</th><th>Qty</th><th>Price</th><th>Upsell</th><th></th></tr>
              </thead>
              <tbody>
                <?php foreach ($selectedOrder['items'] as $item): ?>
                  <tr>
                    <td><?= (int) $item['line_no'] ?></td>
                    <td><?= h($item['item_type_code']) ?></td>
                    <td><?= h($item['title']) ?></td>
                    <td><?= (int) $item['qty'] ?></td>
                    <td><?= number_format((float) $item['unit_price'], 2) ?></td>
                    <td><?= (int) $item['is_upsell'] === 1 ? 'Yes' : 'No' ?></td>
                    <td class="text-right">
                      <a href="index.php?page=custom_orders&custom_order_id=<?= (int) $selectedOrder['id'] ?>&edit_item_id=<?= (int) $item['id'] ?>" class="btn btn-secondary btn-xs">Edit</a>
                      <form method="post" action="scripts/custom_orders/delete_item.php" style="display:inline-block;">
                        <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                        <input type="hidden" name="custom_item_id" value="<?= (int) $item['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="custom-orders-panel">
          <div class="panel-body">
            <div class="custom-order-section-title">Recent Activity</div>
            <table class="table table-sm table-dark table-striped custom-mini-table">
              <thead><tr><th>When</th><th>Action</th><th>Note</th></tr></thead>
              <tbody>
                <?php foreach ($selectedOrder['activity'] as $activity): ?>
                  <tr>
                    <td><?= h($activity['created_at']) ?></td>
                    <td><?= h($activity['action']) ?></td>
                    <td><?= h($activity['note']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
