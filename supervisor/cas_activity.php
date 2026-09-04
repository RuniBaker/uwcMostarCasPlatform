<?php
// Start session for user authentication
session_start();

// Check if user is logged in and is a supervisor
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_status'] !== 'supervisor') {
    header("Location: ../login.php");
    exit();
}

// Database connection
require_once '../includes/db_connect.php';

// Message handling
$message = "";
$message_type = "";

// Get CAS leader's user ID
$user_id = $_SESSION['user_id'];

// Get CAS activity ID from URL
$cas_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no CAS ID provided, redirect to dashboard
if ($cas_id === 0) {
    header("Location: dashboard.php");
    exit();
}

// Check if CAS leader is authorized for this CAS activity
$stmt = $conn->prepare("
    SELECT 
        ca.cas_id,
        ca.cas_name,
        ca.cas_type,
        ca.cas_description,
        ca.cas_day,
        ca.cas_time,
        ca.cas_location,
        ca.is_active,
        ca.created_at
    FROM 
        cas_activities ca
    JOIN 
        cas_supervisors cl ON ca.cas_id = cl.cas_id
    WHERE 
        ca.cas_id = ? AND cl.user_id = ? AND ca.is_active = 1
    LIMIT 1
");
$stmt->bind_param("ii", $cas_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // CAS activity not found or not authorized, redirect to dashboard
    header("Location: dashboard.php");
    exit();
}

$cas = $result->fetch_assoc();
$stmt->close();

// Get other CAS leaders for this activity
$leaders = [];
$stmt = $conn->prepare("
    SELECT 
        u.user_id,
        u.first_name,
        u.last_name,
        u.email
    FROM 
        cas_supervisors cl
    JOIN 
        users u ON cl.user_id = u.user_id
    WHERE 
        cl.cas_id = ? AND u.user_id != ?
    ORDER BY 
        u.last_name, u.first_name
");
$stmt->bind_param("ii", $cas_id, $user_id);
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
        s.student_id,
        s.first_name,
        s.last_name,
        s.email,
        s.grade_year,
        sce.enrollment_date,
        (
            SELECT COUNT(*) 
            FROM attendance_sessions ats
            JOIN attendance_records ar ON ats.session_id = ar.session_id
            WHERE ats.cas_id = ? AND ar.student_id = s.student_id AND ar.status = 'present'
        ) as present_count,
        (
            SELECT COUNT(*) 
            FROM attendance_sessions ats
            JOIN attendance_records ar ON ats.session_id = ar.session_id
            WHERE ats.cas_id = ? AND ar.student_id = s.student_id AND ar.status = 'absent'
        ) as absent_count,
        (
            SELECT COUNT(*) 
            FROM attendance_sessions ats
            JOIN attendance_records ar ON ats.session_id = ar.session_id
            WHERE ats.cas_id = ? AND ar.student_id = s.student_id AND ar.status = 'excused'
        ) as excused_count
    FROM 
        students s
    JOIN 
        student_cas_enrollment sce ON s.student_id = sce.student_id
    WHERE 
        sce.cas_id = ? AND sce.is_active = 1 AND s.is_active = 1
    ORDER BY 
        s.grade_year, s.last_name, s.first_name
");
$stmt->bind_param("iiii", $cas_id, $cas_id, $cas_id, $cas_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}
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

// Get attendance summary
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT ats.session_id) as total_sessions,
        SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END) as excused_count,
        COUNT(ar.record_id) as total_records
    FROM 
        attendance_sessions ats
    LEFT JOIN 
        attendance_records ar ON ats.session_id = ar.session_id
    WHERE 
        ats.cas_id = ?
");
$stmt->bind_param("i", $cas_id);
$stmt->execute();
$attendance_summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Calculate attendance rate
$attendance_rate = 0;
if ($attendance_summary['total_records'] > 0) {
    $attendance_rate = round(($attendance_summary['present_count'] / $attendance_summary['total_records']) * 100, 1);
}

// Get next session date
$next_session = null;
$today = strtolower(date('l')); // Current day of the week
$days_order = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
$today_index = array_search($today, $days_order);

// Calculate days until next session
$cas_day_index = array_search($cas['cas_day'], $days_order);
$days_until_next = ($cas_day_index - $today_index + 7) % 7;
if ($days_until_next === 0) {
    // If today is the session day, check if the session time has passed
    $current_time = date('H:i:s');
    $session_time = $cas['cas_time'];
    
    if ($current_time > $session_time) {
        // Session time has passed, next session is in 7 days
        $days_until_next = 7;
    }
}

// Calculate the next session date
$next_session_date = date('Y-m-d', strtotime("+$days_until_next days"));
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($cas['cas_name']); ?> - UWC Mostar CAS</title>
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
                    <!-- Supervisor Badge -->
                    <span class="hidden sm:inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                        Supervisor
                    </span>
                    
                    <!-- User dropdown -->
                    <div class="relative">
                        <button class="flex items-center text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500" id="user-menu-button">
                            <span class="sr-only">Open user menu</span>
                            <div class="h-8 w-8 rounded-full bg-purple-600 flex items-center justify-center text-white">
                                <i class="fas fa-user"></i>
                            </div>
                            <span class="hidden md:ml-2 md:block text-gray-700 font-medium"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Supervisor'); ?></span>
                            <svg class="hidden md:block ml-1 h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                        
                        <!-- User dropdown menu -->
                        <div class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 ring-1 ring-black ring-opacity-5 z-50" id="user-menu-dropdown">
                            <div class="px-4 py-2 text-sm text-gray-700 border-b border-gray-100">
                                <p class="font-medium"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Supervisor'); ?></p>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($_SESSION['email'] ?? 'casleader@uwcmostar.ba'); ?></p>
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
                            <h1 class="text-lg font-bold text-gray-900">Supervisor Panel</h1>
                        </div>
                    </div>
                </div>
                
                <nav class="px-4 space-y-1">
                    <a href="/supervisor/dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-purple-50 border-r-4 border-purple-600 text-purple-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="/supervisor/record_attendance?id=<?php echo $cas_id; ?>" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-clipboard-check mr-3 text-lg"></i>
                        Record Attendance
                    </a>
                    
                    <div class="pt-4 pb-2">
                        <div class="px-2">
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                Current CAS Activity
                            </h3>
                        </div>
                    </div>
                    
                    <div class="bg-purple-50 rounded-lg p-3 border border-purple-200">
                        <div class="text-sm font-medium text-purple-800"><?php echo htmlspecialchars($cas['cas_name']); ?></div>
                        <div class="text-xs text-purple-600"><?php echo ucfirst($cas['cas_type']); ?> • <?php echo ucfirst($cas['cas_day']); ?></div>
                    </div>
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
                    <a href="/supervisor/dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-purple-50 border-r-4 border-purple-600 text-purple-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="/supervisor/record_attendance?id=<?php echo $cas_id; ?>" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-clipboard-check mr-3 text-lg"></i>
                        Record Attendance
                    </a>
                    
                    <div class="pt-4 pb-2">
                        <div class="px-2">
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                Current CAS Activity
                            </h3>
                        </div>
                    </div>
                    
                    <div class="bg-purple-50 rounded-lg p-3 border border-purple-200">
                        <div class="text-sm font-medium text-purple-800"><?php echo htmlspecialchars($cas['cas_name']); ?></div>
                        <div class="text-xs text-purple-600"><?php echo ucfirst($cas['cas_type']); ?> • <?php echo ucfirst($cas['cas_day']); ?></div>
                    </div>
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
                    <div class="flex items-center">
                        <a href="/supervisor/dashboard" class="mr-4 text-gray-600 hover:text-gray-800 text-sm sm:text-base">
                            <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
                        </a>
                        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">CAS Activity</h1>
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
                
                <!-- CAS Activity Profile -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 mb-6 sm:mb-8">
                    <div class="flex flex-col lg:flex-row">
                        <div class="lg:w-1/4 flex justify-center lg:justify-start mb-6 lg:mb-0">
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
                            <div class="h-24 w-24 sm:h-32 sm:w-32 <?php echo $bg_class; ?> rounded-full flex items-center justify-center text-3xl sm:text-4xl <?php echo $text_class; ?>">
                                <i class="fas <?php echo $icon_class; ?>"></i>
                            </div>
                        </div>
                        
                        <div class="lg:w-3/4">
                            <div class="flex flex-col sm:flex-row sm:items-center mb-4">
                                <h2 class="text-xl sm:text-2xl font-bold text-gray-800 mb-2 sm:mb-0"><?php echo htmlspecialchars($cas['cas_name']); ?></h2>
                                
                                <span class="sm:ml-4 px-3 py-1 rounded-full text-sm font-semibold <?php echo $bg_class . ' ' . $text_class; ?> w-fit">
                                    <?php echo ucfirst($cas['cas_type']); ?>
                                </span>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm sm:text-base">
                                <div class="space-y-2">
                                    <p class="text-gray-600"><i class="fas fa-calendar-day mr-2 text-purple-600"></i> <?php echo ucfirst($cas['cas_day']); ?></p>
                                    <p class="text-gray-600"><i class="fas fa-clock mr-2 text-purple-600"></i> <?php echo date('g:i A', strtotime($cas['cas_time'])); ?></p>
                                    <p class="text-gray-600"><i class="fas fa-map-marker-alt mr-2 text-purple-600"></i> 
                                        <?php echo !empty($cas['cas_location']) ? htmlspecialchars($cas['cas_location']) : 'No location specified'; ?>
                                    </p>
                                </div>
                                
                                <div class="space-y-2">
                                    <p class="text-gray-600"><i class="fas fa-users mr-2 text-purple-600"></i> <?php echo count($students); ?> enrolled student<?php echo count($students) != 1 ? 's' : ''; ?></p>
                                    <p class="text-gray-600"><i class="fas fa-user-tie mr-2 text-purple-600"></i> <?php echo count($leaders) + 1; ?> CAS leader<?php echo count($leaders) + 1 != 1 ? 's' : ''; ?></p>
                                    <p class="text-gray-600"><i class="fas fa-calendar-alt mr-2 text-purple-600"></i> Next session: <?php echo date('l, F j, Y', strtotime($next_session_date)); ?></p>
                                </div>
                            </div>
                            
                            <?php if (!empty($cas['cas_description'])): ?>
                            <div class="mt-4 bg-gray-50 p-4 rounded-lg">
                                <h3 class="font-semibold text-gray-700 mb-2">Description</h3>
                                <p class="text-gray-600 text-sm sm:text-base"><?php echo nl2br(htmlspecialchars($cas['cas_description'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 sm:gap-8">
                    <!-- Main Content - Students & Sessions -->
                    <div class="xl:col-span-2 space-y-6 sm:space-y-8">
                        <!-- Enrolled Students -->
                        <div class="bg-white rounded-lg shadow-md overflow-hidden">
                            <div class="bg-blue-600 text-white px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center">
                                <h2 class="text-lg sm:text-xl font-bold">Enrolled Students (<?php echo count($students); ?>)</h2>
                            </div>
                            
                            <?php if (empty($students)): ?>
                            <div class="p-6 sm:p-8 text-center text-gray-500">
                                <i class="fas fa-users text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                                <p class="text-base sm:text-lg">No students enrolled in this CAS activity.</p>
                            </div>
                            <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Student
                                            </th>
                                            <th scope="col" class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Year
                                            </th>
                                            <th scope="col" class="hidden lg:table-cell px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Email
                                            </th>
                                            <th scope="col" class="px-3 sm:px-6 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Attendance
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($students as $student): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-8 w-8 sm:h-10 sm:w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                                        <span class="text-blue-800 font-semibold text-xs sm:text-sm"><?php echo substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1); ?></span>
                                                    </div>
                                                    <div class="ml-2 sm:ml-4">
                                                        <div class="text-xs sm:text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                        </div>
                                                        <div class="text-xs text-gray-500">
                                                            ID: <?php echo $student['student_id']; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                    <?php echo $student['grade_year']; ?>
                                                </span>
                                            </td>
                                            <td class="hidden lg:table-cell px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">
                                                    <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>" class="hover:text-blue-600">
                                                        <?php echo htmlspecialchars($student['email']); ?>
                                                    </a>
                                                </div>
                                            </td>
                                            <td class="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap">
                                                <?php
                                                $total = $student['present_count'] + $student['absent_count'] + $student['excused_count'];
                                                $rate = $total > 0 ? round(($student['present_count'] / $total) * 100, 1) : 0;
                                                $color_class = $rate >= 90 ? 'bg-green-100 text-green-800' : 
                                                              ($rate >= 75 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
                                                ?>
                                                <div class="flex flex-col lg:flex-row lg:items-center">
                                                    <span class="px-2 mb-1 lg:mb-0 lg:mr-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $color_class; ?>">
                                                        <?php echo $rate; ?>%
                                                    </span>
                                                    <div class="text-xs text-gray-500">
                                                        <?php echo $student['present_count']; ?> P / <?php echo $student['absent_count']; ?> A / <?php echo $student['excused_count']; ?> E
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Recent Attendance Sessions -->
                        <div class="bg-white rounded-lg shadow-md overflow-hidden">
                            <div class="bg-green-600 text-white px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center">
                                <h2 class="text-lg sm:text-xl font-bold">Recent Attendance Sessions</h2>
                            </div>
                            
                            <?php if (empty($recent_sessions)): ?>
                            <div class="p-6 sm:p-8 text-center text-gray-500">
                                <i class="fas fa-clipboard-check text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                                <p class="text-base sm:text-lg mb-4">No attendance sessions recorded yet.</p>
                                <div class="mt-4">
                                    <a href="record_attendance.php?id=<?php echo $cas_id; ?>" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm sm:text-base">
                                        <i class="fas fa-clipboard-check mr-2"></i> Record First Attendance
                                    </a>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="divide-y divide-gray-200">
                                <?php foreach ($recent_sessions as $session): ?>
                                <div class="p-4 sm:p-6 hover:bg-gray-50 transition-colors">
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-2">
                                        <div class="font-medium text-gray-900 text-sm sm:text-base">
                                            <?php echo date('F j, Y', strtotime($session['session_date'])); ?>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1 sm:mt-0">
                                            Recorded by <?php echo htmlspecialchars($session['recorded_by']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="flex flex-wrap gap-2 mb-2">
                                        <div class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full">
                                            <i class="fas fa-check mr-1"></i> <?php echo $session['present_count']; ?> Present
                                        </div>
                                        <div class="px-2 py-1 bg-red-100 text-red-800 text-xs rounded-full">
                                            <i class="fas fa-times mr-1"></i> <?php echo $session['absent_count']; ?> Absent
                                        </div>
                                        <div class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded-full">
                                            <i class="fas fa-exclamation mr-1"></i> <?php echo $session['excused_count']; ?> Excused
                                        </div>
                                    </div>
                                    
                                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center">
                                        <div class="text-sm text-gray-600 mb-2 sm:mb-0">
                                            <?php if (!empty($session['notes'])): ?>
                                            <span class="font-medium">Note:</span> <?php echo htmlspecialchars($session['notes']); ?>
                                            <?php else: ?>
                                            <span class="text-gray-400">No notes</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <a href="view_attendance.php?session_id=<?php echo $session['session_id']; ?>" class="text-purple-600 hover:text-purple-900 text-sm font-medium">
                                            View Details <i class="fas fa-arrow-right ml-1"></i>
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Sidebar - Summary, Leaders, Actions -->
                    <div class="space-y-6 sm:space-y-8">
                        <!-- Quick Actions -->
                        <div class="bg-white rounded-lg shadow-md overflow-hidden">
                            <div class="bg-purple-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                                <h2 class="text-lg sm:text-xl font-bold">Quick Actions</h2>
                            </div>
                            
                            <div class="p-4 sm:p-6">
                                <a href="record_attendance.php?id=<?php echo $cas_id; ?>" class="block w-full mb-3 text-center py-3 bg-green-100 hover:bg-green-200 text-green-800 rounded-lg transition-colors text-sm sm:text-base font-medium">
                                    <i class="fas fa-clipboard-check mr-2"></i> Record Attendance
                                </a>
                                
                                <?php if ($today === $cas['cas_day']): ?>
                                <div class="bg-blue-50 p-4 rounded-lg text-center mb-3">
                                    <p class="text-blue-800 font-medium text-sm sm:text-base">Today is <?php echo ucfirst($cas['cas_day']); ?>!</p>
                                    <p class="text-xs sm:text-sm text-blue-600"><?php echo $cas['cas_name']; ?> meets today at <?php echo date('g:i A', strtotime($cas['cas_time'])); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Attendance Summary -->
                        <div class="bg-white rounded-lg shadow-md overflow-hidden">
                            <div class="bg-amber-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                                <h2 class="text-lg sm:text-xl font-bold">Attendance Summary</h2>
                            </div>
                            
                            <div class="p-4 sm:p-6">
                                <div class="grid grid-cols-2 gap-4 mb-6">
                                    <div class="bg-blue-50 p-3 sm:p-4 rounded-lg text-center">
                                        <div class="text-xl sm:text-2xl font-bold text-blue-600"><?php echo $attendance_summary['total_sessions']; ?></div>
                                        <div class="text-gray-600 text-xs sm:text-sm">Total Sessions</div>
                                    </div>
                                    
                                    <div class="bg-green-50 p-3 sm:p-4 rounded-lg text-center">
                                        <div class="text-xl sm:text-2xl font-bold text-green-600">
                                            <?php echo $attendance_rate; ?>%
                                        </div>
                                        <div class="text-gray-600 text-xs sm:text-sm">Attendance Rate</div>
                                    </div>
                                </div>
                                
                                <?php if ($attendance_summary['total_records'] > 0): ?>
                                <div class="mb-6">
                                    <div class="flex justify-between text-xs sm:text-sm font-medium text-gray-700 mb-1">
                                        <span>Attendance Breakdown</span>
                                    </div>
                                    <div class="h-4 sm:h-6 bg-gray-200 rounded-full overflow-hidden">
                                        <?php
                                        $total_records = $attendance_summary['total_records'];
                                        $present_percentage = $total_records > 0 ? ($attendance_summary['present_count'] / $total_records) * 100 : 0;
                                        $absent_percentage = $total_records > 0 ? ($attendance_summary['absent_count'] / $total_records) * 100 : 0;
                                        $excused_percentage = $total_records > 0 ? ($attendance_summary['excused_count'] / $total_records) * 100 : 0;
                                        ?>
                                        <div class="flex h-full">
                                            <div class="bg-green-500 h-full" style="width: <?php echo $present_percentage; ?>%"></div>
                                            <div class="bg-red-500 h-full" style="width: <?php echo $absent_percentage; ?>%"></div>
                                            <div class="bg-yellow-500 h-full" style="width: <?php echo $excused_percentage; ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="flex justify-between text-xs text-gray-600 mt-2">
                                        <div>Present: <?php echo $attendance_summary['present_count']; ?></div>
                                        <div>Absent: <?php echo $attendance_summary['absent_count']; ?></div>
                                        <div>Excused: <?php echo $attendance_summary['excused_count']; ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Other Supervisors -->
                        <?php if (!empty($leaders)): ?>
                        <div class="bg-white rounded-lg shadow-md overflow-hidden">
                            <div class="bg-indigo-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                                <h2 class="text-base sm:text-lg font-bold">Other Supervisors</h2>
                            </div>
                            
                            <div class="divide-y divide-gray-200">
                                <?php foreach ($leaders as $leader): ?>
                                <div class="p-3 sm:p-4 hover:bg-gray-50 transition-colors">
                                    <div class="flex items-center">
                                        <div class="h-8 w-8 sm:h-10 sm:w-10 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-800 font-bold text-xs sm:text-sm">
                                            <?php echo substr($leader['first_name'], 0, 1) . substr($leader['last_name'], 0, 1); ?>
                                        </div>
                                        <div class="ml-3">
                                            <div class="text-xs sm:text-sm font-medium text-gray-900"><?php echo htmlspecialchars($leader['first_name'] . ' ' . $leader['last_name']); ?></div>
                                            <div class="text-xs text-gray-500">
                                                <a href="mailto:<?php echo htmlspecialchars($leader['email']); ?>" class="hover:text-blue-600">
                                                    <?php echo htmlspecialchars($leader['email']); ?>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
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
                        <a href="../about.php" class="text-gray-300 hover:text-white text-sm sm:text-base">About</a>
                        <a href="../contact.php" class="text-gray-300 hover:text-white text-sm sm:text-base">Contact</a>
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