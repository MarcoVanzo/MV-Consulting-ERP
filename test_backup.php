<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require 'api/Database.php';
require 'api/Response.php';
require 'api/Controllers/AdminController.php';

try {
    $c = new AdminController();
    $c->createBackup();
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
