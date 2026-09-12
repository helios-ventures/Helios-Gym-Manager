<?php

require_once __DIR__ . '/../config/config.php';

use Gym\Core\Database;
use Gym\Core\DeviceSyncManager;


$jobs = Database::fetchAll("
    SELECT q.*, d.device_ip, d.port, d.id as device_pk
    FROM sync_queue q
    JOIN zkteco_devices d ON d.id = q.device_id
    WHERE q.status = 'pending'
    ORDER BY q.id ASC
    LIMIT 20
");

foreach ($jobs as $job) {

    Database::execute(
        "UPDATE sync_queue SET status = 'processing' WHERE id = ?",
        [$job['id']]
    );

    try {

        $device = [
            'id' => $job['device_pk'],
            'device_ip' => $job['device_ip'],
            'port' => $job['port']
        ];

        $sync = new DeviceSyncManager(Database::getInstance(), $device);

        if ($job['operation_type'] === 'attendance_from_device') {
            $result = $sync->syncAttendance();
        }

        if ($job['operation_type'] === 'members_to_device') {
            $result = $sync->smartSync();
        }

        Database::execute(
            "UPDATE sync_queue 
             SET status = 'completed', response = ? 
             WHERE id = ?",
            [json_encode($result), $job['id']]
        );

    } catch (Exception $e) {

        Database::execute(
            "UPDATE sync_queue 
             SET status = 'failed', error_message = ? 
             WHERE id = ?",
            [$e->getMessage(), $job['id']]
        );
    }
}