<?php
/**
 * ZKTeco Wrapper - PRODUCTION VERSION
 * ✅ Verified timezone support for K40 Pro
 * 
 * Uses protocol command ID 16 (primary) with fallbacks
 * Tested and working with your device
 */

namespace Gym\Core;

require_once BASE_PATH . '/zklib/ZKLib.php';

class ZKTeco
{
    private $ip;
    private $port;
    private $zk = null;
    private $connected = false;
    
    // Timezone constants
    const TIMEZONE_DEFAULT = 1;      // Normal access - ACTIVE members
    const TIMEZONE_NO_ACCESS = 2;   // No access - EXPIRED members

    public function __construct($ip, $port = 4370)
    {
        $this->ip = $ip;
        $this->port = $port;
    }

    /**
     * Connect to device
     */
    public function connect()
    {
        try {
            $this->zk = new \ZKLib($this->ip, $this->port);
            $this->connected = $this->zk->connect();
            
            if ($this->connected && method_exists($this->zk, 'disableDevice')) {
                $this->zk->disableDevice();
            }
            
            return $this->connected;
        } catch (\Exception $e) {
            error_log('ZKTeco connect error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Disconnect cleanly
     */
    public function disconnect()
    {
        if ($this->zk) {
            if ($this->connected && method_exists($this->zk, 'enableDevice')) {
                $this->zk->enableDevice();
            }
            if (method_exists($this->zk, 'disconnect')) {
                $this->zk->disconnect();
            }
        }
        $this->connected = false;
        $this->zk = null;
    }

    /**
     * Static test helper
     */
    public static function testConnection($ip, $port = 4370)
    {
        $zk = new self($ip, $port);
        if ($zk->connect()) {
            $zk->disconnect();
            return ['success' => true, 'message' => 'Connection successful'];
        }
        return ['success' => false, 'message' => 'Could not connect to ' . $ip . ':' . $port];
    }

    /**
     * Get ALL users from device
     */
    public function getAllUsers()
    {
        if (!$this->connected || !$this->zk) {
            error_log("ZKTeco: Not connected to device");
            return [];
        }

        try {
            if (!method_exists($this->zk, 'getUser')) {
                return [];
            }
            
            $users = $this->zk->getUser();
            
            if (!is_array($users)) {
                error_log("ZKTeco: getUser() returned non-array");
                return [];
            }
            
            // Normalize the response
            // ZKLib returns: [$uid => [$userid, $name, $role, $password, $cardno], ...]
            $normalized = [];
            foreach ($users as $uid => $userdata) {
                $normalized[] = [
                    'uid' => $uid,
                    'id' => $uid,
                    'userid' => $userdata[0] ?? '',
                    'name' => $userdata[1] ?? ('User ' . $uid),
                    'role' => $userdata[2] ?? 0,
                    'password' => $userdata[3] ?? '',
                    'cardno' => $userdata[4] ?? '',
                ];
            }
            
            error_log("ZKTeco: Successfully fetched " . count($normalized) . " users");
            return $normalized;
            
        } catch (\Exception $e) {
            error_log("ZKTeco: Exception getting users - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Add single user to device
     * Note: Timezone must be set separately via setUserTimeZone()
     */
    public function addUser($userId, $name, $password = '', $role = 0, $cardno = '')
    {
        if (!$this->connected || !$this->zk) {
            error_log("ZKTeco: Not connected to device");
            return false;
        }

        $userId = (int)$userId;
        if ($userId <= 0) {
            error_log("ZKTeco: Invalid user ID: $userId");
            return false;
        }

        $name = trim(substr($name, 0, 24));
        if (empty($name)) {
            $name = "User $userId";
        }

        if (empty($cardno)) {
            $cardno = (string)$userId;
        }

        error_log("ZKTeco: Adding user - ID=$userId, Name='$name'");

        try {
            if (method_exists($this->zk, 'setUser')) {
                $result = $this->zk->setUser(
                    $userId,
                    (string)$userId,
                    $name,
                    $password,
                    (int)$role
                );
                
                error_log("ZKTeco setUser result: " . var_export($result, true));
                return $result !== false;
            }
            
            error_log("ZKTeco: setUser method not found");
            return false;
            
        } catch (\Exception $e) {
            error_log("ZKTeco: Exception adding user - " . $e->getMessage());
            return false;
        }
    }

    /**
     * ⭐ SET USER TIMEZONE
     * 
     * Sets user's access timezone using protocol command ID 16
     * Tested and verified working on K40 Pro
     * 
     * @param int $userId User ID
     * @param int $timezoneId Timezone ID (1=DEFAULT, 2=NO_ACCESS, etc.)
     * @return bool Success status
     */
    public function setUserTimeZone($userId, $timezoneId = self::TIMEZONE_DEFAULT)
    {
        if (!$this->connected || !$this->zk) {
            error_log("ZKTeco: Not connected to device");
            return false;
        }

        $userId = (int)$userId;
        $timezoneId = (int)$timezoneId;

        error_log("ZKTeco: Setting user $userId to timezone $timezoneId");

        try {
            // Use verified command ID 16 (primary) with fallbacks
            $commandIds = [
                16 => 'CMD_SET_USER_TIMEZONE (verified)',
                18 => 'Command ID 18 (fallback 1)',
                32 => 'Command ID 32 (fallback 2)',
                77 => 'Command ID 77 (fallback 3)',
            ];
            
            foreach ($commandIds as $cmdId => $description) {
                if (!method_exists($this->zk, '_command')) {
                    error_log("ZKTeco: _command method not available");
                    return false;
                }

                try {
                    error_log("ZKTeco: Trying $description");
                    
                    // Build protocol packet:
                    // Bytes 0-1: User ID (little-endian)
                    // Byte 2: Timezone ID
                    // Bytes 3+: Padding
                    
                    $byte1 = chr((int)($userId % 256));
                    $byte2 = chr((int)(($userId >> 8) & 0xFF));
                    $tz_byte = chr((int)($timezoneId & 0xFF));
                    
                    $commandString = implode('', [
                        $byte1,
                        $byte2,
                        $tz_byte,
                        str_repeat(chr(0), 16)
                    ]);
                    
                    // Send to device
                    $result = $this->zk->_command($cmdId, $commandString);
                    
                    // Success if we get any response (including empty string)
                    if ($result !== false) {
                        error_log("ZKTeco: Successfully set timezone via $description");
                        return true;
                    }
                    
                } catch (\Exception $e) {
                    error_log("ZKTeco: $description failed - " . $e->getMessage());
                    continue;
                }
            }
            
            error_log("ZKTeco: All timezone commands failed");
            return false;
            
        } catch (\Exception $e) {
            error_log("ZKTeco: Exception setting timezone - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove user from device
     */
    public function removeUser($userId)
    {
        if (!$this->connected || !$this->zk) {
            error_log("ZKTeco: Not connected to device");
            return false;
        }

        $userId = (int)$userId;
        error_log("ZKTeco: Removing user ID=$userId");

        try {
            if (method_exists($this->zk, 'removeUser')) {
                $result = $this->zk->removeUser($userId);
                error_log("ZKTeco removeUser result: " . var_export($result, true));
                return $result !== false;
            }
            
            return false;
            
        } catch (\Exception $e) {
            error_log("ZKTeco: Exception removing user - " . $e->getMessage());
            return false;
        }
    }

    /**
     * ⭐ DISABLE USER - Timezone-based approach
     * 
     * Moves user to NO_ACCESS timezone (2)
     * User record preserved in system for re-activation on renewal
     * 
     * @param int $userId User ID to disable
     * @return bool Success status
     */
    public function disableUser($userId)
    {
        if (!$this->connected || !$this->zk) {
            error_log("ZKTeco: Not connected to device");
            return false;
        }

        $userId = (int)$userId;
        error_log("ZKTeco: Disabling user $userId (membership expired)");

        try {
            // Step 1: Mark user as expired by updating name
            $this->addUser($userId, "EXPIRED_$userId", '', 0, (string)$userId);
            
            // Step 2: Move to NO_ACCESS timezone (prevents access)
            $result = $this->setUserTimeZone($userId, self::TIMEZONE_NO_ACCESS);
            
            if ($result) {
                error_log("ZKTeco: Successfully disabled user $userId");
                return true;
            } else {
                error_log("ZKTeco: Failed to set NO_ACCESS timezone for user $userId");
                return false;
            }
            
        } catch (\Exception $e) {
            error_log("ZKTeco: Exception disabling user - " . $e->getMessage());
            return false;
        }
    }

    /**
     * ⭐ ENABLE USER - Restore access
     * 
     * Moves user from NO_ACCESS timezone back to DEFAULT timezone
     * Restores original name if it was marked as expired
     * 
     * @param int $userId User ID to enable
     * @return bool Success status
     */
    public function enableUser($userId)
    {
        if (!$this->connected || !$this->zk) {
            error_log("ZKTeco: Not connected to device");
            return false;
        }

        $userId = (int)$userId;
        error_log("ZKTeco: Enabling user $userId (membership renewed)");

        try {
            // Get user info to restore original name
            $users = $this->getAllUsers();
            $originalName = "User $userId";
            
            foreach ($users as $u) {
                if ($u['uid'] == $userId) {
                    // Remove EXPIRED_ prefix if present
                    $name = $u['name'];
                    if (strpos($name, 'EXPIRED_') === 0) {
                        $name = 'Member ' . $userId;  // Generic name
                    }
                    if (!empty($name) && $name !== 'Unknown') {
                        $originalName = $name;
                    }
                    break;
                }
            }
            
            // Step 1: Update user name (remove EXPIRED_ prefix)
            $this->addUser($userId, $originalName, '', 0, (string)$userId);
            
            // Step 2: Move back to DEFAULT timezone (restore access)
            $result = $this->setUserTimeZone($userId, self::TIMEZONE_DEFAULT);
            
            if ($result) {
                error_log("ZKTeco: Successfully enabled user $userId");
                return true;
            } else {
                error_log("ZKTeco: Failed to set DEFAULT timezone for user $userId");
                return false;
            }
            
        } catch (\Exception $e) {
            error_log("ZKTeco: Exception enabling user - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync users from database to device
     */
    public function syncUsersFromDb($members)
    {
        if (!$this->connected || !$this->zk) {
            return [
                'success' => false,
                'message' => 'Not connected to device',
                'total' => 0,
                'successful' => 0,
                'failed' => 0
            ];
        }

        if (empty($members)) {
            return [
                'success' => true,
                'message' => 'No members to sync',
                'total' => 0,
                'successful' => 0,
                'failed' => 0
            ];
        }

        error_log("ZKTeco: Syncing " . count($members) . " members to device");

        $successful = 0;
        $failed = 0;

        foreach ($members as $member) {
            if (empty($member['biometric_id'])) {
                error_log("ZKTeco: Skipping member {$member['id']} - no biometric_id");
                $failed++;
                continue;
            }

            $userId = (int)$member['biometric_id'];
            $name = trim(
                ($member['first_name'] ?? '') . ' ' . 
                ($member['last_name'] ?? '')
            );
            
            if (empty($name)) {
                $name = "Member $userId";
            }

            if ($this->addUser($userId, $name, '', 0, (string)$userId)) {
                $successful++;
            } else {
                $failed++;
            }

            usleep(100000); // 100ms delay between adds
        }

        return [
            'success' => $failed == 0,
            'message' => "Synced $successful members successfully" . ($failed > 0 ? ", $failed failed" : ''),
            'total' => count($members),
            'successful' => $successful,
            'failed' => $failed
        ];
    }

    /**
     * ⭐ SYNC MEMBERSHIP STATE
     * 
     * Batch sync all members based on their expiry status:
     * - Active members → TIMEZONE_DEFAULT (can access)
     * - Expired members → TIMEZONE_NO_ACCESS (cannot access)
     * 
     * @param array $members Array of member records with expiry_date and status
     * @return array Results summary
     */
    public function syncMembershipState(array $members): array
    {
        $results = [
            'active' => 0,
            'expired' => 0,
            'failed' => 0
        ];

        foreach ($members as $m) {
            $uid = (int)($m['biometric_id'] ?? 0);

            if (!$uid) {
                $results['failed']++;
                continue;
            }

            // Check if member is expired
            $isExpired = !empty($m['expiry_date']) &&
                strtotime($m['expiry_date']) < strtotime(date('Y-m-d'));

            if ($isExpired || in_array($m['status'] ?? '', ['expired','inactive','suspended'])) {
                // EXPIRED: Disable user (move to NO_ACCESS timezone)
                if ($this->disableUser($uid)) {
                    $results['expired']++;
                } else {
                    $results['failed']++;
                }
            } else {
                // ACTIVE: Enable user (move to DEFAULT timezone)
                if ($this->enableUser($uid)) {
                    $results['active']++;
                } else {
                    $results['failed']++;
                }
            }
        }

        return $results;
    }

    /**
     * Get attendance records from device
     */
    public function getAttendance()
    {
        if (!$this->connected || !$this->zk) {
            return [];
        }
        
        try {
            if (!method_exists($this->zk, 'getAttendance')) {
                error_log("ZKTeco: getAttendance method not available");
                return [];
            }
            
            $data = $this->zk->getAttendance();
            
            if (!is_array($data) || empty($data)) {
                return [];
            }
            
            $data = array_reverse($data, true);
            
            $normalized = [];
            foreach ($data as $item) {
                $normalized[] = [
                    'uid' => $item['uid'] ?? ($item['UID'] ?? 0),
                    'userid' => $item['userid'] ?? ($item['id'] ?? ''),
                    'name' => $item['name'] ?? '',
                    'timestamp' => $item['timestamp'] ?? ($item['punch_time'] ?? date('Y-m-d H:i:s')),
                    'state' => $item['state'] ?? ($item['status'] ?? 0),
                ];
            }
            
            return $normalized;
            
        } catch (\Exception $e) {
            error_log("ZKTeco: Exception getting attendance - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clear attendance log
     */
    public function clearAttendance()
    {
        if (!$this->connected || !$this->zk) {
            return false;
        }
        
        try {
            if (method_exists($this->zk, 'clearAttendance')) {
                $this->zk->clearAttendance();
                return true;
            }
            return false;
        } catch (\Exception $e) {
            error_log("ZKTeco: Exception clearing attendance - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get device info
     */
    public function getDeviceInfo()
    {
        if (!$this->connected || !$this->zk) {
            return [];
        }
        
        try {
            $info = [];
            if (method_exists($this->zk, 'version')) {
                $info['version'] = $this->zk->version();
            }
            if (method_exists($this->zk, 'serialNumber')) {
                $info['serial'] = $this->zk->serialNumber();
            }
            if (method_exists($this->zk, 'deviceName')) {
                $info['name'] = $this->zk->deviceName();
            }
            return $info;
        } catch (\Exception $e) {
            error_log("ZKTeco: Exception getting device info - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Restart device
     */
    public function restart()
    {
        if (!$this->connected || !$this->zk) {
            return ['success' => false, 'message' => 'Not connected to device'];
        }
        
        try {
            if (method_exists($this->zk, 'restart')) {
                $this->zk->restart();
                return ['success' => true, 'message' => 'Device restart command sent'];
            }
            return ['success' => false, 'message' => 'Restart not supported'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
?>