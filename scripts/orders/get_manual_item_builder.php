<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/conn.php';
require_once __DIR__ . '/manual_item_builder_helper.php';

function out_manual_builder(array $payload): void
{
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ((int) ($_SESSION['permission'] ?? 0) < 300) {
  out_manual_builder(['ok' => false, 'error' => 'No permission']);
}

$type = strtoupper(trim((string) ($_POST['item_type_code'] ?? '')));
if ($type === '') {
  out_manual_builder(['ok' => true, 'html' => '']);
}

$definitions = manualItemFieldDefinitions($conn, $type);
if (!$definitions) {
  out_manual_builder([
    'ok' => true,
    'html' => '<div class="small text-muted">No product specification fields configured for ' . htmlspecialchars(manualItemTypeLabel($type), ENT_QUOTES, 'UTF-8') . '.</div>',
  ]);
}

$mainFields = [];
$textFields = [];
foreach ($definitions as $definition) {
  $sourceKey = trim((string) ($definition['source_key'] ?? ''));
  $fieldType = trim((string) ($definition['field_type'] ?? 'dropdown'));

  if ($fieldType === 'text' || in_array($sourceKey, ['name', 'number', 'note', 'my-item-note', 'buyer-note'], true)) {
    $textFields[] = $definition;
  } else {
    $mainFields[] = $definition;
  }
}

ob_start();
?>
<div class="manual-item-builder-panel mt-2">
  <div class="small text-muted mb-2">
    Default fields for <b><?= htmlspecialchars(manualItemTypeLabel($type), ENT_QUOTES, 'UTF-8') ?></b>
  </div>

  <?php if (!empty($mainFields)): ?>
    <div class="g-options-bar manual-item-spec-row">
      <?php foreach ($mainFields as $definition): ?>
        <label class="product-spec-label">
          <span class="product-spec-label-title"><?= htmlspecialchars(manualItemBuilderSpecLabel($type, $definition), ENT_QUOTES, 'UTF-8') ?></span>
          <?= manualItemRenderSpecFieldInput($conn, $definition) ?>
        </label>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($textFields)): ?>
    <div class="g-options-bar manual-item-spec-row mt-2">
      <?php foreach ($textFields as $definition): ?>
        <label class="product-spec-label">
          <span class="product-spec-label-title"><?= htmlspecialchars(manualItemBuilderSpecLabel($type, $definition), ENT_QUOTES, 'UTF-8') ?></span>
          <?= manualItemRenderSpecFieldInput($conn, $definition) ?>
        </label>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php

out_manual_builder([
  'ok' => true,
  'html' => (string) ob_get_clean(),
]);
