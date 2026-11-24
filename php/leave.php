<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Authorization");

require_once 'db.php';

// Extract Token from Headers
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = '';

if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $token = $matches[1];
}

if (!$token) {
    echo json_encode(["success" => false, "message" => "Unauthorized access. Missing token."]);
    exit;
}

try {
    // Verify Token & Extract Secure Staff ID
    $stmt = $pdo->prepare("SELECT staff_id, role FROM users WHERE auth_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $activeUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeUser) {
        echo json_encode(["success" => false, "message" => "Session expired or invalid. Please log in again."]);
        exit;
    }

    // Lock user directly to the authenticated token
    $active_staff_id = $activeUser['staff_id'];
    $active_role = $activeUser['role'];

    $action      = $_GET['action'] ?? $_POST['action'] ?? '';
    $today       = date('Y-m-d');
    $currentTime = date('H:i');
    $curYear     = intval(date('Y'));

    // Helper: Ensure a quota record exists for a staff member for the given year
    function ensureLeaveEntitlement($pdo, $staff_id, $year, $role) {
        $stmtCheck = $pdo->prepare("SELECT id FROM leave_entitlements WHERE staff_id = ? AND year = ? LIMIT 1");
        $stmtCheck->execute([$staff_id, $year]);
        if (!$stmtCheck->fetch()) {
            $defaultAL = ($role === 'admin' || $role === 'super_admin') ? 0.0 : 14.0;
            $pdo->prepare("
                INSERT IGNORE INTO leave_entitlements 
                (staff_id, year, annual_total, annual_used, mc_total, mc_used, emergency_total, emergency_used) 
                VALUES (?, ?, ?, 0.0, 14.0, 0.0, 3.0, 0.0)
            ")->execute([$staff_id, $year, $defaultAL]);
        }
    }

    // 1. Staff / Admin: Fetch personal leave balances & history
    if ($action === 'get_staff_leave') {
        $staff_id = trim($_GET['staff_id'] ?? $active_staff_id);

        $stmtUser = $pdo->prepare("SELECT role, full_name, department FROM users WHERE staff_id = ? LIMIT 1");
        $stmtUser->execute([$staff_id]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
        $role = $user['role'] ?? 'staff';

        if ($role === 'super_admin') {
            echo json_encode([
                "success"      => true,
                "current_year" => $curYear,
                "role"         => $role,
                "exempt"       => true,
                "entitlement"  => null,
                "balances"     => ["annual_left" => 0, "mc_left" => 0, "emergency_left" => 0, "unpaid_taken" => 0],
                "history"      => []
            ]);
            exit;
        }

        ensureLeaveEntitlement($pdo, $staff_id, $curYear, $role);

        $stmtEnt = $pdo->prepare("SELECT * FROM leave_entitlements WHERE staff_id = ? AND year = ? LIMIT 1");
        $stmtEnt->execute([$staff_id, $curYear]);
        $entitlement = $stmtEnt->fetch(PDO::FETCH_ASSOC);

        // --- DYNAMIC UNPAID CALCULATION (Fixed to include both approved and rejected penalties) ---
        $stmtUnpaid = $pdo->prepare("SELECT COALESCE(SUM(total_days), 0) FROM leave_applications WHERE staff_id = ? AND deducted_from = 'unpaid' AND status IN ('approved', 'rejected') AND YEAR(start_date) = ?");
        $stmtUnpaid->execute([$staff_id, $curYear]);
        $unpaidTaken = floatval($stmtUnpaid->fetchColumn());

        $alLeft = max(0, floatval($entitlement['annual_total']) - floatval($entitlement['annual_used']));
        $mcLeft = max(0, floatval($entitlement['mc_total']) - floatval($entitlement['mc_used']));
        $elLeft = max(0, floatval($entitlement['emergency_total']) - floatval($entitlement['emergency_used']));

        $stmtHist = $pdo->prepare("
            SELECT 
                l.*,
                u.full_name AS reviewer_name,
                DATE_FORMAT(l.created_at, '%d/%m/%Y, %h:%i %p') AS formatted_created_at
            FROM leave_applications l
            LEFT JOIN users u ON l.reviewed_by = u.staff_id
            WHERE l.staff_id = ?
            ORDER BY l.id DESC
        ");
        $stmtHist->execute([$staff_id]);
        $history = $stmtHist->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            "success"      => true,
            "current_year" => $curYear,
            "role"         => $role,
            "entitlement"  => $entitlement,
            "balances"     => [
                "annual_left"    => $alLeft,
                "mc_left"        => $mcLeft,
                "emergency_left" => $elLeft,
                "unpaid_taken"   => $unpaidTaken
            ],
            "history"      => $history
        ]);
        exit;
    }

    // 2. Submit Leave Application 
    if ($action === 'apply_leave') {
        $staff_id   = $active_staff_id; // Securely locked
        $leave_type = trim($_POST['leave_type'] ?? 'annual');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date   = trim($_POST['end_date'] ?? '');
        $total_days = floatval($_POST['total_days'] ?? 1.0);
        $reason     = trim($_POST['reason'] ?? '');

        if (empty($start_date) || empty($end_date) || empty($reason)) {
            echo json_encode(["success" => false, "message" => "Please complete all required fields."]);
            exit;
        }

        if ($active_role === 'super_admin') {
            echo json_encode(["success" => false, "message" => "Super Admins do not need to submit formal leave applications."]);
            exit;
        }

        if ($active_role === 'admin' && $leave_type === 'annual') {
            echo json_encode(["success" => false, "message" => "Admins are entitled to MC and Emergency Leave only (0 Annual Leave)."]);
            exit;
        }

        $attachment_name = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/leave/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $orig_name = basename($_FILES['attachment']['name']);
            $file_ext  = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $allowed   = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];

            if (in_array($file_ext, $allowed)) {
                $clean_filename = time() . '_' . $staff_id . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($orig_name, PATHINFO_FILENAME)) . '.' . $file_ext;
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . $clean_filename)) {
                    $attachment_name = $clean_filename;
                }
            }
        }

        if ($leave_type === 'mc') {
            if (!$attachment_name) {
                echo json_encode(["success" => false, "message" => "Medical Leave (MC) strictly requires an attached clinic slip."]); exit;
            }
            if ($start_date === $today && $currentTime > '12:30') {
                echo json_encode(["success" => false, "message" => "Same-day MC must be submitted before 12:30 PM. Late submissions will cascade to Annual or Unpaid Leave."]); exit;
            }
        }

        if ($leave_type === 'annual' && $total_days > 2.0 && !$attachment_name) {
            echo json_encode(["success" => false, "message" => "Annual Leave exceeding 2 days requires a supporting attachment."]); exit;
        }

        $stmtOverlap = $pdo->prepare("
            SELECT id FROM leave_applications 
            WHERE staff_id = ? AND status IN ('pending', 'approved') AND NOT (end_date < ? OR start_date > ?) LIMIT 1
        ");
        $stmtOverlap->execute([$staff_id, $start_date, $end_date]);
        if ($stmtOverlap->fetch()) {
            echo json_encode(["success" => false, "message" => "You already have a pending or approved leave application overlapping these dates."]); exit;
        }

        $app_code = 'LV-' . date('y') . '-' . rand(1000, 9999);

        $stmtInsert = $pdo->prepare("
            INSERT INTO leave_applications (application_code, staff_id, leave_type, start_date, end_date, total_days, reason, attachment_name, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmtInsert->execute([$app_code, $staff_id, $leave_type, $start_date, $end_date, $total_days, $reason, $attachment_name]);

        echo json_encode(["success" => true, "message" => "Leave application submitted. Reference: " . $app_code]);
        exit;
    }

    // 3. Admin: Fetch applications queue 
    if ($action === 'admin_get_leave_queue') {
        if ($active_role !== 'admin' && $active_role !== 'super_admin') {
            echo json_encode(["success" => false, "message" => "Access denied."]); exit;
        }

        $stmt = $pdo->prepare("
            SELECT l.*, u.full_name AS staff_name, u.role AS applicant_role, u.department,
                   e.annual_total, e.annual_used, e.mc_total, e.mc_used, e.emergency_total, e.emergency_used,
                   r.full_name AS reviewer_name,
                   DATE_FORMAT(l.reviewed_at, '%d/%m/%Y, %h:%i %p') AS formatted_reviewed_at,
                   DATE_FORMAT(l.created_at, '%d/%m/%Y') AS formatted_applied_date
            FROM leave_applications l
            JOIN users u ON l.staff_id = u.staff_id
            LEFT JOIN leave_entitlements e ON l.staff_id = e.staff_id AND e.year = ?
            LEFT JOIN users r ON l.reviewed_by = r.staff_id
            ORDER BY l.id DESC
        ");
        $stmt->execute([$curYear]);
        $applications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtAllActiveUsers = $pdo->prepare("SELECT staff_id, role FROM users WHERE role IN ('staff', 'admin', 'intern') AND status = 'active'");
        $stmtAllActiveUsers->execute();
        while ($usr = $stmtAllActiveUsers->fetch(PDO::FETCH_ASSOC)) {
            ensureLeaveEntitlement($pdo, $usr['staff_id'], $curYear, $usr['role']);
        }

        // --- DYNAMIC UNPAID CALCULATION IN ADMIN ROSTER (Fixed to include rejected penalties) ---
        $stmtRoster = $pdo->prepare("
            SELECT u.staff_id, u.full_name AS staff_name, u.role AS applicant_role, u.department,
                   COALESCE(e.annual_total, IF(u.role = 'admin', 0.0, 14.0)) AS annual_total,
                   COALESCE(e.annual_used, 0.0) AS annual_used,
                   COALESCE(e.mc_total, 14.0) AS mc_total, COALESCE(e.mc_used, 0.0) AS mc_used,
                   COALESCE(e.emergency_total, 3.0) AS emergency_total, COALESCE(e.emergency_used, 0.0) AS emergency_used,
                   (
                       SELECT COALESCE(SUM(total_days), 0)
                       FROM leave_applications
                       WHERE staff_id = u.staff_id AND deducted_from = 'unpaid' AND status IN ('approved', 'rejected') AND YEAR(start_date) = ?
                   ) AS unpaid_taken
            FROM users u
            LEFT JOIN leave_entitlements e ON u.staff_id = e.staff_id AND e.year = ?
            WHERE u.role IN ('staff', 'admin', 'intern') AND u.status = 'active'
            ORDER BY u.role DESC, u.staff_id ASC
        ");
        $stmtRoster->execute([$curYear, $curYear]);
        $roster = $stmtRoster->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode(["success" => true, "current_year" => $curYear, "applications" => $applications, "roster" => $roster]);
        exit;
    }

    // 4. Admin / Super Admin: Review Application (Deduction & Cascade Protocol)
    if ($action === 'admin_review_leave') {
        if ($active_role !== 'admin' && $active_role !== 'super_admin') {
            echo json_encode(["success" => false, "message" => "Access denied."]); exit;
        }

        $app_id      = intval($_POST['app_id'] ?? 0);
        $decision    = trim($_POST['status'] ?? 'approved');
        $admin_reply = trim($_POST['admin_reply'] ?? '');
        $admin_id    = $active_staff_id;

        if (!$app_id || !in_array($decision, ['approved', 'rejected'])) {
            echo json_encode(["success" => false, "message" => "Invalid application or status."]); exit;
        }

        $stmtApp = $pdo->prepare("SELECT l.*, u.role AS applicant_role FROM leave_applications l JOIN users u ON l.staff_id = u.staff_id WHERE l.id = ? LIMIT 1");
        $stmtApp->execute([$app_id]);
        $app = $stmtApp->fetch(PDO::FETCH_ASSOC);

        if (!$app) {
            echo json_encode(["success" => false, "message" => "Application not found."]); exit;
        }

        if ($app['applicant_role'] === 'admin' && $active_role !== 'super_admin') {
            echo json_encode(["success" => false, "message" => "Unauthorized. Only Super Admins can verify an Admin's leave request."]); exit;
        }

        $days = floatval($app['total_days']); 
        $staff = $app['staff_id']; 
        $appYear = intval(substr($app['start_date'], 0, 4)); 
        $reqType = $app['leave_type'];

        ensureLeaveEntitlement($pdo, $staff, $appYear, $app['applicant_role']);

        $stmtBal = $pdo->prepare("SELECT * FROM leave_entitlements WHERE staff_id = ? AND year = ? LIMIT 1");
        $stmtBal->execute([$staff, $appYear]);
        $bal = $stmtBal->fetch(PDO::FETCH_ASSOC);

        $mcBal = $bal ? max(0, floatval($bal['mc_total']) - floatval($bal['mc_used'])) : 0;
        $elBal = $bal ? max(0, floatval($bal['emergency_total']) - floatval($bal['emergency_used'])) : 0;
        $alBal = $bal ? max(0, floatval($bal['annual_total']) - floatval($bal['annual_used'])) : 0;
        $deductedFrom = null;

        if ($decision === 'approved') {
            if ($reqType === 'mc') {
                if ($mcBal >= $days) { 
                    $deductedFrom = 'mc'; 
                    $pdo->prepare("UPDATE leave_entitlements SET mc_used = mc_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]); 
                } elseif ($alBal >= $days) { 
                    $deductedFrom = 'annual'; 
                    $pdo->prepare("UPDATE leave_entitlements SET annual_used = annual_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]); 
                } else { 
                    $deductedFrom = 'unpaid'; 
                }
            } elseif ($reqType === 'emergency') {
                if ($elBal >= $days) { 
                    $deductedFrom = 'emergency'; 
                    $pdo->prepare("UPDATE leave_entitlements SET emergency_used = emergency_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]); 
                } elseif ($alBal >= $days) { 
                    $deductedFrom = 'annual'; 
                    $pdo->prepare("UPDATE leave_entitlements SET annual_used = annual_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]); 
                } else { 
                    $deductedFrom = 'unpaid'; 
                }
            } elseif ($reqType === 'annual') {
                if ($alBal >= $days) { 
                    $deductedFrom = 'annual'; 
                    $pdo->prepare("UPDATE leave_entitlements SET annual_used = annual_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]); 
                } else { 
                    $deductedFrom = 'unpaid'; 
                }
            } else {
                $deductedFrom = 'unpaid'; 
            }
        } elseif ($decision === 'rejected') {
            if (($reqType === 'mc' || $reqType === 'emergency') && $alBal >= $days) {
                $deductedFrom = 'annual'; 
                $pdo->prepare("UPDATE leave_entitlements SET annual_used = annual_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]);
            } elseif ($reqType === 'mc' || $reqType === 'emergency') {
                $deductedFrom = 'unpaid'; 
            } elseif ($reqType === 'annual' && $app['start_date'] <= $today) {
                $deductedFrom = 'unpaid'; 
            }
        }

        $stmtUpdate = $pdo->prepare("UPDATE leave_applications SET status = ?, deducted_from = ?, admin_reply = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $stmtUpdate->execute([$decision, $deductedFrom, $admin_reply, $admin_id, $app_id]);

        $noticeMsg = "Application " . ucfirst($decision);
        if ($deductedFrom) $noticeMsg .= " (Deducted from: " . strtoupper($deductedFrom) . ")";

        echo json_encode(["success" => true, "message" => $noticeMsg]);
        exit;
    }

    // 5. Admin: Update Quota
    if ($action === 'admin_update_quota') {
        if ($active_role !== 'admin' && $active_role !== 'super_admin') {
            echo json_encode(["success" => false, "message" => "Access denied."]); exit;
        }

        $admin_id        = $active_staff_id;
        $target_staff_id = trim($_POST['target_staff_id'] ?? '');
        $annual_total    = floatval($_POST['annual_total'] ?? 14.0);
        $mc_total        = floatval($_POST['mc_total'] ?? 14.0);
        $emergency_total = floatval($_POST['emergency_total'] ?? 3.0);
        $target_year     = intval($_POST['year'] ?? $curYear);

        if (empty($target_staff_id)) {
            echo json_encode(["success" => false, "message" => "Target staff ID is required."]); exit;
        }

        $stmtTarget = $pdo->prepare("SELECT role FROM users WHERE staff_id = ? LIMIT 1");
        $stmtTarget->execute([$target_staff_id]);
        $targetRole = $stmtTarget->fetchColumn() ?: 'staff';

        if (($targetRole === 'admin' || $targetRole === 'super_admin') && $active_role !== 'super_admin') {
            echo json_encode(["success" => false, "message" => "Unauthorized. Only Super Admins can modify an Admin's quota."]); exit;
        }
        if ($targetRole === 'admin' || $targetRole === 'super_admin') $annual_total = 0.0;

        ensureLeaveEntitlement($pdo, $target_staff_id, $target_year, $targetRole);

        $stmt = $pdo->prepare("
            UPDATE leave_entitlements 
            SET annual_total = ?, mc_total = ?, emergency_total = ? 
            WHERE staff_id = ? AND year = ?
        ");
        $stmt->execute([$annual_total, $mc_total, $emergency_total, $target_staff_id, $target_year]);

        echo json_encode(["success" => true, "message" => "Quota updated for {$target_staff_id} ({$target_year})"]);
        exit;
    }

    // Admin / Super Admin: Smart delete leave
    if ($action === 'admin_delete_leave'){
        if ($active_role !== 'admin' && $active_role !== 'super_admin') {
            echo json_encode(["success" => false, "message" => "Access denied."]); exit;
        }

        $app_id = intval($_POST['app_id'] ?? 0);
        if(!$app_id){ echo json_encode(["success" => false, "message" => "Application ID required."]); exit; }

        $stmtApp = $pdo->prepare("SELECT l.*, u.role AS applicant_role FROM leave_applications l JOIN users u ON l.staff_id = u.staff_id WHERE l.id = ? LIMIT 1");
        $stmtApp->execute([$app_id]);
        $app = $stmtApp->fetch(PDO::FETCH_ASSOC);

        if (!$app) { echo json_encode(["success" => false, "message" => "Leave record not found."]); exit; }

        if($app['applicant_role'] === 'admin' && $active_role !== 'super_admin') {
            echo json_encode(["success" => false, "message" => "Only Super Admin can delete an Admin's leave record."]); exit;
        }

        $deductedFrom = $app['deducted_from'];
        $days = floatval($app['total_days']); $staff = $app['staff_id']; $appYear = intval(substr($app['start_date'], 0, 4));
        $refundedText = "";

        if (!empty($deductedFrom) && $deductedFrom !== 'unpaid' && $days > 0) {
            $pdo->prepare("UPDATE leave_entitlements SET {$deductedFrom}_used = GREATEST(0, {$deductedFrom}_used - ?) WHERE staff_id = ? AND year = ?")
                ->execute([$days, $staff, $appYear]);
            $refundedText = " ({$days} day(s) returned to " . strtoupper($deductedFrom) . " balance)";
        }

        if (!empty($app['attachment_name'])) {
            $filePath = __DIR__ . '/../uploads/leave/' . $app['attachment_name'];
            if (file_exists($filePath)) @unlink($filePath);
        }

        $pdo->prepare("DELETE FROM leave_applications WHERE id = ?")->execute([$app_id]);

        echo json_encode(["success" => true, "message" => "Leave record deleted" . $refundedText . "."]);
        exit;
    }

    // 6. Dashboard Ledger
    if ($action === 'get_today_roster') {
        if ($active_role !== 'admin' && $active_role !== 'super_admin') {
            echo json_encode(["success" => false, "message" => "Access denied."]); exit;
        }

        $stmtLeave = $pdo->prepare("
            SELECT l.staff_id, u.full_name, u.department, l.leave_type, l.deducted_from, l.reason
            FROM leave_applications l JOIN users u ON l.staff_id = u.staff_id
            WHERE (l.status = 'approved' OR (l.status = 'rejected' AND l.deducted_from IS NOT NULL)) AND ? BETWEEN l.start_date AND l.end_date
        ");
        $stmtLeave->execute([$today]);
        $onLeave = $stmtLeave->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtAtt = $pdo->prepare("
            SELECT a.staff_id, u.full_name, a.clock_in_time, a.clock_in_location, a.clock_out_time, a.status AS punch_status
            FROM attendance_records a JOIN users u ON a.staff_id = u.staff_id WHERE a.work_date = ?
        ");
        $stmtAtt->execute([$today]);
        $clockedIn = $stmtAtt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode(["success" => true, "today_date" => $today, "on_leave" => $onLeave, "clocked_in" => $clockedIn]);
        exit;
    }

    echo json_encode(["success" => false, "message" => "Invalid action specified."]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>