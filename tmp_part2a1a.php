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
