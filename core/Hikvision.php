<?php
/**
 * Hikvision ISAPI Wrapper
 *
 * Mirrors the public interface of Gym\Core\ZKTeco so both device types can be
 * driven the same way from the rest of the app (api/*-user.php, devices.php, etc).
 *
 * Access control model:
 *   - A member's employeeNo == members.biometric_id (same field ZKTeco uses).
 *   - Instead of ZKTeco's "timezone" trick, Hikvision access is toggled via the
 *     UserInfo.Valid.enable flag. When false, the device instantly blocks that
 *     employeeNo at the door regardless of door-right plan or credentials.
 *   - The user record itself is never deleted on disable, so re-enabling is instant.
 */

namespace Gym\Core;

class Hikvision
{
    private string $ip;
    private int $port;
    private string $username;
    private string $password;

    public function __construct(string $ip, string $username, string $password, int $port = 80)
    {
        $this->ip = $ip;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    private function baseUrl(): string
    {
        return 'http://' . $this->ip . ($this->port && $this->port !== 80 ? ':' . $this->port : '');
    }

    /**
     * Low level ISAPI request with digest auth. Returns a normalized result array
     * so callers never have to deal with cURL directly.
     */
    private function request(string $method, string $path, ?array $payload = null, int $timeout = 10): array
    {
        $url = $this->baseUrl() . $path;
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $headers = ['Content-Type: application/json; charset=UTF-8'];

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Length: ' . strlen($json);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("Hikvision: cURL error calling {$method} {$path} - {$curlError}");
            return ['success' => false, 'http_code' => 0, 'message' => $curlError, 'raw' => null];
        }

        $decoded = json_decode($response, true);

        return [
            'success'   => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'message'   => $decoded['statusString'] ?? $decoded['errorMsg'] ?? null,
            'raw'       => $decoded ?? $response,
        ];
    }

    /**
     * Basic reachability + credential check.
     */
    public function testConnection(): array
    {
        $result = $this->request('GET', '/ISAPI/System/deviceInfo?format=json');
        return [
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Connection successful'
                : ('Connection failed: ' . ($result['message'] ?? ('HTTP ' . $result['http_code']))),
        ];
    }

    public static function testConnectionStatic(string $ip, string $username, string $password, int $port = 80): array
    {
        return (new self($ip, $username, $password, $port))->testConnection();
    }

    /**
     * Shared UserInfo payload builder for both add and modify calls.
     */
    private function buildUserInfo(
        string $employeeNo,
        string $name,
        bool $enable,
        ?string $beginTime = null,
        ?string $endTime = null,
        string $doorNo = '1'
    ): array {
        // Validity window is intentionally wide when enabled - access is controlled
        // purely by Valid.enable, the same way ZKTeco access is controlled by timezone.
        $beginTime = $beginTime ?? date('Y-m-d\TH:i:s', strtotime('-1 day'));
        $endTime   = $endTime   ?? date('Y-m-d\TH:i:s', strtotime('+10 years'));

        return [
            'UserInfo' => [
                'employeeNo' => $employeeNo,
                'name'       => substr($name, 0, 32),
                'userType'   => 'normal',
                'doorRight'  => $doorNo,
                'Valid'      => [
                    'enable'    => $enable,
                    'beginTime' => $beginTime,
                    'endTime'   => $endTime,
                    'timeType'  => 'local',
                ],
                'RightPlan' => [
                    ['doorNo' => (int)$doorNo, 'planTemplateNo' => '1'],
                ],
            ],
        ];
    }

    /**
     * Add a brand-new user to the device (enabled by default).
     * Falls back to Modify if Record fails because the employeeNo already exists.
     */
    public function addUser($employeeNo, string $name, bool $enable = true): array
    {
        $employeeNo = (string)$employeeNo;
        $payload = $this->buildUserInfo($employeeNo, $name, $enable);

        $result = $this->request('POST', '/ISAPI/AccessControl/UserInfo/Record?format=json', $payload);

        if (!$result['success']) {
            error_log("Hikvision: Record failed for {$employeeNo} (HTTP {$result['http_code']}), retrying via Modify");
            $result = $this->request('PUT', '/ISAPI/AccessControl/UserInfo/Modify?format=json', $payload);
        }

        return $result;
    }

    /**
     * Update an existing user's name/validity window and enable state.
     */
    public function modifyUser($employeeNo, string $name, bool $enable, ?string $beginTime = null, ?string $endTime = null): array
    {
        $payload = $this->buildUserInfo((string)$employeeNo, $name, $enable, $beginTime, $endTime);
        return $this->request('PUT', '/ISAPI/AccessControl/UserInfo/Modify?format=json', $payload);
    }

    /**
     * Instantly block a member at the door (expired/suspended membership).
     * The user record and door-right plan stay intact so re-enabling is immediate.
     */
    public function disableUser($employeeNo, string $name = ''): array
    {
        $now = date('Y-m-d\TH:i:s');
        return $this->modifyUser($employeeNo, $name ?: ('Member ' . $employeeNo), false, $now, $now);
    }

    /**
     * Restore access for a renewed member.
     */
    public function enableUser($employeeNo, string $name = ''): array
    {
        return $this->modifyUser(
            $employeeNo,
            $name ?: ('Member ' . $employeeNo),
            true,
            date('Y-m-d\TH:i:s', strtotime('-1 day')),
            date('Y-m-d\TH:i:s', strtotime('+10 years'))
        );
    }

    /**
     * Remove a user entirely from the device.
     */
    public function removeUser($employeeNo): array
    {
        $payload = [
            'UserInfoDetail' => [
                'mode' => 'byEmployeeNo',
                'EmployeeNoList' => [
                    ['employeeNo' => (string)$employeeNo],
                ],
            ],
        ];

        return $this->request('PUT', '/ISAPI/AccessControl/UserInfoDetail/Delete?format=json', $payload);
    }

    /**
     * Reboot the terminal (ISAPI System/reboot).
     */
    public function restart(): array
    {
        $result = $this->request('PUT', '/ISAPI/System/reboot?format=json');
        return [
            'success' => $result['success'],
            'message' => $result['success']
                ? 'Restart command sent'
                : ('Restart failed: ' . ($result['message'] ?? ('HTTP ' . $result['http_code']))),
        ];
    }

    /**
     * Push/update a batch of members as enabled users on the device.
     * addUser() already upserts (Record, falling back to Modify), so this is
     * safe to call for members that may or may not already exist on the device.
     */
    public function syncUsersFromDb(array $members): array
    {
        if (empty($members)) {
            return ['success' => true, 'message' => 'No members to sync', 'total' => 0, 'successful' => 0, 'failed' => 0];
        }

        $successful = 0;
        $failed = 0;

        foreach ($members as $member) {
            if (empty($member['biometric_id'])) {
                $failed++;
                continue;
            }

            $name = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
            if ($name === '') {
                $name = 'Member ' . $member['biometric_id'];
            }

            $result = $this->addUser($member['biometric_id'], $name, true);
            $result['success'] ? $successful++ : $failed++;

            // Gentle pacing - these terminals process face/user records synchronously
            // and can choke on rapid-fire requests.
            usleep(150000);
        }

        return [
            'success' => $failed === 0,
            'message' => "Synced {$successful} member(s)" . ($failed > 0 ? ", {$failed} failed" : ''),
            'total' => count($members),
            'successful' => $successful,
            'failed' => $failed,
        ];
    }

    /**
     * Pull access events (door punches) from the terminal for a time window.
     * Uses the standard ISAPI AccessControl/AcsEvent search.
     *
     * NOTE: field names in the response (employeeNoString, currentVerifyMode, etc.)
     * are the common shape for Hikvision access-control firmware, but can vary
     * slightly by firmware version on the DS-K1T343/DS-K1T671. If events come back
     * empty despite door activity, log $result['raw'] once to check the actual
     * field names your firmware returns and adjust the mapping below.
     *
     * Only returns the first page (up to $maxResults). If you have busy doors,
     * call this more frequently (e.g. every few minutes via cron) rather than
     * trying to page through totalMatches in one call.
     */
    public function getAccessEvents(?string $startTime = null, ?string $endTime = null, int $maxResults = 200): array
    {
        $startTime = $startTime ?? date('Y-m-d\TH:i:sP', strtotime('-1 day'));
        $endTime   = $endTime   ?? date('Y-m-d\TH:i:sP');

        $payload = [
            'AcsEventCond' => [
                'searchID'             => uniqid('acs_', true),
                'searchResultPosition' => 0,
                'maxResults'           => $maxResults,
                'major'                => 0,
                'minor'                => 0,
                'startTime'            => $startTime,
                'endTime'              => $endTime,
            ],
        ];

        $result = $this->request('POST', '/ISAPI/AccessControl/AcsEvent?format=json', $payload, 20);

        if (!$result['success']) {
            error_log('Hikvision: AcsEvent search failed - ' . json_encode($result));
            return [];
        }

        $raw = is_array($result['raw']) ? $result['raw'] : [];
        $infoList = $raw['AcsEvent']['InfoList'] ?? [];

        $normalized = [];
        foreach ($infoList as $item) {
            $employeeNo = $item['employeeNoString'] ?? ($item['employeeNo'] ?? null);
            $time = $item['time'] ?? null;

            if (empty($employeeNo) || empty($time)) {
                continue; // door-open/tamper/system events without a member attached
            }

            $normalized[] = [
                'employeeNo'  => $employeeNo,
                'timestamp'   => $time,
                'verify_mode' => $item['currentVerifyMode'] ?? '',
                'name'        => $item['name'] ?? '',
            ];
        }

        return $normalized;
    }

    /**
     * Batch sync membership state: active members are enabled, expired/inactive/
     * suspended members are disabled.
     */
    public function syncMembershipState(array $members): array
    {
        $results = ['active' => 0, 'expired' => 0, 'failed' => 0];

        foreach ($members as $m) {
            $employeeNo = $m['biometric_id'] ?? null;
            if (empty($employeeNo)) {
                $results['failed']++;
                continue;
            }

            $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
            if ($name === '') {
                $name = 'Member ' . $employeeNo;
            }

            $isExpired = !empty($m['expiry_date']) && strtotime($m['expiry_date']) < strtotime(date('Y-m-d'));

            if ($isExpired || in_array($m['status'] ?? '', ['expired', 'inactive', 'suspended'], true)) {
                $result = $this->disableUser($employeeNo, $name);
                $result['success'] ? $results['expired']++ : $results['failed']++;
            } else {
                $result = $this->enableUser($employeeNo, $name);
                $result['success'] ? $results['active']++ : $results['failed']++;
            }
        }

        return $results;
    }
}