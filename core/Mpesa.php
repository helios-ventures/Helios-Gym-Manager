<?php
/**
 * Mpesa - Safaricom Daraja STK Push (Lipa na M-Pesa Online)
 *
 * Credentials come from system_settings via Auth::getSetting() at the call
 * site, not hardcoded here - see api/mpesa-stk-push.php for how it's built.
 */

namespace Gym\Core;

class Mpesa
{
    private string $consumerKey;
    private string $consumerSecret;
    private string $shortcode;
    private string $passkey;
    private string $callbackUrl;
    private string $baseUrl;

    public function __construct(
        string $consumerKey,
        string $consumerSecret,
        string $shortcode,
        string $passkey,
        string $callbackUrl,
        bool $sandbox = true
    ) {
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->shortcode = $shortcode;
        $this->passkey = $passkey;
        $this->callbackUrl = $callbackUrl;
        $this->baseUrl = $sandbox ? 'https://sandbox.safaricom.co.ke' : 'https://api.safaricom.co.ke';
    }

    private function getAccessToken(): ?string
    {
        $ch = curl_init($this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($this->consumerKey . ':' . $this->consumerSecret),
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log('Mpesa: failed to get access token (HTTP ' . $httpCode . ') - ' . $response);
            return null;
        }

        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    /** Normalize a Kenyan number to the 2547XXXXXXXX / 2541XXXXXXXX format Daraja expects. */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        } elseif (str_starts_with($phone, '7') || str_starts_with($phone, '1')) {
            $phone = '254' . $phone;
        }
        return $phone;
    }

    /**
     * Push an STK prompt to the customer's phone.
     * $accountReference shows on the prompt (max ~12 chars) - e.g. member code.
     */
    public function stkPush(string $phone, float $amount, string $accountReference, string $description = 'Payment'): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Could not authenticate with Safaricom - check consumer key/secret in Settings'];
        }

        $phone = $this->normalizePhone($phone);
        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int)round($amount),
            'PartyA' => $phone,
            'PartyB' => $this->shortcode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $this->callbackUrl,
            'AccountReference' => substr($accountReference, 0, 12),
            'TransactionDesc' => substr($description, 0, 13),
        ];

        $ch = curl_init($this->baseUrl . '/mpesa/stkpush/v1/processrequest');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode !== 200 || empty($data['CheckoutRequestID'])) {
            return [
                'success' => false,
                'message' => $data['errorMessage'] ?? ('STK push failed (HTTP ' . $httpCode . ')'),
                'raw' => $data,
            ];
        }

        return [
            'success' => true,
            'message' => 'STK push sent - ask the customer to enter their M-Pesa PIN on their phone.',
            'checkout_request_id' => $data['CheckoutRequestID'],
            'merchant_request_id' => $data['MerchantRequestID'] ?? null,
        ];
    }

    /**
     * Poll status directly (fallback for when the callback URL isn't reachable
     * from Safaricom yet, e.g. during local dev without a tunnel).
     */
    public function queryStkStatus(string $checkoutRequestId): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Could not authenticate with Safaricom'];
        }

        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        $ch = curl_init($this->baseUrl . '/mpesa/stkpushquery/v1/query');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $resultCode = $data['ResultCode'] ?? null;

        return [
            'success' => true,
            'completed' => $resultCode !== null,
            'paid' => $resultCode === '0' || $resultCode === 0,
            'result_desc' => $data['ResultDesc'] ?? null,
            'raw' => $data,
        ];
    }
}
