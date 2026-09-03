<?php
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

// Get admin's user ID
$user_id = $_SESSION['user_id'];

// Get CAS activity ID from URL if provided
$cas_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no specific CAS activity is requested, show selection page
$cas_selection = ($cas_id === 0);

// Get all active CAS activities (admin can record for any)
$cas_activities = [];
$stmt = $conn->prepare("
    SELECT 
        ca.cas_id,
        ca.cas_name,
        ca.cas_type,
        ca.cas_day,
        ca.cas_time,
        ca.cas_location,
        COUNT(DISTINCT sce.student_id) AS student_count
    FROM 
        cas_activities ca
    LEFT JOIN 
        student_cas_enrollment sce ON ca.cas_id = sce.cas_id AND sce.is_active = 1
    WHERE 
        ca.is_active = 1
    GROUP BY 
        ca.cas_id, ca.cas_name, ca.cas_type, ca.cas_day, ca.cas_time, ca.cas_location
    ORDER BY 
        ca.cas_day, ca.cas_time
");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $cas_activities[] = $row;
}
$stmt->close();

// Get current activity details if specific CAS is selected
$current_activity = null;
if (!$cas_selection) {
    foreach ($cas_activities as $activity) {
        if ($activity['cas_id'] === $cas_id) {
            $current_activity = $activity;
            break;
        }
    }
    
    if (!$current_activity) {
        // CAS activity not found, redirect to selection
        header("Location: admin_record_attendance.php");
        exit();
    }
}

// Handle form submission for recording attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_attendance'])) {
    // Get other form data
    $notes = trim($_POST['session_notes']);
    $selected_cas_id = (int)$_POST['cas_id'];
    $recorded_by = $user_id;
    
    // Get and validate session date
    if (isset($_POST['session_date']) && !empty($_POST['session_date'])) {
        $session_date = trim($_POST['session_date']);
        
        // Ensure date format is valid (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $session_date)) {
            $message = "Invalid date format. Please use the date picker to select a date.";
            $message_type = "error";
        }
        // Ensure the date is not in the future
        else if (strtotime($session_date) > time()) {
            $message = "Cannot record attendance for future dates.";
            $message_type = "error";
        } 
        else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Insert attendance session
                $stmt = $conn->prepare("INSERT INTO attendance_sessions (cas_id, session_date, recorded_by, notes, created_at) VALUES (?, STR_TO_DATE(?, '%Y-%m-%d'), ?, ?, NOW())");
                $stmt->bind_param("isss", $selected_cas_id, $session_date, $recorded_by, $notes);
                $stmt->execute();
                
                $session_id = $conn->insert_id;
                $stmt->close();
                
                // Get all active students enrolled in this CAS
                $stmt = $conn->prepare("
                    SELECT s.student_id 
                    FROM students s
                    JOIN student_cas_enrollment sce ON s.student_id = sce.student_id
                    WHERE sce.cas_id = ? AND sce.is_active = 1 AND s.is_active = 1
                ");
                $stmt->bind_param("i", $selected_cas_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                // Create attendance records for each student
                $attendance_status = isset($_POST['attendance_status']) ? $_POST['attendance_status'] : [];
                $attendance_notes = isset($_POST['attendance_notes']) ? $_POST['attendance_notes'] : [];
                
                $insert_stmt = $conn->prepare("INSERT INTO attendance_records (session_id, student_id, status, notes) VALUES (?, ?, ?, ?)");
                
                while ($student = $result->fetch_assoc()) {
                    $student_id = $student['student_id'];
                    $status = isset($attendance_status[$student_id]) ? $attendance_status[$student_id] : 'absent';
                    $student_notes = isset($attendance_notes[$student_id]) ? trim($attendance_notes[$student_id]) : '';
                    
                    $insert_stmt->bind_param("iiss", $session_id, $student_id, $status, $student_notes);
                    $insert_stmt->execute();
                }
                
                $insert_stmt->close();
                $stmt->close();
                
                // Auto-apply 'excused' status for any absence requests already approved
                // for this CAS + date. This covers the case where a request was approved
                // before this session was ever recorded - previously that excusal would
                // silently never get applied.
                $excuse_stmt = $conn->prepare("
                    SELECT student_id 
                    FROM absence_requests 
                    WHERE cas_id = ? AND absence_date = ? AND status = 'approved'
                ");
                $excuse_stmt->bind_param("is", $selected_cas_id, $session_date);
                $excuse_stmt->execute();
                $excuse_result = $excuse_stmt->get_result();
                
                $apply_excused_stmt = $conn->prepare("
                    UPDATE attendance_records 
                    SET status = 'excused', 
                        notes = CONCAT(IFNULL(notes, ''), ' [Auto-excused: approved absence request]')
                    WHERE session_id = ? AND student_id = ?
                ");
                $excused_count = 0;
                $excused_student_ids = [];
                while ($excused_student = $excuse_result->fetch_assoc()) {
                    $apply_excused_stmt->bind_param("ii", $session_id, $excused_student['student_id']);
                    $apply_excused_stmt->execute();
                    $excused_count++;
                    $excused_student_ids[] = $excused_student['student_id'];
                }
                $apply_excused_stmt->close();
                $excuse_stmt->close();
                
                // Commit transaction
                $conn->commit();
                $conn->autocommit(true);
                
                // Check the 2-absence warning threshold for every student marked
                // 'absent' in this session (skipping anyone just auto-excused above).
                // Done after commit so the counts this reads reflect the final saved state.
                foreach ($attendance_status as $student_id_checked => $status_checked) {
                    if ($status_checked === 'absent' && !in_array($student_id_checked, $excused_student_ids)) {
                        check_and_send_absence_warning($conn, (int)$student_id_checked, $selected_cas_id);
                    }
                }
                
                $message = "Attendance recorded successfully for date: " . $session_date;
                if ($excused_count > 0) {
                    $message .= " (" . $excused_count . " student" . ($excused_count != 1 ? 's' : '') . " auto-marked excused from approved absence requests.)";
                }
                $message_type = "success";
                
                // Redirect to attendance reports with success message
                header("Location: attendance_report.php?view_type=detailed&cas_id=" . $selected_cas_id . "&success=1");
                exit();
            } catch (Exception $e) {
                // Rollback in case of error
                $conn->rollback();
                $message = "Error recording attendance: " . $e->getMessage();
                $message_type = "error";
            }
        }
    } else {
        $message = "Please select a session date.";
        $message_type = "error";
    }
}

// If we're recording for a specific CAS, get active students
$active_students = [];
if (!$cas_selection && $cas_id > 0) {
    $stmt = $conn->prepare("
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            s.grade_year
        FROM 
            students s
        JOIN 
            student_cas_enrollment sce ON s.student_id = sce.student_id
        WHERE 
            sce.cas_id = ? AND sce.is_active = 1 AND s.is_active = 1
        ORDER BY 
            s.grade_year, s.last_name, s.first_name
    ");
    $stmt->bind_param("i", $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $active_students[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Attendance - UWC Mostar CAS</title>
    <link rel="icon" type="image/x-icon" href="../tab.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
</head>
<body class="bg-gray-100 h-full">
    <!-- Navigation Header -->
    <nav class="bg-white shadow-lg border-b border-gray-200 fixed top-0 left-0 right-0 z-50">
        <div class="mx-auto px-4">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <button type="button" class="md:hidden inline-flex items-center justify-center p-2 rounded-md text-gray-600 hover:text-gray-900 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500 mr-3" id="mobile-menu-button">
                        <span class="sr-only">Open main menu</span>
                        <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    
                    <div class="flex items-center">
                        <img src="../850.png" alt="UWC Mostar Logo" class="h-8 w-auto mr-3">
                    </div>
                </div>

                <div class="flex items-center space-x-4">
                    <span class="hidden sm:inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                        Administrator
                    </span>
                    
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
            
            <div class="flex-1 h-0 pt-5 pb-4 overflow-y-auto">
                <div class="px-4 mb-6">
                    <div class="flex items-center">
                        <div class="h-8 w-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold mr-3">
                            UWC
                        </div>
                        <div>
                            <h1 class="text-lg font-bold text-gray-900">Admin Panel</h1>
                        </div>
                    </div>
                </div>
                
                <nav class="px-4 space-y-1">
                    <a href="dashboard.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="students.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-user-graduate mr-3 text-lg"></i>
                        Students
                    </a>
                    
                    <a href="cas_activities.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-alt mr-3 text-lg"></i>
                        CAS Activities
                    </a>
                    
                    <a href="admin_record_attendance.php" class="bg-blue-50 border-r-4 border-blue-600 text-blue-700 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-clipboard-check mr-3 text-lg"></i>
                        Record Attendance
                    </a>
                    
                    <a href="users.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-users mr-3 text-lg"></i>
                        Users/Leaders
                    </a>
                    
                    <a href="attendance_report.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-chart-bar mr-3 text-lg"></i>
                        Attendance Reports
                    </a>
                    
                    <a href="manage_absences.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-times mr-3 text-lg"></i>
                        Absence Requests
                    </a>
                    
                    <a href="activity_log.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-history mr-3 text-lg"></i>
                        Activity Log
                    </a>
                    
                    <a href="year_transition.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-arrow-up mr-3 text-lg"></i>
                        Year Transition
                    </a>
                </nav>
            </div>
            
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
                    <a href="dashboard.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="students.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-user-graduate mr-3 text-lg"></i>
                        Students
                    </a>
                    
                    <a href="cas_activities.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-alt mr-3 text-lg"></i>
                        CAS Activities
                    </a>
                    
                    <a href="admin_record_attendance.php" class="bg-blue-50 border-r-4 border-blue-600 text-blue-700 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-clipboard-check mr-3 text-lg"></i>
                        Record Attendance
                    </a>
                    
                    <a href="users.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-users mr-3 text-lg"></i>
                        Users/Leaders
                    </a>
                    
                    <a href="attendance_report.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-chart-bar mr-3 text-lg"></i>
                        Attendance Reports
                    </a>
                    
                    <a href="manage_absences.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-times mr-3 text-lg"></i>
                        Absence Requests
                    </a>
                    
                    <a href="activity_log.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-history mr-3 text-lg"></i>
                        Activity Log
                    </a>
                    
                    <a href="year_transition.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-arrow-up mr-3 text-lg"></i>
                        Year Transition
                    </a>
                </nav>
            </div>
            
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
                    <div class="flex items-center">
                        <a href="dashboard.php" class="mr-4 text-gray-600 hover:text-gray-800 text-sm sm:text-base">
                            <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
                        </a>
                        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">Record Attendance (Admin)</h1>
                    </div>
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
                
                <?php if ($cas_selection): ?>
                <!-- CAS Activity Selection -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 mb-6 sm:mb-8">
                    <h2 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4">Select CAS Activity</h2>
                    <p class="text-gray-600 mb-6 text-sm sm:text-base">Choose a CAS activity to record attendance for:</p>
                    
                    <?php if (empty($cas_activities)): ?>
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-lg">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-yellow-700">
                                    No active CAS activities found. Please create a CAS activity first.
                                </p>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
                        <?php foreach ($cas_activities as $activity): ?>
                        <?php
                        $bg_class = '';
                        $border_class = '';
                        $icon_class = '';
                        switch($activity['cas_type']) {
                            case 'creativity':
                                $bg_class = 'bg-purple-50';
                                $border_class = 'border-purple-200';
                                $icon_class = 'text-purple-500 fa-paintbrush';
                                break;
                            case 'activity':
                                $bg_class = 'bg-blue-50';
                                $border_class = 'border-blue-200';
                                $icon_class = 'text-blue-500 fa-person-running';
                                break;
                            case 'service':
                                $bg_class = 'bg-yellow-50';
                                $border_class = 'border-yellow-200';
                                $icon_class = 'text-yellow-500 fa-hands-helping';
                                break;
                        }
                        ?>
                        <a href="?id=<?php echo $activity['cas_id']; ?>" class="block rounded-lg shadow-sm overflow-hidden border <?php echo $border_class; ?> hover:shadow-md transition-shadow duration-300">
                            <div class="px-4 sm:px-6 py-3 sm:py-4 <?php echo $bg_class; ?> border-b <?php echo $border_class; ?>">
                                <div class="flex items-center">
                                    <div class="mr-3 sm:mr-4">
                                        <i class="fas <?php echo $icon_class; ?> text-xl sm:text-2xl"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-bold text-gray-800 text-sm sm:text-base truncate"><?php echo htmlspecialchars($activity['cas_name']); ?></h3>
                                        <p class="text-xs sm:text-sm text-gray-600"><?php echo ucfirst($activity['cas_type']); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="px-4 sm:px-6 py-3 sm:py-4">
                                <div class="space-y-1 sm:space-y-2">
                                    <div class="flex items-center justify-between text-xs sm:text-sm text-gray-600">
                                        <div>
                                            <i class="fas fa-calendar-day mr-1"></i> <?php echo ucfirst($activity['cas_day']); ?>
                                        </div>
                                        <div>
                                            <i class="fas fa-clock mr-1"></i> <?php echo date('g:i A', strtotime($activity['cas_time'])); ?>
                                        </div>
                                    </div>
                                    <div class="text-xs sm:text-sm text-gray-600">
                                        <i class="fas fa-users mr-1"></i> <?php echo $activity['student_count']; ?> student<?php echo $activity['student_count'] != 1 ? 's' : ''; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="px-4 sm:px-6 py-2 sm:py-3 bg-white border-t border-gray-100 text-center">
                                <span class="text-xs sm:text-sm text-green-600 font-medium">Record Attendance <i class="fas fa-clipboard-check ml-1"></i></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <!-- Record Attendance Form (Same as CAS leader version) -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-green-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                            <h2 class="text-lg sm:text-xl font-bold mb-2 sm:mb-0">Record Attendance</h2>
                            <div class="flex items-center">
                                <?php
                                $icon_class = '';
                                $type_color = '';
                                switch($current_activity['cas_type']) {
                                    case 'creativity':
                                        $icon_class = 'fa-paintbrush';
                                        $type_color = 'bg-purple-100 text-purple-800';
                                        break;
                                    case 'activity':
                                        $icon_class = 'fa-person-running';
                                        $type_color = 'bg-blue-100 text-blue-800';
                                        break;
                                    case 'service':
                                        $icon_class = 'fa-hands-helping';
                                        $type_color = 'bg-yellow-100 text-yellow-800';
                                        break;
                                }
                                ?>
                                <i class="fas <?php echo $icon_class; ?> mr-2"></i>
                                <span class="font-medium"><?php echo htmlspecialchars($current_activity['cas_name']); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Activity Info -->
                    <div class="px-4 sm:px-6 py-3 sm:py-4 bg-gray-50 border-b border-gray-200">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                            <div class="flex items-center">
                                <i class="fas fa-calendar-day mr-2 text-gray-400"></i>
                                <span class="font-medium text-gray-700">Day:</span>
                                <span class="ml-1 text-gray-900"><?php echo ucfirst($current_activity['cas_day']); ?></span>
                            </div>
                            <div class="flex items-center">
                                <i class="fas fa-clock mr-2 text-gray-400"></i>
                                <span class="font-medium text-gray-700">Time:</span>
                                <span class="ml-1 text-gray-900"><?php echo date('g:i A', strtotime($current_activity['cas_time'])); ?></span>
                            </div>
                            <div class="flex items-center">
                                <i class="fas fa-users mr-2 text-gray-400"></i>
                                <span class="font-medium text-gray-700">Students:</span>
                                <span class="ml-1 text-gray-900"><?php echo count($active_students); ?></span>
                            </div>
                        </div>
                        <?php if (!empty($current_activity['cas_location'])): ?>
                        <div class="mt-2 flex items-center text-sm">
                            <i class="fas fa-map-marker-alt mr-2 text-gray-400"></i>
                            <span class="font-medium text-gray-700">Location:</span>
                            <span class="ml-1 text-gray-900"><?php echo htmlspecialchars($current_activity['cas_location']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($active_students)): ?>
                    <!-- No Students Message -->
                    <div class="p-6 sm:p-8 text-center">
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-lg">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm text-yellow-700">
                                        No active students are enrolled in this CAS activity. Please enroll students first.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Attendance Form -->
                    <form method="POST" action="" class="p-4 sm:p-6">
                        <input type="hidden" name="cas_id" value="<?php echo $cas_id; ?>">
                        
                        <!-- Session Date and Notes -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                            <div>
                                <label for="session_date" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-calendar-day mr-1"></i> Session Date *
                                </label>
                                <input type="date" 
                                       id="session_date" 
                                       name="session_date" 
                                       required 
                                       max="<?php echo date('Y-m-d'); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm sm:text-base">
                                <p class="mt-1 text-xs text-gray-500">Cannot be a future date</p>
                            </div>
                            
                            <div>
                                <label for="session_notes" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-sticky-note mr-1"></i> Session Notes (Optional)
                                </label>
                                <textarea id="session_notes" 
                                          name="session_notes" 
                                          rows="3" 
                                          placeholder="Add any notes about this session..."
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 resize-none text-sm sm:text-base"></textarea>
                            </div>
                        </div>
                        
                        <!-- Attendance List -->
                        <div class="mb-6">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
                                <h3 class="text-lg font-semibold text-gray-800 mb-2 sm:mb-0">
                                    <i class="fas fa-users mr-2"></i> Student Attendance
                                </h3>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" 
                                            onclick="markAllPresent()" 
                                            class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium hover:bg-green-200 transition-colors">
                                        <i class="fas fa-check mr-1"></i> Mark All Present
                                    </button>
                                    <button type="button" 
                                            onclick="markAllAbsent()" 
                                            class="px-3 py-1 bg-red-100 text-red-800 rounded-full text-xs font-medium hover:bg-red-200 transition-colors">
                                        <i class="fas fa-times mr-1"></i> Mark All Absent
                                    </button>
                                    <button type="button" 
                                            onclick="clearAll()" 
                                            class="px-3 py-1 bg-gray-100 text-gray-800 rounded-full text-xs font-medium hover:bg-gray-200 transition-colors">
                                        <i class="fas fa-eraser mr-1"></i> Clear All
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Mobile View -->
                            <div class="block md:hidden space-y-4">
                                <?php foreach ($active_students as $student): ?>
                                <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                                    <div class="flex items-center justify-between mb-3">
                                        <div>
                                            <h4 class="font-medium text-gray-900">
                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                            </h4>
                                            <p class="text-sm text-gray-500">Grade <?php echo htmlspecialchars($student['grade_year']); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="space-y-3">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Attendance Status</label>
                                            <div class="grid grid-cols-3 gap-2">
                                                <label class="flex items-center p-2 border border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50 attendance-option">
                                                    <input type="radio" 
                                                           name="attendance_status[<?php echo $student['student_id']; ?>]" 
                                                           value="present" 
                                                           class="sr-only attendance-radio">
                                                    <div class="w-4 h-4 border-2 border-gray-300 rounded-full mr-2 radio-circle"></div>
                                                    <div class="flex-1 text-center">
                                                        <i class="fas fa-check text-green-600 mb-1"></i>
                                                        <div class="text-xs font-medium text-gray-700">Present</div>
                                                    </div>
                                                </label>
                                                
                                                <label class="flex items-center p-2 border border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50 attendance-option">
                                                    <input type="radio" 
                                                           name="attendance_status[<?php echo $student['student_id']; ?>]" 
                                                           value="absent" 
                                                           class="sr-only attendance-radio">
                                                    <div class="w-4 h-4 border-2 border-gray-300 rounded-full mr-2 radio-circle"></div>
                                                    <div class="flex-1 text-center">
                                                        <i class="fas fa-times text-red-600 mb-1"></i>
                                                        <div class="text-xs font-medium text-gray-700">Absent</div>
                                                    </div>
                                                </label>
                                                
                                                <label class="flex items-center p-2 border border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50 attendance-option">
                                                    <input type="radio" 
                                                           name="attendance_status[<?php echo $student['student_id']; ?>]" 
                                                           value="excused" 
                                                           class="sr-only attendance-radio">
                                                    <div class="w-4 h-4 border-2 border-gray-300 rounded-full mr-2 radio-circle"></div>
                                                    <div class="flex-1 text-center">
                                                        <i class="fas fa-user-check text-yellow-600 mb-1"></i>
                                                        <div class="text-xs font-medium text-gray-700">Excused</div>
                                                    </div>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes (Optional)</label>
                                            <textarea name="attendance_notes[<?php echo $student['student_id']; ?>]" 
                                                      rows="2" 
                                                      placeholder="Add notes for this student..."
                                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 resize-none"></textarea>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Desktop View -->
                            <div class="hidden md:block overflow-x-auto">
                                <table class="min-w-full bg-white border border-gray-200 rounded-lg overflow-hidden">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grade</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance Status</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach ($active_students as $index => $student): ?>
                                        <tr class="<?php echo $index % 2 === 0 ? 'bg-white' : 'bg-gray-50'; ?> hover:bg-blue-50 transition-colors">
                                            <td class="px-4 py-4 whitespace-nowrap">
                                                <div class="font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                                                Grade <?php echo htmlspecialchars($student['grade_year']); ?>
                                            </td>
                                            <td class="px-4 py-4 whitespace-nowrap">
                                                <div class="flex justify-center space-x-4">
                                                    <label class="flex items-center cursor-pointer attendance-option">
                                                        <input type="radio" 
                                                               name="attendance_status[<?php echo $student['student_id']; ?>]" 
                                                               value="present" 
                                                               class="sr-only attendance-radio">
                                                        <div class="w-4 h-4 border-2 border-gray-300 rounded-full mr-2 radio-circle"></div>
                                                        <span class="text-sm font-medium text-green-600">
                                                            <i class="fas fa-check mr-1"></i> Present
                                                        </span>
                                                    </label>
                                                    
                                                    <label class="flex items-center cursor-pointer attendance-option">
                                                        <input type="radio" 
                                                               name="attendance_status[<?php echo $student['student_id']; ?>]" 
                                                               value="absent" 
                                                               class="sr-only attendance-radio">
                                                        <div class="w-4 h-4 border-2 border-gray-300 rounded-full mr-2 radio-circle"></div>
                                                        <span class="text-sm font-medium text-red-600">
                                                            <i class="fas fa-times mr-1"></i> Absent
                                                        </span>
                                                    </label>
                                                    
                                                    <label class="flex items-center cursor-pointer attendance-option">
                                                        <input type="radio" 
                                                               name="attendance_status[<?php echo $student['student_id']; ?>]" 
                                                               value="excused" 
                                                               class="sr-only attendance-radio">
                                                        <div class="w-4 h-4 border-2 border-gray-300 rounded-full mr-2 radio-circle"></div>
                                                        <span class="text-sm font-medium text-yellow-600">
                                                            <i class="fas fa-user-check mr-1"></i> Excused
                                                        </span>
                                                    </label>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4">
                                                <textarea name="attendance_notes[<?php echo $student['student_id']; ?>]" 
                                                          rows="1" 
                                                          placeholder="Notes..."
                                                          class="w-full px-2 py-1 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 resize-none"></textarea>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="flex flex-col sm:flex-row justify-between items-center space-y-4 sm:space-y-0 pt-6 border-t border-gray-200">
                            <div class="flex items-center text-sm text-gray-600">
                                <i class="fas fa-info-circle mr-2"></i>
                                <span>Recording attendance for <?php echo count($active_students); ?> student<?php echo count($active_students) != 1 ? 's' : ''; ?></span>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-3 w-full sm:w-auto">
                                <a href="dashboard.php" 
                                   class="w-full sm:w-auto px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 focus:ring-2 focus:ring-gray-500 transition-colors text-center text-sm font-medium">
                                    <i class="fas fa-times mr-2"></i> Cancel
                                </a>
                                <button type="submit" 
                                        name="record_attendance" 
                                        class="w-full sm:w-auto px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:ring-2 focus:ring-green-500 transition-colors text-center text-sm font-medium">
                                    <i class="fas fa-save mr-2"></i> Record Attendance
                                </button>
                            </div>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Date picker JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
    
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
            if (mobileMenuButton) {
                mobileMenuButton.addEventListener('click', function() {
                    mobileSidebarOverlay.classList.remove('hidden');
                });
            }

            // Close mobile sidebar
            function closeMobileSidebar() {
                mobileSidebarOverlay.classList.add('hidden');
            }

            if (closeSidebarButton) {
                closeSidebarButton.addEventListener('click', closeMobileSidebar);
            }
            
            if (mobileSidebarBackdrop) {
                mobileSidebarBackdrop.addEventListener('click', closeMobileSidebar);
            }

            // User menu toggle
            if (userMenuButton) {
                userMenuButton.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userMenuDropdown.classList.toggle('hidden');
                });
            }

            // Close user menu when clicking outside
            document.addEventListener('click', function(e) {
                if (userMenuButton && userMenuDropdown) {
                    if (!userMenuButton.contains(e.target) && !userMenuDropdown.contains(e.target)) {
                        userMenuDropdown.classList.add('hidden');
                    }
                }
            });

            // Close mobile sidebar when window is resized to desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeMobileSidebar();
                }
            });

            // Initialize date picker
            flatpickr('#session_date', {
                maxDate: 'today',
                dateFormat: 'Y-m-d',
                defaultDate: 'today'
            });

            // Handle radio button styling
            document.querySelectorAll('.attendance-option').forEach(function(option) {
                option.addEventListener('click', function() {
                    const radio = this.querySelector('.attendance-radio');
                    const circle = this.querySelector('.radio-circle');
                    
                    // Clear all other options in the same group
                    const groupName = radio.name;
                    document.querySelectorAll(`input[name="${groupName}"]`).forEach(function(otherRadio) {
                        const otherOption = otherRadio.closest('.attendance-option');
                        const otherCircle = otherOption.querySelector('.radio-circle');
                        
                        otherCircle.classList.remove('bg-green-500', 'bg-red-500', 'bg-yellow-500', 'border-green-500', 'border-red-500', 'border-yellow-500');
                        otherCircle.classList.add('border-gray-300');
                        otherOption.classList.remove('bg-green-50', 'bg-red-50', 'bg-yellow-50', 'border-green-300', 'border-red-300', 'border-yellow-300');
                        otherOption.classList.add('border-gray-300');
                    });
                    
                    // Style the selected option
                    radio.checked = true;
                    
                    if (radio.value === 'present') {
                        circle.classList.remove('border-gray-300');
                        circle.classList.add('bg-green-500', 'border-green-500');
                        option.classList.remove('border-gray-300');
                        option.classList.add('bg-green-50', 'border-green-300');
                    } else if (radio.value === 'absent') {
                        circle.classList.remove('border-gray-300');
                        circle.classList.add('bg-red-500', 'border-red-500');
                        option.classList.remove('border-gray-300');
                        option.classList.add('bg-red-50', 'border-red-300');
                    } else if (radio.value === 'excused') {
                        circle.classList.remove('border-gray-300');
                        circle.classList.add('bg-yellow-500', 'border-yellow-500');
                        option.classList.remove('border-gray-300');
                        option.classList.add('bg-yellow-50', 'border-yellow-300');
                    }
                });
            });
        });

        // Bulk attendance functions
        function markAllPresent() {
            document.querySelectorAll('input[type="radio"][value="present"]').forEach(function(radio) {
                radio.closest('.attendance-option').click();
            });
        }

        function markAllAbsent() {
            document.querySelectorAll('input[type="radio"][value="absent"]').forEach(function(radio) {
                radio.closest('.attendance-option').click();
            });
        }

        function clearAll() {
            document.querySelectorAll('.attendance-radio').forEach(function(radio) {
                radio.checked = false;
                const option = radio.closest('.attendance-option');
                const circle = option.querySelector('.radio-circle');
                
                circle.classList.remove('bg-green-500', 'bg-red-500', 'bg-yellow-500', 'border-green-500', 'border-red-500', 'border-yellow-500');
                circle.classList.add('border-gray-300');
                option.classList.remove('bg-green-50', 'bg-red-50', 'bg-yellow-50', 'border-green-300', 'border-red-300', 'border-yellow-300');
                option.classList.add('border-gray-300');
            });
            
            // Clear all notes
            document.querySelectorAll('textarea[name^="attendance_notes"]').forEach(function(textarea) {
                textarea.value = '';
            });
        }
    </script>
</body>
</html>