<?php
require_once dirname(__DIR__) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;

Auth::requireAuth();
header('Content-Type: application/json');

// Seed mode: just report the current max id so the browser doesn't toast the
// entire attendance history on first load.
if (isset($_GET['seed'])) {
    $row = Database::fetchOne("SELECT MAX(id) as max_id FROM attendance_logs");
    echo json_encode(['max_id' => (int)($row['max_id'] ?? 0)]);
    exit;
}

$sinceId = intval($_GET['since_id'] ?? 0);

$events = Database::fetchAll(
    "SELECT al.id, al.check_in, m.first_name, m.last_name, m.member_code
     FROM attendance_logs al
     JOIN members m ON m.id = al.member_id
     WHERE al.id > ?
     ORDER BY al.id ASC
     LIMIT 20",
    [$sinceId]
);

echo json_encode(['events' => $events]);
