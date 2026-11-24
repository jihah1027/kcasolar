<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Authorization");

require_once 'db.php';

// Extract Token
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$token = '';

if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $token = $matches[1];
}

if (!$token) {
    echo json_encode(["success" => false, "message" => "Unauthorized access. Please log in again."]);
    exit;
}

try {
    // Verify Token and Admin Status
    $stmt = $pdo->prepare("SELECT role FROM users WHERE auth_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $activeUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeUser || ($activeUser['role'] !== 'admin' && $activeUser['role'] !== 'super_admin')) {
        echo json_encode(["success" => false, "message" => "Access denied. Administrative privileges required."]);
        exit;
    }

    // Fetch Directory Data (Exclude passwords + tokens)
    $stmt = $pdo->query("SELECT id, staff_id, full_name, email, phone, department, role, status FROM users ORDER BY staff_id ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "users" => $users
    ]);

} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>