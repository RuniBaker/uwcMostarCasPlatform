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

// Process URL parameters and set default values
$view_type = isset($_GET['view_type']) && in_array($_GET['view_type'], ['summary', 'by_student', 'detailed']) 
    ? $_GET['view_type'] 
    : 'summary';

$cas_id = isset($_GET['cas_id']) ? filter_var($_GET['cas_id'], FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]) : 0;
$student_id = isset($_GET['student_id']) ? filter_var($_GET['student_id'], FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0]]) : 0;
$year_filter = isset($_GET['year']) && in_array($_GET['year'], ['all', 'Y1', 'Y2']) ? $_GET['year'] : 'all';

// Validate and set date ranges
$today = date('Y-m-d');
$thirty_days_ago = date('Y-m-d', strtotime('-30 days'));

// Validate start_date and end_date
if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
    $start_date = date('Y-m-d', strtotime($_GET['start_date']));
    // Check if the parsed date is valid
    if ($start_date === false || $start_date === '-0001-11-30') {
        $start_date = $thirty_days_ago;
    }
} else {
    $start_date = $thirty_days_ago;
}

if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
    $end_date = date('Y-m-d', strtotime($_GET['end_date']));
    // Check if the parsed date is valid
    if ($end_date === false || $end_date === '-0001-11-30') {
        $end_date = $today;
    }
} else {
    $end_date = $today;
}

// Ensure start_date is not after end_date
if (strtotime($start_date) > strtotime($end_date)) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

// Get list of CAS activities for filter dropdown
$cas_activities = [];
$stmt = $conn->prepare("SELECT cas_id, cas_name, cas_type FROM cas_activities ORDER BY cas_name");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $cas_activities[] = $row;
}
$stmt->close();

// Get list of students for filter dropdown
$students = [];
$stmt = $conn->prepare("SELECT student_id, first_name, last_name, grade_year FROM students WHERE is_active = 1 ORDER BY last_name, first_name");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();

// Function to get attendance summary by CAS activity
function getAttendanceByCAS($conn, $start_date, $end_date, $year_filter) {
    $query = "
        SELECT 
            ca.cas_id,
            ca.cas_name,
            ca.cas_type,
            COUNT(DISTINCT ats.session_id) AS session_count,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END) AS excused_count,
            COUNT(ar.record_id) AS total_records,
            ROUND((SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) / 
                  NULLIF(COUNT(ar.record_id), 0)) * 100, 1) AS attendance_rate
        FROM 
            cas_activities ca
        LEFT JOIN 
            attendance_sessions ats ON ca.cas_id = ats.cas_id
            AND ats.session_date BETWEEN ? AND ?
        LEFT JOIN 
            attendance_records ar ON ats.session_id = ar.session_id
    ";
    
    $params = [$start_date, $end_date];
    $types = "ss";
    
    // Add year filter if specified
    if ($year_filter !== 'all') {
        $query .= " 
        LEFT JOIN 
            students s ON ar.student_id = s.student_id 
        WHERE 
            s.grade_year = ? OR s.grade_year IS NULL
        ";
        $params[] = $year_filter;
        $types .= "s";
    }
    
    $query .= "
        GROUP BY 
            ca.cas_id, ca.cas_name, ca.cas_type
        ORDER BY 
            ca.cas_type, ca.cas_name
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $cas_summary = [];
    while ($row = $result->fetch_assoc()) {
        // Make sure attendance_rate is not NULL
        if ($row['attendance_rate'] === NULL) {
            $row['attendance_rate'] = 0;
        }
        $cas_summary[] = $row;
    }
    
    $stmt->close();
    return $cas_summary;
}

// Function to get attendance summary by student
function getAttendanceByStudent($conn, $start_date, $end_date, $cas_id = 0) {
    $query = "
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            s.grade_year,
            COUNT(DISTINCT ats.session_id) AS attended_sessions,
            (
                SELECT COUNT(DISTINCT session_id) 
                FROM attendance_sessions 
                WHERE session_date BETWEEN ? AND ?
                " . ($cas_id > 0 ? "AND cas_id = ?" : "") . "
            ) AS total_sessions,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END) AS excused_count,
            ROUND((SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) / 
                  NULLIF(COUNT(ar.record_id), 0)) * 100, 1) AS attendance_rate
        FROM 
            students s
        JOIN 
            student_cas_enrollment sce ON s.student_id = sce.student_id
        JOIN 
            cas_activities ca ON sce.cas_id = ca.cas_id
        LEFT JOIN 
            attendance_sessions ats ON ca.cas_id = ats.cas_id
            AND ats.session_date BETWEEN ? AND ?
        LEFT JOIN 
            attendance_records ar ON ats.session_id = ar.session_id AND ar.student_id = s.student_id
        WHERE 
            s.is_active = 1
    ";
    
    $params = [$start_date, $end_date];
    $types = "ss";
    
    if ($cas_id > 0) {
        $params[] = $cas_id;
        $types .= "i";
    }
    
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
    
    if ($cas_id > 0) {
        $query .= " AND ca.cas_id = ?";
        $params[] = $cas_id;
        $types .= "i";
    }
    
    $query .= "
        GROUP BY 
            s.student_id, s.first_name, s.last_name, s.grade_year
        ORDER BY 
            s.grade_year, s.last_name, s.first_name
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $student_summary = [];
    while ($row = $result->fetch_assoc()) {
        // Make sure attendance_rate is not NULL
        if ($row['attendance_rate'] === NULL) {
            $row['attendance_rate'] = 0;
        }
        $student_summary[] = $row;
    }
    
    $stmt->close();
    return $student_summary;
}

// Function to get detailed attendance records
function getDetailedAttendance($conn, $cas_id, $student_id, $start_date, $end_date) {
    $query = "
        SELECT 
            ats.session_id,
            ats.session_date,
            ca.cas_id,
            ca.cas_name,
            ca.cas_type,
            s.student_id,
            s.first_name,
            s.last_name,
            s.grade_year,
            ar.status,
            ar.notes,
            u.first_name AS recorded_by_first,
            u.last_name AS recorded_by_last
        FROM 
            attendance_sessions ats
        JOIN 
            cas_activities ca ON ats.cas_id = ca.cas_id
        JOIN 
            attendance_records ar ON ats.session_id = ar.session_id
        JOIN 
            students s ON ar.student_id = s.student_id
        JOIN 
            users u ON ats.recorded_by = u.user_id
        WHERE 
            ats.session_date BETWEEN ? AND ?
    ";
    
    $params = [$start_date, $end_date];
    $types = "ss";
    
    if ($cas_id > 0) {
        $query .= " AND ca.cas_id = ?";
        $params[] = $cas_id;
        $types .= "i";
    }
    
    if ($student_id > 0) {
        $query .= " AND s.student_id = ?";
        $params[] = $student_id;
        $types .= "i";
    }
    
    $query .= " ORDER BY ats.session_date DESC, ca.cas_name, s.last_name, s.first_name";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $detailed_records = [];
    while ($row = $result->fetch_assoc()) {
        $detailed_records[] = $row;
    }
    
    $stmt->close();
    return $detailed_records;
}

// Function to get students' attendance for a specific CAS activity
function getStudentsByCasActivity($conn, $cas_id, $start_date, $end_date) {
    $query = "
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            s.grade_year,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END) AS excused_count
        FROM 
            students s
        JOIN 
            student_cas_enrollment sce ON s.student_id = sce.student_id
        LEFT JOIN 
            attendance_sessions ats ON sce.cas_id = ats.cas_id AND ats.session_date BETWEEN ? AND ?
        LEFT JOIN 
            attendance_records ar ON ats.session_id = ar.session_id AND ar.student_id = s.student_id
        WHERE 
            sce.cas_id = ?
            AND s.is_active = 1
        GROUP BY 
            s.student_id, s.first_name, s.last_name, s.grade_year
        ORDER BY 
            s.last_name, s.first_name
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssi", $start_date, $end_date, $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $students_data = [];
    while ($row = $result->fetch_assoc()) {
        $students_data[] = $row;
    }
    
    $stmt->close();
    return $students_data;
}

// Function to get CAS activities for a specific student
function getCasActivitiesByStudent($conn, $student_id, $start_date, $end_date) {
    $query = "
        SELECT 
            ca.cas_id,
            ca.cas_name,
            ca.cas_type,
            SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END) AS excused_count
        FROM 
            cas_activities ca
        JOIN 
            student_cas_enrollment sce ON ca.cas_id = sce.cas_id
        LEFT JOIN 
            attendance_sessions ats ON ca.cas_id = ats.cas_id AND ats.session_date BETWEEN ? AND ?
        LEFT JOIN 
            attendance_records ar ON ats.session_id = ar.session_id AND ar.student_id = sce.student_id
        WHERE 
            sce.student_id = ?
        GROUP BY 
            ca.cas_id, ca.cas_name, ca.cas_type
        ORDER BY 
            ca.cas_name
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssi", $start_date, $end_date, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $activities_data = [];
    while ($row = $result->fetch_assoc()) {
        $activities_data[] = $row;
    }
    
    $stmt->close();
    return $activities_data;
}

// Get attendance data based on view type for table display
$attendance_data = [];
if ($view_type === 'summary') {
    $attendance_data = getAttendanceByCAS($conn, $start_date, $end_date, $year_filter);
} else if ($view_type === 'by_student') {
    $attendance_data = getAttendanceByStudent($conn, $start_date, $end_date, $cas_id);
} else if ($view_type === 'detailed') {
    $attendance_data = getDetailedAttendance($conn, $cas_id, $student_id, $start_date, $end_date);
}

// Prepare data for the chart based on the current view and filters
$chart_data = [];
$chart_title = '';
$chart_labels = [];
$chart_present = [];
$chart_absent = [];
$chart_excused = [];
$axis_label = '';

if ($view_type === 'summary') {
    // Summary view (all CAS activities)
    $chart_data = $attendance_data;
    $chart_title = 'Attendance by CAS Activity';
    $axis_label = 'CAS Activities';
    
    foreach ($chart_data as $row) {
        $chart_labels[] = $row['cas_name'];
        $chart_present[] = (int)$row['present_count'];
        $chart_absent[] = (int)$row['absent_count'];
        $chart_excused[] = (int)$row['excused_count'];
    }
} else if ($view_type === 'by_student') {
    if ($cas_id > 0) {
        // Get the specific CAS activity name
        $selected_cas_name = '';
        foreach ($cas_activities as $activity) {
            if ((int)$activity['cas_id'] === $cas_id) {
                $selected_cas_name = $activity['cas_name'];
                break;
            }
        }
        
        // Get student attendance data for this specific CAS activity
        $chart_data = getStudentsByCasActivity($conn, $cas_id, $start_date, $end_date);
        $chart_title = 'Student Attendance for ' . htmlspecialchars($selected_cas_name);
        $axis_label = 'Students';
        
        foreach ($chart_data as $student) {
            $chart_labels[] = $student['first_name'] . ' ' . $student['last_name'];
            $chart_present[] = (int)$student['present_count'];
            $chart_absent[] = (int)$student['absent_count'];
            $chart_excused[] = (int)$student['excused_count'];
        }
    } else {
        // All CAS activities, student summary view
        $chart_data = $attendance_data;
        $chart_title = 'Student Attendance Summary';
        $axis_label = 'Students';
        
        foreach ($chart_data as $row) {
            $chart_labels[] = $row['first_name'] . ' ' . $row['last_name'];
            $chart_present[] = (int)$row['present_count'];
            $chart_absent[] = (int)$row['absent_count'];
            $chart_excused[] = (int)$row['excused_count'];
        }
    }
} else if ($view_type === 'detailed' && $student_id > 0) {
    // Single student, show all their CAS activities
    $selected_student_name = '';
    foreach ($students as $student) {
        if ((int)$student['student_id'] === $student_id) {
            $selected_student_name = $student['first_name'] . ' ' . $student['last_name'];
            break;
        }
    }
    
    $chart_data = getCasActivitiesByStudent($conn, $student_id, $start_date, $end_date);
    $chart_title = 'CAS Activities for ' . htmlspecialchars($selected_student_name);
    $axis_label = 'CAS Activities';
    
    foreach ($chart_data as $activity) {
        $chart_labels[] = $activity['cas_name'];
        $chart_present[] = (int)$activity['present_count'];
        $chart_absent[] = (int)$activity['absent_count'];
        $chart_excused[] = (int)$activity['excused_count'];
    }
}

// Get the name of the selected CAS activity
$selected_cas_name = '';
if ($cas_id > 0) {
    foreach ($cas_activities as $activity) {
        if ((int)$activity['cas_id'] === $cas_id) {
            $selected_cas_name = $activity['cas_name'];
            break;
        }
    }
}

// Get the name of the selected student
$selected_student_name = '';
if ($student_id > 0) {
    foreach ($students as $student) {
        if ((int)$student['student_id'] === $student_id) {
            $selected_student_name = $student['first_name'] . ' ' . $student['last_name'];
            break;
        }
    }
}

// Export to CSV if requested
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Set headers
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_report_' . date('Y-m-d') . '.csv"');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add headers based on view type
    if ($view_type === 'summary') {
        fputcsv($output, ['CAS Name', 'Type', 'Sessions', 'Present', 'Absent', 'Excused', 'Attendance Rate (%)']);
        
        foreach ($attendance_data as $row) {
            fputcsv($output, [
                $row['cas_name'],
                ucfirst($row['cas_type']),
                $row['session_count'],
                $row['present_count'],
                $row['absent_count'],
                $row['excused_count'],
                $row['attendance_rate']
            ]);
        }
    } else if ($view_type === 'by_student') {
        fputcsv($output, ['Student Name', 'Year', 'Attended Sessions', 'Total Sessions', 'Present', 'Absent', 'Excused', 'Attendance Rate (%)']);
        
        foreach ($attendance_data as $row) {
            fputcsv($output, [
                $row['first_name'] . ' ' . $row['last_name'],
                $row['grade_year'],
                $row['attended_sessions'],
                $row['total_sessions'],
                $row['present_count'],
                $row['absent_count'],
                $row['excused_count'],
                $row['attendance_rate']
            ]);
        }
    } else if ($view_type === 'detailed') {
        fputcsv($output, ['Date', 'CAS Activity', 'Type', 'Student', 'Year', 'Status', 'Notes', 'Recorded By']);
        
        foreach ($attendance_data as $row) {
            fputcsv($output, [
                date('m/d/Y', strtotime($row['session_date'])),
                $row['cas_name'],
                ucfirst($row['cas_type']),
                $row['first_name'] . ' ' . $row['last_name'],
                $row['grade_year'],
                ucfirst($row['status']),
                $row['notes'],
                $row['recorded_by_first'] . ' ' . $row['recorded_by_last']
            ]);
        }
    }
    
    fclose($output);
    exit;
}

// Encode chart data for JavaScript
$chart_labels_json = json_encode($chart_labels);
$chart_present_json = json_encode($chart_present);
$chart_absent_json = json_encode($chart_absent);
$chart_excused_json = json_encode($chart_excused);
$chart_title_json = json_encode($chart_title);
$axis_label_json = json_encode($axis_label);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Reports - UWC Mostar CAS</title>
    <link rel="icon" type="image/x-icon" href="../tab.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <!-- Add Chart.js for visualizations -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.0/chart.min.js"></script>
    <style>
        /* Custom styles for charts */
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }
        
        @media (min-width: 768px) {
            .chart-container {
                height: 400px;
                margin-bottom: 30px;
            }
        }
        
        /* Ensure filter visibility */
        .filter-section:not(.hidden) {
            display: block !important;
        }
        
        /* Fix for rotated labels */
        canvas {
            max-height: 400px;
        }
        
        /* Quick filter buttons */
        .quick-filter-btn {
            transition: all 0.2s ease-in-out;
        }
        
        .quick-filter-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
    </style>
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
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6">Attendance Reports</h1>
                
                <?php if (!empty($message)): ?>
                <div class="mb-6 alert-dismissible <?php echo $message_type === 'error' ? 'bg-red-100 border-red-400 text-red-700' : 'bg-green-100 border-green-400 text-green-700'; ?> px-4 py-3 rounded relative border" role="alert">
                    <span class="block sm:inline"><?php echo $message; ?></span>
                    <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>

                <!-- Filters and Report Type Selection -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 mb-6">
                    <form action="attendance_report.php" method="GET" id="filterForm" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div>
                                <label for="view_type" class="block text-sm font-medium text-gray-700 mb-1">Report Type</label>
                                <select id="view_type" name="view_type" class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-amber-500 text-sm sm:text-base">
                                    <option value="summary" <?php echo $view_type === 'summary' ? 'selected' : ''; ?>>CAS Summary</option>
                                    <option value="by_student" <?php echo $view_type === 'by_student' ? 'selected' : ''; ?>>Student Summary</option>
                                    <option value="detailed" <?php echo $view_type === 'detailed' ? 'selected' : ''; ?>>Detailed Records</option>
                                </select>
                            </div>
                            
                            <div id="cas_filter_section" class="filter-section <?php echo $view_type === 'summary' ? 'hidden' : ''; ?>">
                                <label for="cas_id" class="block text-sm font-medium text-gray-700 mb-1">CAS Activity</label>
                                <select id="cas_id" name="cas_id" class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-amber-500 text-sm sm:text-base" <?php echo $view_type === 'summary' ? 'disabled' : ''; ?>>
                                    <option value="0">All CAS Activities</option>
                                    <?php foreach ($cas_activities as $activity): ?>
                                    <option value="<?php echo $activity['cas_id']; ?>" <?php echo (int)$cas_id === (int)$activity['cas_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($activity['cas_name']); ?> (<?php echo ucfirst($activity['cas_type']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div id="student_filter_section" class="filter-section <?php echo $view_type !== 'detailed' ? 'hidden' : ''; ?>">
                                <label for="student_id" class="block text-sm font-medium text-gray-700 mb-1">Student</label>
                                <select id="student_id" name="student_id" class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-amber-500 text-sm sm:text-base" <?php echo $view_type !== 'detailed' ? 'disabled' : ''; ?>>
                                    <option value="0">All Students</option>
                                    <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['student_id']; ?>" <?php echo (int)$student_id === (int)$student['student_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> (<?php echo $student['grade_year']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div id="year_filter_section" class="filter-section <?php echo $view_type !== 'summary' ? 'hidden' : ''; ?>">
                                <label for="year" class="block text-sm font-medium text-gray-700 mb-1">Year</label>
                                <select id="year" name="year" class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-amber-500 text-sm sm:text-base" <?php echo $view_type !== 'summary' ? 'disabled' : ''; ?>>
                                    <option value="all" <?php echo $year_filter === 'all' ? 'selected' : ''; ?>>All Years</option>
                                    <option value="Y1" <?php echo $year_filter === 'Y1' ? 'selected' : ''; ?>>Y1</option>
                                    <option value="Y2" <?php echo $year_filter === 'Y2' ? 'selected' : ''; ?>>Y2</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div>
                                <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                                <input type="date" id="start_date" name="start_date" value="<?php echo $start_date; ?>" max="<?php echo date('Y-m-d'); ?>"
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-amber-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                                <input type="date" id="end_date" name="end_date" value="<?php echo $end_date; ?>" max="<?php echo date('Y-m-d'); ?>"
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-amber-500 text-sm sm:text-base">
                            </div>
                            
                            <div class="flex flex-col sm:flex-row items-end space-y-2 sm:space-y-0 sm:space-x-2">
                                <button type="submit" class="w-full sm:w-auto px-4 sm:px-6 py-2 bg-amber-600 text-black rounded-md hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 text-sm sm:text-base">
                                    <i class="fas fa-filter mr-2"></i> Apply Filters
                                </button>
                                
                                <button type="button" id="resetFiltersBtn" class="w-full sm:w-auto px-3 sm:px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 text-sm sm:text-base">   
                                    <i class="fas fa-redo"></i>
                                </button>
                                
                                <button type="submit" name="export" value="csv" class="w-full sm:w-auto px-3 sm:px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                                    <i class="fas fa-file-csv mr-2"></i> Export
                                </button>
                            </div>
                        </div>
                        
                        <!-- Quick Filters -->
                        <div class="pt-4 border-t border-gray-200">
                            <h3 class="text-sm font-medium text-gray-700 mb-2">Quick Filters:</h3>
                            <div class="flex flex-wrap gap-2">
                                <!-- Date range quick filters -->
                                <button type="button" class="quick-filter-btn px-2 sm:px-3 py-1 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 text-xs" 
                                        data-filter-type="date_range" data-filter-value="week">
                                    Last Week
                                </button>
                                <button type="button" class="quick-filter-btn px-2 sm:px-3 py-1 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 text-xs" 
                                        data-filter-type="date_range" data-filter-value="month">
                                    Last Month
                                </button>
                                <button type="button" class="quick-filter-btn px-2 sm:px-3 py-1 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 text-xs" 
                                        data-filter-type="date_range" data-filter-value="quarter">
                                    Last 3 Months
                                </button>
                                
                                <!-- CAS type quick filters -->
                                <button type="button" class="quick-filter-btn px-2 sm:px-3 py-1 bg-purple-100 text-purple-700 rounded-md hover:bg-purple-200 text-xs" 
                                        data-filter-type="view" data-filter-value="summary">
                                    All CAS Summary
                                </button>
                                
                                <!-- Student year quick filters -->
                                <button type="button" class="quick-filter-btn px-2 sm:px-3 py-1 bg-blue-100 text-blue-700 rounded-md hover:bg-blue-200 text-xs" 
                                        data-filter-type="year" data-filter-value="Y1">
                                    Y1 Students
                                </button>
                                <button type="button" class="quick-filter-btn px-2 sm:px-3 py-1 bg-blue-100 text-blue-700 rounded-md hover:bg-blue-200 text-xs" 
                                        data-filter-type="year" data-filter-value="Y2">
                                    Y2 Students
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <?php if (!empty($chart_data)): ?>
                <!-- Chart Display -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                    <div class="px-4 sm:px-6 py-3 sm:py-4 bg-amber-100 border-b border-amber-200">
                        <h2 class="text-lg sm:text-xl font-bold text-amber-800">
                            <i class="fas fa-chart-bar mr-2"></i> <?php echo htmlspecialchars($chart_title); ?>
                        </h2>
                        <p class="text-amber-700 text-xs sm:text-sm">
                            Showing attendance data from <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?>
                            <?php 
                            if (!empty($selected_cas_name) && $view_type === 'by_student') {
                                echo " for " . htmlspecialchars($selected_cas_name);
                            } elseif (!empty($selected_student_name) && $view_type === 'detailed') {
                                echo " for " . htmlspecialchars($selected_student_name);
                            } elseif ($year_filter !== 'all' && $view_type === 'summary') {
                                echo " for $year_filter students";
                            }
                            ?>
                        </p>
                    </div>
                    
                    <div class="p-4 sm:p-6">
                        <div class="chart-container">
                            <canvas id="attendanceChart"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Data Tables -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="px-4 sm:px-6 py-3 sm:py-4 bg-gray-50 border-b border-gray-200">
                        <h2 class="text-lg sm:text-xl font-bold text-gray-800">
                            <?php
                            if ($view_type === 'summary') {
                                echo 'CAS Activity Summary';
                            } elseif ($view_type === 'by_student') {
                                echo 'Student Attendance Summary';
                            } else {
                                echo 'Detailed Attendance Records';
                            }
                            ?>
                        </h2>
                    </div>
                    
                    <?php if ($view_type === 'summary'): ?>
                    <?php if (empty($attendance_data)): ?>
                    <div class="p-6 text-center text-gray-500">
                        <p>No CAS activities found for the selected criteria.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        CAS Activity
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Type
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Sessions
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Present
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Absent
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Excused
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Rate
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($attendance_data as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 sm:px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($row['cas_name']); ?></div>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $type_color = '';
                                        switch($row['cas_type']) {
                                            case 'creativity':
                                                $type_color = 'bg-purple-100 text-purple-800';
                                                break;
                                            case 'activity':
                                                $type_color = 'bg-blue-100 text-blue-800';
                                                break;
                                            case 'service':
                                                $type_color = 'bg-yellow-100 text-yellow-800';
                                                break;
                                        }
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $type_color; ?>">
                                            <?php echo ucfirst($row['cas_type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $row['session_count']; ?>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-green-600 font-medium">
                                        <?php echo $row['present_count']; ?>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-red-600 font-medium">
                                        <?php echo $row['absent_count']; ?>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-yellow-600 font-medium">
                                        <?php echo $row['excused_count']; ?>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-1 bg-gray-200 rounded-full h-2 mr-2">
                                                <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $row['attendance_rate']; ?>%"></div>
                                            </div>
                                            <span class="text-sm font-medium text-gray-900"><?php echo $row['attendance_rate']; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <?php elseif ($view_type === 'by_student'): ?>
                    <?php if (empty($attendance_data)): ?>
                    <div class="p-6 text-center text-gray-500">
                        <p>No student attendance data found for the selected criteria.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Student
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Year
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Sessions
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Present
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Absent
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Excused
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Rate
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($attendance_data as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 sm:px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>
                                        </div>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                            <?php echo $row['grade_year']; ?>
                                        </span>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $row['attended_sessions'] . '/' . $row['total_sessions']; ?>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-green-600 font-medium">
                                        <?php echo $row['present_count']; ?>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-red-600 font-medium">
                                        <?php echo $row['absent_count']; ?>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-yellow-600 font-medium">
                                        <?php echo $row['excused_count']; ?>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-1 bg-gray-200 rounded-full h-2 mr-2">
                                                <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $row['attendance_rate']; ?>%"></div>
                                            </div>
                                            <span class="text-sm font-medium text-gray-900"><?php echo $row['attendance_rate']; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <?php elseif ($view_type === 'detailed'): ?>
                    <?php if (empty($attendance_data)): ?>
                    <div class="p-6 text-center text-gray-500">
                        <p>No attendance records found for the selected criteria.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Date
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        CAS Activity
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Student
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Year
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Notes
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Recorded By
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php 
                                $current_date = '';
                                foreach ($attendance_data as $row): 
                                    $row_date = date('Y-m-d', strtotime($row['session_date']));
                                    $new_date = ($current_date !== $row_date);
                                    $current_date = $row_date;
                                ?>
                                <tr class="<?php echo $new_date ? 'border-t-2 border-gray-300' : ''; ?> hover:bg-gray-50" data-record-id="<?php echo $row['session_id']; ?>-<?php echo $row['student_id']; ?>">
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                        <?php if ($new_date): ?>
                                        <span class="font-semibold text-sm"><?php echo date('M j, Y', strtotime($row['session_date'])); ?></span>
                                        <?php else: ?>
                                        <span class="text-gray-400 text-sm"><?php echo date('M j, Y', strtotime($row['session_date'])); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4">
                                        <div class="text-sm text-blue-600 hover:text-blue-900">
                                            <?php echo htmlspecialchars($row['cas_name']); ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?php echo ucfirst($row['cas_type']); ?>
                                        </div>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4">
                                        <div class="text-sm text-blue-600 hover:text-blue-900">
                                            <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>
                                        </div>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                            <?php echo $row['grade_year']; ?>
                                        </span>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                        <!-- Status Display/Edit -->
                                        <div class="status-container">
                                            <!-- Display Status -->
                                            <span class="status-display cursor-pointer inline-flex items-center" onclick="toggleStatusEdit(this)">
                                                <?php
                                                switch ($row['status']) {
                                                    case 'present':
                                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800"><i class="fas fa-check mr-1"></i>Present</span>';
                                                        break;
                                                    case 'absent':
                                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800"><i class="fas fa-times mr-1"></i>Absent</span>';
                                                        break;
                                                    case 'excused':
                                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800"><i class="fas fa-calendar-times mr-1"></i>Excused</span>';
                                                        break;
                                                }
                                                ?>
                                                <i class="fas fa-edit ml-2 text-gray-400 hover:text-gray-600"></i>
                                            </span>
                                            
                                            <!-- Edit Status -->
                                            <div class="status-edit hidden">
    <select class="status-select w-full text-xs border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
            data-session-id="<?php echo $row['session_id']; ?>" 
            data-student-id="<?php echo $row['student_id']; ?>"
            data-original-status="<?php echo $row['status']; ?>">
        <option value="present" <?php echo $row['status'] === 'present' ? 'selected' : ''; ?>>Present</option>
        <option value="absent" <?php echo $row['status'] === 'absent' ? 'selected' : ''; ?>>Absent</option>
        <option value="excused" <?php echo $row['status'] === 'excused' ? 'selected' : ''; ?>>Excused</option>
    </select>
    <div class="flex mt-1 space-x-1">
        <button class="save-status px-2 py-1 bg-green-600 text-white text-xs rounded hover:bg-green-700" onclick="saveStatusChange(this)">
            <i class="fas fa-save"></i>
        </button>
        <button class="cancel-status px-2 py-1 bg-gray-400 text-white text-xs rounded hover:bg-gray-500" onclick="cancelStatusEdit(this)">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>    
                                        </div>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4">
                                        <div class="text-sm text-gray-900">
                                            <?php echo !empty($row['notes']) ? htmlspecialchars($row['notes']) : '<span class="text-gray-400">No notes</span>'; ?>
                                        </div>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($row['recorded_by_first'] . ' ' . $row['recorded_by_last']); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
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


// JavaScript functions for inline attendance status editing
// Add this to your main JavaScript section in attendance_report.php

// Toggle status editing mode
function toggleStatusEdit(element) {
    const container = element.closest('.status-container');
    const display = container.querySelector('.status-display');
    const edit = container.querySelector('.status-edit');
    
    // Hide display, show edit
    display.classList.add('hidden');
    edit.classList.remove('hidden');
    
    // Focus on the select element
    const select = edit.querySelector('.status-select');
    select.focus();
}

// Cancel status editing
function cancelStatusEdit(button) {
    const container = button.closest('.status-container');
    const display = container.querySelector('.status-display');
    const edit = container.querySelector('.status-edit');
    const select = edit.querySelector('.status-select');
    
    // Reset select to original value
    select.value = select.dataset.originalStatus;
    
    // Show display, hide edit
    display.classList.remove('hidden');
    edit.classList.add('hidden');
}

// Save status change
function saveStatusChange(button) {
    const container = button.closest('.status-container');
    const select = container.querySelector('.status-select');
    const sessionId = select.dataset.sessionId;
    const studentId = select.dataset.studentId;
    const newStatus = select.value;
    const originalStatus = select.dataset.originalStatus;
    
    // If no change, just cancel
    if (newStatus === originalStatus) {
        cancelStatusEdit(button);
        return;
    }
    
    // Show loading state
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    // Prepare request data
    const requestData = {
        session_id: parseInt(sessionId),
        student_id: parseInt(studentId),
        new_status: newStatus
    };
    
    // Send AJAX request
    fetch('update_attendance_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(requestData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update the display with new status
            updateStatusDisplay(container, newStatus);
            
            // Update original status for future comparisons
            select.dataset.originalStatus = newStatus;
            
            // Show success message
            showStatusMessage('Attendance status updated successfully', 'success');
            
            // Hide edit mode
            const display = container.querySelector('.status-display');
            const edit = container.querySelector('.status-edit');
            display.classList.remove('hidden');
            edit.classList.add('hidden');
            
        } else {
            // Show error message
            showStatusMessage(data.message || 'Failed to update attendance status', 'error');
            
            // Reset select to original value
            select.value = originalStatus;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showStatusMessage('Network error. Please try again.', 'error');
        
        // Reset select to original value
        select.value = originalStatus;
    })
    .finally(() => {
        // Reset button state
        button.disabled = false;
        button.innerHTML = '<i class="fas fa-save"></i>';
    });
}

// Update status display with new status
function updateStatusDisplay(container, newStatus) {
    const display = container.querySelector('.status-display');
    let statusHtml = '';
    
    switch (newStatus) {
        case 'present':
            statusHtml = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800"><i class="fas fa-check mr-1"></i>Present</span>';
            break;
        case 'absent':
            statusHtml = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800"><i class="fas fa-times mr-1"></i>Absent</span>';
            break;
        case 'excused':
            statusHtml = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800"><i class="fas fa-calendar-times mr-1"></i>Excused</span>';
            break;
    }
    
    statusHtml += '<i class="fas fa-edit ml-2 text-gray-400 hover:text-gray-600"></i>';
    display.innerHTML = statusHtml;
}

// Show status message (success/error)
function showStatusMessage(message, type) {
    // Remove any existing status messages
    const existingMessage = document.getElementById('status-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    // Create message element
    const messageDiv = document.createElement('div');
    messageDiv.id = 'status-message';
    messageDiv.className = `fixed top-20 right-4 z-50 p-4 rounded-lg shadow-lg transition-all duration-300 ${
        type === 'success' 
            ? 'bg-green-100 border border-green-400 text-green-700' 
            : 'bg-red-100 border border-red-400 text-red-700'
    }`;
    
    messageDiv.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-2"></i>
            <span>${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    // Add to page
    document.body.appendChild(messageDiv);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (messageDiv && messageDiv.parentNode) {
            messageDiv.remove();
        }
    }, 5000);
}

// Close edit mode when clicking outside
document.addEventListener('click', function(event) {
    const statusContainers = document.querySelectorAll('.status-container');
    
    statusContainers.forEach(container => {
        const edit = container.querySelector('.status-edit');
        const display = container.querySelector('.status-display');
        
        // If edit mode is visible and click is outside the container
        if (!edit.classList.contains('hidden') && !container.contains(event.target)) {
            // Cancel the edit
            const select = edit.querySelector('.status-select');
            select.value = select.dataset.originalStatus;
            
            display.classList.remove('hidden');
            edit.classList.add('hidden');
        }
    });
});

// Handle keyboard shortcuts in edit mode
document.addEventListener('keydown', function(event) {
    if (event.target.classList.contains('status-select')) {
        if (event.key === 'Escape') {
            // Cancel edit
            const cancelButton = event.target.closest('.status-edit').querySelector('.cancel-status');
            cancelStatusEdit(cancelButton);
        } else if (event.key === 'Enter') {
            // Save change
            event.preventDefault();
            const saveButton = event.target.closest('.status-edit').querySelector('.save-status');
            saveStatusChange(saveButton);
        }
    }
});



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

            // Filter form functionality
            const viewTypeSelect = document.getElementById('view_type');
            const casFilterSection = document.getElementById('cas_filter_section');
            const studentFilterSection = document.getElementById('student_filter_section');
            const yearFilterSection = document.getElementById('year_filter_section');
            const casIdSelect = document.getElementById('cas_id');
            const studentIdSelect = document.getElementById('student_id');
            const yearSelect = document.getElementById('year');
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const filterForm = document.getElementById('filterForm');
            
            // Function to update filter visibility and enabled state
            function updateFilterVisibility() {
                const viewType = viewTypeSelect.value;
                
                // Hide all filter sections first
                casFilterSection.classList.add('hidden');
                studentFilterSection.classList.add('hidden');
                yearFilterSection.classList.add('hidden');
                
                // Disable all filters
                casIdSelect.disabled = true;
                studentIdSelect.disabled = true;
                yearSelect.disabled = true;
                
                // Show and enable relevant filters based on view type
                if (viewType === 'summary') {
                    yearFilterSection.classList.remove('hidden');
                    yearSelect.disabled = false;
                } else if (viewType === 'by_student') {
                    casFilterSection.classList.remove('hidden');
                    casIdSelect.disabled = false;
                } else if (viewType === 'detailed') {
                    casFilterSection.classList.remove('hidden');
                    studentFilterSection.classList.remove('hidden');
                    casIdSelect.disabled = false;
                    studentIdSelect.disabled = false;
                }
            }
            

            // Update filter visibility when the view type changes
            viewTypeSelect.addEventListener('change', updateFilterVisibility);

            // Handle reset filters button
            const resetFiltersBtn = document.getElementById('resetFiltersBtn');
            resetFiltersBtn.addEventListener('click', function() {
                // Reset select fields
                viewTypeSelect.value = 'summary';
                casIdSelect.value = '0';
                studentIdSelect.value = '0';
                yearSelect.value = 'all';
                
                // Reset date fields to last 30 days
                const today = new Date();
                const thirtyDaysAgo = new Date();
                thirtyDaysAgo.setDate(today.getDate() - 30);
                
                startDateInput.value = formatDate(thirtyDaysAgo);
                endDateInput.value = formatDate(today);
                
                // Update filter visibility
                updateFilterVisibility();
                
                // Submit the form
                filterForm.submit();
            });
            
            // Helper function to format date as YYYY-MM-DD
            function formatDate(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            }
            
            // Form validation
            filterForm.addEventListener('submit', function(e) {
                // Validate date range
                const startDate = new Date(startDateInput.value);
                const endDate = new Date(endDateInput.value);
                
                if (startDate > endDate) {
                    e.preventDefault();
                    alert('Start date cannot be after end date');
                    return false;
                }
                
                // Enable appropriate filters before submission
                if (viewTypeSelect.value === 'summary') {
                    yearSelect.disabled = false;
                    casIdSelect.disabled = true;
                    studentIdSelect.disabled = true;
                } else if (viewTypeSelect.value === 'by_student') {
                    yearSelect.disabled = true;
                    casIdSelect.disabled = false;
                    studentIdSelect.disabled = true;
                } else if (viewTypeSelect.value === 'detailed') {
                    yearSelect.disabled = true;
                    casIdSelect.disabled = false;
                    studentIdSelect.disabled = false;
                }
                
                return true;
            });

            // Add event listeners for quick filter buttons
            document.querySelectorAll('.quick-filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const filterType = this.dataset.filterType;
                    const filterValue = this.dataset.filterValue;
                    
                    if (filterType === 'cas') {
                        viewTypeSelect.value = 'by_student';
                        casIdSelect.value = filterValue;
                    } else if (filterType === 'student') {
                        viewTypeSelect.value = 'detailed';
                        studentIdSelect.value = filterValue;
                    } else if (filterType === 'year') {
                        viewTypeSelect.value = 'summary';
                        yearSelect.value = filterValue;
                    } else if (filterType === 'date_range') {
                        // Handle predefined date ranges
                        const today = new Date();
                        let startDate = new Date();
                        
                        if (filterValue === 'week') {
                            startDate.setDate(today.getDate() - 7);
                        } else if (filterValue === 'month') {
                            startDate.setMonth(today.getMonth() - 1);
                        } else if (filterValue === 'quarter') {
                            startDate.setMonth(today.getMonth() - 3);
                        } else if (filterValue === 'year') {
                            startDate.setFullYear(today.getFullYear() - 1);
                        }
                        
                        startDateInput.value = formatDate(startDate);
                        endDateInput.value = formatDate(today);
                    } else if (filterType === 'view') {
                        viewTypeSelect.value = filterValue;
                    }
                    
                    updateFilterVisibility();
                    filterForm.submit();
                });
            });
            
            // Initialize filter visibility
            updateFilterVisibility();
            
            <?php if (!empty($chart_data)): ?>
            // Create attendance chart
            const ctx = document.getElementById('attendanceChart').getContext('2d');
            
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo $chart_labels_json; ?>,
                    datasets: [
                        {
                            label: 'Present',
                            data: <?php echo $chart_present_json; ?>,
                            backgroundColor: 'rgba(52, 211, 153, 0.7)',
                            borderColor: 'rgba(52, 211, 153, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Absent',
                            data: <?php echo $chart_absent_json; ?>,
                            backgroundColor: 'rgba(239, 68, 68, 0.7)',
                            borderColor: 'rgba(239, 68, 68, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Excused',
                            data: <?php echo $chart_excused_json; ?>,
                            backgroundColor: 'rgba(251, 191, 36, 0.7)',
                            borderColor: 'rgba(251, 191, 36, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: <?php echo $chart_title_json; ?>,
                            font: { size: 16 }
                        },
                        legend: { position: 'top' },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            stacked: false,
                            title: {
                                display: true,
                                text: <?php echo $axis_label_json; ?>
                            },
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        },
                        y: {
                            stacked: false,
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Records'
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>