<?php
// php/login.php
session_start();

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false, 
        "message" => "Method not allowed. Please submit the login form."
    ]);
    exit;
}

$staff_id = trim($_POST['staff_id'] ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($staff_id) || empty($password)) {
    $input = json_decode(file_get_contents("php://input"), true);
    $staff_id = trim($input['staff_id'] ?? '');
    $password = trim($input['password'] ?? '');
}

if (empty($staff_id) || empty($password)) {
    echo json_encode([
        "success" => false, 
        "message" => "Please enter both Staff ID and Password."
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, staff_id, full_name, email, phone, password_hash, role, department, status FROM users WHERE UPPER(staff_id) = UPPER(?) LIMIT 1");
    $stmt->execute([$staff_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode([
            "success" => false, 
            "message" => "Account not found. Please check your Staff ID."
        ]);
        exit;
    }

    if (isset($user['status']) && $user['status'] !== 'active') {
        echo json_encode([
            "success" => false, 
            "message" => "This account is inactive. Please contact management."
        ]);
        exit;
    }

    // Verify Password & Auto-Migrate Plain Text to Bcrypt Hash
    $isPasswordCorrect = false;

    if (password_verify($password, $user['password_hash'])) {
        $isPasswordCorrect = true;
    } elseif ($password === $user['password_hash']) {
        $isPasswordCorrect = true;
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $updateStmt->execute([$newHash, $user['id']]);
    }

    if (!$isPasswordCorrect) {
        echo json_encode([
            "success" => false, 
            "message" => "Incorrect password. Please try again."
        ]);
        exit;
    }

    $_SESSION['user_id']     = $user['id'];
    $_SESSION['staff_id']    = $user['staff_id'];
    $_SESSION['full_name']   = $user['full_name'];
    $_SESSION['role']        = $user['role'];
    $_SESSION['department']  = $user['department'] ?? '';

    $redirectUrl = ($user['role'] === 'admin' || $user['role'] === 'super_admin') ? "admin-dashboard.html" : "staff-attendance.html";

    echo json_encode([
        "success" => true,
        "message" => "Login successful!",
        "redirect" => $redirectUrl,
        "user" => [
            "id"         => $user['staff_id'],
            "name"       => $user['full_name'],
            "role"       => $user['role'],
            "email"      => $user['email'],
            "phone"      => $user['phone'] ?? '',
            "department" => $user['department'] ?? ''
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => "Database connection error: " . $e->getMessage()
    ]);
}
?>