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
        echo json_encode(["success" => false, "message" => "Access denied. Administrative privileges required."]);
        exit;
    }

    // Collect Form Data
    $target_id = trim($_POST['target_id'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    $new_role = trim($_POST['role'] ?? '');

    if (empty($target_id) || empty($full_name) || empty($department)) {
        echo json_encode(["success" => false, "message" => "Please fill in all required fields."]);
        exit;
    }

    // Fetch target user to check permissions
    $targetStmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $targetStmt->execute([$target_id]);
    $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        echo json_encode(["success" => false, "message" => "Target user not found."]);
        exit;
    }

    $isSuperAdmin = ($activeUser['role'] === 'super_admin');

    // Regular admins cannot edit other admin or super admin
    if (!$isSuperAdmin && ($targetUser['role'] === 'admin' || $targetUser['role'] === 'super_admin') && $activeUser['id'] != $target_id) {
        echo json_encode(["success" => false, "message" => "Permission denied. Only Super Admins can modify executive accounts."]);
        exit;
    }

    // Regular admins cannot change roles (Keep original role)
    if (!$isSuperAdmin || empty($new_role)) {
        $new_role = $targetUser['role']; 
    }

    // Update DB Record
    $updateStmt = $pdo->prepare("
        UPDATE users 
        SET full_name = ?, email = ?, phone = ?, department = ?, role = ?, status = ?
        WHERE id = ?
    ");
    $updateStmt->execute([$full_name, $email, $phone, $department, $new_role, $status, $target_id]);

    echo json_encode(["success" => true, "message" => "User account updated successfully."]);

} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>