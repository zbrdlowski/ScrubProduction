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
