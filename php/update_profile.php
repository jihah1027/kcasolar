<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");
require_once 'db.php';

// Check if user log in and request is POST
if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Unauthorized access."]);
    exit;
}

$user_id = $_SESSION['user_id'];
$full_name = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$new_password = trim($_POST['new_password'] ?? '');

if (empty($full_name)) {
    echo json_encode(["success" => false, "message" => "Full Name cannot be empty."]);
    exit;
}

try {
    // 1. Check duplicate email
    if ($email !== '') {
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $checkStmt->execute([$email, $user_id]);
        if ($checkStmt->fetch()) {
            echo json_encode(["success" => false, "message" => "This email address is already in use by another staff member."]);
            exit;
        }
    }

    // 2. Perform update
    if (!empty($new_password)) {
        // Hash new password + update all fields
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        $updateStmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, password_hash = ? WHERE id = ?");
        $updateStmt->execute([$full_name, $email, $phone, $hashed_password, $user_id]);
    } else {
        // Only update full_name, email and phone if password field was left blank
        $updateStmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?");
        $updateStmt->execute([$full_name, $email, $phone, $user_id]);
    }

    // Update the session variable for the server-side as well
    $_SESSION['full_name'] = $full_name;

    echo json_encode(["success" => true, "message" => "Profile updated successfully!"]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>