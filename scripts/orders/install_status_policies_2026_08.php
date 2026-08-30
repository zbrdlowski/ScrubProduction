<?php
declare(strict_types=1);

/**
 * install_status_policies_2026_08_v3.php
 * ---------------------------------------------------------
 * UPSERT install skript pre aktualizovanu sadu status policies
 * podla noveho statuses.xlsx, vratane Plastics Check Stock gate.
 *
 * POUZITIE: nahraj do scripts/orders/ a spusti RAZ cez browser
 * (ako admin) alebo cez CLI: php install_status_policies_2026_08.php
 *
 * UPSERT SPRAVANIE: ak policy s rovnakym nazvom uz existuje (napr.
 * z predchadzajuceho installu), skript ju NAJPRV KOMPLETNE ZMAZE
 * (aj s podmienkami a allowed statuses) a znova vytvori s
 * aktualnymi datami. NIC TREBA MAZAT RUCNE VOPRED.
 * Po dokonceni automaticky prepocita vsetky (neuzavrete) objednavky.
 */

session_start();
$base = dirname(__DIR__, 2);
require_once $base . '/includes/conn.php';
require_once $base . '/includes/orders_status_helpers.php';
require_once $base . '/includes/orders_workflow_helpers.php';

if (php_sapi_name() !== 'cli') {
    if (empty($_SESSION['user_id'])) {
        http_response_code(403);
        exit('Unauthorized - prihlas sa najprv do administracie.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$requiredStatusDefinitions = [
    ['item', 'P', 'CHECK_STOCK', 'Check Stock', '#6c757d', 5],
    ['item', 'G', 'PLASTICS_IN_STOCK', 'Plastics in stock?', '#00ffe1', 120],
    ['item', 'S', 'PLASTICS_IN_STOCK', 'Plastics in stock?', '#00ffe1', 100],
    ['item', 'F', 'PLASTICS_IN_STOCK', 'Plastics in stock?', '#6c757d', 5],
    ['order', null, 'PLASTICS_IN_STOCK', 'Plastics in stock?', '#6c757d', 15],
];

foreach ($requiredStatusDefinitions as $definition) {
    [$scope, $department, $code, $label, $color, $sortOrder] = $definition;
    $checkDefinition = $conn->prepare("\n        SELECT id\n        FROM status_definitions\n        WHERE scope = ?\n          AND department <=> ?\n          AND code = ?\n        LIMIT 1\n    ");
    $checkDefinition->bind_param('sss', $scope, $department, $code);
    $checkDefinition->execute();
    $existingDefinition = $checkDefinition->get_result()->fetch_assoc();
    $checkDefinition->close();

    if ($existingDefinition) {
        continue;
    }

    $workflowState = 'IN_PROGRESS';
    $isFinal = 0;
    $isWaiting = 0;
    $active = 1;
    $insertDefinition = $conn->prepare("\n        INSERT INTO status_definitions\n            (scope, department, code, label, color, sort_order, workflow_state, is_final, is_waiting, active)\n        VALUES\n            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n    ");
    $insertDefinition->bind_param(
        'sssssisiii',
        $scope,
        $department,
        $code,
        $label,
        $color,
        $sortOrder,
        $workflowState,
        $isFinal,
        $isWaiting,
        $active
    );
    $insertDefinition->execute();
    $insertDefinition->close();
}

// PENDING is deliberately excluded: an unpaid order must remain visibly
// pending even though its item-level plastics gate is already initialized.
$allowedStatuses = ['NEW', 'IN_PROGRESS', 'READY_TO_SHIP', 'READY_TO_INVOICE', 'INFO_REQUESTED', 'COMMUNICATION', 'HOLD', 'DELAY', 'PLASTICS_IN_STOCK'];

$policies = [
    [
        'name' => 'Plastics - Check Stock',
        'priority' => 5,
        'result' => 'PLASTICS_IN_STOCK',
        'stop' => 1,
        'conditions' => [
            ['P', 'status', 'IN', ['CHECK_STOCK']],
        ],
    ],
    [
        'name' => 'Graphics - Info Requested',
        'priority' => 10,
        'result' => 'INFO_REQUESTED',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['MODEL_REQUIRED', 'MODEL_REQUESTED', 'IMG_REQUIRED', 'IMG_REQUESTED', 'N_N_REQUIRED', 'N_N_REQUESTED']],
        ],
    ],
    [
        'name' => 'Graphics - Communication',
        'priority' => 20,
        'result' => 'COMMUNICATION',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['COMMUNICATION']],
        ],
    ],
    [
        'name' => 'Seat Cover - Info Requested',
        'priority' => 30,
        'result' => 'INFO_REQUESTED',
        'stop' => 1,
        'conditions' => [
            ['S', 'status', 'IN', ['MODEL_REQUIRED', 'MODEL_REQUESTED', 'IMG_REQUIRED', 'IMG_REQUESTED']],
        ],
    ],
    [
        'name' => 'Seat Cover - Communication',
        'priority' => 40,
        'result' => 'COMMUNICATION',
        'stop' => 1,
        'conditions' => [
            ['S', 'status', 'IN', ['COMMUNICATION']],
        ],
    ],
    [
        'name' => 'Plastics - Info Requested',
        'priority' => 50,
        'result' => 'INFO_REQUESTED',
        'stop' => 1,
        'conditions' => [
            ['P', 'status', 'IN', ['MODEL_REQUIRED', 'MODEL_REQUESTED', 'IMG_REQUIRED', 'IMG_REQUESTED']],
        ],
    ],
    [
        'name' => 'Ready (G)',
        'priority' => 100,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['READY']],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'Ready (G+F)',
        'priority' => 110,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['READY']],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'status', 'IN', ['READY']],
        ],
    ],
    [
        'name' => 'Ready (P)',
        'priority' => 120,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'status', 'IN', ['READY']],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'Ready (P+S)',
        'priority' => 130,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'status', 'IN', ['READY']],
            ['P', 'status', 'IN', ['READY']],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'Ready (P+F)',
        'priority' => 140,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'status', 'IN', ['READY']],
            ['F', 'status', 'IN', ['READY']],
        ],
    ],
    [
        'name' => 'Ready (S)',
        'priority' => 150,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'status', 'IN', ['READY']],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'Ready (G+S)',
        'priority' => 160,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['READY']],
            ['S', 'status', 'IN', ['READY']],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'Ready (G+P+F)',
        'priority' => 170,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['READY']],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'status', 'IN', ['READY']],
            ['F', 'status', 'IN', ['READY']],
        ],
    ],
    [
        'name' => 'Ready (G+P+F+S)',
        'priority' => 180,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['READY']],
            ['S', 'status', 'IN', ['READY']],
            ['P', 'status', 'IN', ['READY']],
            ['F', 'status', 'IN', ['READY']],
        ],
    ],
    [
        'name' => 'Plastics - Delay',
        'priority' => 200,
        'result' => 'DELAY',
        'stop' => 1,
        'conditions' => [
            ['P', 'status', 'IN', ['OUT_OF_STOCK_NOT_ORDERED', 'OUT_OF_STOCK_ORDERED', 'OUT_OF_STOCK_ORDERED_COMM']],
        ],
    ],
    [
        'name' => 'Graphics - In Progress',
        'priority' => 300,
        'result' => 'IN_PROGRESS',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['RTP_AD_CHANGES', 'RTP_READY', 'RIP', 'PRINTED', 'CUT', 'PRODUCED', 'DRAFT_✗', 'DRAFT_AD_CHANGES', 'DRAFT_READY', 'DRAFT_SENT', 'HO_RIP', 'REPRINT', 'BARTOS_PRODUCTION']],
        ],
    ],
    [
        'name' => 'Seat Cover - In Progress',
        'priority' => 310,
        'result' => 'IN_PROGRESS',
        'stop' => 1,
        'conditions' => [
            ['S', 'status', 'IN', ['STARTED', 'PRODUCED', 'DRAFT_✗', 'DRAFT_AD_CHANGES', 'DRAFT_READY', 'DRAFT_SENT']],
        ],
    ],
    [
        'name' => 'Plastics - In Progress',
        'priority' => 320,
        'result' => 'IN_PROGRESS',
        'stop' => 1,
        'conditions' => [
            ['P', 'status', 'IN', ['SCAN_OUT', 'PREORDER_NOT_ORDERED', 'PREORDER_ORDERED', 'ABO_NOT_ORDERED', 'ABO_ORDERED', 'ON_THE_WAY']],
        ],
    ],
    [
        'name' => 'Fitting - In Progress',
        'priority' => 330,
        'result' => 'IN_PROGRESS',
        'stop' => 1,
        'conditions' => [
            ['F', 'status', 'IN', ['STARTED', 'CHECK_24', 'PHOTO', 'REPRINT']],
        ],
    ],
];

$newOrderCombinations = [
    ['G', 900],
    ['GF', 910],
    ['P', 920],
    ['PS', 930],
    ['PF', 940],
    ['S', 950],
    ['GS', 960],
    ['GPF', 970],
    ['GPFS', 980],
];
$newDefaults = [
    'G' => ['RTP_✗', 'SPOKE_COATS_✗'],
    'S' => ['SEW_✗'],
    'P' => ['PK_✗'],
    'F' => ['FIT_✗'],
];

foreach ($newOrderCombinations as [$combination, $priority]) {
    $conditions = [];
    foreach (['G', 'S', 'P', 'F'] as $department) {
        if (strpos($combination, $department) !== false) {
            $conditions[] = [$department, 'status', 'IN', $newDefaults[$department]];
        } else {
            $conditions[] = [$department, 'presence', 'ABSENT', null];
        }
    }

    $policies[] = [
        'name' => 'New (' . implode('+', str_split($combination)) . ')',
        'priority' => $priority,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => $conditions,
    ];
}


$created = 0;
$replaced = 0;

foreach ($policies as $p) {
    $checkStmt = $conn->prepare("SELECT id FROM status_workflow_rules WHERE name = ? LIMIT 1");
    $checkStmt->bind_param('s', $p['name']);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    $wasReplaced = false;
    if ($existing) {
        $oldRuleId = (int) $existing['id'];

        $delAllowed = $conn->prepare("DELETE FROM status_workflow_rule_allowed_order_statuses WHERE rule_id = ?");
        $delAllowed->bind_param('i', $oldRuleId);
        $delAllowed->execute();
        $delAllowed->close();

        $delConditions = $conn->prepare("DELETE FROM status_workflow_rule_conditions WHERE rule_id = ?");
        $delConditions->bind_param('i', $oldRuleId);
        $delConditions->execute();
        $delConditions->close();

        $delRule = $conn->prepare("DELETE FROM status_workflow_rules WHERE id = ?");
        $delRule->bind_param('i', $oldRuleId);
        $delRule->execute();
        $delRule->close();

        $wasReplaced = true;
    }

    $active = 1;
    $stmt = $conn->prepare("
        INSERT INTO status_workflow_rules (name, description, result_order_status_code, priority, active, stop_on_match)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $description = 'Auto-generated z statuses.xlsx (' . date('Y-m-d') . ')';
    $stmt->bind_param('sssiii', $p['name'], $description, $p['result'], $p['priority'], $active, $p['stop']);
    $stmt->execute();
    $ruleId = $stmt->insert_id;
    $stmt->close();

    $sortOrder = 10;
    foreach ($p['conditions'] as $cond) {
        [$department, $conditionType, $operator, $codes] = $cond;
        $statusCode = $codes === null ? null : implode(',', $codes);

        $condStmt = $conn->prepare("
            INSERT INTO status_workflow_rule_conditions
            (rule_id, department, condition_type, operator, status_code, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $condStmt->bind_param('issssi', $ruleId, $department, $conditionType, $operator, $statusCode, $sortOrder);
        $condStmt->execute();
        $condStmt->close();
        $sortOrder += 10;
    }

    foreach ($allowedStatuses as $statusCode) {
        $allowStmt = $conn->prepare("
            INSERT IGNORE INTO status_workflow_rule_allowed_order_statuses (rule_id, order_status_code)
            VALUES (?, ?)
        ");
        $allowStmt->bind_param('is', $ruleId, $statusCode);
        $allowStmt->execute();
        $allowStmt->close();
    }

    if ($wasReplaced) {
        echo "REPLACED (id={$ruleId}): {$p['name']}\n";
        $replaced++;
    } else {
        echo "CREATED (id={$ruleId}): {$p['name']}\n";
        $created++;
    }
}

echo "\n--- Hotovo: {$created} novych, {$replaced} nahradenych ---\n";

echo "\nPrepocitavam vsetky objednavky...\n";
$orderIds = [];
$res = $conn->query("SELECT id FROM orders WHERE status NOT IN ('SHIPPED','CANCELLED','DELIVERED')");
while ($row = $res->fetch_assoc()) {
    $orderIds[] = (int)$row['id'];
}
$recalculated = 0;
foreach ($orderIds as $oid) {
    recalculateOrderWorkflow($conn, $oid);
    $recalculated++;
}
echo "Prepocitanych objednavok: {$recalculated}\n";
