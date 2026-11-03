<?php
// php/attendance.php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
header("Content-Type: application/json; charset=UTF-8");

require_once 'db.php';

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
    // 1. Get Status & History
    if ($action === 'get_status') {
        $todayPunch = null;
        $activeSitePerm = null;
        $todayOt = null;
        $history = [];

        try {
            $stmt = $pdo->prepare("SELECT * FROM attendance_records WHERE staff_id = ? AND work_date = ? LIMIT 1");
            $stmt->execute([$staff_id, $today]);
            $todayPunch = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {}

        try {
            $stmtPerm = $pdo->prepare("
                SELECT * FROM site_permissions 
                WHERE staff_id = ? AND work_date = ? AND status = 'active' 
                ORDER BY id DESC LIMIT 1
            ");
            $stmtPerm->execute([$staff_id, $today]);
            $activeSitePerm = $stmtPerm->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {}

        try {
            $stmtOt = $pdo->prepare("SELECT * FROM overtime_claims WHERE staff_id = ? AND claim_date = ? ORDER BY id DESC LIMIT 1");
            $stmtOt->execute([$staff_id, $today]);
            $todayOt = $stmtOt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {}

        try {
            $stmtHistory = $pdo->prepare("
                SELECT 
                    a.work_date, 
                    a.clock_in_time, 
                    a.clock_out_time, 
                    a.work_hours, 
                    a.ot_hours, 
                    a.status,
                    c.status AS ot_status
                FROM attendance_records a
                LEFT JOIN overtime_claims c ON a.staff_id = c.staff_id AND a.work_date = c.claim_date
                WHERE a.staff_id = ? 
                ORDER BY a.work_date DESC 
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

    // 2. Clock-In
    if ($action === 'clock_in') {
        $lat = $_POST['lat'] ?? null;
        $lng = $_POST['lng'] ?? null;
        $location = trim($_POST['location'] ?? 'Main Office');
        $status = trim($_POST['status'] ?? 'Active');
        $currentTime = date('H:i:s');

        $stmtCheck = $pdo->prepare("SELECT id FROM attendance_records WHERE staff_id = ? AND work_date = ? LIMIT 1");
        $stmtCheck->execute([$staff_id, $today]);
        if ($stmtCheck->fetch()) {
            echo json_encode(["success" => false, "message" => "You have already clocked in today."]);
            exit;
        }

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

    // 3. Clock-Out
    if ($action === 'clock_out') {
        $lat = $_POST['lat'] ?? null;
        $lng = $_POST['lng'] ?? null;
        $location = trim($_POST['location'] ?? 'Main Office');
        $currentTime = date('H:i:s');

        $stmt = $pdo->prepare("SELECT id, clock_in_time FROM attendance_records WHERE staff_id = ? AND work_date = ? LIMIT 1");
        $stmt->execute([$staff_id, $today]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record || empty($record['clock_in_time'])) {
            echo json_encode(["success" => false, "message" => "Cannot clock out without clocking in first."]);
            exit;
        }

        $timeIn = strtotime($record['clock_in_time']);
        $timeOut = strtotime($currentTime);
        $diffSeconds = max(0, $timeOut - $timeIn);
        $workedHours = round($diffSeconds / 3600, 2);
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

    // 4. Submit Overtime (OT) Request with Duplicate Guard & Custom Date Support
    if ($action === 'submit_ot') {
        $otHours = floatval($_POST['ot_hours'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $projectName = trim($_POST['project_name'] ?? 'Daily Operations OT');
        $claimCode = 'OT-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));

        // 1. Accepts custom date if submitted (admin modal), otherwise defaults to today (staff)
        $claimDate = trim($_POST['claim_date'] ?? $today);

        if ($otHours <= 0 || empty($reason)) {
            echo json_encode(["success" => false, "message" => "Please provide valid OT hours and task reason."]);
            exit;
        }

        // 2. Duplicate guard checks against $claimDate
        $stmtCheckOt = $pdo->prepare("SELECT id, status FROM overtime_claims WHERE staff_id = ? AND claim_date = ? LIMIT 1");
        $stmtCheckOt->execute([$staff_id, $claimDate]);
        $existingClaim = $stmtCheckOt->fetch(PDO::FETCH_ASSOC);

        if ($existingClaim) {
            echo json_encode([
                "success" => false, 
                "message" => "An OT claim for date {$claimDate} has already been submitted (Status: " . ucfirst($existingClaim['status']) . ")."
            ]);
            exit;
        }

        $hourlyRate = 15.00;
        $totalAmount = $otHours * $hourlyRate;

        // 3. Insert using $claimDate
        $stmtOt = $pdo->prepare("
            INSERT INTO overtime_claims 
            (claim_code, staff_id, claim_date, ot_hours, hourly_rate, total_amount, project_name, reason, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmtOt->execute([$claimCode, $staff_id, $claimDate, $otHours, $hourlyRate, $totalAmount, $projectName, $reason]);

        // 4. Sync ot_hours to attendance_records using $claimDate
        $stmtUpdateRecord = $pdo->prepare("UPDATE attendance_records SET ot_hours = ? WHERE staff_id = ? AND work_date = ?");
        $stmtUpdateRecord->execute([$otHours, $staff_id, $claimDate]);

        echo json_encode([
            "success" => true,
            "message" => "OT request submitted for review.",
            "claim_code" => $claimCode,
            "ot_hours" => $otHours
        ]);
        exit;
    }

    // 5. Admin: Fetch All Overtime Claims (With claimer_role included)
    if ($action === 'admin_get_ot') {
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.claim_code,
                c.staff_id,
                u.full_name AS staff_name,
                u.role AS claimer_role,
                c.claim_date,
                c.ot_hours,
                c.hourly_rate,
                c.total_amount,
                c.project_name,
                c.reason,
                c.status,
                c.reviewed_by,
                r.full_name AS reviewer_name,
                DATE_FORMAT(c.reviewed_at, '%d/%m/%Y, %h:%i %p') AS formatted_reviewed_at,
                a.clock_out_time,
                a.clock_out_location,
                a.clock_in_location AS att_location
            FROM overtime_claims c
            LEFT JOIN users u ON c.staff_id = u.staff_id
            LEFT JOIN users r ON c.reviewed_by = r.staff_id
            LEFT JOIN attendance_records a ON c.staff_id = a.staff_id AND c.claim_date = a.work_date
            ORDER BY c.id DESC
        ");
        $stmt->execute();
        $claims = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode(["success" => true, "claims" => $claims]);
        exit;
    }

    // 6. Admin: Review Overtime Claim (Hierarchy Guard Enforced)
    if ($action === 'admin_review_ot') {
        $claim_id = $_POST['claim_id'] ?? '';
        $status = $_POST['status'] ?? 'approved';
        $approved_hours = floatval($_POST['approved_hours'] ?? 0);
        $admin_id = trim($_POST['admin_id'] ?? $staff_id);

        $stmt = $pdo->prepare("SELECT * FROM overtime_claims WHERE id = ? LIMIT 1");
        $stmt->execute([$claim_id]);
        $claim = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$claim) {
            echo json_encode(["success" => false, "message" => "Claim record not found in database."]);
            exit;
        }

        $stmtClaimer = $pdo->prepare("SELECT role FROM users WHERE staff_id = ? LIMIT 1");
        $stmtClaimer->execute([$claim['staff_id']]);
        $claimer = $stmtClaimer->fetch(PDO::FETCH_ASSOC);
        $claimerRole = $claimer['role'] ?? 'staff';

        $stmtReviewer = $pdo->prepare("SELECT role FROM users WHERE staff_id = ? LIMIT 1");
        $stmtReviewer->execute([$admin_id]);
        $reviewer = $stmtReviewer->fetch(PDO::FETCH_ASSOC);
        $reviewerRole = $reviewer['role'] ?? '';

        // If claim belongs to an Admin, ONLY super_admin can approve/reject
        if ($claimerRole === 'admin' && $reviewerRole !== 'super_admin') {
            echo json_encode([
                "success" => false, 
                "message" => "Unauthorized. Only a Super Admin can approve or reject an Admin's overtime claim."
            ]);
            exit;
        }

        $hourly_rate = floatval($claim['hourly_rate'] ?? 15.00);
        $total_amount = ($status === 'approved') ? ($approved_hours * $hourly_rate) : 0.00;
        $final_hours = ($status === 'approved') ? $approved_hours : 0.00;

        $stmtUpdate = $pdo->prepare("
            UPDATE overtime_claims 
            SET status = ?, ot_hours = ?, total_amount = ?, reviewed_by = ?, reviewed_at = NOW() 
            WHERE id = ?
        ");
        $stmtUpdate->execute([$status, $final_hours, $total_amount, $admin_id, $claim['id']]);

        $stmtAtt = $pdo->prepare("UPDATE attendance_records SET ot_hours = ? WHERE staff_id = ? AND work_date = ?");
        $stmtAtt->execute([$final_hours, $claim['staff_id'], $claim['claim_date']]);

        echo json_encode([
            "success" => true, 
            "message" => "Claim successfully updated in database."
        ]);
        exit;
    }

    // 7. Dispatch List
    if ($action === 'admin_get_dispatch') {
        $stmt = $pdo->prepare("
            SELECT 
                u.staff_id,
                u.full_name,
                u.phone,
                u.department,
                p.id AS permission_id,
                p.site_location AS destination,
                p.project_name AS task_details,
                p.work_date,
                p.validity,
                COALESCE(p.status, 'office') AS dispatch_status,
                a.clock_in_time,
                a.clock_out_time,
                a.status AS punch_status
            FROM users u
            LEFT JOIN site_permissions p ON u.staff_id = p.staff_id 
                AND p.work_date = ? 
                AND p.status = 'active'
            LEFT JOIN attendance_records a ON u.staff_id = a.staff_id 
                AND a.work_date = ?
            WHERE u.role = 'staff' AND u.status = 'active'
            ORDER BY u.staff_id ASC
        ");
        $stmt->execute([$today, $today]);
        $staffList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode(["success" => true, "staff" => $staffList]);
        exit;
    }

    // 8. Save Dispatch
    if ($action === 'admin_save_dispatch') {
        $target_staff_id = trim($_POST['target_staff_id'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $task_details = trim($_POST['task_details'] ?? '');
        $work_date = trim($_POST['effective_date'] ?? $today);
        $validity = trim($_POST['validity'] ?? 'Today (Exp 23:59 pm)');
        $dispatch_action = $_POST['dispatch_action'] ?? 'grant';

        if (empty($target_staff_id)) {
            echo json_encode(["success" => false, "message" => "Target Staff ID is required."]);
            exit;
        }

        if ($dispatch_action === 'revoke') {
            $stmtRevoke = $pdo->prepare("UPDATE site_permissions SET status = 'revoked' WHERE staff_id = ? AND work_date = ?");
            $stmtRevoke->execute([$target_staff_id, $work_date]);
            echo json_encode(["success" => true, "message" => "Off-site permission revoked. Staff reverted to Main Office."]);
            exit;
        }

        $stmtDeactivate = $pdo->prepare("UPDATE site_permissions SET status = 'expired' WHERE staff_id = ? AND work_date = ?");
        $stmtDeactivate->execute([$target_staff_id, $work_date]);

        $stmtInsert = $pdo->prepare("
            INSERT INTO site_permissions 
            (staff_id, project_name, site_location, work_date, validity, status) 
            VALUES (?, ?, ?, ?, ?, 'active')
        ");
        $stmtInsert->execute([
            $target_staff_id,
            $task_details,
            $destination,
            $work_date,
            $validity
        ]);

        echo json_encode([
            "success" => true,
            "message" => "Off-site permission successfully granted for " . $target_staff_id . "!"
        ]);
        exit;
    }

    echo json_encode(["success" => false, "message" => "Invalid action specified."]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>