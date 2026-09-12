<?php
require_once 'config/config.php';
use Gym\Core\Database;
use Gym\Core\ZKTeco;

$zk = new ZKTeco('10.5.50.64', 4370);
if ($zk->connect()) {
    // Get sample members from database
    $members = Database::fetchAll(
        "SELECT id, biometric_id, first_name, last_name, status, expiry_date 
         FROM members WHERE biometric_id IS NOT NULL LIMIT 5"
    );
    
    echo "Testing membership sync with " . count($members) . " members...\n";
    
    $result = $zk->syncMembershipState($members);
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    
    $zk->disconnect();
}
?>