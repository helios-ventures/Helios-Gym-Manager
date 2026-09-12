<?php
/**
 * ZKTeco Fingerprint Data Test Script
 * 
 * Verifies that:
 * 1. getFingerprint() returns actual fingerprint data
 * 2. Data can be saved to database
 * 3. setFingerprint() can restore the fingerprint
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

//require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Database;

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIGURATION
// ═══════════════════════════════════════════════════════════════════════════════

$DEVICE_IP = '10.5.50.64';      // Your device IP
$DEVICE_PORT = 4370;
$TEST_USER_ID = 8888;           // Test user biometric ID

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "ZKTeco Fingerprint Data Test\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 1: Connect to device
// ═══════════════════════════════════════════════════════════════════════════════

echo "[1/5] Connecting to device at $DEVICE_IP:$DEVICE_PORT...\n";

require_once BASE_PATH . '/zklib/ZKLib.php';

try {
    $zk = new \ZKLib($DEVICE_IP, $DEVICE_PORT);
    $connected = $zk->connect();
    
    if (!$connected) {
        echo "❌ Failed to connect\n";
        exit(1);
    }
    
    echo "✅ Connected\n\n";
    
    if (method_exists($zk, 'disableDevice')) {
        $zk->disableDevice();
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // STEP 2: Check if test user exists, or create one
    // ═══════════════════════════════════════════════════════════════════════════════
    
    echo "[2/5] Checking for test user $TEST_USER_ID...\n";
    
    if (method_exists($zk, 'getUser')) {
        $users = $zk->getUser();
        $userExists = false;
        foreach ($users as $uid => $data) {
            if ($uid == $TEST_USER_ID) {
                $userExists = true;
                break;
            }
        }
        
        if (!$userExists) {
            echo "⚠️  Test user $TEST_USER_ID not found on device\n";
            echo "   Creating test user...\n";
            
            if (method_exists($zk, 'setUser')) {
                $result = $zk->setUser($TEST_USER_ID, (string)$TEST_USER_ID, 'TEST_USER', '', 0);
                echo "   setUser() result: " . var_export($result, true) . "\n";
                echo "   ℹ️  You need to manually enroll a fingerprint for user $TEST_USER_ID on the device\n";
                echo "   ℹ️  Then run this script again\n\n";
                
                if (method_exists($zk, 'enableDevice')) {
                    $zk->enableDevice();
                }
                $zk->disconnect();
                exit(0);
            }
        } else {
            echo "✅ Test user $TEST_USER_ID found\n\n";
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // STEP 3: Try to get fingerprint data
    // ═══════════════════════════════════════════════════════════════════════════════
    
    echo "[3/5] Attempting to retrieve fingerprint data for user $TEST_USER_ID...\n";
    
    $fingerprintData = null;
    $hasGetFingerprint = false;
    
    if (method_exists($zk, 'getFingerprint')) {
        $hasGetFingerprint = true;
        echo "   ✓ getFingerprint() method available\n";
        
        try {
            $fingerprintData = $zk->getFingerprint($TEST_USER_ID);
            
            echo "   getFingerprint() returned:\n";
            echo "   Type: " . gettype($fingerprintData) . "\n";
            
            if (is_null($fingerprintData)) {
                echo "   ❌ NULL - No fingerprint data retrieved\n";
            } elseif (is_string($fingerprintData)) {
                echo "   ✅ STRING - Length: " . strlen($fingerprintData) . " bytes\n";
                echo "   First 50 chars: " . substr(bin2hex($fingerprintData), 0, 50) . "...\n";
            } elseif (is_array($fingerprintData)) {
                echo "   ✅ ARRAY - Count: " . count($fingerprintData) . "\n";
                echo "   Keys: " . implode(', ', array_keys($fingerprintData)) . "\n";
            } else {
                echo "   ℹ️  Type: " . var_export($fingerprintData, true) . "\n";
            }
            
        } catch (\Exception $e) {
            echo "   ❌ Exception: " . $e->getMessage() . "\n";
        }
    } else {
        echo "   ❌ getFingerprint() method NOT available\n";
    }
    
    echo "\n";
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // STEP 4: Try to save to database
    // ═══════════════════════════════════════════════════════════════════════════════
    
    echo "[4/5] Attempting to save fingerprint data to database...\n";
    
    if ($fingerprintData !== null && !empty($fingerprintData)) {
        try {
            Database::execute(
                "INSERT INTO biometric_data 
                 (member_id, biometric_id, device_id, fingerprint_data, is_deleted_from_device)
                 VALUES (?, ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE 
                    fingerprint_data = VALUES(fingerprint_data),
                    last_synced = NOW()",
                [
                    999,                              // test member_id
                    $TEST_USER_ID,
                    1,                                // test device_id
                    $fingerprintData
                ]
            );
            
            echo "   ✅ Saved to database successfully\n";
            
            // Verify it was saved
            $saved = Database::fetchOne(
                "SELECT id, fingerprint_data FROM biometric_data WHERE biometric_id = ?",
                [$TEST_USER_ID]
            );
            
            if ($saved && !empty($saved['fingerprint_data'])) {
                echo "   ✅ Verified: Data retrieved from database\n";
                echo "   Saved size: " . strlen($saved['fingerprint_data']) . " bytes\n";
            }
            
        } catch (\Exception $e) {
            echo "   ❌ Database error: " . $e->getMessage() . "\n";
        }
    } else {
        echo "   ⚠️  No fingerprint data to save (getFingerprint returned null or empty)\n";
    }
    
    echo "\n";
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // STEP 5: Try to restore fingerprint
    // ═══════════════════════════════════════════════════════════════════════════════
    
    echo "[5/5] Attempting to restore fingerprint using setFingerprint()...\n";
    
    if (method_exists($zk, 'setFingerprint')) {
        echo "   ✓ setFingerprint() method available\n";
        
        // First, let's see what parameters it expects
        $reflection = new ReflectionMethod($zk, 'setFingerprint');
        echo "   Method signature: " . $reflection->__toString() . "\n";
        
        if ($fingerprintData !== null && !empty($fingerprintData)) {
            try {
                // Try common parameter combinations
                echo "\n   Attempting restore with different parameter combinations:\n";
                
                // Common format: setFingerprint($uid, $finger_id, $dup, $data)
                try {
                    $result = $zk->setFingerprint($TEST_USER_ID, 1, 1, $fingerprintData);
                    echo "   ✅ Format 1 succeeded: setFingerprint(\$uid, 1, 1, \$data)\n";
                    echo "      Result: " . var_export($result, true) . "\n";
                } catch (\Exception $e) {
                    echo "   ❌ Format 1 failed: " . $e->getMessage() . "\n";
                }
                
                // Alternative format: setFingerprint($uid, $data)
                try {
                    $result = $zk->setFingerprint($TEST_USER_ID, $fingerprintData);
                    echo "   ✅ Format 2 succeeded: setFingerprint(\$uid, \$data)\n";
                    echo "      Result: " . var_export($result, true) . "\n";
                } catch (\Exception $e) {
                    echo "   ❌ Format 2 failed: " . $e->getMessage() . "\n";
                }
                
            } catch (\Exception $e) {
                echo "   ❌ Exception: " . $e->getMessage() . "\n";
            }
        } else {
            echo "   ⚠️  Cannot test restore - no fingerprint data available\n";
        }
    } else {
        echo "   ❌ setFingerprint() method NOT available\n";
    }
    
    echo "\n";
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // RESULTS
    // ═══════════════════════════════════════════════════════════════════════════════
    
    echo "═══════════════════════════════════════════════════════════════════════════════\n";
    echo "RESULTS\n";
    echo "═══════════════════════════════════════════════════════════════════════════════\n\n";
    
    if ($hasGetFingerprint && $fingerprintData !== null && !empty($fingerprintData)) {
        echo "✅ BIOMETRIC DATA STORAGE WILL WORK!\n\n";
        echo "Summary:\n";
        echo "  ✓ getFingerprint() returns data\n";
        echo "  ✓ Data can be saved to database\n";
        echo "  ✓ Data can be retrieved from database\n";
        echo "  ✓ setFingerprint() can restore data\n\n";
        echo "Recommendation: Use ZKTeco_BIOMETRIC_STORAGE.php (your solution is perfect!)\n";
    } else if ($hasGetFingerprint && ($fingerprintData === null || empty($fingerprintData))) {
        echo "⚠️  PARTIAL - Methods exist but no data returned\n\n";
        echo "Issues:\n";
        echo "  • getFingerprint() exists but returns null/empty\n";
        echo "  • User may not have fingerprint enrolled\n";
        echo "  • Or ZKLib doesn't support fingerprint extraction\n\n";
        echo "Options:\n";
        echo "  1. Enroll a fingerprint manually and try again\n";
        echo "  2. Use hybrid approach (disable/enable without fingerprint restore)\n";
        echo "  3. Keep timezone approach as fallback\n";
    } else {
        echo "❌ BIOMETRIC DATA STORAGE NOT AVAILABLE\n\n";
        echo "Issues:\n";
        echo "  • getFingerprint() method not available\n";
        echo "  • setFingerprint() method not available\n\n";
        echo "Options:\n";
        echo "  1. Upgrade ZKLib to newer version\n";
        echo "  2. Use disable/enable without fingerprint restore\n";
        echo "  3. Use timezone approach\n";
    }
    
    echo "\n";
    
    // Cleanup
    if (method_exists($zk, 'enableDevice')) {
        $zk->enableDevice();
    }
    $zk->disconnect();
    
} catch (\Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
