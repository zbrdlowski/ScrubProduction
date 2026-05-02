<?php
declare(strict_types=1);
session_start();
//out(200, ['ok'=>false,'error'=>'PHP '.PHP_VERSION]);
header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_SESSION['permission'])) {
  out(403, ['ok'=>false,'error'=>'Not logged in']);
}

// robust path (works regardless of relative include quirks)
$base = dirname(__DIR__, 2); // /.../darkscrub
$connFile = $base . '/includes/conn.php';
if (!is_file($connFile)) {
  out(500, ['ok'=>false,'error'=>'conn.php not found: ' . $connFile]);
}
require_once $connFile;

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) out(400, ['ok'=>false,'error'=>'Invalid order_id']);

$dpt = (int)($_SESSION['dpt'] ?? 0);
$allAccess = in_array($dpt, [1,3,4,5,7], true);

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function countryFlag($code): string {
  $code = strtoupper(trim((string)$code));
  if ($code === '') return '';

  if ($code === 'UK') $code = 'GB';
  if ($code === 'UM') $code = 'US';
  if ($code === 'KX') $code = 'XK';

  $imgCode = strtolower($code);

  return '<img src="https://flagcdn.com/16x12/' . h($imgCode) . '.png" '
    . 'alt="' . h($code) . '" '
    . 'style="margin-right:5px; vertical-align:-1px;">';
}

function normalizeUsZipFromAddress(array $a): string {
  $text = trim(
    ($a['zip'] ?? '') . ' ' .
    ($a['street'] ?? '') . ' ' .
    ($a['city'] ?? '')
  );

  if ($text === '') return '';

  // ZIP+4: 11706-4815 => 11706
  if (preg_match('/\b(\d{5})-\d{4}\b/', $text, $m)) {
    return $m[1];
  }

  // last standalone 5 digits
  if (preg_match_all('/\b\d{5}\b/', $text, $m) && !empty($m[0])) {
    return end($m[0]);
  }

  // MXLocker/Shoptet missing leading zero: 2703 => 02703
  if (preg_match('/\b(\d{4})\b\s*$/', $text, $m)) {
    return '0' . $m[1];
  }

  return '';
}

function usStateFromZip(string $zip): string {
  $zip = preg_replace('/\D+/', '', $zip);
  if (strlen($zip) < 5) return '';

  $n = (int)substr($zip, 0, 5);

  $ranges = [
    'AL'=>[[35000,36999]], 'AK'=>[[99500,99999]], 'AZ'=>[[85000,86999]],
    'AR'=>[[71600,72999]], 'CA'=>[[90000,96699]], 'CO'=>[[80000,81999]],
    'CT'=>[[6000,6999]],   'DE'=>[[19700,19999]], 'DC'=>[[20000,20099],[20200,20599],[56900,56999]],
    'FL'=>[[32000,34999]], 'GA'=>[[30000,31999],[39800,39999]], 'HI'=>[[96700,96899]],
    'ID'=>[[83200,83999]], 'IL'=>[[60000,62999]], 'IN'=>[[46000,47999]],
    'IA'=>[[50000,52999]], 'KS'=>[[66000,67999]], 'KY'=>[[40000,42999]],
    'LA'=>[[70000,71599]], 'ME'=>[[3900,4999]],   'MD'=>[[20600,21999]],
    'MA'=>[[1000,2799],[5500,5599]], 'MI'=>[[48000,49999]], 'MN'=>[[55000,56799]],
    'MS'=>[[38600,39799]], 'MO'=>[[63000,65999]], 'MT'=>[[59000,59999]],
    'NE'=>[[68000,69999]], 'NV'=>[[88900,89999]], 'NH'=>[[3000,3899]],
    'NJ'=>[[7000,8999]],   'NM'=>[[87000,88499]], 'NY'=>[[10000,14999],[500,599],[6390,6390]],
    'NC'=>[[27000,28999]], 'ND'=>[[58000,58999]], 'OH'=>[[43000,45999]],
    'OK'=>[[73000,74999]], 'OR'=>[[97000,97999]], 'PA'=>[[15000,19699]],
    'RI'=>[[2800,2999]],   'SC'=>[[29000,29999]], 'SD'=>[[57000,57999]],
    'TN'=>[[37000,38599]], 'TX'=>[[75000,79999],[88500,88599]], 'UT'=>[[84000,84999]],
    'VT'=>[[5000,5999]],   'VA'=>[[20100,24699]], 'WA'=>[[98000,99499]],
    'WV'=>[[24700,26999]], 'WI'=>[[53000,54999]], 'WY'=>[[82000,83199]],
  ];

  foreach ($ranges as $state => $rs) {
    foreach ($rs as $r) {
      if ($n >= $r[0] && $n <= $r[1]) return $state;
    }
  }

  return '';
}

function addressCopyText(array $a, string $state = ''): string {
  return trim(
    ($a['name'] ?? '') . "\n" .
    ($a['company'] ?? '') . "\n" .
    ($a['street'] ?? '') . "\n" .
    trim(($a['city'] ?? '') . ' ' . ($a['zip'] ?? '')) .
    ($state !== '' ? "\nState: " . $state : '')
  );
}

// tu upraviť farbu badge podľa statusu, používa sa v detailoch objednávky a v zozname objednávok
function status_badge_class($status): string {
  $s = strtoupper(trim((string)$status));
  switch ($s) {
    case 'NEW': return 'bg-info';
    case 'IN_PROGRESS': return 'bg-warning';
    case 'HOLD': return 'bg-secondary';
    case 'DONE': return 'bg-success';
    case 'COMPLETED': return 'bg-success';
    case 'SHIPPED': return 'bg-success';
    case 'NEED_INFO': return 'bg-danger';
    case 'CANCELLED': return 'bg-secondary';
    default: return 'bg-secondary';
  }
}

// --- order header ---
$stmt = $conn->prepare("SELECT
    o.*,
    os.code AS source_code,
    cu.name AS customer_name,
    cu.email AS customer_email,
    cu.phone AS customer_phone
  FROM orders o
  JOIN order_sources os ON os.id=o.source_id
  LEFT JOIN customers cu ON cu.id=o.customer_id
  WHERE o.id=?
  LIMIT 1
");
if (!$stmt) out(500, ['ok'=>false,'error'=>'SQL prepare failed: '.$conn->error]);
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) out(404, ['ok'=>false,'error'=>'Order not found']);

// --- ACL (dept) ---
if (!$allAccess) {
  $deptFilter = [
    2 => ['GRAPHICS'],
    6 => ['PLASTICS'],
    8 => ['SEATCOVER'],
    9 => ['FITTING'],
  ];
  $cats = $deptFilter[$dpt] ?? ['__NONE__'];

  $ph = implode(',', array_fill(0, count($cats), '?'));
  $types = 'i' . str_repeat('s', count($cats));
  $params = array_merge([$orderId], $cats);

  $q = $conn->prepare("SELECT 1
    FROM order_categories oc
    JOIN categories c ON c.id=oc.category_id
    WHERE oc.order_id=? AND c.code IN ($ph)
    LIMIT 1
  ");
  if (!$q) out(500, ['ok'=>false,'error'=>'ACL prepare failed: '.$conn->error]);
  $q->bind_param($types, ...$params);
  $q->execute();
  $ok = (bool)$q->get_result()->fetch_row();
  $q->close();

  if (!$ok) out(403, ['ok'=>false,'error'=>'Forbidden']);
}

// --- categories ---
$stmt = $conn->prepare("SELECT c.code
  FROM order_categories oc
  JOIN categories c ON c.id=oc.category_id
  WHERE oc.order_id=?
  ORDER BY c.code
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$cats = [];
$r = $stmt->get_result();
while ($x = $r->fetch_assoc()) $cats[] = $x['code'];
$stmt->close();

// --- addresses ---
$stmt = $conn->prepare("SELECT type, name, company, street, city, zip, country, email, phone
FROM order_addresses
WHERE order_id=?
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$addr = ['BILLING'=>null,'SHIPPING'=>null];
$r = $stmt->get_result();
while ($a = $r->fetch_assoc()) {
  $addr[$a['type']] = $a;
}
$stmt->close();

$orderCountry = '';
if (!empty($addr['SHIPPING']['country'])) {
  $orderCountry = strtoupper((string)$addr['SHIPPING']['country']);
} elseif (!empty($addr['BILLING']['country'])) {
  $orderCountry = strtoupper((string)$addr['BILLING']['country']);
}

// --- items (no fetch_all to avoid mysqlnd dependency issues) ---
$stmt = $conn->prepare("SELECT id, line_no, sku, title, custom_label, item_type_code, qty, options_json
FROM order_items
WHERE order_id=?
  AND deleted_at IS NULL
  AND item_type_code IS NOT NULL
  AND item_type_code <> ''
ORDER BY COALESCE(line_no, 999999), id
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$r = $stmt->get_result();
$items = [];
while ($it = $r->fetch_assoc()) $items[] = $it;
$stmt->close();

$status = (string)($order['status'] ?? '');
$badgeClass = status_badge_class($status);

// --- build HTML ---
ob_start();
?>
<style>
/* Detail objednávky – väčší komfort čítania */
.order-detail-table {
    border-collapse: separate;
    border-spacing: 0;
}

.order-detail-table th,
.order-detail-table td {
    padding: 0.6rem 0.75rem !important;
    vertical-align: middle !important;
}

.order-detail-table td {
    line-height: 1.4;
}

/* Trochu viac priestoru medzi riadkami */
.order-detail-table tbody tr {
    height: 42px;
}

/* Jemnejší vzhľad v dark mode */
.order-detail-table th {
    background-color: #343a40;
    font-weight: 600;
}
.order-detail-table tbody tr.qty-warning-row > td {
  background: rgba(255, 193, 7, 0.22) !important;
  box-shadow: inset 4px 0 0 #ffc107;
}
.activity-log-row {
  border-bottom: 1px solid rgba(255,255,255,0.08);
  padding: 6px 0;
}
</style>
<div class="p-3">
  <div class="card card-dark mb-0" style="border-radius:14px; overflow:hidden;">
    <div class="<?php echo h($badgeClass); ?>" style="padding:10px 14px;">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <b>#<?php echo h($order['order_number'] ?? $order['external_order_id'] ?? $orderId); ?></b>
          <span class="ml-2 badge badge-light"><?php echo h($order['source_code'] ?? ''); ?></span>
          <?php if (!empty($cats)): ?>
            <span class="ml-2 text-dark badge badge-light"><?php echo h(implode(' · ', $cats)); ?></span>
          <?php endif; ?>
        </div>
            <?php if ((int)($_SESSION['permission'] ?? 0) >= 400): ?>
  <button type="button"
          class="btn btn-sm btn-light ml-2 btn-edit-order-header"
          data-order-id="<?php echo (int)$orderId; ?>">
        Edit header
      </button>
    <?php endif; ?>
        <div class="text-right">
          <?php
            $statusOptions = [
              'NEW',
              'IN_PROGRESS',
              'NEED_INFO',
              'DRAFT_REQUESTED',
              'DRAFT_READY',
              'RIPPED',
              'PRINT_QUEUE',
              'PRODUCTION',
              'READY_TO_SHIP',
              'SHIPPED',
              'HOLD',
              'CANCELLED',
            ];

            $currentStatus = strtoupper((string)($order['status'] ?? 'NEW'));
            ?>

            <select class="form-control form-control-sm order-status-select"
                    data-order-id="<?php echo (int)$orderId; ?>"
                    style="min-width:180px;">
              <?php foreach ($statusOptions as $st): ?>
                <option value="<?php echo h($st); ?>" <?php echo ($currentStatus === $st ? 'selected' : ''); ?>>
                  <?php echo h(str_replace('_', ' ', $st)); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php
                $manualTypes = strtoupper((string)($order['manual_types_override'] ?? ''));
                $typeOptions = [
                  '' => 'AUTO',
                  'G' => 'G',
                  'P' => 'P',
                  'S' => 'S',
                  'F' => 'F',
                  'GP' => 'GP',
                  'GS' => 'GS',
                  'GF' => 'GF',
                  'PS' => 'PS',
                  'PF' => 'PF',
                  'SF' => 'SF',
                  'GPS' => 'GPS',
                  'GPF' => 'GFP',
                  'GSF' => 'GSF',
                  'PSF' => 'PSF',
                  'GPSF' => 'GFPS',
                ];
                ?>

                <?php if ((int)($_SESSION['permission'] ?? 0) >= 300): ?>
                  <select class="form-control form-control-sm order-types-select mt-1"
                          data-order-id="<?php echo (int)$orderId; ?>"
                          style="min-width:180px;">
                    <?php foreach ($typeOptions as $val => $label): ?>
                      <option value="<?php echo h($val); ?>" <?php echo ($manualTypes === $val ? 'selected' : ''); ?>>
                        <?php echo h($label); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
        </div>
      </div>
    </div>



    <div class="card-body">

      <div class="row">
        <div class="col-md-6">
          <div>
          <b>Zákazník:</b><br />
          <?php $val = $order['customer_name'] ?: $order['customer_email'] ?: '-'; ?>
          <?php echo h($val); ?>
          <button class="btn btn-xs btn-copy-inline ml-1" data-copy="<?php echo h($val); ?>">📋</button>
        </div>
          <?php if (!empty($order['customer_email'])): ?>
          <div class="text-muted">
            <?php echo h($order['customer_email']); ?>
            <button class="btn btn-xs btn-copy-inline ml-1" data-copy="<?php echo h($order['customer_email']); ?>">📋</button>
          </div>
          <?php endif; ?>
          <?php if (!empty($order['customer_phone'])): ?>
          <div class="text-muted">
            <?php echo h($order['customer_phone']); ?>
            <button class="btn btn-xs btn-copy-inline ml-1" data-copy="<?php echo h($order['customer_phone']); ?>">📋</button>
          </div>
          <?php endif; ?>
        </div>
        <div class="col-md-6">
          <div><b>Shipping:</b> <?php echo h($order['shipping_method'] ?? '-'); ?></div>
          <div><b>Payment:</b> <?php echo h($order['payment_method'] ?? '-'); ?></div>

              <div>
                <b>Country:</b>
                <span class="order-country-display"><?php echo h($orderCountry ?: '-'); ?></span>

                <?php if ((int)($_SESSION['permission'] ?? 0) >= 400): ?>
                  <button type="button"
                          class="btn btn-xs btn-outline-warning btn-edit-country ml-2"
                          data-order-id="<?php echo (int)$orderId; ?>"
                          data-country="<?php echo h($orderCountry); ?>">
                    Edit
                  </button>
                <?php endif; ?>
              </div>

              <div class="text-muted">
            <b>Dátum:</b> <?php echo h($order['order_date'] ?? '-'); ?>
            <span class="ml-2"><b>Import:</b> <?php echo h($order['imported_at'] ?? '-'); ?></span>
          </div>
        </div>
      </div>

      <?php if ((int)($_SESSION['permission'] ?? 0) >= 400): ?>
<div class="order-header-edit mt-3" style="display:none;">
  <div class="card bg-dark border-warning">
    <div class="card-header">
      <b>Edit order header</b>
    </div>

    <div class="card-body">
      <input type="hidden" class="edit-order-id" value="<?php echo (int)$orderId; ?>">

      <div class="form-row">
        <div class="form-group col-md-6">
          <label>Shipping</label>
          <input class="form-control form-control-sm edit-delivery"
                 value="<?php echo h($order['shipping_method'] ?? ''); ?>">
        </div>

        <div class="form-group col-md-6">
          <label>Payment</label>
          <input class="form-control form-control-sm edit-payment"
                 value="<?php echo h($order['payment_method'] ?? ''); ?>">
        </div>
      </div>

      <?php $b = $addr['BILLING'] ?? []; ?>
      <?php $s = $addr['SHIPPING'] ?? []; ?>

      <div class="row">
        <div class="col-md-6">
          <h6>Billing</h6>
          <input class="form-control form-control-sm mb-1 edit-billing-name" placeholder="Name" value="<?php echo h($b['name'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-billing-company" placeholder="Company" value="<?php echo h($b['company'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-billing-street" placeholder="Street" value="<?php echo h($b['street'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-billing-city" placeholder="City" value="<?php echo h($b['city'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-billing-zip" placeholder="ZIP" value="<?php echo h($b['zip'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-billing-country" placeholder="Country" value="<?php echo h($b['country'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-billing-email" placeholder="Email" value="<?php echo h($b['email'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-billing-phone" placeholder="Phone" value="<?php echo h($b['phone'] ?? ''); ?>">
        </div>

        <div class="col-md-6">
          <h6>Shipping</h6>
          <input class="form-control form-control-sm mb-1 edit-shipping-name" placeholder="Name" value="<?php echo h($s['name'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-shipping-company" placeholder="Company" value="<?php echo h($s['company'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-shipping-street" placeholder="Street" value="<?php echo h($s['street'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-shipping-city" placeholder="City" value="<?php echo h($s['city'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-shipping-zip" placeholder="ZIP" value="<?php echo h($s['zip'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-shipping-country" placeholder="Country" value="<?php echo h($s['country'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-shipping-email" placeholder="Email" value="<?php echo h($s['email'] ?? ''); ?>">
          <input class="form-control form-control-sm mb-1 edit-shipping-phone" placeholder="Phone" value="<?php echo h($s['phone'] ?? ''); ?>">
        </div>
      </div>

      <button type="button" class="btn btn-warning btn-sm mt-2 btn-save-order-header">
        Save changes
      </button>

      <button type="button" class="btn btn-secondary btn-sm mt-2 btn-cancel-order-header">
        Cancel
      </button>
    </div>
  </div>
</div>
<?php endif; ?>
      <hr/>

      <div class="row">
        <div class="col-md-6">
          <h6 class="text-muted"><span class="badge badge-secondary">Billing</span></h6>
          <?php $b = $addr['BILLING']; ?>
          <?php if ($b): ?>
            <?php
            if (strtoupper($b['country'] ?? '') === 'US') {
            $billingZip = normalizeUsZipFromAddress($b);
            $billingState = usStateFromZip($billingZip);
          }

            $fullBilling = trim(
              ($b['name'] ?? '') . "\n" .
              ($b['company'] ?? '') . "\n" .
              ($b['street'] ?? '') . "\n" .
              trim(($b['city'] ?? '') . " " . ($b['zip'] ?? '')) .
              ($billingState !== '' ? "\n" . $billingState : '')
            );
            ?>
            <button class="btn btn-xs btn-copy-inline mb-2" data-copy="<?php echo h($fullBilling); ?>">
              📋 Copy address
            </button>
            <div><?php echo h($b['name'] ?? '-'); ?><?php echo !empty($b['company']) ? ' ('.h($b['company']).')' : ''; ?></div>
            <div class="text-muted"><?php echo h(trim(($b['street'] ?? '').', '.($b['city'] ?? '').' '.($b['zip'] ?? ''))); ?></div>
            
            <?php if (!empty($b['country'])): ?>
              <div class="text-muted">
                <?php if ($billingState !== ''): ?>
                  <div>
                    <span><b><?php echo h($billingState); ?></b></span>
                  </div>
                <?php endif; ?>

                <?php
                  $cc = strtoupper($b['country']);
                  echo countryFlag($cc) . ' ' . h($cc);
                ?>

                <hr class="my-2">

                <h6 class="text-muted mb-2">
                  <span class="badge badge-secondary">Invoices</span>
                </h6>

                <?php
                $invStmt = $conn->prepare("
                  SELECT id, invoice_number
                  FROM order_invoices
                  WHERE order_id = ? AND deleted_at IS NULL
                  ORDER BY id DESC
                ");
                $invStmt->bind_param('i', $orderId);
                $invStmt->execute();
                $invRes = $invStmt->get_result();
                ?>

                <?php while ($inv = $invRes->fetch_assoc()): ?>
                  <div class="small mb-1 d-flex align-items-center">

                    <div>
                      <b><?php echo h($inv['invoice_number']); ?></b>
                    </div>

                    <?php if ((int)($_SESSION['permission'] ?? 0) >= 400): ?>
                      <button class="btn btn-xs btn-outline-danger ml-2 py-0 px-2 btn-delete-invoice" data-id="<?php echo (int)$inv['id']; ?>" data-order-id="<?php echo (int)$orderId; ?>"> × </button>
                    <?php endif; ?>

                  </div>

                <?php endwhile; ?>
                <?php $invStmt->close(); ?>

                <?php if ((int)($_SESSION['permission'] ?? 0) >= 400): ?>
                  <div class="form-row mt-2">
                    <div class="col-md-8">
                      <input class="form-control form-control-sm invoice-number"
                            placeholder="Invoice number">
                    </div>
                    <div class="col-md-4">
                      <button class="btn btn-sm btn-info btn-block btn-add-invoice"
                              data-order-id="<?php echo (int)$orderId; ?>">
                        Add Invoice
                      </button>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="text-muted">—</div>
          <?php endif; ?>
        </div>

          <div class="col-md-6">
             <h6 class="text-muted"><span class="badge badge-secondary">Delivery</span></h6>
            <?php $s = $addr['SHIPPING']; ?>
            <?php if ($s): ?>
              <?php
                $shippingZip = normalizeUsZipFromAddress($s);
                $shippingState = '';

                  if (strtoupper($s['country'] ?? '') === 'US') {
                    $shippingZip = normalizeUsZipFromAddress($s);
                    $shippingState = usStateFromZip($shippingZip);
                  }
                $fullShipping = addressCopyText($s, $shippingState);
              ?>

              <button class="btn btn-xs btn-copy-inline mb-2" data-copy="<?php echo h($fullShipping); ?>">
                📋 Copy address
              </button>

              <div>
                <?php echo h($s['name'] ?? '-'); ?>
                <?php echo !empty($s['company']) ? ' ('.h($s['company']).')' : ''; ?>
              </div>

              <div class="text-muted">
                <?php echo h(trim(($s['street'] ?? '').', '.($s['city'] ?? '').' '.($s['zip'] ?? ''))); ?>
              </div>

              <?php if ($shippingState !== ''): ?>
                <div>
                  <span><?php echo h($shippingState); ?></span>
                </div>
              <?php endif; ?>

              <?php if (!empty($s['country'])): ?>
                <div class="text-muted">
                  <?php
                    $cc = strtoupper($s['country']);
                    echo countryFlag($cc) . ' ' . h($cc);
                  ?>
                </div>
              <?php endif; ?>

            <?php else: ?>
              <div class="text-muted">—</div>
            <?php endif; ?>
            <hr class="my-2">

          <h6 class="text-muted mb-2">
            <span class="badge badge-secondary">Tracking</span>
          </h6>
          

          <?php
          $trackingStmt = $conn->prepare("
            SELECT id, tracking_number, carrier
            FROM order_tracking_numbers
            WHERE order_id = ? AND deleted_at IS NULL
            ORDER BY id DESC
          ");
          $trackingStmt->bind_param('i', $orderId);
          $trackingStmt->execute();
          $trackingRes = $trackingStmt->get_result();
          ?>

          <?php while ($t = $trackingRes->fetch_assoc()): ?>
            <div class="small mb-1 d-flex align-items-center">
  
            <div>
              <b><?php echo h($t['tracking_number']); ?></b>
              <?php if (!empty($t['carrier'])): ?>
                <span class="text-muted">(<?php echo h($t['carrier']); ?>)</span>
              <?php endif; ?>
            </div>

            <?php if ((int)($_SESSION['permission'] ?? 0) >= 400): ?>
              <button class="btn btn-xs btn-outline-danger ml-2 py-0 px-2 btn-delete-tracking" data-id="<?php echo (int)$t['id']; ?>" data-order-id="<?php echo (int)$orderId; ?>">
                ×
              </button>
            <?php endif; ?>

          </div>
          <?php endwhile; ?>
          <?php $trackingStmt->close(); ?>

          <?php if ((int)($_SESSION['permission'] ?? 0) >= 400): ?>
            <div class="form-row mt-2">
              <div class="col-md-7">
                <input class="form-control form-control-sm tracking-number"
                      placeholder="Tracking number">
              </div>
              <div class="col-md-3">
                <input class="form-control form-control-sm tracking-carrier"
                      placeholder="Carrier">
              </div>
              <div class="col-md-2">
                <button class="btn btn-sm btn-info btn-block btn-add-tracking"
                        data-order-id="<?php echo (int)$orderId; ?>">
                  Add Tracking
                </button>
                
              </div>
            </div>
          <?php endif; ?>
          </div>
      </div>
          
      <hr/>

<h6 class="text-muted mb-2">Production note</h6>

        <div class="card bg-dark border-info p-2 production-note-box">
          <div class="production-note-display text-light">
            <?php echo nl2br(h($order['production_note'] ?? '')); ?>
            <?php if (trim((string)($order['production_note'] ?? '')) === ''): ?>
              <span class="text-muted">No production note.</span>
            <?php endif; ?>
          </div>

          <?php if ((int)($_SESSION['permission'] ?? 0) >= 400): ?>
            <textarea class="form-control form-control-sm mt-2 production-note-input production-note-textarea"
                      rows="3"
                      placeholder="Customer changes / production instructions..."><?php echo h($order['production_note'] ?? ''); ?></textarea>
              <div class="mt-2">  
            <button class="btn btn-sm btn-info mt-2 btn-save-production-note"
              style="width:auto; display:inline-block;"
              data-order-id="<?php echo (int)$orderId; ?>">
              Save note
            </button>
            </div>
          <?php endif; ?>
      </div>
      <h6 class="text-muted mb-2">Položky</h6>
      <?php if ((int)($_SESSION['permission'] ?? 0) >= 300): ?>
        <div class="card bg-dark border-info p-2 mb-3 manual-item-box">
          <div class="d-flex justify-content-between align-items-center">
            <b class="text-info">Add manual item</b>
          </div>

          <div class="form-row mt-2">
            <div class="col-md-2">
              <select class="form-control form-control-sm manual-item-type">
                <option value="">Select type...</option>
                <option value="G">G - Graphics</option>
                <option value="P">P - Plastics</option>
                <option value="S">S - Seat Cover</option>
                <option value="F">F - Fitting</option>
                <option value="T">T - Trim Kit</option>
                <option value="M">M - Bike Mats</option>
              </select>
            </div>

            <div class="col-md-1">
              <input type="number"
                    class="form-control form-control-sm manual-item-qty"
                    value="1"
                    min="1"
                    placeholder="Qty">
            </div>

            <div class="col-md-3">
              <input class="form-control form-control-sm manual-item-sku"
                    placeholder="SKU"
                    value="MANUAL">
            </div>

            <div class="col-md-4">
              <input class="form-control form-control-sm manual-item-title"
                    placeholder="Item title / service name">
            </div>

            <div class="col-md-2">
              <button type="button"
                      class="btn btn-sm btn-info btn-block btn-add-manual-item"
                      data-order-id="<?php echo (int)$orderId; ?>">
                Add item
              </button>
            </div>
          </div>

          <div class="mt-2">
            <input class="form-control form-control-sm manual-item-reason"
                  placeholder="Reason / customer request note">
          </div>
        </div>
        <?php endif; ?>
      <div class="table-responsive">
    <table class="table table-sm table-bordered mb-0 order-detail-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Typ</th>
              <th>Názov</th>
              <th>SKU</th>
              <th>Label</th>
              <th>Qty</th>
              <th>Edit</th>
              <?php if ((int)($_SESSION['permission'] ?? 0) >= 300): ?>
              <th>Actions</th>
              <th>Delete</th>
            <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if ($hasManualTypes): ?>
              <span class="badge badge-light mr-1" title="Manual types override">M</span>
            <?php endif; ?>
          <?php foreach ($items as $it): ?>
            <?php
              $t = strtoupper((string)($it['item_type_code'] ?? 'NULL'));
              $badge = 'badge-secondary';             
              
              if ($t === 'T' || $t === 'M') $badge = 'badge-warning';
              elseif ($t === 'G') $badge = 'badge-info';
              elseif ($t === 'P') $badge = 'badge-primary';
              elseif ($t === 'S') $badge = 'badge-success';
              elseif ($t === 'F') $badge = 'badge-danger';
              $qty = (int)($it['qty'] ?? 1);
              $rowClass = $qty > 1 ? 'qty-warning-row' : '';
              $optPreview = '';
              if (!empty($it['options_json'])) {
                $decoded = json_decode((string)$it['options_json'], true);
                if (is_array($decoded)) {
                  $pairs = [];
                  foreach ($decoded as $k=>$v) {
                    if ($k === '_item') continue;
                    if (is_array($v)) continue;
                    $pairs[] = $k . ': ' . (string)$v;
                    if (count($pairs) >= 4) break;
                  }
                  $optPreview = implode(' | ', $pairs);
                } else {
                  $optPreview = substr((string)$it['options_json'], 0, 120);
                }
              }
            ?>
            <tr class="<?php echo h($rowClass); ?>">
              <td><?php echo (int)($it['line_no'] ?? 0); ?></td>
              <td><span class="badge <?php echo h($badge); ?>"><?php echo h($t); ?></span></td>
              <td>
              <input class="form-control form-control-sm item-title"
                    value="<?php echo h($it['title']); ?>">
            </td>

            <td>
              <select class="form-control form-control-sm item-type">
                <?php foreach (['G','P','S','F','T','M'] as $t): ?>
                  <option value="<?= $t ?>" <?= ($it['item_type_code']===$t?'selected':'') ?>>
                    <?= $t ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>

            <td>
              <input type="number"
                    class="form-control form-control-sm item-qty"
                    value="<?php echo (int)$it['qty']; ?>">
            </td>

            <td>
              <input class="form-control form-control-sm item-sku"
                    value="<?php echo h($it['sku']); ?>">
            </td>

            <td class="text-center">
              <button class="btn btn-sm btn-outline-success btn-save-item"
                      data-id="<?php echo (int)$it['id']; ?>"
                      data-order-id="<?php echo (int)$orderId; ?>">
                EDIT
              </button>
            </td>
              <td class="text-center">
              <button class="btn btn-sm btn-outline-info btn-view-options"
                      data-options='<?php echo h($it['options_json'] ?? ''); ?>'>
                VIEW
              </button>

              <button class="btn btn-sm btn-outline-warning btn-copy-options"
                      data-options='<?php echo h($it['options_json'] ?? ''); ?>'>
                COPY
              </button>
              </td>
              <?php if ((int)($_SESSION['permission'] ?? 0) >= 300): ?>
                <td class="text-center">
                  <button type="button"
                          class="btn btn-xs btn-outline-danger btn-delete-order-item"
                          data-item-id="<?php echo (int)$it['id']; ?>"
                          data-order-id="<?php echo (int)$orderId; ?>">
                    DELETE
                  </button>                
              <?php endif; ?>
            </td>



            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ((int)($_SESSION['permission'] ?? 0) >= 400): ?>
  <hr/>

  <button type="button"
          class="btn btn-sm btn-outline-info btn-toggle-activity"
          data-order-id="<?php echo (int)$orderId; ?>">
    Activity log
  </button>

  <div class="activity-log-panel mt-2" style="display:none;">
    <?php
    $actStmt = $conn->prepare("
      SELECT
        oa.id,
        oa.action,
        oa.entity_type,
        oa.entity_id,
        oa.payload,
        oa.note,
        oa.created_at,
        CONCAT(e.firstname, ' ', e.lastname) AS actor_name
      FROM order_activity oa
      LEFT JOIN employees e ON e.id = oa.actor_employee_id
      WHERE oa.order_id = ?
      ORDER BY oa.id DESC
      LIMIT 30
    ");
    $actStmt->bind_param('i', $orderId);
    $actStmt->execute();
    $actRes = $actStmt->get_result();
    ?>

    <div class="small activity-log-list">
      <?php while ($a = $actRes->fetch_assoc()): ?>
        <div class="py-1 activity-log-row" style="border-bottom: 1px solid rgba(255,255,255,0.08);">
          <span class="text-muted"><?php echo h($a['created_at']); ?></span>
          —
          <b><?php echo h($a['actor_name'] ?: 'System'); ?></b>
          :
          <span><?php echo h($a['note'] ?: $a['action']); ?></span>
        </div>
      <?php endwhile; ?>
    </div>

    <?php $actStmt->close(); ?>

    <button type="button"
            class="btn btn-xs btn-outline-secondary mt-2 btn-load-older-activity"
            data-order-id="<?php echo (int)$orderId; ?>"
            data-offset="30">
      Load older
    </button>
  </div>
<?php endif; ?>
      </div>

    </div>
  </div>
</div>
<?php
$html = ob_get_clean();

out(200, ['ok'=>true,'html'=>$html]);
try {
  // ... celý tvoj existujúci kód (queries, build html) ...

  out(200, ['ok'=>true,'html'=>$html]);

} catch (Throwable $e) {
  out(500, ['ok'=>false,'error'=>$e->getMessage()]);
}
?>
