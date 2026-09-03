<?php
// admin/manage_absences.php - Admin interface for managing absence requests

// Start session for user authentication
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_status'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Database connection
require_once '../includes/db_connect.php';
require_once '../includes/mailer.php';

// Message handling
$message = "";
$message_type = "";

// Get request to review if specified
$review_id = isset($_GET['review']) ? (int)$_GET['review'] : 0;
$review_request = null;

if ($review_id > 0) {
    $stmt = $conn->prepare("
        SELECT 
            ar.*,
            s.first_name,
            s.last_name,
            s.grade_year
        FROM 
            absence_requests ar
        JOIN 
            students s ON ar.student_id = s.student_id
        WHERE 
            ar.request_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $review_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $review_request = $result->fetch_assoc();
    }
    
    $stmt->close();
}

// Handle deletion of an absence request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request'])) {
    $request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    
    if ($request_id > 0) {
        $stmt = $conn->prepare("DELETE FROM absence_requests WHERE request_id = ?");
        $stmt->bind_param("i", $request_id);
        
        if ($stmt->execute()) {
            $message = "The absence request has been deleted.";
            $message_type = "success";
        } else {
            $message = "Error deleting the request: " . $conn->error;
            $message_type = "error";
        }
        $stmt->close();
        
        header("Location: manage_absences.php?status=" . ($_GET['status'] ?? 'pending') . "&message=" . urlencode($message) . "&message_type=" . $message_type);
        exit();
    }
}

// Handle approval/decline
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_request']) || isset($_POST['decline_request'])) {
        $request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        $admin_notes = isset($_POST['admin_notes']) ? trim($_POST['admin_notes']) : '';
        $status = isset($_POST['approve_request']) ? 'approved' : 'declined';
        
        if ($request_id > 0) {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Update the request status
                $stmt = $conn->prepare("
                    UPDATE absence_requests 
                    SET status = ?, admin_notes = ?, approved_by = ? 
                    WHERE request_id = ?
                ");
                $stmt->bind_param("ssii", $status, $admin_notes, $_SESSION['user_id'], $request_id);
                $stmt->execute();
                
                // If approved, also update the attendance record
                if ($status === 'approved') {
                    // First, get the request details
                    $stmt = $conn->prepare("
                        SELECT student_id, cas_id, absence_date 
                        FROM absence_requests 
                        WHERE request_id = ?
                    ");
                    $stmt->bind_param("i", $request_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $request = $result->fetch_assoc();
                    
                    if ($request) {
                        // Find the attendance session for that date and CAS
                        $stmt = $conn->prepare("
                            SELECT ats.session_id 
                            FROM attendance_sessions ats
                            WHERE ats.cas_id = ? AND ats.session_date = ?
                        ");
                        $stmt->bind_param("is", $request['cas_id'], $request['absence_date']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $session = $result->fetch_assoc();
                        
                        if ($session) {
                            // Update the attendance record to 'excused'
                            $stmt = $conn->prepare("
                                UPDATE attendance_records 
                                SET status = 'excused', 
                                    notes = CONCAT(IFNULL(notes, ''), ' [Admin-excused through absence request]')
                                WHERE session_id = ? AND student_id = ?
                            ");
                            $stmt->bind_param("ii", $session['session_id'], $request['student_id']);
                            $stmt->execute();
                            $session_recorded = true;
                        } else {
                            // No session recorded for this CAS + date yet. The request is
                            // still approved, but the excusal can't be applied until
                            // attendance is recorded. It will be applied automatically at
                            // that point (see record_attendance.php / admin_record_attendance.php).
                            $session_recorded = false;
                        }
                    }
                }
                
                // Commit transaction
                $conn->commit();
                
                if ($status === 'approved' && isset($session_recorded) && !$session_recorded) {
                    $message = "Request approved. Attendance for that session hasn't been recorded yet, so the excusal will be applied automatically as soon as it is.";
                    $message_type = "success";
                } else {
                    $message = "The absence request has been " . ($status === 'approved' ? 'approved' : 'declined') . " successfully.";
                    $message_type = "success";
                }
                
                // Send the student a decision confirmation email, including
                // admin notes/comments if any were left (especially relevant
                // on a decline, so the student knows why).
                $notify_stmt = $conn->prepare("
                    SELECT ar.student_id, ar.cas_id, s.email, s.first_name, ar.cas_name, ar.absence_date
                    FROM absence_requests ar
                    JOIN students s ON ar.student_id = s.student_id
                    WHERE ar.request_id = ?
                ");
                $notify_stmt->bind_param("i", $request_id);
                $notify_stmt->execute();
                $notify_result = $notify_stmt->get_result();
                $notify_data = $notify_result->fetch_assoc();
                $notify_stmt->close();
                
                if ($notify_data && !empty($notify_data['email'])) {
                    $formatted_date = date('F j, Y', strtotime($notify_data['absence_date']));
                    $decision_label = $status === 'approved' ? 'Approved' : 'Declined';
                    $email_subject = "Absence Request " . $decision_label . " - " . htmlspecialchars($notify_data['cas_name']);
                    $email_body = "
                        <p>Hi " . htmlspecialchars($notify_data['first_name']) . ",</p>
                        <p>Your absence request for <strong>" . htmlspecialchars($notify_data['cas_name']) . "</strong> on " . $formatted_date . " has been <strong>" . strtolower($decision_label) . "</strong>.</p>
                    ";
                    if (!empty($admin_notes)) {
                        $email_body .= "
                            <p><strong>Comments from the CAS team:</strong></p>
                            <div style='padding: 10px; background: #f5f5f5; border-left: 3px solid #ccc;'>" . nl2br(htmlspecialchars($admin_notes)) . "</div>
                        ";
                    }
                    $email_body .= "<p style='color: #888; font-size: 13px;'>UWC Mostar CAS Platform</p>";
                    
                    send_email($notify_data['email'], $email_subject, $email_body, null, 'absence_decision', $notify_data['student_id'], $notify_data['cas_id']);
                }
                
                // Redirect to remove the request from the URL
                header("Location: manage_absences.php?status=" . ($_GET['status'] ?? 'pending') . "&message=" . urlencode($message) . "&message_type=" . $message_type);
                exit();
            } catch (Exception $e) {
                // Rollback in case of error
                $conn->rollback();
                $message = "Error processing the request: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// Check for passed message in URL
if (isset($_GET['message']) && isset($_GET['message_type'])) {
    $message = $_GET['message'];
    $message_type = $_GET['message_type'];
}

// Get absence requests with student details
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$valid_statuses = ['pending', 'approved', 'declined', 'all'];
if (!in_array($filter_status, $valid_statuses)) {
    $filter_status = 'pending';
}

$query = "
    SELECT 
        ar.request_id,
        ar.absence_date,
        ar.cas_name,
        ar.reason,
        ar.staff_confirmer,
        ar.status,
        ar.created_at,
        ar.admin_notes,
        s.student_id,
        s.first_name,
        s.last_name,
        s.grade_year,
        CONCAT(u.first_name, ' ', u.last_name) AS approved_by_name,
        ats.session_id AS matching_session_id
    FROM 
        absence_requests ar
    JOIN 
        students s ON ar.student_id = s.student_id
    LEFT JOIN 
        users u ON ar.approved_by = u.user_id
    LEFT JOIN
        attendance_sessions ats ON ats.cas_id = ar.cas_id AND ats.session_date = ar.absence_date
";

if ($filter_status !== 'all') {
    $query .= " WHERE ar.status = '$filter_status'";
}

$query .= " ORDER BY ar.created_at DESC";

$result = $conn->query($query);
$requests = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
}

// Count requests by status
$counts = [
    'pending' => 0,
    'approved' => 0,
    'declined' => 0,
    'total' => 0
];

$count_result = $conn->query("SELECT status, COUNT(*) as count FROM absence_requests GROUP BY status");
if ($count_result && $count_result->num_rows > 0) {
    while ($row = $count_result->fetch_assoc()) {
        $counts[$row['status']] = $row['count'];
        $counts['total'] += $row['count'];
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Absence Requests - UWC Mostar CAS</title>
    <link rel="icon" type="image/x-icon" href="../tab.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 h-full">
    <!-- Navigation Header -->
    <nav class="bg-white shadow-lg border-b border-gray-200 fixed top-0 left-0 right-0 z-50">
        <div class="mx-auto px-4">
            <div class="flex justify-between items-center h-16">
                <!-- Left side: Logo and Mobile Menu Button -->
                <div class="flex items-center">
                    <!-- Mobile menu button -->
                    <button type="button" class="md:hidden inline-flex items-center justify-center p-2 rounded-md text-gray-600 hover:text-gray-900 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500 mr-3" id="mobile-menu-button">
                        <span class="sr-only">Open main menu</span>
                        <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    
                    <!-- Logo -->
                    <div class="flex items-center">
                        <img src="../850.png" alt="UWC Mostar Logo" class="h-8 w-auto mr-3">
                        <div>
                        </div>
                    </div>
                </div>

                <!-- Right side: User menu -->
                <div class="flex items-center space-x-4">
                    <!-- Admin Badge -->
                    <span class="hidden sm:inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                        Administrator
                    </span>
                    
                    <!-- User dropdown -->
                    <div class="relative">
                        <button class="flex items-center text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500" id="user-menu-button">
                            <span class="sr-only">Open user menu</span>
                            <div class="h-8 w-8 rounded-full bg-red-600 flex items-center justify-center text-white">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <span class="hidden md:ml-2 md:block text-gray-700 font-medium"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span>
                            <svg class="hidden md:block ml-1 h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                        
                        <!-- User dropdown menu -->
                        <div class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 ring-1 ring-black ring-opacity-5 z-50" id="user-menu-dropdown">
                            <div class="px-4 py-2 text-sm text-gray-700 border-b border-gray-100">
                                <p class="font-medium"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></p>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($_SESSION['email'] ?? 'admin@uwcmostar.ba'); ?></p>
                            </div>
                            <a href="../logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                                <i class="fas fa-sign-out-alt mr-2"></i>
                                Sign out
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Mobile sidebar overlay -->
    <div class="fixed inset-0 z-40 md:hidden hidden" id="mobile-sidebar-overlay">
        <div class="fixed inset-0 bg-gray-600 bg-opacity-75" id="mobile-sidebar-backdrop"></div>
        <div class="relative flex-1 flex flex-col max-w-xs w-full bg-white">
            <div class="absolute top-0 right-0 -mr-12 pt-2">
                <button type="button" class="ml-1 flex items-center justify-center h-10 w-10 rounded-full focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white" id="close-sidebar-button">
                    <span class="sr-only">Close sidebar</span>
                    <svg class="h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            
            <!-- Mobile navigation -->
            <div class="flex-1 h-0 pt-5 pb-4 overflow-y-auto">
                <div class="px-4 mb-6">
                    <div class="flex items-center">
                        <img src="../850.png" alt="UWC Mostar Logo" class="h-8 w-auto mr-3">
                        <div>
                            <h1 class="text-lg font-bold text-gray-900">Admin Panel</h1>
                        </div>
                    </div>
                </div>
                
                <nav class="px-4 space-y-1">
                    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="students.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-user-graduate mr-3 text-lg"></i>
                        Students
                    </a>
                    
                    <a href="cas_activities.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'cas_activities.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-alt mr-3 text-lg"></i>
                        CAS Activities
                    </a>
                    
                    <a href="users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-users mr-3 text-lg"></i>
                        Users/Leaders
                    </a>
                    
                    <a href="attendance_report.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance_report.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-chart-bar mr-3 text-lg"></i>
                        Attendance Reports
                    </a>
                    
                    <a href="manage_absences.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_absences.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-times mr-3 text-lg"></i>
                        Absence Requests
                    </a>
                    
                    <a href="activity_log.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'activity_log.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-history mr-3 text-lg"></i>
                        Activity Log
                    </a>
                    
                    <a href="year_transition.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'year_transition.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-arrow-up mr-3 text-lg"></i>
                        Year Transition
                    </a>
                </nav>
            </div>
            
            <!-- Mobile logout -->
            <div class="flex-shrink-0 px-4 py-4 border-t border-gray-200">
                <a href="../logout.php" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt mr-3 text-lg"></i>
                    Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Desktop sidebar -->
    <div class="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0 md:pt-16">
        <div class="flex-1 flex flex-col bg-white border-r border-gray-200">
            <div class="flex-1 flex flex-col pt-5 pb-4 overflow-y-auto">
                <nav class="px-4 space-y-1">
                    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="students.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-user-graduate mr-3 text-lg"></i>
                        Students
                    </a>
                    
                    <a href="cas_activities.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'cas_activities.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-alt mr-3 text-lg"></i>
                        CAS Activities
                    </a>
                    
                    <a href="users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-users mr-3 text-lg"></i>
                        Users/Leaders
                    </a>
                    
                    <a href="attendance_report.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance_report.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-chart-bar mr-3 text-lg"></i>
                        Attendance Reports
                    </a>
                    
                    <a href="manage_absences.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_absences.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-times mr-3 text-lg"></i>
                        Absence Requests
                    </a>
                    
                    <a href="activity_log.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'activity_log.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-history mr-3 text-lg"></i>
                        Activity Log
                    </a>
                    
                    <a href="year_transition.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'year_transition.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-arrow-up mr-3 text-lg"></i>
                        Year Transition
                    </a>
                </nav>
            </div>
            
            <!-- Desktop logout -->
            <div class="flex-shrink-0 px-4 py-4 border-t border-gray-200">
                <a href="../logout.php" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt mr-3 text-lg"></i>
                    Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="md:pl-64 pt-16 min-h-screen flex flex-col bg-gray-100">
        <div class="flex-1">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-6 sm:mb-8">
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">Manage Absence Requests</h1>
                        <p class="text-gray-600 text-sm sm:text-base mt-1">Review and process student CAS absence requests</p>
                    </div>
                    
                    <a href="dashboard.php" class="mt-4 sm:mt-0 inline-flex items-center text-purple-600 hover:text-purple-800 text-sm sm:text-base">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
                    </a>
                </div>
                
                <?php if (!empty($message)): ?>
                <div class="mb-6 sm:mb-8 p-4 rounded-lg border-l-4 <?php echo $message_type === 'error' ? 'bg-red-50 border-red-400' : 'bg-green-50 border-green-400'; ?> shadow-md" role="alert">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <?php if ($message_type === 'error'): ?>
                                <i class="fas fa-exclamation-circle text-red-400 text-lg"></i>
                            <?php else: ?>
                                <i class="fas fa-check-circle text-green-400 text-lg"></i>
                            <?php endif; ?>
                        </div>
                        <div class="ml-3 flex-1">
                            <p class="text-sm font-medium <?php echo $message_type === 'error' ? 'text-red-800' : 'text-green-800'; ?>"><?php echo htmlspecialchars($message); ?></p>
                        </div>
                        <button type="button" class="flex-shrink-0 ml-4 p-1 hover:bg-black hover:bg-opacity-10 rounded transition-colors" onclick="this.parentElement.parentElement.remove()">
                            <i class="fas fa-times <?php echo $message_type === 'error' ? 'text-red-400' : 'text-green-400'; ?>"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Filters and Stats -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 mb-6 sm:mb-8">
                    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-4 sm:mb-6">
                        <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4 lg:mb-0">Request Statistics</h2>
                        
                        <div class="flex flex-wrap gap-2">
                            <a href="?status=pending" class="px-3 sm:px-4 py-2 <?php echo $filter_status === 'pending' ? 'bg-yellow-100 text-yellow-800 border-yellow-300' : 'bg-gray-100 text-gray-800 border-gray-300'; ?> rounded-lg border hover:bg-yellow-50 text-xs sm:text-sm font-medium transition-colors">
                                Pending (<?php echo $counts['pending']; ?>)
                            </a>
                            <a href="?status=approved" class="px-3 sm:px-4 py-2 <?php echo $filter_status === 'approved' ? 'bg-green-100 text-green-800 border-green-300' : 'bg-gray-100 text-gray-800 border-gray-300'; ?> rounded-lg border hover:bg-green-50 text-xs sm:text-sm font-medium transition-colors">
                                Approved (<?php echo $counts['approved']; ?>)
                            </a>
                            <a href="?status=declined" class="px-3 sm:px-4 py-2 <?php echo $filter_status === 'declined' ? 'bg-red-100 text-red-800 border-red-300' : 'bg-gray-100 text-gray-800 border-gray-300'; ?> rounded-lg border hover:bg-red-50 text-xs sm:text-sm font-medium transition-colors">
                                Declined (<?php echo $counts['declined']; ?>)
                            </a>
                            <a href="?status=all" class="px-3 sm:px-4 py-2 <?php echo $filter_status === 'all' ? 'bg-blue-100 text-blue-800 border-blue-300' : 'bg-gray-100 text-gray-800 border-gray-300'; ?> rounded-lg border hover:bg-blue-50 text-xs sm:text-sm font-medium transition-colors">
                                All (<?php echo $counts['total']; ?>)
                            </a>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6">
                        <div class="bg-white p-4 sm:p-6 rounded-lg border border-gray-200 shadow-sm">
                            <div class="flex items-center">
                                <div class="p-2 sm:p-3 rounded-full bg-yellow-100 text-yellow-500 mr-3 sm:mr-4">
                                    <i class="fas fa-clock text-lg sm:text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-xs sm:text-sm text-gray-500 uppercase">Pending Requests</p>
                                    <p class="text-xl sm:text-2xl font-bold"><?php echo $counts['pending']; ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white p-4 sm:p-6 rounded-lg border border-gray-200 shadow-sm">
                            <div class="flex items-center">
                                <div class="p-2 sm:p-3 rounded-full bg-green-100 text-green-500 mr-3 sm:mr-4">
                                    <i class="fas fa-check-circle text-lg sm:text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-xs sm:text-sm text-gray-500 uppercase">Approved Requests</p>
                                    <p class="text-xl sm:text-2xl font-bold"><?php echo $counts['approved']; ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white p-4 sm:p-6 rounded-lg border border-gray-200 shadow-sm">
                            <div class="flex items-center">
                                <div class="p-2 sm:p-3 rounded-full bg-red-100 text-red-500 mr-3 sm:mr-4">
                                    <i class="fas fa-times-circle text-lg sm:text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-xs sm:text-sm text-gray-500 uppercase">Declined Requests</p>
                                    <p class="text-xl sm:text-2xl font-bold"><?php echo $counts['declined']; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Request Review Modal -->
                <?php if ($review_request): ?>
                <div class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full flex items-center justify-center z-50" id="reviewModal">
                    <div class="relative bg-white rounded-lg shadow-xl max-w-2xl mx-4 md:mx-auto my-8 p-5 w-full">
                        <div class="flex justify-between items-center pb-3 border-b border-gray-200 mb-4">
                            <h3 class="text-lg sm:text-xl font-bold text-gray-900">
                                Review Absence Request
                            </h3>
                            <a href="manage_absences.php?status=<?php echo $filter_status; ?>" class="text-gray-400 hover:text-gray-500">
                                <i class="fas fa-times text-xl"></i>
                            </a>
                        </div>
                        
                        <div class="mt-2">
                            <div class="mb-4">
                                <h4 class="text-base sm:text-lg font-medium text-gray-900">
                                    <?php echo htmlspecialchars($review_request['first_name'] . ' ' . $review_request['last_name']); ?> 
                                    <span class="text-sm font-normal text-gray-600">(<?php echo $review_request['grade_year']; ?>)</span>
                                </h4>
                                
                                <div class="mt-1 grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-gray-600">
                                            <span class="font-medium">CAS Activity:</span> <?php echo htmlspecialchars($review_request['cas_name']); ?>
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            <span class="font-medium">Absence Date:</span> <?php echo date('F j, Y', strtotime($review_request['absence_date'])); ?>
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            <span class="font-medium">Staff Confirmer:</span> <?php echo htmlspecialchars($review_request['staff_confirmer']); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600">
                                            <span class="font-medium">Submitted:</span> <?php echo date('F j, Y, g:i A', strtotime($review_request['created_at'])); ?>
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            <span class="font-medium">Status:</span> 
                                            <span class="font-medium 
                                                <?php 
                                                echo $review_request['status'] === 'pending' ? 'text-yellow-600' : 
                                                    ($review_request['status'] === 'approved' ? 'text-green-600' : 'text-red-600'); 
                                                ?>">
                                                <?php echo ucfirst($review_request['status']); ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <h4 class="font-medium text-gray-700">Reason for Absence:</h4>
                                <div class="mt-1 p-3 bg-gray-50 rounded-lg border border-gray-200">
                                    <?php echo nl2br(htmlspecialchars($review_request['reason'])); ?>
                                </div>
                            </div>
                            
                            <?php if ($review_request['status'] !== 'pending'): ?>
                            <div class="mb-4">
                                <h4 class="font-medium text-gray-700">Admin Notes:</h4>
                                <div class="mt-1 p-3 bg-gray-50 rounded-lg border border-gray-200">
                                    <?php echo empty($review_request['admin_notes']) ? 'No notes provided.' : nl2br(htmlspecialchars($review_request['admin_notes'])); ?>
                                </div>
                            </div>
                            
                            <div class="mt-4 flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-3">
                                <form action="manage_absences.php?status=<?php echo $filter_status; ?>" method="POST" onsubmit="return confirm('Delete this absence request permanently? This cannot be undone. Note: if this request was already approved and excused an attendance record, deleting it here will NOT change that attendance status back.');">
                                    <input type="hidden" name="request_id" value="<?php echo $review_request['request_id']; ?>">
                                    <button type="submit" name="delete_request" class="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm sm:text-base">
                                        <i class="fas fa-trash-alt mr-1"></i> Delete Request
                                    </button>
                                </form>
                                <a href="manage_absences.php?status=<?php echo $filter_status; ?>" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors text-sm sm:text-base text-center">
                                    Close
                                </a>
                            </div>
                            <?php else: ?>
                            <form action="manage_absences.php?review=<?php echo $review_request['request_id']; ?>&status=<?php echo $filter_status; ?>" method="POST">
                                <input type="hidden" name="request_id" value="<?php echo $review_request['request_id']; ?>">
                                
                                <div class="mb-4">
                                    <label for="admin_notes" class="block font-medium text-gray-700 text-sm sm:text-base">Admin Notes:</label>
                                    <textarea id="admin_notes" name="admin_notes" rows="3" class="mt-1 p-3 w-full border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 text-sm sm:text-base" placeholder="Optional notes about this absence request"></textarea>
                                </div>
                                
                                <div class="mt-4 flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-3">
                                    <a href="manage_absences.php?status=<?php echo $filter_status; ?>" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors text-center text-sm sm:text-base">
                                        Cancel
                                    </a>
                                    <button type="submit" name="decline_request" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 transition-colors text-sm sm:text-base">
                                        <i class="fas fa-times-circle mr-1"></i> Decline
                                    </button>
                                    <button type="submit" name="approve_request" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 transition-colors text-sm sm:text-base">
                                        <i class="fas fa-check-circle mr-1"></i> Approve
                                    </button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Absence Requests List -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-purple-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                        <h2 class="text-lg sm:text-xl font-bold">
                            <?php 
                            echo ucfirst($filter_status) . " Absence Requests"; 
                            if ($filter_status === 'all') { 
                                echo " (All)"; 
                            }
                            ?>
                        </h2>
                    </div>
                    
                    <?php if (empty($requests)): ?>
                    <div class="p-6 sm:p-8 text-center text-gray-500">
                        <i class="fas fa-calendar-times text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                        <p class="text-base sm:text-lg">No <?php echo $filter_status === 'all' ? '' : $filter_status; ?> absence requests found.</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($requests as $request): ?>
                        <div class="p-4 sm:p-6 hover:bg-gray-50 transition-colors">
                            <div class="flex flex-col lg:flex-row lg:justify-between lg:items-start mb-3">
                                <div class="flex-1">
                                    <h3 class="text-base sm:text-lg font-medium text-gray-900">
                                        <?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?> 
                                        <span class="text-sm text-gray-500">(<?php echo $request['grade_year']; ?>)</span>
                                    </h3>
                                    
                                    <p class="text-sm text-gray-600 mt-1">
                                        <span class="font-medium"><?php echo htmlspecialchars($request['cas_name']); ?></span> - 
                                        <?php echo date('F j, Y', strtotime($request['absence_date'])); ?>
                                    </p>
                                </div>
                                
                                <div class="mt-2 lg:mt-0 text-sm">
                                    <p class="text-gray-500">
                                        Submitted: <?php echo date('M j, g:i A', strtotime($request['created_at'])); ?>
                                    </p>
                                    
                                    <?php if ($request['status'] !== 'pending'): ?>
                                    <p class="text-gray-500">
                                        By: <?php echo htmlspecialchars($request['approved_by_name'] ?: 'Unknown'); ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="mb-3 space-y-1">
                                <div class="text-sm text-gray-600">
                                    <span class="font-medium">Reason:</span> 
                                    <?php 
                                    $short_reason = substr($request['reason'], 0, 100);
                                    echo htmlspecialchars($short_reason) . (strlen($request['reason']) > 100 ? '...' : '');
                                    ?>
                                </div>
                                
                                <div class="text-sm text-gray-600">
                                    <span class="font-medium">Staff Confirmer:</span> 
                                    <?php echo htmlspecialchars($request['staff_confirmer']); ?>
                                </div>
                                
                                <?php if (!empty($request['admin_notes'])): ?>
                                <div class="text-sm text-gray-600">
                                    <span class="font-medium">Admin Notes:</span> 
                                    <?php 
                                    $short_notes = substr($request['admin_notes'], 0, 100);
                                    echo htmlspecialchars($short_notes) . (strlen($request['admin_notes']) > 100 ? '...' : '');
                                    ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-2 sm:space-y-0">
                                <div>
                                    <?php
                                    $status_class = '';
                                    $status_icon = '';
                                    
                                    switch($request['status']) {
                                        case 'pending':
                                            $status_class = 'bg-yellow-100 text-yellow-800';
                                            $status_icon = 'fa-clock';
                                            break;
                                        case 'approved':
                                            $status_class = 'bg-green-100 text-green-800';
                                            $status_icon = 'fa-check-circle';
                                            break;
                                        case 'declined':
                                            $status_class = 'bg-red-100 text-red-800';
                                            $status_icon = 'fa-times-circle';
                                            break;
                                    }
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                        <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                        <?php echo ucfirst($request['status']); ?>
                                    </span>
                                    <?php if ($request['status'] === 'pending'): ?>
                                        <?php if ($request['matching_session_id']): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 ml-2">
                                            <i class="fas fa-clipboard-check mr-1"></i> Session recorded
                                        </span>
                                        <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 ml-2">
                                            <i class="fas fa-clipboard-list mr-1"></i> No session yet
                                        </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4">
                                    <a href="manage_absences.php?review=<?php echo $request['request_id']; ?>&status=<?php echo $filter_status; ?>" class="text-purple-600 hover:text-purple-800 text-sm font-medium">
                                        <i class="fas fa-eye mr-1"></i> View Details
                                    </a>
                                    
                                    <?php if ($request['status'] === 'pending'): ?>
                                    <a href="manage_absences.php?review=<?php echo $request['request_id']; ?>&status=<?php echo $filter_status; ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                        <i class="fas fa-edit mr-1"></i> Process Request
                                    </a>
                                    <?php endif; ?>
                                    
                                    <form action="manage_absences.php?status=<?php echo $filter_status; ?>" method="POST" class="inline" onsubmit="return confirm('Delete this absence request permanently? This cannot be undone. Note: if this request was already approved and excused an attendance record, deleting it here will NOT change that attendance status back.');">
                                        <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                        <button type="submit" name="delete_request" class="text-red-600 hover:text-red-800 text-sm font-medium">
                                            <i class="fas fa-trash-alt mr-1"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-gray-800 text-white py-4 sm:py-6 mt-auto">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col md:flex-row justify-between items-center">
                    <div class="mb-4 md:mb-0">
                        <p class="text-sm sm:text-base">&copy; 2025 UWC Mostar CAS Tracking System</p>
                    </div>
                    <div class="flex space-x-4">
                        <a href="../index.html" class="text-gray-300 hover:text-white text-sm sm:text-base">Home</a>
                        <a href="../index.html#about" class="text-gray-300 hover:text-white text-sm sm:text-base">About</a>
                        <a href="../index.html#contact" class="text-gray-300 hover:text-white text-sm sm:text-base">Contact</a>
                    </div>
                </div>
                <div class="border-t border-gray-700 mt-4 pt-4 text-center">
                    <p class="text-xs sm:text-sm text-gray-400">All rights reserved. (Roni Baker UWCiM25)</p>
                </div>
            </div>
        </footer>
    </div>
    
    <script>
        // Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileSidebarOverlay = document.getElementById('mobile-sidebar-overlay');
            const closeSidebarButton = document.getElementById('close-sidebar-button');
            const mobileSidebarBackdrop = document.getElementById('mobile-sidebar-backdrop');
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenuDropdown = document.getElementById('user-menu-dropdown');

            // Open mobile sidebar
            mobileMenuButton.addEventListener('click', function() {
                mobileSidebarOverlay.classList.remove('hidden');
            });

            // Close mobile sidebar
            function closeMobileSidebar() {
                mobileSidebarOverlay.classList.add('hidden');
            }

            closeSidebarButton.addEventListener('click', closeMobileSidebar);
            mobileSidebarBackdrop.addEventListener('click', closeMobileSidebar);

            // User menu toggle
            userMenuButton.addEventListener('click', function(e) {
                e.stopPropagation();
                userMenuDropdown.classList.toggle('hidden');
            });

            // Close user menu when clicking outside
            document.addEventListener('click', function(e) {
                if (!userMenuButton.contains(e.target) && !userMenuDropdown.contains(e.target)) {
                    userMenuDropdown.classList.add('hidden');
                }
            });

            // Close mobile sidebar when window is resized to desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeMobileSidebar();
                }
            });

            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('[role="alert"]');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease-out';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>