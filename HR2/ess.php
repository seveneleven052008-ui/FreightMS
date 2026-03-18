<?php
require_once 'config/config.php';
requireLogin();

$pageTitle = 'Employee Self Service';
$pdo = getDBConnection();

$activeTab = $_GET['tab'] ?? 'personal-info';
$userId = $_SESSION['user_id'];

// Get personal information
$userInfo = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$userInfo->execute([$userId]);
$personalInfo = $userInfo->fetch();

// Determine if current user should be treated as an admin (by role or username)
$roleValue = strtolower($personalInfo['role'] ?? '');
$isAdmin = ($roleValue === 'admin') || (($personalInfo['username'] ?? '') === 'admin');

// Handle ESS POST actions (leave request, approval, rejection)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Employee: submit leave request
    if ($action === 'request_leave') {
        $leaveType  = $_POST['leave_type'] ?? '';
        $startDate  = $_POST['start_date'] ?? '';
        $endDate    = $_POST['end_date'] ?? '';
        $days       = (int)($_POST['days'] ?? 0);
        $notes      = $_POST['reason'] ?? null; // Database column is 'notes'

        if ($leaveType && $startDate && $endDate && $days > 0) {
            // Check leave balance first
            $balanceStmt = $pdo->prepare("SELECT * FROM leave_balance WHERE employee_id = ? AND year = ?");
            $balanceStmt->execute([$userId, date('Y')]);
            $currentBalance = $balanceStmt->fetch();

            if (!$currentBalance) {
                header('Location: ess.php?tab=leave&error=no_balance_record');
                exit;
            }

            // Map leave type to balance column
            $balanceColumn = '';
            if ($leaveType === 'Annual Leave') $balanceColumn = 'annual_remaining';
            elseif ($leaveType === 'Sick Leave') $balanceColumn = 'sick_remaining';
            elseif ($leaveType === 'Personal Leave') $balanceColumn = 'personal_remaining';

            if ($balanceColumn && $currentBalance[$balanceColumn] < $days) {
                header('Location: ess.php?tab=leave&error=insufficient_balance&requested=' . $days . '&available=' . $currentBalance[$balanceColumn]);
                exit;
            }

            $insertLeave = $pdo->prepare("
                INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, days, notes, status, applied_date)
                VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW())
            ");
            $insertLeave->execute([
                $userId,
                $leaveType,
                $startDate,
                $endDate,
                $days,
                $notes
            ]);

            // Notify all admins that a new leave request needs approval
            $admins = $pdo->query("SELECT id FROM users WHERE LOWER(role) = 'admin' OR username = 'admin'")->fetchAll();
            if ($admins) {
                $title = 'New Leave Request';
                $msg = sprintf(
                    '%s requested %d day(s) of %s from %s to %s.',
                    $personalInfo['full_name'] ?? 'An employee',
                    $days,
                    $leaveType,
                    $startDate,
                    $endDate
                );
                $notifyStmt = $pdo->prepare("
                    INSERT INTO notifications (employee_id, title, message, type, priority, is_read, created_at)
                    VALUES (?, ?, ?, 'leave', 'high', 0, NOW())
                ");
                foreach ($admins as $admin) {
                    $notifyStmt->execute([
                        $admin['id'],
                        $title,
                        $msg
                    ]);
                }
            }

            header('Location: ess.php?tab=leave&leave_submitted=1');
            exit;
        } else {
            header('Location: ess.php?tab=leave&leave_submitted=0');
            exit;
        }
    }

    // Admin: approve / reject leave request
    if ($action === 'update_leave_status' && $isAdmin) {
        $leaveId = (int)($_POST['leave_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';

        if ($leaveId > 0 && in_array($newStatus, ['Approved', 'Rejected'], true)) {
            // Get leave details
            $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE id = ?");
            $stmt->execute([$leaveId]);
            $leave = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($leave) {
                // Update status and approver
                $update = $pdo->prepare("
                    UPDATE leave_requests 
                    SET status = ?, approver_id = ?
                    WHERE id = ?
                ");
                $update->execute([$newStatus, $userId, $leaveId]);

                // Notify employee about decision
                $employeeId = $leave['employee_id'];
                $title = 'Leave Request ' . $newStatus;
                $msg = sprintf(
                    'Your %s leave from %s to %s has been %s.',
                    $leave['leave_type'],
                    $leave['start_date'],
                    $leave['end_date'],
                    strtolower($newStatus)
                );
                $notifyStmt = $pdo->prepare("
                    INSERT INTO notifications (employee_id, title, message, type, priority, is_read, created_at)
                    VALUES (?, ?, ?, 'leave', 'high', 0, NOW())
                ");
                $notifyStmt->execute([
                    $employeeId,
                    $title,
                    $msg
                ]);
            }
        }

        header('Location: ess.php?tab=leave');
        exit;
    }

    // Employee: update personal information
    if ($action === 'update_personal_info') {
        $fullName    = $_POST['full_name'] ?? '';
        $username    = $_POST['username'] ?? '';
        $email       = $_POST['email'] ?? '';
        $phone       = $_POST['phone'] ?? '';
        $birthday    = $_POST['birthday'] ?? '';
        $address     = $_POST['address'] ?? '';
        $city        = $_POST['city'] ?? '';
        $civilStatus = $_POST['civil_status'] ?? '';
        $profilePic  = $personalInfo['profile_picture']; // Current value from DB

        // Handle profile picture upload
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['profile_pic']['tmp_name'];
            $fileName = $_FILES['profile_pic']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
            if (in_array($fileExtension, $allowedExtensions)) {
                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR;
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $newFileName = $userId . '_' . time() . '.' . $fileExtension;
                $destPath = $uploadDir . $newFileName;

                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $profilePic = $newFileName;
                } else {
                    error_log("FMS Error: move_uploaded_file failed for $destPath");
                }
            } else {
                error_log("FMS Error: Invalid file extension $fileExtension");
            }
        }

        $updateInfo = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, date_of_birth = ?, address = ?, city = ?, civil_status = ?, profile_picture = ? WHERE id = ?");
        $updateInfo->execute([$fullName, $username, $email, $phone, $birthday, $address, $city, $civilStatus, $profilePic, $userId]);

        // Sync session variables immediately
        $_SESSION['full_name'] = $fullName;
        $_SESSION['username'] = $username;
        $_SESSION['email'] = $email;
        $_SESSION['profile_picture'] = $profilePic;
        
        // Final fallback to ensure the Fetch at the top of the next load sees it
        // (The redirect takes care of this but we're being safe)
        
        if ($profilePic !== $personalInfo['profile_picture']) {
            $msg = "&updated=1";
        } else if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] !== UPLOAD_ERR_NO_FILE) {
            $msg = "&updated=1&upload_err=" . $_FILES['profile_pic']['error'];
        } else {
            $msg = "&updated=1";
        }

        header('Location: ess.php?tab=personal-info' . $msg);
        exit;
    }

    // Employee: Clock In
    if ($action === 'attendance_clock_in') {
        $today = date('Y-m-d');
        $now = date('H:i:s');
        
        // Check if there's an active session already
        $check = $pdo->prepare("SELECT id FROM attendance_records WHERE employee_id = ? AND attendance_date = ? AND check_out IS NULL");
        $check->execute([$userId, $today]);
        if (!$check->fetch()) {
            $insert = $pdo->prepare("INSERT INTO attendance_records (employee_id, attendance_date, check_in, status) VALUES (?, ?, ?, 'Present')");
            $insert->execute([$userId, $today, $now]);
        }
        
        header('Location: ess.php?tab=leave&clocked_in=1');
        exit;
    }

    // Employee: Clock Out
    if ($action === 'attendance_clock_out') {
        $today = date('Y-m-d');
        $now = date('H:i:s');
        
        // Find the active session (latest today with no check_out)
        $fetch = $pdo->prepare("SELECT id, check_in FROM attendance_records WHERE employee_id = ? AND attendance_date = ? AND check_out IS NULL ORDER BY created_at DESC LIMIT 1");
        $fetch->execute([$userId, $today]);
        $record = $fetch->fetch();
        
        if ($record && $record['check_in']) {
            $checkIn = new DateTime($record['check_in']);
            $checkOut = new DateTime($now);
            $interval = $checkIn->diff($checkOut);
            $hours = round($interval->h + ($interval->i / 60) + ($interval->s / 3600), 2);
            
            $update = $pdo->prepare("UPDATE attendance_records SET check_out = ?, hours = ? WHERE id = ?");
            $update->execute([$now, $hours, $record['id']]);
        }
        
        header('Location: ess.php?tab=leave&clocked_out=1');
        exit;
    }
}

// Get payslips
$payslips = $pdo->prepare("SELECT * FROM payslips WHERE employee_id = ? ORDER BY period_start DESC");
$payslips->execute([$userId]);
$myPayslips = $payslips->fetchAll();

// Get leave requests
if ($isAdmin) {
    // Admins see all leave requests
    $leaveRequests = $pdo->query("
        SELECT 
            lr.*, 
            u.full_name AS approver_name,
            e.full_name AS employee_name
        FROM leave_requests lr
        LEFT JOIN users u ON lr.approver_id = u.id
        LEFT JOIN users e ON lr.employee_id = e.id
        ORDER BY lr.applied_date DESC
    ");
    $myLeaves = $leaveRequests->fetchAll();
} else {
    // Employees see only their own leave requests
    $leaveRequests = $pdo->prepare("
        SELECT lr.*, u.full_name as approver_name 
        FROM leave_requests lr 
        LEFT JOIN users u ON lr.approver_id = u.id 
        WHERE lr.employee_id = ? 
        ORDER BY lr.applied_date DESC
    ");
    $leaveRequests->execute([$userId]);
    $myLeaves = $leaveRequests->fetchAll();
}

// Get Validated Leave for current user
$stmt = $pdo->prepare("
    SELECT lv.*, u.full_name, u.department
    FROM leave_validation lv
    JOIN users u ON lv.employee_id = u.id
    WHERE lv.employee_id = ? AND lv.validation_status = 'Validated'
    ORDER BY lv.leave_date DESC
");
$stmt->execute([$userId]);
$validatedLeave = $stmt->fetchAll();

// Get leave balance
$leaveBalanceStmt = $pdo->prepare("SELECT * FROM leave_balance WHERE employee_id = ? AND year = ?");
$leaveBalanceStmt->execute([$userId, date('Y')]);
$balance = $leaveBalanceStmt->fetch();

if (!$balance) {
    // Create default balance
    $createBalance = $pdo->prepare("INSERT INTO leave_balance (employee_id, year) VALUES (?, ?)");
    $createBalance->execute([$userId, date('Y')]);
    $leaveBalanceStmt->execute([$userId, date('Y')]);
    $balance = $leaveBalanceStmt->fetch();
}

// Get attendance records
if ($isAdmin) {
    // Admins see all attendance records
    $attendance = $pdo->query("
        SELECT ar.*, u.full_name as employee_name 
        FROM attendance_records ar 
        JOIN users u ON ar.employee_id = u.id 
        ORDER BY ar.attendance_date DESC, ar.check_in DESC 
        LIMIT 20
    ");
    $myAttendance = $attendance->fetchAll();
} else {
    // Employees see only their own (Premium list for dashboard)
    $attendance = $pdo->prepare("SELECT * FROM attendance_records WHERE employee_id = ? ORDER BY attendance_date DESC LIMIT 5");
    $attendance->execute([$userId]);
    $myAttendance = $attendance->fetchAll();
}

// Check today's active attendance session for buttons
$activeSessionStmt = $pdo->prepare("SELECT * FROM attendance_records WHERE employee_id = ? AND attendance_date = ? AND check_out IS NULL ORDER BY created_at DESC LIMIT 1");
$activeSessionStmt->execute([$userId, date('Y-m-d')]);
$activeSession = $activeSessionStmt->fetch();

$canClockIn = !$activeSession;
$canClockOut = (bool)$activeSession;

// Get notifications
$notifications = $pdo->prepare("SELECT * FROM notifications WHERE employee_id = ? ORDER BY created_at DESC");
$notifications->execute([$userId]);
$myNotifications = $notifications->fetchAll();

$unreadCount = count(array_filter($myNotifications, fn($n) => !$n['is_read']));

// --- Integration: HR3 Scheduling & HR2 Attendance Integration ---
$currentMonth = date('m');
$currentYear = date('Y');
$firstDay = "$currentYear-$currentMonth-01";
$lastDay = date('Y-m-t', strtotime($firstDay));

// Fetch all schedules for this month
$scheduleStmt = $pdo->prepare("SELECT * FROM employee_schedules WHERE employee_id = ? AND schedule_date BETWEEN ? AND ?");
$scheduleStmt->execute([$userId, $firstDay, $lastDay]);
$monthlySchedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all attendance for this month
$attendStmt = $pdo->prepare("SELECT * FROM attendance_records WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
$attendStmt->execute([$userId, $firstDay, $lastDay]);
$monthlyAttendance = $attendStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch approved leaves for this month
$leaveStmt = $pdo->prepare("SELECT * FROM leave_requests WHERE employee_id = ? AND status = 'Approved' AND ((start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?))");
$leaveStmt->execute([$userId, $firstDay, $lastDay, $firstDay, $lastDay]);
$monthlyLeaves = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch validated leaves (from specific join table if exists)
$validatedLeaveStmt = $pdo->prepare("SELECT * FROM leave_validation WHERE employee_id = ?");
$validatedLeaveStmt->execute([$userId]);
$validatedLeave = $validatedLeaveStmt->fetchAll();

// Consolidate data by date
$integratedSchedule = [];
$current = new DateTime($firstDay);
$end = new DateTime($lastDay);
$end->modify('+1 day'); 

while ($current < $end) {
    $dateStr = $current->format('Y-m-d');
    $integratedSchedule[$dateStr] = [
        'schedule' => null,
        'attendance' => null,
        'leave' => null,
        'status' => 'None'
    ];
    $current->modify('+1 day');
}

foreach ($monthlySchedules as $s) { $integratedSchedule[$s['schedule_date']]['schedule'] = $s; }
foreach ($monthlyAttendance as $a) { $integratedSchedule[$a['attendance_date']]['attendance'] = $a; }
foreach ($monthlyLeaves as $l) {
    $l_start = new DateTime($l['start_date']);
    $l_end = new DateTime($l['end_date']);
    $curr = clone $l_start;
    while ($curr <= $l_end) {
        $d = $curr->format('Y-m-d');
        if (isset($integratedSchedule[$d])) {
            $integratedSchedule[$d]['leave'] = $l;
        }
        $curr->modify('+1 day');
    }
}

// Determine status for each day
foreach ($integratedSchedule as $date => &$day) {
    if ($day['leave']) {
        $day['status'] = 'Leave';
    } elseif ($day['schedule']) {
        if ($day['attendance']) {
            $schedStart = $day['schedule']['start_time'];
            $schedEnd = $day['schedule']['end_time'];
            $actualStart = $day['attendance']['check_in'];
            $actualEnd = $day['attendance']['check_out'];
            
            $isLate = ($actualStart && strtotime($actualStart) > strtotime($schedStart));
            $isUndertime = ($actualEnd && strtotime($actualEnd) < strtotime($schedEnd));
            
            if ($isLate && $isUndertime) {
                $day['status'] = 'Late & Undertime';
            } elseif ($isLate) {
                $day['status'] = 'Late';
            } elseif ($isUndertime) {
                $day['status'] = 'Undertime';
            } else {
                $day['status'] = 'Present';
            }
            
            // Calculate actual hours
            if ($actualStart && $actualEnd) {
                $diff = strtotime($actualEnd) - strtotime($actualStart);
                $day['actual_hours'] = round($diff / 3600, 1);
            } else {
                $day['actual_hours'] = 0;
            }
        } elseif (strtotime($date) < strtotime(date('Y-m-d'))) {
            $day['status'] = 'Absent';
            $day['actual_hours'] = 0;
        }
    }
}
unset($day);
// --- End Integration ---

ob_start();
?>
<div class="p-8">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Employee Self Service (ESS)</h1>
        <p class="text-gray-600">
            Manage your personal information and submit leave request
        </p>
    </div>

    <!-- Tabs -->
    <div class="mb-6 border-b border-gray-200">
        <div class="flex gap-4 overflow-x-auto">
            <a href="?tab=personal-info" class="pb-3 px-2 border-b-2 transition-colors whitespace-nowrap <?php echo $activeTab == 'personal-info' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-600 hover:text-gray-900'; ?>">
                <div class="flex items-center gap-2">
                    <i class="fas fa-user"></i>
                    <span>Personal Information</span>
                </div>
            </a>
            <a href="?tab=payroll" class="pb-3 px-2 border-b-2 transition-colors whitespace-nowrap <?php echo $activeTab == 'payroll' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-600 hover:text-gray-900'; ?>">
                <div class="flex items-center gap-2">
                    <i class="fas fa-dollar-sign"></i>
                    <span>Payslips & Payroll</span>
                </div>
            </a>
            <a href="?tab=leave" class="pb-3 px-2 border-b-2 transition-colors whitespace-nowrap <?php echo $activeTab == 'leave' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-600 hover:text-gray-900'; ?>">
                <div class="flex items-center gap-2">
                    <i class="fas fa-calendar"></i>
                    <span>Leave & Attendance</span>
                </div>
            </a>
            <a href="?tab=notifications" class="pb-3 px-2 border-b-2 transition-colors whitespace-nowrap <?php echo $activeTab == 'notifications' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-600 hover:text-gray-900'; ?>">
                <div class="flex items-center gap-2">
                    <i class="fas fa-bell"></i>
                    <span>Notifications</span>
                    <?php if ($unreadCount > 0): ?>
                        <span class="px-2 py-0.5 bg-red-500 text-white rounded-full text-xs"><?php echo $unreadCount; ?></span>
                    <?php endif; ?>
                </div>
            </a>
        </div>
    </div>

    <!-- Personal Information Tab -->
    <?php if ($activeTab == 'personal-info'): ?>
        <div class="mb-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold text-gray-900 border-l-4 border-indigo-600 pl-4">My Profile</h2>
                <button onclick="showEditInfoModal()" class="flex items-center gap-2 px-6 py-2 bg-white border border-indigo-600 text-indigo-600 rounded-xl hover:bg-indigo-50 transition-all font-semibold shadow-sm">
                    <i class="fas fa-edit"></i>
                    <span>Edit Information</span>
                </button>
            </div>

            <?php if (isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-xl text-sm flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-500">
                    <i class="fas fa-check-circle text-green-500"></i>
                    <div>
                        <p class="font-bold">Information updated successfully.</p>
                        <?php if (isset($_GET['upload_err'])): ?>
                            <p class="text-xs mt-1 text-red-600">Note: Profile picture upload had an issue (Error Code: <?php echo (int)$_GET['upload_err']; ?>)</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Profile Side (Left) -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="h-32 bg-gradient-to-r from-indigo-600 to-blue-600 relative">
                            <div class="absolute -bottom-12 left-1/2 -translate-x-1/2">
                                <div class="w-24 h-24 bg-white rounded-full p-1 shadow-md">
                                    <?php if (!empty($personalInfo['profile_picture'])): ?>
                                        <img src="uploads/avatars/<?php echo htmlspecialchars($personalInfo['profile_picture']); ?>" alt="Profile" class="w-full h-full rounded-full object-cover">
                                    <?php else: ?>
                                        <div class="w-full h-full bg-indigo-50 rounded-full flex items-center justify-center text-indigo-600 text-3xl font-bold">
                                            <?php echo substr($personalInfo['full_name'], 0, 1); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="pt-16 pb-8 px-6 text-center">
                            <h3 class="text-xl font-bold text-gray-900 mb-1"><?php echo htmlspecialchars($personalInfo['full_name']); ?></h3>
                            <p class="text-gray-500 text-sm mb-6"><?php echo htmlspecialchars($personalInfo['position'] ?? 'Employee'); ?></p>
                            
                            <div class="space-y-4 text-left border-t border-gray-50 pt-6">
                                <div>
                                    <p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-1">Username</p>
                                    <p class="text-gray-800 font-medium"><?php echo htmlspecialchars($personalInfo['username']); ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-1">Employee ID</p>
                                    <p class="text-gray-800 font-medium"><?php echo htmlspecialchars($personalInfo['employee_id']); ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-1">Role</p>
                                    <span class="px-3 py-1 bg-indigo-50 text-indigo-700 rounded-full text-xs font-bold"><?php echo htmlspecialchars($personalInfo['role']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal Information Side (Right) -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
                        <h4 class="text-lg font-bold text-gray-900 mb-8 flex items-center gap-2">
                            <i class="fas fa-info-circle text-indigo-600"></i>
                            Personal Information
                        </h4>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-8">
                            <div>
                                <p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-2">Email Address</p>
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center text-blue-600 text-sm">
                                        <i class="fas fa-envelope"></i>
                                    </div>
                                    <p class="text-gray-800 font-medium"><?php echo htmlspecialchars($personalInfo['email']); ?></p>
                                </div>
                            </div>

                            <div>
                                <p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-2">Contact Number</p>
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 bg-green-50 rounded-lg flex items-center justify-center text-green-600 text-sm">
                                        <i class="fas fa-phone"></i>
                                    </div>
                                    <p class="text-gray-800 font-medium"><?php echo htmlspecialchars($personalInfo['phone'] ?? 'N/A'); ?></p>
                                </div>
                            </div>

                            <div>
                                <p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-2">Birthday</p>
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 bg-red-50 rounded-lg flex items-center justify-center text-red-600 text-sm">
                                        <i class="fas fa-birthday-cake"></i>
                                    </div>
                                    <p class="text-gray-800 font-medium"><?php echo formatDate($personalInfo['date_of_birth']); ?></p>
                                </div>
                            </div>

                            <div>
                                <p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-2">Civil Status</p>
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 bg-purple-50 rounded-lg flex items-center justify-center text-purple-600 text-sm">
                                        <i class="fas fa-heart"></i>
                                    </div>
                                    <p class="text-gray-800 font-medium"><?php echo htmlspecialchars($personalInfo['civil_status'] ?? 'N/A'); ?></p>
                                </div>
                            </div>

                            <div class="md:col-span-2">
                                <p class="text-gray-400 text-xs font-bold uppercase tracking-wider mb-2">Address</p>
                                <div class="flex items-start gap-3">
                                    <div class="w-8 h-8 bg-amber-50 rounded-lg flex items-center justify-center text-amber-600 text-sm mt-0.5">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </div>
                                    <div>
                                        <p class="text-gray-800 font-medium"><?php echo htmlspecialchars($personalInfo['address'] ?? 'N/A'); ?></p>
                                        <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($personalInfo['city'] ?? ''); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>

    <!-- Payroll Tab -->
    <?php if ($activeTab == 'payroll'): ?>
        <div class="space-y-8">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-4">
                    <div class="relative group">
                        <div class="w-10 h-10 bg-white border border-gray-100 rounded-xl flex items-center justify-center text-indigo-600 shadow-sm hover:shadow-md transition-all cursor-pointer">
                            <i class="fas fa-calendar-alt text-lg"></i>
                            <input type="month" id="payslipMonthFilter" class="absolute inset-0 opacity-0 cursor-pointer w-full" onchange="filterPayslips(this.value)">
                        </div>
                        <!-- Tooltip -->
                        <div class="absolute -top-10 left-1/2 -translate-x-1/2 px-2 py-1 bg-gray-900 text-white text-[10px] font-bold rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap pointer-events-none">
                            Filter by Month
                        </div>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-900 border-l-4 border-indigo-600 pl-4">My Payslips</h2>
                </div>
                <div class="flex gap-2">
                    <button class="px-4 py-2 bg-indigo-50 text-indigo-700 rounded-lg hover:bg-indigo-100 transition-colors text-sm font-semibold">
                        <i class="fas fa-file-download mr-1"></i>
                        Download All
                    </button>
                </div>
            </div>



            <!-- Payslips Table -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead>
                            <tr class="bg-gray-50/50">
                                <th class="px-6 py-4 text-left text-xs font-bold text-gray-400 uppercase tracking-wider">Payroll Period</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-gray-400 uppercase tracking-wider">Gross Pay</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-gray-400 uppercase tracking-wider">Deductions</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-gray-400 uppercase tracking-wider">Net Pay</th>
                                <th class="px-6 py-4 text-center text-xs font-bold text-gray-400 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-4 text-center text-xs font-bold text-gray-400 uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($myPayslips)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center">
                                        <div class="flex flex-col items-center gap-3 text-gray-400">
                                            <i class="fas fa-file-invoice-dollar text-4xl opacity-20"></i>
                                            <p class="italic">No payroll records found.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($myPayslips as $slip): ?>
                                    <tr class="hover:bg-gray-50/50 transition-colors group">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <p class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($slip['month']); ?></p>
                                            <p class="text-[10px] text-gray-400 font-medium"><?php echo formatDate($slip['period_start']); ?> - <?php echo formatDate($slip['period_end']); ?></p>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-600">
                                            <?php echo formatCurrency($slip['gross_pay']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-red-500">
                                            <?php echo formatCurrency($slip['deductions']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-black text-emerald-600">
                                            <?php echo formatCurrency($slip['net_pay']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase <?php echo getStatusBadge($slip['status']); ?>">
                                                <?php echo htmlspecialchars($slip['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <button onclick='showPayslipDetails(<?php echo json_encode($slip); ?>)' class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-all flex items-center justify-center shadow-sm">
                                                <i class="fas fa-eye text-xs"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pro Tip -->
            <div class="bg-amber-50 border border-amber-100 rounded-2xl p-6 flex gap-4 items-start">
                <div class="w-10 h-10 bg-amber-100 rounded-xl flex items-center justify-center text-amber-600 flex-shrink-0">
                    <i class="fas fa-question-circle"></i>
                </div>
                <div>
                    <h4 class="font-bold text-amber-800 text-sm mb-1">Need help with your payroll?</h4>
                    <p class="text-amber-700 text-xs leading-relaxed">
                        If you notice any discrepancies in your payslip or have questions about deductions, please contact the <span class="font-bold">Human Resources</span> department or your immediate supervisor.
                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Leave & Attendance Tab -->
    <?php if ($activeTab == 'leave'): ?>
        <div class="space-y-8">
            <!-- Daily Attendance & Schedule Monitoring Table -->
            <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden">
                <div class="p-6 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                    <h2 class="text-lg font-extrabold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-history text-indigo-600"></i>
                        Leave & Attendance
                    </h2>
                        <button onclick="showRequestModal()" class="px-6 py-2 bg-[#001f3f] text-white rounded-lg hover:bg-[#002d5a] font-bold transition-all shadow-md flex items-center gap-2 text-sm">
                            <i class="fas fa-plus"></i> Request
                        </button>
                </div>

                <?php if (isset($_GET['leave_submitted'])): ?>
                    <div class="mx-6 mt-4 p-4 <?php echo $_GET['leave_submitted'] == '1' ? 'bg-green-50 text-green-800 border-green-100' : 'bg-red-50 text-red-800 border-red-100'; ?> border rounded-lg text-sm font-semibold">
                        <?php echo $_GET['leave_submitted'] == '1' ? 'Request submitted successfully!' : 'Submission failed. Please try again.'; ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="mx-6 mt-4 p-4 bg-red-50 text-red-800 border-red-100 border rounded-lg text-sm font-semibold">
                        <?php 
                        if ($_GET['error'] === 'insufficient_balance') {
                            echo 'Insufficient leave balance. requested: ' . htmlspecialchars($_GET['requested']) . ' days, available: ' . htmlspecialchars($_GET['available']) . ' days.';
                        } elseif ($_GET['error'] === 'no_balance_record') {
                            echo 'No leave balance record found for the current year.';
                        } else {
                            echo 'An error occurred. Please try again.';
                        }
                        ?>
                    </div>
                <?php endif; ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50/50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-bold text-gray-400 uppercase tracking-wider">Employee</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-gray-400 uppercase tracking-wider">Department</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-gray-400 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-gray-400 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-4 text-center text-xs font-bold text-gray-400 uppercase tracking-wider">Credits</th>
                                <th class="px-6 py-4 text-center text-xs font-bold text-gray-400 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                                <?php 
                                $activityFound = false;
                                $displayLimit = 1; // Show only one record
                                $count = 0;
                                // Sort date descending for history view
                                krsort($integratedSchedule);
                                foreach ($integratedSchedule as $date => $data): 
                                    if ($count >= $displayLimit) break;
                                    $activityFound = true;
                                    $count++;
                                ?>
                                    <tr class="hover:bg-gray-50/80 transition-all group">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-400 font-bold text-xs">
                                                    <?php echo strtolower(substr($personalInfo['full_name'], 0, 1)); ?>
                                                </div>
                                                <div class="text-sm font-bold text-gray-900"><?php echo strtolower(htmlspecialchars($personalInfo['full_name'])); ?></div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-600 font-medium"><?php echo htmlspecialchars($personalInfo['department'] ?? 'N/A'); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center gap-1">
                                                <span class="text-sm font-bold text-gray-900"><?php echo date('M d, Y', strtotime($date)); ?></span>
                                                <span class="text-xs text-gray-300 font-medium">(<?php echo date('l', strtotime($date)); ?>)</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($data['leave']): ?>
                                                <span class="text-xs font-bold text-indigo-600"><?php echo htmlspecialchars($data['leave']['leave_type']); ?></span>
                                            <?php elseif ($data['schedule']): ?>
                                                <span class="text-xs font-medium text-gray-700">Regular Duty</span>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-400/50 italic font-medium">No Activity</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <span class="text-sm font-bold text-gray-900"><?php echo $data['leave'] ? '1' : '0'; ?></span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <?php
                                            $status = strtoupper($data['status'] ?: 'NONE');
                                            $badgeClass = 'bg-gray-50 text-gray-400 border-gray-100';
                                            if ($status === 'PRESENT') $badgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-100';
                                            if ($status === 'ABSENT') $badgeClass = 'bg-red-50 text-red-700 border-red-100';
                                            if ($status === 'NONE') $badgeClass = 'bg-gray-50/50 text-gray-300 border-gray-100/50';
                                            ?>
                                            <span class="px-4 py-1 rounded-full text-[10px] font-black border <?php echo $badgeClass; ?>">
                                                <?php echo $status; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$activityFound): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-12 text-center">
                                            <div class="max-w-[200px] mx-auto opacity-20 mb-4">
                                                <i class="fas fa-calendar-times text-6xl text-gray-300"></i>
                                            </div>
                                            <p class="text-xs text-gray-400 italic">No attendance or schedule activity found for this month.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Detailed Attendance Records Table -->
                <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                        <h2 class="text-lg font-extrabold text-[#001f3f] flex items-center gap-2">
                            <i class="fas fa-list-alt"></i>
                            Attendance Logs
                        </h2>
                        <button onclick="showAttendanceRequestModal()" class="px-6 py-2 bg-[#001f3f] text-white rounded-lg hover:bg-[#002d5a] font-bold transition-all shadow-md flex items-center gap-2 text-sm">
                            <i class="fas fa-plus"></i> Request
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead>
                                <tr>
                                    <th class="px-6 py-4 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Attendance ID</th>
                                    <th class="px-6 py-4 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">EMP ID</th>
                                    <th class="px-6 py-4 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Date</th>
                                    <th class="px-6 py-4 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Time In</th>
                                    <th class="px-6 py-4 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Time Out</th>
                                    <th class="px-6 py-4 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest">Late Min</th>
                                    <th class="px-6 py-4 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest">Undertime Min</th>
                                    <th class="px-6 py-4 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest">Overtime Min</th>
                                    <th class="px-6 py-4 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest">Status</th>
                                    <th class="px-6 py-4 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest">Worked Min</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-50">
                                <?php 
                                $attendanceFound = false;
                                $displayLimitLogs = 1; // Show only one record
                                $countLogs = 0;
                                foreach ($integratedSchedule as $date => $data): 
                                    $att = $data['attendance'];
                                    if (!$att) continue;
                                    if ($countLogs >= $displayLimitLogs) break;
                                    $attendanceFound = true;
                                    $countLogs++;
                                ?>
                                    <tr class="hover:bg-gray-50/50 transition-all">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="text-xs font-black text-gray-400">#<?php echo str_pad($att['id'], 5, '0', STR_PAD_LEFT); ?></span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-400 font-bold text-xs">
                                                    <?php echo strtolower(substr($personalInfo['full_name'], 0, 1)); ?>
                                                </div>
                                                <div class="text-sm font-bold text-gray-900"><?php echo strtolower(htmlspecialchars($personalInfo['employee_id'])); ?></div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center gap-1">
                                                <span class="text-sm font-bold text-gray-900"><?php echo date('M d, Y', strtotime($date)); ?></span>
                                                <span class="text-xs text-gray-300 font-medium">(<?php echo date('l', strtotime($date)); ?>)</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="text-sm font-bold text-gray-900"><?php echo $att['check_in'] ? date('h:i A', strtotime($att['check_in'])) : '---'; ?></span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="text-sm font-bold text-gray-900"><?php echo $att['check_out'] ? date('h:i A', strtotime($att['check_out'])) : '---'; ?></span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <span class="text-sm font-bold text-red-400"><?php echo $att['late_minutes'] ?? 0; ?></span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <span class="text-sm font-bold text-amber-400"><?php echo $att['undertime_minutes'] ?? 0; ?></span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <span class="text-sm font-bold text-emerald-400"><?php echo $att['overtime_minutes'] ?? 0; ?></span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <?php
                                            $status = strtoupper(($data['status'] ?? 'NONE') ?: 'NONE');
                                            $badgeClass = 'bg-gray-50 text-gray-400 border-gray-100';
                                            if ($status === 'PRESENT') $badgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-100';
                                            if ($status === 'ABSENT') $badgeClass = 'bg-red-50 text-red-700 border-red-100';
                                            if ($status === 'NONE') $badgeClass = 'bg-gray-50/50 text-gray-300 border-gray-100/50';
                                            ?>
                                            <span class="px-4 py-1 rounded-full text-[10px] font-black border <?php echo $badgeClass; ?>">
                                                <?php echo $status; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <span class="text-sm font-bold text-indigo-600"><?php echo $att['worked_minutes'] ?? 0; ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$attendanceFound): ?>
                                    <tr>
                                        <td colspan="10" class="px-6 py-12 text-center text-gray-400 italic text-xs">No detailed attendance records found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
    <?php endif; ?>

    <!-- Notifications Tab -->
    <?php if ($activeTab == 'notifications'): ?>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <i class="fas fa-bell text-indigo-600 text-xl"></i>
                    <h2 class="text-xl font-bold text-gray-900">Notifications</h2>
                    <?php if ($unreadCount > 0): ?>
                        <span class="px-2 py-1 bg-red-500 text-white rounded-full text-sm"><?php echo $unreadCount; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="space-y-3">
                <?php foreach ($myNotifications as $notification): ?>
                    <div class="border rounded-lg p-4 <?php echo !$notification['is_read'] ? 'border-indigo-300 bg-indigo-50' : 'border-gray-200 hover:bg-gray-50'; ?>">
                        <div class="flex items-start gap-4">
                            <div class="p-2 rounded-lg bg-indigo-100 text-indigo-600">
                                <?php
                                $icon = 'bell';
                                if ($notification['type'] === 'training') {
                                    $icon = 'graduation-cap';
                                } elseif ($notification['type'] === 'promotion') {
                                    $icon = 'chart-line';
                                } elseif ($notification['type'] === 'leave') {
                                    $icon = 'calendar-check';
                                }
                                ?>
                                <i class="fas fa-<?php echo $icon; ?>"></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-start justify-between mb-1">
                                    <h3 class="text-gray-900 font-semibold"><?php echo htmlspecialchars($notification['title']); ?></h3>
                                    <?php if ($notification['priority'] == 'high'): ?>
                                        <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-sm">High Priority</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-gray-600 text-sm mb-2"><?php echo htmlspecialchars($notification['message']); ?></p>
                                <span class="text-gray-500 text-sm"><?php echo formatDateTime($notification['created_at']); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Payslip Details Modal -->
<div id="payslipModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-2xl shadow-xl max-w-2xl w-full mx-4 overflow-hidden animate-in zoom-in duration-300">
        <div class="bg-[#00bcd4] p-6 text-white relative">
            <div class="flex justify-between items-start">
                <div>
                    <h3 class="text-3xl font-light mb-1">Payslip</h3>
                    <p class="text-[#e0f7fa] text-xs font-medium uppercase tracking-widest" id="modal_period_label">DECEMBER 2024</p>
                </div>
                <button onclick="closePayslipModal()" class="text-white/80 hover:text-white transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div class="p-8">
            <!-- Employee Info Mini -->
            <div class="mb-8 p-4 bg-gray-50 rounded-xl flex justify-between items-center text-sm border border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-700 font-bold">
                        <?php echo substr($personalInfo['full_name'], 0, 1); ?>
                    </div>
                    <div>
                        <p class="font-bold text-gray-900"><?php echo htmlspecialchars($personalInfo['full_name']); ?></p>
                        <p class="text-gray-400 text-[10px] uppercase font-black"><?php echo htmlspecialchars($personalInfo['employee_id']); ?></p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-gray-400 text-[10px] uppercase font-black mb-1">Status</p>
                    <span id="modal_status_badge" class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase">PAID</span>
                </div>
            </div>

            <!-- Table -->
            <div class="space-y-4 mb-8">
                <div class="grid grid-cols-12 border-b border-gray-100 pb-2 mb-2">
                    <span class="col-span-8 text-[#9c27b0] text-[10px] font-black uppercase tracking-widest">Description</span>
                    <span class="col-span-4 text-[#9c27b0] text-[10px] font-black uppercase tracking-widest text-right">Amount</span>
                </div>
                <div class="grid grid-cols-12 items-center py-1">
                    <span class="col-span-8 text-gray-600 font-medium">Basic Salary</span>
                    <span class="col-span-4 text-gray-900 font-bold text-right" id="modal_gross_pay">0.00</span>
                </div>
                <div class="grid grid-cols-12 items-center py-1">
                    <span class="col-span-8 text-gray-400 font-medium italic">Statutory Deductions</span>
                    <span class="col-span-4 text-red-500 font-bold text-right" id="modal_deductions">0.00</span>
                </div>
                <div class="pt-4 mt-4 border-t-2 border-dashed border-gray-100 grid grid-cols-12 items-center">
                    <span class="col-span-8 text-gray-900 font-black uppercase tracking-tight">Net Take Home</span>
                    <span class="col-span-4 text-[#00bcd4] text-xl font-black text-right" id="modal_net_pay">0.00</span>
                </div>
            </div>

            <div class="flex gap-3">
                <button class="flex-1 py-3 bg-gray-100 text-gray-700 rounded-xl font-bold hover:bg-gray-200 transition-colors flex items-center justify-center gap-2 text-sm">
                    <i class="fas fa-print"></i>
                    Print
                </button>
                <button class="flex-1 py-3 bg-[#00bcd4] text-white rounded-xl font-bold shadow-lg shadow-cyan-500/30 hover:shadow-cyan-500/50 transition-all flex items-center justify-center gap-2 text-sm">
                    <i class="fas fa-download"></i>
                    Download PDF
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Information Modal -->
<div id="editInfoModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-900">Edit Personal Information</h3>
            <button onclick="closeEditInfoModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form id="editInfoForm" method="POST" action="" class="p-6" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_personal_info">
            <div class="space-y-4">
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-1">Avatar</label>
                    <p class="text-xs text-gray-400 mb-2">Display Picture</p>
                    <div class="flex items-center gap-4">
                        <div id="avatar_preview_container" class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center text-gray-400 border-2 border-dashed border-gray-300 relative overflow-hidden group cursor-pointer" onclick="document.getElementById('profile_pic_input').click()">
                            <?php if (!empty($personalInfo['profile_picture'])): ?>
                                <img src="uploads/avatars/<?php echo htmlspecialchars($personalInfo['profile_picture']); ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <i class="fas fa-camera"></i>
                            <?php endif; ?>
                            <div class="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                <i class="fas fa-camera text-white"></i>
                            </div>
                        </div>
                        <input type="file" name="profile_pic" id="profile_pic_input" class="hidden" accept="image/*" onchange="previewImage(this)">
                        <div class="flex-1">
                            <button type="button" onclick="document.getElementById('profile_pic_input').click()" class="text-indigo-600 text-sm font-bold hover:underline">Change Picture</button>
                            <p class="text-[10px] text-gray-400 mt-1">JPG, PNG or GIF. Max 2MB.</p>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Full Name</label>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($personalInfo['full_name'] ?? ''); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Username</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($personalInfo['username'] ?? ''); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Email Address</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($personalInfo['email'] ?? ''); ?>" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Phone Number</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($personalInfo['phone'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Birthday</label>
                        <input type="date" name="birthday" value="<?php echo htmlspecialchars($personalInfo['date_of_birth'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Civil Status</label>
                        <select name="civil_status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="Single" <?php echo ($personalInfo['civil_status'] ?? '') == 'Single' ? 'selected' : ''; ?>>Single</option>
                            <option value="Married" <?php echo ($personalInfo['civil_status'] ?? '') == 'Married' ? 'selected' : ''; ?>>Married</option>
                            <option value="Divorced" <?php echo ($personalInfo['civil_status'] ?? '') == 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                            <option value="Widowed" <?php echo ($personalInfo['civil_status'] ?? '') == 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-2">Address</label>
                    <textarea name="address" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"><?php echo htmlspecialchars($personalInfo['address'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-2">City</label>
                    <input type="text" name="city" value="<?php echo htmlspecialchars($personalInfo['city'] ?? ''); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeEditInfoModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Request Leave Modal -->
<div id="requestLeaveModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-900">Request Leave</h3>
            <button onclick="closeRequestLeaveModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form id="requestLeaveForm" method="POST" action="" class="p-6">
            <input type="hidden" name="action" value="request_leave">
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Employee</label>
                        <input type="text" value="<?php echo htmlspecialchars($personalInfo['full_name']); ?>" readonly class="w-full px-4 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-500 text-sm font-bold">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Department</label>
                        <input type="text" value="<?php echo htmlspecialchars($personalInfo['department'] ?? 'N/A'); ?>" readonly class="w-full px-4 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-500 text-sm font-bold">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Type (Leave Type) *</label>
                        <select name="leave_type" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 font-bold text-sm">
                            <option value="">Select Leave Type</option>
                            <option value="Annual Leave">Annual Leave</option>
                            <option value="Sick Leave">Sick Leave</option>
                            <option value="Personal Leave">Personal Leave</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Status</label>
                        <input type="text" value="Pending Approval" readonly class="w-full px-4 py-2 border border-indigo-100 rounded-lg bg-indigo-50 text-indigo-700 text-sm font-bold">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Date (Start) *</label>
                        <input type="date" name="start_date" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 font-bold text-sm" onchange="calculateDays()">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Date (End) *</label>
                        <input type="date" name="end_date" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 font-bold text-sm" onchange="calculateDays()">
                    </div>
                </div>
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-2">Credits (Total Days)</label>
                    <input type="number" name="days" id="leave_days" readonly class="w-full px-4 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-900 font-black">
                </div>
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-2">Reason (Optional)</label>
                    <textarea name="reason" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm"></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeRequestLeaveModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                    Submit Request
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Request Modal -->
<div id="requestModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-900">Request</h3>
            <button onclick="closeRequestModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form id="requestForm" method="POST" action="" class="p-6">
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Employee</label>
                        <input type="text" name="employee_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm font-bold">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Department</label>
                        <input type="text" name="department" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm font-bold">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Date</label>
                        <input type="date" name="request_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 font-bold text-sm">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Type</label>
                        <input type="text" name="request_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 font-bold text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Credits</label>
                        <input type="number" name="credits" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 font-bold text-sm">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Status</label>
                        <input type="text" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm font-bold">
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeRequestModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                    Submit Request
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Attendance Request Modal -->
<div id="attendanceRequestModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-900">Attendance Request</h3>
            <button onclick="closeAttendanceRequestModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form id="attendanceRequestForm" method="POST" action="" class="p-6">
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Attendance ID</label>
                        <input type="text" name="attendance_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">EMP ID</label>
                        <input type="text" name="emp_id" value="<?php echo htmlspecialchars($personalInfo['employee_id']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Date</label>
                        <input type="date" name="date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Status</label>
                        <input type="text" name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Time In</label>
                        <input type="time" name="time_in" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Time Out</label>
                        <input type="time" name="time_out" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Late Minutes</label>
                        <input type="number" name="late_minutes" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Undertime Minutes</label>
                        <input type="number" name="undertime_minutes" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm font-medium mb-2">Overtime Minutes</label>
                        <input type="number" name="overtime_minutes" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-gray-700 text-sm font-medium mb-2">Worked Minutes</label>
                    <input type="number" name="worked_minutes" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeAttendanceRequestModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                    Submit Request
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Animate leave progress bars
document.addEventListener('DOMContentLoaded', function() {
    const leaveBars = document.querySelectorAll('.leave-bar');
    leaveBars.forEach(bar => {
        const target = parseFloat(bar.getAttribute('data-target')) || 0;
        animateProgressBar(bar, target);
    });

    // Add hover effects to cards
    const cards = document.querySelectorAll('.border.border-gray-200.rounded-lg');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.transition = 'transform 0.2s ease, box-shadow 0.2s ease';
            this.style.boxShadow = '0 4px 6px rgba(0, 0, 0, 0.1)';
        });
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '';
        });
    });

    // Mark notifications as read on click
    const notifications = document.querySelectorAll('.border.rounded-lg.p-4');
    notifications.forEach(notification => {
        if (notification.classList.contains('bg-indigo-50')) {
            notification.addEventListener('click', function() {
                // You can add AJAX call here to mark as read
                this.classList.remove('bg-indigo-50', 'border-indigo-300');
                this.classList.add('bg-gray-50', 'border-gray-200');
            });
        }
    });
});

// Animate Progress Bar
function animateProgressBar(element, target) {
    if (!element) return;
    let current = 0;
    const increment = target / 50;
    const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
            element.style.width = target + '%';
            clearInterval(timer);
        } else {
            element.style.width = current + '%';
        }
    }, 30);
}

// Calculate leave days
function calculateDays() {
    const startDate = document.querySelector('[name="start_date"]').value;
    const endDate = document.querySelector('[name="end_date"]').value;
    const daysInput = document.getElementById('leave_days');
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        if (end >= start) {
            const diffTime = Math.abs(end - start);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
            daysInput.value = diffDays;
        } else {
            daysInput.value = 0;
            alert('End date must be after start date');
        }
    }
}

// Edit Information Modal
// Payslip Modal Functions
function filterPayslips(monthValue) {
    if (!monthValue) return;
    
    // Format input month (YYYY-MM) to matched stored month name or date
    const date = new Date(monthValue + "-01");
    const monthName = date.toLocaleString('en-US', { month: 'long', year: 'numeric' });
    
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const periodCell = row.querySelector('td:first-child p:first-child');
        if (periodCell) {
            const rowMonth = periodCell.textContent.trim();
            if (rowMonth.toLowerCase().includes(monthName.toLowerCase())) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        }
    });
}

function showPayslipDetails(data) {
    const modal = document.getElementById('payslipModal');
    
    // Set labels
    document.getElementById('modal_period_label').textContent = data.month;
    document.getElementById('modal_gross_pay').textContent = parseFloat(data.gross_pay).toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('modal_deductions').textContent = '-' + parseFloat(data.deductions).toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('modal_net_pay').textContent = parseFloat(data.net_pay).toLocaleString(undefined, {minimumFractionDigits: 2});
    
    // Status badge
    const badge = document.getElementById('modal_status_badge');
    badge.textContent = data.status.toUpperCase();
    badge.className = 'px-2 py-0.5 rounded-full text-[10px] font-black uppercase ' + 
        (data.status === 'Paid' ? 'bg-green-50 text-green-700 border border-green-100' : 'bg-yellow-50 text-yellow-700 border border-yellow-100');

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closePayslipModal() {
    const modal = document.getElementById('payslipModal');
    modal.classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Close payslip modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('payslipModal');
    if (event.target === modal) {
        closePayslipModal();
    }
});

function showEditInfoModal() {
    const modal = document.getElementById('editInfoModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

// Image Preview
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const container = document.getElementById('avatar_preview_container');
            // Store the "click to upload" overlays if needed, but here we just replace content
            container.innerHTML = `
                <img src="${e.target.result}" class="w-full h-full object-cover">
                <div class="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                    <i class="fas fa-camera text-white"></i>
                </div>
            `;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function closeEditInfoModal() {
    const modal = document.getElementById('editInfoModal');
    modal.classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Request Leave Modal
function showRequestLeaveModal() {
    const modal = document.getElementById('requestLeaveModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeRequestLeaveModal() {
    const modal = document.getElementById('requestLeaveModal');
    modal.classList.add('hidden');
    document.body.style.overflow = 'auto';
    document.getElementById('requestLeaveForm').reset();
    document.getElementById('leave_days').value = '';
}

// Request Modal
function showRequestModal() {
    const modal = document.getElementById('requestModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeRequestModal() {
    const modal = document.getElementById('requestModal');
    modal.classList.add('hidden');
    document.body.style.overflow = 'auto';
    document.getElementById('requestForm').reset();
}

// Attendance Request Modal
function showAttendanceRequestModal() {
    const modal = document.getElementById('attendanceRequestModal');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeAttendanceRequestModal() {
    const modal = document.getElementById('attendanceRequestModal');
    modal.classList.add('hidden');
    document.body.style.overflow = 'auto';
    document.getElementById('attendanceRequestForm').reset();
}

// Close modals when clicking outside
document.addEventListener('click', function(event) {
    const editModal = document.getElementById('editInfoModal');
    const leaveModal = document.getElementById('requestLeaveModal');
    const requestModal = document.getElementById('requestModal');
    const attendanceModal = document.getElementById('attendanceRequestModal');
    
    if (event.target === editModal) {
        closeEditInfoModal();
    }
    
    if (event.target === leaveModal) {
        closeRequestLeaveModal();
    }

    if (event.target === requestModal) {
        closeRequestModal();
    }

    if (event.target === attendanceModal) {
        closeAttendanceRequestModal();
    }
});

// Close modals with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeEditInfoModal();
        closeRequestLeaveModal();
        closeRequestModal();
        closeAttendanceRequestModal();
    }
});
</script>

<?php
$content = ob_get_clean();
require_once 'includes/layout.php';
require_once 'includes/footer.php';
?>
