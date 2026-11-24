<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Authorization");

require_once 'db.php';

// Extract Token from Headers (Bulletproof fallback for cPanel/Nginx/Apache)
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
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
    // Verify Token & Admin Privileges
    $stmt = $pdo->prepare("SELECT role FROM users WHERE auth_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $activeUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeUser || ($activeUser['role'] !== 'admin' && $activeUser['role'] !== 'super_admin')) {
        echo json_encode(["success" => false, "message" => "Access denied. Administrative privileges required."]);
        exit;
    }

    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // Fetch all inquiries from database
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

    // Update inquiry status
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

    // Smart Delete Inquiry (Removes record + cleans up attached file)
    if ($action === 'delete_inquiry') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(["success" => false, "message" => "Invalid inquiry ID."]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT attachment_name, attachment_path FROM website_inquiries WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(["success" => false, "message" => "Inquiry record not found."]);
            exit;
        }

        // Check both attachment_path and attachment_name in uploads/inquiries/ and uploads/
        $filesToCheck = [];
        if (!empty($row['attachment_path'])) {
            $filesToCheck[] = __DIR__ . '/../' . ltrim($row['attachment_path'], './');
            $filesToCheck[] = __DIR__ . '/../uploads/inquiries/' . basename($row['attachment_path']);
            $filesToCheck[] = __DIR__ . '/../uploads/' . basename($row['attachment_path']);
        }
        if (!empty($row['attachment_name'])) {
            $filesToCheck[] = __DIR__ . '/../uploads/inquiries/' . basename($row['attachment_name']);
            $filesToCheck[] = __DIR__ . '/../uploads/' . basename($row['attachment_name']);
        }

        foreach (array_unique($filesToCheck) as $filePath) {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        $pdo->prepare("DELETE FROM website_inquiries WHERE id = ?")->execute([$id]);

        echo json_encode([
            "success" => true,
            "message" => "Inquiry and attached file deleted permanently."
        ]);
        exit;
    }

    echo json_encode(["success" => false, "message" => "Invalid action specified."]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>