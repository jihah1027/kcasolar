<?php
// php/submit_inquiry.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit;
}

// 1. Sanitize Form Inputs
$sender_name = trim($_POST['full_name'] ?? '');
$email       = trim($_POST['email'] ?? '');
$phone       = trim($_POST['contact_number'] ?? '');
$category    = trim($_POST['category'] ?? 'General Question');
$description = trim($_POST['description'] ?? '');

if (empty($sender_name) || empty($email) || empty($phone) || empty($description)) {
    echo json_encode(["success" => false, "message" => "Please complete all required fields."]);
    exit;
}

// 2. Generate unique Inquiry Code (e.g. INQ-2410)
$inquiry_code = 'INQ-' . rand(1000, 9999);

// 3. Handle File Upload (Optional)
$attachment_name = null;
$attachment_size = null;

if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    // Upload directory: root/uploads/
    $upload_dir = __DIR__ . '/../uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $original_name = basename($_FILES['attachment']['name']);
    $file_info = pathinfo($original_name);
    $file_ext = strtolower($file_info['extension'] ?? '');

    // Allow safe document extensions only
    $allowed_exts = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    if (in_array($file_ext, $allowed_exts)) {
        // Safe filename with timestamp
        $clean_basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $file_info['filename']);
        $unique_filename = time() . '_' . $clean_basename . '.' . $file_ext;
        $target_path = $upload_dir . $unique_filename;

        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
            $attachment_name = $unique_filename;
            $attachment_size = round($_FILES['attachment']['size'] / (1024 * 1024), 2) . ' MB';
        }
    }
}

// 4. Insert Record into website_inquiries table
try {
    $stmt = $pdo->prepare("
        INSERT INTO website_inquiries 
        (inquiry_code, sender_name, phone, email, category, description, attachment_name, attachment_size, status, received_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW())
    ");

    $stmt->execute([
        $inquiry_code,
        $sender_name,
        $phone,
        $email,
        $category,
        $description,
        $attachment_name,
        $attachment_size
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Thank you! Your inquiry has been submitted successfully.",
        "code" => $inquiry_code
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error: Unable to record your inquiry. Please try again."
    ]);
}
?>