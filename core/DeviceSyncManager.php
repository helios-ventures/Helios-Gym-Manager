<?php
namespace Gym\Core;

class DeviceSyncManager {
    private $db;
    private $device;
    
    public function __construct(Database $db, $device) {
        $this->db = $db;
        $this->device = $device;
    }
    
    /**
     * Smart sync: only changed data
     */
    public function smartSync($userId = null) {
        $startTime = microtime(true);
        
        // Start logging
        $logId = $this->db->insertAndGetId(
            "INSERT INTO device_sync_logs (device_id, sync_type, status, initiated_by)
             VALUES (?, ?, 'started', ?)",
            [$this->device['id'], 'members_sync', $userId]
        );
        
        try {
            // Get unsynced members
            $members = $this->db->fetchAll(
                "SELECT id, biometric_id, first_name, last_name 
                 FROM members 
                 WHERE status = 'active'
                 AND (synced_to_device IS NULL OR updated_at > synced_to_device)
                 LIMIT 500"
            );
            
            if (empty($members)) {
                $this->updateLog($logId, [
                    'total' => 0,
                    'successful' => 0,
                    'status' => 'completed'
                ], microtime(true) - $startTime);
                return ['success' => true, 'message' => 'No changes to sync'];
            }
            
            // Connect to device
            $zk = new ZKTeco($this->device['device_ip'], (int)$this->device['port']);
            if (!$zk->connect()) {
                throw new \Exception('Cannot connect to device');
            }
            
            $successful = 0;
            $failed = 0;
            
            // Sync in batches of 50
            foreach (array_chunk($members, 50) as $batch) {
                foreach ($batch as $member) {
                    try {
                        // Format name (max 28 chars for ZKTeco)
                        $name = substr($member['first_name'] . ' ' . $member['last_name'], 0, 28);
                        
                        // Add or update user on device
                        $result = $zk->addUser($member['biometric_id'] ?: $member['id'], trim($name));
                        
                        if ($result) {
                            // Mark as synced
                            $this->db->execute(
                                "UPDATE members SET synced_to_device = NOW() WHERE id = ?",
                                [$member['id']]
                            );
                            $successful++;
                        } else {
                            $failed++;
                        }
                    } catch (\Exception $e) {
                        $failed++;
                    }
                }
                
                // Small delay between batches
                usleep(200000); // 200ms
            }
            
            $zk->disconnect();
            
            // Update log
            $this->updateLog($logId, [
                'total' => count($members),
                'successful' => $successful,
                'failed' => $failed,
                'status' => 'completed'
            ], microtime(true) - $startTime);
            
            return [
                'success' => true,
                'message' => "Synced $successful/$" . count($members) . " members",
                'successful' => $successful,
                'failed' => $failed
            ];
            
        } catch (\Exception $e) {
            $this->db->execute(
                "UPDATE device_sync_logs SET status = 'failed', error_details = ? WHERE id = ?",
                [json_encode(['error' => $e->getMessage()]), $logId]
            );
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Sync attendance FROM device
     */
    public function syncAttendance($userId = null) {
        $startTime = microtime(true);
        
        // Start log
        $logId = $this->db->insertAndGetId(
            "INSERT INTO device_sync_logs (device_id, sync_type, direction, status, initiated_by)
             VALUES (?, ?, 'pull', 'started', ?)",
            [$this->device['id'], 'attendance_sync', $userId]
        );
        
        try {
            $zk = new ZKTeco($this->device['device_ip'], (int)$this->device['port']);
            if (!$zk->connect()) {
                throw new \Exception('Cannot connect to device');
            }
            
            // Get last sync time
            $lastSync = $this->getLastSyncTime();
            
            // Fetch from device
            $records = $zk->getAttendanceSince($lastSync);
            $zk->disconnect();
            
            if (empty($records)) {
                $this->updateLog($logId, [
                    'total' => 0,
                    'successful' => 0,
                    'status' => 'completed'
                ], microtime(true) - $startTime);
                return ['success' => true, 'message' => 'No new records'];
            }
            
            // Process records
            $inserted = 0;
            $paired = 0;
            $failed = 0;
            
            $this->db->beginTransaction();
            
            foreach ($records as $rec) {
                try {
                    // Try to parse timestamp
                    if (!isset($rec['timestamp']) || empty($rec['timestamp'])) {
                        $failed++;
                        continue;
                    }
                    
                    $ts = strtotime($rec['timestamp']);
                    if ($ts === false) {
                        $failed++;
                        continue;
                    }
                    
                    $checkTime = date('Y-m-d H:i:s', $ts);
                    $bioId = intval($rec['id'] ?? 0);
                    $isCheckout = in_array(intval($rec['state'] ?? 0), [1, 3, 5]);
                    
                    // Try to pair with open check-in
                    if ($isCheckout) {
                        $open = $this->db->fetchOne(
                            "SELECT id, check_in FROM attendance_logs 
                             WHERE biometric_id = ? AND DATE(check_in) = DATE(?)
                             AND check_out IS NULL 
                             ORDER BY check_in DESC LIMIT 1",
                            [$bioId, $checkTime]
                        );
                        
                        if ($open) {
                            $duration = round((strtotime($checkTime) - strtotime($open['check_in'])) / 60);
                            
                            $this->db->execute(
                                "UPDATE attendance_logs SET check_out = ?, duration_minutes = ? WHERE id = ?",
                                [$checkTime, max(0, $duration), $open['id']]
                            );
                            
                            $paired++;
                            continue;
                        }
                    }
                    
                    // Check for duplicates
                    $dup = $this->db->fetchOne(
                        "SELECT id FROM attendance_logs WHERE biometric_id = ? AND check_in = ?",
                        [$bioId, $checkTime]
                    );
                    
                    if ($dup) {
                        continue; // Skip duplicate
                    }
                    
                    // Insert new record
                    $this->db->insert(
                        "INSERT INTO attendance_logs (biometric_id, device_id, check_in, check_type, status)
                         VALUES (?, ?, ?, 'fingerprint', 'present')",
                        [$bioId, $this->device['id'], $checkTime]
                    );
                    
                    $inserted++;
                    
                } catch (\Exception $e) {
                    $failed++;
                }
            }
            
            $this->db->commit();
            
            // Update log
            $this->updateLog($logId, [
                'total' => count($records),
                'successful' => $inserted + $paired,
                'failed' => $failed,
                'status' => 'completed'
            ], microtime(true) - $startTime);
            
            return [
                'success' => true,
                'message' => "Inserted: $inserted, Paired: $paired, Failed: $failed",
                'inserted' => $inserted,
                'paired' => $paired
            ];
            
        } catch (\Exception $e) {
            $this->db->rollback();
            $this->db->execute(
                "UPDATE device_sync_logs SET status = 'failed', error_details = ? WHERE id = ?",
                [json_encode(['error' => $e->getMessage()]), $logId]
            );
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function updateLog($logId, $data, $duration) {
        $this->db->execute(
            "UPDATE device_sync_logs 
             SET total_records = ?, successful_records = ?, failed_records = ?, 
                 status = ?, duration_seconds = ?, completed_at = NOW()
             WHERE id = ?",
            [
                $data['total'] ?? 0,
                $data['successful'] ?? 0,
                $data['failed'] ?? 0,
                $data['status'] ?? 'completed',
                $duration,
                $logId
            ]
        );
    }
    
    private function getLastSyncTime() {
        $log = $this->db->fetchOne(
            "SELECT completed_at FROM device_sync_logs 
             WHERE device_id = ? AND sync_type = 'attendance_sync' AND status = 'completed'
             ORDER BY completed_at DESC LIMIT 1",
            [$this->device['id']]
        );
        
        return $log ? $log['completed_at'] : date('Y-m-d H:i:s', strtotime('-7 days'));
    }
}
?>