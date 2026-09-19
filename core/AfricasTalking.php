<?php
/**
 * AfricasTalking - bulk SMS via Africa's Talking.
 * Credentials/sender ID come from system_settings via Auth::getSetting().
 */

namespace Gym\Core;

class AfricasTalking
{
    private string $username;
    private string $apiKey;
    private ?string $senderId;
    private string $baseUrl;

    public function __construct(string $username, string $apiKey, ?string $senderId = null, bool $sandbox = false)
    {
        $this->username = $username;
        $this->apiKey = $apiKey;
        $this->senderId = $senderId ?: null;
        // Africa's Talking's sandbox app uses username "sandbox" against the
        // same production API host - not a different base URL.
        $this->baseUrl = 'https://api.africastalking.com/version1/messaging';
    }

    /** Normalize to +254XXXXXXXXX. */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        } elseif (str_starts_with($phone, '7') || str_starts_with($phone, '1')) {
            $phone = '254' . $phone;
        }
        return '+' . $phone;
    }

    /**
     * @param string[] $phones
     * @return array{success:bool, message:string, recipients:array}
     *   recipients is Africa's Talking' per-number status (statusCode, status,
     *   messageId, cost) - use this to mark individual message_logs rows
     *   sent/failed accurately instead of assuming the whole batch succeeded.
     */
    public function sendBulk(array $phones, string $message): array
    {
        if (empty($phones)) {
            return ['success' => false, 'message' => 'No recipients', 'recipients' => []];
        }

        $to = implode(',', array_map([$this, 'normalizePhone'], $phones));

        $fields = [
            'username' => $this->username,
            'to' => $to,
            'message' => $message,
        ];
        if ($this->senderId) {
            $fields['from'] = $this->senderId;
        }

        $ch = curl_init($this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apiKey: ' . $this->apiKey,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => $error, 'recipients' => []];
        }

        $data = json_decode($response, true);
        $recipients = $data['SMSMessageData']['Recipients'] ?? [];

        if ($httpCode !== 201 && $httpCode !== 200) {
            return [
                'success' => false,
                'message' => $data['SMSMessageData']['Message'] ?? ('HTTP ' . $httpCode),
                'recipients' => $recipients,
            ];
        }

        return [
            'success' => true,
            'message' => $data['SMSMessageData']['Message'] ?? 'Sent',
            'recipients' => $recipients,
        ];
    }

    /** Convenience for a single recipient (e.g. the expiry-reminder cron). */
    public function sendOne(string $phone, string $message): array
    {
        return $this->sendBulk([$phone], $message);
    }
}
