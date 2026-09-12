<?php
/**
 * Dashboard module - redirects to main dashboard
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

header('Location: ' . BASE_URL . '/index.php');
exit;
