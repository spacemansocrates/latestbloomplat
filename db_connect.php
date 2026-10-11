<?php
require_once __DIR__ . '/config/db_connect.php'; // unified DB credentials (.env)
// db_connect.php

$host = DB_HOST; // Your host might be 'n localhost' or an IP
$db   = DB_NAME;
$user = DB_USER;      // Replace with your DB username
$pass=DB_PASSWORD;          // Replace with your DB password
$charset = 'utf8mb4';

// Set timezone to match your local time (UTC+2)
date_default_timezone_set('Africa/Blantyre'); // or 'Africa/Johannesburg', 'Africa/Cairo', etc.


$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Sync MySQL session timezone with PHP timezone
    $now = new DateTime();
    $mins = $now->getOffset() / 60;
    $sgn = ($mins < 0 ? -1 : 1);
    $mins = abs($mins);
    $hrs = floor($mins / 60);
    $mins -= $hrs * 60;
    $offset = sprintf('%+03d:%02d', $hrs * $sgn, $mins);
    $pdo->exec("SET time_zone='$offset';");
    
    // echo "Connected successfully to the database."; // For debugging, remove in production
} catch (\PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}