<?php
/**
 * Safaricom Daraja STK Push callback. No staff session - Safaricom calls this
 * directly, so it must be a publicly reachable HTTPS URL matching whatever
 * you set as mpesa_callback_url in Settings (and registered with Safaricom
 * for your shortcode). During local dev, use a tunnel (ngrok etc.) - Daraja
 * will not call a localhost/private-IP URL.
 */

require_once dirname(__DIR__) . '/config/config.php';

use Gym\Core\Database;
use Gym\Core\MemberAccess;

$raw = file_get_contents('php://input');
error_log('Mpesa callback: ' . $raw);

$data = json_decode($raw, true);
$stkCallback = $data['Body']['stkCallback'] ?? null;

if (!$stkCallback) {
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Ignored - no callback body']);
    exit;
}

$checkoutRequestId = $stkCallback['CheckoutRequestID'] ?? null;
$resultCode = $stkCallback['ResultCode'] ?? null;
$resultDesc = $stkCallback['ResultDesc'] ?? '';

$transaction = $checkoutRequestId
    ? Database::fetchOne("SELECT * FROM mpesa_transactions WHERE checkout_request_id = ?", [$checkoutRequestId])
    : null;

if (!$transaction) {
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Ignored - unknown transaction']);
    exit;
}

if ((int)$resultCode === 0) {
    $items = $stkCallback['CallbackMetadata']['Item'] ?? [];
    $receiptNumber = null;
    foreach ($items as $item) {
        if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
            $receiptNumber = $item['Value'] ?? null;
        }
    }

    Database::execute(
        "UPDATE mpesa_transactions SET status = 'completed', mpesa_receipt_number = ?, result_desc = ? WHERE id = ?",
        [$receiptNumber, $resultDesc, $transaction['id']]
    );

    // Bonus re-sync for the subscription case: if renew.php's own submission
    // already ran by the time this callback lands, this is a harmless no-op.
    if ($transaction['purpose'] === 'subscription' && $transaction['reference_id']) {
        $member = Database::fetchOne("SELECT * FROM members WHERE id = ?", [$transaction['reference_id']]);
        if ($member && !empty($member['biometric_id'])) {
            MemberAccess::syncFromMembershipState($member);
        }
    }

    // purpose = 'sale': not wired up - see the M-Pesa wiring notes for what's
    // needed once the sales table schema is shared.
} else {
    Database::execute(
        "UPDATE mpesa_transactions SET status = 'failed', result_desc = ? WHERE id = ?",
        [$resultDesc, $transaction['id']]
    );
}

http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
