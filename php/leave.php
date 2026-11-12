<?php
// php/leave.php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
header("Content-Type: application/json; charset=UTF-8");

require_once 'db.php';

$action      = $_GET['action'] ?? $_POST['action'] ?? '';
$today       = date('Y-m-d');
$currentTime = date('H:i');
$curYear     = intval(date('Y'));

try {
    // 1. Staff / Admin: Fetch personal leave balances & history
    if ($action === 'get_staff_leave') {
        $staff_id = trim($_GET['staff_id'] ?? '');
        if (empty($staff_id)) {
            echo json_encode(["success" => false, "message" => "Staff ID is required."]);
            exit;
        }

        // Fetch user profile and role
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
                "balances"     => [
                    "annual_left"    => 0,
                    "mc_left"        => 0,
                    "emergency_left" => 0,
                    "unpaid_taken"   => 0
                ],
                "history"      => []
            ]);
            exit;
        }

        // Fetch current year entitlement
        $stmtEnt = $pdo->prepare("SELECT * FROM leave_entitlements WHERE staff_id = ? AND year = ? LIMIT 1");
        $stmtEnt->execute([$staff_id, $curYear]);
        $entitlement = $stmtEnt->fetch(PDO::FETCH_ASSOC);

        // Auto-refresh / initialize if first time this year
        if (!$entitlement) {
            $prevYear = $curYear - 1;
            $stmtPrev = $pdo->prepare("SELECT annual_total, mc_total, emergency_total FROM leave_entitlements WHERE staff_id = ? AND year = ? LIMIT 1");
            $stmtPrev->execute([$staff_id, $prevYear]);
            $prevQuota = $stmtPrev->fetch(PDO::FETCH_ASSOC);

            // Inherit promoted quotas if available; otherwise apply role baseline
            $defaultAL      = ($role === 'admin') ? 0.0 : 14.0;
            $annualTotal    = $prevQuota ? floatval($prevQuota['annual_total']) : $defaultAL;
            $mcTotal        = $prevQuota ? floatval($prevQuota['mc_total']) : 14.0;
            $emergencyTotal = $prevQuota ? floatval($prevQuota['emergency_total']) : 3.0;

            $pdo->prepare("
                INSERT IGNORE INTO leave_entitlements 
                (staff_id, year, annual_total, annual_used, mc_total, mc_used, emergency_total, emergency_used, unpaid_used) 
                VALUES (?, ?, ?, 0.0, ?, 0.0, ?, 0.0, 0.0)
            ")->execute([$staff_id, $curYear, $annualTotal, $mcTotal, $emergencyTotal]);

            $entitlement = [
                "annual_total"    => $annualTotal,
                "annual_used"     => 0.0,
                "mc_total"        => $mcTotal,
                "mc_used"         => 0.0,
                "emergency_total" => $emergencyTotal,
                "emergency_used"  => 0.0,
                "unpaid_used"     => 0.0
            ];
        }

        // Calculate available balances
        $alLeft = max(0, floatval($entitlement['annual_total']) - floatval($entitlement['annual_used']));
        $mcLeft = max(0, floatval($entitlement['mc_total']) - floatval($entitlement['mc_used']));
        $elLeft = max(0, floatval($entitlement['emergency_total']) - floatval($entitlement['emergency_used']));

        // Fetch application history
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
                "unpaid_taken"   => floatval($entitlement['unpaid_used'])
            ],
            "history"      => $history
        ]);
        exit;
    }

    // 2. Submit Leave Application (Staff & Admin)
    if ($action === 'apply_leave') {
        $staff_id   = trim($_POST['staff_id'] ?? '');
        $leave_type = trim($_POST['leave_type'] ?? 'annual');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date   = trim($_POST['end_date'] ?? '');
        $total_days = floatval($_POST['total_days'] ?? 1.0);
        $reason     = trim($_POST['reason'] ?? '');

        if (empty($staff_id) || empty($start_date) || empty($end_date) || empty($reason)) {
            echo json_encode(["success" => false, "message" => "Please complete all required fields."]);
            exit;
        }

        // Check applicant role
        $stmtRole = $pdo->prepare("SELECT role FROM users WHERE staff_id = ? LIMIT 1");
        $stmtRole->execute([$staff_id]);
        $applicant = $stmtRole->fetch(PDO::FETCH_ASSOC);
        $applicantRole = $applicant['role'] ?? 'staff';

        // Super Admin exempt
        if ($applicantRole === 'super_admin') {
            echo json_encode([
                "success" => false, 
                "message" => "Super Admins do not need to submit formal leave applications."
            ]);
            exit;
        }

        // Admins: MC & Emergency only
        if ($applicantRole === 'admin' && $leave_type === 'annual') {
            echo json_encode([
                "success" => false, 
                "message" => "Admins are entitled to MC and Emergency Leave only (0 Annual Leave)."
            ]);
            exit;
        }

        // File upload handling
        $attachment_name = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/leave/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

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

        // MC Validation: Clinic slip mandatory & 12:30 PM cutoff for same-day claims
        if ($leave_type === 'mc') {
            if (!$attachment_name) {
                echo json_encode(["success" => false, "message" => "Medical Leave (MC) strictly requires an attached clinic slip."]);
                exit;
            }
            if ($start_date === $today && $currentTime > '12:30') {
                echo json_encode(["success" => false, "message" => "Same-day MC must be submitted before 12:30 PM. Late submissions will cascade to Annual or Unpaid Leave."]);
                exit;
            }
        }

        // Annual Leave Validation: > 2 days requires attachment
        if ($leave_type === 'annual' && $total_days > 2.0 && !$attachment_name) {
            echo json_encode([
                "success" => false, 
                "message" => "Annual Leave exceeding 2 days requires a supporting attachment."
            ]);
            exit;
        }

        // Prevent overlapping applications
        $stmtOverlap = $pdo->prepare("
            SELECT id FROM leave_applications 
            WHERE staff_id = ? 
              AND status IN ('pending', 'approved') 
              AND NOT (end_date < ? OR start_date > ?)
            LIMIT 1
        ");
        $stmtOverlap->execute([$staff_id, $start_date, $end_date]);
        if ($stmtOverlap->fetch()) {
            echo json_encode([
                "success" => false, 
                "message" => "You already have a pending or approved leave application overlapping these dates."
            ]);
            exit;
        }

        $app_code = 'LV-' . date('y') . '-' . rand(1000, 9999);

        $stmtInsert = $pdo->prepare("
            INSERT INTO leave_applications 
            (application_code, staff_id, leave_type, start_date, end_date, total_days, reason, attachment_name, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmtInsert->execute([
            $app_code,
            $staff_id,
            $leave_type,
            $start_date,
            $end_date,
            $total_days,
            $reason,
            $attachment_name
        ]);

        echo json_encode([
            "success" => true, 
            "message" => "Leave application submitted. Reference: " . $app_code
        ]);
        exit;
    }

    // 3. Admin: Fetch applications queue 
    if ($action === 'admin_get_leave_queue') {
        $stmt = $pdo->prepare("
            SELECT 
                l.*,
                u.full_name AS staff_name,
                u.role AS applicant_role,
                u.department,
                e.annual_total,
                e.annual_used,
                e.mc_total,
                e.mc_used,
                e.emergency_total,
                e.emergency_used,
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

        // Fetch roster of all staff & admins for the Quota Settings
        $stmtRoster = $pdo->prepare("
            SELECT 
                u.staff_id,
                u.full_name AS staff_name,
                u.role AS applicant_role,
                u.department,
                COALESCE(e.annual_total, IF(u.role = 'admin', 0.0, 14.0)) AS annual_total,
                COALESCE(e.annual_used, 0.0) AS annual_used,
                COALESCE(e.mc_total, 14.0) AS mc_total,
                COALESCE(e.mc_used, 0.0) AS mc_used,
                COALESCE(e.emergency_total, 3.0) AS emergency_total,
                COALESCE(e.emergency_used, 0.0) AS emergency_used,
                COALESCE(e.unpaid_used, 0.0) AS unpaid_used
            FROM users u
            LEFT JOIN leave_entitlements e ON u.staff_id = e.staff_id AND e.year = ?
            WHERE u.role IN ('staff', 'admin')
            ORDER BY u.role DESC, u.staff_id ASC
        ");
        $stmtRoster->execute([$curYear]);
        $roster = $stmtRoster->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            "success"      => true,
            "current_year" => $curYear,
            "applications" => $applications,
            "roster"       => $roster
        ]);
        exit;
    }

    // 4. Admin / Super Admin: Review Application (Deduction)
    if ($action === 'admin_review_leave') {
        $app_id      = intval($_POST['app_id'] ?? 0);
        $decision    = trim($_POST['status'] ?? 'approved');
        $admin_reply = trim($_POST['admin_reply'] ?? '');
        $admin_id    = trim($_POST['admin_id'] ?? '');

        if (!$app_id || !in_array($decision, ['approved', 'rejected'])) {
            echo json_encode(["success" => false, "message" => "Invalid application or status."]);
            exit;
        }

        // Fetch application details & applicant role
        $stmtApp = $pdo->prepare("
            SELECT l.*, u.role AS applicant_role 
            FROM leave_applications l 
            JOIN users u ON l.staff_id = u.staff_id 
            WHERE l.id = ? LIMIT 1
        ");
        $stmtApp->execute([$app_id]);
        $app = $stmtApp->fetch(PDO::FETCH_ASSOC);

        if (!$app) {
            echo json_encode(["success" => false, "message" => "Application not found."]);
            exit;
        }

        // Fetch reviewer role
        $stmtRev = $pdo->prepare("SELECT role FROM users WHERE staff_id = ? LIMIT 1");
        $stmtRev->execute([$admin_id]);
        $reviewer = $stmtRev->fetch(PDO::FETCH_ASSOC);
        $reviewerRole = $reviewer['role'] ?? 'admin';

        // Admin applications verified strictly by Super Admin
        if ($app['applicant_role'] === 'admin' && $reviewerRole !== 'super_admin') {
            echo json_encode([
                "success" => false, 
                "message" => "Unauthorized. Only Super Admins (Hisham/Nadi) can verify an Admin's leave request."
            ]);
            exit;
        }

        $days    = floatval($app['total_days']);
        $staff   = $app['staff_id'];
        $appYear = intval(substr($app['start_date'], 0, 4));
        $reqType = $app['leave_type'];

        // Live balances
        $stmtBal = $pdo->prepare("SELECT * FROM leave_entitlements WHERE staff_id = ? AND year = ? LIMIT 1");
        $stmtBal->execute([$staff, $appYear]);
        $bal = $stmtBal->fetch(PDO::FETCH_ASSOC);

        $mcBal = $bal ? max(0, floatval($bal['mc_total']) - floatval($bal['mc_used'])) : 0;
        $elBal = $bal ? max(0, floatval($bal['emergency_total']) - floatval($bal['emergency_used'])) : 0;
        $alBal = $bal ? max(0, floatval($bal['annual_total']) - floatval($bal['annual_used'])) : 0;

        $deductedFrom = null;

        // DEDUCTION
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
                    $pdo->prepare("UPDATE leave_entitlements SET unpaid_used = unpaid_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]);
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
                    $pdo->prepare("UPDATE leave_entitlements SET unpaid_used = unpaid_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]);
                }
            } elseif ($reqType === 'annual') {
                if ($alBal >= $days) {
                    $deductedFrom = 'annual';
                    $pdo->prepare("UPDATE leave_entitlements SET annual_used = annual_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]);
                } else {
                    $deductedFrom = 'unpaid';
                    $pdo->prepare("UPDATE leave_entitlements SET unpaid_used = unpaid_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]);
                }
            } else {
                $deductedFrom = 'unpaid';
                $pdo->prepare("UPDATE leave_entitlements SET unpaid_used = unpaid_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]);
            }
        } elseif ($decision === 'rejected') {
            if ($reqType === 'mc' || $reqType === 'emergency') {
                if ($alBal >= $days) {
                    $deductedFrom = 'annual';
                    $pdo->prepare("UPDATE leave_entitlements SET annual_used = annual_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]);
                } else {
                    $deductedFrom = 'unpaid';
                    $pdo->prepare("UPDATE leave_entitlements SET unpaid_used = unpaid_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]);
                }
            } elseif ($reqType === 'annual') {
                if ($app['start_date'] <= $today) {
                    $deductedFrom = 'unpaid';
                    $pdo->prepare("UPDATE leave_entitlements SET unpaid_used = unpaid_used + ? WHERE staff_id = ? AND year = ?")->execute([$days, $staff, $appYear]);
                } else {
                    $deductedFrom = null;
                }
            } else {
                $deductedFrom = null;
            }
        }

        // Commit update with deduction source
        $stmtUpdate = $pdo->prepare("
            UPDATE leave_applications 
            SET status = ?, deducted_from = ?, admin_reply = ?, reviewed_by = ?, reviewed_at = NOW() 
            WHERE id = ?
        ");
        $stmtUpdate->execute([$decision, $deductedFrom, $admin_reply, $admin_id, $app_id]);

        $noticeMsg = "Application " . ucfirst($decision);
        if ($deductedFrom) {
            $noticeMsg .= " (Deducted from: " . strtoupper($deductedFrom) . ")";
        }

        echo json_encode([
            "success" => true, 
            "message" => $noticeMsg
        ]);
        exit;
    }

    // 5. Admin: Update Quota (With Permission Validation)
    if ($action === 'admin_update_quota') {
        $admin_id        = trim($_POST['admin_id'] ?? '');
        $target_staff_id = trim($_POST['target_staff_id'] ?? '');
        $annual_total    = floatval($_POST['annual_total'] ?? 14.0);
        $mc_total        = floatval($_POST['mc_total'] ?? 14.0);
        $emergency_total = floatval($_POST['emergency_total'] ?? 3.0);
        $target_year     = intval($_POST['year'] ?? $curYear);

        if (empty($target_staff_id) || empty($admin_id)) {
            echo json_encode(["success" => false, "message" => "Target staff ID and Admin ID are required."]);
            exit;
        }

        // Verify who is making the update
        $stmtRev = $pdo->prepare("SELECT role FROM users WHERE staff_id = ? LIMIT 1");
        $stmtRev->execute([$admin_id]);
        $reviewer = $stmtRev->fetch(PDO::FETCH_ASSOC);
        $reviewerRole = $reviewer['role'] ?? 'admin';

        // Check target's role
        $stmtTarget = $pdo->prepare("SELECT role FROM users WHERE staff_id = ? LIMIT 1");
        $stmtTarget->execute([$target_staff_id]);
        $target = $stmtTarget->fetch(PDO::FETCH_ASSOC);
        $targetRole = $target['role'] ?? 'staff';

        // Permission Rule: Regular Admins cannot modify an Admin's quota
        if ($targetRole === 'admin' || $targetRole === 'super_admin') {
            if ($reviewerRole !== 'super_admin') {
                echo json_encode([
                    "success" => false, 
                    "message" => "Unauthorized. Only Super Admins (Hisham/Nadi) can modify an Admin's quota."
                ]);
                exit;
            }
            // Admin must always have 0.0 Annual Leave
            $annual_total = 0.0;
        }

        $stmt = $pdo->prepare("
            INSERT INTO leave_entitlements (staff_id, year, annual_total, mc_total, emergency_total) 
            VALUES (?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                annual_total    = VALUES(annual_total), 
                mc_total        = VALUES(mc_total),
                emergency_total = VALUES(emergency_total)
        ");
        $stmt->execute([$target_staff_id, $target_year, $annual_total, $mc_total, $emergency_total]);

        echo json_encode([
            "success" => true, 
            "message" => "Quota updated for {$target_staff_id} ({$target_year})"
        ]);
        exit;
    }

    // Admin / Super Admin: Smart delete leave with quota refund
    if ($action === 'admin_delete_leave'){
        $app_id = intval($_POST['app_id'] ?? 0);
        $admin_id = trim($_POST['admin_id'] ?? '');

        if(!$app_id || empty($admin_id)){
            echo json_encode(["success" => false, "message" => "Application ID and Admin ID are required."]);
            exit;
        }

        //Fetch application details and applicant role
        $stmtApp = $pdo->prepare("
            SELECT l.*, u.role AS applicant_role 
            FROM leave_applications l 
            JOIN users u ON l.staff_id = u.staff_id 
            WHERE l.id = ? LIMIT 1
        ");
        $stmtApp->execute([$app_id]);
        $app = $stmtApp->fetch(PDO::FETCH_ASSOC);

        if (!$app) {
            echo json_encode(["success" => false, "message" => "Leave record not found."]);
            exit;
        }

        //Check who is deleting
        $stmtRev = $pdo->prepare("SELECT role FROM users WHERE staff_id = ? LIMIT 1");
        $stmtRev->execute([$admin_id]);
        $reviewerRole = $reviewer['role'] ?? 'admin';

        // Only Super Admin can delete Admin leave record
        if($app['applicant_role'] === 'admin' && $reviewerRole !== 'super_admin') {
            echo json_encode([
                "success" => false,
                "message" => "Only Super Admin can delete an Admin's leave reocrd."
            ]);
            exit;
        }

        // 1. Refund deducted days back into the employee's balance if already deducted
        $deductedFrom = $app['deducted_from'];
        $days         = floatval($app['total_days']);
        $staff        = $app['staff_id'];
        $appYear      = intval(substr($app['start_date'], 0, 4));
        $refundedText = "";

        if (!empty($deductedFrom) && $days > 0) {
            if ($deductedFrom === 'mc') {
                $pdo->prepare("UPDATE leave_entitlements SET mc_used = GREATEST(0, mc_used - ?) WHERE staff_id = ? AND year = ?")
                    ->execute([$days, $staff, $appYear]);
            } elseif ($deductedFrom === 'emergency') {
                $pdo->prepare("UPDATE leave_entitlements SET emergency_used = GREATEST(0, emergency_used - ?) WHERE staff_id = ? AND year = ?")
                    ->execute([$days, $staff, $appYear]);
            } elseif ($deductedFrom === 'annual') {
                $pdo->prepare("UPDATE leave_entitlements SET annual_used = GREATEST(0, annual_used - ?) WHERE staff_id = ? AND year = ?")
                    ->execute([$days, $staff, $appYear]);
            } elseif ($deductedFrom === 'unpaid') {
                $pdo->prepare("UPDATE leave_entitlements SET unpaid_used = GREATEST(0, unpaid_used - ?) WHERE staff_id = ? AND year = ?")
                    ->execute([$days, $staff, $appYear]);
            }
            $refundedText = " ({$days} day(s) returned to " . strtoupper($deductedFrom) . " balance)";
        }

        // 2. Remove attached clinic slip / document file from server storage
        if (!empty($app['attachment_name'])) {
            $filePath = __DIR__ . '/../uploads/leave/' . $app['attachment_name'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        // 3. Delete the record cleanly
        $pdo->prepare("DELETE FROM leave_applications WHERE id = ?")->execute([$app_id]);

        echo json_encode([
            "success" => true,
            "message" => "Leave record deleted" . $refundedText . "."
        ]);
        exit;
    }

    // 6. Dashboard Ledger: Who is working vs who is on leave today
    if ($action === 'get_today_roster') {
        $stmtLeave = $pdo->prepare("
            SELECT 
                l.staff_id,
                u.full_name,
                u.department,
                l.leave_type,
                l.deducted_from,
                l.reason
            FROM leave_applications l
            JOIN users u ON l.staff_id = u.staff_id
            WHERE (l.status = 'approved' OR (l.status = 'rejected' AND l.deducted_from IS NOT NULL))
              AND ? BETWEEN l.start_date AND l.end_date
        ");
        $stmtLeave->execute([$today]);
        $onLeave = $stmtLeave->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtAtt = $pdo->prepare("
            SELECT 
                a.staff_id,
                u.full_name,
                a.clock_in_time,
                a.clock_in_location,
                a.clock_out_time,
                a.status AS punch_status
            FROM attendance_records a
            JOIN users u ON a.staff_id = u.staff_id
            WHERE a.work_date = ?
        ");
        $stmtAtt->execute([$today]);
        $clockedIn = $stmtAtt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            "success"    => true,
            "today_date" => $today,
            "on_leave"   => $onLeave,
            "clocked_in" => $clockedIn
        ]);
        exit;
    }

    echo json_encode(["success" => false, "message" => "Invalid action specified."]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>