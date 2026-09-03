<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
session_start();



if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_status'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}


require_once '../includes/db_connect.php';


$message = "";
$message_type = "";


$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20; // Items per page
$offset = ($page - 1) * $limit;
$activity_type = isset($_GET['activity_type']) ? $_GET['activity_type'] : '';
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';


$tableExists = false;
$checkTableQuery = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'activity_log' LIMIT 1";
$result = $conn->query($checkTableQuery);
if ($result && $result->num_rows > 0) {
    $tableExists = true;
}


$activities = [];





$currentUser = [
    'username' => 'System',
    'full_name' => 'System',
    'user_id' => 0
];

if (isset($_SESSION['user_id'])) {
    $userQuery = "SELECT user_id, username, CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE user_id = ?";
    $stmtUser = $conn->prepare($userQuery);
    $stmtUser->bind_param("i", $_SESSION['user_id']);
    $stmtUser->execute();
    $resultUser = $stmtUser->get_result();
    if ($resultUser->num_rows > 0) {
        $currentUser = $resultUser->fetch_assoc();
    }
    $stmtUser->close();
}

// 2. Update the addActivity function (replace the existing one)
function addActivity(&$activities, $date, $type, $username, $full_name, $details, $user_id = 0) {
    $activities[] = [
        'created_at' => $date,
        'activity_type' => $type,
        'username' => $username,
        'full_name' => $full_name,
        'activity_details' => $details,
        'user_id' => $user_id,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ];
}

// 3. REPLACE the student query section with this:
$studentQuery = "
    SELECT 
        s.created_at,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        s.created_by,
        u.username,
        CONCAT(u.first_name, ' ', u.last_name) as creator_name
    FROM 
        students s
    LEFT JOIN 
        users u ON s.created_by = u.user_id
    ORDER BY 
        s.created_at DESC 
    LIMIT 20
";
$stmtStudents = $conn->prepare($studentQuery);
$stmtStudents->execute();
$resultStudents = $stmtStudents->get_result();
while ($student = $resultStudents->fetch_assoc()) {
    $creatorUsername = $student['username'] ?? $currentUser['username'];
    $creatorName = $student['creator_name'] ?? $currentUser['full_name'];
    $creatorId = $student['created_by'] ?? $currentUser['user_id'];
    
    addActivity(
        $activities, 
        $student['created_at'], 
        'Added new student', 
        $creatorUsername,
        $creatorName,
        'Added ' . $student['student_name'] . ' to system',
        $creatorId
    );
}
$stmtStudents->close();

// 4. Update CAS activities to track creators
$casQuery = "
    SELECT 
        ca.cas_name,
        ca.created_at,
        ca.created_by,
        u.username,
        CONCAT(u.first_name, ' ', u.last_name) as creator_name
    FROM 
        cas_activities ca
    LEFT JOIN 
        users u ON ca.created_by = u.user_id
    ORDER BY 
        ca.created_at DESC 
    LIMIT 20
";
$stmtCAS = $conn->prepare($casQuery);
$stmtCAS->execute();
$resultCAS = $stmtCAS->get_result();
while ($cas = $resultCAS->fetch_assoc()) {
    $creatorUsername = $cas['username'] ?? $currentUser['username'];
    $creatorName = $cas['creator_name'] ?? $currentUser['full_name'];
    $creatorId = $cas['created_by'] ?? $currentUser['user_id'];
    
    addActivity(
        $activities, 
        $cas['created_at'], 
        'Created CAS activity', 
        $creatorUsername,
        $creatorName,
        'Created new "' . $cas['cas_name'] . '" activity',
        $creatorId
    );
}
$stmtCAS->close();

// 5. Update leader assignments to show who made the assignment
$leaderQuery = "
    SELECT 
        cl.created_at,
        ca.cas_name,
        CONCAT(leader.first_name, ' ', leader.last_name) as leader_name,
        cl.assigned_by,
        assigner.username as assigner_username,
        CONCAT(assigner.first_name, ' ', assigner.last_name) as assigner_name
    FROM 
        cas_leaders cl
    JOIN 
        cas_activities ca ON cl.cas_id = ca.cas_id
    JOIN 
        users leader ON cl.user_id = leader.user_id
    LEFT JOIN 
        users assigner ON cl.assigned_by = assigner.user_id
    ORDER BY 
        cl.created_at DESC 
    LIMIT 20
";
$stmtLeaders = $conn->prepare($leaderQuery);
$stmtLeaders->execute();
$resultLeaders = $stmtLeaders->get_result();
while ($leader = $resultLeaders->fetch_assoc()) {
    $assignerUsername = $leader['assigner_username'] ?? $currentUser['username'];
    $assignerName = $leader['assigner_name'] ?? $currentUser['full_name'];
    $assignerId = $leader['assigned_by'] ?? $currentUser['user_id'];
    
    addActivity(
        $activities, 
        $leader['created_at'], 
        'Assigned CAS Leader', 
        $assignerUsername,
        $assignerName,
        'Assigned ' . $leader['leader_name'] . ' as leader for "' . $leader['cas_name'] . '"',
        $assignerId
    );
}
$stmtLeaders->close();

// 6. Update attendance sessions to show who recorded them
$sessionQuery = "
    SELECT 
        ats.created_at,
        ca.cas_name,
        ats.session_date,
        ats.recorded_by,
        recorder.username as recorder_username,
        CONCAT(recorder.first_name, ' ', recorder.last_name) as recorder_name,
        COUNT(ar.record_id) as student_count
    FROM 
        attendance_sessions ats
    JOIN 
        cas_activities ca ON ats.cas_id = ca.cas_id
    LEFT JOIN 
        users recorder ON ats.recorded_by = recorder.user_id
    LEFT JOIN 
        attendance_records ar ON ats.session_id = ar.session_id
    GROUP BY 
        ats.session_id, ats.created_at, ca.cas_name, ats.session_date, 
        ats.recorded_by, recorder.username, recorder_name
    ORDER BY 
        ats.created_at DESC 
    LIMIT 20
";
$stmtSessions = $conn->prepare($sessionQuery);
$stmtSessions->execute();
$resultSessions = $stmtSessions->get_result();
while ($session = $resultSessions->fetch_assoc()) {
    $recorderUsername = $session['recorder_username'] ?? $currentUser['username'];
    $recorderName = $session['recorder_name'] ?? $currentUser['full_name'];
    $recorderId = $session['recorded_by'] ?? $currentUser['user_id'];
    
    addActivity(
        $activities, 
        $session['created_at'], 
        'Recorded Attendance', 
        $recorderUsername,
        $recorderName,
        'Recorded attendance for "' . $session['cas_name'] . '" on ' . date('M j, Y', strtotime($session['session_date'])) . 
        ' (' . $session['student_count'] . ' students)',
        $recorderId
    );
}
$stmtSessions->close();

// 7. Update absence request handling to show who approved/declined
$absenceQuery = "
    SELECT 
        ar.updated_at as created_at,
        ar.status,
        ar.cas_name,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        ar.approved_by,
        handler.username as handler_username,
        CONCAT(handler.first_name, ' ', handler.last_name) as handler_name
    FROM 
        absence_requests ar
    JOIN 
        students s ON ar.student_id = s.student_id
    LEFT JOIN 
        users handler ON ar.approved_by = handler.user_id
    WHERE 
        ar.status != 'pending'
    ORDER BY 
        ar.updated_at DESC 
    LIMIT 20
";
$stmtAbsences = $conn->prepare($absenceQuery);
$stmtAbsences->execute();
$resultAbsences = $stmtAbsences->get_result();
while ($absence = $resultAbsences->fetch_assoc()) {
    $handlerUsername = $absence['handler_username'] ?? $currentUser['username'];
    $handlerName = $absence['handler_name'] ?? $currentUser['full_name'];
    $handlerId = $absence['handled_by'] ?? $currentUser['user_id'];
    
    $action = $absence['status'] === 'approved' ? 'Approved' : 'Declined';
    addActivity(
        $activities, 
        $absence['created_at'], 
        $action . ' absence request', 
        $handlerUsername,
        $handlerName,
        $action . ' absence request for ' . $absence['student_name'] . ' from "' . $absence['cas_name'] . '"',
        $handlerId
    );
}
$stmtAbsences->close();



usort($activities, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});


$filteredActivities = $activities;

if (!empty($activity_type)) {
    $filteredActivities = array_filter($filteredActivities, function($activity) use ($activity_type) {
        return $activity['activity_type'] === $activity_type;
    });
}

if ($user_id > 0) {
   
    $selectedUserQuery = "SELECT username FROM users WHERE user_id = ?";
    $stmtUser = $conn->prepare($selectedUserQuery);
    $stmtUser->bind_param("i", $user_id);
    $stmtUser->execute();
    $userResult = $stmtUser->get_result();
    if ($userResult->num_rows > 0) {
        $selectedUsername = $userResult->fetch_assoc()['username'];
        $filteredActivities = array_filter($filteredActivities, function($activity) use ($selectedUsername) {
            return $activity['username'] === $selectedUsername;
        });
    }
    $stmtUser->close();
}

if (!empty($date_from)) {
    $fromTimestamp = strtotime($date_from . ' 00:00:00');
    $filteredActivities = array_filter($filteredActivities, function($activity) use ($fromTimestamp) {
        return strtotime($activity['created_at']) >= $fromTimestamp;
    });
}

if (!empty($date_to)) {
    $toTimestamp = strtotime($date_to . ' 23:59:59');
    $filteredActivities = array_filter($filteredActivities, function($activity) use ($toTimestamp) {
        return strtotime($activity['created_at']) <= $toTimestamp;
    });
}


$total_records = count($filteredActivities);
$activities = array_slice($filteredActivities, $offset, $limit);

 
if (empty($activities)) {
    $activities = [
        [
            'created_at' => date('Y-m-d H:i:s'),
            'activity_type' => 'System Initialization',
            'username' => $currentUser['username'],
            'full_name' => $currentUser['full_name'],
            'activity_details' => 'Welcome to UWC Mostar CAS System',
            'ip_address' => '127.0.0.1'
        ]
    ];
    $total_records = 1;
}

// Get activity types for filter dropdown
$activityTypes = array_unique(array_column($filteredActivities, 'activity_type'));
sort($activityTypes);

// Get users for filter dropdown
$users = [];
$userQuery = "SELECT user_id, CONCAT(first_name, ' ', last_name) as name, username FROM users ORDER BY name";
$userResult = $conn->query($userQuery);
if ($userResult) {
    while ($row = $userResult->fetch_assoc()) {
        $users[] = $row;
    }
}

// Calculate pagination
$total_pages = ceil($total_records / $limit);
$prev_page = ($page > 1) ? $page - 1 : 1;
$next_page = ($page < $total_pages) ? $page + 1 : $total_pages;
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - UWC Mostar CAS</title>
    <link rel="icon" type="image/x-icon" href="../tab.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.9/flatpickr.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.9/flatpickr.min.js"></script>
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
                    <a href="dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="students" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-user-graduate mr-3 text-lg"></i>
                        Students
                    </a>
                    
                    <a href="cas_activities" class="<?php echo basename($_SERVER['PHP_SELF']) == 'cas_activities.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-alt mr-3 text-lg"></i>
                        CAS Activities
                    </a>
                    
                    <a href="users" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-users mr-3 text-lg"></i>
                        Users/Leaders
                    </a>
                    
                    <a href="attendance_report" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance_report.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-chart-bar mr-3 text-lg"></i>
                        Attendance Reports
                    </a>
                    
                    <a href="manage_absences" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_absences.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-times mr-3 text-lg"></i>
                        Absence Requests
                    </a>
                    
                    <a href="activity_log" class="<?php echo basename($_SERVER['PHP_SELF']) == 'activity_log.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-history mr-3 text-lg"></i>
                        Activity Log
                    </a>
                    
                    <a href="year_transition" class="<?php echo basename($_SERVER['PHP_SELF']) == 'year_transition.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
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
                    <a href="dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="students" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-user-graduate mr-3 text-lg"></i>
                        Students
                    </a>
                    
                    <a href="cas_activities" class="<?php echo basename($_SERVER['PHP_SELF']) == 'cas_activities.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-alt mr-3 text-lg"></i>
                        CAS Activities
                    </a>
                    
                    <a href="users" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-users mr-3 text-lg"></i>
                        Users/Leaders
                    </a>
                    
                    <a href="attendance_report" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance_report.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-chart-bar mr-3 text-lg"></i>
                        Attendance Reports
                    </a>
                    
                    <a href="manage_absences" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_absences.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-times mr-3 text-lg"></i>
                        Absence Requests
                    </a>
                    
                    <a href="activity_log" class="<?php echo basename($_SERVER['PHP_SELF']) == 'activity_log.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-history mr-3 text-lg"></i>
                        Activity Log
                    </a>
                    
                    <a href="year_transition" class="<?php echo basename($_SERVER['PHP_SELF']) == 'year_transition.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
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
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-4 sm:mb-0">Activity Log</h1>
                    <a href="dashboard.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors text-sm sm:text-base">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
                    </a>
                </div>
              
                
                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
                    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-blue-500">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 rounded-full bg-blue-100 text-blue-500 mr-3 sm:mr-4">
                                <i class="fas fa-list text-lg sm:text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs sm:text-sm text-gray-500 uppercase">Total Activities</p>
                                <p class="text-xl sm:text-2xl font-bold"><?php echo $total_records; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-green-500">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 rounded-full bg-green-100 text-green-500 mr-3 sm:mr-4">
                                <i class="fas fa-clock text-lg sm:text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs sm:text-sm text-gray-500 uppercase">Today's Activities</p>
                                <p class="text-xl sm:text-2xl font-bold">
                                    <?php 
                                    $todayCount = 0;
                                    foreach ($activities as $activity) {
                                        if (date('Y-m-d', strtotime($activity['created_at'])) === date('Y-m-d')) {
                                            $todayCount++;
                                        }
                                    }
                                    echo $todayCount;
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-purple-500">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 rounded-full bg-purple-100 text-purple-500 mr-3 sm:mr-4">
                                <i class="fas fa-tags text-lg sm:text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs sm:text-sm text-gray-500 uppercase">Activity Types</p>
                                <p class="text-xl sm:text-2xl font-bold"><?php echo count($activityTypes); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-amber-500">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 rounded-full bg-amber-100 text-amber-500 mr-3 sm:mr-4">
                                <i class="fas fa-calendar-week text-lg sm:text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs sm:text-sm text-gray-500 uppercase">This Week</p>
                                <p class="text-xl sm:text-2xl font-bold">
                                    <?php 
                                    $weekCount = 0;
                                    $weekStart = strtotime('monday this week');
                                    foreach ($activities as $activity) {
                                        if (strtotime($activity['created_at']) >= $weekStart) {
                                            $weekCount++;
                                        }
                                    }
                                    echo $weekCount;
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 mb-6 sm:mb-8">
                    <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">Filter Activities</h2>
                    
                    <form action="activity_log.php" method="GET" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <!-- Activity Type Filter -->
                            <div>
                                <label for="activity_type" class="block text-sm font-medium text-gray-700 mb-1">Activity Type</label>
                                <select name="activity_type" id="activity_type" class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base">
                                    <option value="">All Activity Types</option>
                                    <?php foreach ($activityTypes as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($activity_type === $type) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- User Filter -->
                            <div>
                                <label for="user_id" class="block text-sm font-medium text-gray-700 mb-1">User</label>
                                <select name="user_id" id="user_id" class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base">
                                    <option value="0">All Users</option>
                                    <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['user_id']; ?>" <?php echo ($user_id === (int)$user['user_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['name']); ?> (<?php echo htmlspecialchars($user['username']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Date Range Filter -->
                            <div>
                                <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                                <input type="text" name="date_from" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 datepicker text-sm sm:text-base"
                                       placeholder="Select start date">
                            </div>
                            
                            <div>
                                <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                                <input type="text" name="date_to" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 datepicker text-sm sm:text-base"
                                       placeholder="Select end date">
                            </div>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row sm:justify-between space-y-2 sm:space-y-0">
                            <button type="submit" class="px-4 sm:px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base">
                                <i class="fas fa-filter mr-2"></i> Apply Filters
                            </button>
                            
                            <a href="activity_log.php" class="px-4 sm:px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 text-center text-sm sm:text-base">
                                <i class="fas fa-times mr-2"></i> Clear Filters
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Activity List -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-indigo-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                        <h2 class="text-lg sm:text-xl font-bold">Activity Log</h2>
                        <p class="text-indigo-200 text-xs sm:text-sm mt-1">
                            Showing live data from your database (same as dashboard) - <?php echo $total_records; ?> activities found
                        </p>
                    </div>
                    
                    <?php if (empty($activities)): ?>
                    <div class="p-6 sm:p-8 text-center text-gray-500">
                        <i class="fas fa-search text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                        <p class="text-base sm:text-lg">No activities found matching your criteria.</p>
                        <p class="text-sm mt-2">Try adjusting your filters or clearing them to see more results.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white">
                            <thead>
                                <tr>
                                    <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                    <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">Activity Type</th>
                                    <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                    <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider hidden lg:table-cell">IP Address</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($activities as $activity): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="py-2 sm:py-4 px-2 sm:px-4 text-sm text-gray-500">
                                        <div>
                                            <?php echo date('Y-m-d H:i:s', strtotime($activity['created_at'])); ?>
                                        </div>
                                        <div class="text-xs text-gray-400">
                                            <?php 
                                            $timeAgo = time() - strtotime($activity['created_at']);
                                            if ($timeAgo < 60) {
                                                echo "Just now";
                                            } elseif ($timeAgo < 3600) {
                                                echo floor($timeAgo / 60) . " minutes ago";
                                            } elseif ($timeAgo < 86400) {
                                                echo floor($timeAgo / 3600) . " hours ago";
                                            } else {
                                                echo floor($timeAgo / 86400) . " days ago";
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <td class="py-2 sm:py-4 px-2 sm:px-4 text-sm font-medium text-gray-900">
                                        <?php 
                                        $activityType = htmlspecialchars($activity['activity_type']);
                                        $badgeClass = 'bg-gray-100 text-gray-800';
                                        
                                        // Color code activity types
                                        if (strpos($activityType, 'Added') !== false || strpos($activityType, 'Created') !== false) {
                                            $badgeClass = 'bg-green-100 text-green-800';
                                        } elseif (strpos($activityType, 'Updated') !== false || strpos($activityType, 'Assigned') !== false) {
                                            $badgeClass = 'bg-blue-100 text-blue-800';
                                        } elseif (strpos($activityType, 'Deleted') !== false || strpos($activityType, 'Declined') !== false) {
                                            $badgeClass = 'bg-red-100 text-red-800';
                                        } elseif (strpos($activityType, 'Approved') !== false) {
                                            $badgeClass = 'bg-green-100 text-green-800';
                                        } elseif (strpos($activityType, 'Recorded') !== false) {
                                            $badgeClass = 'bg-purple-100 text-purple-800';
                                        }
                                        ?>
                                        <span class="inline-flex items-center px-2 sm:px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $badgeClass; ?>">
                                            <?php echo $activityType; ?>
                                        </span>
                                    </td>
                                    <td class="py-2 sm:py-4 px-2 sm:px-4 text-sm text-gray-500">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-6 w-6 sm:h-8 sm:w-8">
                                                <div class="h-6 w-6 sm:h-8 sm:w-8 rounded-full bg-gray-300 flex items-center justify-center">
                                                    <i class="fas fa-user text-gray-600 text-xs"></i>
                                                </div>
                                            </div>
                                            <div class="ml-2 sm:ml-3">
                                                <div class="text-xs sm:text-sm font-medium text-gray-900">
                                                    <?php echo $activity['full_name'] ? htmlspecialchars($activity['full_name']) : 'System'; ?>
                                                </div>
                                                <?php if (isset($activity['username']) && $activity['username']): ?>
                                                <div class="text-xs text-gray-500 hidden sm:block">
                                                    @<?php echo htmlspecialchars($activity['username']); ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-2 sm:py-4 px-2 sm:px-4 text-sm text-gray-500">
                                        <div class="max-w-xs lg:max-w-md">
                                            <p class="text-xs sm:text-sm truncate sm:whitespace-normal">
                                                <?php echo htmlspecialchars($activity['activity_details']); ?>
                                            </p>
                                        </div>
                                    </td>
                                    <td class="py-2 sm:py-4 px-2 sm:px-4 text-sm text-gray-500 hidden lg:table-cell">
                                        <span class="font-mono text-xs bg-gray-100 px-2 py-1 rounded">
                                            <?php echo htmlspecialchars($activity['ip_address'] ?? '127.0.0.1'); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="bg-gray-50 px-4 sm:px-6 py-3 sm:py-4 border-t border-gray-200">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-3 sm:space-y-0">
                            <div class="text-xs sm:text-sm text-gray-700 text-center sm:text-left">
                                Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to 
                                <span class="font-medium"><?php echo min($offset + $limit, $total_records); ?></span> of 
                                <span class="font-medium"><?php echo $total_records; ?></span> results
                            </div>
                            
                            <div class="flex justify-center sm:justify-end space-x-1 sm:space-x-2">
                                <a href="?page=1<?php echo (!empty($activity_type)) ? '&activity_type=' . urlencode($activity_type) : ''; ?><?php echo ($user_id > 0) ? '&user_id=' . $user_id : ''; ?><?php echo (!empty($date_from)) ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo (!empty($date_to)) ? '&date_to=' . urlencode($date_to) : ''; ?>" 
                                   class="px-2 sm:px-3 py-1 rounded-md text-xs sm:text-sm <?php echo ($page === 1) ? 'bg-gray-200 text-gray-500 cursor-not-allowed' : 'bg-white text-blue-600 hover:bg-blue-50 border border-gray-300'; ?>">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                
                                <a href="?page=<?php echo $prev_page; ?><?php echo (!empty($activity_type)) ? '&activity_type=' . urlencode($activity_type) : ''; ?><?php echo ($user_id > 0) ? '&user_id=' . $user_id : ''; ?><?php echo (!empty($date_from)) ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo (!empty($date_to)) ? '&date_to=' . urlencode($date_to) : ''; ?>" 
                                   class="px-2 sm:px-3 py-1 rounded-md text-xs sm:text-sm <?php echo ($page === 1) ? 'bg-gray-200 text-gray-500 cursor-not-allowed' : 'bg-white text-blue-600 hover:bg-blue-50 border border-gray-300'; ?>">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                                
                                <?php
                                $range = 2;
                                $start_page = max(1, $page - $range);
                                $end_page = min($total_pages, $page + $range);
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                <a href="?page=<?php echo $i; ?><?php echo (!empty($activity_type)) ? '&activity_type=' . urlencode($activity_type) : ''; ?><?php echo ($user_id > 0) ? '&user_id=' . $user_id : ''; ?><?php echo (!empty($date_from)) ?'&date_from=' . urlencode($date_from) : ''; ?><?php echo (!empty($date_to)) ? '&date_to=' . urlencode($date_to) : ''; ?>" 
                                   class="px-2 sm:px-3 py-1 rounded-md text-xs sm:text-sm <?php echo ($i === $page) ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 hover:bg-blue-50 border border-gray-300'; ?>">
                                    <?php echo $i; ?>
                                </a>
                                <?php endfor; ?>
                                
                                <a href="?page=<?php echo $next_page; ?><?php echo (!empty($activity_type)) ? '&activity_type=' . urlencode($activity_type) : ''; ?><?php echo ($user_id > 0) ? '&user_id=' . $user_id : ''; ?><?php echo (!empty($date_from)) ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo (!empty($date_to)) ? '&date_to=' . urlencode($date_to) : ''; ?>" 
                                   class="px-2 sm:px-3 py-1 rounded-md text-xs sm:text-sm <?php echo ($page === $total_pages) ? 'bg-gray-200 text-gray-500 cursor-not-allowed' : 'bg-white text-blue-600 hover:bg-blue-50 border border-gray-300'; ?>">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                
                                <a href="?page=<?php echo $total_pages; ?><?php echo (!empty($activity_type)) ? '&activity_type=' . urlencode($activity_type) : ''; ?><?php echo ($user_id > 0) ? '&user_id=' . $user_id : ''; ?><?php echo (!empty($date_from)) ? '&date_from=' . urlencode($date_from) : ''; ?><?php echo (!empty($date_to)) ? '&date_to=' . urlencode($date_to) : ''; ?>" 
                                   class="px-2 sm:px-3 py-1 rounded-md text-xs sm:text-sm <?php echo ($page === $total_pages) ? 'bg-gray-200 text-gray-500 cursor-not-allowed' : 'bg-white text-blue-600 hover:bg-blue-50 border border-gray-300'; ?>">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Export Options -->
                <div class="mt-6 sm:mt-8 bg-white rounded-lg shadow-md p-4 sm:p-6">
                    <h3 class="text-base sm:text-lg font-bold text-gray-800 mb-4">Export Options</h3>
                    <div class="flex flex-col sm:flex-row flex-wrap gap-2 sm:gap-4">
                        <button onclick="exportToCSV()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm sm:text-base">
                            <i class="fas fa-file-csv mr-2"></i> Export to CSV
                        </button>
                        <button onclick="printLog()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm sm:text-base">
                            <i class="fas fa-print mr-2"></i> Print Log
                        </button>
                        <button onclick="refreshActivities()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors text-sm sm:text-base">
                            <i class="fas fa-sync mr-2"></i> Refresh Activities
                        </button>
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

            // Initialize date pickers
            flatpickr(".datepicker", {
                dateFormat: "Y-m-d",
                allowInput: true,
                altInput: true,
                altFormat: "F j, Y",
                maxDate: "today"
            });
            
            // Auto-refresh every 30 seconds
            setInterval(function() {
                // Only refresh if no filters are applied and on first page
                const urlParams = new URLSearchParams(window.location.search);
                if (!urlParams.has('activity_type') && !urlParams.has('user_id') && 
                    !urlParams.has('date_from') && !urlParams.has('date_to') && 
                    (!urlParams.has('page') || urlParams.get('page') === '1')) {
                    
                    // Only refresh if no user interaction in last 30 seconds
                    if (typeof lastUserActivity === 'undefined' || 
                        (Date.now() - lastUserActivity) > 30000) {
                        refreshActivities();
                    }
                }
            }, 30000);
            
            // Track user activity
            let lastUserActivity = Date.now();
            document.addEventListener('mousemove', () => lastUserActivity = Date.now());
            document.addEventListener('keypress', () => lastUserActivity = Date.now());
            document.addEventListener('click', () => lastUserActivity = Date.now());
        });
        
        function refreshActivities() {
            // Show refresh indicator
            const indicator = document.createElement('div');
            indicator.className = 'fixed top-4 right-4 bg-blue-500 text-white px-3 py-1 rounded text-sm z-50';
            indicator.textContent = 'Refreshing activities...';
            document.body.appendChild(indicator);
            
            // Remove indicator and refresh after 2 seconds
            setTimeout(() => {
                if (document.body.contains(indicator)) {
                    document.body.removeChild(indicator);
                }
                location.reload();
            }, 2000);
        }
        
        // Export functions
        function exportToCSV() {
            const table = document.querySelector('table');
            if (!table) {
                alert('No data to export');
                return;
            }
            
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = [];
                const cols = rows[i].querySelectorAll('td, th');
                
                for (let j = 0; j < cols.length; j++) {
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/(\s\s)/gm, ' ');
                    data = data.replace(/"/g, '""');
                    row.push('"' + data + '"');
                }
                csv.push(row.join(','));
            }
            
            const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
            const downloadLink = document.createElement('a');
            downloadLink.download = 'uwc_mostar_activity_log_' + new Date().toISOString().slice(0, 10) + '.csv';
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = 'none';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }
        
        function printLog() {
            const printContent = document.querySelector('.bg-white.rounded-lg.shadow-md.overflow-hidden').cloneNode(true);
            const printWindow = window.open('', '', 'height=600,width=800');
            printWindow.document.write('<html><head><title>UWC Mostar CAS - Activity Log</title>');
            printWindow.document.write('<link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">');
            printWindow.document.write('<style>body { font-family: Arial, sans-serif; }</style>');
            printWindow.document.write('</head><body>');
            printWindow.document.write('<h1 class="text-2xl font-bold mb-4">UWC Mostar CAS - Activity Log</h1>');
            printWindow.document.write('<p class="mb-4">Generated on: ' + new Date().toLocaleDateString() + ' at ' + new Date().toLocaleTimeString() + '</p>');
            printWindow.document.write('<p class="mb-4 text-sm">Total Activities: <?php echo $total_records; ?></p>');
            printWindow.document.write(printContent.outerHTML);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            printWindow.print();
        }
        
        // Activity type color coding
        function updateActivityTypeColors() {
            const activityBadges = document.querySelectorAll('.inline-flex.items-center.px-2\\.5.py-0\\.5.rounded-full');
            activityBadges.forEach(badge => {
                const text = badge.textContent.toLowerCase();
                badge.classList.remove('bg-gray-100', 'text-gray-800');
                
                if (text.includes('added') || text.includes('created')) {
                    badge.classList.add('bg-green-100', 'text-green-800');
                } else if (text.includes('updated') || text.includes('assigned')) {
                    badge.classList.add('bg-blue-100', 'text-blue-800');
                } else if (text.includes('deleted') || text.includes('declined')) {
                    badge.classList.add('bg-red-100', 'text-red-800');
                } else if (text.includes('approved')) {
                    badge.classList.add('bg-green-100', 'text-green-800');
                } else if (text.includes('recorded')) {
                    badge.classList.add('bg-purple-100', 'text-purple-800');
                } else {
                    badge.classList.add('bg-gray-100', 'text-gray-800');
                }
            });
        }
        
        // Initialize color coding on page load
        updateActivityTypeColors();
    </script>
</body>
</html>