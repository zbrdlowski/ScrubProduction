<?php
declare(strict_types=1);

const PC_SAVE_TRACKING_FLAGS = ['done_web', 'done_ebay', 'done_graphics_templates', 'done_seatcover_templates', 'done_products'];

function pcDecodeTracking(string $json): array
{
    $value = json_decode($json, true);
    if (!is_array($value) || !isset($value['trackid']) || filter_var($value['trackid'], FILTER_VALIDATE_INT) === false || (int)$value['trackid'] <= 0) {
        throw new InvalidArgumentException('Invalid model year update.', 400);
    }
    $result = ['trackid' => (int)$value['trackid']];
    foreach (PC_SAVE_TRACKING_FLAGS as $field) {
        if (!array_key_exists($field, $value) || !in_array($value[$field], [0, 1, '0', '1', false, true], true)) {
            throw new InvalidArgumentException('Missing or invalid update status: ' . $field, 400);
        }
        $result[$field] = (int)$value[$field];
    }
    return $result;
}

/** Save both sections atomically. Tracking is optional for models without a year update and older clients. */
function pcSaveModelDetails(PDO $pdo, array $input, int $userId): array
{
    $identity = [];
    foreach (['brand', 'model', 'rangeyear', 'modelcode'] as $field) {
        $value = $input[$field] ?? '';
        if (!is_string($value) || trim($value) === '') throw new InvalidArgumentException('Missing product identity.', 400);
        $identity[$field] = trim($value);
    }
    $metaRaw = $input['meta_json'] ?? '';
    $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : null;
    if (!is_array($meta)) throw new InvalidArgumentException('Invalid metadata.', 400);
    foreach ($meta as $fields) {
        if (!is_array($fields)) throw new InvalidArgumentException('Invalid metadata block.', 400);
        foreach ($fields as $value) {
            if (!is_scalar($value) && $value !== null) throw new InvalidArgumentException('Invalid metadata field.', 400);
        }
    }
    $metaClean = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $tracking = null;
    $original = null;
    if (array_key_exists('tracking_json', $input)) {
        if (!is_string($input['tracking_json'])) throw new InvalidArgumentException('Invalid model year update.', 400);
        $tracking = pcDecodeTracking($input['tracking_json']);
        if (array_key_exists('tracking_original_json', $input)) {
            if (!is_string($input['tracking_original_json'])) throw new InvalidArgumentException('Invalid original update status.', 400);
            $original = pcDecodeTracking($input['tracking_original_json']);
        }
    }

    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare('SELECT lineid FROM scrubdata WHERE brand=? AND model=? AND rangeyear=? AND modelcode=? LIMIT 1');
        $check->execute(array_values($identity));
        if (!$check->fetchColumn()) throw new RuntimeException('Model no longer exists. Reload the page.', 404);

        $current = null;
        if ($tracking !== null) {
            $check = $pdo->prepare('SELECT trackid, new_year, done_web, done_ebay, done_graphics_templates, done_seatcover_templates, done_products
                FROM scrub_update_tracking WHERE brand=? AND model=? AND modelcode=? ORDER BY trackid DESC LIMIT 1 FOR UPDATE');
            $check->execute([$identity['brand'], $identity['model'], $identity['modelcode']]);
            $current = $check->fetch(PDO::FETCH_ASSOC);
            if (!$current || (int)$current['trackid'] !== $tracking['trackid']) {
                throw new RuntimeException('The model year update changed. Reload the page before saving.', 409);
            }
            if ($original !== null) {
                foreach (array_merge(['trackid'], PC_SAVE_TRACKING_FLAGS) as $field) {
                    if ((int)$current[$field] !== $original[$field]) {
                        throw new RuntimeException('Update tracking was changed by another edit. Cancel and reload the page before saving.', 409);
                    }
                }
            }
        }

        $stmt = $pdo->prepare('INSERT INTO scrubdata_meta (brand, model, rangeyear, modelcode, meta_json, updated_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE meta_json=VALUES(meta_json), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP');
        $stmt->execute(array_merge(array_values($identity), [$metaClean, $userId]));

        if ($tracking !== null) {
            $stmt = $pdo->prepare('UPDATE scrub_update_tracking SET done_web=?, done_ebay=?, done_graphics_templates=?, done_seatcover_templates=?, done_products=? WHERE trackid=?');
            $values = [];
            foreach (PC_SAVE_TRACKING_FLAGS as $field) $values[] = $tracking[$field];
            $values[] = $tracking['trackid'];
            $stmt->execute($values);
            $current = array_merge($current, $tracking);
            foreach ($current as $field => $value) $current[$field] = (int)$value;
        }
        $pdo->commit();
        return ['ok' => true, 'modelcode' => $identity['modelcode'], 'tracking' => $current];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
