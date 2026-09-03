<?php
// Start session for user authentication
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_status'] !== 'admin') {
    // Not logged in or not an admin, redirect to login page
    header("Location: ../login.php");
    exit();
}

// Database connection
require_once '../includes/db_connect.php';

// Message handling
$message = "";
$message_type = "";

// Get student ID from URL
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($student_id === 0) {
    // No student ID provided, redirect to students list
    header("Location: students.php");
    exit();
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Enroll student in CAS activity
    if (isset($_POST['enroll_student'])) {
        $cas_id = (int)$_POST['cas_id'];
        
        // Check if already enrolled
        $stmt = $conn->prepare("SELECT enrollment_id FROM student_cas_enrollment WHERE student_id = ? AND cas_id = ?");
        $stmt->bind_param("ii", $student_id, $cas_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Already enrolled, update enrollment if inactive
            $enrollment = $result->fetch_assoc();
            $stmt = $conn->prepare("UPDATE student_cas_enrollment SET is_active = 1 WHERE enrollment_id = ?");
            $stmt->bind_param("i", $enrollment['enrollment_id']);
            
            if ($stmt->execute()) {
                $message = "Student's enrollment has been reactivated.";
                $message_type = "success";
            } else {
                $message = "Error updating enrollment: " . $conn->error;
                $message_type = "error";
            }
        } else {
            // Not enrolled, create new enrollment
            $stmt = $conn->prepare("INSERT INTO student_cas_enrollment (student_id, cas_id, enrollment_date, is_active) VALUES (?, ?, CURRENT_DATE(), 1)");
            $stmt->bind_param("ii", $student_id, $cas_id);
            
            if ($stmt->execute()) {
                $message = "Student successfully enrolled in CAS activity.";
                $message_type = "success";
            } else {
                $message = "Error enrolling student: " . $conn->error;
                $message_type = "error";
            }
        }
        
        $stmt->close();
    }
    
    // Remove student from CAS activity
    if (isset($_POST['unenroll_student'])) {
        $enrollment_id = (int)$_POST['enrollment_id'];
        
        // Fully remove the enrollment record rather than soft-deleting it.
        // This is safe: attendance_records references student_id and session_id
        // directly (not enrollment_id), so the student's attendance history for
        // this CAS is preserved even though the enrollment link itself is gone.
        $stmt = $conn->prepare("DELETE FROM student_cas_enrollment WHERE enrollment_id = ? AND student_id = ?");
        $stmt->bind_param("ii", $enrollment_id, $student_id);
        
        if ($stmt->execute()) {
            $message = "Student removed from CAS activity.";
            $message_type = "success";
        } else {
            $message = "Error removing student: " . $conn->error;
            $message_type = "error";
        }
        
        $stmt->close();
    }
}

// Get student details
$student = null;
$stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Student not found, redirect to students list
    header("Location: students.php");
    exit();
}

$student = $result->fetch_assoc();
$stmt->close();

// Check if student is a CAS leader
$is_cas_leader = false;
$cas_leader_id = null;
$stmt = $conn->prepare("SELECT user_id FROM users WHERE student_id = ? AND user_status = 'cas_leader'");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $is_cas_leader = true;
    $cas_leader_id = $result->fetch_assoc()['user_id'];
}
$stmt->close();

// Get student's CAS enrollments
$enrollments = [];
$stmt = $conn->prepare("
    SELECT 
        sce.enrollment_id,
        sce.enrollment_date,
        sce.is_active,
        ca.cas_id,
        ca.cas_name,
        ca.cas_type,
        ca.cas_day,
        ca.cas_time,
        ca.cas_location,
        GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', ') AS leader_names
    FROM 
        student_cas_enrollment sce
    JOIN 
        cas_activities ca ON sce.cas_id = ca.cas_id
    LEFT JOIN 
        cas_leaders cl ON ca.cas_id = cl.cas_id
    LEFT JOIN 
        users u ON cl.user_id = u.user_id
    WHERE 
        sce.student_id = ?
    GROUP BY 
        sce.enrollment_id, sce.enrollment_date, sce.is_active, 
        ca.cas_id, ca.cas_name, ca.cas_type, ca.cas_day, ca.cas_time, ca.cas_location
    ORDER BY 
        sce.is_active DESC, ca.cas_type, ca.cas_name
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $enrollments[] = $row;
}
$stmt->close();

// Get attendance summary
$attendance_summary = [];
$stmt = $conn->prepare("
    SELECT 
        ca.cas_id,
        ca.cas_name,
        ca.cas_type,
        COUNT(DISTINCT ats.session_id) AS total_sessions,
        SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
        SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END) AS excused_count,
        ROUND((SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) / COUNT(ar.record_id)) * 100, 1) AS attendance_rate
    FROM 
        cas_activities ca
    JOIN 
        student_cas_enrollment sce ON ca.cas_id = sce.cas_id AND sce.student_id = ?
    LEFT JOIN 
        attendance_sessions ats ON ca.cas_id = ats.cas_id
    LEFT JOIN 
        attendance_records ar ON ats.session_id = ar.session_id AND ar.student_id = ?
    GROUP BY 
        ca.cas_id, ca.cas_name, ca.cas_type
    ORDER BY 
        ca.cas_type, ca.cas_name
");
$stmt->bind_param("ii", $student_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $attendance_summary[] = $row;
}
$stmt->close();

// Get recent attendance records
$recent_attendance = [];
$stmt = $conn->prepare("
    SELECT 
        ar.record_id,
        ar.status,
        ar.notes,
        ats.session_date,
        ca.cas_id,
        ca.cas_name,
        ca.cas_type,
        CONCAT(u.first_name, ' ', u.last_name) AS recorded_by
    FROM 
        attendance_records ar
    JOIN 
        attendance_sessions ats ON ar.session_id = ats.session_id
    JOIN 
        cas_activities ca ON ats.cas_id = ca.cas_id
    JOIN 
        users u ON ats.recorded_by = u.user_id
    WHERE 
        ar.student_id = ?
    ORDER BY 
        ats.session_date DESC
    LIMIT 10
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $recent_attendance[] = $row;
}
$stmt->close();

// Get available CAS activities for enrollment
$available_cas = [];
$stmt = $conn->prepare("
    SELECT 
        ca.cas_id,
        ca.cas_name,
        ca.cas_type,
        ca.cas_day,
        ca.cas_time
    FROM 
        cas_activities ca
    LEFT JOIN 
        student_cas_enrollment sce ON ca.cas_id = sce.cas_id AND sce.student_id = ? AND sce.is_active = 1
    WHERE 
        ca.is_active = 1 AND sce.enrollment_id IS NULL
    ORDER BY 
        ca.cas_type, ca.cas_name
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $available_cas[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Details - UWC Mostar CAS</title>
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
                        <div class="h-8 w-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold mr-3">UWC</div>
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
                    
                    <a href="students.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' || basename($_SERVER['PHP_SELF']) == 'student_details.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
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
                    
                    <a href="students.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' || basename($_SERVER['PHP_SELF']) == 'student_details.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
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
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 sm:mb-8 space-y-4 sm:space-y-0">
                    <div class="flex items-center">
                        <a href="students.php" class="mr-3 sm:mr-4 text-gray-600 hover:text-gray-800 transition-colors">
                            <i class="fas fa-arrow-left text-lg sm:text-xl"></i>
                        </a>
                        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">Student Details</h1>
                    </div>
                    
                    <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-3 w-full sm:w-auto">
                        <a href="students.php?action=edit&id=<?php echo $student_id; ?>" class="w-full sm:w-auto bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-3 sm:px-4 rounded transition-colors text-center text-sm sm:text-base">
                            <i class="fas fa-edit mr-2"></i> Edit Student
                        </a>
                        <a href="attendance_report.php?view_type=detailed&student_id=<?php echo $student_id; ?>" class="w-full sm:w-auto bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-3 sm:px-4 rounded transition-colors text-center text-sm sm:text-base">
                            <i class="fas fa-clipboard-list mr-2"></i> Full Attendance Report
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($message)): ?>
                <div class="mb-6 alert-dismissible <?php echo $message_type === 'error' ? 'bg-red-100 border-red-400 text-red-700' : 'bg-green-100 border-green-400 text-green-700'; ?> px-4 py-3 rounded relative border" role="alert">
                    <span class="block sm:inline"><?php echo $message; ?></span>
                    <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
                
                <!-- Student Profile -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 lg:p-8 mb-6 sm:mb-8">
                    <div class="flex flex-col md:flex-row">
                        <div class="md:w-1/4 flex justify-center md:justify-start mb-6 md:mb-0">
                            <div class="h-24 w-24 sm:h-32 sm:w-32 bg-blue-100 rounded-full flex items-center justify-center text-3xl sm:text-4xl text-blue-800 font-bold">
                                <?php echo substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1); ?>
                            </div>
                        </div>
                        
                        <div class="md:w-3/4 text-center md:text-left">
                            <div class="flex flex-col md:flex-row md:items-center mb-4 space-y-4 md:space-y-0">
                                <h2 class="text-xl sm:text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h2>
                                
                                <div class="flex flex-wrap justify-center md:justify-start md:ml-4 gap-2">
                                    <span class="px-3 py-1 rounded-full text-sm font-semibold <?php echo $student['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    
                                    <span class="px-3 py-1 rounded-full text-sm font-semibold bg-blue-100 text-blue-800">
                                        <?php echo $student['grade_year']; ?>
                                    </span>
                                    
                                    <?php if ($is_cas_leader): ?>
                                    <span class="px-3 py-1 rounded-full text-sm font-semibold bg-purple-100 text-purple-800">
                                        CAS Leader
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm sm:text-base">
                                <div>
                                    <p class="text-gray-600"><i class="fas fa-envelope mr-2"></i> <?php echo htmlspecialchars($student['email']); ?></p>
                                    <p class="text-gray-600"><i class="fas fa-id-card mr-2"></i> Student ID: <?php echo $student['student_id']; ?></p>
                                </div>
                                
                                <div>
                                    <p class="text-gray-600"><i class="fas fa-calendar-alt mr-2"></i> Created: <?php echo date('M j, Y', strtotime($student['created_at'])); ?></p>
                                    <?php if ($is_cas_leader): ?>
                                    <p class="text-gray-600">
                                        <i class="fas fa-user-tie mr-2"></i> 
                                        <a href="users.php?action=edit&id=<?php echo $cas_leader_id; ?>" class="text-blue-600 hover:text-blue-800">
                                            View CAS Leader Account
                                        </a>
                                    </p>
                                    <?php else: ?>
                                    <p class="text-gray-600">
                                        <i class="fas fa-user-plus mr-2"></i> 
                                        <a href="users.php?action=add" class="text-blue-600 hover:text-blue-800">
                                            Create CAS Leader Account
                                        </a>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- CAS Enrollments -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                            <div class="bg-blue-600 text-white px-4 sm:px-6 py-4 flex flex-col sm:flex-row justify-between items-start sm:items-center space-y-2 sm:space-y-0">
                                <h2 class="text-lg sm:text-xl font-bold">CAS Activities</h2>
                                
                                <?php if (!empty($available_cas)): ?>
                                <button type="button" onclick="toggleEnrollForm()" class="w-full sm:w-auto px-3 sm:px-4 py-2 bg-white text-blue-600 rounded-md hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-white text-sm sm:text-base">
                                    <i class="fas fa-plus mr-2"></i> Enroll in CAS
                                </button>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Enrollment Form (Hidden by Default) -->
                            <div id="enrollForm" class="px-4 sm:px-6 py-4 bg-blue-50 border-b border-blue-100 hidden">
                                <h3 class="text-base sm:text-lg font-semibold text-blue-800 mb-4">Enroll in CAS Activity</h3>
                                
                                <?php if (empty($available_cas)): ?>
                                <p class="text-gray-700 text-sm sm:text-base">No available CAS activities for enrollment.</p>
                                <?php else: ?>
                                <form action="student_details.php?id=<?php echo $student_id; ?>" method="POST">
                                    <div class="mb-4">
                                        <label for="cas_id" class="block text-sm font-medium text-gray-700 mb-1">Select CAS Activity</label>
                                        <select id="cas_id" name="cas_id" required
                                                class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base">
                                            <option value="">Select a CAS activity</option>
                                            <?php foreach ($available_cas as $cas): ?>
                                            <option value="<?php echo $cas['cas_id']; ?>">
                                                <?php echo htmlspecialchars($cas['cas_name'] . ' (' . ucfirst($cas['cas_type']) . ')'); ?> - 
                                                <?php echo ucfirst($cas['cas_day']) . ' at ' . date('g:i A', strtotime($cas['cas_time'])); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-2">
                                        <button type="button" onclick="toggleEnrollForm()" class="w-full sm:w-auto px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 text-sm sm:text-base">
                                            Cancel
                                        </button>
                                        <button type="submit" name="enroll_student" class="w-full sm:w-auto px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm sm:text-base">
                                            Enroll Student
                                        </button>
                                    </div>
                                </form>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (empty($enrollments)): ?>
                            <div class="p-6 sm:p-8 text-center text-gray-500">
                                <i class="fas fa-users text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                                <p class="text-base sm:text-lg">No CAS Activities</p>
                                <p class="text-sm">This student is not enrolled in any CAS activities.</p>
                            </div>
                            <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                CAS Activity
                                            </th>
                                            <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Type
                                            </th>
                                            <th scope="col" class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Schedule
                                            </th>
                                            <th scope="col" class="hidden lg:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Leader
                                            </th>
                                            <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Status
                                            </th>
                                            <th scope="col" class="hidden sm:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Enrolled
                                            </th>
                                            <th scope="col" class="px-4 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($enrollments as $enrollment): ?>
                                        <tr class="hover:bg-gray-50 <?php echo !$enrollment['is_active'] ? 'bg-gray-50 text-gray-400' : ''; ?>">
                                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                <div>
                                                    <a href="cas_details.php?id=<?php echo $enrollment['cas_id']; ?>" class="text-blue-600 hover:text-blue-900 <?php echo !$enrollment['is_active'] ? 'text-gray-400' : ''; ?> font-medium text-sm sm:text-base">
                                                        <?php echo htmlspecialchars($enrollment['cas_name']); ?>
                                                    </a>
                                                    <div class="md:hidden text-xs text-gray-500 mt-1">
                                                        <?php echo ucfirst($enrollment['cas_day']) . ' at ' . date('g:i A', strtotime($enrollment['cas_time'])); ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                <?php
                                                $type_class = '';
                                                switch($enrollment['cas_type']) {
                                                    case 'creativity':
                                                        $type_class = !$enrollment['is_active'] ? 'bg-gray-100 text-gray-600' : 'bg-purple-100 text-purple-800';
                                                        break;
                                                    case 'activity':
                                                        $type_class = !$enrollment['is_active'] ? 'bg-gray-100 text-gray-600' : 'bg-blue-100 text-blue-800';
                                                        break;
                                                    case 'service':
                                                        $type_class = !$enrollment['is_active'] ? 'bg-gray-100 text-gray-600' : 'bg-yellow-100 text-yellow-800';
                                                        break;
                                                }
                                                ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $type_class; ?>">
                                                    <?php echo ucfirst($enrollment['cas_type']); ?>
                                                </span>
                                            </td>
                                            <td class="hidden md:table-cell px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm <?php echo !$enrollment['is_active'] ? 'text-gray-400' : 'text-gray-900'; ?>">
                                                    <?php echo ucfirst($enrollment['cas_day']); ?>
                                                </div>
                                                <div class="text-sm <?php echo !$enrollment['is_active'] ? 'text-gray-400' : 'text-gray-500'; ?>">
                                                    <?php echo date('g:i A', strtotime($enrollment['cas_time'])); ?>
                                                </div>
                                            </td>
                                            <td class="hidden lg:table-cell px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm <?php echo !$enrollment['is_active'] ? 'text-gray-400' : 'text-gray-900'; ?>">
                                                    <?php echo htmlspecialchars($enrollment['leader_names'] ?? 'No leader assigned'); ?>
                                                </div>
                                            </td>
                                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                <?php if ($enrollment['is_active']): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                    Active
                                                </span>
                                                <?php else: ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                                    Inactive
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="hidden sm:table-cell px-6 py-4 whitespace-nowrap text-sm <?php echo !$enrollment['is_active'] ? 'text-gray-400' : 'text-gray-500'; ?>">
                                                <?php echo date('M j, Y', strtotime($enrollment['enrollment_date'])); ?>
                                            </td>
                                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <?php if ($enrollment['is_active']): ?>
                                                <form action="student_details.php?id=<?php echo $student_id; ?>" method="POST" class="inline" onsubmit="return confirm('Remove this student from the CAS activity? This will permanently delete the enrollment record. Their attendance history for this CAS will be kept, but they will need to be re-enrolled from scratch if added back later.');">
                                                    <input type="hidden" name="enrollment_id" value="<?php echo $enrollment['enrollment_id']; ?>">
                                                    <button type="submit" name="unenroll_student" class="text-red-600 hover:text-red-900 p-2">
                                                        <i class="fas fa-user-minus"></i>
                                                    </button>
                                                </form>
                                                <?php else: ?>
                                                <form action="student_details.php?id=<?php echo $student_id; ?>" method="POST" class="inline">
                                                    <input type="hidden" name="cas_id" value="<?php echo $enrollment['cas_id']; ?>">
                                                    <button type="submit" name="enroll_student" class="text-green-600 hover:text-green-900 p-2">
                                                        <i class="fas fa-user-plus"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Attendance Summary -->
                        <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                            <div class="bg-amber-600 text-white px-4 sm:px-6 py-4">
                                <h2 class="text-lg sm:text-xl font-bold">Attendance Summary</h2>
                            </div>
                            
                            <?php if (empty($attendance_summary) || (count($attendance_summary) === 1 && empty($attendance_summary[0]['total_sessions']))): ?>
                            <div class="p-6 sm:p-8 text-center text-gray-500">
                                <i class="fas fa-chart-line text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                                <p class="text-base sm:text-lg">No Attendance Records</p>
                                <p class="text-sm">No attendance records found for this student.</p>
                            </div>
                            <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                CAS Activity
                                            </th>
                                            <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Type
                                            </th>
                                            <th scope="col" class="hidden sm:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Total Sessions
                                            </th>
                                            <th scope="col" class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Present
                                            </th>
                                            <th scope="col" class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Absent
                                            </th>
                                            <th scope="col" class="hidden lg:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Excused
                                            </th>
                                            <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Attendance Rate
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($attendance_summary as $summary): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                <div>
                                                    <a href="cas_details.php?id=<?php echo $summary['cas_id']; ?>" class="text-blue-600 hover:text-blue-900 font-medium text-sm sm:text-base">
                                                        <?php echo htmlspecialchars($summary['cas_name']); ?>
                                                    </a>
                                                    <div class="sm:hidden text-xs text-gray-500 mt-1">
                                                        <?php echo $summary['total_sessions']; ?> sessions
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                <?php
                                                $type_class = '';
                                                switch($summary['cas_type']) {
                                                    case 'creativity':
                                                        $type_class = 'bg-purple-100 text-purple-800';
                                                        break;
                                                    case 'activity':
                                                        $type_class = 'bg-blue-100 text-blue-800';
                                                        break;
                                                    case 'service':
                                                        $type_class = 'bg-yellow-100 text-yellow-800';
                                                        break;
                                                }
                                                ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $type_class; ?>">
                                                    <?php echo ucfirst($summary['cas_type']); ?>
                                                </span>
                                            </td>
                                            <td class="hidden sm:table-cell px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo $summary['total_sessions']; ?>
                                            </td>
                                            <td class="hidden md:table-cell px-6 py-4 whitespace-nowrap text-sm text-green-600">
                                                <?php echo $summary['present_count']; ?>
                                            </td>
                                            <td class="hidden md:table-cell px-6 py-4 whitespace-nowrap text-sm text-red-600">
                                                <?php echo $summary['absent_count']; ?>
                                            </td>
                                            <td class="hidden lg:table-cell px-6 py-4 whitespace-nowrap text-sm text-yellow-600">
                                                <?php echo $summary['excused_count']; ?>
                                            </td>
                                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                <?php
                                                $rate = $summary['attendance_rate'];
                                                $color_class = $rate >= 90 ? 'bg-green-100 text-green-800' : 
                                                              ($rate >= 75 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
                                                ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $color_class; ?>">
                                                    <?php echo $rate; ?>%
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recent Attendance Sidebar -->
                    <div>
                        <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                            <div class="bg-green-600 text-white px-4 sm:px-6 py-4">
                                <h2 class="text-lg sm:text-xl font-bold">Recent Attendance</h2>
                            </div>
                            
                            <?php if (empty($recent_attendance)): ?>
                            <div class="p-6 sm:p-8 text-center text-gray-500">
                                <i class="fas fa-calendar-check text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                                <p class="text-base sm:text-lg">No Recent Records</p>
                                <p class="text-sm">No recent attendance records found.</p>
                            </div>
                            <?php else: ?>
                            <div class="divide-y divide-gray-200">
                                <?php foreach ($recent_attendance as $record): ?>
                                <div class="p-4 hover:bg-gray-50">
                                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-2 space-y-2 sm:space-y-0">
                                        <div class="font-semibold text-sm sm:text-base"><?php echo date('M j, Y', strtotime($record['session_date'])); ?></div>
                                        <?php
                                        switch ($record['status']) {
                                            case 'present':
                                                echo '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 self-start sm:self-auto">Present</span>';
                                                break;
                                            case 'absent':
                                                echo '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800 self-start sm:self-auto">Absent</span>';
                                                break;
                                            case 'excused':
                                                echo '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800 self-start sm:self-auto">Excused</span>';
                                                break;
                                        }
                                        ?>
                                    </div>
                                    
                                    <div class="text-sm text-gray-800 font-medium">
                                        <a href="cas_details.php?id=<?php echo $record['cas_id']; ?>" class="text-blue-600 hover:text-blue-900">
                                            <?php echo htmlspecialchars($record['cas_name']); ?>
                                        </a>
                                    </div>
                                    
                                    <?php if (!empty($record['notes'])): ?>
                                    <div class="text-sm text-gray-600 mt-1">
                                        <span class="font-medium">Note:</span> <?php echo htmlspecialchars($record['notes']); ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="text-xs text-gray-500 mt-1">
                                        Recorded by <?php echo htmlspecialchars($record['recorded_by']); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="p-4 bg-gray-50 border-t border-gray-200 text-center">
                                <a href="attendance_report.php?view_type=detailed&student_id=<?php echo $student_id; ?>" class="text-blue-600 hover:text-blue-900 text-sm font-medium">
                                    View All Attendance Records <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- CAS Balance Summary -->
                        <div class="bg-white rounded-lg shadow-md overflow-hidden">
                            <div class="bg-indigo-600 text-white px-4 sm:px-6 py-4">
                                <h2 class="text-lg sm:text-xl font-bold">CAS Balance</h2>
                            </div>
                            
                            <?php
                            // Calculate CAS balance
                            $cas_types = ['creativity' => 0, 'activity' => 0, 'service' => 0];
                            $active_enrollments = array_filter($enrollments, function($e) { return $e['is_active']; });
                            
                            foreach ($active_enrollments as $enrollment) {
                                $cas_types[$enrollment['cas_type']]++;
                            }
                            
                            // Total for chart
                            $total_cas = array_sum($cas_types);
                            ?>
                            
                            <div class="p-4 sm:p-6">
                                <div class="grid grid-cols-3 gap-3 sm:gap-4 mb-4 sm:mb-6">
                                    <div class="text-center">
                                        <div class="text-2xl sm:text-3xl font-bold text-purple-600"><?php echo $cas_types['creativity']; ?></div>
                                        <div class="text-gray-600 text-xs sm:text-sm">Creativity</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl sm:text-3xl font-bold text-blue-600"><?php echo $cas_types['activity']; ?></div>
                                        <div class="text-gray-600 text-xs sm:text-sm">Activity</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl sm:text-3xl font-bold text-yellow-600"><?php echo $cas_types['service']; ?></div>
                                        <div class="text-gray-600 text-xs sm:text-sm">Service</div>
                                    </div>
                                </div>
                                
                                <?php if ($total_cas > 0): ?>
                                <div class="h-4 sm:h-6 bg-gray-200 rounded-full overflow-hidden">
                                    <div class="flex h-full">
                                        <?php if ($cas_types['creativity'] > 0): ?>
                                        <div class="bg-purple-500 h-full transition-all duration-300" style="width: <?php echo ($cas_types['creativity'] / $total_cas) * 100; ?>%"></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($cas_types['activity'] > 0): ?>
                                        <div class="bg-blue-500 h-full transition-all duration-300" style="width: <?php echo ($cas_types['activity'] / $total_cas) * 100; ?>%"></div>
                                        <?php endif; ?>
                                        
                                        <?php if ($cas_types['service'] > 0): ?>
                                        <div class="bg-yellow-500 h-full transition-all duration-300" style="width: <?php echo ($cas_types['service'] / $total_cas) * 100; ?>%"></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs text-gray-600 mt-3">
                                    <div class="text-center">
                                        <span class="inline-block w-3 h-3 bg-purple-500 rounded-full mr-1"></span>
                                        Creativity: <?php echo round(($cas_types['creativity'] / $total_cas) * 100); ?>%
                                    </div>
                                    <div class="text-center">
                                        <span class="inline-block w-3 h-3 bg-blue-500 rounded-full mr-1"></span>
                                        Activity: <?php echo round(($cas_types['activity'] / $total_cas) * 100) ?>%
                                        </div>
                                    <div class="text-center">
                                        <span class="inline-block w-3 h-3 bg-yellow-500 rounded-full mr-1"></span>
                                        Service: <?php echo round(($cas_types['service'] / $total_cas) * 100); ?>%
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="text-center text-gray-500">
                                    <p class="text-sm">No active CAS enrollments</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Total CAS Hours (if tracking hours) -->
                        <div class="bg-white rounded-lg shadow-md overflow-hidden mt-6">
                            <div class="bg-gray-600 text-white px-4 sm:px-6 py-4">
                                <h2 class="text-lg sm:text-xl font-bold">CAS Progress</h2>
                            </div>
                            
                            <div class="p-4 sm:p-6">
                                <div class="grid grid-cols-1 gap-4">
                                    <!-- Overall Attendance Rate -->
                                    <?php
                                    $overall_rate = 0;
                                    $total_attendance_records = 0;
                                    foreach ($attendance_summary as $summary) {
                                        if ($summary['total_sessions'] > 0) {
                                            $overall_rate += $summary['attendance_rate'];
                                            $total_attendance_records++;
                                        }
                                    }
                                    if ($total_attendance_records > 0) {
                                        $overall_rate = round($overall_rate / $total_attendance_records, 1);
                                    }
                                    ?>
                                    
                                    <div class="text-center">
                                        <div class="text-3xl sm:text-4xl font-bold <?php echo $overall_rate >= 90 ? 'text-green-600' : ($overall_rate >= 75 ? 'text-yellow-600' : 'text-red-600'); ?>">
                                            <?php echo $overall_rate; ?>%
                                        </div>
                                        <div class="text-gray-600 text-sm">Overall Attendance Rate</div>
                                    </div>
                                    
                                    <!-- Progress Bar -->
                                    <div class="w-full bg-gray-200 rounded-full h-3">
                                        <div class="<?php echo $overall_rate >= 90 ? 'bg-green-500' : ($overall_rate >= 75 ? 'bg-yellow-500' : 'bg-red-500'); ?> h-3 rounded-full transition-all duration-300" 
                                             style="width: <?php echo $overall_rate; ?>%"></div>
                                    </div>
                                    
                                    <!-- Status Badge -->
                                    <div class="text-center">
                                        <?php if ($overall_rate >= 90): ?>
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-800">
                                            <i class="fas fa-check-circle mr-1"></i> Excellent Attendance
                                        </span>
                                        <?php elseif ($overall_rate >= 75): ?>
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold bg-yellow-100 text-yellow-800">
                                            <i class="fas fa-exclamation-triangle mr-1"></i> Good Attendance
                                        </span>
                                        <?php else: ?>
                                        <span class="px-3 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-800">
                                            <i class="fas fa-times-circle mr-1"></i> Needs Improvement
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Additional Notes -->
                                    <div class="text-center text-xs text-gray-500 mt-2">
                                        <?php if ($total_attendance_records === 0): ?>
                                        No attendance records available
                                        <?php else: ?>
                                        Based on <?php echo $total_attendance_records; ?> active CAS <?php echo $total_attendance_records === 1 ? 'activity' : 'activities'; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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
            if (mobileMenuButton) {
                mobileMenuButton.addEventListener('click', function() {
                    mobileSidebarOverlay.classList.remove('hidden');
                });
            }

            // Close mobile sidebar
            function closeMobileSidebar() {
                if (mobileSidebarOverlay) {
                    mobileSidebarOverlay.classList.add('hidden');
                }
            }

            if (closeSidebarButton) {
                closeSidebarButton.addEventListener('click', closeMobileSidebar);
            }
            
            if (mobileSidebarBackdrop) {
                mobileSidebarBackdrop.addEventListener('click', closeMobileSidebar);
            }

            // User menu toggle
            if (userMenuButton && userMenuDropdown) {
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
            }

            // Close mobile sidebar when window is resized to desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeMobileSidebar();
                }
            });
        });

        // Enrollment form toggle
        function toggleEnrollForm() {
            const enrollForm = document.getElementById('enrollForm');
            if (enrollForm) {
                enrollForm.classList.toggle('hidden');
            }
        }

        // Auto-dismiss alerts
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) {
                // Auto-dismiss after 5 seconds
                setTimeout(function() {
                    if (alert && alert.parentElement) {
                        alert.style.opacity = '0';
                        alert.style.transform = 'translateY(-10px)';
                        setTimeout(function() {
                            alert.remove();
                        }, 300);
                    }
                }, 5000);
            });
        });

        // Confirmation dialogs for destructive actions
        document.addEventListener('DOMContentLoaded', function() {
            const unenrollForms = document.querySelectorAll('form[onsubmit*="confirm"]');
            unenrollForms.forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    if (!confirm('Are you sure you want to remove this student from the CAS activity?')) {
                        e.preventDefault();
                        return false;
                    }
                });
            });
        });

        // Smooth scrolling for internal links
        document.addEventListener('DOMContentLoaded', function() {
            const internalLinks = document.querySelectorAll('a[href^="#"]');
            internalLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href');
                    const targetElement = document.querySelector(targetId);
                    if (targetElement) {
                        targetElement.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });
        });

        // Table row hover effects and interactions
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(function(row) {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8fafc';
                });
                
                row.addEventListener('mouseleave', function() {
                    if (!this.classList.contains('bg-gray-50')) {
                        this.style.backgroundColor = '';
                    }
                });
            });
        });

        // Form validation enhancement
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    const requiredFields = form.querySelectorAll('[required]');
                    let isValid = true;
                    
                    requiredFields.forEach(function(field) {
                        if (!field.value.trim()) {
                            field.classList.add('border-red-500');
                            isValid = false;
                        } else {
                            field.classList.remove('border-red-500');
                        }
                    });
                    
                    if (!isValid) {
                        e.preventDefault();
                        const firstInvalidField = form.querySelector('.border-red-500');
                        if (firstInvalidField) {
                            firstInvalidField.focus();
                            firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                });
            });
        });

        // Loading states for buttons
        document.addEventListener('DOMContentLoaded', function() {
            const submitButtons = document.querySelectorAll('button[type="submit"]');
            submitButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const form = this.closest('form');
                    if (form && form.checkValidity()) {
                        this.disabled = true;
                        const originalText = this.innerHTML;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                        
                        // Re-enable button after 3 seconds as failsafe
                        setTimeout(() => {
                            this.disabled = false;
                            this.innerHTML = originalText;
                        }, 3000);
                    }
                });
            });
        });

        // Responsive table handling
        document.addEventListener('DOMContentLoaded', function() {
            function handleTableResponsiveness() {
                const tables = document.querySelectorAll('table');
                tables.forEach(function(table) {
                    const wrapper = table.closest('.overflow-x-auto');
                    if (wrapper) {
                        if (table.scrollWidth > wrapper.clientWidth) {
                            wrapper.classList.add('shadow-inner');
                        } else {
                            wrapper.classList.remove('shadow-inner');
                        }
                    }
                });
            }
            
            handleTableResponsiveness();
            window.addEventListener('resize', handleTableResponsiveness);
        });

        // Enhanced status badge animations
        document.addEventListener('DOMContentLoaded', function() {
            const statusBadges = document.querySelectorAll('span[class*="rounded-full"]');
            statusBadges.forEach(function(badge) {
                badge.style.transition = 'all 0.2s ease-in-out';
                badge.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.05)';
                });
                badge.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });
        });
    </script>
</body>
</html>