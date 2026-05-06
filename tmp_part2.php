    <div class="<?php echo h($badgeClass); ?>" style="padding:10px 14px;">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <b>#<?php echo h($order['order_number'] ?? $order['external_order_id'] ?? $orderId); ?></b>
          <span class="ml-2 badge badge-light"><?php echo h($order['source_code'] ?? ''); ?></span>
          <?php if (!empty($cats)): ?>
            <span class="ml-2 text-dark badge badge-dark"><?php echo h(implode(' · ', $cats)); ?></span>
          <?php endif; ?>
        </div>
        <?php if ($isAdminLike): ?>
          <button type="button" class="btn btn-sm btn-light ml-2 btn-edit-order-header"
            data-order-id="<?php echo (int) $orderId; ?>">
            Edit header
          </button>
        <?php endif; ?>
        <div class="d-flex justify-content-end align-items-center" style="gap:6px;">
          <?php
          $statusOptions = [
            'NEW',
            'IN_PROGRESS',
            'NEED_INFO',
            'DRAFT_READY',
            'READY_TO_INVOICE',
            'READY_TO_SHIP',
            'SHIPPED',
            'HOLD',
            'CANCELLED'
          ];

          $currentStatus = strtoupper(trim((string) ($it['item_status'] ?? 'NEW')));
          if ($currentStatus === '') {
            $currentStatus = 'NEW';
          }
          ?>

          <select class="form-control form-control-sm order-status-select" data-order-id="<?php echo (int) $orderId; ?>"
            style="min-width:180px;">
            <?php foreach ($statusOptions as $st): ?>
              <option value="<?php echo h($st); ?>" <?php echo ($currentStatus === $st ? 'selected' : ''); ?>>
                <?php echo h(str_replace('_', ' ', $st)); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php
          $manualTypes = strtoupper((string) ($order['manual_types_override'] ?? ''));
          $hasManualTypes = $manualTypes !== '';
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

          <?php if ($isAdminLike): ?>
            <select class="form-control form-control-sm order-types-select mt-1"
              data-order-id="<?php echo (int) $orderId; ?>" style="min-width:180px;">
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
              <button class="btn btn-xs btn-copy-inline ml-1"
                data-copy="<?php echo h($order['customer_email']); ?>">📋</button>
            </div>
          <?php endif; ?>
          <?php if ($displayCustomerPhone !== ''): ?>
            <div class="text-muted">
              <?php echo h($displayCustomerPhone); ?>
              <button class="btn btn-xs btn-copy-inline ml-1"
                data-copy="<?php echo h($displayCustomerPhone); ?>">📋</button>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-md-6">
          <div><b>Shipping:</b> <?php echo h($order['shipping_method'] ?? '-'); ?></div>
          <div><b>Payment:</b> <?php echo h($order['payment_method'] ?? '-'); ?></div>

          <div>
            <b>Country:</b>
            <span class="order-country-display"><?php echo h($orderCountry ?: '-'); ?></span>

            <?php if ($isAdminLike): ?>
              <button type="button" class="btn btn-xs btn-outline-warning btn-edit-country ml-2"
                data-order-id="<?php echo (int) $orderId; ?>" data-country="<?php echo h($orderCountry); ?>">
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

      <?php if ($isAdminLike): ?>
        <div class="order-header-edit mt-3" style="display:none;">
          <div class="card bg-dark border-warning">
            <div class="card-header">
              <b>Edit order header</b>
            </div>

            <div class="card-body">
              <input type="hidden" class="edit-order-id" value="<?php echo (int) $orderId; ?>">

              <div class="form-row">
                <div class="form-group col-md-6">
                  <label>Payment</label>
                  <input class="form-control form-control-sm edit-payment"
                    value="<?php echo h($order['payment_method'] ?? ''); ?>">
                </div>

                <div class="form-group col-md-6">
                  <label>Shipping</label>
                  <input class="form-control form-control-sm edit-delivery"
                    value="<?php echo h($order['shipping_method'] ?? ''); ?>">
                </div>
              </div>

              <?php $b = $addr['BILLING'] ?? []; ?>
              <?php $s = $addr['SHIPPING'] ?? []; ?>

              <div class="row">
                <!-- LEFT: Billing -->
                <div class="col-md-6">
                  <h6>Billing</h6>
                  <input class="form-control form-control-sm mb-1 edit-billing-name" placeholder="Name"
                    value="<?php echo h($b['name'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-billing-company" placeholder="Company"
                    value="<?php echo h($b['company'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-billing-street" placeholder="Street"
                    value="<?php echo h($b['street'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-billing-city" placeholder="City"
                    value="<?php echo h($b['city'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-billing-zip" placeholder="ZIP"
                    value="<?php echo h($b['zip'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-billing-country" placeholder="Country"
                    value="<?php echo h($b['country'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-billing-email" placeholder="Email"
                    value="<?php echo h($b['email'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-billing-phone" placeholder="Phone"
                    value="<?php echo h($b['phone'] ?? ''); ?>">
                </div>

                <!-- RIGHT: Shipping -->
                <div class="col-md-6">
                  <h6>Shipping</h6>
                  <input class="form-control form-control-sm mb-1 edit-shipping-name" placeholder="Name"
                    value="<?php echo h($s['name'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-shipping-company" placeholder="Company"
                    value="<?php echo h($s['company'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-shipping-street" placeholder="Street"
                    value="<?php echo h($s['street'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-shipping-city" placeholder="City"
                    value="<?php echo h($s['city'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-shipping-zip" placeholder="ZIP"
                    value="<?php echo h($s['zip'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-shipping-country" placeholder="Country"
                    value="<?php echo h($s['country'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-shipping-email" placeholder="Email"
                    value="<?php echo h($s['email'] ?? ''); ?>">
                  <input class="form-control form-control-sm mb-1 edit-shipping-phone" placeholder="Phone"
                    value="<?php echo h($s['phone'] ?? ''); ?>">
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
      <hr />
 <?php if ($isAdminLike): ?>
      <div class="row">
        <div class="col-md-6">          
          <h6 class="text-muted"><span class="badge badge-secondary">Billing</span></h6>
          <?php $b = $addr['BILLING']; ?>
          <?php if ($b): ?>
            <?php
            $billingState = '';
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
            <div>
              <?php echo h($b['name'] ?? '-'); ?>
              <?php echo !empty($b['company']) ? ' (' . h($b['company']) . ')' : ''; ?>
            </div>
            <div class="text-muted">
              <?php echo h(trim(($b['street'] ?? '') . ', ' . ($b['city'] ?? '') . ' ' . ($b['zip'] ?? ''))); ?>
            </div>
            <?php if (!empty($b['phone'])): ?>
              <div class="text-muted">
                <b>Phone:</b> <?php echo h($b['phone']); ?>
                <button class="btn btn-xs btn-copy-inline ml-1" data-copy="<?php echo h($b['phone']); ?>">📋</button>
              </div>
            <?php endif; ?>
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

                    <?php if ($isAdminLike): ?>
                      <button class="btn btn-xs btn-outline-danger ml-2 py-0 px-2 btn-delete-invoice"
                        data-id="<?php echo (int) $inv['id']; ?>" data-order-id="<?php echo (int) $orderId; ?>"> × </button>
                    <?php endif; ?>

                  </div>

                <?php endwhile; ?>
                <?php $invStmt->close(); ?>

                <?php if ($isAdminLike): ?>
                  <div class="form-row mt-2">
                    <div class="col-md-8">
                      <input class="form-control form-control-sm invoice-number" placeholder="Invoice number">
                    </div>
                    <div class="col-md-4">
                      <button class="btn btn-sm btn-info btn-block btn-add-invoice"
                        data-order-id="<?php echo (int) $orderId; ?>">
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
              <?php echo !empty($s['company']) ? ' (' . h($s['company']) . ')' : ''; ?>
            </div>

            <div class="text-muted">
              <?php echo h(trim(($s['street'] ?? '') . ', ' . ($s['city'] ?? '') . ' ' . ($s['zip'] ?? ''))); ?>
            </div>

            <?php if ($shippingState !== ''): ?>
 <?php if ($isAdminLike): ?>
  <hr class="my-3">
<?php else: ?>
  <div class="my-3"></div>
<?php endif; ?>

<div class="card bg-dark border-info mb-3 production-note-box">
  <div class="card-header py-2">
    <h6 class="mb-0 text-muted">
      <i class="fas fa-sticky-note mr-1"></i> Production note
    </h6>
  </div>

  <div class="card-body">
    <div class="production-note-display text-light" style="white-space:pre-wrap;">
      <?php if (trim((string)($order['production_note'] ?? '')) !== ''): ?>
        <?php echo h($order['production_note'] ?? ''); ?>
      <?php else: ?>
        <span class="text-muted">No production note.</span>
      <?php endif; ?>
    </div>

    <?php if ($isAdminLike): ?>
      <textarea class="form-control form-control-sm mt-2 production-note-input production-note-textarea"
                rows="3"
                placeholder="Customer changes / production instructions..."><?php echo h($order['production_note'] ?? ''); ?></textarea>

      <div class="mt-2">
        <button class="btn btn-sm btn-info btn-save-production-note"
                data-order-id="<?php echo (int) $orderId; ?>">
          Save note
        </button>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
  <h6 class="text-muted mb-0">
    <i class="fas fa-boxes mr-1"></i> Položky
  </h6>
</div>

<?php if ($isAdminLike): ?>
  <div class="card bg-dark border-secondary mb-3 manual-item-box">
    <div class="card-header py-2">
      <h6 class="mb-0 text-muted">
        <i class="fas fa-plus-circle mr-1"></i> Add manual item
      </h6>
    </div>

    <div class="card-body">
      <div class="form-row">
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
          <input type="number" class="form-control form-control-sm manual-item-qty" value="1" min="1" placeholder="Qty">
        </div>

        <div class="col-md-3">
          <input class="form-control form-control-sm manual-item-sku" placeholder="SKU" value="MANUAL">
        </div>

        <div class="col-md-4">
          <input class="form-control form-control-sm manual-item-title" placeholder="Item title / service name">
        </div>

        <div class="col-md-2">
          <button type="button"
                  class="btn btn-sm btn-info btn-block btn-add-manual-item"
                  data-order-id="<?php echo (int) $orderId; ?>">
            Add item
          </button>
        </div>
      </div>

      <div class="mt-2">
        <input class="form-control form-control-sm manual-item-reason" placeholder="Reason / customer request note">
      </div>
    </div>
  </div>
<?php endif; ?>
              <div class="col-md-2">
                <button class="btn btn-sm btn-info btn-block btn-add-tracking"
                  data-order-id="<?php echo (int) $orderId; ?>">
                  Add Tracking
                </button>

              </div>
            </div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <hr />
 
      <h6 class="text-muted mb-2">Production note</h6>

      <div class="card bg-dark border-info p-2 production-note-box">
        <div class="d-flex justify-content-between align-items-start">
          <div class="production-note-display text-light" style="white-space:pre-wrap; flex:1;">
            <?php if (trim((string) ($order['production_note'] ?? '')) !== ''): ?>
              <?php echo h($order['production_note'] ?? ''); ?>
            <?php else: ?>
              <span class="text-muted">No production note.</span>
            <?php endif; ?>
          </div>

          <?php if ($isAdminLike): ?>
            <button type="button" class="btn btn-xs btn-outline-info ml-2 btn-edit-production-note">
              Edit
            </button>
          <?php endif; ?>
        </div>

        <?php if ($isAdminLike): ?>
          <div class="production-note-editor mt-2" style="display:none;">
            <textarea class="form-control form-control-sm production-note-input production-note-textarea" rows="2"
              placeholder="Customer changes / production instructions..."><?php echo h($order['production_note'] ?? ''); ?></textarea>

            <div class="mt-2">
              <button class="btn btn-sm btn-info btn-save-production-note" data-order-id="<?php echo (int) $orderId; ?>">
                Save
              </button>

              <button type="button" class="btn btn-sm btn-secondary btn-cancel-production-note">
                Cancel
              </button>
            </div>
          </div>
       
      </div>


      <h6 class="text-muted mb-2">Položky </h6>
      <?php if ($isAdminLike): ?>
        <div class="card bg-dark border-info p-2 mb-3 manual-item-box">
          <div class="d-flex justify-content-between align-items-center">
            <b class="text-info">Add manual item</b>
          </div>
<?php endif; ?>
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
              <input type="number" class="form-control form-control-sm manual-item-qty" value="1" min="1"
                placeholder="Qty">
            </div>

            <div class="col-md-3">
              <input class="form-control form-control-sm manual-item-sku" placeholder="SKU" value="MANUAL">
            </div>

            <div class="col-md-4">
              <input class="form-control form-control-sm manual-item-title" placeholder="Item title / service name">
            </div>

            <div class="col-md-2">
              <button type="button" class="btn btn-sm btn-info btn-block btn-add-manual-item"
                data-order-id="<?php echo (int) $orderId; ?>">
                Add item
              </button>
            </div>
          </div>

          <div class="mt-2">
            <input class="form-control form-control-sm manual-item-reason" placeholder="Reason / customer request note">
          </div>
        </div>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0 order-detail-table">
          <thead>
            <tr>
              <th class="text-center">Assigned</th>
              <th>Názov</th>
              <th>SKU</th>
              <th>Label</th>
              <th>Qty</th>
              <th>Status</th>
              <th>Waiting</th>
              <th>Action</th>
              <th>Product</th>
              <th class="text-center">View</th>
              <th class="text-center">Copy</th>
              <?php if ($isAdminLike): ?>
                <th class="text-center">Save</th>
                <th class="text-center">Delete</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if ($hasManualTypes): ?>
              <span class="badge badge-light mr-1" title="Manual types override">M</span>
            <?php endif; ?>
            <?php foreach ($items as $it): ?>
              <?php
              $t = strtoupper((string) ($it['item_type_code'] ?? 'NULL'));
              $badge = 'badge-secondary';

              if ($t === 'T' || $t === 'M')
                $badge = 'badge-warning';
              elseif ($t === 'G')
                $badge = 'badge-info';
              elseif ($t === 'P')
                $badge = 'badge-primary';
              elseif ($t === 'S')
                $badge = 'badge-success';
              elseif ($t === 'F')
                $badge = 'badge-danger';
              $qty = (int) ($it['qty'] ?? 1);
              $rowClass = $qty > 1 ? 'qty-warning-row' : '';
              $optPreview = '';
              if (!empty($it['options_json'])) {
                $decoded = json_decode((string) $it['options_json'], true);
                if (is_array($decoded)) {
                  $pairs = [];
                  foreach ($decoded as $k => $v) {
                    if ($k === '_item')
                      continue;
                    if (is_array($v))
                      continue;
                    $pairs[] = $k . ': ' . (string) $v;
                    if (count($pairs) >= 4)
                      break;
                  }
                  $optPreview = implode(' | ', $pairs);
                } else {
                  $optPreview = substr((string) $it['options_json'], 0, 120);
                }
              }
              ?>
              <tr class="<?php echo ((int) $it['qty'] > 1 ? 'qty-warning-row' : ''); ?>">
                <td class="text-center" style="min-width:80px;">
                  <?php
                  $assignedRaw = trim((string) ($it['item_assigned_users'] ?? ''));
                  $itemAssigned = [];

                  if ($assignedRaw !== '') {
                    foreach (explode(';;', $assignedRaw) as $part) {
                      $bits = explode('|', $part);
                      if (count($bits) >= 3) {
                        $itemAssigned[] = [
                          'id' => (int) $bits[0],
                          'name' => $bits[1],
                          'photo' => $bits[2],
                        ];
                      }
                    }
                  }

                  $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
                  $currentUserAssignedToItem = false;

                  foreach ($itemAssigned as $a) {
                    if ((int) $a['id'] === $currentUserId) {
                      $currentUserAssignedToItem = true;
                      break;
                    }
                  }

                  $itemType = strtoupper((string) ($it['item_type_code'] ?? ''));
                  $userDpt = (int) ($_SESSION['dpt'] ?? 0);

                  $dptItemMap = [
                    2 => 'G',
                    6 => 'P',
                    8 => 'S',
                    9 => 'F',
                  ];

                  $canAssignThisItem = false;
                  $perm = (int) ($_SESSION['permission'] ?? 0);

                  if (isset($dptItemMap[$userDpt]) && $dptItemMap[$userDpt] === $itemType) {
                    if ($perm >= 400) {
                      $canAssignThisItem = true;
                    } else {
                      $deptRoleMap = [
                        2 => ['PRIMARY_GRAPHICS', 'COLLAB_GRAPHICS'],
                        6 => ['PRIMARY_PLASTICS', 'COLLAB_PLASTICS'],
                        8 => ['PRIMARY_SEATCOVER', 'COLLAB_SEATCOVER'],
                        9 => ['PRIMARY_FITTING', 'COLLAB_FITTING'],
                      ];

                      $allowedRoles = $deptRoleMap[$userDpt] ?? [];

                      if ($allowedRoles) {
                        $stmtPerm = $conn->prepare("
                        SELECT 1
                        FROM order_assignments
                        WHERE order_id = ?
                          AND employee_id = ?
                          AND role IN ('" . implode("','", array_map([$conn, 'real_escape_string'], $allowedRoles)) . "')
                          AND removed_at IS NULL
                        LIMIT 1
                      ");
                        $stmtPerm->bind_param('ii', $orderId, $currentUserId);
                        $stmtPerm->execute();
                        $canAssignThisItem = (bool) $stmtPerm->get_result()->fetch_row();
                        $stmtPerm->close();
                      }
                    }
                  }
                  ?>

                  <div class="d-flex justify-content-center align-items-center flex-wrap" style="gap:4px;">
                    <?php foreach ($itemAssigned as $a): ?>
                      <?php
                      $name = trim((string) $a['name']);
                      $photo = trim((string) $a['photo']);

                      $initials = '';
                      foreach (preg_split('/\s+/', $name) as $p) {
                        if ($p !== '') {
                          $initials .= mb_strtoupper(mb_substr($p, 0, 1));
                        }
                      }
                      $initials = mb_substr($initials, 0, 2);
                      ?>

                      <?php if ($photo !== ''): ?>
                        <img src="images/<?= h($photo) ?>" class="img-circle elevation-2"
                          style="width:28px; height:28px; object-fit:cover;" title="<?= h($name) ?>">
                      <?php else: ?>
                        <span class="badge badge-secondary"
                          style="width:28px; height:28px; line-height:28px; border-radius:50%;" title="<?= h($name) ?>">
                          <?= h($initials ?: '?') ?>
                        </span>
                      <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($canAssignThisItem && empty($itemAssigned)): ?>
                      <button type="button"
                        class="btn btn-outline-warning btn-assign-item d-flex align-items-center justify-content-center"
                        data-item-id="<?= (int) $it['id'] ?>" title="Assign me to this item"
                        style="width:28px; height:28px; padding:0; border-radius:6px;">
                        <i class="fas fa-plus"></i>
                      </button>
                    <?php endif; ?>
                  </div>
                </td>


                <td>
                  <?php if ($isAdminLike): ?>
                    <input class="form-control form-control-sm item-title" value="<?php echo h($it['title'] ?? ''); ?>">
                  <?php else: ?>
                    <?php echo h($it['title'] ?? ''); ?>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if ($isAdminLike): ?>
                    <input class="form-control form-control-sm item-sku" value="<?php echo h($it['sku'] ?? ''); ?>">
                  <?php else: ?>
                    <?php echo h($it['sku'] ?? ''); ?>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if ($isAdminLike): ?>
                    <input class="form-control form-control-sm item-label"
                      value="<?php echo h($it['custom_label'] ?? ''); ?>">
                  <?php else: ?>
                    <?php echo h($it['custom_label'] ?? ''); ?>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if ($isAdminLike): ?>
                    <input type="number" class="form-control form-control-sm item-qty"
                      value="<?php echo (int) $it['qty']; ?>" min="1">
                  <?php else: ?>
                    <?php echo (int) $it['qty']; ?>
                  <?php endif; ?>
                </td>

                <td>
                  <span class="badge badge-info">
                    <?= h($it['item_status'] ?? 'NEW') ?>
                  </span>
                </td>

                <td style="min-width:220px;">
                  <input type="text" class="form-control form-control-sm item-waiting-note"
                    data-item-id="<?= (int) $it['id'] ?>" value="<?= h($it['waiting_note'] ?? '') ?>"
                    placeholder="Na čo čakáme?">

                  <input type="date" class="form-control form-control-sm mt-1 item-expected-date"
                    data-item-id="<?= (int) $it['id'] ?>" value="<?= h($it['expected_date'] ?? '') ?>">
                </td>

                <td>
                  <?php
                  $type = strtoupper((string) ($it['item_type_code'] ?? ''));

                  if ($type === 'G') {
                    $statuses = ['NEW', 'RTP', 'PRINT_QUEUE', 'PRINTED', 'CUT', 'READY', 'WAITING'];
                  } elseif ($type === 'F') {
                    $statuses = ['NEW', 'PROCESSING', 'DONE', 'READY', 'WAITING'];
                  } else {
                    $statuses = ['NEW', 'PROCESSING', 'READY', 'WAITING'];
                  }

                  $currentStatus = strtoupper(trim((string)($order['status'] ?? 'NEW')));
                  if ($currentStatus === '') {
                    $currentStatus = 'NEW';
                  }
                  if ($currentStatus === '') {
                    $currentStatus = 'NEW';
                  }

                  if (!in_array($currentStatus, $statuses, true)) {
                    $statuses[] = $currentStatus;
                  }
                  ?>

                  <select class="form-control form-control-sm item-status-select" data-item-id="<?= (int) $it['id'] ?>">
                    <?php foreach ($statuses as $s): ?>
                      <option value="<?= h($s) ?>" <?= ($currentStatus === $s ? 'selected' : '') ?>>
                        <?= h(str_replace('_', ' ', $s)) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <?php
                $productUrl = itemProductUrl($order, $it);
                ?>

                <td class="text-center">
                  <?php if ($productUrl !== ''): ?>
                    <a href="<?= h($productUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info"
                      title="<?= h($productUrl) ?>">
                      <i class="fas fa-external-link-alt mr-1"></i> Product
                    </a>
                  <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-warning btn-set-product-url"
                      data-item-id="<?= (int) $it['id'] ?>">
                      Set URL
                    </button>
                  <?php endif; ?>
                </td>

                <td class="text-center">
                  <button type="button" class="btn btn-xs btn-outline-info btn-view-options"
                    data-options="<?php echo h(prepareOptionsJsonForModal($conn, (string) ($it['options_json'] ?? '{}'))); ?>">
                    View
                  </button>
                </td>

                <td class="text-center">
                  <button type="button" class="btn btn-xs btn-outline-warning"
                    data-copy="<?php echo h($it['options_json'] ?? ''); ?>">
                    Copy
                  </button>
                </td>

                <?php if ($isAdminLike): ?>
                  <td class="text-center">
                    <button type="button" class="btn btn-xs btn-outline-success btn-save-item"
                      data-id="<?php echo (int) $it['id']; ?>" data-order-id="<?php echo (int) $orderId; ?>">
                      Save
                    </button>
                  </td>

                  <td class="text-center">
                    <button type="button" class="btn btn-xs btn-outline-danger btn-delete-order-item"
                      data-item-id="<?php echo (int) $it['id']; ?>" data-order-id="<?php echo (int) $orderId; ?>">
                      Delete
                    </button>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($isAdminLike): ?>
          <hr />

          <button type="button" class="btn btn-sm btn-outline-info btn-toggle-activity"
            data-order-id="<?php echo (int) $orderId; ?>">
            Activity log
          </button>

          <div class="activity-log-panel mt-2" style="display:none;">
            <?php
            $actStmt = $conn->prepare("SELECT
        oa.id,
        oa.action,
        oa.entity_type,
        oa.entity_id,
        oa.payload,
        oa.note,
        oa.created_at,
        COALESCE(
        NULLIF(TRIM(CONCAT(e.firstname, ' ', e.lastname)), ''),
        NULLIF(TRIM(CONCAT(ec.firstname, ' ', ec.lastname)), ''),
        CONCAT('Employee #', COALESCE(oa.actor_employee_id, JSON_UNQUOTE(JSON_EXTRACT(oa.payload, '$.created_by'))))
      ) AS actor_name
      FROM order_activity oa
      LEFT JOIN employees e ON e.id = oa.actor_employee_id
      LEFT JOIN employees ec ON ec.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(oa.payload, '$.created_by')) AS UNSIGNED)
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
                  <b><?php echo h($a['actor_name'] ?? 'System'); ?></b>
                  :
                  <?php
                  $actorName = (string) ($a['actor_name'] ?? 'System');
                  $rawActivity = trim((string) ($a['note'] ?? ''));

                  if ($rawActivity === '') {
                    $rawActivity = trim((string) ($a['action'] ?? ''));
                  }

                  $activityText = preg_replace('/\s*\[created_by\s*:\s*\d+\]\s*/i', ' ', $rawActivity);
                  $activityText = trim((string) $activityText);
                  ?>
                  <span><?php echo h($activityText); ?></span>
                </div>
              <?php endwhile; ?>
            </div>

            <?php $actStmt->close(); ?>

            <button type="button" class="btn btn-xs btn-outline-secondary mt-2 btn-load-older-activity"
              data-order-id="<?php echo (int) $orderId; ?>" data-offset="30">
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
out(200, ['ok' => true, 'html' => $html]);
?>
