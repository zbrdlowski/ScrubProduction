<?php
declare(strict_types=1);

/**
 * install_status_policies_2026_08_v2.php
 * ---------------------------------------------------------
 * UPSERT install skript pre aktualizovanu sadu status policies
 * podla noveho statuses.xlsx (Plastics ma novy Info Requested
 * bucket, novy In Progress bucket, upraveny Delay bucket).
 *
 * POUZITIE: nahraj do scripts/orders/ a spusti RAZ cez browser
 * (ako admin) alebo cez CLI: php install_status_policies_2026_08_v2.php
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

$allowedStatuses = ['NEW', 'IN_PROGRESS', 'READY_TO_SHIP', 'READY_TO_INVOICE', 'INFO_REQUESTED', 'COMMUNICATION', 'PENDING', 'HOLD', 'DELAY'];

$policies = [
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
