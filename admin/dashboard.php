<?php
// Start session for user authentication
session_start();// Check if user is logged in and is an admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_status'] !== 'admin') {
    // Not logged in or not an admin, redirect to login page
    header("Location: ../login.php");
    exit();
}// Database connection
require_once '../includes/db_connect.php';// Message handling
$message = "";
$message_type = "";// Count total students
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM students WHERE is_active = 1");
$stmt->execute();
$result = $stmt->get_result();
$studentCount = $result->fetch_assoc()['total'];// Count total CAS activities
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM cas_activities WHERE is_active = 1");
$stmt->execute();
$result = $stmt->get_result();
$casCount = $result->fetch_assoc()['total'];// Count CAS leaders
$stmt = $conn->prepare("SELECT COUNT(DISTINCT cl.user_id) as total FROM cas_leaders cl JOIN users u ON cl.user_id = u.user_id WHERE u.user_status = 'cas_leader'");
$stmt->execute();
$result = $stmt->get_result();
$leaderCount = $result->fetch_assoc()['total'];// Count attendance sessions in the last 30 days
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM attendance_sessions WHERE session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$stmt->execute();
$result = $stmt->get_result();
$sessionCount = $result->fetch_assoc()['total'];// Count pending absence requests
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM absence_requests WHERE status = 'pending'");
$stmt->execute();
$result = $stmt->get_result();
$pendingRequestsCount = $result->fetch_assoc()['total'];// Get recent absence requests
$stmt = $conn->prepare("
    SELECT 
        ar.request_id,
        ar.absence_date,
        ar.cas_name,
        ar.status,
        ar.created_at,
        s.first_name,
        s.last_name
    FROM 
        absence_requests ar
    JOIN 
        students s ON ar.student_id = s.student_id
    ORDER BY 
        ar.created_at DESC
    LIMIT 5
");
$stmt->execute();
$result = $stmt->get_result();
$recentRequests = [];
while ($row = $result->fetch_assoc()) {
    $recentRequests[] = $row;
}// Gather actual recent activities from various tables

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

// Gather actual recent activities from various tables
$activities = [];

// Function to add activity with proper formatting
function addActivity(&$activities, $date, $type, $username, $full_name, $details) {
    $activities[] = [
        'date' => $date,
        'activity' => $type,
        'user' => $full_name, // Use full name for display
        'details' => $details
    ];
}

// 1. Get recent student additions with creator info
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
    LIMIT 2
";
$stmtStudents = $conn->prepare($studentQuery);
$stmtStudents->execute();
$resultStudents = $stmtStudents->get_result();
while ($student = $resultStudents->fetch_assoc()) {
    $creatorUsername = $student['username'] ?? $currentUser['username'];
    $creatorName = $student['creator_name'] ?? $currentUser['full_name'];
    
    addActivity(
        $activities, 
        $student['created_at'], 
        'Added new student', 
        $creatorUsername,
        $creatorName,
        'Added ' . $student['student_name'] . ' to system'
    );
}
$stmtStudents->close();

// 2. Get recent CAS activity additions/updates with creator info
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
    LIMIT 2
";
$stmtCAS = $conn->prepare($casQuery);
$stmtCAS->execute();
$resultCAS = $stmtCAS->get_result();
while ($cas = $resultCAS->fetch_assoc()) {
    $creatorUsername = $cas['username'] ?? $currentUser['username'];
    $creatorName = $cas['creator_name'] ?? $currentUser['full_name'];
    
    addActivity(
        $activities, 
        $cas['created_at'], 
        'Created CAS activity', 
        $creatorUsername,
        $creatorName,
        'Created new "' . $cas['cas_name'] . '" activity'
    );
}
$stmtCAS->close();

// 3. Get recent CAS leader assignments with assigner info
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
    LIMIT 2
";
$stmtLeaders = $conn->prepare($leaderQuery);
$stmtLeaders->execute();
$resultLeaders = $stmtLeaders->get_result();
while ($leader = $resultLeaders->fetch_assoc()) {
    $assignerUsername = $leader['assigner_username'] ?? $currentUser['username'];
    $assignerName = $leader['assigner_name'] ?? $currentUser['full_name'];
    
    addActivity(
        $activities, 
        $leader['created_at'], 
        'Assigned CAS Leader', 
        $assignerUsername,
        $assignerName,
        'Assigned ' . $leader['leader_name'] . ' as leader for "' . $leader['cas_name'] . '"'
    );
}
$stmtLeaders->close();

// 4. Get recent attendance sessions with recorder info
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
    LIMIT 2
";
$stmtSessions = $conn->prepare($sessionQuery);
$stmtSessions->execute();
$resultSessions = $stmtSessions->get_result();
while ($session = $resultSessions->fetch_assoc()) {
    $recorderUsername = $session['recorder_username'] ?? $currentUser['username'];
    $recorderName = $session['recorder_name'] ?? $currentUser['full_name'];
    
    addActivity(
        $activities, 
        $session['created_at'], 
        'Recorded Attendance', 
        $recorderUsername,
        $recorderName,
        'Recorded attendance for "' . $session['cas_name'] . '" on ' . date('M j, Y', strtotime($session['session_date'])) . 
        ' (' . $session['student_count'] . ' students)'
    );
}
$stmtSessions->close();

// 5. Get recent absence request resolutions with handler info
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
    LIMIT 2
";
$stmtAbsences = $conn->prepare($absenceQuery);
$stmtAbsences->execute();
$resultAbsences = $stmtAbsences->get_result();
while ($absence = $resultAbsences->fetch_assoc()) {
    $handlerUsername = $absence['handler_username'] ?? $currentUser['username'];
    $handlerName = $absence['handler_name'] ?? $currentUser['full_name'];
    
    $action = $absence['status'] === 'approved' ? 'Approved' : 'Declined';
    addActivity(
        $activities, 
        $absence['created_at'], 
        $action . ' absence request', 
        $handlerUsername,
        $handlerName,
        $action . ' absence request for ' . $absence['student_name'] . ' from "' . $absence['cas_name'] . '"'
    );
}
$stmtAbsences->close();

// Sort activities by date (newest first)
usort($activities, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// Keep only the 5 most recent activities
$activities = array_slice($activities, 0, 5);

// If no real activities found, create some placeholder ones based on system data
if (empty($activities)) {
    $activities = [
        [
            'date' => date('Y-m-d H:i:s'),
            'activity' => 'System Initialization',
            'user' => $currentUser['full_name'],
            'details' => 'Welcome to UWC Mostar CAS System'
        ]
    ];
}

$stmt->close();
?>
<!DOCTYPE html><html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - UWC Mostar CAS</title>
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
                <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
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
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6 sm:mb-8">Admin Dashboard</h1>
            
            <!-- Stats Overview -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
                <!-- Students Count -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-blue-500">
                    <div class="flex items-center">
                        <div class="p-2 sm:p-3 rounded-full bg-blue-100 text-blue-500 mr-3 sm:mr-4">
                            <i class="fas fa-user-graduate text-lg sm:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-xs sm:text-sm text-gray-500 uppercase">Total Students</p>
                            <p class="text-xl sm:text-2xl font-bold"><?php echo $studentCount; ?></p>
                        </div>
                    </div>
                    <div class="mt-3 sm:mt-4">
                        <a href="students.php" class="text-blue-500 hover:text-blue-700 text-xs sm:text-sm font-medium">View all students <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                </div>
                
                <!-- CAS Activities Count -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-green-500">
                    <div class="flex items-center">
                        <div class="p-2 sm:p-3 rounded-full bg-green-100 text-green-500 mr-3 sm:mr-4">
                            <i class="fas fa-calendar-alt text-lg sm:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-xs sm:text-sm text-gray-500 uppercase">CAS Activities</p>
                            <p class="text-xl sm:text-2xl font-bold"><?php echo $casCount; ?></p>
                        </div>
                    </div>
                    <div class="mt-3 sm:mt-4">
                        <a href="cas_activities.php" class="text-green-500 hover:text-green-700 text-xs sm:text-sm font-medium">Manage activities <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                </div>
                
                <!-- CAS Leaders Count -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-purple-500">
                    <div class="flex items-center">
                        <div class="p-2 sm:p-3 rounded-full bg-purple-100 text-purple-500 mr-3 sm:mr-4">
                            <i class="fas fa-user-tie text-lg sm:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-xs sm:text-sm text-gray-500 uppercase">CAS Leaders</p>
                            <p class="text-xl sm:text-2xl font-bold"><?php echo $leaderCount; ?></p>
                        </div>
                    </div>
                    <div class="mt-3 sm:mt-4">
                        <a href="users.php" class="text-purple-500 hover:text-purple-700 text-xs sm:text-sm font-medium">Manage users <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                </div>
                
                <!-- Attendance Sessions -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-amber-500">
                    <div class="flex items-center">
                        <div class="p-2 sm:p-3 rounded-full bg-amber-100 text-amber-500 mr-3 sm:mr-4">
                            <i class="fas fa-clipboard-check text-lg sm:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-xs sm:text-sm text-gray-500 uppercase">Recent Sessions</p>
                            <p class="text-xl sm:text-2xl font-bold"><?php echo $sessionCount; ?></p>
                            <p class="text-xs text-gray-500">Last 30 days</p>
                        </div>
                    </div>
                    <div class="mt-3 sm:mt-4">
                        <a href="attendance_report.php" class="text-amber-500 hover:text-amber-700 text-xs sm:text-sm font-medium">View reports <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                </div>
            </div>
            
            <!-- Absence Requests Alert -->
            <?php if ($pendingRequestsCount > 0): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6 sm:mb-8 rounded-md shadow">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-base sm:text-lg font-medium text-yellow-800">Attention Required</h3>
                        <div class="mt-2 text-yellow-700">
                            <p class="text-sm sm:text-base">You have <?php echo $pendingRequestsCount; ?> pending CAS absence <?php echo $pendingRequestsCount === 1 ? 'request' : 'requests'; ?> that require your review.</p>
                            <a href="manage_absences.php" class="inline-block mt-2 px-3 sm:px-4 py-2 bg-yellow-500 text-white rounded hover:bg-yellow-600 text-sm sm:text-base">
                                View Requests <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Quick Access Buttons -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
                <a href="students.php?action=add" class="bg-white hover:bg-blue-50 transition-colors duration-200 rounded-lg shadow-md p-4 sm:p-6 text-center">
                    <div class="w-12 h-12 sm:w-16 sm:h-16 bg-blue-100 text-blue-500 rounded-full flex items-center justify-center mx-auto mb-3 sm:mb-4">
                        <i class="fas fa-user-plus text-lg sm:text-2xl"></i>
                    </div>
                    <h3 class="text-base sm:text-lg font-semibold text-gray-800">Add New Student</h3>
                    <p class="text-gray-600 mt-2 text-sm sm:text-base">Register a new student in the system</p>
                </a>
                
                <a href="cas_activities.php?action=add" class="bg-white hover:bg-green-50 transition-colors duration-200 rounded-lg shadow-md p-4 sm:p-6 text-center">
                    <div class="w-12 h-12 sm:w-16 sm:h-16 bg-green-100 text-green-500 rounded-full flex items-center justify-center mx-auto mb-3 sm:mb-4">
                        <i class="fas fa-plus text-lg sm:text-2xl"></i>
                    </div>
                    <h3 class="text-base sm:text-lg font-semibold text-gray-800">Create CAS Activity</h3>
                    <p class="text-gray-600 mt-2 text-sm sm:text-base">Add a new CAS activity to the system</p>
                </a>
                
                <a href="users.php?action=create" class="bg-white hover:bg-purple-50 transition-colors duration-200 rounded-lg shadow-md p-4 sm:p-6 text-center">
                    <div class="w-12 h-12 sm:w-16 sm:h-16 bg-purple-100 text-purple-500 rounded-full flex items-center justify-center mx-auto mb-3 sm:mb-4">
                        <i class="fas fa-user-shield text-lg sm:text-2xl"></i>
                    </div>
                    <h3 class="text-base sm:text-lg font-semibold text-gray-800">Add CAS Leader</h3>
                    <p class="text-gray-600 mt-2 text-sm sm:text-base">Create a new CAS leader account</p>
                </a>
                
                <a href="manage_absences.php" class="bg-white hover:bg-yellow-50 transition-colors duration-200 rounded-lg shadow-md p-4 sm:p-6 text-center">
                    <div class="w-12 h-12 sm:w-16 sm:h-16 bg-yellow-100 text-yellow-500 rounded-full flex items-center justify-center mx-auto mb-3 sm:mb-4">
                        <i class="fas fa-calendar-times text-lg sm:text-2xl"></i>
                    </div>
                    <h3 class="text-base sm:text-lg font-semibold text-gray-800">Absence Requests</h3>
                    <p class="text-gray-600 mt-2 text-sm sm:text-base">Manage student absence requests and excuses</p>
                </a>
            </div>
            
            <!-- Dashboard Sections -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-6 sm:mb-8">
                <!-- Recent Absence Requests -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-yellow-600 text-white px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center">
                        <h2 class="text-lg sm:text-xl font-bold">Recent Absence Requests</h2>
                        <a href="manage_absences.php" class="text-xs bg-yellow-700 hover:bg-yellow-800 text-white py-1 px-2 sm:px-3 rounded">
                            View All
                        </a>
                    </div>
                    
                    <?php if (empty($recentRequests)): ?>
                    <div class="p-4 sm:p-6 text-center text-gray-500">
                        <p class="text-sm sm:text-base">No recent absence requests found.</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($recentRequests as $request): ?>
                        <div class="p-3 sm:p-4 hover:bg-gray-50">
                            <div class="flex justify-between">
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-medium text-gray-900 text-sm sm:text-base">
                                        <?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?>
                                    </h3>
                                    <p class="text-xs sm:text-sm text-gray-600 truncate">
                                        <?php echo htmlspecialchars($request['cas_name']); ?> - 
                                        <?php echo date('F j, Y', strtotime($request['absence_date'])); ?>
                                    </p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        Submitted: <?php echo date('M j, g:i A', strtotime($request['created_at'])); ?>
                                    </p>
                                </div>
                                <div class="flex-shrink-0 ml-2">
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
                                    <span class="inline-flex items-center px-2 sm:px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                                        <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                        <?php echo ucfirst($request['status']); ?>
                                    </span>
                                    
                                    <?php if ($request['status'] === 'pending'): ?>
                                    <a href="manage_absences.php?review=<?php echo $request['request_id']; ?>" class="block text-xs text-center mt-2 text-blue-600 hover:text-blue-800">
                                        Review
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="bg-gray-50 px-4 sm:px-6 py-3 text-center">
                        <a href="manage_absences.php" class="text-purple-600 hover:text-purple-800 text-xs sm:text-sm font-medium">
                            Manage All Absence Requests <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Search -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
                    <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">Quick Search</h2>
                    
                    <form action="search.php" method="GET" class="mb-4">
                        <div class="flex flex-col space-y-2">
                            <div>
                                <input type="text" name="query" placeholder="Search for students, CAS activities, or leaders..." 
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base">
                            </div>
                            <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2">
                                <div class="flex-1">
                                    <select name="type" class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base">
                                        <option value="all">All</option>
                                        <option value="student">Students</option>
                                        <option value="cas">CAS Activities</option>
                                        <option value="user">Users/Leaders</option>
                                    </select>
                                </div>
                                <div>
                                    <button type="submit" class="w-full sm:w-auto px-4 sm:px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base">
                                        <i class="fas fa-search mr-2"></i> Search
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                    
                    <div class="border-t border-gray-200 pt-4">
                        <h3 class="font-medium text-gray-700 mb-2 text-sm sm:text-base">Quick Links</h3>
                        <div class="grid grid-cols-2 gap-2">
                            <a href="attendance_report.php" class="text-purple-600 hover:text-purple-800 text-xs sm:text-sm">
                                <i class="fas fa-chart-bar mr-1"></i> Attendance Reports
                            </a>
                            <a href="cas_activities.php" class="text-purple-600 hover:text-purple-800 text-xs sm:text-sm">
                                <i class="fas fa-list-alt mr-1"></i> CAS Activities
                            </a>
                            <a href="students.php?search=&year=Y1&active=all" class="text-purple-600 hover:text-purple-800 text-xs sm:text-sm">
                                <i class="fas fa-user-graduate mr-1"></i> Y1 Students
                            </a>
                            <a href="students.php?search=&year=Y2&active=all" class="text-purple-600 hover:text-purple-800 text-xs sm:text-sm">
                                <i class="fas fa-user-graduate mr-1"></i> Y2 Students
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activities -->
            <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg sm:text-xl font-bold text-gray-800">Recent Activities</h2>
                    <a href="activity_log.php" class="text-blue-500 hover:text-blue-700 text-xs sm:text-sm font-medium">View all <i class="fas fa-arrow-right ml-1"></i></a>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white">
                        <thead>
                            <tr>
                                <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">Activity</th>
                                <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (empty($activities)): ?>
                            <tr>
                                <td colspan="4" class="py-3 sm:py-4 px-2 sm:px-4 text-center text-gray-500 text-sm">No recent activities found.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($activities as $activity): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="py-2 sm:py-4 px-2 sm:px-4 text-xs sm:text-sm text-gray-500"><?php echo date('Y-m-d H:i:s', strtotime($activity['date'])); ?></td>
                                    <td class="py-2 sm:py-4 px-2 sm:px-4 text-xs sm:text-sm font-medium text-gray-900"><?php echo htmlspecialchars($activity['activity']); ?></td>
                                    <td class="py-2 sm:py-4 px-2 sm:px-4 text-xs sm:text-sm text-gray-500"><?php echo htmlspecialchars($activity['user']); ?></td>
                                    <td class="py-2 sm:py-4 px-2 sm:px-4 text-xs sm:text-sm text-gray-500"><?php echo htmlspecialchars($activity['details']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
    });
</script>

</body>
</html>