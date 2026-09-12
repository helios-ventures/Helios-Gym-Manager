<?php
/**
 * Logout Handler
 */

require_once __DIR__ . '/config/config.php';

use Gym\Core\Auth;

Auth::logout();
header('Location: ' . BASE_URL . '/login.php');
exit;
