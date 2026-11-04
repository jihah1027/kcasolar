<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
header("Content-Type: application/json; charset=UTF-8");

require_once 'db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    // 1. Fetch all inquiries from database
    if ($action === 'get_inquiries') {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                inquiry_code,
                sender_name,
                phone,
                email,
                category,
                description,
                attachment_name,
                attachment_size,
                attachment_path,
                status,
                DATE_FORMAT(received_at, '%d/%m/%Y, %h:%i %p') AS formatted_received_at,
                DATE(received_at) AS received_date
            FROM website_inquiries 
            ORDER BY id DESC
        ");
        $stmt->execute();
        $inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            "success" => true,
            "inquiries" => $inquiries
        ]);
        exit;
    }

    // 2. Update inquiry status
    if ($action === 'update_status') {
        $id = intval($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');

        $allowed = ['new', 'review', 'replied', 'resolved'];
        if (!$id || !in_array($status, $allowed)) {
            echo json_encode(["success" => false, "message" => "Invalid inquiry ID or status."]);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE website_inquiries SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);

        echo json_encode([
            "success" => true,
            "message" => "Status updated successfully."
        ]);
        exit;
    }

    echo json_encode(["success" => false, "message" => "Invalid action specified."]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>