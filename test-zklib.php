<?php
require_once 'zklib/ZKLib.php';

$zk = new ZKLib('10.5.50.62', 4370);
if ($zk->connect()) {
    echo "<pre>";
    echo "Available methods:\n";
    $reflection = new ReflectionClass($zk);
    foreach ($reflection->getMethods() as $method) {
        echo "- " . $method->getName() . "\n";
    }
    echo "</pre>";
    $zk->disconnect();
} else {
    echo "Cannot connect to device";
}
?>