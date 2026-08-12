<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/orders/department_config.php';

/**
 * Installs the additive status-definition target schema used by Controls.
 * Existing item statuses are migrated to ALL, preserving the old behaviour.
 */
function statusDefinitionEnsureExtensions(mysqli $conn): bool
{
    static $ensured = null;
    if ($ensured !== null) {
        return $ensured;
    }

    if (!$conn->query("
        CREATE TABLE IF NOT EXISTS status_definition_targets (
            status_definition_id INT(11) NOT NULL,
            target_type VARCHAR(20) NOT NULL,
            subcategory_code VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (status_definition_id, target_type, subcategory_code),
            KEY idx_status_definition_targets_lookup (target_type, subcategory_code),
            CONSTRAINT fk_status_definition_targets_definition
                FOREIGN KEY (status_definition_id) REFERENCES status_definitions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ")) {
        return $ensured = false;
    }

    // A missing target means legacy ALL at read time. Persist ALL as well so the
    // relationship is explicit for rows that already exist at migration time.
    $conn->query("
        INSERT IGNORE INTO status_definition_targets (status_definition_id, target_type, subcategory_code)
        SELECT sd.id, 'ALL', ''
        FROM status_definitions sd
        LEFT JOIN status_definition_targets sdt ON sdt.status_definition_id = sd.id
        WHERE sd.scope = 'item' AND sdt.status_definition_id IS NULL
    ");

    return $ensured = true;
}

function statusDefinitionAllowedTargetKeys(?string $department): array
{
    $department = strtoupper(trim((string) $department));
    if ($department !== 'G') {
        return ['ALL' => 'All department items'];
    }

    $targets = [
        'ALL' => 'All Graphics',
        'MAIN' => 'Main Graphics',
    ];
    foreach (GRAPHICS_SUBCAT_LABELS as $code => $label) {
        $targets['SUBCATEGORY:' . strtoupper((string) $code)] = (string) $label;
    }
    return $targets;
}

function statusDefinitionNormalizeTargetKeys($rawTargets, ?string $department): array
{
    $allowed = statusDefinitionAllowedTargetKeys($department);
    $rawTargets = is_array($rawTargets) ? $rawTargets : [$rawTargets];
    $targets = [];
    foreach ($rawTargets as $rawTarget) {
        $target = strtoupper(trim((string) $rawTarget));
        if (isset($allowed[$target])) {
            $targets[$target] = true;
        }
    }

    if (isset($targets['ALL']) || !$targets) {
        return ['ALL'];
    }
    return array_keys($targets);
}

function statusDefinitionSaveTargets(mysqli $conn, int $definitionId, string $scope, ?string $department, $rawTargets): bool
{
    if ($definitionId <= 0) {
        return false;
    }

    $targets = strtolower(trim($scope)) === 'item'
        ? statusDefinitionNormalizeTargetKeys($rawTargets, $department)
        : [];

    $delete = $conn->prepare('DELETE FROM status_definition_targets WHERE status_definition_id = ?');
    if (!$delete) {
        return false;
    }
    $delete->bind_param('i', $definitionId);
    $ok = $delete->execute();
    $delete->close();
    if (!$ok || !$targets) {
        return $ok;
    }

    $insert = $conn->prepare("
        INSERT INTO status_definition_targets (status_definition_id, target_type, subcategory_code)
        VALUES (?, ?, ?)
    ");
    if (!$insert) {
        return false;
    }

    foreach ($targets as $target) {
        $targetType = $target;
        $subcategory = '';
        if (strpos($target, 'SUBCATEGORY:') === 0) {
            $targetType = 'SUBCATEGORY';
            $subcategory = substr($target, strlen('SUBCATEGORY:'));
        }
        $insert->bind_param('iss', $definitionId, $targetType, $subcategory);
        if (!$insert->execute()) {
            $insert->close();
            return false;
        }
    }
    $insert->close();
    return true;
}
