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

    if (!statusDefinitionEnsureTabBarColumn($conn)) {
        return $ensured = false;
    }

    if (!$conn->query("
        CREATE TABLE IF NOT EXISTS status_definition_tab_bar_positions (
            status_definition_id INT(11) NOT NULL,
            position_id INT(11) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (status_definition_id, position_id),
            KEY idx_status_definition_tab_bar_positions_position (position_id),
            CONSTRAINT fk_status_definition_tab_bar_positions_definition
                FOREIGN KEY (status_definition_id) REFERENCES status_definitions(id) ON DELETE CASCADE,
            CONSTRAINT fk_status_definition_tab_bar_positions_position
                FOREIGN KEY (position_id) REFERENCES `position`(id) ON DELETE CASCADE
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

    $conn->query("
        INSERT IGNORE INTO status_definition_tab_bar_positions (status_definition_id, position_id)
        SELECT sd.id, p.id
        FROM status_definitions sd
        CROSS JOIN `position` p
        LEFT JOIN status_definition_tab_bar_positions existing
            ON existing.status_definition_id = sd.id
        WHERE sd.scope = 'order'
          AND sd.tab_bar = 1
          AND existing.status_definition_id IS NULL
    ");

    $conn->query("
        INSERT IGNORE INTO status_definition_tab_bar_positions (status_definition_id, position_id)
        SELECT sd.id, p.id
        FROM status_definitions sd
        JOIN `position` p
            ON p.description = CASE sd.department
                WHEN 'G' THEN 'Graphics Designer'
                WHEN 'P' THEN 'Plastics sklad'
                WHEN 'S' THEN 'Seat Covers Production'
                WHEN 'F' THEN 'Production - Fitting'
            END
        LEFT JOIN status_definition_tab_bar_positions existing
            ON existing.status_definition_id = sd.id
        WHERE sd.scope = 'item'
          AND sd.department IN ('G', 'P', 'S', 'F')
          AND sd.tab_bar = 1
          AND existing.status_definition_id IS NULL
    ");

    return $ensured = true;
}

function statusDefinitionColumnExistsFresh(mysqli $conn, string $tableName, string $columnName): bool
{
    $tableName = trim($tableName);
    $columnName = trim($columnName);
    if ($tableName === '' || $columnName === '') {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    return $exists;
}

function statusDefinitionEnsureTabBarColumn(mysqli $conn): bool
{
    if (statusDefinitionColumnExistsFresh($conn, 'status_definitions', 'tab_bar')) {
        return true;
    }

    return (bool) $conn->query("
        ALTER TABLE status_definitions
        ADD COLUMN tab_bar TINYINT(1) NOT NULL DEFAULT 1 AFTER active
    ");
}

function statusDefinitionHasColumn(mysqli $conn, string $columnName): bool
{
    static $cache = [];

    $columnName = trim($columnName);
    if ($columnName === '') {
        return false;
    }

    if (array_key_exists($columnName, $cache)) {
        return $cache[$columnName];
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'status_definitions'
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return $cache[$columnName] = false;
    }

    $stmt->bind_param('s', $columnName);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    return $cache[$columnName] = $exists;
}

function statusDefinitionHasTabBarColumn(mysqli $conn): bool
{
    return statusDefinitionHasColumn($conn, 'tab_bar');
}

function statusDefinitionFetchPositionOptions(mysqli $conn): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    $result = $conn->query("SELECT id, description FROM `position` ORDER BY id ASC");
    if (!$result instanceof mysqli_result) {
        return $cache;
    }

    while ($row = $result->fetch_assoc()) {
        $id = (int)($row['id'] ?? 0);
        $label = trim((string)($row['description'] ?? ''));
        if ($id > 0 && $label !== '') {
            $cache[$id] = $label;
        }
    }
    $result->free();

    return $cache;
}

function statusDefinitionDefaultTabBarPositionIds(mysqli $conn, string $scope, ?string $department): array
{
    $positions = statusDefinitionFetchPositionOptions($conn);
    if (!$positions) {
        return [];
    }

    if (strtolower(trim($scope)) === 'order') {
        return array_keys($positions);
    }

    $department = strtoupper(trim((string)$department));
    $departmentPositionNames = [
        'G' => ['Graphics Designer'],
        'P' => ['Plastics sklad'],
        'S' => ['Seat Covers Production'],
        'F' => ['Production - Fitting'],
    ];

    $targetNames = $departmentPositionNames[$department] ?? [];
    if (!$targetNames) {
        return [];
    }

    $ids = [];
    foreach ($positions as $positionId => $positionLabel) {
        if (in_array($positionLabel, $targetNames, true)) {
            $ids[] = (int)$positionId;
        }
    }

    return $ids;
}

function statusDefinitionNormalizeTabBarPositionIds(mysqli $conn, $rawPositionIds): array
{
    $allowedPositions = statusDefinitionFetchPositionOptions($conn);
    $rawPositionIds = is_array($rawPositionIds) ? $rawPositionIds : [$rawPositionIds];
    $positionIds = [];

    foreach ($rawPositionIds as $rawPositionId) {
        $positionId = (int)$rawPositionId;
        if ($positionId > 0 && isset($allowedPositions[$positionId])) {
            $positionIds[$positionId] = true;
        }
    }

    return array_keys($positionIds);
}

function statusDefinitionFetchTabBarPositionsByDefinition(mysqli $conn): array
{
    $positionsByDefinition = [];
    $result = $conn->query("
        SELECT status_definition_id, position_id
        FROM status_definition_tab_bar_positions
        ORDER BY status_definition_id ASC, position_id ASC
    ");
    if (!$result instanceof mysqli_result) {
        return $positionsByDefinition;
    }

    while ($row = $result->fetch_assoc()) {
        $definitionId = (int)($row['status_definition_id'] ?? 0);
        $positionId = (int)($row['position_id'] ?? 0);
        if ($definitionId > 0 && $positionId > 0) {
            $positionsByDefinition[$definitionId][] = $positionId;
        }
    }
    $result->free();

    return $positionsByDefinition;
}

function statusDefinitionSaveTabBarPositions(mysqli $conn, int $definitionId, $rawPositionIds): bool
{
    if ($definitionId <= 0) {
        return false;
    }

    $positionIds = statusDefinitionNormalizeTabBarPositionIds($conn, $rawPositionIds);

    $delete = $conn->prepare('DELETE FROM status_definition_tab_bar_positions WHERE status_definition_id = ?');
    if (!$delete) {
        return false;
    }
    $delete->bind_param('i', $definitionId);
    $ok = $delete->execute();
    $delete->close();
    if (!$ok || !$positionIds) {
        return $ok;
    }

    $insert = $conn->prepare("
        INSERT INTO status_definition_tab_bar_positions (status_definition_id, position_id)
        VALUES (?, ?)
    ");
    if (!$insert) {
        return false;
    }

    foreach ($positionIds as $positionId) {
        $insert->bind_param('ii', $definitionId, $positionId);
        if (!$insert->execute()) {
            $insert->close();
            return false;
        }
    }
    $insert->close();

    return true;
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
