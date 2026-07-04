<?php
declare(strict_types=1);

require_once __DIR__ . '/conn.php';
require_once dirname(__DIR__) . '/scripts/custom_orders/helpers.php';

customOrdersEnsureSchema($conn);

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
$builderType = strtoupper(trim((string) ($_GET['builder_type'] ?? '')));

$listRows = [];
$selectedOrder = null;
$editItem = null;
$relatedOrders = [];
$moduleLoadError = null;

$where = [];
$sequences = ['SO' => 0, 'GO' => 0, 'SC' => 0];
$suggestions = [];
$statusCounts = array_fill_keys(array_keys($statuses), 0);
$statusCounts['_all'] = 0;

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
      COALESCE(item_stats.item_count, 0) AS item_count,
      COALESCE(item_stats.item_total, 0) AS item_total,
      COALESCE(item_stats.upsell_total, 0) AS upsell_total,
      COALESCE(payment_stats.deposit_total, 0) AS deposit_total,
      COALESCE(payment_stats.paid_total, 0) AS paid_total
    FROM custom_orders co
    LEFT JOIN employees eo ON eo.id = co.owner_employee_id
    LEFT JOIN (
      SELECT
        custom_order_id,
        COUNT(*) AS item_count,
        SUM(qty * unit_price) AS item_total,
        SUM(CASE WHEN is_upsell = 1 THEN qty * unit_price ELSE 0 END) AS upsell_total
      FROM custom_order_items
      GROUP BY custom_order_id
    ) item_stats ON item_stats.custom_order_id = co.id
    LEFT JOIN (
      SELECT
        custom_order_id,
        SUM(CASE WHEN payment_kind IN ('DEPOSIT', 'EXTRA_DEPOSIT') THEN amount ELSE 0 END) AS deposit_total,
        SUM(CASE WHEN payment_kind = 'REFUND' THEN -amount ELSE amount END) AS paid_total
      FROM custom_order_payments
      GROUP BY custom_order_id
    ) payment_stats ON payment_stats.custom_order_id = co.id
  ";
  if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
  }
  $sql .= ' ORDER BY co.updated_at DESC, co.id DESC LIMIT 300';
  $res = $conn->query($sql);
  if (!$res) {
    throw new RuntimeException('Custom orders list query failed: ' . $conn->error);
  }
  while ($row = $res->fetch_assoc()) {
    $listRows[] = $row;
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

  $res = $conn->query("
    SELECT status, COUNT(*) AS cnt
    FROM custom_orders
    GROUP BY status
  ");
  if ($res) {
    while ($row = $res->fetch_assoc()) {
      $code = (string) ($row['status'] ?? '');
      $cnt = (int) ($row['cnt'] ?? 0);
      if (isset($statusCounts[$code])) {
        $statusCounts[$code] = $cnt;
      }
      $statusCounts['_all'] += $cnt;
    }
  }

  if ($selectedOrder) {
    $relatedWhere = [];
    if ((int) ($selectedOrder['contact_directory_id'] ?? 0) > 0) {
      $relatedWhere[] = 'co.contact_directory_id = ' . (int) $selectedOrder['contact_directory_id'];
    }

    $relatedEmail = trim((string) ($selectedOrder['customer_email'] ?? ''));
    if ($relatedEmail !== '') {
      $relatedWhere[] = "LOWER(TRIM(co.customer_email)) = '" . $conn->real_escape_string(strtolower($relatedEmail)) . "'";
    }

    $relatedHandle = trim((string) ($selectedOrder['social_handle'] ?? ''));
    if ($relatedHandle !== '') {
      $relatedWhere[] = "LOWER(TRIM(co.social_handle)) = '" . $conn->real_escape_string(strtolower($relatedHandle)) . "'";
    }

    $relatedName = trim((string) ($selectedOrder['customer_name'] ?? ''));
    if ($relatedName !== '') {
      $relatedWhere[] = "LOWER(TRIM(co.customer_name)) = '" . $conn->real_escape_string(strtolower($relatedName)) . "'";
    }

    if ($relatedWhere) {
      $relatedSql = "
        SELECT
          co.id,
          co.internal_code,
          co.official_order_number,
          co.status,
          co.customer_name,
          co.social_handle,
          co.customer_email,
          co.customer_country,
          co.currency,
          co.shipping_price,
          co.updated_at,
          co.production_order_id,
          TRIM(CONCAT_WS(' ', eo.firstname, eo.lastname)) AS owner_name,
          COALESCE(item_stats.item_count, 0) AS item_count,
          COALESCE(item_stats.item_total, 0) AS item_total,
          COALESCE(payment_stats.paid_total, 0) AS paid_total
        FROM custom_orders co
        LEFT JOIN employees eo ON eo.id = co.owner_employee_id
        LEFT JOIN (
          SELECT
            custom_order_id,
            COUNT(*) AS item_count,
            SUM(qty * unit_price) AS item_total
          FROM custom_order_items
          GROUP BY custom_order_id
        ) item_stats ON item_stats.custom_order_id = co.id
        LEFT JOIN (
          SELECT
            custom_order_id,
            SUM(CASE WHEN payment_kind = 'REFUND' THEN -amount ELSE amount END) AS paid_total
          FROM custom_order_payments
          GROUP BY custom_order_id
        ) payment_stats ON payment_stats.custom_order_id = co.id
        WHERE (" . implode(' OR ', $relatedWhere) . ")
        ORDER BY co.updated_at DESC, co.id DESC
        LIMIT 25
      ";
      $res = $conn->query($relatedSql);
      if ($res) {
        while ($row = $res->fetch_assoc()) {
          $relatedOrders[] = $row;
        }
      }
    }
  }
} catch (Throwable $e) {
  $moduleLoadError = $e->getMessage();
}

function h($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function customOrderFormatMultiline(string $value): string
{
  $normalized = str_replace(["\r\n", "\r"], "\n", $value);
  $lines = array_map('trim', explode("\n", $normalized));
  return h(trim(implode("\n", $lines)));
}

function selectedText(array $map, string $key): string
{
  return $map[$key] ?? $key;
}

function customOrderBuildUrl(?int $orderId = null, array $extraParams = [], bool $includeOrder = true): string
{
  global $statusFilter, $query, $customOrderHelpLang, $editItemId;

  $params = ['page' => 'custom_orders'];
  if ($statusFilter !== '') {
    $params['status'] = $statusFilter;
  }
  if ($query !== '') {
    $params['q'] = $query;
  }
  if ($customOrderHelpLang !== '') {
    $params['help_lang'] = $customOrderHelpLang;
  }
  if ($includeOrder && $orderId !== null && $orderId > 0) {
    $params['custom_order_id'] = $orderId;
  }
  if ($includeOrder && $editItemId > 0 && !array_key_exists('edit_item_id', $extraParams) && $orderId !== null && $orderId > 0) {
    $params['edit_item_id'] = $editItemId;
  }
  foreach ($extraParams as $key => $value) {
    if ($value === null || $value === '') {
      unset($params[$key]);
      continue;
    }
    $params[$key] = (string) $value;
  }

  return 'index.php?' . http_build_query($params);
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
    'payment_method' => 'Sposob platby pre finalnu objednavku, napriklad PayPal, Card, Bank Transfer.',
    'billing_name' => 'Fakturacne meno alebo meno zakaznika na fakture.',
    'billing_company' => 'Fakturacna firma, ak sa pouziva.',
    'billing_company_id' => 'ICO / VAT / Company ID pre fakturacnu adresu, ak je potrebne.',
    'billing_street' => 'Ulica a cislo pre fakturacnu adresu.',
    'billing_city' => 'Mesto pre fakturacnu adresu.',
    'billing_zip' => 'PSC / ZIP pre fakturacnu adresu.',
    'billing_country' => '2-letter kod krajiny fakturacnej adresy.',
    'billing_email' => 'Fakturacny email, ak sa ma lisit od shipping alebo customer emailu.',
    'billing_phone' => 'Fakturacny telefon, ak sa ma lisit.',
    'currency' => 'Odporucane 3-letter kody meny: EUR, USD, GBP.',
    'shipping_name' => 'Meno prijemcu. Povinne pre export do production orders.',
    'shipping_company' => 'Volitelna firma prijemcu.',
    'shipping_company_id' => 'ICO / VAT / Company ID pre shipping adresu, ak je potrebne.',
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
    'payment_method' => 'Final payment method for the order, for example PayPal, Card, Bank Transfer.',
    'billing_name' => 'Billing name or customer name to appear on the invoice.',
    'billing_company' => 'Billing company if used.',
    'billing_company_id' => 'VAT / Company ID for the billing address if needed.',
    'billing_street' => 'Street and house number for the billing address.',
    'billing_city' => 'City for the billing address.',
    'billing_zip' => 'Postal code / ZIP for the billing address.',
    'billing_country' => '2-letter country code for the billing address.',
    'billing_email' => 'Billing email if it should differ from customer or shipping email.',
    'billing_phone' => 'Billing phone if it should differ.',
    'currency' => 'Recommended 3-letter currency codes: EUR, USD, GBP.',
    'shipping_name' => 'Recipient name. Required for export to production orders.',
    'shipping_company' => 'Optional recipient company.',
    'shipping_company_id' => 'VAT / Company ID for the shipping address if needed.',
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

function customOrderLoadSpecDropdownOptions(mysqli $conn, string $specKey): array
{
  $options = [];
  $stmt = $conn->prepare("
    SELECT label, value
    FROM product_spec_options
    WHERE spec_key = ? AND active = 1
    ORDER BY sort_order ASC, id ASC
  ");
  if (!$stmt) {
    return $options;
  }

  $stmt->bind_param('s', $specKey);
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $value = trim((string) ($row['value'] ?? ''));
      $label = trim((string) ($row['label'] ?? ''));
      if ($value === '' || $label === '') {
        continue;
      }
      $options[] = ['value' => $value, 'label' => $label];
    }
  }
  $stmt->close();

  return $options;
}

function customOrderFlagIcon(string $countryCode, string $label): string
{
  $countryCode = strtolower(trim($countryCode));
  if (!preg_match('/^[a-z]{2}$/', $countryCode)) {
    return '';
  }

  return '<img src="https://flagcdn.com/16x12/' . htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') . '.png" '
    . 'alt="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" '
    . 'title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" '
    . 'style="width:16px;height:12px;object-fit:cover;border-radius:2px;vertical-align:-1px;">';
}

function customOrderTruncate(string $value, int $limit = 36): string
{
  $value = trim($value);
  if ($value === '') {
    return '<span class="text-muted">-</span>';
  }

  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($value, 'UTF-8') <= $limit) {
      return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    $short = rtrim(mb_substr($value, 0, max(1, $limit - 3), 'UTF-8')) . '...';
  } else {
    if (strlen($value) <= $limit) {
      return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    $short = rtrim(substr($value, 0, max(1, $limit - 3))) . '...';
  }

  return '<span title="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . '</span>';
}

function customOrderCopyButton(string $value): string
{
  $value = trim($value);
  if ($value === '') {
    return '';
  }

  return ' <button type="button" class="btn btn-xs btn-copy-inline" data-copy="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" title="Copy">📋</button>';
}

function customOrderNoteTypeLabel(string $type): string
{
  switch (strtoupper(trim($type))) {
    case 'CUSTOMER':
      return 'Customer note';
    case 'REVISION':
      return 'Revision';
    default:
      return 'Internal note';
  }
}

function customOrderBuilderSpecLabel(string $itemTypeCode, array $definition): string
{
  $label = trim((string) ($definition['label'] ?? $definition['spec_key'] ?? ''));
  $sourceKey = trim((string) ($definition['source_key'] ?? ''));
  $itemTypeCode = strtoupper(trim($itemTypeCode));

  if ($itemTypeCode === 'G' && $sourceKey === 'note') {
    return 'Buyer Note';
  }

  return $label;
}

function customOrderItemOptionGroups(mysqli $conn, string $itemTypeCode, array $options): array
{
  $department = customOrdersItemTypeToDepartment($itemTypeCode);
  $sortMap = [];

  foreach (productSpecFieldDefinitions($conn, $department) as $definition) {
    $sourceKey = productSpecNormalizeSourceKey((string) ($definition['source_key'] ?? ''));
    if ($sourceKey === '') {
      continue;
    }

    $sortMap[$sourceKey] = (int) ($definition['field_sort_order'] ?? 999);
  }

  $groups = [
    'spec_rows' => [],
    'note_rows' => [],
  ];

  foreach ($options as $rawKey => $rawValue) {
    if (!is_scalar($rawValue) && $rawValue !== null) {
      continue;
    }

    $value = trim((string) $rawValue);
    if ($value === '') {
      continue;
    }

    $normalizedKey = productSpecNormalizeSourceKey((string) $rawKey);
    if ($normalizedKey === '' || strpos($normalizedKey, '_') === 0) {
      continue;
    }
    if (in_array($normalizedKey, ['category-info', 'name', 'number'], true)) {
      continue;
    }

    $row = [
      'label' => productSpecDisplayLabelForOptionKey($conn, (string) $rawKey, $department),
      'value' => $value,
      'sort_order' => $sortMap[$normalizedKey] ?? 999,
    ];

    if (in_array($normalizedKey, ['note', 'buyer-note', 'my-item-note'], true)) {
      $groups['note_rows'][] = $row;
      continue;
    }

    $groups['spec_rows'][] = $row;
  }

  $sortRows = static function (array &$rows): void {
    usort($rows, static function (array $a, array $b): int {
      $sortCompare = ((int) ($a['sort_order'] ?? 999)) <=> ((int) ($b['sort_order'] ?? 999));
      if ($sortCompare !== 0) {
        return $sortCompare;
      }

      return strnatcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });
  };

  $sortRows($groups['spec_rows']);
  $sortRows($groups['note_rows']);

  return $groups;
}

function customOrderRenderSpecFieldInput(mysqli $conn, array $definition, array $editOptions, array $selectedOrder, string $cssClass = '', string $extraAttr = ''): string
{
  $specKey = trim((string) ($definition['spec_key'] ?? ''));
  $sourceKey = trim((string) ($definition['source_key'] ?? ''));
  $fieldName = 'spec_' . $specKey;
  $currentValue = trim((string) ($editOptions[$sourceKey] ?? ''));

  if ($currentValue === '' && $sourceKey === 'name') {
    $currentValue = trim((string) ($selectedOrder['rider_name'] ?? ''));
  }
  if ($currentValue === '' && $sourceKey === 'number') {
    $currentValue = trim((string) ($selectedOrder['rider_number'] ?? ''));
  }

  return renderProductSpecField(
    $conn,
    $specKey,
    $currentValue,
    [],
    $cssClass,
    trim('name="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '" ' . $extraAttr)
  );
}

$customOrderHelpLang = customOrderResolveHelpLanguage();
$graphicsMaterialOptions = customOrderLoadSpecDropdownOptions($conn, 'graphics_material');
$graphicsFinishOptions = customOrderLoadSpecDropdownOptions($conn, 'graphics_finish');
$graphicsSubcategoryLabels = customOrdersGraphicsSubcategoryLabels();
$productSpecDefinitions = [
  'G' => productSpecFieldDefinitions($conn, 'G'),
  'P' => productSpecFieldDefinitions($conn, 'P'),
  'S' => productSpecFieldDefinitions($conn, 'S'),
  'F' => productSpecFieldDefinitions($conn, 'F'),
];
if (!isset(customOrdersAllowedItemTypes()[$builderType])) {
  $builderType = $editItem ? strtoupper((string) ($editItem['item_type_code'] ?? '')) : '';
}
?>
<style>
  .custom-orders-grid {
    display: grid;
    grid-template-columns: 420px 1fr;
    gap: 16px;
  }

  /*pozadie fieldsetov */
  .custom-orders-panel {
    border: 1px solid #495057;
    border-radius: 8px;
    background: #20252b;
  }

  .custom-orders-panel .panel-body {
    padding: 14px;
  }

  .custom-order-list-row {
    border-bottom: 1px solid #343a40;
    padding: 10px 12px;
    display: block;
    color: #f8f9fa;
  }

  /*bočné karty*/
  .custom-order-list-row:hover,
  .custom-order-list-row.active {
    background: #2d343c;
    color: #fff;
    text-decoration: none;
  }

  .custom-order-meta {
    font-size: 12px;
    color: #adb5bd;
  }

  .custom-order-table-row {
    cursor: pointer;
  }

  .custom-order-table-row:hover td {
    background: rgba(60, 141, 188, .12);
  }

  .custom-status-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
  }

  .custom-status-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, .12);
    background: rgba(255, 255, 255, .04);
    color: #e6edf3;
    font-size: 13px;
    text-decoration: none;
  }

  .custom-status-tab:hover {
    color: #fff;
    text-decoration: none;
    border-color: rgba(255, 255, 255, .24);
    background: rgba(255, 255, 255, .08);
  }

  .custom-status-tab.active {
    background: rgba(60, 141, 188, .24);
    border-color: rgba(60, 141, 188, .55);
    color: #fff;
  }

  .custom-status-tab-count {
    min-width: 20px;
    padding: 1px 7px;
    border-radius: 999px;
    background: rgba(17, 24, 39, .48);
    font-weight: 700;
    text-align: center;
  }

  .custom-order-section-title {
    font-size: 13px;
    letter-spacing: .05em;
    color: #adb5bd;
    text-transform: uppercase;
    margin-bottom: 10px;
  }

  .custom-order-block {
    margin-bottom: 16px;
  }

  .custom-order-subgrid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
  }

.custom-field-cluster {
  position: relative;
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 10px;
  background: rgba(255,255,255,.025);
  padding: 34px 12px 12px;
}

.custom-field-cluster-title {
  position: absolute;
  top: 12px;
  left: 12px;

  display: block;
  width: auto;
  margin: 0;
  padding: 0;

  float: none;
  border: 0;

  font-size: 12px;
  font-weight: 700;
  color: #cfd6dc;
  letter-spacing: .05em;
  text-transform: uppercase;
  line-height: 1;
}

.custom-inline-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

  .custom-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(17, 24, 39, .28);
    border: 1px solid rgba(255, 255, 255, .08);
    font-size: 12px;
  }

  .custom-summary-list {
    display: grid;
    gap: 8px;
  }

  .custom-summary-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 9px 10px;
    border-radius: 8px;
    background: rgba(17, 24, 39, .24);
    border: 1px solid rgba(255, 255, 255, .06);
  }

  .custom-summary-row strong {
    color: #adb5bd;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
  }

  .custom-notes-timeline {
    display: grid;
    gap: 10px;
  }

  .custom-note-entry {
    border-left: 3px solid rgba(60, 141, 188, .65);
    border-radius: 8px;
    background: rgba(255, 255, 255, .03);
    padding: 10px 12px;
  }

  .custom-note-entry-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 6px;
    color: #adb5bd;
    font-size: 12px;
  }

  .custom-note-entry-body {
    color: #f8f9fa;
    white-space: pre-wrap;
    line-height: 1.45;
  }

  .custom-item-spec-groups {
    display: grid;
    gap: 12px;
  }

  .custom-item-spec-group {
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 10px;
    background: rgba(255, 255, 255, .03);
    padding: 12px;
  }

  .custom-item-spec-group[hidden] {
    display: none !important;
  }

  .custom-item-spec-group-title {
    font-size: 12px;
    font-weight: 700;
    color: #cfd6dc;
    letter-spacing: .05em;
    text-transform: uppercase;
    margin-bottom: 10px;
  }

  .custom-optical-divider {
    height: 1px;
    margin: 18px 0;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .16), transparent);
  }

  .custom-item-builder-shell {
    border: 1px solid rgba(60, 141, 188, .28);
    border-radius: 12px;
    background: rgba(60, 141, 188, .06);
    padding: 12px;
  }

  .custom-item-builder-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
  }

  .custom-item-builder-title {
    font-size: 12px;
    font-weight: 700;
    color: #cfd6dc;
    letter-spacing: .05em;
    text-transform: uppercase;
  }

  .custom-builder-picker {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 18px;
    margin-bottom: 12px;
  }

  .custom-builder-picker-copy {
    flex: 1 1 auto;
    min-width: 0;
  }

  .custom-builder-picker-label {
    flex: 0 0 220px;
    max-width: 220px;
  }

  .custom-builder-placeholder {
    margin-top: 8px;
    color: #8f9ba7;
    font-size: 13px;
  }

  .custom-builder-order-shell {
    border: 1px solid rgba(255, 255, 255, .1);
    border-radius: 10px;
    background: rgba(255, 255, 255, .015);
    overflow: hidden;
  }

  .custom-builder-order-shell[hidden] {
    display: none !important;
  }

  .custom-builder-subtitle {
    padding: 12px 14px 8px;
    color: #f4f6f8;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
  }

  .custom-builder-order-table {
    margin-bottom: 0;
  }

  .custom-builder-order-table td,
  .custom-builder-order-table th {
    outline: none !important;
    vertical-align: middle;
  }

  .custom-builder-order-table tr.item-repeat-header-row>th {
    background-color: #343a40 !important;
    font-weight: 600;
    font-size: .78rem;
    color: #f0f3f6;
    padding: .35rem .6rem !important;
    border-top: 2px solid rgba(255, 255, 255, .15) !important;
    border-bottom: 1px solid rgba(255, 255, 255, .1) !important;
    border-left: 1px solid rgba(255, 255, 255, .14) !important;
    border-right: 1px solid rgba(255, 255, 255, .14) !important;
    white-space: nowrap;
  }

  .custom-builder-order-table tbody tr.item-info-row>td,
  .custom-builder-order-table tbody tr.g-item-options-row>td {
    border-top: 1px solid rgba(255, 255, 255, .24) !important;
    border-bottom: 1px solid rgba(255, 255, 255, .24) !important;
    background-clip: padding-box;
  }

  .custom-builder-order-table tbody tr.item-info-row>td:first-child,
  .custom-builder-order-table tbody tr.g-item-options-row>td:first-child {
    border-left: 3px solid var(--item-accent, #8a8f98) !important;
  }

  .custom-builder-order-table tbody tr.item-info-row>td:last-child,
  .custom-builder-order-table tbody tr.g-item-options-row>td:last-child {
    border-right: 1px solid rgba(255, 255, 255, .24) !important;
  }

  .custom-builder-order-table tbody tr.g-item-options-row>td[colspan] {
    border-left: 3px solid var(--item-accent, #17a2b8) !important;
    border-right: 1px solid rgba(255, 255, 255, .24) !important;
  }

  .custom-builder-order-table tbody tr.g-item-options-row>td {
    border-top: 1px solid rgba(255, 255, 255, .32) !important;
    background: rgba(23, 162, 184, .045) !important;
    padding: 5px 8px 7px !important;
  }

  .custom-builder-order-table tbody tr.item-spacer-row>td {
    height: 8px !important;
    padding: 0 !important;
    border: none !important;
    background: transparent !important;
    box-shadow: none !important;
  }

  .custom-builder-order-table tr.item-type-G {
    --item-accent: #28a745;
  }

  .custom-builder-order-table tr.item-type-P,
  .custom-builder-order-table tr.item-type-T,
  .custom-builder-order-table tr.item-type-M {
    --item-accent: #17a2b8;
  }

  .custom-builder-order-table tr.item-type-S {
    --item-accent: #ebd618;
  }

  .custom-builder-order-table tr.item-type-F {
    --item-accent: #fd7e14;
  }

  .custom-builder-order-table tr.item-type-G.item-info-row>td,
  .custom-builder-order-table tr.item-type-G.g-item-options-row>td {
    background: rgba(23, 163, 184, .2) !important;
  }

  .custom-builder-order-table tr.item-type-P.item-info-row>td,
  .custom-builder-order-table tr.item-type-P.g-item-options-row>td,
  .custom-builder-order-table tr.item-type-T.item-info-row>td,
  .custom-builder-order-table tr.item-type-T.g-item-options-row>td,
  .custom-builder-order-table tr.item-type-M.item-info-row>td,
  .custom-builder-order-table tr.item-type-M.g-item-options-row>td {
    background: rgba(76, 142, 247, .05) !important;
  }

  .custom-builder-order-table tr.item-type-S.item-info-row>td,
  .custom-builder-order-table tr.item-type-S.g-item-options-row>td {
    background: rgba(40, 167, 69, .05) !important;
  }

  .custom-builder-order-table tr.item-type-F.item-info-row>td,
  .custom-builder-order-table tr.item-type-F.g-item-options-row>td {
    background: rgba(253, 126, 20, .05) !important;
  }

  .custom-builder-type-badge {
    min-width: 28px;
    height: 28px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: #6c757d;
    color: #fff;
    font-weight: 700;
    text-align: center;
  }

  .custom-builder-assigned-placeholder {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, .18);
    background: rgba(255, 255, 255, .04);
    color: #cdd6df;
    font-size: 11px;
    font-weight: 700;
  }

  .custom-builder-order-table .g-options-bar {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    gap: 6px;
    width: 100%;
  }

  .custom-builder-order-table .g-options-bar .product-spec-label {
    display: flex;
    flex: 1 1 0 !important;
    min-width: 0 !important;
    margin: 0 !important;
    flex-direction: column;
    gap: 4px;
    padding: 6px;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 6px;
    background: rgba(255, 255, 255, .025);
    height: 100%;
  }

  .custom-builder-order-table .g-options-bar .product-spec-label select,
  .custom-builder-order-table .g-options-bar .product-spec-label input {
    flex: 1;
  }

  .custom-builder-order-table .product-spec-label-title {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .04em;
    color: #d7dee7;
    line-height: 1.1;
  }

  .custom-builder-order-table .g-opt-note-display {
    flex: 1;
    min-height: 31px;
    padding: .25rem .5rem;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: .2rem;
    background-color: transparent;
    color: inherit;
    line-height: 1.5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  .custom-builder-order-table .custom-builder-mini-btn {
    min-width: 44px;
  }

  .custom-builder-order-table .custom-builder-link-btn {
    padding: .2rem .5rem;
  }

  .custom-kpi {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
    margin-bottom: 14px;
  }

  /*vrchné karty*/
  .custom-kpi-card {
    border: 1px solid #495057;
    border-radius: 8px;
    padding: 10px;
    background: #252c33;
  }

  .custom-form-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
  }

  .custom-form-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }

  .custom-form-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }

  .custom-form-full {
    grid-column: 1 / -1;
  }

  .custom-mini-table td,
  .custom-mini-table th {
    padding: 6px 8px;
    font-size: 13px;
  }

  .custom-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

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
    border: 1px solid rgba(255, 255, 255, .35);
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
    border-color: rgba(255, 255, 255, .55);
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

  .custom-help-lang-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  /** design modalu. 
      Najdôležitejšie bloky pre modal sú:
    .custom-item-modal .modal-content
    .custom-item-modal .modal-header
    .custom-item-modal .modal-body
    .custom-item-section
    .custom-item-detail-row
    .custom-item-summary-card
    .custom-item-meta-pill
    .custom-item-note-box
    Ak budú frflať na kontrast, najrýchlejšie bude doladť hlavne tieto hodnoty:
    background
    border
    color
    prípadne box-shadow **/
    
  .custom-item-modal .modal-content {
    border: 1px solid #56606b;
    border-radius: 12px;
    overflow: hidden;
    background: #242a31;
    box-shadow: 0 20px 60px rgba(0, 0, 0, .45);
  }

  .custom-item-modal .modal-header {
    border-bottom: 1px solid rgba(255, 255, 255, .08);
    background: linear-gradient(180deg, rgba(255, 255, 255, .03), rgba(255, 255, 255, 0));
    align-items: flex-start;
  }

  .custom-item-modal .modal-title {
    font-size: 20px;
    font-weight: 700;
    line-height: 1.2;
  }

  .custom-item-modal .modal-subtitle {
    margin-top: 4px;
    color: #adb5bd;
    font-size: 12px;
    letter-spacing: .04em;
    text-transform: uppercase;
  }

  .custom-item-modal .modal-body {
    padding: 18px;
    background:
      radial-gradient(circle at top right, rgba(60, 141, 188, .10), transparent 34%),
      #242a31;
  }

  .custom-item-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 16px;
  }

  .custom-item-summary-card {
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 10px;
    padding: 10px 12px;
    background: rgba(255, 255, 255, .03);
  }

  .custom-item-summary-card-label {
    color: #9aa4ad;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 4px;
  }

  .custom-item-summary-card-value {
    font-size: 15px;
    font-weight: 700;
    color: #f8f9fa;
    word-break: break-word;
  }

  .custom-item-sections {
    display: grid;
    grid-template-columns: 1.2fr .8fr;
    gap: 14px;
  }

  .custom-item-section {
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 12px;
    background: rgba(255, 255, 255, .03);
    padding: 14px;
  }

  .custom-item-section-title {
    color: #cfd6dc;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-bottom: 10px;
  }

  .custom-item-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .custom-item-detail-row {
    border: 1px solid rgba(255, 255, 255, .06);
    border-radius: 10px;
    padding: 10px 12px;
    background: rgba(17, 24, 39, .18);
  }

  .custom-item-detail-row.is-full {
    grid-column: 1 / -1;
  }

  .custom-item-detail-label {
    color: #98a3ad;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 4px;
  }

  .custom-item-detail-value {
    color: #f8f9fa;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.35;
    word-break: break-word;
  }

  .custom-item-meta-list {
    display: grid;
    gap: 8px;
  }

  .custom-item-meta-pill {
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 999px;
    padding: 8px 12px;
    background: rgba(17, 24, 39, .18);
    display: flex;
    justify-content: space-between;
    gap: 12px;
    font-size: 13px;
  }

  .custom-item-meta-pill strong {
    color: #9aa4ad;
    font-weight: 600;
  }

  .custom-item-note-box {
    min-height: 90px;
    border: 1px dashed rgba(255, 255, 255, .12);
    border-radius: 10px;
    padding: 12px;
    background: rgba(17, 24, 39, .16);
    color: #f8f9fa;
    line-height: 1.45;
    white-space: pre-line;
  }

  @media (max-width: 1200px) {
    .custom-orders-grid {
      grid-template-columns: 1fr;
    }

    .custom-form-grid,
    .custom-form-grid-2,
    .custom-form-grid-4,
    .custom-kpi,
    .custom-order-subgrid {
      grid-template-columns: 1fr;
    }

    .custom-item-summary,
    .custom-item-sections,
    .custom-item-detail-grid,
    .custom-builder-picker {
      grid-template-columns: 1fr;
    }

    .custom-builder-picker {
      display: block;
    }

    .custom-builder-picker-label {
      max-width: none;
      margin-top: 10px;
    }

    .custom-builder-order-table .g-options-bar {
      flex-wrap: wrap;
    }
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
        <a href="<?= h(customOrderBuildUrl($selectedOrderId > 0 ? $selectedOrderId : null, ['help_lang' => 'sk'])) ?>"
          class="btn <?= $customOrderHelpLang === 'sk' ? 'btn-info' : 'btn-outline-light' ?>">
          <span class="custom-help-lang-btn"><?= customOrderFlagIcon('sk', 'Slovakia') ?><span>SK Help</span></span>
        </a>
        <a href="<?= h(customOrderBuildUrl($selectedOrderId > 0 ? $selectedOrderId : null, ['help_lang' => 'en'])) ?>"
          class="btn <?= $customOrderHelpLang === 'en' ? 'btn-info' : 'btn-outline-light' ?>">
          <span class="custom-help-lang-btn"><?= customOrderFlagIcon('gb', 'United Kingdom') ?><span>EN
              Help</span></span>
        </a>
      </div>
    </div>
    <form method="post" action="scripts/custom_orders/create_order.php" class="mb-0">
      <button type="submit" class="btn btn-success">New Custom Lead</button>
    </form>
  </div>

  <div class="custom-status-tabs">
    <a href="<?= h(customOrderBuildUrl(null, ['status' => null, 'custom_order_id' => null, 'edit_item_id' => null], false)) ?>"
      class="custom-status-tab <?= $statusFilter === '' ? 'active' : '' ?>">
      <span>All</span><span class="custom-status-tab-count"><?= (int) ($statusCounts['_all'] ?? 0) ?></span>
    </a>
    <?php foreach ($statuses as $code => $label): ?>
      <a href="<?= h(customOrderBuildUrl(null, ['status' => $code, 'custom_order_id' => null, 'edit_item_id' => null], false)) ?>"
        class="custom-status-tab <?= $statusFilter === $code ? 'active' : '' ?>">
        <span><?= h($label) ?></span><span class="custom-status-tab-count"><?= (int) ($statusCounts[$code] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="custom-orders-grid">
    <div class="custom-orders-panel">
      <div class="panel-body">
        <form method="get" class="mb-3">
          <input type="hidden" name="page" value="custom_orders">
          <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= h($statusFilter) ?>"><?php endif; ?>
          <?php if ($customOrderHelpLang !== ''): ?><input type="hidden" name="help_lang" value="<?= h($customOrderHelpLang) ?>"><?php endif; ?>
          <div class="form-group">
            <label>Search<?= customOrderHelp('search') ?></label>
            <input type="text" name="q" class="form-control form-control-sm" value="<?= h($query) ?>"
              placeholder="Internal code, official no., customer, handle">
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        </form>

        <div class="custom-order-section-title">Order Seeds</div>
        <form method="post" action="scripts/custom_orders/update_sequences.php" class="mb-3">
          <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrderId ?>">
          <div class="custom-form-grid-4">
            <div><label>SO next seed<?= customOrderHelp('seq_so') ?></label><input type="number" name="seq_so"
                class="form-control form-control-sm" value="<?= (int) $sequences['SO'] ?>"></div>
            <div><label>GO next seed<?= customOrderHelp('seq_go') ?></label><input type="number" name="seq_go"
                class="form-control form-control-sm" value="<?= (int) $sequences['GO'] ?>"></div>
            <div><label>SC next seed<?= customOrderHelp('seq_sc') ?></label><input type="number" name="seq_sc"
                class="form-control form-control-sm" value="<?= (int) $sequences['SC'] ?>"></div>
            <div class="d-flex align-items-end"><button type="submit" class="btn btn-outline-light btn-sm w-100">Save
                Seeds</button></div>
          </div>
        </form>

        <?php if ($selectedOrder): ?>
          <a href="<?= h(customOrderBuildUrl(null, ['custom_order_id' => null, 'edit_item_id' => null], false)) ?>"
            class="btn btn-outline-light btn-sm btn-block mb-3">Back To Orders List</a>
          <div class="custom-order-section-title">Customer Context</div>
          <div class="custom-field-cluster mb-3">
            <div class="custom-summary-list">
              <div class="custom-summary-row"><strong>Customer</strong><span><?= h($selectedOrder['customer_name'] ?: 'Unnamed lead') ?></span></div>
              <div class="custom-summary-row"><strong>Handle</strong><span><?= h($selectedOrder['social_handle'] ?: '-') ?></span></div>
              <div class="custom-summary-row"><strong>Email</strong><span><?= h($selectedOrder['customer_email'] ?: '-') ?></span></div>
              <div class="custom-summary-row"><strong>Phone</strong><span><?= h($selectedOrder['customer_phone'] ?: $selectedOrder['shipping_phone'] ?: $selectedOrder['billing_phone'] ?: '-') ?></span></div>
              <div class="custom-summary-row"><strong>Country</strong><span><?= h($selectedOrder['customer_country'] ?: $selectedOrder['shipping_country'] ?: '-') ?></span></div>
            </div>
          </div>

          <div class="custom-order-section-title">Lead Actions</div>
          <div class="custom-field-cluster mb-3">
            <div class="custom-summary-list mb-3">
              <div class="custom-summary-row"><strong>Internal</strong><span><?= h($selectedOrder['internal_code']) ?></span></div>
              <div class="custom-summary-row"><strong>Official</strong><span><?= h($selectedOrder['official_order_number'] ?: 'Not assigned') ?></span></div>
              <div class="custom-summary-row"><strong>Owner</strong><span><?= h($selectedOrder['owner_name'] ?: 'Unassigned') ?></span></div>
            </div>
            <form method="post" action="scripts/custom_orders/assign_owner.php" class="mb-2">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <div class="input-group input-group-sm">
                <select name="owner_employee_id" class="form-control<?= customOrderInvalid($invalidFields, 'owner') ?>">
                  <?php foreach ($assignableEmployees as $employee): ?>
                    <?php $employeeName = trim(((string) ($employee['firstname'] ?? '')) . ' ' . ((string) ($employee['lastname'] ?? ''))); ?>
                    <option value="<?= (int) $employee['id'] ?>" <?= (int) ($selectedOrder['owner_employee_id'] ?? 0) === (int) $employee['id'] ? 'selected' : '' ?>><?= h($employeeName) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="input-group-append"><button type="submit" class="btn btn-info">Assign</button></div>
              </div>
            </form>
            <form method="post" action="scripts/custom_orders/assign_official_number.php" class="mb-2">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <div class="input-group input-group-sm">
                <select name="official_prefix" class="form-control<?= customOrderInvalid($invalidFields, 'official_prefix') ?>">
                  <option value="SO" <?= ($selectedOrder['official_prefix'] ?? 'SO') === 'SO' ? 'selected' : '' ?>>SO</option>
                  <option value="GO" <?= ($selectedOrder['official_prefix'] ?? '') === 'GO' ? 'selected' : '' ?>>GO</option>
                  <option value="SC" <?= ($selectedOrder['official_prefix'] ?? '') === 'SC' ? 'selected' : '' ?>>SC</option>
                </select>
                <div class="input-group-append"><button type="submit" class="btn btn-warning">Official No.</button></div>
              </div>
            </form>
            <form method="post" action="scripts/custom_orders/export_order.php" class="mb-2">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <button type="submit" class="btn btn-primary btn-sm btn-block" <?= (int) ($selectedOrder['production_order_id'] ?? 0) > 0 ? 'disabled' : '' ?>>Export To Production</button>
            </form>
            <?php if ((int) ($selectedOrder['production_order_id'] ?? 0) > 0): ?>
              <a class="btn btn-outline-success btn-sm btn-block" href="index.php?page=orders&q=<?= urlencode((string) $selectedOrder['official_order_number']) ?>">Open Production Order</a>
            <?php endif; ?>
          </div>
          <div class="custom-order-section-title">Related Orders</div>
          <div style="max-height: 46vh; overflow:auto;">
            <?php foreach ($relatedOrders as $row): ?>
              <?php $isActive = (int) $row['id'] === $selectedOrderId; ?>
              <a class="custom-order-list-row <?= $isActive ? 'active' : '' ?>"
                href="<?= h(customOrderBuildUrl((int) $row['id'], ['edit_item_id' => null])) ?>">
                <div class="d-flex justify-content-between">
                  <strong><?= h($row['official_order_number'] ?: $row['internal_code']) ?></strong>
                  <span
                    class="badge badge-<?= $row['status'] === 'EXPORTED' ? 'success' : ($row['status'] === 'DEAD' ? 'danger' : 'warning') ?>"><?= h(selectedText($statuses, (string) $row['status'])) ?></span>
                </div>
                <div><?= h($row['customer_name'] ?: $row['social_handle'] ?: 'Unnamed lead') ?></div>
                <div class="custom-order-meta">
                  Owner <?= h($row['owner_name'] ?: '-') ?> | Updated <?= h(date('d.m.Y H:i', strtotime((string) $row['updated_at']))) ?>
                </div>
                <div class="custom-order-meta">
                  Items <?= (int) $row['item_count'] ?> | Total
                  <?= number_format((float) (($row['item_total'] ?? 0) + ($row['shipping_price'] ?? 0)), 2) ?>
                  <?= h($row['currency'] ?: '') ?>
                </div>
              </a>
            <?php endforeach; ?>
            <?php if (!$relatedOrders): ?>
              <div class="text-muted">No related orders found for this customer yet.</div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="custom-order-section-title">Workspace Mode</div>
          <div class="custom-field-cluster">
            <div class="text-muted small mb-2">List view is now the default landing mode for browsing all leads.</div>
            <div class="custom-summary-list">
              <div class="custom-summary-row"><strong>Filtered rows</strong><span><?= count($listRows) ?></span></div>
              <div class="custom-summary-row"><strong>Status scope</strong><span><?= h($statusFilter !== '' ? selectedText($statuses, $statusFilter) : 'All') ?></span></div>
              <div class="custom-summary-row"><strong>Search</strong><span><?= h($query !== '' ? $query : 'None') ?></span></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div>
      <?php if (!$selectedOrder): ?>
        <div class="custom-orders-panel">
          <div class="panel-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div class="custom-order-section-title mb-0">Orders List</div>
              <div class="text-muted small">Showing up to 300 most recently updated rows</div>
            </div>
            <div class="table-responsive">
              <table class="table table-sm table-dark table-striped custom-mini-table mb-0">
                <thead>
                  <tr>
                    <th>Official / Internal</th>
                    <th>Customer</th>
                    <th>Handle</th>
                    <th>Country</th>
                    <th>Status</th>
                    <th>Owner</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Updated</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($listRows as $row): ?>
                    <?php $rowUrl = customOrderBuildUrl((int) $row['id'], ['edit_item_id' => null]); ?>
                    <tr class="custom-order-table-row" data-href="<?= h($rowUrl) ?>">
                      <td>
                        <div><strong><?= h($row['official_order_number'] ?: $row['internal_code']) ?></strong></div>
                        <div class="custom-order-meta"><?= h($row['official_order_number'] ? $row['internal_code'] : 'No official number yet') ?></div>
                      </td>
                      <td><?= h($row['customer_name'] ?: 'Unnamed lead') ?></td>
                      <td><?= h($row['social_handle'] ?: '-') ?></td>
                      <td><?= h($row['customer_country'] ?: $row['shipping_country'] ?: '-') ?></td>
                      <td><?= h(selectedText($statuses, (string) $row['status'])) ?></td>
                      <td><?= h($row['owner_name'] ?: '-') ?></td>
                      <td><?= (int) $row['item_count'] ?></td>
                      <td><?= number_format((float) (($row['item_total'] ?? 0) + ($row['shipping_price'] ?? 0)), 2) ?> <?= h($row['currency'] ?: '') ?></td>
                      <td><?= h(date('d.m.Y H:i', strtotime((string) $row['updated_at']))) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$listRows): ?>
                    <tr>
                      <td colspan="9" class="text-muted">No custom orders found for the current filter.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php else: ?>
        <?php $summary = $selectedOrder['summary']; ?>
        <?php $productionOverview = $selectedOrder['production_overview'] ?? ['order' => null, 'billing' => null, 'shipping' => null, 'invoices' => [], 'tracking' => []]; ?>
        <div class="custom-kpi">
          <div class="custom-kpi-card">
            <div class="text-muted">Internal</div><strong><?= h($selectedOrder['internal_code']) ?></strong>
          </div>
          <div class="custom-kpi-card">
            <div class="text-muted">Official</div>
            <strong><?= h($selectedOrder['official_order_number'] ?: 'Not assigned') ?></strong>
          </div>
          <div class="custom-kpi-card">
            <div class="text-muted">Owner<?= customOrderHelp('owner') ?></div>
            <strong><?= h($selectedOrder['owner_name'] ?: 'Unassigned') ?></strong>
          </div>
          <div class="custom-kpi-card">
            <div class="text-muted">Gross Total</div><strong><?= number_format((float) $summary['gross_total'], 2) ?>
              <?= h($selectedOrder['currency']) ?></strong>
          </div>
          <div class="custom-kpi-card">
            <div class="text-muted">Deposits</div><strong><?= number_format((float) $summary['deposit_total'], 2) ?>
              <?= h($selectedOrder['currency']) ?></strong>
          </div>
        </div>

        <datalist id="custom-contact-suggestions">
          <?php foreach ($suggestions as $sg): ?>
            <?php $label = trim(implode(' | ', array_filter([(string) ($sg['name'] ?? ''), (string) ($sg['social_handle'] ?? ''), (string) ($sg['email'] ?? ''), (string) ($sg['phone'] ?? '')]))); ?>
            <?php if ($label !== ''): ?>
              <option value="<?= h($label) ?>"></option><?php endif; ?>
          <?php endforeach; ?>
        </datalist>

        <div id="custom-order-accounting-panel"
          data-scroll-block
          class="custom-orders-panel mb-3<?= isset($invalidFields['customer_name']) || isset($invalidFields['social_handle']) || isset($invalidFields['shipping_name']) || isset($invalidFields['shipping_street']) || isset($invalidFields['shipping_city']) || isset($invalidFields['shipping_zip']) || isset($invalidFields['shipping_country']) || isset($invalidFields['customer_email']) || isset($invalidFields['shipping_email']) || isset($invalidFields['shipping_phone']) || isset($invalidFields['shipping_price']) ? ' custom-panel-invalid' : '' ?>">
          <div class="panel-body">
            <div class="custom-order-section-title">1. Accounting, Addresses And Production</div>
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
            <form method="post" action="scripts/custom_orders/save_order.php" data-scroll-target="#custom-order-accounting-panel">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <div class="custom-order-subgrid">
                <fieldset class="custom-field-cluster custom-form-full">
                  <legend class="custom-field-cluster-title">Payment And Delivery Methods</legend>
                  <div class="custom-form-grid-4">
                    <div><label>Payment<?= customOrderHelp('payment_method') ?></label><input type="text" name="payment_method" class="form-control form-control-sm" value="<?= h($selectedOrder['payment_method'] ?? '') ?>" placeholder="PayPal, Card, Bank Transfer"></div>
                    <div><label>Shipping<?= customOrderHelp('shipping_method') ?></label><input type="text" name="shipping_method" class="form-control form-control-sm" value="<?= h($selectedOrder['shipping_method']) ?>" placeholder="FedEx International Economy"></div>
                    <div><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_price')) ?>">Shipping price<?= customOrderHelp('shipping_price') ?></label><input type="number" step="0.01" name="shipping_price" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_price') ?>" value="<?= h($selectedOrder['shipping_price']) ?>"></div>
                    <div><label>Currency<?= customOrderHelp('currency') ?></label><input type="text" name="currency" class="form-control form-control-sm" value="<?= h($selectedOrder['currency']) ?>"></div>
                  </div>
                </fieldset>

                <fieldset class="custom-field-cluster custom-form-full">
                  <legend class="custom-field-cluster-title">Lead Identity And Billing</legend>
                  <div class="custom-form-grid">
                    <div><label>Status<?= customOrderHelp('status') ?></label><select name="status" class="form-control form-control-sm"><?php foreach ($statuses as $code => $label): ?><option value="<?= h($code) ?>" <?= $selectedOrder['status'] === $code ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
                    <div><label>Source channel<?= customOrderHelp('source_channel') ?></label><input type="text" name="source_channel" class="form-control form-control-sm" value="<?= h($selectedOrder['source_channel']) ?>" placeholder="Instagram, WhatsApp, Email"></div>
                    <div><label>Communication platform<?= customOrderHelp('social_platform') ?></label><input type="text" name="social_platform" class="form-control form-control-sm" value="<?= h($selectedOrder['social_platform']) ?>"></div>
                    <div><label class="<?= trim(customOrderInvalid($invalidFields, 'social_handle')) ?>">Social handle<?= customOrderHelp('social_handle') ?></label><input type="text" name="social_handle" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'social_handle') ?>" value="<?= h($selectedOrder['social_handle']) ?>" list="custom-contact-suggestions"></div>
                    <div><label class="<?= trim(customOrderInvalid($invalidFields, 'customer_name')) ?>">Customer name<?= customOrderHelp('customer_name') ?></label><input type="text" name="customer_name" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'customer_name') ?>" value="<?= h($selectedOrder['customer_name']) ?>" list="custom-contact-suggestions"></div>
                    <div><label class="<?= trim(customOrderInvalid($invalidFields, 'customer_email')) ?>">Customer email<?= customOrderHelp('customer_email') ?></label><input type="text" name="customer_email" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'customer_email') ?>" value="<?= h($selectedOrder['customer_email']) ?>"></div>
                    <div><label class="<?= trim(customOrderInvalid($invalidFields, 'customer_phone')) ?>">Customer phone<?= customOrderHelp('customer_phone') ?></label><input type="text" name="customer_phone" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'customer_phone') ?>" value="<?= h($selectedOrder['customer_phone'] ?? '') ?>"></div>
                  </div>
                </fieldset>

                <fieldset class="custom-field-cluster custom-form-full">
                  <legend class="custom-field-cluster-title">Order Financial Rules</legend>
                  <div class="custom-form-grid-4">
                    <div><label>Complexity<?= customOrderHelp('complexity_level') ?></label><input type="number" min="1" max="10" name="complexity_level" class="form-control form-control-sm" value="<?= (int) $selectedOrder['complexity_level'] ?>"></div>
                    <div><label>Revisions included<?= customOrderHelp('deposit_revision_limit') ?></label><input type="number" name="deposit_revision_limit" class="form-control form-control-sm" value="<?= (int) $selectedOrder['deposit_revision_limit'] ?>"></div>
                    <div><label>Revisions used<?= customOrderHelp('deposit_revision_used') ?></label><input type="number" name="deposit_revision_used" class="form-control form-control-sm" value="<?= (int) $selectedOrder['deposit_revision_used'] ?>"></div>
                  </div>
                </fieldset>

                <fieldset class="custom-field-cluster">
                  <legend class="custom-field-cluster-title">Invoicing Address</legend>
                  <div class="custom-form-grid">
                    <div class="custom-form-full"><label>Billing name<?= customOrderHelp('billing_name') ?></label><input type="text" name="billing_name" class="form-control form-control-sm" value="<?= h($selectedOrder['billing_name'] ?? '') ?>" placeholder="Name"></div>
                    <div style="grid-column: span 2;"><label>Company<?= customOrderHelp('billing_company') ?></label><input type="text" name="billing_company" class="form-control form-control-sm" value="<?= h($selectedOrder['billing_company'] ?? '') ?>" placeholder="Company"></div>
                    <div><label>Company ID<?= customOrderHelp('billing_company_id') ?></label><input type="text" name="billing_company_id" class="form-control form-control-sm" value="<?= h($selectedOrder['billing_company_id'] ?? '') ?>" placeholder="Company ID"></div>
                    <div class="custom-form-full"><label>Street<?= customOrderHelp('billing_street') ?></label><input type="text" name="billing_street" class="form-control form-control-sm" value="<?= h($selectedOrder['billing_street'] ?? '') ?>" placeholder="Street"></div>
                    <div class="custom-form-full"><label>City<?= customOrderHelp('billing_city') ?></label><input type="text" name="billing_city" class="form-control form-control-sm" value="<?= h($selectedOrder['billing_city'] ?? '') ?>" placeholder="City"></div>
                    <div class="custom-form-full"><label>ZIP<?= customOrderHelp('billing_zip') ?></label><input type="text" name="billing_zip" class="form-control form-control-sm" value="<?= h($selectedOrder['billing_zip'] ?? '') ?>" placeholder="ZIP"></div>
                    <div class="custom-form-full"><label>Country<?= customOrderHelp('billing_country') ?></label><input type="text" name="billing_country" class="form-control form-control-sm" value="<?= h($selectedOrder['billing_country'] ?? '') ?>" placeholder="Country"></div>
                    <div class="custom-form-full"><label>Email<?= customOrderHelp('billing_email') ?></label><input type="text" name="billing_email" class="form-control form-control-sm" value="<?= h($selectedOrder['billing_email'] ?? '') ?>" placeholder="Email"></div>
                    <div class="custom-form-full"><label>Phone<?= customOrderHelp('billing_phone') ?></label><input type="text" name="billing_phone" class="form-control form-control-sm" value="<?= h($selectedOrder['billing_phone'] ?? '') ?>" placeholder="Phone"></div>
                  </div>
                </fieldset>

                <fieldset class="custom-field-cluster">
                  <legend class="custom-field-cluster-title">Shipping Address</legend>
                  <div class="custom-form-grid">
                    <div class="custom-form-full"><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_name')) ?>">Shipping name<?= customOrderHelp('shipping_name') ?></label><input type="text" name="shipping_name" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_name') ?>" value="<?= h($selectedOrder['shipping_name']) ?>" placeholder="Name"></div>
                    <div style="grid-column: span 2;"><label>Company<?= customOrderHelp('shipping_company') ?></label><input type="text" name="shipping_company" class="form-control form-control-sm" value="<?= h($selectedOrder['shipping_company']) ?>" placeholder="Company"></div>
                    <div><label>Company ID<?= customOrderHelp('shipping_company_id') ?></label><input type="text" name="shipping_company_id" class="form-control form-control-sm" value="<?= h($selectedOrder['shipping_company_id'] ?? '') ?>" placeholder="Company ID"></div>
                    <div class="custom-form-full"><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_street')) ?>">Street<?= customOrderHelp('shipping_street') ?></label><input type="text" name="shipping_street" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_street') ?>" value="<?= h($selectedOrder['shipping_street']) ?>" placeholder="Street"></div>
                    <div class="custom-form-full"><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_city')) ?>">City<?= customOrderHelp('shipping_city') ?></label><input type="text" name="shipping_city" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_city') ?>" value="<?= h($selectedOrder['shipping_city']) ?>" placeholder="City"></div>
                    <div class="custom-form-full"><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_zip')) ?>">ZIP<?= customOrderHelp('shipping_zip') ?></label><input type="text" name="shipping_zip" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_zip') ?>" value="<?= h($selectedOrder['shipping_zip']) ?>" placeholder="ZIP"></div>
                    <div class="custom-form-full"><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_country')) ?>">Country<?= customOrderHelp('shipping_country') ?></label><input type="text" name="shipping_country" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_country') ?>" value="<?= h($selectedOrder['shipping_country']) ?>" placeholder="Country"></div>
                    <div class="custom-form-full"><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_email')) ?>">Email<?= customOrderHelp('shipping_email') ?></label><input type="text" name="shipping_email" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_email') ?>" value="<?= h($selectedOrder['shipping_email']) ?>" placeholder="Email"></div>
                    <div class="custom-form-full"><label class="<?= trim(customOrderInvalid($invalidFields, 'shipping_phone')) ?>">Phone<?= customOrderHelp('shipping_phone') ?></label><input type="text" name="shipping_phone" class="form-control form-control-sm<?= customOrderInvalid($invalidFields, 'shipping_phone') ?>" value="<?= h($selectedOrder['shipping_phone']) ?>" placeholder="Phone"></div>
                  </div>
                </fieldset>
              </div>
              <button type="submit" class="btn btn-success btn-sm mt-3">Save Accounting / Addresses</button>
            </form>

            <div class="custom-optical-divider"></div>

            <div class="custom-order-subgrid">
              <fieldset class="custom-field-cluster" id="custom-order-payments-block" data-scroll-block>
                <legend class="custom-field-cluster-title">Payments And Deposits</legend>
                <form method="post" action="scripts/custom_orders/save_payment.php" data-scroll-target="#custom-order-payments-block">
                  <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                  <div class="custom-form-grid-4">
                    <div><label>Kind<?= customOrderHelp('payment_kind') ?></label><select name="payment_kind"
                        class="form-control form-control-sm"><?php foreach ($paymentKinds as $code => $label): ?>
                          <option value="<?= h($code) ?>"><?= h($label) ?></option><?php endforeach; ?>
                      </select></div>
                    <div><label>PayPal tx ID<?= customOrderHelp('paypal_transaction_id') ?></label><input type="text"
                        name="paypal_transaction_id" class="form-control form-control-sm"></div>
                    <div><label>Amount<?= customOrderHelp('payment_amount') ?></label><input type="number" step="0.01"
                        name="amount" class="form-control form-control-sm" required></div>
                    <div><label>Currency<?= customOrderHelp('payment_currency') ?></label><input type="text" name="currency"
                        class="form-control form-control-sm" value="<?= h($selectedOrder['currency']) ?>"></div>
                    <div><label>Received at<?= customOrderHelp('payment_received_at') ?></label><input type="datetime-local"
                        name="received_at" class="form-control form-control-sm"></div>
                    <div class="custom-form-full"><label>Note<?= customOrderHelp('payment_note') ?></label><input
                        type="text" name="note" class="form-control form-control-sm"></div>
                  </div>
                  <button type="submit" class="btn btn-outline-light btn-sm mt-2">Add Payment</button>
                </form>
                <table class="table table-sm table-dark table-striped custom-mini-table mt-3">
                  <thead>
                    <tr>
                      <th>Kind</th>
                      <th>Amount</th>
                      <th>PayPal</th>
                      <th>Note</th>
                      <th>At</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($selectedOrder['payments'] as $payment): ?>
                      <tr>
                        <td><?= h($payment['payment_kind']) ?></td>
                        <td><?= number_format((float) $payment['amount'], 2) ?></td>
                        <td><?= h($payment['paypal_transaction_id']) ?></td>
                        <td><?= customOrderTruncate((string) ($payment['note'] ?? ''), 42) ?></td>
                        <td><?= h($payment['received_at']) ?></td>
                        <td>
                          <form method="post" action="scripts/custom_orders/delete_payment.php" data-scroll-target="#custom-order-payments-block" onsubmit="return confirm('Delete this payment record?');">
                            <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                            <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </fieldset>

              <fieldset class="custom-field-cluster">
                <legend class="custom-field-cluster-title">Production Snapshot</legend>
                <?php if ((int) ($selectedOrder['production_order_id'] ?? 0) > 0): ?>
                  <div class="custom-summary-list mb-3">
                    <div class="custom-summary-row"><strong>Production ID</strong><span><?= (int) $selectedOrder['production_order_id'] ?></span></div>
                    <div class="custom-summary-row"><strong>Invoices</strong><span><?= count($productionOverview['invoices'] ?? []) ?></span></div>
                    <div class="custom-summary-row"><strong>Tracking</strong><span><?= count($productionOverview['tracking'] ?? []) ?></span></div>
                  </div>
                  <div class="custom-order-section-title">Invoices</div>
                  <?php if (!empty($productionOverview['invoices'])): ?>
                    <div class="custom-inline-list mb-3">
                      <?php foreach ($productionOverview['invoices'] as $invoice): ?>
                        <span class="custom-chip"><span><?= h((string) ($invoice['invoice_number'] ?? '')) ?></span><?= customOrderCopyButton((string) ($invoice['invoice_number'] ?? '')) ?></span>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="text-muted mb-3">No invoices in production yet.</div>
                  <?php endif; ?>
                  <div class="custom-order-section-title">Tracking Numbers</div>
                  <?php if (!empty($productionOverview['tracking'])): ?>
                    <div class="custom-summary-list">
                      <?php foreach ($productionOverview['tracking'] as $tracking): ?>
                        <div class="custom-summary-row"><strong><?= h((string) ($tracking['carrier'] ?: 'Tracking')) ?></strong><span><?= h((string) ($tracking['tracking_number'] ?? '')) ?><?= customOrderCopyButton((string) ($tracking['tracking_number'] ?? '')) ?></span></div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="text-muted">No tracking numbers in production yet.</div>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="text-muted">Lead not exported yet. Production invoice and tracking data will appear here after export.</div>
                <?php endif; ?>
              </fieldset>
            </div>
          </div>
        </div>

        <div id="custom-order-service-panel" data-scroll-block class="custom-orders-panel custom-order-block">
          <div class="panel-body">
            <div class="custom-order-section-title">2. Customer Service Workspace</div>
            <form method="post" action="scripts/custom_orders/save_order.php" data-scroll-target="#custom-order-service-panel">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <div class="custom-order-subgrid">
                <fieldset class="custom-field-cluster">
                  <legend class="custom-field-cluster-title">Lead Context</legend>
                  <div class="custom-form-grid">
                    <div><label>Bike brand<?= customOrderHelp('bike_brand') ?></label><input type="text" name="bike_brand" class="form-control form-control-sm" value="<?= h($selectedOrder['bike_brand']) ?>"></div>
                    <div><label>Bike model<?= customOrderHelp('bike_model') ?></label><input type="text" name="bike_model" class="form-control form-control-sm" value="<?= h($selectedOrder['bike_model']) ?>"></div>
                    <div><label>Bike year<?= customOrderHelp('bike_year') ?></label><input type="text" name="bike_year" class="form-control form-control-sm" value="<?= h($selectedOrder['bike_year']) ?>"></div>
                    <div><label>Rider name<?= customOrderHelp('rider_name') ?></label><input type="text" name="rider_name" class="form-control form-control-sm" value="<?= h($selectedOrder['rider_name']) ?>"></div>
                    <div><label>Rider number<?= customOrderHelp('rider_number') ?></label><input type="text" name="rider_number" class="form-control form-control-sm" value="<?= h($selectedOrder['rider_number']) ?>"></div>
                    <div><label>Last contact<?= customOrderHelp('last_contact_at') ?></label><input type="datetime-local" name="last_contact_at" class="form-control form-control-sm" value="<?= h($selectedOrder['last_contact_at'] ? date('Y-m-d\TH:i', strtotime((string) $selectedOrder['last_contact_at'])) : '') ?>"></div>
                    <div class="custom-form-full"><label>Bike details<?= customOrderHelp('bike_details') ?></label><input type="text" name="bike_details" class="form-control form-control-sm" value="<?= h($selectedOrder['bike_details']) ?>" placeholder="Engine, generation, plastics note, unusual fitment"></div>
                    <div class="custom-form-full"><label>Graphics brief<?= customOrderHelp('graphics_brief') ?></label><textarea name="graphics_brief" rows="3" class="form-control form-control-sm"><?= h($selectedOrder['graphics_brief']) ?></textarea></div>
                    <div class="custom-form-full"><label>Bike photo URLs<?= customOrderHelp('bike_photo_urls') ?></label><textarea name="bike_photo_urls" rows="2" class="form-control form-control-sm" placeholder="One URL per line"><?= h($selectedOrder['bike_photo_urls']) ?></textarea></div>
                    <div class="custom-form-full"><label>Reference URLs / files<?= customOrderHelp('reference_urls') ?></label><textarea name="reference_urls" rows="2" class="form-control form-control-sm" placeholder="One URL or note per line"><?= h($selectedOrder['reference_urls']) ?></textarea></div>
                  </div>
                </fieldset>

                <fieldset class="custom-field-cluster">
                  <legend class="custom-field-cluster-title">Working Summaries</legend>
                  <div class="custom-form-grid">
                    <div class="custom-form-full"><label>Customer notes<?= customOrderHelp('customer_notes') ?></label><textarea name="customer_notes" rows="6" class="form-control form-control-sm"><?= h($selectedOrder['customer_notes']) ?></textarea></div>
                    <div class="custom-form-full"><label>Internal notes<?= customOrderHelp('internal_notes') ?></label><textarea name="internal_notes" rows="6" class="form-control form-control-sm"><?= h($selectedOrder['internal_notes']) ?></textarea></div>
                  </div>
                </fieldset>
              </div>
              <button type="submit" class="btn btn-success btn-sm mt-3">Save Customer Service Block</button>
            </form>
            <div class="custom-optical-divider"></div>

            <fieldset class="custom-field-cluster" id="custom-order-followups-block" data-scroll-block>
              <legend class="custom-field-cluster-title">Contact Attempts And Follow-ups</legend>
              <?php $deadOrderLocked = !empty($selectedOrder['exported_at']) || !empty($selectedOrder['production_order_id']) || (string) ($selectedOrder['status'] ?? '') === 'EXPORTED'; ?>
              <form method="post" action="scripts/custom_orders/save_order.php" class="mb-3" data-scroll-target="#custom-order-followups-block">
                <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                <input type="hidden" name="dead_order_flag" value="<?= $deadOrderLocked ? (int) $selectedOrder['dead_order_flag'] : 0 ?>">
                <div class="custom-form-grid-4">
                  <div><label>Next follow-up<?= customOrderHelp('next_followup_at') ?></label><input type="datetime-local" name="next_followup_at" class="form-control form-control-sm" value="<?= h($selectedOrder['next_followup_at'] ? date('Y-m-d\TH:i', strtotime((string) $selectedOrder['next_followup_at'])) : '') ?>"></div>
                  <div class="d-flex align-items-end">
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="checkbox" name="dead_order_flag" id="dead_order_flag_followups" value="1" <?= (int) $selectedOrder['dead_order_flag'] === 1 ? 'checked' : '' ?> <?= $deadOrderLocked ? 'disabled' : '' ?>>
                      <label class="form-check-label" for="dead_order_flag_followups">Dead order<?= customOrderHelp('dead_order_flag') ?></label>
                    </div>
                  </div>
                  <div class="custom-form-full text-muted small">
                    <?php if ($deadOrderLocked): ?>
                      Dead order is locked because this lead has already been exported to production.
                    <?php endif; ?>
                  </div>
                </div>
                <button type="submit" class="btn btn-outline-light btn-sm mt-2">Save Follow-up Status</button>
              </form>
              <form method="post" action="scripts/custom_orders/save_followup.php" data-scroll-target="#custom-order-followups-block">
                <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                <div class="custom-form-grid-4">
                  <div><label>Contacted at<?= customOrderHelp('followup_contacted_at') ?></label><input
                      type="datetime-local" name="contacted_at" class="form-control form-control-sm"></div>
                  <div><label>Channel<?= customOrderHelp('followup_channel') ?></label><input type="text" name="channel"
                      class="form-control form-control-sm" placeholder="IG / WhatsApp / Email"></div>
                  <div class="custom-form-full"><label>Note<?= customOrderHelp('followup_note') ?></label><input
                      type="text" name="note" class="form-control form-control-sm"
                      placeholder="Requested address, clarified plastics generation, etc."></div>
                </div>
                <button type="submit" class="btn btn-outline-light btn-sm mt-2">Add Follow-up</button>
              </form>
              <table class="table table-sm table-dark table-striped custom-mini-table mt-3">
                <thead>
                  <tr>
                    <th>When</th>
                    <th>Channel</th>
                    <th>Note</th>
                  </tr>
                </thead>
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
            </fieldset>
          </div>
        </div>

        <div id="custom-order-builder-panel" data-scroll-block class="custom-orders-panel mb-3<?= isset($invalidFields['items']) ? ' custom-panel-invalid' : '' ?>">
          <div class="panel-body">
            <div class="custom-order-section-title">3. Product Builder</div>
            <?php $editOptions = $editItem ? (json_decode((string) $editItem['options_json'], true) ?: []) : []; ?>
            <?php $editInternalOptions = $editItem ? (json_decode((string) ($editItem['internal_options_json'] ?? ''), true) ?: []) : []; ?>
            <?php $currentBuilderType = $editItem ? strtoupper((string) ($editItem['item_type_code'] ?? '')) : $builderType; ?>
            <?php $currentBuilderDepartment = customOrdersItemTypeToDepartment($currentBuilderType); ?>
            <?php $currentBuilderSubcategory = $currentBuilderDepartment === 'G' ? customOrdersGraphicsSubcategoryFromItemData((string) ($editInternalOptions['_subcat'] ?? ''), (string) ($editItem['custom_label'] ?? ''), (string) ($editItem['sku'] ?? '')) : ''; ?>
            <form method="post" action="scripts/custom_orders/save_item.php" data-scroll-target="#custom-order-builder-panel">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <input type="hidden" name="custom_item_id" value="<?= (int) ($editItem['id'] ?? 0) ?>">
              <div class="custom-item-builder-shell">
                <div class="custom-builder-picker">
                  <div class="custom-builder-picker-copy">
                    <div class="custom-item-builder-title"><?= $editItem ? 'Edit Product' : 'Add Product To Lead' ?></div>
                    <?php if (!$editItem && $currentBuilderType === ''): ?>
                      <div class="custom-builder-placeholder" data-builder-empty-note>Select a kind to render the familiar order-style product block.</div>
                    <?php endif; ?>
                  </div>
                  <div class="custom-builder-picker-label">
                    <label>Kind<?= customOrderHelp('item_type_code') ?></label>
                    <select name="item_type_code" class="form-control form-control-sm custom-item-type-select">
                      <option value="">Select kind...</option>
                      <?php foreach ($allowedTypes as $code => $label): ?><option value="<?= h($code) ?>" <?= ($currentBuilderType === $code) ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="custom-builder-picker-label" data-graphics-subcategory-wrap <?= $currentBuilderDepartment === 'G' ? '' : 'hidden' ?>>
                    <label>Graphics subcategory</label>
                    <select name="graphics_subcategory" class="form-control form-control-sm custom-graphics-subcategory-select">
                      <option value="">General graphics</option>
                      <?php foreach ($graphicsSubcategoryLabels as $subcatCode => $subcatLabel): ?>
                        <option value="<?= h((string) $subcatCode) ?>" <?= $currentBuilderSubcategory === (string) $subcatCode ? 'selected' : '' ?>><?= h((string) $subcatLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="custom-builder-order-shell" data-builder-body <?= $currentBuilderType === '' ? 'hidden' : '' ?>>
                  <div class="custom-builder-subtitle">Core Item Row</div>
                  <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 custom-builder-order-table">
                      <tbody>
                        <tr class="item-repeat-header-row">
                          <th class="text-center">Assigned</th>
                          <th>Type</th>
                          <th class="text-center">Nazov</th>
                          <th>Qty</th>
                          <th>Price</th>
                          <th>Category Info</th>
                          <th>Link</th>
                          <th class="text-center">Detail</th>
                          <th>Action</th>
                          <th>Waiting</th>
                          <th class="text-center">Save</th>
                          <th class="text-center">Delete</th>
                        </tr>
                        <tr class="item-info-row <?= $currentBuilderType !== '' ? 'item-type-' . h($currentBuilderType) : '' ?>" data-builder-row>
                          <td class="text-center" style="width:56px;">
                            <span class="custom-builder-assigned-placeholder" title="Lead owner / production assignment context">
                              <?= h($selectedOrder['owner_name'] ? strtoupper(substr((string) $selectedOrder['owner_name'], 0, 1)) : '-') ?>
                            </span>
                          </td>
                          <td class="text-center" style="width:46px;">
                            <span class="custom-builder-type-badge" data-builder-type-badge><?= h($currentBuilderType !== '' ? $currentBuilderType : '?') ?></span>
                          </td>
                          <td style="min-width:280px;">
                            <input type="text" name="title" class="form-control form-control-sm mb-1" value="<?= h($editItem['title'] ?? '') ?>" required>
                            <div class="small text-muted">
                              <span data-builder-sku-preview><?= h(trim((string) ($editItem['sku'] ?? '')) !== '' ? (string) ($editItem['sku'] ?? '') : 'MANUAL') ?></span>
                              |
                              <span data-builder-label-preview><?= h(trim((string) ($editItem['custom_label'] ?? '')) !== '' ? (string) ($editItem['custom_label'] ?? '') : '-') ?></span>
                            </div>
                          </td>
                          <td style="width:72px;">
                            <input type="number" min="1" name="qty" class="form-control form-control-sm" value="<?= h($editItem['qty'] ?? 1) ?>">
                          </td>
                          <td style="width:92px;">
                            <input type="number" step="0.01" name="unit_price" class="form-control form-control-sm" value="<?= h($editItem['unit_price'] ?? 0) ?>">
                          </td>
                          <td style="min-width:220px;">
                            <input type="text" name="category_info" class="form-control form-control-sm" value="<?= h($editOptions['category_info'] ?? '') ?>" placeholder="Brand | Model | Year">
                          </td>
                          <td class="text-center" style="width:76px;">
                            <button type="button" class="btn btn-sm btn-outline-info custom-builder-link-btn" disabled><i class="fas fa-external-link-alt"></i></button>
                          </td>
                          <td class="text-center" style="width:92px;">
                            <button type="button" class="btn btn-xs btn-outline-info custom-builder-mini-btn" disabled>Detail</button>
                          </td>
                          <td style="min-width:140px;">
                            <select class="form-control form-control-sm" disabled><option selected>NEW</option></select>
                          </td>
                          <td style="min-width:170px;">
                            <div class="input-group input-group-sm mb-1">
                              <input type="text" class="form-control form-control-sm" placeholder="Na co cakame?" disabled>
                              <div class="input-group-append">
                                <button type="button" class="btn btn-outline-success" disabled><i class="fas fa-save"></i></button>
                              </div>
                            </div>
                            <input type="date" class="form-control form-control-sm" disabled>
                          </td>
                          <td class="text-center" style="width:52px;">
                            <button type="button" class="btn btn-xs btn-outline-success custom-builder-mini-btn" disabled>Save</button>
                          </td>
                          <td class="text-center" style="width:58px;">
                            <button type="button" class="btn btn-xs btn-outline-danger custom-builder-mini-btn" disabled>Delete</button>
                          </td>
                        </tr>

                        <?php foreach ($productSpecDefinitions as $departmentCode => $definitions): ?>
                          <?php
                          $subcatScopes = [''];
                          if ($departmentCode === 'G') {
                            $subcatScopes = array_merge($subcatScopes, array_keys($graphicsSubcategoryLabels));
                          }
                          ?>
                          <?php foreach ($subcatScopes as $subcatScope): ?>
                            <?php
                            $filteredDefinitions = customOrdersFilterSpecDefinitionsForBuilder($definitions, $departmentCode, (string) $subcatScope);
                            $departmentMainFields = [];
                            $departmentTextFields = [];
                            foreach ($filteredDefinitions as $definition) {
                              $sourceKey = trim((string) ($definition['source_key'] ?? ''));
                              $fieldType = trim((string) ($definition['field_type'] ?? 'dropdown'));
                              if ($fieldType === 'text' || in_array($sourceKey, ['name', 'number', 'note', 'my-item-note'], true)) {
                                $departmentTextFields[] = $definition;
                              } else {
                                $departmentMainFields[] = $definition;
                              }
                            }
                            $rowVisible = $currentBuilderDepartment === $departmentCode;
                            if ($rowVisible && $departmentCode === 'G') {
                              $rowVisible = (string) $currentBuilderSubcategory === (string) $subcatScope;
                            }
                            ?>

                            <?php if (!empty($departmentMainFields)): ?>
                              <tr class="g-item-options-row <?= $currentBuilderType !== '' ? 'item-type-' . h($currentBuilderType) : '' ?> custom-item-spec-group" data-builder-row data-department="<?= h($departmentCode) ?>" data-subcategory="<?= h((string) $subcatScope) ?>" <?= $rowVisible ? '' : 'hidden' ?>>
                                <td colspan="99">
                                  <div class="g-options-bar">
                                    <?php foreach ($departmentMainFields as $definition): ?>
                                      <label class="product-spec-label">
                                        <span class="product-spec-label-title"><?= h(customOrderBuilderSpecLabel($departmentCode, $definition)) ?></span>
                                        <?= customOrderRenderSpecFieldInput($conn, $definition, $editOptions, $selectedOrder) ?>
                                      </label>
                                    <?php endforeach; ?>
                                  </div>
                                </td>
                              </tr>
                            <?php endif; ?>

                            <?php if (!empty($departmentTextFields)): ?>
                              <tr class="g-item-options-row <?= $currentBuilderType !== '' ? 'item-type-' . h($currentBuilderType) : '' ?> custom-item-spec-group" data-builder-row data-department="<?= h($departmentCode) ?>" data-subcategory="<?= h((string) $subcatScope) ?>" <?= $rowVisible ? '' : 'hidden' ?>>
                                <td colspan="99">
                                  <div class="g-options-bar">
                                    <?php foreach ($departmentTextFields as $definition): ?>
                                      <label class="product-spec-label">
                                        <span class="product-spec-label-title"><?= h(customOrderBuilderSpecLabel($departmentCode, $definition)) ?></span>
                                        <?= customOrderRenderSpecFieldInput($conn, $definition, $editOptions, $selectedOrder) ?>
                                      </label>
                                    <?php endforeach; ?>
                                  </div>
                                </td>
                              </tr>
                            <?php endif; ?>
                          <?php endforeach; ?>
                        <?php endforeach; ?>

                        <tr class="item-spacer-row" aria-hidden="true">
                          <td colspan="99"></td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div class="custom-form-grid-4 mt-3" data-builder-body <?= $currentBuilderType === '' ? 'hidden' : '' ?>>
                  <div><label>SKU<?= customOrderHelp('item_sku') ?></label><input type="text" name="sku" class="form-control form-control-sm" value="<?= h($editItem['sku'] ?? '') ?>"></div>
                  <div><label>Custom label<?= customOrderHelp('item_custom_label') ?></label><input type="text" name="custom_label" class="form-control form-control-sm" value="<?= h($editItem['custom_label'] ?? '') ?>"></div>
                  <div><label>Upsell source<?= customOrderHelp('item_upsell_source') ?></label><input type="text" name="upsell_source" class="form-control form-control-sm" value="<?= h($editItem['upsell_source'] ?? '') ?>" placeholder="Converted from graphics-only"></div>
                  <div class="d-flex align-items-end">
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_upsell" id="is_upsell" <?= (int) ($editItem['is_upsell'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="is_upsell">Mark as upsell<?= customOrderHelp('item_is_upsell') ?></label></div>
                  </div>
                </div>
              </div>
              <button type="submit" data-builder-body <?= $currentBuilderType === '' ? 'hidden' : '' ?>
                class="btn btn-success btn-sm mt-2"><?= $editItem ? 'Update Item' : 'Add Item' ?></button>
              <?php if ($editItem): ?>
                <a href="<?= h(customOrderBuildUrl((int) $selectedOrder['id'], ['edit_item_id' => null])) ?>"
                  class="btn btn-secondary btn-sm mt-2 custom-order-remember-position" data-scroll-target="#custom-order-builder-panel">Cancel Edit</a>
              <?php endif; ?>
            </form>
            <div class="custom-optical-divider"></div>

            <div class="custom-field-cluster-title">Existing Items And Upsells</div>
            <table class="table table-sm table-dark table-striped custom-mini-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Type</th>
                  <th>SKU</th>
                  <th>Category</th>
                  <th>Title</th>
                  <th>Qty</th>
                  <th>Price</th>
                  <th>Upsell</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($selectedOrder['items'] as $item): ?>
                  <?php $itemOptions = json_decode((string) ($item['options_json'] ?? ''), true) ?: []; ?>
                  <?php $itemCategoryInfo = trim((string) ($itemOptions['category_info'] ?? '')); ?>
                  <?php $itemModalId = 'custom-item-modal-' . (int) $item['id']; ?>
                  <tr>
                    <td><?= (int) $item['line_no'] ?></td>
                    <td><?= h($item['item_type_code']) ?></td>
                    <td><?= customOrderTruncate((string) ($item['sku'] ?? ''), 18) ?></td>
                    <td><?= customOrderTruncate($itemCategoryInfo, 28) ?></td>
                    <td><?= h($item['title']) ?></td>
                    <td><?= (int) $item['qty'] ?></td>
                    <td><?= number_format((float) $item['unit_price'], 2) ?></td>
                    <td><?= (int) $item['is_upsell'] === 1 ? 'Yes' : 'No' ?></td>
                    <td class="text-right">
                      <button type="button" class="btn btn-info btn-xs" data-toggle="modal"
                        data-target="#<?= h($itemModalId) ?>">View</button>
                      <a href="<?= h(customOrderBuildUrl((int) $selectedOrder['id'], ['edit_item_id' => (int) $item['id']])) ?>"
                        class="btn btn-secondary btn-xs custom-order-remember-position" data-scroll-target="#custom-order-builder-panel">Edit</a>
                      <form method="post" action="scripts/custom_orders/delete_item.php" style="display:inline-block;" data-scroll-target="#custom-order-builder-panel">
                        <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
                        <input type="hidden" name="custom_item_id" value="<?= (int) $item['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php foreach ($selectedOrder['items'] as $item): ?>
              <?php $itemOptions = json_decode((string) ($item['options_json'] ?? ''), true) ?: []; ?>
              <?php $itemCategoryInfo = trim((string) ($itemOptions['category_info'] ?? '')); ?>
              <?php $itemOptionGroups = customOrderItemOptionGroups($conn, (string) ($item['item_type_code'] ?? ''), $itemOptions); ?>
              <?php $itemModalId = 'custom-item-modal-' . (int) $item['id']; ?>
              <div class="modal fade custom-item-modal" id="<?= h($itemModalId) ?>" tabindex="-1" role="dialog"
                aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                  <div class="modal-content bg-dark">
                    <div class="modal-header">
                      <div>
                        <h5 class="modal-title"><?= h($item['title']) ?></h5>
                        <div class="modal-subtitle">Custom item detail</div>
                      </div>
                      <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                      </button>
                    </div>
                    <div class="modal-body">
                      <div class="custom-item-summary">
                        <div class="custom-item-summary-card">
                          <div class="custom-item-summary-card-label">Type</div>
                          <div class="custom-item-summary-card-value"><?= h($item['item_type_code']) ?></div>
                        </div>
                        <div class="custom-item-summary-card">
                          <div class="custom-item-summary-card-label">SKU</div>
                          <div class="custom-item-summary-card-value"><?= h($item['sku'] ?: '-') ?></div>
                        </div>
                        <div class="custom-item-summary-card">
                          <div class="custom-item-summary-card-label">Qty</div>
                          <div class="custom-item-summary-card-value"><?= (int) $item['qty'] ?></div>
                        </div>
                        <div class="custom-item-summary-card">
                          <div class="custom-item-summary-card-label">Unit price</div>
                          <div class="custom-item-summary-card-value"><?= number_format((float) $item['unit_price'], 2) ?>
                          </div>
                        </div>
                      </div>

                      <div class="custom-item-sections">
                        <div class="custom-item-section">
                          <div class="custom-item-section-title">Core Info</div>
                          <div class="custom-item-detail-grid">
                            <div class="custom-item-detail-row is-full">
                              <div class="custom-item-detail-label">Title</div>
                              <div class="custom-item-detail-value"><?= h($item['title']) ?></div>
                            </div>
                            <div class="custom-item-detail-row is-full">
                              <div class="custom-item-detail-label">Custom label</div>
                              <div class="custom-item-detail-value"><?= h($item['custom_label'] ?: '-') ?></div>
                            </div>
                            <div class="custom-item-detail-row is-full">
                              <div class="custom-item-detail-label">Category info</div>
                              <div class="custom-item-detail-value"><?= h($itemCategoryInfo ?: '-') ?></div>
                            </div>
                            <div class="custom-item-detail-row">
                              <div class="custom-item-detail-label">Rider name</div>
                              <div class="custom-item-detail-value"><?= h((string) ($itemOptions['name'] ?? '-')) ?></div>
                            </div>
                            <div class="custom-item-detail-row">
                              <div class="custom-item-detail-label">Rider number</div>
                              <div class="custom-item-detail-value"><?= h((string) ($itemOptions['number'] ?? '-')) ?></div>
                            </div>
                          </div>
                        </div>

                        <div class="custom-item-section">
                          <div class="custom-item-section-title">Sales Flags</div>
                          <div class="custom-item-meta-list">
                            <div class="custom-item-meta-pill">
                              <strong>Upsell</strong><span><?= (int) $item['is_upsell'] === 1 ? 'Yes' : 'No' ?></span></div>
                            <div class="custom-item-meta-pill"><strong>Upsell
                                source</strong><span><?= h((string) ($item['upsell_source'] ?? '-')) ?></span></div>
                          </div>
                        </div>
                      </div>

                      <div class="custom-item-sections mt-3">
                        <div class="custom-item-section">
                          <div class="custom-item-section-title">Specification</div>
                          <div class="custom-item-detail-grid">
                            <?php if (!empty($itemOptionGroups['spec_rows'])): ?>
                              <?php foreach ($itemOptionGroups['spec_rows'] as $specRow): ?>
                                <div class="custom-item-detail-row">
                                  <div class="custom-item-detail-label"><?= h((string) ($specRow['label'] ?? '')) ?></div>
                                  <div class="custom-item-detail-value"><?= h((string) ($specRow['value'] ?? '-')) ?></div>
                                </div>
                              <?php endforeach; ?>
                            <?php else: ?>
                              <div class="custom-item-detail-row is-full">
                                <div class="custom-item-detail-label">Specification</div>
                                <div class="custom-item-detail-value">-</div>
                              </div>
                            <?php endif; ?>
                          </div>
                        </div>

                        <div class="custom-item-section">
                          <div class="custom-item-section-title">Notes</div>
                          <?php if (!empty($itemOptionGroups['note_rows'])): ?>
                            <?php foreach ($itemOptionGroups['note_rows'] as $noteRow): ?>
                              <div class="custom-item-note-box mb-2">
                                <strong><?= h((string) ($noteRow['label'] ?? '')) ?>:</strong><br>
                                <?= customOrderFormatMultiline((string) ($noteRow['value'] ?? '-')) ?>
                              </div>
                            <?php endforeach; ?>
                          <?php else: ?>
                            <div class="custom-item-note-box">-</div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="custom-orders-panel mb-3">
          <div class="panel-body">
            <div class="custom-order-section-title">4. Append-Only Notes</div>
            <form method="post" action="scripts/custom_orders/save_note.php" class="mb-3">
              <input type="hidden" name="custom_order_id" value="<?= (int) $selectedOrder['id'] ?>">
              <div class="custom-form-grid">
                <div><label>Type</label><select name="note_type" class="form-control form-control-sm"><option value="CUSTOMER">Customer</option><option value="INTERNAL">Internal</option><option value="REVISION">Revision</option></select></div>
                <div class="custom-form-full"><label>New note</label><textarea name="note_body" rows="4" class="form-control form-control-sm" placeholder="Append next customer change, revision request, or internal update..."></textarea></div>
              </div>
              <button type="submit" class="btn btn-outline-light btn-sm mt-2">Append Note</button>
            </form>
            <div class="custom-notes-timeline">
              <?php foreach (($selectedOrder['notes'] ?? []) as $note): ?>
                <div class="custom-note-entry">
                  <div class="custom-note-entry-meta"><span class="badge badge-secondary"><?= h(customOrderNoteTypeLabel((string) ($note['note_type'] ?? ''))) ?></span><span><?= h((string) ($note['created_at'] ?? '')) ?></span></div>
                  <div class="custom-note-entry-body"><?= h((string) ($note['note_body'] ?? '')) ?></div>
                </div>
              <?php endforeach; ?>
              <?php if (empty($selectedOrder['notes'])): ?>
                <div class="text-muted">No appended notes yet.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="custom-orders-panel">
          <div class="panel-body">
            <div class="custom-order-section-title">Recent Activity</div>
            <?php $recentActivity = $selectedOrder['activity'] ?? []; ?>
            <?php $recentActivityVisibleLimit = 5; ?>
            <table class="table table-sm table-dark table-striped custom-mini-table" id="custom-order-recent-activity-table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Who</th>
                  <th>Action</th>
                  <th>Detail</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentActivity as $activityIndex => $activity): ?>
                  <tr<?= $activityIndex >= $recentActivityVisibleLimit ? ' class="custom-activity-extra-row" hidden' : '' ?>>
                    <td><?= h($activity['created_at']) ?></td>
                    <td><?= h(trim((string) ($activity['actor_name'] ?? '')) !== '' ? (string) $activity['actor_name'] : ((int) ($activity['actor_employee_id'] ?? 0) > 0 ? ('Employee #' . (int) $activity['actor_employee_id']) : 'System')) ?></td>
                    <td><?= h(customOrdersActivityActionLabel((string) ($activity['action'] ?? ''))) ?></td>
                    <td><?= h(customOrdersActivityDetail($activity)) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php if (count($recentActivity) > $recentActivityVisibleLimit): ?>
              <button type="button" class="btn btn-outline-light btn-sm" id="custom-order-activity-toggle"
                aria-expanded="false">Show more</button>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  (function () {
    var customOrdersScrollStorageKey = 'custom-orders-scroll:' + window.location.pathname;
    var customOrdersHighlightStorageKey = 'custom-orders-highlight:' + window.location.pathname;

    function resolveScrollTargetSelector(element) {
      if (!element) return '';
      var explicitTarget = String(element.getAttribute('data-scroll-target') || '').trim();
      if (explicitTarget !== '') {
        return explicitTarget;
      }
      var nearestBlock = element.closest('[data-scroll-block]');
      if (nearestBlock && nearestBlock.id) {
        return '#' + nearestBlock.id;
      }
      return '';
    }

    function ensureHighlightStyles() {
      if (document.getElementById('custom-orders-scroll-highlight-style')) {
        return;
      }
      var style = document.createElement('style');
      style.id = 'custom-orders-scroll-highlight-style';
      style.textContent = ''
        + '.custom-orders-scroll-highlight {'
        + ' box-shadow: 0 0 0 1px rgba(53, 208, 127, 0.55), 0 0 0 10px rgba(53, 208, 127, 0.10);'
        + ' transition: box-shadow 0.45s ease;'
        + '}'
        + '.custom-orders-scroll-highlight-fade {'
        + ' transition: box-shadow 1.4s ease;'
        + ' box-shadow: 0 0 0 1px rgba(53, 208, 127, 0), 0 0 0 0 rgba(53, 208, 127, 0);'
        + '}';
      document.head.appendChild(style);
    }

    function flashCustomOrdersTarget(target) {
      if (!target) return;
      ensureHighlightStyles();
      target.classList.remove('custom-orders-scroll-highlight', 'custom-orders-scroll-highlight-fade');
      void target.offsetWidth;
      target.classList.add('custom-orders-scroll-highlight');
      window.setTimeout(function () {
        target.classList.add('custom-orders-scroll-highlight-fade');
      }, 220);
      window.setTimeout(function () {
        target.classList.remove('custom-orders-scroll-highlight', 'custom-orders-scroll-highlight-fade');
      }, 1900);
    }

    function rememberCustomOrdersScroll(element) {
      try {
        sessionStorage.setItem(customOrdersScrollStorageKey, String(window.scrollY || window.pageYOffset || 0));
        var targetSelector = resolveScrollTargetSelector(element);
        if (targetSelector !== '') {
          sessionStorage.setItem(customOrdersHighlightStorageKey, targetSelector);
        } else {
          sessionStorage.removeItem(customOrdersHighlightStorageKey);
        }
      } catch (err) {
      }
    }

    function restoreCustomOrdersScroll() {
      try {
        var stored = sessionStorage.getItem(customOrdersScrollStorageKey);
        if (stored === null) {
          return;
        }
        sessionStorage.removeItem(customOrdersScrollStorageKey);
        var targetSelector = sessionStorage.getItem(customOrdersHighlightStorageKey);
        if (targetSelector !== null) {
          sessionStorage.removeItem(customOrdersHighlightStorageKey);
        }
        var top = parseInt(stored, 10);
        if (!isNaN(top)) {
          window.requestAnimationFrame(function () {
            window.scrollTo(0, top);
            if (targetSelector) {
              window.setTimeout(function () {
                flashCustomOrdersTarget(document.querySelector(targetSelector));
              }, 140);
            }
          });
        } else if (targetSelector) {
          flashCustomOrdersTarget(document.querySelector(targetSelector));
        }
      } catch (err) {
      }
    }

    restoreCustomOrdersScroll();
    window.addEventListener('DOMContentLoaded', restoreCustomOrdersScroll);
    window.addEventListener('load', restoreCustomOrdersScroll);

    function mapTypeToDepartment(typeCode) {
      var code = String(typeCode || '').toUpperCase();
      if (!code) return '';
      if (code === 'S') return 'S';
      if (code === 'F') return 'F';
      if (code === 'P' || code === 'T' || code === 'M') return 'P';
      return 'G';
    }

    function syncCustomItemSpecGroups(selectEl) {
      if (!selectEl) return;
      var form = selectEl.closest('form');
      if (!form) return;
      var typeCode = String(selectEl.value || '').toUpperCase();
      var department = mapTypeToDepartment(typeCode);
      var subcategoryWrap = form.querySelector('[data-graphics-subcategory-wrap]');
      var subcategorySelect = form.querySelector('.custom-graphics-subcategory-select');
      var subcategory = subcategorySelect ? String(subcategorySelect.value || '').toUpperCase() : '';

      form.querySelectorAll('[data-builder-body]').forEach(function (block) {
        block.hidden = typeCode === '';
      });

      form.querySelectorAll('[data-builder-empty-note]').forEach(function (note) {
        note.hidden = typeCode !== '';
      });

      if (subcategoryWrap) {
        subcategoryWrap.hidden = department !== 'G';
      }
      if (department !== 'G' && subcategorySelect) {
        subcategorySelect.value = '';
        subcategory = '';
      }

      form.querySelectorAll('.custom-item-spec-group').forEach(function (group) {
        var visible = !!department && group.getAttribute('data-department') === department;
        if (visible && department === 'G') {
          visible = String(group.getAttribute('data-subcategory') || '').toUpperCase() === subcategory;
        }
        group.hidden = !visible;
      });

      form.querySelectorAll('[data-builder-row]').forEach(function (row) {
        row.classList.remove('item-type-G', 'item-type-P', 'item-type-S', 'item-type-F', 'item-type-T', 'item-type-M');
        if (typeCode) {
          row.classList.add('item-type-' + typeCode);
        }
      });

      form.querySelectorAll('[data-builder-type-badge]').forEach(function (badge) {
        badge.textContent = typeCode || '?';
      });
    }

    function syncBuilderPreview(form) {
      if (!form) return;
      var skuInput = form.querySelector('input[name="sku"]');
      var labelInput = form.querySelector('input[name="custom_label"]');
      var skuPreview = form.querySelector('[data-builder-sku-preview]');
      var labelPreview = form.querySelector('[data-builder-label-preview]');

      if (skuPreview) {
        skuPreview.textContent = skuInput && skuInput.value.trim() !== '' ? skuInput.value.trim() : 'MANUAL';
      }

      if (labelPreview) {
        labelPreview.textContent = labelInput && labelInput.value.trim() !== '' ? labelInput.value.trim() : '-';
      }
    }

    document.querySelectorAll('form[action^="scripts/custom_orders/"]').forEach(function (form) {
      form.addEventListener('submit', function () {
        rememberCustomOrdersScroll(form);
      });
    });

    document.querySelectorAll('a.custom-order-remember-position').forEach(function (link) {
      link.addEventListener('click', function () {
        rememberCustomOrdersScroll(link);
      });
    });

    document.querySelectorAll('.custom-order-table-row[data-href]').forEach(function (row) {
      row.addEventListener('click', function (event) {
        var target = event.target;
        if (target && target.closest('a, button, input, select, textarea, label')) {
          return;
        }
        var href = row.getAttribute('data-href');
        if (href) {
          window.location.href = href;
        }
      });
    });

    document.querySelectorAll('form[action="scripts/custom_orders/save_item.php"]').forEach(function (form) {
      var typeSelect = form.querySelector('.custom-item-type-select');
      var subcategorySelect = form.querySelector('.custom-graphics-subcategory-select');
      if (typeSelect) {
        syncCustomItemSpecGroups(typeSelect);
        typeSelect.addEventListener('change', function () {
          syncCustomItemSpecGroups(typeSelect);
        });
      }
      if (subcategorySelect && typeSelect) {
        subcategorySelect.addEventListener('change', function () {
          syncCustomItemSpecGroups(typeSelect);
        });
      }

      ['sku', 'custom_label'].forEach(function (fieldName) {
        var input = form.querySelector('input[name="' + fieldName + '"]');
        if (!input) return;
        input.addEventListener('input', function () {
          syncBuilderPreview(form);
        });
      });

      syncBuilderPreview(form);
    });

    document.querySelectorAll('.btn-copy-inline').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var value = btn.getAttribute('data-copy') || '';
        if (!value) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(value);
        }
      });
    });

    var activityToggle = document.getElementById('custom-order-activity-toggle');
    if (activityToggle) {
      activityToggle.addEventListener('click', function () {
        var expanded = activityToggle.getAttribute('aria-expanded') === 'true';
        document.querySelectorAll('.custom-activity-extra-row').forEach(function (row) {
          row.hidden = expanded;
        });
        activityToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        activityToggle.textContent = expanded ? 'Show more' : 'Show less';
      });
    }
  })();
</script>
