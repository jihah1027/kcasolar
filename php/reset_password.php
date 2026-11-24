<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
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
    echo json_encode(["success" => false, "message" => "Unauthorized access."]);
    exit;
}

try {
    // Verify Token and Admin Status
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE auth_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $activeUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeUser || ($activeUser['role'] !== 'admin' && $activeUser['role'] !== 'super_admin')) {
        echo json_encode(["success" => false, "message" => "Access denied."]);
        exit;
    }

    $target_id = trim($_POST['target_id'] ?? '');

    if (empty($target_id)) {
        echo json_encode(["success" => false, "message" => "Target user ID is missing."]);
        exit;
    }
    
    // Regular admins can't reset Super Admin's password
    if ($activeUser['role'] !== 'super_admin') {
        $targetStmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $targetStmt->execute([$target_id]);
        $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($targetUser && ($targetUser['role'] === 'admin' || $targetUser['role'] === 'super_admin') && $activeUser['id'] != $target_id) {
            echo json_encode(["success" => false, "message" => "Permission denied. You cannot reset an executive's password."]);
            exit;
        }
    }

    // Hash default password + wipe existing token
    $newHash = password_hash('kca123', PASSWORD_BCRYPT);
    
    $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ?, auth_token = NULL WHERE id = ?");
    $updateStmt->execute([$newHash, $target_id]);

    echo json_encode(["success" => true, "message" => "Password reset to kca123."]);

} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>