<?php
require_once __DIR__ . '/../config/db_connect.php'; // unified DB credentials (.env)
// db_connect.php

$host = DB_HOST; // Your host might be 'localhost' or an IP
$db   = DB_NAME;
$user = DB_USER;      // Replace with your DB username
$pass=DB_PASSWORD;          // Replace with your DB password
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // echo "Connected successfully to the database."; // For debugging, remove in production
} catch (\PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}
?>