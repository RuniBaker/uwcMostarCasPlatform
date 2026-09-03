<?php
    error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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

    // Get CAS ID from URL
    $cas_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($cas_id === 0) {
        // No CAS ID provided, redirect to CAS activities list
        header("Location: cas_activities.php");
        exit();
    }

    // Process form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Add CAS Leader
        // Add CAS Leader
if (isset($_POST['add_leader'])) {
    error_log("=== ADD LEADER DEBUG START ===");
    $user_id = (int)$_POST['user_id'];
    error_log("Adding leader with user_id: " . $user_id . " to cas_id: " . $cas_id);
    
    // Check if already a leader for this CAS
    $stmt = $conn->prepare("SELECT cas_leader_id FROM cas_leaders WHERE cas_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $cas_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $message = "This user is already a leader for this CAS activity.";
        $message_type = "error";
        error_log("User is already a leader");
    } else {
        // Add new leader
        $stmt = $conn->prepare("INSERT INTO cas_leaders (cas_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $cas_id, $user_id);
        
        if ($stmt->execute()) {
            $message = "CAS leader added successfully.";
            $message_type = "success";
            error_log("CAS leader added successfully");
            
            // Check if this leader is also a student and auto-enroll them
            error_log("Checking if leader is also a student...");
            $check_student_stmt = $conn->prepare("SELECT student_id FROM users WHERE user_id = ? AND student_id IS NOT NULL");
            $check_student_stmt->bind_param("i", $user_id);
            $check_student_stmt->execute();
            $student_result = $check_student_stmt->get_result();
            
            error_log("Student query returned " . $student_result->num_rows . " rows");
            
            if ($student_result->num_rows > 0) {
                $student_data = $student_result->fetch_assoc();
                $student_id = $student_data['student_id'];
                error_log("Leader is also student with ID: " . $student_id);
                
                // Check if this student is already enrolled in this CAS activity
                $check_enrollment_stmt = $conn->prepare("SELECT enrollment_id, is_active FROM student_cas_enrollment WHERE student_id = ? AND cas_id = ?");
                $check_enrollment_stmt->bind_param("ii", $student_id, $cas_id);
                $check_enrollment_stmt->execute();
                $enrollment_result = $check_enrollment_stmt->get_result();
                
                error_log("Enrollment query returned " . $enrollment_result->num_rows . " rows");
                
                if ($enrollment_result->num_rows === 0) {
                    // Student is not enrolled, so enroll them automatically
                    error_log("Student not enrolled, enrolling automatically...");
                    $auto_enroll_stmt = $conn->prepare("INSERT INTO student_cas_enrollment (student_id, cas_id, enrollment_date, is_active) VALUES (?, ?, CURRENT_DATE(), 1)");
                    $auto_enroll_stmt->bind_param("ii", $student_id, $cas_id);
                    
                    if ($auto_enroll_stmt->execute()) {
                        $message .= " The leader has also been automatically enrolled as a student in this CAS activity.";
                        error_log("Auto-enrollment successful");
                    } else {
                        error_log("Auto-enrollment failed: " . $auto_enroll_stmt->error);
                    }
                    $auto_enroll_stmt->close();
                } else {
                    // Check if enrollment exists but is inactive
                    $enrollment_data = $enrollment_result->fetch_assoc();
                    error_log("Existing enrollment found. Active status: " . $enrollment_data['is_active']);
                    
                    if ($enrollment_data['is_active'] == 0) {
                        // Reactivate the enrollment
                        error_log("Reactivating inactive enrollment...");
                        $reactivate_stmt = $conn->prepare("UPDATE student_cas_enrollment SET is_active = 1 WHERE student_id = ? AND cas_id = ?");
                        $reactivate_stmt->bind_param("ii", $student_id, $cas_id);
                        
                        if ($reactivate_stmt->execute()) {
                            $message .= " The leader's student enrollment in this CAS activity has been reactivated.";
                            error_log("Enrollment reactivation successful");
                        } else {
                            error_log("Enrollment reactivation failed: " . $reactivate_stmt->error);
                        }
                        $reactivate_stmt->close();
                    } else {
                        error_log("Student is already actively enrolled");
                        $message .= " The leader is already enrolled as a student in this activity.";
                    }
                }
                
                $check_enrollment_stmt->close();
            } else {
                error_log("Leader is not linked to a student account");
            }
            $check_student_stmt->close();
            
        } else {
            $message = "Error adding CAS leader: " . $conn->error;
            $message_type = "error";
            error_log("Failed to add CAS leader: " . $conn->error);
        }
    }
    
    $stmt->close();
    error_log("=== ADD LEADER DEBUG END ===");
}
        
        // Remove CAS Leader
        if (isset($_POST['remove_leader'])) {
            $cas_leader_id = (int)$_POST['cas_leader_id'];
            
            // Delete the leader association
            $stmt = $conn->prepare("DELETE FROM cas_leaders WHERE cas_leader_id = ? AND cas_id = ?");
            $stmt->bind_param("ii", $cas_leader_id, $cas_id);
            
            if ($stmt->execute()) {
                $message = "CAS leader removed successfully.";
                $message_type = "success";
            } else {
                $message = "Error removing CAS leader: " . $conn->error;
                $message_type = "error";
            }
            
            $stmt->close();
        }
        
        // Add Student to CAS
        if (isset($_POST['enroll_student'])) {
            $student_id = (int)$_POST['student_id'];
            
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
        
        // Remove Student from CAS
        if (isset($_POST['unenroll_student'])) {
            $enrollment_id = (int)$_POST['enrollment_id'];
            
            // Fully remove the enrollment record rather than soft-deleting it.
            // Safe: attendance_records references student_id and session_id
            // directly (not enrollment_id), so attendance history for this
            // CAS is preserved even though the enrollment link is gone.
            $stmt = $conn->prepare("DELETE FROM student_cas_enrollment WHERE enrollment_id = ? AND cas_id = ?");
            $stmt->bind_param("ii", $enrollment_id, $cas_id);
            
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

    // Get CAS activity details
    $cas = null;
    $stmt = $conn->prepare("SELECT * FROM cas_activities WHERE cas_id = ?");
    $stmt->bind_param("i", $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // CAS activity not found, redirect to CAS activities list
        header("Location: cas_activities.php");
        exit();
    }

    $cas = $result->fetch_assoc();
    $stmt->close();

    // Get CAS leaders
    $leaders = [];
    $stmt = $conn->prepare("
        SELECT 
            cl.cas_leader_id,
            u.user_id,
            u.first_name,
            u.last_name,
            u.email,
            u.username,
            u.last_login
        FROM 
            cas_leaders cl
        JOIN 
            users u ON cl.user_id = u.user_id
        WHERE 
            cl.cas_id = ?
        ORDER BY 
            u.last_name, u.first_name
    ");
    $stmt->bind_param("i", $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $leaders[] = $row;
    }
    $stmt->close();

    // Get enrolled students
    $students = [];
    $stmt = $conn->prepare("
        SELECT 
            sce.enrollment_id,
            sce.enrollment_date,
            sce.is_active,
            s.student_id,
            s.first_name,
            s.last_name,
            s.email,
            s.grade_year,
            s.is_active AS student_active
        FROM 
            student_cas_enrollment sce
        JOIN 
            students s ON sce.student_id = s.student_id
        WHERE 
            sce.cas_id = ?
        ORDER BY 
            sce.is_active DESC, s.grade_year, s.last_name, s.first_name
    ");
    $stmt->bind_param("i", $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();

    // Get attendance summary
    $attendance_summary = [];
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT ats.session_id) AS total_sessions,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END) AS excused_count,
            COALESCE(ROUND((SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) / NULLIF(COUNT(ar.record_id), 0)) * 100, 1), 0) AS attendance_rate
        FROM 
            cas_activities ca
        LEFT JOIN 
            attendance_sessions ats ON ca.cas_id = ats.cas_id
        LEFT JOIN 
            attendance_records ar ON ats.session_id = ar.session_id
        WHERE 
            ca.cas_id = ?
    ");
    $stmt->bind_param("i", $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $attendance_summary = $result->fetch_assoc();
    $stmt->close();

    // Get recent attendance sessions
    $recent_sessions = [];
    $stmt = $conn->prepare("
        SELECT 
            ats.session_id,
            ats.session_date,
            ats.notes,
            CONCAT(u.first_name, ' ', u.last_name) AS recorded_by,
            COUNT(ar.record_id) AS student_count,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END) AS excused_count
        FROM 
            attendance_sessions ats
        JOIN 
            users u ON ats.recorded_by = u.user_id
        LEFT JOIN 
            attendance_records ar ON ats.session_id = ar.session_id
        WHERE 
            ats.cas_id = ?
        GROUP BY 
            ats.session_id, ats.session_date, ats.notes, recorded_by
        ORDER BY 
            ats.session_date DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $recent_sessions[] = $row;
    }
    $stmt->close();

    // Get available leaders (not already assigned to this CAS)
    $available_leaders = [];
    $stmt = $conn->prepare("
        SELECT 
            u.user_id,
            u.first_name,
            u.last_name
        FROM 
            users u
        LEFT JOIN 
            cas_leaders cl ON u.user_id = cl.user_id AND cl.cas_id = ?
        WHERE 
            u.user_status = 'cas_leader' AND cl.cas_leader_id IS NULL
        ORDER BY 
            u.last_name, u.first_name
    ");
    $stmt->bind_param("i", $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $available_leaders[] = $row;
    }
    $stmt->close();

    // Get available students (not already enrolled in this CAS)
    $available_students = [];
    $stmt = $conn->prepare("
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            s.grade_year
        FROM 
            students s
        LEFT JOIN 
            student_cas_enrollment sce ON s.student_id = sce.student_id AND sce.cas_id = ? AND sce.is_active = 1
        WHERE 
            s.is_active = 1 AND sce.enrollment_id IS NULL
        ORDER BY 
            s.grade_year, s.last_name, s.first_name
    ");
    $stmt->bind_param("i", $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $available_students[] = $row;
    }
    $stmt->close();
    ?>
    <!DOCTYPE html>
    <html lang="en" class="h-full">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CAS Activity Details - UWC Mostar CAS</title>
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
                            <div class="h-8 w-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold mr-3">
                                UWC
                            </div>
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
                        
                        <a href="cas_activities.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'cas_activities.php' || basename($_SERVER['PHP_SELF']) == 'cas_details.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
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
                        
                        <a href="cas_activities.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'cas_activities.php' || basename($_SERVER['PHP_SELF']) == 'cas_details.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
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
                    <!-- Header -->
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 sm:mb-8 space-y-4 sm:space-y-0">
                        <div class="flex items-center">
                            <a href="cas_activities.php" class="mr-3 sm:mr-4 text-gray-600 hover:text-gray-800 transition-colors">
                                <i class="fas fa-arrow-left text-lg sm:text-xl"></i>
                            </a>
                            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">CAS Activity Details</h1>
                        </div>
                        <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-3 w-full sm:w-auto">
                            <a href="cas_activities.php?action=edit&id=<?php echo $cas_id; ?>" class="w-full sm:w-auto bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-3 sm:px-4 rounded transition-colors text-center text-sm sm:text-base">
                                <i class="fas fa-edit mr-2"></i> Edit Activity
                            </a>
                            <a href="attendance_report.php?view_type=detailed&cas_id=<?php echo $cas_id; ?>" class="w-full sm:w-auto bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-3 sm:px-4 rounded transition-colors text-center text-sm sm:text-base">
                                <i class="fas fa-clipboard-list mr-2"></i> View Reports
                            </a>
                            <a href="admin_record_attendance.php?id=<?php echo $cas_id; ?>" class="w-full sm:w-auto bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-3 sm:px-4 rounded transition-colors text-center text-sm sm:text-base">
    <i class="fas fa-clipboard-check mr-2"></i> Record Attendance
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
                    
                    <!-- CAS Activity Profile Card -->
                    <div class="bg-white rounded-lg shadow-lg p-4 sm:p-6 lg:p-8 mb-6 sm:mb-8">
                        <div class="flex flex-col lg:flex-row items-center lg:items-start">
                            <!-- Activity Icon -->
                            <div class="mb-6 lg:mb-0 lg:mr-8">
                                <?php
                                $icon_class = '';
                                $bg_class = '';
                                $text_class = '';
                                switch($cas['cas_type']) {
                                    case 'creativity':
                                        $icon_class = 'fa-paintbrush';
                                        $bg_class = 'bg-purple-100';
                                        $text_class = 'text-purple-800';
                                        break;
                                    case 'activity':
                                        $icon_class = 'fa-person-running';
                                        $bg_class = 'bg-blue-100';
                                        $text_class = 'text-blue-800';
                                        break;
                                    case 'service':
                                        $icon_class = 'fa-hands-helping';
                                        $bg_class = 'bg-yellow-100';
                                        $text_class = 'text-yellow-800';
                                        break;
                                }
                                ?>
                                <div class="h-24 w-24 sm:h-32 sm:w-32 lg:h-40 lg:w-40 <?php echo $bg_class; ?> rounded-full flex items-center justify-center text-3xl sm:text-4xl lg:text-6xl <?php echo $text_class; ?> shadow-lg mx-auto lg:mx-0">
                                    <i class="fas <?php echo $icon_class; ?>"></i>
                                </div>
                            </div>
                            
                            <!-- Activity Details -->
                            <div class="flex-1 text-center lg:text-left w-full">
                                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between mb-4 sm:mb-6">
                                    <h2 class="text-2xl sm:text-3xl lg:text-4xl font-bold text-gray-800 mb-4 lg:mb-0"><?php echo htmlspecialchars($cas['cas_name']); ?></h2>
                                    
                                    <div class="flex flex-wrap justify-center lg:justify-end gap-2 sm:gap-3">
                                        <span class="px-3 sm:px-4 py-1 sm:py-2 rounded-full text-xs sm:text-sm font-semibold <?php echo $cas['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo $cas['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                        
                                        <span class="px-3 sm:px-4 py-1 sm:py-2 rounded-full text-xs sm:text-sm font-semibold <?php echo $bg_class . ' ' . $text_class; ?>">
                                            <?php echo ucfirst($cas['cas_type']); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6 mb-4 sm:mb-6">
                                    <div class="bg-gray-50 p-3 sm:p-4 rounded-lg">
                                        <div class="flex items-center">
                                            <i class="fas fa-calendar-day text-blue-600 text-lg sm:text-xl mr-3"></i>
                                            <div>
                                                <p class="text-xs sm:text-sm text-gray-500">Day</p>
                                                <p class="font-semibold text-sm sm:text-base"><?php echo ucfirst($cas['cas_day']); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-gray-50 p-3 sm:p-4 rounded-lg">
                                        <div class="flex items-center">
                                            <i class="fas fa-clock text-green-600 text-lg sm:text-xl mr-3"></i>
                                            <div>
                                                <p class="text-xs sm:text-sm text-gray-500">Time</p>
                                                <p class="font-semibold text-sm sm:text-base"><?php echo date('g:i A', strtotime($cas['cas_time'])); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-gray-50 p-3 sm:p-4 rounded-lg md:col-span-2 lg:col-span-1">
                                        <div class="flex items-center">
                                            <i class="fas fa-map-marker-alt text-red-600 text-lg sm:text-xl mr-3"></i>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-xs sm:text-sm text-gray-500">Location</p>
                                                <p class="font-semibold text-sm sm:text-base truncate"><?php echo !empty($cas['cas_location']) ? htmlspecialchars($cas['cas_location']) : 'Not specified'; ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($cas['cas_description'])): ?>
                                <div class="bg-blue-50 p-4 sm:p-6 rounded-lg border-l-4 border-blue-400">
                                    <h3 class="font-semibold text-blue-800 mb-2 sm:mb-3 text-sm sm:text-base"><i class="fas fa-info-circle mr-2"></i>Description</h3>
                                    <p class="text-blue-700 leading-relaxed text-sm sm:text-base"><?php echo nl2br(htmlspecialchars($cas['cas_description'])); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Statistics Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
                        <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-blue-500">
                            <div class="flex items-center">
                                <div class="p-2 sm:p-3 rounded-full bg-blue-100 text-blue-500 mr-3 sm:mr-4">
                                    <i class="fas fa-users text-lg sm:text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-xs sm:text-sm text-gray-500 uppercase">Active Students</p>
                                    <p class="text-xl sm:text-2xl font-bold"><?php echo count(array_filter($students, function($s) { return $s['is_active'] && $s['student_active']; })); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-purple-500">
                            <div class="flex items-center">
                                <div class="p-2 sm:p-3 rounded-full bg-purple-100 text-purple-500 mr-3 sm:mr-4">
                                    <i class="fas fa-user-tie text-lg sm:text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-xs sm:text-sm text-gray-500 uppercase">CAS Leaders</p>
                                    <p class="text-xl sm:text-2xl font-bold"><?php echo count($leaders); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-green-500">
                            <div class="flex items-center">
                                <div class="p-2 sm:p-3 rounded-full bg-green-100 text-green-500 mr-3 sm:mr-4">
                                    <i class="fas fa-calendar-check text-lg sm:text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-xs sm:text-sm text-gray-500 uppercase">Total Sessions</p>
                                    <p class="text-xl sm:text-2xl font-bold"><?php echo $attendance_summary['total_sessions']; ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-amber-500">
                            <div class="flex items-center">
                                <div class="p-2 sm:p-3 rounded-full bg-amber-100 text-amber-500 mr-3 sm:mr-4">
                                    <i class="fas fa-chart-line text-lg sm:text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-xs sm:text-sm text-gray-500 uppercase">Attendance Rate</p>
                                    <p class="text-xl sm:text-2xl font-bold"><?php echo $attendance_summary['attendance_rate']; ?>%</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 sm:gap-8">
                        <!-- Left Column - CAS Leaders & Students -->
                        <div class="xl:col-span-2 space-y-6 sm:space-y-8">
                            <!-- CAS Leaders Section -->
                            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                                <div class="bg-purple-600 text-white px-4 sm:px-6 py-4 flex flex-col sm:flex-row justify-between items-start sm:items-center space-y-2 sm:space-y-0">
                                    <h2 class="text-lg sm:text-xl font-bold"><i class="fas fa-user-tie mr-2"></i>CAS Leaders</h2>
                                    
                                    <?php if (!empty($available_leaders)): ?>
                                    <button type="button" onclick="toggleLeaderForm()" class="w-full sm:w-auto px-3 sm:px-4 py-2 bg-white text-purple-600 rounded-md hover:bg-purple-50 focus:outline-none focus:ring-2 focus:ring-white transition-colors text-sm sm:text-base">
                                        <i class="fas fa-plus mr-2"></i> Add Leader
                                    </button>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Add Leader Form (Hidden by Default) -->
                                <div id="leaderForm" class="px-4 sm:px-6 py-4 bg-purple-50 border-b border-purple-100 hidden">
                                    <h3 class="text-base sm:text-lg font-semibold text-purple-800 mb-4">Add CAS Leader</h3>
                                    
                                    <?php if (empty($available_leaders)): ?>
                                    <p class="text-gray-700 text-sm sm:text-base">No available CAS leaders to add.</p>
                                    <?php else: ?>
                                    <form action="cas_details.php?id=<?php echo $cas_id; ?>" method="POST">
                                        <div class="mb-4">
                                            <label for="user_id" class="block text-sm font-medium text-gray-700 mb-1">Select CAS Leader</label>
                                            <select id="user_id" name="user_id" required
                                                    class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 text-sm sm:text-base">
                                                <option value="">Select a CAS leader</option>
                                                <?php foreach ($available_leaders as $leader): ?>
                                                <option value="<?php echo $leader['user_id']; ?>">
                                                    <?php echo htmlspecialchars($leader['first_name'] . ' ' . $leader['last_name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-2">
                                            <button type="button" onclick="toggleLeaderForm()" class="w-full sm:w-auto px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 text-sm sm:text-base">
                                                Cancel
                                            </button>
                                            <button type="submit" name="add_leader" class="w-full sm:w-auto px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 text-sm sm:text-base">
                                                Add Leader
                                            </button>
                                        </div>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (empty($leaders)): ?>
                                <div class="p-6 sm:p-8 text-center text-gray-500">
                                    <i class="fas fa-user-tie text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                                    <p class="text-base sm:text-lg">No CAS leaders assigned</p>
                                    <p class="text-sm">Add a leader to get started</p>
                                </div>
                                <?php else: ?>
                                <div class="divide-y divide-gray-200">
                                    <?php foreach ($leaders as $leader): ?>
                                    <div class="p-4 sm:p-6 flex items-center justify-between hover:bg-gray-50 transition-colors">
                                        <div class="flex items-center min-w-0 flex-1">
                                            <div class="h-10 w-10 sm:h-12 sm:w-12 bg-purple-100 rounded-full flex items-center justify-center text-lg sm:text-xl text-purple-800 font-bold mr-3 sm:mr-4 flex-shrink-0">
                                                <?php echo substr($leader['first_name'], 0, 1) . substr($leader['last_name'], 0, 1); ?>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-base sm:text-lg font-medium text-gray-900 truncate"><?php echo htmlspecialchars($leader['first_name'] . ' ' . $leader['last_name']); ?></div>
                                                <div class="text-sm text-gray-500 truncate"><?php echo htmlspecialchars($leader['email']); ?></div>
                                                <div class="text-xs text-gray-400">@<?php echo htmlspecialchars($leader['username']); ?></div>
                                            </div>
                                        </div>
                                        <div class="flex space-x-2 sm:space-x-3 ml-4">
                                            <a href="users.php?action=edit&id=<?php echo $leader['user_id']; ?>" class="text-indigo-600 hover:text-indigo-900 p-2 transition-colors">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <form action="cas_details.php?id=<?php echo $cas_id; ?>" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to remove this leader?');">
                                                <input type="hidden" name="cas_leader_id" value="<?php echo $leader['cas_leader_id']; ?>">
                                                <button type="submit" name="remove_leader" class="text-red-600 hover:text-red-900 p-2 transition-colors">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Enrolled Students Section -->
                            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                                <div class="bg-blue-600 text-white px-4 sm:px-6 py-4 flex flex-col sm:flex-row justify-between items-start sm:items-center space-y-2 sm:space-y-0">
                                    <h2 class="text-lg sm:text-xl font-bold"><i class="fas fa-users mr-2"></i>Enrolled Students</h2>
                                    
                                    <?php if (!empty($available_students)): ?>
                                    <button type="button" onclick="toggleStudentForm()" class="w-full sm:w-auto px-3 sm:px-4 py-2 bg-white text-blue-600 rounded-md hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-white transition-colors text-sm sm:text-base">
                                        <i class="fas fa-plus mr-2"></i> Add Student
                                    </button>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Add Student Form (Hidden by Default) -->
                                <div id="studentForm" class="px-4 sm:px-6 py-4 bg-blue-50 border-b border-blue-100 hidden">
                                    <h3 class="text-base sm:text-lg font-semibold text-blue-800 mb-4">Add Student to CAS</h3>
                                    
                                    <?php if (empty($available_students)): ?>
                                    <p class="text-gray-700 text-sm sm:text-base">No available students to add.</p>
                                    <?php else: ?>
                                    <form action="cas_details.php?id=<?php echo $cas_id; ?>" method="POST">
                                        <div class="mb-4">
                                            <label for="student_id" class="block text-sm font-medium text-gray-700 mb-1">Select Student</label>
                                            <select id="student_id" name="student_id" required
                                                    class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base">
                                                <option value="">Select a student</option>
                                                <optgroup label="Y1 Students">
                                                    <?php 
                                                    foreach ($available_students as $student):
                                                        if ($student['grade_year'] === 'Y1'):
                                                    ?>
                                                    <option value="<?php echo $student['student_id']; ?>">
                                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                    </option>
                                                    <?php 
                                                        endif;
                                                    endforeach;
                                                    ?>
                                                </optgroup>
                                                <optgroup label="Y2 Students">
                                                    <?php 
                                                    foreach ($available_students as $student):
                                                        if ($student['grade_year'] === 'Y2'):
                                                    ?>
                                                    <option value="<?php echo $student['student_id']; ?>">
                                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                    </option>
                                                    <?php 
                                                        endif;
                                                    endforeach;
                                                    ?>
                                                </optgroup>
                                            </select>
                                        </div>
                                        
                                        <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-2">
                                            <button type="button" onclick="toggleStudentForm()" class="w-full sm:w-auto px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 text-sm sm:text-base">
                                                Cancel
                                            </button>
                                            <button type="submit" name="enroll_student" class="w-full sm:w-auto px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm sm:text-base">
                                                Add Student
                                            </button>
                                        </div>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (empty($students)): ?>
                                <div class="p-6 sm:p-8 text-center text-gray-500">
                                    <i class="fas fa-users text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                                    <p class="text-base sm:text-lg">No students enrolled</p>
                                    <p class="text-sm">Add students to get started</p>
                                </div>
                                <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Student
                                                </th>
                                                <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Year
                                                </th>
                                                <th scope="col" class="hidden sm:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Email
                                                </th>
                                                <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Status
                                                </th>
                                                <th scope="col" class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Enrolled
                                                </th>
                                                <th scope="col" class="px-4 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($students as $student): ?>
                                            <tr class="hover:bg-gray-50 transition-colors <?php echo (!$student['is_active'] || !$student['student_active']) ? 'bg-gray-50 text-gray-400' : ''; ?>">
                                                <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="flex-shrink-0 h-8 w-8 sm:h-10 sm:w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                                            <span class="text-blue-800 font-semibold text-xs sm:text-sm"><?php echo substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1); ?></span>
                                                        </div>
                                                        <div class="ml-3 sm:ml-4">
                                                            <div class="text-sm font-medium <?php echo (!$student['is_active'] || !$student['student_active']) ? 'text-gray-400' : 'text-gray-900'; ?>">
                                                                <a href="student_details.php?id=<?php echo $student['student_id']; ?>" class="<?php echo (!$student['is_active'] || !$student['student_active']) ? 'text-gray-400' : 'text-blue-600 hover:text-blue-900'; ?>">
                                                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                                </a>
                                                            </div>
                                                            <div class="text-xs sm:text-sm <?php echo (!$student['is_active'] || !$student['student_active']) ? 'text-gray-400' : 'text-gray-500'; ?>">
                                                                ID: <?php echo $student['student_id']; ?>
                                                            </div>
                                                            <div class="sm:hidden text-xs <?php echo (!$student['is_active'] || !$student['student_active']) ? 'text-gray-400' : 'text-gray-500'; ?>">
                                                                <?php echo htmlspecialchars($student['email']); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo (!$student['is_active'] || !$student['student_active']) ? 'bg-gray-100 text-gray-600' : 'bg-blue-100 text-blue-800'; ?>">
                                                        <?php echo $student['grade_year']; ?>
                                                    </span>
                                                </td>
                                                <td class="hidden sm:table-cell px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm <?php echo (!$student['is_active'] || !$student['student_active']) ? 'text-gray-400' : 'text-gray-900'; ?>">
                                                        <?php echo htmlspecialchars($student['email']); ?>
                                                    </div>
                                                </td>
                                                <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                    <?php if (!$student['student_active']): ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                        Inactive Student
                                                    </span>
                                                    <?php elseif (!$student['is_active']): ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                                        Inactive Enrollment
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                        Active
                                                    </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="hidden md:table-cell px-6 py-4 whitespace-nowrap text-sm <?php echo (!$student['is_active'] || !$student['student_active']) ? 'text-gray-400' : 'text-gray-500'; ?>">
                                                    <?php echo date('M j, Y', strtotime($student['enrollment_date'])); ?>
                                                </td>
                                                <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <?php if ($student['student_active']): ?>
                                                        <?php if ($student['is_active']): ?>
                                                        <form action="cas_details.php?id=<?php echo $cas_id; ?>" method="POST" class="inline" onsubmit="return confirm('Remove this student from the CAS activity? This will permanently delete the enrollment record. Their attendance history for this CAS will be kept, but they will need to be re-enrolled from scratch if added back later.');">
                                                            <input type="hidden" name="enrollment_id" value="<?php echo $student['enrollment_id']; ?>">
                                                            <button type="submit" name="unenroll_student" class="text-red-600 hover:text-red-900 p-2 transition-colors">
                                                                <i class="fas fa-user-minus"></i>
                                                            </button>
                                                        </form>
                                                        <?php else: ?>
                                                        <form action="cas_details.php?id=<?php echo $cas_id; ?>" method="POST" class="inline">
                                                            <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                                            <button type="submit" name="enroll_student" class="text-green-600 hover:text-green-900 p-2 transition-colors">
                                                                <i class="fas fa-user-plus"></i>
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Right Column - Attendance Summary & Recent Sessions -->
                        <div class="space-y-6 sm:space-y-8">
                            <!-- Attendance Summary -->
                            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                                <div class="bg-amber-600 text-white px-4 sm:px-6 py-4">
                                    <h2 class="text-lg sm:text-xl font-bold"><i class="fas fa-chart-pie mr-2"></i>Attendance Summary</h2>
                                </div>
                                
                                <div class="p-4 sm:p-6">
                                    <div class="grid grid-cols-2 gap-3 sm:gap-4 mb-4 sm:mb-6">
                                        <div class="bg-blue-50 p-3 sm:p-4 rounded-lg text-center border border-blue-200">
                                            <div class="text-2xl sm:text-3xl font-bold text-blue-600"><?php echo $attendance_summary['total_sessions']; ?></div>
                                            <div class="text-blue-600 text-xs sm:text-sm font-medium">Total Sessions</div>
                                        </div>
                                        
                                        <div class="bg-green-50 p-3 sm:p-4 rounded-lg text-center border border-green-200">
                                            <div class="text-2xl sm:text-3xl font-bold text-green-600">
                                                <?php echo $attendance_summary['attendance_rate']; ?>%
                                            </div>
                                            <div class="text-green-600 text-xs sm:text-sm font-medium">Attendance Rate</div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($attendance_summary['total_sessions'] > 0): ?>
                                    <div class="mb-4 sm:mb-6">
                                        <div class="flex justify-between text-sm font-medium text-gray-700 mb-3">
                                            <span>Attendance Breakdown</span>
                                        </div>
                                        <div class="h-4 sm:h-6 bg-gray-200 rounded-full overflow-hidden">
                                            <?php
                                            $total_records = $attendance_summary['present_count'] + $attendance_summary['absent_count'] + $attendance_summary['excused_count'];
                                            $present_percentage = $total_records > 0 ? ($attendance_summary['present_count'] / $total_records) * 100 : 0;
                                            $absent_percentage = $total_records > 0 ? ($attendance_summary['absent_count'] / $total_records) * 100 : 0;
                                            $excused_percentage = $total_records > 0 ? ($attendance_summary['excused_count'] / $total_records) * 100 : 0;
                                            ?>
                                            <div class="flex h-full">
                                                <div class="bg-green-500 h-full transition-all duration-300" style="width: <?php echo $present_percentage; ?>%"></div>
                                                <div class="bg-red-500 h-full transition-all duration-300" style="width: <?php echo $absent_percentage; ?>%"></div>
                                                <div class="bg-yellow-500 h-full transition-all duration-300" style="width: <?php echo $excused_percentage; ?>%"></div>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs text-gray-600 mt-3">
                                            <div class="text-center">
                                                <span class="inline-block w-3 h-3 bg-green-500 rounded-full mr-1"></span>
                                                Present: <?php echo $attendance_summary['present_count']; ?> (<?php echo round($present_percentage); ?>%)
                                            </div>
                                            <div class="text-center">
                                                <span class="inline-block w-3 h-3 bg-red-500 rounded-full mr-1"></span>
                                                Absent: <?php echo $attendance_summary['absent_count']; ?> (<?php echo round($absent_percentage); ?>%)
                                            </div>
                                            <div class="text-center">
                                                <span class="inline-block w-3 h-3 bg-yellow-500 rounded-full mr-1"></span>
                                                Excused: <?php echo $attendance_summary['excused_count']; ?> (<?php echo round($excused_percentage); ?>%)
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="space-y-2 sm:space-y-3">
                                        <a href="attendance_report.php?view_type=by_student&cas_id=<?php echo $cas_id; ?>" class="block text-center py-2 sm:py-3 px-3 sm:px-4 bg-amber-100 text-amber-800 rounded-md hover:bg-amber-200 transition-colors font-medium text-sm sm:text-base">
                                            <i class="fas fa-chart-bar mr-2"></i>View Detailed Reports
                                        </a>
                                       
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Recent Sessions -->
                            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                                <div class="bg-indigo-600 text-white px-4 sm:px-6 py-4">
                                    <h2 class="text-lg sm:text-xl font-bold"><i class="fas fa-history mr-2"></i>Recent Sessions</h2>
                                </div>
                                
                                <?php if (empty($recent_sessions)): ?>
                                <div class="p-6 sm:p-8 text-center text-gray-500">
                                    <i class="fas fa-calendar-plus text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                                    <p class="text-base sm:text-lg">No sessions recorded yet</p>
                                    <p class="text-sm">Start recording attendance to see session history</p>
                                </div>
                                <?php else: ?>
                                <div class="divide-y divide-gray-200">
                                    <?php foreach ($recent_sessions as $session): ?>
                                    <div class="p-4 sm:p-6 hover:bg-gray-50 transition-colors">
                                        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-3 space-y-2 sm:space-y-0">
                                            <div class="font-semibold text-base sm:text-lg text-gray-900"><?php echo date('M j, Y', strtotime($session['session_date'])); ?></div>
                                            <div class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded self-start sm:self-auto">
                                                By <?php echo htmlspecialchars($session['recorded_by']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="grid grid-cols-3 gap-2 sm:gap-3 mb-3">
                                            <div class="bg-green-50 p-2 sm:p-3 rounded-lg text-center border border-green-200">
                                                <div class="text-base sm:text-lg font-bold text-green-700"><?php echo $session['present_count']; ?></div>
                                                <div class="text-xs text-green-600">Present</div>
                                            </div>
                                            <div class="bg-red-50 p-2 sm:p-3 rounded-lg text-center border border-red-200">
                                                <div class="text-base sm:text-lg font-bold text-red-700"><?php echo $session['absent_count']; ?></div>
                                                <div class="text-xs text-red-600">Absent</div>
                                            </div>
                                            <div class="bg-yellow-50 p-2 sm:p-3 rounded-lg text-center border border-yellow-200">
                                                <div class="text-base sm:text-lg font-bold text-yellow-700"><?php echo $session['excused_count']; ?></div>
                                                <div class="text-xs text-yellow-600">Excused</div>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($session['notes'])): ?>
                                        <div class="text-sm text-gray-600 bg-blue-50 p-3 rounded-lg border-l-4 border-blue-400">
                                            <span class="font-medium text-blue-800">Session Note:</span> <?php echo htmlspecialchars($session['notes']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="p-4 bg-gray-50 border-t border-gray-200 text-center">
                                    <a href="attendance_report.php?view_type=detailed&cas_id=<?php echo $cas_id; ?>" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium transition-colors">
                                        View All Sessions <i class="fas fa-arrow-right ml-1"></i>
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Mobile menu toggle
            document.getElementById('mobile-menu-button').addEventListener('click', function() {
                document.getElementById('mobile-sidebar-overlay').classList.remove('hidden');
            });

            // Close mobile menu
            document.getElementById('close-sidebar-button').addEventListener('click', function() {
                document.getElementById('mobile-sidebar-overlay').classList.add('hidden');
            });

            document.getElementById('mobile-sidebar-backdrop').addEventListener('click', function() {
                document.getElementById('mobile-sidebar-overlay').classList.add('hidden');
            });

            // User dropdown toggle
            document.getElementById('user-menu-button').addEventListener('click', function() {
                const dropdown = document.getElementById('user-menu-dropdown');
                dropdown.classList.toggle('hidden');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                const userMenuButton = document.getElementById('user-menu-button');
                const userMenuDropdown = document.getElementById('user-menu-dropdown');
                
                if (!userMenuButton.contains(event.target) && !userMenuDropdown.contains(event.target)) {
                    userMenuDropdown.classList.add('hidden');
                }
            });

            // Toggle leader form visibility
            function toggleLeaderForm() {
                const leaderForm = document.getElementById('leaderForm');
                leaderForm.classList.toggle('hidden');
                
                // Focus on select if showing form
                if (!leaderForm.classList.contains('hidden')) {
                    setTimeout(() => {
                        document.getElementById('user_id').focus();
                    }, 100);
                }
            }
            
            // Toggle student form visibility
            function toggleStudentForm() {
                const studentForm = document.getElementById('studentForm');
                studentForm.classList.toggle('hidden');
                
                // Focus on select if showing form
                if (!studentForm.classList.contains('hidden')) {
                    setTimeout(() => {
                        document.getElementById('student_id').focus();
                    }, 100);
                }
            }
            
            // Auto-hide alerts after 5 seconds
            document.addEventListener('DOMContentLoaded', function() {
                const alerts = document.querySelectorAll('.alert-dismissible');
                alerts.forEach(alert => {
                    setTimeout(() => {
                        alert.style.opacity = '0';
                        alert.style.transform = 'translateY(-20px)';
                        setTimeout(() => {
                            alert.remove();
                        }, 300);
                    }, 5000);
                });
            });
            
            // Smooth scroll to sections
            function scrollToSection(sectionId) {
                document.getElementById(sectionId).scrollIntoView({
                    behavior: 'smooth'
                });
            }

            // Handle responsive table on mobile
            window.addEventListener('resize', function() {
                // Add any resize handling if needed
            });
        </script>
    </body>
    </html>