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

// Message handling
$message = "";
$message_type = "";

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Promote Y1 students to Y2
    if (isset($_POST['promote_students'])) {
        try {
            $conn->begin_transaction();
            
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM students WHERE grade_year = 'Y1' AND is_active = 1");
            $stmt->execute();
            $result = $stmt->get_result();
            $student_count = $result->fetch_assoc()['count'];
            $stmt->close();
            
            $stmt = $conn->prepare("UPDATE students SET grade_year = 'Y2' WHERE grade_year = 'Y1' AND is_active = 1");
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            
            if ($student_count > 0) {
                $message = "Successfully promoted " . $student_count . " students from Y1 to Y2.";
                $message_type = "success";
            } else {
                $message = "No active Y1 students found to promote.";
                $message_type = "info";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error promoting students: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    // Permanently delete graduating Y2 students and all their associated data
    if (isset($_POST['delete_graduating_students'])) {
        try {
            $conn->begin_transaction();
            
            // Get the Y2 student IDs first - needed for absence_requests cleanup,
            // since that table has no foreign key cascade covering it
            $stmt = $conn->prepare("SELECT student_id FROM students WHERE grade_year = 'Y2'");
            $stmt->execute();
            $result = $stmt->get_result();
            $y2_student_ids = [];
            while ($row = $result->fetch_assoc()) {
                $y2_student_ids[] = $row['student_id'];
            }
            $stmt->close();
            
            $student_count = count($y2_student_ids);
            $leader_count = 0;
            
            if ($student_count > 0) {
                $placeholders = implode(',', array_fill(0, $student_count, '?'));
                $types = str_repeat('i', $student_count);
                
                // Find any user/leader accounts linked to these students (e.g. peer leaders)
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE student_id IN ($placeholders)");
                $stmt->bind_param($types, ...$y2_student_ids);
                $stmt->execute();
                $result = $stmt->get_result();
                $leader_user_ids = [];
                while ($row = $result->fetch_assoc()) {
                    $leader_user_ids[] = $row['user_id'];
                }
                $stmt->close();
                
                $leader_count = count($leader_user_ids);
                
                if ($leader_count > 0) {
                    $leader_placeholders = implode(',', array_fill(0, $leader_count, '?'));
                    $leader_types = str_repeat('i', $leader_count);
                    
                    // attendance_sessions.recorded_by is NOT NULL with ON DELETE RESTRICT,
                    // so deleting a leader's account would otherwise fail with a foreign
                    // key error if they ever recorded a session. Reassign those sessions
                    // to the admin performing this cleanup first - other students' actual
                    // attendance data in those sessions is untouched, only the recorded-by
                    // attribution changes.
                    $current_admin_id = (int)$_SESSION['user_id'];
                    $stmt = $conn->prepare("
                        UPDATE attendance_sessions 
                        SET recorded_by = ?, 
                            notes = CONCAT(IFNULL(notes, ''), ' [Originally recorded by a leader account removed during year-end cleanup]')
                        WHERE recorded_by IN ($leader_placeholders)
                    ");
                    $stmt->bind_param("i" . $leader_types, $current_admin_id, ...$leader_user_ids);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Delete their leader/user accounts entirely. This cascades to
                    // remove their cas_leaders assignments automatically.
                    $stmt = $conn->prepare("DELETE FROM users WHERE user_id IN ($leader_placeholders)");
                    $stmt->bind_param($leader_types, ...$leader_user_ids);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Delete their absence requests (no FK cascade covers this table)
                $stmt = $conn->prepare("DELETE FROM absence_requests WHERE student_id IN ($placeholders)");
                $stmt->bind_param($types, ...$y2_student_ids);
                $stmt->execute();
                $stmt->close();
                
                // Delete the student records themselves. This cascades automatically
                // to remove their student_cas_enrollment rows and attendance_records
                // rows (both have ON DELETE CASCADE on student_id in the schema).
                $stmt = $conn->prepare("DELETE FROM students WHERE student_id IN ($placeholders)");
                $stmt->bind_param($types, ...$y2_student_ids);
                $stmt->execute();
                $stmt->close();
            }
            
            $conn->commit();
            
            if ($student_count > 0) {
                $message = "Permanently deleted " . $student_count . " Y2 students and all their associated data (enrollments, attendance records, absence requests" . ($leader_count > 0 ? ", and " . $leader_count . " linked leader account(s)" : "") . ").";
                $message_type = "success";
            } else {
                $message = "No Y2 students found to delete.";
                $message_type = "info";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error deleting graduating students: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    // Wipe attendance history for currently active students. Meant to be used
    // after promoting Y1 to Y2, so the newly-promoted class starts their new
    // year with a clean attendance slate instead of carrying over their
    // freshman-year records. Scoped to active students only - does not touch
    // any remaining inactive/archived students' historical data.
    if (isset($_POST['wipe_active_attendance'])) {
        try {
            $conn->begin_transaction();
            
            // Count first for reporting
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count FROM attendance_records ar
                JOIN students s ON ar.student_id = s.student_id
                WHERE s.is_active = 1 AND s.grade_year = 'Y2'
            ");
            $stmt->execute();
            $record_count = $stmt->get_result()->fetch_assoc()['count'];
            $stmt->close();
            
            // Delete attendance records belonging to currently active Y2 students only.
            // Scoped to grade_year = 'Y2' specifically (not just is_active) so that if
            // new freshmen (Y1) are added or start attending sessions before this button
            // is clicked, their attendance is never touched - only the class that was
            // just promoted gets their old attendance cleared.
            $stmt = $conn->prepare("
                DELETE ar FROM attendance_records ar
                JOIN students s ON ar.student_id = s.student_id
                WHERE s.is_active = 1 AND s.grade_year = 'Y2'
            ");
            $stmt->execute();
            $stmt->close();
            
            // Clean up any sessions left with zero remaining attendance records
            // (e.g. a session where every attendee was an active student)
            $stmt = $conn->prepare("
                DELETE FROM attendance_sessions 
                WHERE session_id NOT IN (SELECT DISTINCT session_id FROM attendance_records)
            ");
            $stmt->execute();
            $session_count = $stmt->affected_rows;
            $stmt->close();
            
            $conn->commit();
            
            if ($record_count > 0) {
                $message = "Wiped " . $record_count . " attendance records for currently active students" . ($session_count > 0 ? " and removed " . $session_count . " now-empty session(s)" : "") . ".";
                $message_type = "success";
            } else {
                $message = "No attendance records found for currently active students.";
                $message_type = "info";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error wiping attendance: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    // Data cleanup functionality
    if (isset($_POST['cleanup_data'])) {
        if (isset($_POST['confirm_cleanup']) && $_POST['confirm_cleanup'] === 'YES_DELETE_ALL_DATA') {
            try {
                $conn->begin_transaction();
                
                $deleted_counts = [];
                $tables_to_cleanup = $_POST['cleanup_tables'] ?? [];
                
                if (in_array('attendance', $tables_to_cleanup)) {
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM attendance_records");
                    $stmt->execute();
                    $deleted_counts['attendance'] = $stmt->get_result()->fetch_assoc()['count'];
                    $stmt->close();
                    
                    $conn->query("DELETE FROM attendance_records");
                    $conn->query("DELETE FROM attendance_sessions");
                }
                
                if (in_array('activity_log', $tables_to_cleanup)) {
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM activity_log");
                    $stmt->execute();
                    $deleted_counts['activity_log'] = $stmt->get_result()->fetch_assoc()['count'];
                    $stmt->close();
                    
                    $conn->query("DELETE FROM activity_log");
                }
                
                if (in_array('absence_requests', $tables_to_cleanup)) {
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM absence_requests");
                    $stmt->execute();
                    $deleted_counts['absence_requests'] = $stmt->get_result()->fetch_assoc()['count'];
                    $stmt->close();
                    
                    $conn->query("DELETE FROM absence_requests");
                    $conn->query("DELETE FROM absence_notifications");
                }
                
                if (in_array('y1_students', $tables_to_cleanup)) {
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM students WHERE grade_year = 'Y1'");
                    $stmt->execute();
                    $deleted_counts['y1_students'] = $stmt->get_result()->fetch_assoc()['count'];
                    $stmt->close();
                    
                    $conn->query("DELETE FROM student_cas_enrollment WHERE student_id IN (SELECT student_id FROM students WHERE grade_year = 'Y1')");
                    $conn->query("DELETE FROM students WHERE grade_year = 'Y1'");
                }
                
                if (in_array('y2_students', $tables_to_cleanup)) {
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM students WHERE grade_year = 'Y2'");
                    $stmt->execute();
                    $deleted_counts['y2_students'] = $stmt->get_result()->fetch_assoc()['count'];
                    $stmt->close();
                    
                    $conn->query("DELETE FROM student_cas_enrollment WHERE student_id IN (SELECT student_id FROM students WHERE grade_year = 'Y2')");
                    $conn->query("DELETE FROM students WHERE grade_year = 'Y2'");
                }
                
                if (in_array('cas_leaders', $tables_to_cleanup)) {
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM cas_leaders");
                    $stmt->execute();
                    $deleted_counts['cas_leaders'] = $stmt->get_result()->fetch_assoc()['count'];
                    $stmt->close();
                    
                    $conn->query("DELETE FROM cas_leaders");
                }
                
                if (in_array('cas_activities', $tables_to_cleanup)) {
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM cas_activities");
                    $stmt->execute();
                    $deleted_counts['cas_activities'] = $stmt->get_result()->fetch_assoc()['count'];
                    $stmt->close();
                    
                    $conn->query("DELETE FROM student_cas_enrollment");
                    $conn->query("DELETE FROM cas_activities");
                }
                
                if (in_array('users', $tables_to_cleanup)) {
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE user_status != 'admin'");
                    $stmt->execute();
                    $deleted_counts['users'] = $stmt->get_result()->fetch_assoc()['count'];
                    $stmt->close();
                    
                    $conn->query("DELETE FROM users WHERE user_status != 'admin'");
                }
                
                $conn->commit();
                
                $summary_parts = [];
                foreach ($deleted_counts as $table => $count) {
                    if ($count > 0) {
                        $table_name = ucwords(str_replace('_', ' ', $table));
                        $summary_parts[] = "$count $table_name records";
                    }
                }
                
                if (!empty($summary_parts)) {
                    $message = "Data cleanup completed successfully. Deleted: " . implode(', ', $summary_parts) . ".";
                    $message_type = "success";
                } else {
                    $message = "No data found to delete for the selected categories.";
                    $message_type = "info";
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error during data cleanup: " . $e->getMessage();
                $message_type = "error";
            }
        } else {
            $message = "Data cleanup cancelled - confirmation phrase was incorrect.";
            $message_type = "error";
        }
    }
    
    // Import students from CSV
    if (isset($_POST['import_students'])) {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['csv_file']['tmp_name'];
            $file_name = $_FILES['csv_file']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            if ($file_ext === 'csv') {
                try {
                    $conn->begin_transaction();
                    
                    $handle = fopen($file_tmp, 'r');
                    
                    if (isset($_POST['has_header']) && $_POST['has_header'] === '1') {
                        fgetcsv($handle);
                    }
                    
                    $success_count = 0;
                    $error_count = 0;
                    $duplicate_count = 0;
                    
                    while (($data = fgetcsv($handle)) !== FALSE) {
                        if (count($data) >= 4) {
                            $first_name = trim($data[0]);
                            $last_name = trim($data[1]);
                            $email = trim($data[2]);
                            $grade_year = trim($data[3]);
                            
                            if (!empty($first_name) && !empty($last_name) && !empty($email) && 
                                ($grade_year === 'Y1' || $grade_year === 'Y2')) {
                                
                                $stmt = $conn->prepare("SELECT student_id FROM students WHERE email = ?");
                                $stmt->bind_param("s", $email);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                
                                if ($result->num_rows === 0) {
                                    $stmt = $conn->prepare("INSERT INTO students (first_name, last_name, email, grade_year, is_active) VALUES (?, ?, ?, ?, 1)");
                                    $stmt->bind_param("ssss", $first_name, $last_name, $email, $grade_year);
                                    
                                    if ($stmt->execute()) {
                                        $success_count++;
                                    } else {
                                        $error_count++;
                                    }
                                } else {
                                    $duplicate_count++;
                                }
                                
                                $stmt->close();
                            } else {
                                $error_count++;
                            }
                        } else {
                            $error_count++;
                        }
                    }
                    
                    fclose($handle);
                    $conn->commit();
                    
                    $message = "Import completed: $success_count students added successfully, $duplicate_count duplicates skipped, $error_count errors.";
                    $message_type = $success_count > 0 ? "success" : "warning";
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "Error importing students: " . $e->getMessage();
                    $message_type = "error";
                }
            } else {
                $message = "Please upload a CSV file.";
                $message_type = "error";
            }
        } else {
            $message = "No file uploaded or an error occurred.";
            $message_type = "error";
        }
    }
}

// Get student counts
$stmt = $conn->prepare("
    SELECT grade_year, 
           COUNT(*) as total, 
           SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count
    FROM students
    GROUP BY grade_year
    ORDER BY grade_year
");
$stmt->execute();
$result = $stmt->get_result();

$student_counts = [
    'Y1' => ['total' => 0, 'active' => 0],
    'Y2' => ['total' => 0, 'active' => 0]
];

while ($row = $result->fetch_assoc()) {
    $year = $row['grade_year'];
    $student_counts[$year]['total'] = $row['total'];
    $student_counts[$year]['active'] = $row['active_count'];
}

$stmt->close();

// Get data counts for cleanup display
$data_counts = [];
$count_queries = [
    'y1_students' => "SELECT COUNT(*) as count FROM students WHERE grade_year = 'Y1'",
    'y2_students' => "SELECT COUNT(*) as count FROM students WHERE grade_year = 'Y2'",
    'cas_activities' => "SELECT COUNT(*) as count FROM cas_activities",
    'cas_leaders' => "SELECT COUNT(*) as count FROM cas_leaders",
    'attendance' => "SELECT COUNT(*) as count FROM attendance_records",
    'activity_log' => "SELECT COUNT(*) as count FROM activity_log",
    'absence_requests' => "SELECT COUNT(*) as count FROM absence_requests",
    'users' => "SELECT COUNT(*) as count FROM users WHERE user_status != 'admin'"
];

foreach ($count_queries as $key => $query) {
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $data_counts[$key] = $result->fetch_assoc()['count'];
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Year Transition - UWC Mostar CAS</title>
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
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6 sm:mb-8">Year Transition Management</h1>
                
                <!-- Message Alert -->
                <?php if (!empty($message)): ?>
                <div class="mb-6 sm:mb-8 p-4 rounded-lg border-l-4 <?php 
                    echo $message_type === 'error' ? 'bg-red-50 border-red-400' : 
                        ($message_type === 'success' ? 'bg-green-50 border-green-400' : 
                        ($message_type === 'warning' ? 'bg-yellow-50 border-yellow-400' : 'bg-blue-50 border-blue-400')); 
                ?> shadow-md" role="alert">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <?php if ($message_type === 'error'): ?>
                                <i class="fas fa-exclamation-circle text-red-400 text-lg"></i>
                            <?php elseif ($message_type === 'success'): ?>
                                <i class="fas fa-check-circle text-green-400 text-lg"></i>
                            <?php elseif ($message_type === 'warning'): ?>
                                <i class="fas fa-exclamation-triangle text-yellow-400 text-lg"></i>
                            <?php else: ?>
                                <i class="fas fa-info-circle text-blue-400 text-lg"></i>
                            <?php endif; ?>
                        </div>
                        <div class="ml-3 flex-1">
                            <p class="text-sm font-medium <?php 
                                echo $message_type === 'error' ? 'text-red-800' : 
                                    ($message_type === 'success' ? 'text-green-800' : 
                                    ($message_type === 'warning' ? 'text-yellow-800' : 'text-blue-800')); 
                            ?>"><?php echo htmlspecialchars($message); ?></p>
                        </div>
                        <button type="button" class="flex-shrink-0 ml-4 p-1 hover:bg-black hover:bg-opacity-10 rounded transition-colors" onclick="this.parentElement.parentElement.remove()">
                            <i class="fas fa-times <?php 
                                echo $message_type === 'error' ? 'text-red-400' : 
                                    ($message_type === 'success' ? 'text-green-400' : 
                                    ($message_type === 'warning' ? 'text-yellow-400' : 'text-blue-400')); 
                            ?>"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Student Overview Stats -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6 mb-6 sm:mb-8">
                    <!-- Y1 Students -->
                    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-blue-500">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 rounded-full bg-blue-100 text-blue-500 mr-3 sm:mr-4">
                                <i class="fas fa-user-graduate text-lg sm:text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-xs sm:text-sm text-gray-500 uppercase">Year 1 Students</p>
                                <p class="text-xl sm:text-2xl font-bold"><?php echo $student_counts['Y1']['active']; ?></p>
                                <p class="text-xs text-gray-500">Active students</p>
                                <?php if ($student_counts['Y1']['total'] > $student_counts['Y1']['active']): ?>
                                <p class="text-xs text-gray-500">(<?php echo $student_counts['Y1']['total'] - $student_counts['Y1']['active']; ?> inactive)</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-3 sm:mt-4">
                            <a href="students.php?search=&year=Y1&active=all" class="text-blue-500 hover:text-blue-700 text-xs sm:text-sm font-medium">View Y1 students <i class="fas fa-arrow-right ml-1"></i></a>
                        </div>
                    </div>
                    
                    <!-- Y2 Students -->
                    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-purple-500">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 rounded-full bg-purple-100 text-purple-500 mr-3 sm:mr-4">
                                <i class="fas fa-graduation-cap text-lg sm:text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-xs sm:text-sm text-gray-500 uppercase">Year 2 Students</p>
                                <p class="text-xl sm:text-2xl font-bold"><?php echo $student_counts['Y2']['active']; ?></p>
                                <p class="text-xs text-gray-500">Active students</p>
                                <?php if ($student_counts['Y2']['total'] > $student_counts['Y2']['active']): ?>
                                <p class="text-xs text-gray-500">(<?php echo $student_counts['Y2']['total'] - $student_counts['Y2']['active']; ?> inactive)</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-3 sm:mt-4">
                            <a href="students.php?search=&year=Y2&active=all" class="text-purple-500 hover:text-purple-700 text-xs sm:text-sm font-medium">View Y2 students <i class="fas fa-arrow-right ml-1"></i></a>
                        </div>
                    </div>
                </div>
                
                <!-- Main Action Cards -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-6 sm:mb-8">
                    <!-- Year End Actions -->
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <div class="bg-indigo-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                            <h2 class="text-lg sm:text-xl font-bold flex items-center">
                                <i class="fas fa-exchange-alt mr-2 sm:mr-3"></i>
                                Year End Actions
                            </h2>
                            <p class="text-indigo-100 text-xs sm:text-sm mt-1">Manage student transitions between years</p>
                        </div>
                        
                        <div class="p-4 sm:p-6 space-y-6 sm:space-y-8">
                            <!-- Promote Y1 to Y2 -->
                            <div class="border border-gray-200 rounded-lg p-4 sm:p-5 hover:shadow-md transition-shadow">
                                <div class="flex flex-col sm:flex-row sm:items-start space-y-3 sm:space-y-0 sm:space-x-4">
                                    <div class="flex-shrink-0">
                                        <div class="p-2 bg-blue-100 rounded-lg">
                                            <i class="fas fa-arrow-up text-blue-600"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <h3 class="text-base sm:text-lg font-semibold text-gray-800 mb-2">Promote Y1 Students to Y2</h3>
                                        <p class="text-gray-600 text-sm mb-4">Move all active Y1 students to Y2. This action should be performed at the end of the academic year.</p>
                                        
                                        <form action="year_transition.php" method="POST" onsubmit="return confirm('Are you sure you want to promote all Y1 students to Y2? This action will affect <?php echo $student_counts['Y1']['active']; ?> students.');">
                                            <button type="submit" name="promote_students" 
                                                    class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed text-sm sm:text-base" 
                                                    <?php echo $student_counts['Y1']['active'] === 0 ? 'disabled' : ''; ?>>
                                                <i class="fas fa-arrow-up mr-2"></i>
                                                Promote <?php echo $student_counts['Y1']['active']; ?> Y1 Students
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Delete Graduating Y2 Students -->
                            <div class="border border-red-200 rounded-lg p-4 sm:p-5 hover:shadow-md transition-shadow bg-red-50">
                                <div class="flex flex-col sm:flex-row sm:items-start space-y-3 sm:space-y-0 sm:space-x-4">
                                    <div class="flex-shrink-0">
                                        <div class="p-2 bg-red-100 rounded-lg">
                                            <i class="fas fa-user-times text-red-600"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <h3 class="text-base sm:text-lg font-semibold text-gray-800 mb-2">Delete Graduating Y2 Students</h3>
                                        <p class="text-gray-600 text-sm mb-4">Permanently delete all Y2 students - their enrollments, attendance records, absence requests, and any linked leader account are all removed. <strong>This cannot be undone.</strong></p>
                                        
                                        <form action="year_transition.php" method="POST" onsubmit="return confirm('PERMANENTLY DELETE all ' + <?php echo (int)$student_counts['Y2']['total']; ?> + ' Y2 students and every record tied to them (enrollments, attendance, absence requests, linked leader accounts)?\n\nThis cannot be undone. Type OK only if you are certain.');">
                                            <button type="submit" name="delete_graduating_students" 
                                                    class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed text-sm sm:text-base" 
                                                    <?php echo $student_counts['Y2']['total'] === 0 ? 'disabled' : ''; ?>>
                                                <i class="fas fa-trash-alt mr-2"></i>
                                                Delete <?php echo $student_counts['Y2']['total']; ?> Y2 Students
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Wipe Freshman-Year Attendance -->
                            <div class="border border-red-200 rounded-lg p-4 sm:p-5 hover:shadow-md transition-shadow bg-red-50">
                                <div class="flex flex-col sm:flex-row sm:items-start space-y-3 sm:space-y-0 sm:space-x-4">
                                    <div class="flex-shrink-0">
                                        <div class="p-2 bg-red-100 rounded-lg">
                                            <i class="fas fa-eraser text-red-600"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <h3 class="text-base sm:text-lg font-semibold text-gray-800 mb-2">Wipe Freshman-Year Attendance</h3>
                                        <p class="text-gray-600 text-sm mb-4">Permanently deletes attendance records for all currently active Y2 students (run this <em>after</em> promoting Y1 to Y2), so the newly-promoted class starts their new year with a clean slate. Scoped to Y2 only, so it's safe to run even if new freshmen have already been added. <strong>This cannot be undone.</strong></p>
                                        
                                        <form action="year_transition.php" method="POST" onsubmit="return confirm('PERMANENTLY DELETE all attendance records for every currently active student?\n\nThis cannot be undone. Make sure you have already promoted Y1 to Y2 before running this.');">
                                            <button type="submit" name="wipe_active_attendance" 
                                                    class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition-colors text-sm sm:text-base">
                                                <i class="fas fa-eraser mr-2"></i>
                                                Wipe Active Students' Attendance
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Import New Students -->
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <div class="bg-green-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                            <h2 class="text-lg sm:text-xl font-bold flex items-center">
                                <i class="fas fa-file-import mr-2 sm:mr-3"></i>
                                Import New Students
                            </h2>
                            <p class="text-green-100 text-xs sm:text-sm mt-1">Add new students from a CSV file</p>
                        </div>
                        
                        <div class="p-4 sm:p-6">
                            <p class="text-gray-600 mb-4 text-sm sm:text-base">Upload a CSV file containing new student information. The file should contain the following columns: First Name, Last Name, Email, Year (Y1 or Y2).</p>

                            <!-- CSV Format Example -->
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 sm:p-4 mb-4 sm:mb-6">
                                <h4 class="font-semibold text-gray-800 mb-2 flex items-center text-sm sm:text-base">
                                    <i class="fas fa-file-alt text-gray-600 mr-2"></i>
                                    CSV Format Example:
                                </h4>
                                <div class="bg-gray-800 text-green-400 p-2 sm:p-3 rounded font-mono text-xs sm:text-sm overflow-x-auto">
                                    <div>First Name,Last Name,Email,Year</div>
                                    <div>John,Smith,john.smith@uwcmostar.ba,Y1</div>
                                    <div>Jane,Doe,jane.doe@uwcmostar.ba,Y2</div>
                                </div>
                            </div>
                            
                            <form action="year_transition.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                                <!-- File Upload -->
                                <div>
                                    <label for="csv_file" class="block text-sm font-medium text-gray-700 mb-2">CSV File</label>
                                    <div class="relative">
                                        <input type="file" id="csv_file" name="csv_file" accept=".csv" required
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 file:mr-4 file:py-1 file:px-3 sm:file:px-4 file:rounded-full file:border-0 file:text-xs sm:file:text-sm file:font-medium file:bg-green-50 file:text-green-700 hover:file:bg-green-100 text-sm sm:text-base">
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">Accepted format: .csv files only</p>
                                </div>
                                
                                <!-- Header Checkbox -->
                                <div class="flex items-center">
                                    <input type="checkbox" id="has_header" name="has_header" value="1" checked
                                           class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded">
                                    <label for="has_header" class="ml-2 text-sm text-gray-700">File has header row</label>
                                </div>
                                
                                <!-- Warning Message -->
                                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-3 sm:p-4 rounded-lg">
                                    <div class="flex">
                                        <i class="fas fa-exclamation-triangle text-yellow-400 mt-0.5 flex-shrink-0"></i>
                                        <div class="ml-3">
                                            <p class="text-xs sm:text-sm text-yellow-800">
                                                <strong>Note:</strong> The system will automatically check for duplicate emails and skip those records.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Submit Button -->
                                <button type="submit" name="import_students" 
                                        class="w-full sm:w-auto inline-flex items-center justify-center px-4 sm:px-6 py-2 sm:py-3 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition-colors text-sm sm:text-base">
                                    <i class="fas fa-file-import mr-2"></i>
                                    Import Students
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- DATA CLEANUP SECTION - NEW -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6 sm:mb-8">
                    <div class="bg-red-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                        <h2 class="text-lg sm:text-xl font-bold flex items-center">
                            <i class="fas fa-trash-alt mr-2 sm:mr-3"></i>
                            System Data Cleanup
                        </h2>
                        <p class="text-red-100 text-xs sm:text-sm mt-1">⚠️ DANGER ZONE - Permanently delete system data</p>
                    </div>
                    
                    <div class="p-4 sm:p-6">
                        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6">
                            <div class="flex">
                                <i class="fas fa-exclamation-triangle text-red-400 mt-0.5 flex-shrink-0"></i>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-red-800">⚠️ CRITICAL WARNING</h3>
                                    <div class="mt-2 text-sm text-red-700">
                                        <p class="mb-2">This feature permanently deletes data from your database. This action CANNOT be undone.</p>
                                        <ul class="list-disc list-inside space-y-1">
                                            <li>Always backup your database before using this feature</li>
                                            <li>Only use this during year transitions or system maintenance</li>
                                            <li>Admin users are protected from deletion</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Data Categories -->
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Select Data to Delete</h3>
                                <form id="cleanup-form" action="year_transition.php" method="POST">
                                    <div class="space-y-3">
                                        <label class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                                            <input type="checkbox" name="cleanup_tables[]" value="y1_students" class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                            <div class="ml-3 flex-1">
                                                <span class="text-sm font-medium text-gray-700">Year 1 Students</span>
                                                <span class="block text-xs text-gray-500"><?php echo $data_counts['y1_students']; ?> records</span>
                                            </div>
                                            <i class="fas fa-user-graduate text-blue-500"></i>
                                        </label>

                                        <label class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                                            <input type="checkbox" name="cleanup_tables[]" value="y2_students" class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                            <div class="ml-3 flex-1">
                                                <span class="text-sm font-medium text-gray-700">Year 2 Students</span>
                                                <span class="block text-xs text-gray-500"><?php echo $data_counts['y2_students']; ?> records</span>
                                            </div>
                                            <i class="fas fa-graduation-cap text-purple-500"></i>
                                        </label>

                                        <label class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                                            <input type="checkbox" name="cleanup_tables[]" value="cas_activities" class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                            <div class="ml-3 flex-1">
                                                <span class="text-sm font-medium text-gray-700">CAS Activities</span>
                                                <span class="block text-xs text-gray-500"><?php echo $data_counts['cas_activities']; ?> records</span>
                                            </div>
                                            <i class="fas fa-calendar-alt text-green-500"></i>
                                        </label>

                                        <label class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                                            <input type="checkbox" name="cleanup_tables[]" value="cas_leaders" class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                            <div class="ml-3 flex-1">
                                                <span class="text-sm font-medium text-gray-700">CAS Leaders</span>
                                                <span class="block text-xs text-gray-500"><?php echo $data_counts['cas_leaders']; ?> records</span>
                                            </div>
                                            <i class="fas fa-user-tie text-indigo-500"></i>
                                        </label>

                                        <label class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                                            <input type="checkbox" name="cleanup_tables[]" value="attendance" class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                            <div class="ml-3 flex-1">
                                                <span class="text-sm font-medium text-gray-700">Attendance Records</span>
                                                <span class="block text-xs text-gray-500"><?php echo $data_counts['attendance']; ?> records</span>
                                            </div>
                                            <i class="fas fa-clipboard-check text-yellow-500"></i>
                                        </label>

                                        <label class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                                            <input type="checkbox" name="cleanup_tables[]" value="activity_log" class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                            <div class="ml-3 flex-1">
                                                <span class="text-sm font-medium text-gray-700">Activity Log</span>
                                                <span class="block text-xs text-gray-500"><?php echo $data_counts['activity_log']; ?> records</span>
                                            </div>
                                            <i class="fas fa-history text-gray-500"></i>
                                        </label>

                                        <label class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                                            <input type="checkbox" name="cleanup_tables[]" value="absence_requests" class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                            <div class="ml-3 flex-1">
                                                <span class="text-sm font-medium text-gray-700">Absence Requests</span>
                                                <span class="block text-xs text-gray-500"><?php echo $data_counts['absence_requests']; ?> records</span>
                                            </div>
                                            <i class="fas fa-calendar-times text-orange-500"></i>
                                        </label>

                                        <label class="flex items-center p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                                            <input type="checkbox" name="cleanup_tables[]" value="users" class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                            <div class="ml-3 flex-1">
                                                <span class="text-sm font-medium text-gray-700">Non-Admin Users</span>
                                                <span class="block text-xs text-gray-500"><?php echo $data_counts['users']; ?> records</span>
                                            </div>
                                            <i class="fas fa-users text-pink-500"></i>
                                        </label>
                                    </div>

                                    <div class="mt-6 space-y-4">
                                        <div class="flex items-center space-x-2">
                                            <button type="button" onclick="selectAllCleanup()" class="text-xs px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded-md text-gray-700">Select All</button>
                                            <button type="button" onclick="selectNoneCleanup()" class="text-xs px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded-md text-gray-700">Select None</button>
                                        </div>
                                    </div>
                            </div>

                            <!-- Confirmation Section -->
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Safety Confirmation</h3>
                                <div class="space-y-4">
                                    <div>
                                        <label for="confirm_cleanup" class="block text-sm font-medium text-gray-700 mb-2">
                                            Type <span class="font-mono bg-red-100 text-red-700 px-2 py-1 rounded">YES_DELETE_ALL_DATA</span> to confirm:
                                        </label>
                                        <input type="text" id="confirm_cleanup" name="confirm_cleanup" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500" 
                                               placeholder="Type confirmation phrase here">
                                    </div>

                                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                        <h4 class="font-medium text-gray-800 mb-2">Before proceeding:</h4>
                                        <div class="space-y-2">
                                            <label class="flex items-center">
                                                <input type="checkbox" id="backup_confirmed" class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                                <span class="ml-2 text-sm text-gray-700">I have backed up my database</span>
                                            </label>
                                            <label class="flex items-center">
                                                <input type="checkbox" id="understand_permanent" class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                                <span class="ml-2 text-sm text-gray-700">I understand this action is permanent</span>
                                            </label>
                                            <label class="flex items-center">
                                                <input type="checkbox" id="authorized_admin" class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                                <span class="ml-2 text-sm text-gray-700">I am authorized to perform this action</span>
                                            </label>
                                        </div>
                                    </div>

                                    <button type="submit" name="cleanup_data" id="cleanup_submit_btn" disabled
                                            class="w-full px-6 py-3 bg-red-600 hover:bg-red-700 disabled:bg-gray-400 disabled:cursor-not-allowed text-white font-medium rounded-lg transition-colors">
                                        <i class="fas fa-trash-alt mr-2"></i>
                                        Delete Selected Data
                                    </button>
                                </div>
                            </div>
                        </div>
                        </form>
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

            // File upload preview
            const csvFileInput = document.getElementById('csv_file');
            if (csvFileInput) {
                csvFileInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const fileName = file.name;
                        const fileSize = (file.size / 1024).toFixed(2);
                        console.log(`Selected file: ${fileName} (${fileSize} KB)`);
                    }
                });
            }

            // Data cleanup functionality
            const cleanupForm = document.getElementById('cleanup-form');
            const confirmInput = document.getElementById('confirm_cleanup');
            const submitBtn = document.getElementById('cleanup_submit_btn');
            const checkboxes = [
                document.getElementById('backup_confirmed'),
                document.getElementById('understand_permanent'),
                document.getElementById('authorized_admin')
            ];

            // Function to check if form is valid
            function checkFormValidity() {
                const confirmationCorrect = confirmInput.value === 'YES_DELETE_ALL_DATA';
                const allCheckboxesChecked = checkboxes.every(cb => cb.checked);
                const atLeastOneTableSelected = document.querySelectorAll('input[name="cleanup_tables[]"]:checked').length > 0;
                
                if (confirmationCorrect && allCheckboxesChecked && atLeastOneTableSelected) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('bg-gray-400', 'cursor-not-allowed');
                    submitBtn.classList.add('bg-red-600', 'hover:bg-red-700');
                } else {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('bg-gray-400', 'cursor-not-allowed');
                    submitBtn.classList.remove('bg-red-600', 'hover:bg-red-700');
                }
            }

            // Add event listeners
            confirmInput.addEventListener('input', checkFormValidity);
            checkboxes.forEach(cb => cb.addEventListener('change', checkFormValidity));
            
            // Add event listeners to table selection checkboxes
            document.querySelectorAll('input[name="cleanup_tables[]"]').forEach(cb => {
                cb.addEventListener('change', checkFormValidity);
            });

            // Final confirmation before submit
            cleanupForm.addEventListener('submit', function(e) {
                const selectedTables = Array.from(document.querySelectorAll('input[name="cleanup_tables[]"]:checked'))
                    .map(cb => cb.parentElement.querySelector('.text-sm.font-medium').textContent);
                
                if (selectedTables.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one data category to delete.');
                    return;
                }

                const confirmMessage = `⚠️ FINAL WARNING ⚠️\n\nYou are about to PERMANENTLY DELETE the following data:\n\n${selectedTables.join('\n')}\n\nThis action CANNOT be undone!\n\nAre you absolutely sure you want to proceed?`;
                
                if (!confirm(confirmMessage)) {
                    e.preventDefault();
                }
            });
        });

        // Helper functions for select all/none
        function selectAllCleanup() {
            document.querySelectorAll('input[name="cleanup_tables[]"]').forEach(cb => {
                cb.checked = true;
            });
            // Trigger validation check
            document.getElementById('confirm_cleanup').dispatchEvent(new Event('input'));
        }

        function selectNoneCleanup() {
            document.querySelectorAll('input[name="cleanup_tables[]"]').forEach(cb => {
                cb.checked = false;
            });
            // Trigger validation check
            document.getElementById('confirm_cleanup').dispatchEvent(new Event('input'));
        }

        // Visual feedback for dangerous actions
        document.addEventListener('DOMContentLoaded', function() {
            const dangerousButtons = document.querySelectorAll('.bg-red-600');
            dangerousButtons.forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    if (!this.disabled) {
                        this.style.transform = 'scale(1.02)';
                        this.style.transition = 'transform 0.1s ease';
                    }
                });
                
                btn.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });
        });
    </script>
</body>
</html>