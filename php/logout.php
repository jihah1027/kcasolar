<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

require_once 'db.php';

// Get the token from the frontend headers
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

// If a token is provided, wipe it from the database
if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $token = $matches[1];
    $stmt = $pdo->prepare("UPDATE users SET auth_token = NULL WHERE auth_token = ?");
    $stmt->execute([$token]);
}

echo json_encode(["success" => true, "message" => "Logged out securely"]);
?>