<?php
// tutor/db.php - alias for db_connect.php
require_once __DIR__ . '/../config/db_connect.php';
// $pdo is initialized in db_connect.php
if (!isset($db)) {
    $db = $pdo;
}