<?php

require_once __DIR__ . '/../config/config.php';

use Gym\Core\Database;
use Gym\Core\DeviceSyncManager;

set_time_limit(0);
ignore_user_abort(true);

// Prevent multiple instances (important)
$lockFile = __DIR__ . '/sync_worker.lock';

if (file_exists($lockFile)) {
    die("Worker already running\n");
}

file_put_contents($lockFile, getmypid());

register_shutdown_function(function () use ($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
});

echo "Sync Daemon started...\n";

while (true) {

    try {

        // 1. Recover stuck jobs
        Database::execute("
            UPDATE sync_queue 
            SET status = 'pending'
            WHERE status = 'processing'
            AND updated_at < NOW() - INTERVAL 10 MINUTE
        ");

        // 2. Fetch next job (lock it immediately)
        $job = Database::fetchOne("
            SELECT q.*, d.device_ip, d.port, d.id as device_pk
            FROM sync_queue q
            JOIN zkteco_devices d ON d.id = q.device_id
            WHERE q.status = 'pending'
            ORDER BY q.priority DESC, q.id ASC
            LIMIT 1
            FOR UPDATE
        ");

        if (!$job) {
            // No jobs → sleep
            sleep(3);
            continue;
        }

        // 3. Mark as processing atomically
        $updated = Database::execute("
            UPDATE sync_queue 
            SET status = 'processing', started_at = NOW()
            WHERE id = ? AND status = 'pending'
        ", [$job['id']]);

        if ($updated === 0) {
            continue; // already taken
        }

        echo "⚙️ Processing job {$job['id']} ({$job['operation_type']})\n";

        $device = [
            'id' => $job['device_pk'],
            'device_ip' => $job['device_ip'],
            'port' => $job['port']
        ];

        $sync = new DeviceSyncManager(Database::getInstance(), $device);

        // 4. Execute job
        if ($job['operation_type'] === 'attendance_from_device') {
            $result = $sync->syncAttendance();
        } elseif ($job['operation_type'] === 'members_to_device') {
            $result = $sync->smartSync();
        } else {
            $result = ['success' => false, 'message' => 'Unknown job type'];
        }

        // 5. Update result
        Database::execute("
            UPDATE sync_queue 
            SET status = ?, response = ?, completed_at = NOW()
            WHERE id = ?
        ", [
            ($result['success'] ?? false) ? 'completed' : 'failed',
            json_encode($result),
            $job['id']
        ]);

        echo "✅ Job {$job['id']} done\n";

    } catch (Throwable $e) {

        echo "❌ Worker error: " . $e->getMessage() . "\n";

        // prevent crash loop
        sleep(2);
    }
}