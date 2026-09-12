<?php
/**
 * Keep session alive endpoint
 */

require_once dirname(__DIR__) . '/config/config.php';

use Gym\Core\Session;

Session::updateActivity();
http_response_code(204);
