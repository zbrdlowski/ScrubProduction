<?php
require_once __DIR__ . '/helpers.php';

chat_require_login();

$userId = (int)$_SESSION['user_id'];
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

function chat_online_status_meta($statusInt): array
{
    switch ((int)$statusInt) {
        case 1:
            return ['label' => 'At work', 'icon' => 'fa-briefcase', 'bg' => 'bg-success'];
        case 2:
            return ['label' => 'At home', 'icon' => 'fa-house-user', 'bg' => 'bg-danger'];
        case 3:
            return ['label' => 'Break', 'icon' => 'fa-smoking', 'bg' => 'bg-warning'];
        case 4:
            return ['label' => 'Lunch', 'icon' => 'fa-utensils', 'bg' => 'bg-info'];
        default:
            return ['label' => 'Unknown', 'icon' => 'fa-question', 'bg' => 'bg-secondary'];
    }
}

$sql = "SELECT 
        e.id,
        e.employee_id,
        e.firstname,
        e.lastname,
        e.username,
        e.photo,
        e.position_id,
        e.permission,
        e.active,
        e.online_status,
        p.description AS department_name
    FROM employees e
    LEFT JOIN position p ON p.id = e.position_id
    WHERE e.active = 'Active'
      AND e.id != ?
";

$params = [$userId];
$types = "i";

if ($search !== '') {
    $sql .= " AND (
        e.firstname LIKE ?
        OR e.lastname LIKE ?
        OR CONCAT(e.firstname, ' ', e.lastname) LIKE ?
        OR e.username LIKE ?
    )";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

$sql .= " ORDER BY e.firstname ASC, e.lastname ASC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    die("Execute failed: " . $stmt->error);
}

$result = $stmt->get_result();
$contacts = [];

while ($row = $result->fetch_assoc()) {
    $meta = chat_online_status_meta((int)$row['online_status']);

    $contacts[] = [
        'id' => (int)$row['id'],
        'employee_id' => $row['employee_id'],
        'name' => trim($row['firstname'] . ' ' . $row['lastname']),
        'firstname' => $row['firstname'],
        'lastname' => $row['lastname'],
        'username' => $row['username'] ?? '',
        'photo' => $row['photo'] ?? '',
        'department_name' => $row['department_name'] ?? '',
        'permission' => (int)$row['permission'],
        'online_status' => (int)$row['online_status'],
        'status_label' => $meta['label'],
        'status_icon' => $meta['icon'],
        'status_bg' => $meta['bg']
    ];
}

$stmt->close();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'success',
    'contacts' => $contacts
], JSON_UNESCAPED_UNICODE);
?>