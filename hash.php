<?php
require_once __DIR__ . '/config/db_connect.php'; // unified DB credentials (.env)

// Database connection parameters
$host = DB_HOST;
$db   = DB_NAME;
$user = DB_USER;
$pass=DB_PASSWORD;
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo "Successfully connected to the database.<br>";
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// User data to be added
$users_to_add = [
    [
        'email' => 'admin@example.com',
        'first_name' => 'Admin',
        'last_name' => 'User',
        'password_hash' => '$2y$10$M.BbjDvLqOPAxB8OEW6BHOE6i0TTzYibiRjdbaN9aH.KWwanyu/ym',
        'role' => 'Administrator',
        'is_active' => 1
    ],
    [
        'email' => 'student@example.com',
        'first_name' => 'Student',
        'last_name' => 'User',
        'password_hash' => password_hash('password123', PASSWORD_DEFAULT),
        'role' => 'Student',
        'is_active' => 1
    ]
];

foreach ($users_to_add as $user_data) {
    try {
        // Check if the user already exists
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt_check->execute([$user_data['email']]);
        if ($stmt_check->fetchColumn() > 0) {
            echo "User with email '{$user_data['email']}' already exists. Skipping...<br>";
            continue;
        }

        // Prepare the insert statement
        $stmt = $pdo->prepare(
            "INSERT INTO users (email, first_name, last_name, password_hash, role, is_active) 
             VALUES (:email, :first_name, :last_name, :password_hash, :role, :is_active)"
        );

        // Execute the statement with the user data
        $stmt->execute([
            'email' => $user_data['email'],
            'first_name' => $user_data['first_name'],
            'last_name' => $user_data['last_name'],
            'password_hash' => $user_data['password_hash'],
            'role' => $user_data['role'],
            'is_active' => $user_data['is_active']
        ]);
        
        echo "Successfully added {$user_data['role']} user: '{$user_data['email']}'<br>";
    
    } catch (\PDOException $e) {
        error_log("Error adding user: " . $e->getMessage());
        echo "Error adding user with email '{$user_data['email']}': " . $e->getMessage() . "<br>";
    }
}
?>
