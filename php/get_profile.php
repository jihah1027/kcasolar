<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Authorization");

require_once 'db.php';

// Extract token from frontend
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$token = '';

if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $token = $matches[1];
}

// Reject if no token is found
if (!$token) {
    echo json_encode(["success" => false, "message" => "Unauthorized access. No token provided."]);
    exit;
}

// Find user in the db who owns this token
try {
    $stmt = $pdo->prepare("SELECT staff_id, full_name, department, role, email, phone FROM users WHERE auth_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo json_encode([
            "success" => true,
            "user" => $user
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Session expired or invalid token."]);
    }

} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>