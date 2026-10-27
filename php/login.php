<?php
// php/login.php
session_start(); // 1. Starts server-side session for Attendance & OT tracking

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

require_once 'db.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false, 
        "message" => "Method not allowed. Please submit the login form."
    ]);
    exit;
}

// 1. Get submitted ID and Password (supports FormData and JSON payload)
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
    // 2. Query user from database (Case-insensitive ID check)
    $stmt = $pdo->prepare("SELECT id, staff_id, full_name, email, phone, password_hash, role, department, status FROM users WHERE UPPER(staff_id) = UPPER(?) LIMIT 1");
    $stmt->execute([$staff_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 3. Verify user exists
    if (!$user) {
        echo json_encode([
            "success" => false, 
            "message" => "Account not found. Please check your Staff ID."
        ]);
        exit;
    }

    // 4. Check if account is active
    if (isset($user['status']) && $user['status'] !== 'active') {
        echo json_encode([
            "success" => false, 
            "message" => "This account is inactive. Please contact management."
        ]);
        exit;
    }

    // 5. Verify Password (supports bcrypt hash or direct string for database testing)
    $isPasswordCorrect = password_verify($password, $user['password_hash']) || ($password === $user['password_hash']);

    if (!$isPasswordCorrect) {
        echo json_encode([
            "success" => false, 
            "message" => "Incorrect password. Please try again."
        ]);
        exit;
    }

    // 6. Establish secure server-side session for Attendance punches
    $_SESSION['user_id']     = $user['id'];
    $_SESSION['staff_id']    = $user['staff_id'];
    $_SESSION['full_name']   = $user['full_name'];
    $_SESSION['role']        = $user['role'];
    $_SESSION['department']  = $user['department'] ?? '';

    // 7. Route to destination inside kcaconnect/
    $redirectUrl = ($user['role'] === 'admin') ? 'admin-dashboard.html' : 'staff-attendance.html';

    // 8. Return JSON response
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