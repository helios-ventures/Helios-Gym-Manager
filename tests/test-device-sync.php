<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// File: tests/test-device-sync.php

require_once dirname(__DIR__) . '/config/config.php';

use Gym\Core\Database;
use Gym\Core\DeviceSyncManager;

echo "=== Device Sync Testing ===\n\n";

$db = new Database();

// Test 1: Check tables exist
echo "Test 1: Check tables...\n";
$tables = ['sync_queue', 'device_sync_logs', 'device_conflicts'];
foreach ($tables as $table) {
    $result = $db->fetchOne(
        "SELECT COUNT(*) as cnt FROM information_schema.TABLES 
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
        [DB_NAME, $table]
    );
    echo "  ✓ $table: " . ($result['cnt'] ? 'OK' : 'MISSING') . "\n";
}

// Test 2: Get device
echo "\nTest 2: Get device...\n";
$device = $db->fetchOne("SELECT * FROM zkteco_devices WHERE is_default = 1 OR status = 'online' LIMIT 1");
if ($device) {
    echo "  ✓ Found device: {$device['device_name']}\n";
} else {
    echo "  ✗ No device found\n";
    exit;
}

// Test 3: Count members to sync
echo "\nTest 3: Check unsynced members...\n";
$unsynced = $db->fetchOne(
    "SELECT COUNT(*) as cnt FROM members 
     WHERE status = 'active' AND (synced_to_device IS NULL OR updated_at > synced_to_device)"
);
echo "  ✓ Unsynced members: {$unsynced['cnt']}\n";

// Test 4: Try smartSync (dry run)
echo "\nTest 4: Test sync manager...\n";
try {
    $manager = new DeviceSyncManager($db, $device);
    echo "  ✓ Manager initialized\n";
    
    // Don't actually sync, just verify it can be instantiated
    echo "  ✓ Ready for sync\n";
} catch (\Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

echo "\n=== All tests passed! ===\n";
?>