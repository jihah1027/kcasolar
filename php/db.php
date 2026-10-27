<?php
header("Content-Type: application/json; charset=UTF-8");

// --- Database Credentials for kcasolar ---
$db_host = 'localhost';
$db_name = 'kcasolar_kcaconnect'; 
$db_user = 'kcasolar_user123';      
$db_pass = 'M#g+??2HI5=3^JU~';    // The password you generated in MySQL Wizard
$charset = 'utf8mb4';

$dsn = "mysql:host=$db_host;dbname=$db_name;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed: " . $e->getMessage()
    ]);
    exit;
}
?>