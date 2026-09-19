<?php
require_once dirname(__DIR__) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Mpesa;

Auth::requireAuth();

header('Content-Type: application/json');

$phone = trim($_POST['phone'] ?? '');
$amount = floatval($_POST['amount'] ?? 0);
$purpose = $_POST['purpose'] ?? '';
$referenceId = intval($_POST['reference_id'] ?? 0);
$accountReference = trim($_POST['account_reference'] ?? 'GYM');
$description = trim($_POST['description'] ?? 'Gym Payment');

if (!$phone || $amount <= 0 || !in_array($purpose, ['subscription', 'sale'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$consumerKey = Auth::getSetting('mpesa_consumer_key', '');
$consumerSecret = Auth::getSetting('mpesa_consumer_secret', '');
$shortcode = Auth::getSetting('mpesa_shortcode', '');
$passkey = Auth::getSetting('mpesa_passkey', '');

if (!$consumerKey || !$consumerSecret || !$shortcode || !$passkey) {
    echo json_encode(['success' => false, 'message' => 'M-Pesa is not configured yet - add your Daraja credentials in Settings']);
    exit;
}

$environment = Auth::getSetting('mpesa_environment', 'sandbox');
$callbackUrl = Auth::getSetting('mpesa_callback_url', rtrim(BASE_URL, '/') . '/api/mpesa-callback.php');

$mpesa = new Mpesa($consumerKey, $consumerSecret, $shortcode, $passkey, $callbackUrl, $environment === 'sandbox');

$result = $mpesa->stkPush($phone, $amount, $accountReference, $description);

if ($result['success']) {
    Database::insert(
        "INSERT INTO mpesa_transactions (checkout_request_id, merchant_request_id, phone, amount, account_reference, purpose, reference_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')",
        [$result['checkout_request_id'], $result['merchant_request_id'], $phone, $amount, $accountReference, $purpose, $referenceId]
    );
}

echo json_encode($result);
