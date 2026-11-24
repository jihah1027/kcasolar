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
    $stmt = $pdo->prepare("SELECT role FROM users WHERE auth_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $activeUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeUser || ($activeUser['role'] !== 'admin' && $activeUser['role'] !== 'super_admin')) {
        echo json_encode(["success" => false, "message" => "Access denied. Administrative privileges required."]);
        exit;
    }

    // Collect and sanitize form data
    $staff_id = trim($_POST['staff_id'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $role = trim($_POST['role'] ?? 'staff');
    $status = 'active';

    if (empty($staff_id) || empty($full_name) || empty($email) || empty($department)) {
        echo json_encode(["success" => false, "message" => "Please fill in all required fields."]);
        exit;
    }

    //  Regular admins from bypassing the UI to create other admins
    if (($role === 'admin' || $role === 'super_admin') && $activeUser['role'] !== 'super_admin') {
        echo json_encode(["success" => false, "message" => "Permission denied. Only Super Admins can assign executive roles."]);
        exit;
    }

    // Check duplicate ID or Email
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE UPPER(staff_id) = UPPER(?) OR email = ? LIMIT 1");
    $checkStmt->execute([$staff_id, $email]);
    if ($checkStmt->fetch()) {
        echo json_encode(["success" => false, "message" => "A user with this Staff ID or Email already exists."]);
        exit;
    }

    // Hash default password ("kca123")
    $defaultPasswordHash = password_hash('kca123', PASSWORD_BCRYPT);

    // Insert the new record
    $insertStmt = $pdo->prepare("
        INSERT INTO users (staff_id, full_name, email, phone, department, role, password_hash, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->execute([$staff_id, $full_name, $email, $phone, $department, $role, $defaultPasswordHash, $status]);

    echo json_encode(["success" => true, "message" => "User successfully registered."]);

} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>