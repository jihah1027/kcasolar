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

// Reject if no token is found
if (!$token) {
    echo json_encode(["success" => false, "message" => "Unauthorized access. Missing authentication token."]);
    exit;
}

try {
    // Verify Token and get the active user's ID
    $stmt = $pdo->prepare("SELECT id FROM users WHERE auth_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $activeUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeUser) {
        echo json_encode(["success" => false, "message" => "Session expired or invalid. Please log in again."]);
        exit;
    }

    $user_id = $activeUser['id'];

    // Collect Form Data
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $new_password = $_POST['new_password'] ?? '';

    // Basic Validation
    if (empty($full_name) || empty($email)) {
        echo json_encode(["success" => false, "message" => "Full Name and Email are required fields."]);
        exit;
    }

    // Check if they are updating their password
    if (!empty($new_password)) {
        // Hash the new password
        $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
        
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET full_name = ?, email = ?, phone = ?, password_hash = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$full_name, $email, $phone, $password_hash, $user_id]);
    } else {
        // Update without touching the password
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET full_name = ?, email = ?, phone = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$full_name, $email, $phone, $user_id]);
    }

    echo json_encode(["success" => true, "message" => "Your profile has been successfully updated!"]);

} catch (PDOException $e) {
    // Catch duplicate email errors or generic DB errors
    if ($e->getCode() == 23000) {
        echo json_encode(["success" => false, "message" => "That email address is already in use by another account."]);
    } else {
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
}
?>