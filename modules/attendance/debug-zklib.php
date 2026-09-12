<?php
require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Database;
use Gym\Core\ZKTeco;

// Get default device
$device = Database::fetchOne("SELECT * FROM zkteco_devices WHERE is_default = 1 LIMIT 1");

if (!$device) {
    die("No default device configured");
}

echo "<h1>ZKLib Method Debug</h1>";
echo "<p>Device: {$device['device_name']} ({$device['device_ip']}:{$device['port']})</p>";

$zk = new ZKTeco($device['device_ip'], (int)$device['port']);

if (!$zk->connect()) {
    die("Cannot connect to device");
}

// Get the internal ZKLib object
$reflection = new ReflectionClass($zk);
$zkProperty = $reflection->getProperty('zk');
$zkProperty->setAccessible(true);
$zkLib = $zkProperty->getValue($zk);

echo "<h2>Available Methods in ZKLib:</h2>";
echo "<pre>";
$methods = get_class_methods($zkLib);
sort($methods);
foreach ($methods as $method) {
    echo "$method\n";
}
echo "</pre>";

echo "<h2>Get One User to See Structure:</h2>";
echo "<pre>";
$users = [];
if (method_exists($zkLib, 'getUser')) {
    $users = $zkLib->getUser();
} elseif (method_exists($zkLib, 'getAllUsers')) {
    $users = $zkLib->getAllUsers();
}

if (!empty($users) && is_array($users)) {
    $firstUser = reset($users);
    echo json_encode($firstUser, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    echo "No users returned or not array: " . var_export($users, true);
}
echo "</pre>";

$zk->disconnect();
?>