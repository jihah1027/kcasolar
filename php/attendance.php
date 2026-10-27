<?php
// php/attendance.php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
header("Content-Type: application/json; charset=UTF-8");

require_once 'db.php';

// Verify authentication (Session or passed staff_id)
$staff_id = $_SESSION['staff_id'] ?? $_POST['staff_id'] ?? $_GET['staff_id'] ?? '';
$staff_id = trim($staff_id);

if (empty($staff_id)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized. Please log in."]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$today = date('Y-m-d');

try {
    // 1. Get Status & Recent 7-Day History on Page Load
    if ($action === 'get_status') {
        $todayPunch = null;
        $activeSitePerm = null;
        $todayOt = null;
        $history = [];

        // 1. Check today's punch in attendance_records
        try {
            $stmt = $pdo->prepare("SELECT * FROM attendance_records WHERE staff_id = ? AND work_date = ? LIMIT 1");
            $stmt->execute([$staff_id, $today]);
            $todayPunch = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {}

        // 2. Check active site permission for today
        try {
            $stmtPerm = $pdo->prepare("SELECT * FROM site_permissions WHERE staff_id = ? AND work_date = ? AND status = 'active' LIMIT 1");
            $stmtPerm->execute([$staff_id, $today]);
            $activeSitePerm = $stmtPerm->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {}

        // 3. Check submitted OT for today
        try {
            $stmtOt = $pdo->prepare("SELECT * FROM overtime_claims WHERE staff_id = ? AND claim_date = ? LIMIT 1");
            $stmtOt->execute([$staff_id, $today]);
            $todayOt = $stmtOt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {}

        // 4. Fetch last 7 days of attendance history
        try {
            $stmtHistory = $pdo->prepare("
                SELECT work_date, clock_in_time, clock_out_time, work_hours, ot_hours, status 
                FROM attendance_records 
                WHERE staff_id = ? 
                ORDER BY work_date DESC 
                LIMIT 7
            ");
            $stmtHistory->execute([$staff_id]);
            $history = $stmtHistory->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {}

        echo json_encode([
            "success" => true,
            "today_punch" => $todayPunch,
            "site_permission" => $activeSitePerm,
            "today_ot" => $todayOt,
            "history" => $history
        ]);
        exit;
    }

    // 2. Clock-In Punch
    if ($action === 'clock_in') {
        $lat = $_POST['lat'] ?? null;
        $lng = $_POST['lng'] ?? null;
        $location = trim($_POST['location'] ?? 'Main Office');
        $status = trim($_POST['status'] ?? 'Active');
        $currentTime = date('H:i:s');

        // Check if already clocked in today
        $stmtCheck = $pdo->prepare("SELECT id FROM attendance_records WHERE staff_id = ? AND work_date = ? LIMIT 1");
        $stmtCheck->execute([$staff_id, $today]);
        if ($stmtCheck->fetch()) {
            echo json_encode(["success" => false, "message" => "You have already clocked in today."]);
            exit;
        }

        // Insert new clock-in
        $stmtInsert = $pdo->prepare("
            INSERT INTO attendance_records 
            (staff_id, work_date, clock_in_time, clock_in_lat, clock_in_lng, clock_in_location, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsert->execute([$staff_id, $today, $currentTime, $lat, $lng, $location, $status]);

        echo json_encode([
            "success" => true,
            "message" => "Clock-in recorded successfully!",
            "time_in" => date('H:i', strtotime($currentTime)),
            "location" => $location
        ]);
        exit;
    }

    // 3. Clock-Out Punch
    if ($action === 'clock_out') {
        $lat = $_POST['lat'] ?? null;
        $lng = $_POST['lng'] ?? null;
        $location = trim($_POST['location'] ?? 'Main Office');
        $currentTime = date('H:i:s');

        // Fetch today's clock-in to calculate worked hours
        $stmt = $pdo->prepare("SELECT id, clock_in_time FROM attendance_records WHERE staff_id = ? AND work_date = ? LIMIT 1");
        $stmt->execute([$staff_id, $today]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record || empty($record['clock_in_time'])) {
            echo json_encode(["success" => false, "message" => "Cannot clock out without clocking in first."]);
            exit;
        }

        // Calculate hours worked
        $timeIn = strtotime($record['clock_in_time']);
        $timeOut = strtotime($currentTime);
        $diffSeconds = max(0, $timeOut - $timeIn);
        $workedHours = round($diffSeconds / 3600, 2);

        // Determine punch status
        $finalStatus = ($location === 'Main Office') ? 'Office' : 'Site';

        $stmtUpdate = $pdo->prepare("
            UPDATE attendance_records 
            SET clock_out_time = ?, clock_out_lat = ?, clock_out_lng = ?, clock_out_location = ?, work_hours = ?, status = ? 
            WHERE id = ?
        ");
        $stmtUpdate->execute([$currentTime, $lat, $lng, $location, $workedHours, $finalStatus, $record['id']]);

        echo json_encode([
            "success" => true,
            "message" => "Clock-out recorded successfully!",
            "time_out" => date('H:i', strtotime($currentTime)),
            "worked_hours" => $workedHours
        ]);
        exit;
    }

    // 4. Submit Overtime (OT) Request
    if ($action === 'submit_ot') {
        $otHours = floatval($_POST['ot_hours'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $projectName = trim($_POST['project_name'] ?? 'Daily Operations OT');
        $claimCode = 'OT-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));

        if ($otHours <= 0 || empty($reason)) {
            echo json_encode(["success" => false, "message" => "Please provide valid OT hours and task reason."]);
            exit;
        }

        $hourlyRate = 15.00;
        $totalAmount = $otHours * $hourlyRate;

        // Insert into overtime_claims
        $stmtOt = $pdo->prepare("
            INSERT INTO overtime_claims 
            (claim_code, staff_id, claim_date, ot_hours, hourly_rate, total_amount, project_name, reason, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmtOt->execute([$claimCode, $staff_id, $today, $otHours, $hourlyRate, $totalAmount, $projectName, $reason]);

        // Also update ot_hours on today's attendance record if present
        $stmtUpdateRecord = $pdo->prepare("UPDATE attendance_records SET ot_hours = ? WHERE staff_id = ? AND work_date = ?");
        $stmtUpdateRecord->execute([$otHours, $staff_id, $today]);

        echo json_encode([
            "success" => true,
            "message" => "OT request submitted for review.",
            "claim_code" => $claimCode,
            "ot_hours" => $otHours
        ]);
        exit;
    }

    echo json_encode(["success" => false, "message" => "Invalid action specified."]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>