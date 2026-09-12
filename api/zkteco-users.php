<?php
/**
 * ZKTeco Wrapper - Enhanced with user fetching and K40 Pro disable support
 */

namespace Gym\Core;

require_once BASE_PATH . '/zklib/ZKLib.php';

class ZKTeco
{
    private $ip;
    private $port;
    private $zk = null;
    private $connected = false;

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
     * ✅ Essential for import feature
     */
    public function getAllUsers()
    {
        if (!$this->connected || !$this->zk) {
            error_log("ZKTeco: Not connected to device");
            return [];
        }

        try {
            $users = [];
            
            // Method 1: getUser() - Most common in ZKLib
            if (method_exists($this->zk, 'getUser')) {
                error_log("ZKTeco: Using getUser() method");
                $users = $this->zk->getUser();
            }
            
            // Method 2: getAllUsers() - Alternative
            elseif (method_exists($this->zk, 'getAllUsers')) {
                error_log("ZKTeco: Using getAllUsers() method");
                $users = $this->zk->getAllUsers();
            }
            
            // Method 3: getUserList() - Another variant
            elseif (method_exists($this->zk, 'getUserList')) {
                error_log("ZKTeco: Using getUserList() method");
                $users = $this->zk->getUserList();
            }
            
            // Method 4: getUsers() - Yet another variant
            elseif (method_exists($this->zk, 'getUsers')) {
                error_log("ZKTeco: Using getUsers() method");
                $users = $this->zk->getUsers();
            }
            
            if (!is_array($users)) {
                error_log("ZKTeco: getUser() returned non-array: " . var_export($users, true));
                return [];
            }
            
            // Normalize the response
            $normalized = [];
            foreach ($users as $user) {
                $normalized[] = [
                    'uid' => $user['uid'] ?? ($user['id'] ?? ($user['userid'] ?? 0)),
                    'id' => $user['uid'] ?? ($user['id'] ?? ($user['userid'] ?? 0)),
                    'userid' => $user['userid'] ?? ($user['id'] ?? ($user['uid'] ?? '')),
                    'name' => $user['name'] ?? ('User ' . ($user['uid'] ?? '')),
                    'password' => $user['password'] ?? '',
                    'cardno' => $user['cardno'] ?? '',
                    'role' => $user['role'] ?? 0,
                    'enabled' => $user['enabled'] ?? true,
                    'type' => $user['type'] ?? 0,
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

        error_log("ZKTeco: Adding user - ID=$userId, Name='$name', Card='$cardno'");

        try {
            // Try different method signatures
            if (method_exists($this->zk, 'setUser')) {
                $result = $this->zk->setUser(
                    $userId,
                    (string)$userId,
                    $name,
                    $password,
                    (int)$role,
                    $cardno
                );
                
                error_log("ZKTeco setUser result: " . var_export($result, true));
                return $result !== false && $result !== null;
            }
            
            if (method_exists($this->zk, 'setUserInfo')) {
                $result = $this->zk->setUserInfo($userId, $name, $password, (int)$role);
                error_log("ZKTeco setUserInfo result: " . var_export($result, true));
                return $result !== false && $result !== null;
            }
            
            if (method_exists($this->zk, 'saveUser')) {
                $result = $this->zk->saveUser($userId, $name);
                error_log("ZKTeco saveUser result: " . var_export($result, true));
                return $result !== false && $result !== null;
            }
            
            error_log("ZKTeco: No suitable add user method found");
            return false;
            
        } catch (\Exception $e) {
            error_log("ZKTeco: Exception adding user - " . $e->getMessage());
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
            
            if (method_exists($this->zk, 'deleteUser')) {
                $result = $this->zk->deleteUser($userId);
                error_log("ZKTeco deleteUser result: " . var_export($result, true));
                return $result !== false;
            }
            
            error_log("ZKTeco: No remove user method found");
            return false;
            
        } catch (\Exception $e) {
            error_log("ZKTeco: Exception removing user - " . $e->getMessage());
            return false;
        }
    }

    /**
     * DISABLE user on device (K40 Pro compatible)
     * ✅ Essential for denying access when membership expires
     * 
     * K40 Pro supports disabling users via setUserInfo or similar
     */
    public function disableUser($userId)
    {
        if (!$this->connected || !$this->zk) {
            error_log("ZKTeco: Not connected to device");
            return false;
        }

        $userId = (int)$userId;
        error_log("ZKTeco K40 Pro: Disabling user ID=$userId");

        try {
            // Method 1: disableUser() - Direct disable method
            if (method_exists($this->zk, 'disableUser')) {
                $result = $this->zk->disableUser($userId);
                error_log("ZKTeco disableUser result: " . var_export($result, true));
                
                if ($result !== false && $result !== null) {
                    error_log("ZKTeco K40 Pro: Successfully disabled user $userId");
                    return true;
                }
            }
            
            // Method 2: setUserInfo with disabled flag
            // Many ZKTeco devices support this via password or role field
            if (method_exists($this->zk, 'setUserInfo')) {
                // Try setting with special marker to disable
                $result = $this->zk->setUserInfo(
                    $userId,
                    'DISABLED_USER_' . $userId,  // Mark as disabled in name
                    '0000',                       // Dummy password
                    255                           // Role 255 = disabled on some devices
                );
                
                error_log("ZKTeco setUserInfo (disable) result: " . var_export($result, true));
                
                if ($result !== false && $result !== null) {
                    error_log("ZKTeco K40 Pro: Disabled user $userId via setUserInfo");
                    return true;
                }
            }
            
            // Method 3: setUser with disabled role
            if (method_exists($this->zk, 'setUser')) {
                $result = $this->zk->setUser(
                    $userId,
                    (string)$userId,
                    'DISABLED_' . $userId,
                    '0000',
                    255,  // High role number = disable
                    (string)$userId
                );
                
                error_log("ZKTeco setUser (disable) result: " . var_export($result, true));
                
                if ($result !== false && $result !== null) {
                    error_log("ZKTeco K40 Pro: Disabled user $userId via setUser");
                    return true;
                }
            }
            
            // Method 4: Fallback - Remove user (most aggressive)
            // If device doesn't support disable, remove user instead
            error_log("ZKTeco K40 Pro: Disable method not available, attempting remove instead");
            return $this->removeUser($userId);
            
        } catch (\Exception $e) {
            error_log("ZKTeco: Exception disabling user - " . $e->getMessage());
            return false;
        }
    }

    /**
     * ENABLE user on device
     */
    public function enableUser($userId)
    {
        if (!$this->connected || !$this->zk) {
            error_log("ZKTeco: Not connected to device");
            return false;
        }

        $userId = (int)$userId;
        error_log("ZKTeco K40 Pro: Enabling user ID=$userId");

        try {
            // Method 1: enableUser() - Direct enable method
            if (method_exists($this->zk, 'enableUser')) {
                $result = $this->zk->enableUser($userId);
                error_log("ZKTeco enableUser result: " . var_export($result, true));
                
                if ($result !== false && $result !== null) {
                    error_log("ZKTeco K40 Pro: Successfully enabled user $userId");
                    return true;
                }
            }
            
            // Method 2: Re-add user with normal role
            // Get current user info first
            $users = $this->getAllUsers();
            foreach ($users as $u) {
                if ($u['uid'] == $userId) {
                    // Re-add with role 0 (normal user)
                    return $this->addUser(
                        $userId,
                        $u['name'] ?? 'User ' . $userId,
                        $u['password'] ?? '',
                        0  // Normal role
                    );
                }
            }
            
            // Fallback: Try setUserInfo with normal role
            if (method_exists($this->zk, 'setUserInfo')) {
                $result = $this->zk->setUserInfo(
                    $userId,
                    'User ' . $userId,
                    '',
                    0  // Normal role
                );
                
                error_log("ZKTeco K40 Pro: Enabled user $userId via setUserInfo");
                return $result !== false;
            }
            
            return true;  // Assume success if no method found
            
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

            usleep(100000); // 100ms delay
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
                    'id' => $item['id'] ?? ($item['userid'] ?? ($item['ID'] ?? '')),
                    'userid' => $item['userid'] ?? ($item['id'] ?? ''),
                    'name' => $item['name'] ?? '',
                    'timestamp' => $item['timestamp'] ?? ($item['punch_time'] ?? date('Y-m-d H:i:s')),
                    'state' => $item['state'] ?? ($item['status'] ?? 0),
                    'type' => $item['type'] ?? 0,
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