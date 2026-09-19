<?php
/**
 * MemberAccess
 *
 * Single place that knows how to flip a member's biometric door access on/off.
 * Used by:
 *   - modules/members/toggle-access.php (staff clicking the switch)
 *   - modules/members/renew.php (auto-restore access after renewal)
 *   - cron/membership_expiry_check.php (auto-disable on expiry)
 *
 * The DB always reflects the INTENDED state immediately (so the UI is honest
 * about what staff asked for), but `biometric_synced_at` is only stamped when
 * the device call actually succeeds - so a failed device call is visible as
 * "not yet synced" rather than silently pretending it worked.
 */

namespace Gym\Core;

class MemberAccess
{
    private static function getDefaultDevice(): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM zkteco_devices WHERE device_type = 'hikvision' AND (is_default = 1 OR status = 'online') LIMIT 1"
        );
    }

    private static function client(array $device): ?Hikvision
    {
        if (empty($device['device_username']) || empty($device['device_password'])) {
            return null;
        }
        return new Hikvision(
            $device['device_ip'],
            $device['device_username'],
            $device['device_password'],
            (int)($device['port'] ?: 80)
        );
    }

    private static function memberName(array $member): string
    {
        $name = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        return $name !== '' ? $name : ('Member ' . ($member['biometric_id'] ?? ''));
    }

    private static function memberGender(array $member): string
    {
        $gender = strtolower($member['gender'] ?? '');
        return in_array($gender, ['male', 'female'], true) ? $gender : 'unknown';
    }

    /**
     * @param array  $member Full members row (needs id, biometric_id, first_name, last_name)
     * @param string $reason 'expired' or 'manual'
     */
    public static function disable(array $member, string $reason = 'manual'): array
    {
        if (empty($member['biometric_id'])) {
            return ['success' => false, 'message' => 'Member has no biometric/employee ID assigned'];
        }

        $deviceResult = ['success' => false, 'message' => 'No Hikvision device configured'];
        $device = self::getDefaultDevice();

        if ($device) {
            $hik = self::client($device);
            $deviceResult = $hik
                ? $hik->disableUser($member['biometric_id'], self::memberName($member), self::memberGender($member))
                : ['success' => false, 'message' => 'Device is missing username/password'];
        }

        Database::execute(
            "UPDATE members SET biometric_enabled = 0, disabled_reason = ?, biometric_synced_at = ? WHERE id = ?",
            [$reason, $deviceResult['success'] ? date('Y-m-d H:i:s') : null, $member['id']]
        );

        return $deviceResult;
    }

    public static function enable(array $member): array
    {
        if (empty($member['biometric_id'])) {
            return ['success' => false, 'message' => 'Member has no biometric/employee ID assigned'];
        }

        $deviceResult = ['success' => false, 'message' => 'No Hikvision device configured'];
        $device = self::getDefaultDevice();

        if ($device) {
            $hik = self::client($device);
            $deviceResult = $hik
                ? $hik->enableUser($member['biometric_id'], self::memberName($member), self::memberGender($member))
                : ['success' => false, 'message' => 'Device is missing username/password'];
        }

        Database::execute(
            "UPDATE members SET biometric_enabled = 1, disabled_reason = NULL, biometric_synced_at = ? WHERE id = ?",
            [$deviceResult['success'] ? date('Y-m-d H:i:s') : null, $member['id']]
        );

        return $deviceResult;
    }

    /**
     * Recompute whether a member SHOULD have device access based on their
     * current status/expiry_date, and push a change only if needed.
     *
     * Use this (not enable()/disable() directly) anywhere expiry_date or status
     * changes as a side effect of a business event: renewal, a POS subscription
     * purchase, or a staff member manually editing the expiry date. It keeps
     * the device in sync without every call site having to re-derive the logic.
     *
     * Deliberately does NOT touch access for a member whose access is off with
     * disabled_reason = 'manual' - a staff-issued block (e.g. banned for
     * conduct) should not be silently undone just because their subscription
     * happens to look valid again. Only an explicit call to enable() (the
     * toggle button) lifts a manual block.
     *
     * The direct enable()/disable() calls remain the right choice for the
     * manual toggle button itself, since that's an explicit override, not a
     * derived state.
     */
    public static function syncFromMembershipState(array $member): array
    {
        if (empty($member['biometric_id'])) {
            return ['success' => true, 'message' => 'No biometric ID assigned - nothing to sync', 'skipped' => true];
        }

        $today = date('Y-m-d');
        $isExpired = !empty($member['expiry_date']) && $member['expiry_date'] < $today;
        $shouldBeEnabled = ($member['status'] ?? '') === 'active' && !$isExpired;
        $isCurrentlyEnabled = !empty($member['biometric_enabled']);
        $wasManuallyDisabled = !$isCurrentlyEnabled && ($member['disabled_reason'] ?? null) === 'manual';

        if ($shouldBeEnabled && $wasManuallyDisabled) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'Subscription looks valid, but access stays off (manually disabled) - re-enable from the member profile if that was intentional.',
            ];
        }

        if ($shouldBeEnabled && (!$isCurrentlyEnabled || empty($member['biometric_synced_at']))) {
            return self::enable($member);
        }

        if (!$shouldBeEnabled && $isCurrentlyEnabled) {
            return self::disable($member, $isExpired ? 'expired' : 'manual');
        }

        return ['success' => true, 'skipped' => true, 'message' => 'No change needed'];
    }
}
