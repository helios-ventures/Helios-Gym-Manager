<?php
/**
 * AttendanceSync
 *
 * Reconciles Hikvision access events into attendance_logs (check-in/check-out
 * toggle). Two entry points share the same per-event logic in recordEvent():
 *   - pull() - batch mode, called by the manual devices.php button and the cron
 *   - recordEvent() - single-event mode, called directly by the real-time
 *     webhook (api/hikvision-webhook.php) as events arrive
 */

namespace Gym\Core;

class AttendanceSync
{
    public static function pull(array $device): array
    {
        if (empty($device['device_username']) || empty($device['device_password'])) {
            return [
                'success' => false,
                'message' => 'Device is missing username/password',
                'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'total_events' => 0,
            ];
        }

        $hik = new Hikvision(
            $device['device_ip'],
            $device['device_username'],
            $device['device_password'],
            (int)($device['port'] ?: 80)
        );

        $since = $device['last_sync']
            ? date('Y-m-d\TH:i:sP', strtotime($device['last_sync']))
            : date('Y-m-d\TH:i:sP', strtotime('-1 day'));

        $records = $hik->getAccessEvents($since);

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($records as $r) {
            $bioId = intval($r['employeeNo'] ?? 0);
            $result = self::recordEvent($bioId, $r['timestamp'] ?? '', $r['verify_mode'] ?? '', $device['id']);
            switch ($result) {
                case 'inserted': $inserted++; break;
                case 'updated':  $updated++;  break;
                default:         $skipped++;
            }
        }

        Database::execute("UPDATE zkteco_devices SET last_sync = NOW() WHERE id = ?", [$device['id']]);

        return [
            'success' => true,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'total_events' => count($records),
        ];
    }

    /**
     * Process a single access event (from either a batch pull or a real-time
     * webhook push) and reconcile it into attendance_logs.
     *
     * @return string 'inserted' | 'updated' | 'skipped'
     */
    public static function recordEvent(int $bioId, string $rawTimestamp, string $verifyMode, int $deviceId): string
    {
        $ts = strtotime($rawTimestamp);

        if (!$bioId || !$ts) {
            return 'skipped';
        }

        $punchTime = date('Y-m-d H:i:s', $ts);

        $member = Database::fetchOne("SELECT id FROM members WHERE biometric_id = ? LIMIT 1", [$bioId]);
        if (!$member) {
            return 'skipped';
        }

        $duplicate = Database::fetchOne(
            "SELECT id FROM attendance_logs WHERE biometric_id = ? AND check_in = ? LIMIT 1",
            [$bioId, $punchTime]
        );
        if ($duplicate) {
            return 'skipped';
        }

        $verifyMode = strtolower($verifyMode);
        $checkType = 'face';
        if (strpos($verifyMode, 'card') !== false) {
            $checkType = 'card';
        } elseif (strpos($verifyMode, 'fp') !== false || strpos($verifyMode, 'finger') !== false) {
            $checkType = 'fingerprint';
        }

        $openAttendance = Database::fetchOne(
            "SELECT id, check_in FROM attendance_logs 
             WHERE biometric_id = ? AND check_out IS NULL 
             ORDER BY check_in DESC LIMIT 1",
            [$bioId]
        );

        if ($openAttendance) {
            $checkInTime = strtotime($openAttendance['check_in']);
            $checkOutTime = strtotime($punchTime);

            if ($checkOutTime <= $checkInTime) {
                return 'skipped';
            }

            $duration = round(($checkOutTime - $checkInTime) / 60);

            Database::execute(
                "UPDATE attendance_logs SET check_out = ?, duration_minutes = ?, status = 'present' WHERE id = ?",
                [$punchTime, $duration, $openAttendance['id']]
            );

            return 'updated';
        }

        Database::execute(
            "INSERT INTO attendance_logs (member_id, biometric_id, device_id, check_in, check_type, status)
             VALUES (?, ?, ?, ?, ?, 'present')",
            [$member['id'], $bioId, $deviceId, $punchTime, $checkType]
        );

        return 'inserted';
    }
}
