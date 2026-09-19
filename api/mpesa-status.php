<?php
require_once dirname(__DIR__) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;

Auth::requireAuth();
header('Content-Type: application/json');

$checkoutRequestId = $_GET['checkout_request_id'] ?? '';
$transaction = Database::fetchOne(
    "SELECT * FROM mpesa_transactions WHERE checkout_request_id = ?",
    [$checkoutRequestId]
);

if (!$transaction) {
    echo json_encode(['status' => 'unknown']);
    exit;
}

echo json_encode([
    'status' => $transaction['status'],
    'mpesa_receipt_number' => $transaction['mpesa_receipt_number'],
]);
