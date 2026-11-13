<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");
require_once 'db.php';

//Check if user log in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Please log in first."]);
    exit;
}

try {
    // Fetch user's data bassed on id
    $stmt = $pdo->prepare("SELECT staff_id, full_name, role, department, email, phone FROM  users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo json_encode(["success" => true, "user" => $user]);
    } else {
        echo json_encode(["success" => false, "message" => "User account not found."]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message"=> "Database connection error."]);
}
?>