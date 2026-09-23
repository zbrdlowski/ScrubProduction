<?php
declare(strict_types=1);

/**
 * install_status_policies_2026_09_v6.php
 * ---------------------------------------------------------
 * Finalna zjednotena verzia:
 *  - Vsetkych 15 nepraznych podmnozin {G,S,P,F} pre Ready aj New
 *    tier (opravuje dieru s chybajucimi kombinaciami ako G+P).
 *  - DRAFT_✗ presunute z 'In Progress' do 'New' bucketu pre
 *    Graphics (rovnocenne s RTP_✗ - obe su 'este nezacate'.
 *  - Draft-before-production grafika NEOBCHADZA Plastics gate -
 *    zablokuje sa na PLASTICS_IN_STOCK rovnako ako ostatne G/S/F
 *    polozky, kym sa plasty nepotvrdia skladom (viz upraveny
 *    orders_plastics_gate_helpers.php).
 *  - PENDING vyhodeny z allowed_statuses (unpaid objednavka ma
 *    ostat viditelne Pending aj ked uz ma nastaveny plastics gate).
 *  - Info Required / Info Requested rozdelene (_REQUIRED vs
 *    _REQUESTED kody), Plastics Check Stock tier, Delay, In Progress
 *    bez zmeny oproti v5.
 *
 * POUZITIE: nahraj do scripts/orders/ a spusti RAZ cez browser
 * (ako admin) alebo cez CLI: php install_status_policies_2026_09_v6.php
 * Bezpecne spustit opakovane - vsetko je upsert/skip-if-exists.
 * Tento skript NAHRADZA v3, v4, v5 aj install_status_policies_2026_09.php
 * - staci spustit len tento.
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


// -----------------------------------------------------------------
// 1) NOVE STATUS DEFINITIONS (skip-if-exists, DRAFT_✗ uz zvycajne
//    existuje z povodneho Excelu, ale pre istotu je tu tiez)
// -----------------------------------------------------------------
$newStatusDefinitions = [
    ['scope' => 'item', 'department' => 'P', 'code' => 'CHECK_STOCK', 'label' => 'Check Stock', 'color' => '#6c757d', 'sort_order' => 5],
    ['scope' => 'item', 'department' => 'G', 'code' => 'DRAFT_✗', 'label' => 'Draft ✗', 'color' => '#ff0000', 'sort_order' => 52],
    ['scope' => 'item', 'department' => 'G', 'code' => 'PLASTICS_IN_STOCK', 'label' => 'Plastics in stock?', 'color' => '#00ffe1', 'sort_order' => 120],
    ['scope' => 'item', 'department' => 'S', 'code' => 'PLASTICS_IN_STOCK', 'label' => 'Plastics in stock?', 'color' => '#00ffe1', 'sort_order' => 100],
    ['scope' => 'item', 'department' => 'F', 'code' => 'PLASTICS_IN_STOCK', 'label' => 'Plastics in stock?', 'color' => '#6c757d', 'sort_order' => 5],
    ['scope' => 'order', 'department' => null, 'code' => 'PLASTICS_IN_STOCK', 'label' => 'Plastics in stock?', 'color' => '#6c757d', 'sort_order' => 15],
    ['scope' => 'order', 'department' => null, 'code' => 'INFO_REQUIRED', 'label' => 'Info required', 'color' => '#dc3545', 'sort_order' => 25],
];

echo "--- Status definitions ---\n";
foreach ($newStatusDefinitions as $def) {
    $checkSql = "SELECT id FROM status_definitions WHERE scope = ? AND code = ? AND " .
        ($def['department'] === null ? "(department IS NULL OR department = '')" : "department = ?");
    $checkStmt = $conn->prepare($checkSql);
    if ($def['department'] === null) {
        $checkStmt->bind_param('ss', $def['scope'], $def['code']);
    } else {
        $checkStmt->bind_param('sss', $def['scope'], $def['code'], $def['department']);
    }
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($exists) {
        echo "SKIP (uz existuje): {$def['scope']}/{$def['department']}/{$def['code']}\n";
        continue;
    }

    $active = 1;
    $workflowState = 'IN_PROGRESS';
    $isFinal = 0;
    $isWaiting = 0;
    $insStmt = $conn->prepare("
        INSERT INTO status_definitions (scope, department, code, label, color, sort_order, workflow_state, is_final, is_waiting, active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insStmt->bind_param('sssssisiii', $def['scope'], $def['department'], $def['code'], $def['label'], $def['color'], $def['sort_order'], $workflowState, $isFinal, $isWaiting, $active);
    $insStmt->execute();
    $insStmt->close();
    echo "CREATED: {$def['scope']}/{$def['department']}/{$def['code']}\n";
}


// -----------------------------------------------------------------
// 2) STATUS WORKFLOW POLICIES (upsert)
// -----------------------------------------------------------------

$allowedStatuses = ['NEW', 'IN_PROGRESS', 'READY_TO_SHIP', 'READY_TO_INVOICE', 'INFO_REQUIRED', 'INFO_REQUESTED', 'COMMUNICATION', 'HOLD', 'DELAY', 'PLASTICS_IN_STOCK'];

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
        'name' => 'Graphics - Info Required',
        'priority' => 10,
        'result' => 'INFO_REQUIRED',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['MODEL_REQUIRED', 'IMG_REQUIRED', 'N_N_REQUIRED']],
        ],
    ],
    [
        'name' => 'Seat Cover - Info Required',
        'priority' => 20,
        'result' => 'INFO_REQUIRED',
        'stop' => 1,
        'conditions' => [
            ['S', 'status', 'IN', ['MODEL_REQUIRED', 'IMG_REQUIRED']],
        ],
    ],
    [
        'name' => 'Plastics - Info Required',
        'priority' => 30,
        'result' => 'INFO_REQUIRED',
        'stop' => 1,
        'conditions' => [
            ['P', 'status', 'IN', ['MODEL_REQUIRED', 'IMG_REQUIRED']],
        ],
    ],
    [
        'name' => 'Graphics - Info Requested',
        'priority' => 40,
        'result' => 'INFO_REQUESTED',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['MODEL_REQUESTED', 'IMG_REQUESTED', 'N_N_REQUESTED']],
        ],
    ],
    [
        'name' => 'Seat Cover - Info Requested',
        'priority' => 50,
        'result' => 'INFO_REQUESTED',
        'stop' => 1,
        'conditions' => [
            ['S', 'status', 'IN', ['MODEL_REQUESTED', 'IMG_REQUESTED']],
        ],
    ],
    [
        'name' => 'Plastics - Info Requested',
        'priority' => 60,
        'result' => 'INFO_REQUESTED',
        'stop' => 1,
        'conditions' => [
            ['P', 'status', 'IN', ['MODEL_REQUESTED', 'IMG_REQUESTED']],
        ],
    ],
    [
        'name' => 'Graphics - Communication',
        'priority' => 70,
        'result' => 'COMMUNICATION',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['COMMUNICATION']],
        ],
    ],
    [
        'name' => 'Seat Cover - Communication',
        'priority' => 80,
        'result' => 'COMMUNICATION',
        'stop' => 1,
        'conditions' => [
            ['S', 'status', 'IN', ['COMMUNICATION']],
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
        'name' => 'Ready (S)',
        'priority' => 110,
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
        'name' => 'Ready (F)',
        'priority' => 130,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'status', 'IN', ['READY']],
        ],
    ],
    [
        'name' => 'Ready (G+S)',
        'priority' => 140,
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
        'name' => 'Ready (G+P)',
        'priority' => 150,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['READY']],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'status', 'IN', ['READY']],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'Ready (G+F)',
        'priority' => 160,
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
        'name' => 'Ready (S+P)',
        'priority' => 170,
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
        'name' => 'Ready (S+F)',
        'priority' => 180,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'status', 'IN', ['READY']],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'status', 'IN', ['READY']],
        ],
    ],
    [
        'name' => 'Ready (P+F)',
        'priority' => 190,
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
        'name' => 'Ready (G+S+P)',
        'priority' => 200,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['READY']],
            ['S', 'status', 'IN', ['READY']],
            ['P', 'status', 'IN', ['READY']],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'Ready (G+S+F)',
        'priority' => 210,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['READY']],
            ['S', 'status', 'IN', ['READY']],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'status', 'IN', ['READY']],
        ],
    ],
    [
        'name' => 'Ready (G+P+F)',
        'priority' => 220,
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
        'name' => 'Ready (S+P+F)',
        'priority' => 230,
        'result' => 'READY_TO_INVOICE',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'status', 'IN', ['READY']],
            ['P', 'status', 'IN', ['READY']],
            ['F', 'status', 'IN', ['READY']],
        ],
    ],
    [
        'name' => 'Ready (G+S+P+F)',
        'priority' => 240,
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
        'priority' => 250,
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
            ['G', 'status', 'IN', ['RTP_AD_CHANGES', 'RTP_READY', 'RIP', 'PRINTED', 'CUT', 'PRODUCED', 'DRAFT_AD_CHANGES', 'DRAFT_READY', 'DRAFT_SENT', 'HO_RIP', 'REPRINT', 'BARTOS_PRODUCTION']],
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
    [
        'name' => 'New (G)',
        'priority' => 900,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['RTP_✗', 'DRAFT_✗', 'SPOKE_COATS_✗']],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'New (S)',
        'priority' => 910,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'status', 'IN', ['SEW_✗']],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'New (P)',
        'priority' => 920,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'status', 'IN', ['PK_✗']],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'New (F)',
        'priority' => 930,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'status', 'IN', ['FIT_✗']],
        ],
    ],
    [
        'name' => 'New (G+S)',
        'priority' => 940,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['RTP_✗', 'DRAFT_✗', 'SPOKE_COATS_✗']],
            ['S', 'status', 'IN', ['SEW_✗']],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'New (G+P)',
        'priority' => 950,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['RTP_✗', 'DRAFT_✗', 'SPOKE_COATS_✗']],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'status', 'IN', ['PK_✗']],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'New (G+F)',
        'priority' => 960,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['RTP_✗', 'DRAFT_✗', 'SPOKE_COATS_✗']],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'status', 'IN', ['FIT_✗']],
        ],
    ],
    [
        'name' => 'New (S+P)',
        'priority' => 970,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'status', 'IN', ['SEW_✗']],
            ['P', 'status', 'IN', ['PK_✗']],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'New (S+F)',
        'priority' => 980,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'status', 'IN', ['SEW_✗']],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'status', 'IN', ['FIT_✗']],
        ],
    ],
    [
        'name' => 'New (P+F)',
        'priority' => 990,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'status', 'IN', ['PK_✗']],
            ['F', 'status', 'IN', ['FIT_✗']],
        ],
    ],
    [
        'name' => 'New (G+S+P)',
        'priority' => 1000,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['RTP_✗', 'DRAFT_✗', 'SPOKE_COATS_✗']],
            ['S', 'status', 'IN', ['SEW_✗']],
            ['P', 'status', 'IN', ['PK_✗']],
            ['F', 'presence', 'ABSENT', null],
        ],
    ],
    [
        'name' => 'New (G+S+F)',
        'priority' => 1010,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['RTP_✗', 'DRAFT_✗', 'SPOKE_COATS_✗']],
            ['S', 'status', 'IN', ['SEW_✗']],
            ['P', 'presence', 'ABSENT', null],
            ['F', 'status', 'IN', ['FIT_✗']],
        ],
    ],
    [
        'name' => 'New (G+P+F)',
        'priority' => 1020,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['RTP_✗', 'DRAFT_✗', 'SPOKE_COATS_✗']],
            ['S', 'presence', 'ABSENT', null],
            ['P', 'status', 'IN', ['PK_✗']],
            ['F', 'status', 'IN', ['FIT_✗']],
        ],
    ],
    [
        'name' => 'New (S+P+F)',
        'priority' => 1030,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'presence', 'ABSENT', null],
            ['S', 'status', 'IN', ['SEW_✗']],
            ['P', 'status', 'IN', ['PK_✗']],
            ['F', 'status', 'IN', ['FIT_✗']],
        ],
    ],
    [
        'name' => 'New (G+S+P+F)',
        'priority' => 1040,
        'result' => 'NEW',
        'stop' => 1,
        'conditions' => [
            ['G', 'status', 'IN', ['RTP_✗', 'DRAFT_✗', 'SPOKE_COATS_✗']],
            ['S', 'status', 'IN', ['SEW_✗']],
            ['P', 'status', 'IN', ['PK_✗']],
            ['F', 'status', 'IN', ['FIT_✗']],
        ],
    ],
];


echo "\n--- Status Workflow Policies ---\n";
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
    $description = 'Auto-generated v6 (' . date('Y-m-d') . ') - 15 kombinacii + DRAFT_✗ v New';
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