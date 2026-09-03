<?php
// Start session for user authentication
session_start();

// Check if user is logged in and is a CAS leader
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_status'] !== 'cas_leader') {
    header("Location: ../login.php");
    exit();
}

// Database connection
require_once '../includes/db_connect.php';

// Get CAS leader's information
$user_id = $_SESSION['user_id'];
$student_id = $_SESSION['student_id']; // Student ID linked to this CAS leader

// Get CAS activities led by this leader
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
        cas_leaders cl
    JOIN 
        cas_activities ca ON cl.cas_id = ca.cas_id
    LEFT JOIN 
        student_cas_enrollment sce ON ca.cas_id = sce.cas_id AND sce.is_active = 1
    WHERE 
        cl.user_id = ? AND ca.is_active = 1
    GROUP BY 
        ca.cas_id, ca.cas_name, ca.cas_type, ca.cas_day, ca.cas_time, ca.cas_location
    ORDER BY 
        ca.cas_day, ca.cas_time
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $cas_activities[] = $row;
}
$stmt->close();

// Get upcoming sessions (based on day of the week)
$upcoming_sessions = [];
$today = strtolower(date('l')); // Current day of the week
$days_order = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
$today_index = array_search($today, $days_order);

// Get the ordered days starting from tomorrow
$ordered_days = array_merge(
    array_slice($days_order, $today_index + 1),
    array_slice($days_order, 0, $today_index + 1)
);

// Get CAS activities ordered by upcoming days
foreach ($ordered_days as $day) {
    $stmt = $conn->prepare("
        SELECT 
            ca.cas_id,
            ca.cas_name,
            ca.cas_type,
            ca.cas_day,
            ca.cas_time,
            ca.cas_location,
            (
                SELECT COUNT(*)
                FROM student_cas_enrollment sce
                WHERE sce.cas_id = ca.cas_id AND sce.is_active = 1
            ) as student_count
        FROM 
            cas_activities ca
        JOIN 
            cas_leaders cl ON ca.cas_id = cl.cas_id
        WHERE 
            cl.user_id = ? AND ca.is_active = 1 AND ca.cas_day = ?
        ORDER BY 
            ca.cas_time
    ");
    $stmt->bind_param("is", $user_id, $day);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $upcoming_sessions[] = $row;
        
        // If we have 5 upcoming sessions, stop
        if (count($upcoming_sessions) >= 5) {
            break 2; // Break out of both the while and for loops
        }
    }
    
    $stmt->close();
}

// Get recent attendance sessions (most recent first)
$recent_attendance = [];
$stmt = $conn->prepare("
    SELECT 
        ats.session_id,
        ats.session_date,
        ca.cas_id,
        ca.cas_name,
        ca.cas_type,
        COUNT(ar.record_id) as total_records,
        SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END) as excused_count
    FROM 
        attendance_sessions ats
    JOIN 
        cas_activities ca ON ats.cas_id = ca.cas_id
    JOIN 
        cas_leaders cl ON ca.cas_id = cl.cas_id
    LEFT JOIN 
        attendance_records ar ON ats.session_id = ar.session_id
    WHERE 
        cl.user_id = ?
    GROUP BY 
        ats.session_id, ats.session_date, ca.cas_id, ca.cas_name, ca.cas_type
    ORDER BY 
        ats.session_date DESC
    LIMIT 5
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $recent_attendance[] = $row;
}
$stmt->close();

// Get total attendance counts across all CAS activities led by this leader
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT ats.session_id) as total_sessions,
        SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END) as excused_count,
        COUNT(ar.record_id) as total_records
    FROM 
        cas_activities ca
    JOIN 
        cas_leaders cl ON ca.cas_id = cl.cas_id
    LEFT JOIN 
        attendance_sessions ats ON ca.cas_id = ats.cas_id
    LEFT JOIN 
        attendance_records ar ON ats.session_id = ar.session_id
    WHERE 
        cl.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$attendance_summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Calculate attendance rate
$attendance_rate = 0;
if ($attendance_summary['total_records'] > 0) {
    $attendance_rate = round(($attendance_summary['present_count'] / $attendance_summary['total_records']) * 100, 1);
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAS Leader Dashboard - UWC Mostar CAS</title>
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
                    <!-- CAS Leader Badge -->
                    <span class="hidden sm:inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                        CAS Leader
                    </span>
                    
                    <!-- User dropdown -->
                    <div class="relative">
                        <button class="flex items-center text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500" id="user-menu-button">
                            <span class="sr-only">Open user menu</span>
                            <div class="h-8 w-8 rounded-full bg-purple-600 flex items-center justify-center text-white">
                                <i class="fas fa-user"></i>
                            </div>
                            <span class="hidden md:ml-2 md:block text-gray-700 font-medium"><?php echo htmlspecialchars($_SESSION['name'] ?? 'CAS Leader'); ?></span>
                            <svg class="hidden md:block ml-1 h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                        
                        <!-- User dropdown menu -->
                        <div class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 ring-1 ring-black ring-opacity-5 z-50" id="user-menu-dropdown">
                            <div class="px-4 py-2 text-sm text-gray-700 border-b border-gray-100">
                                <p class="font-medium"><?php echo htmlspecialchars($_SESSION['name'] ?? 'CAS Leader'); ?></p>
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
                            <h1 class="text-lg font-bold text-gray-900">CAS Leader Panel</h1>
                        </div>
                    </div>
                </div>
                
                <nav class="px-4 space-y-1">
                    <a href="/cas_leader/dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-purple-50 border-r-4 border-purple-600 text-purple-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="/cas_leader/record_attendance" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-clipboard-check mr-3 text-lg"></i>
                        Record Attendance
                    </a>
                    
                   
                    
                    <?php if (!empty($cas_activities)): ?>
                    <div class="pt-4 pb-2">
                        <div class="px-2">
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                Your CAS Activities
                            </h3>
                        </div>
                    </div>
                    
                    <?php foreach ($cas_activities as $activity): ?>
                    <a href="cas_activity.php?id=<?php echo $activity['cas_id']; ?>" class="text-gray-600 hover:bg-purple-50 hover:text-purple-700 group flex items-center px-2 py-2 text-sm rounded-md">
                        <?php
                        $icon_class = '';
                        switch($activity['cas_type']) {
                            case 'creativity':
                                $icon_class = 'fa-paintbrush';
                                break;
                            case 'activity':
                                $icon_class = 'fa-person-running';
                                break;
                            case 'service':
                                $icon_class = 'fa-hands-helping';
                                break;
                        }
                        ?>
                        <i class="fas <?php echo $icon_class; ?> mr-3 text-lg"></i>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium truncate"><?php echo htmlspecialchars($activity['cas_name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo ucfirst($activity['cas_type']); ?> • <?php echo ucfirst($activity['cas_day']); ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
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
                    <a href="/cas_leader/dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-purple-50 border-r-4 border-purple-600 text-purple-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="/cas_leader/record_attendance" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-clipboard-check mr-3 text-lg"></i>
                        Record Attendance
                    </a>
                    
                    
                    
                    <?php if (!empty($cas_activities)): ?>
                    <div class="pt-4 pb-2">
                        <div class="px-2">
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                Your CAS Activities
                            </h3>
                        </div>
                    </div>
                    
                    <?php foreach ($cas_activities as $activity): ?>
                    <a href="cas_activity.php?id=<?php echo $activity['cas_id']; ?>" class="text-gray-600 hover:bg-purple-50 hover:text-purple-700 group flex items-center px-2 py-2 text-sm rounded-md">
                        <?php
                        $icon_class = '';
                        switch($activity['cas_type']) {
                            case 'creativity':
                                $icon_class = 'fa-paintbrush';
                                break;
                            case 'activity':
                                $icon_class = 'fa-person-running';
                                break;
                            case 'service':
                                $icon_class = 'fa-hands-helping';
                                break;
                        }
                        ?>
                        <i class="fas <?php echo $icon_class; ?> mr-3 text-lg"></i>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium truncate"><?php echo htmlspecialchars($activity['cas_name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo ucfirst($activity['cas_type']); ?> • <?php echo ucfirst($activity['cas_day']); ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
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
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6 sm:mb-8">CAS Leader Dashboard</h1>
                
                <!-- Welcome Message -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 mb-6 sm:mb-8">
                    <h2 class="text-lg sm:text-xl font-bold text-purple-800 mb-4">Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h2>
                    <p class="text-gray-600 text-sm sm:text-base">
                        As a CAS leader, you can manage your CAS activities, record attendance, and view reports for the activities you lead.
                        <?php if (empty($cas_activities)): ?>
                        <span class="block mt-2 text-yellow-600">
                            <i class="fas fa-exclamation-triangle mr-1"></i> You are not currently assigned as a leader for any CAS activities. Please contact an administrator.
                        </span>
                        <?php else: ?>
                        <span class="block mt-2">
                            You are currently leading <?php echo count($cas_activities); ?> CAS <?php echo count($cas_activities) === 1 ? 'activity' : 'activities'; ?>.
                        </span>
                        <?php endif; ?>
                    </p>
                </div>
                
                <!-- CAS Activities Summary -->
                <div class="grid grid-cols-1 <?php echo count($cas_activities) == 2 ? 'md:grid-cols-2' : (count($cas_activities) >= 3 ? 'md:grid-cols-2 lg:grid-cols-3' : ''); ?> gap-4 sm:gap-6 mb-6 sm:mb-8">
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
                    <a href="cas_activity.php?id=<?php echo $activity['cas_id']; ?>" class="block rounded-lg shadow-md overflow-hidden border <?php echo $border_class; ?> hover:shadow-lg transition-shadow duration-300">
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
                                <?php if (!empty($activity['cas_location'])): ?>
                                <div class="text-xs sm:text-sm text-gray-600 truncate">
                                    <i class="fas fa-map-marker-alt mr-1"></i> <?php echo htmlspecialchars($activity['cas_location']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="px-4 sm:px-6 py-2 sm:py-3 bg-white border-t border-gray-100 text-center">
                            <span class="text-xs sm:text-sm text-purple-600 font-medium">Manage Activity <i class="fas fa-arrow-right ml-1"></i></span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    
                    <?php if (empty($cas_activities)): ?>
                    <div class="bg-white rounded-lg shadow-md p-6 sm:p-8 text-center col-span-full">
                        <i class="fas fa-exclamation-circle text-yellow-500 text-3xl sm:text-4xl mb-4"></i>
                        <p class="text-gray-600 text-sm sm:text-base">You are not currently assigned as a leader for any CAS activities.</p>
                        <p class="text-gray-600 mt-2 text-sm sm:text-base">Please contact an administrator to be assigned to CAS activities.</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 sm:gap-8">
                    <!-- Upcoming Sessions -->
                    <div class="xl:col-span-2 space-y-6 sm:space-y-8">
                        <div class="bg-white rounded-lg shadow-md overflow-hidden">
                            <div class="bg-purple-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                                <h2 class="text-lg sm:text-xl font-bold">Upcoming Sessions</h2>
                            </div>
                            
                            <?php if (empty($upcoming_sessions)): ?>
                            <div class="p-6 sm:p-8 text-center text-gray-500">
                                <i class="fas fa-calendar-alt text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                                <p class="text-base sm:text-lg">No upcoming CAS sessions found.</p>
                            </div>
                            <?php else: ?>
                            <div class="divide-y divide-gray-200">
                                <?php foreach ($upcoming_sessions as $index => $session): ?>
                                <div class="p-4 sm:p-6 hover:bg-gray-50 transition-colors <?php echo $index === 0 && strtolower(date('l')) === $session['cas_day'] ? 'bg-green-50' : ''; ?>">
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-2">
                                        <div class="font-medium text-gray-900 text-sm sm:text-base">
                                            <?php echo htmlspecialchars($session['cas_name']); ?>
                                            <?php if (strtolower(date('l')) === $session['cas_day']): ?>
                                            <span class="ml-2 text-xs bg-green-100 text-green-800 px-2 py-1 rounded-full">Today</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php
                                        $type_class = '';
                                        switch($session['cas_type']) {
                                            case 'creativity':
                                                $type_class = 'bg-purple-100 text-purple-800';
                                                break;
                                            case 'activity':
                                                $type_class = 'bg-blue-100 text-blue-800';break;
                                                case 'service':
                                                    $type_class = 'bg-yellow-100 text-yellow-800';
                                                    break;
                                            }
                                            ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $type_class; ?> mt-2 sm:mt-0">
                                                <?php echo ucfirst($session['cas_type']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs sm:text-sm text-gray-600">
                                            <div>
                                                <i class="fas fa-calendar-day mr-1"></i> <?php echo ucfirst($session['cas_day']); ?>
                                            </div>
                                            <div>
                                                <i class="fas fa-clock mr-1"></i> <?php echo date('g:i A', strtotime($session['cas_time'])); ?>
                                            </div>
                                            <div>
                                                <i class="fas fa-users mr-1"></i> <?php echo $session['student_count']; ?> student<?php echo $session['student_count'] != 1 ? 's' : ''; ?>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($session['cas_location'])): ?>
                                        <div class="mt-2 text-xs sm:text-sm text-gray-600">
                                            <i class="fas fa-map-marker-alt mr-1"></i> <?php echo htmlspecialchars($session['cas_location']); ?>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="mt-4">
                                            <?php if (strtolower(date('l')) === $session['cas_day']): ?>
                                            <a href="record_attendance.php?id=<?php echo $session['cas_id']; ?>" class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-lg font-semibold text-xs sm:text-sm text-white uppercase tracking-widest hover:bg-green-700 active:bg-green-900 focus:outline-none focus:border-green-900 focus:ring focus:ring-green-300 disabled:opacity-25 transition">
                                                <i class="fas fa-clipboard-check mr-2"></i> Record Attendance
                                            </a>
                                            <?php else: ?>
                                            <a href="cas_activity.php?id=<?php echo $session['cas_id']; ?>" class="text-purple-600 hover:text-purple-900 text-xs sm:text-sm font-medium">
                                                View Details <i class="fas fa-arrow-right ml-1"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Recent Attendance Records -->
                            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                                <div class="bg-green-600 text-white px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center">
                                    <h2 class="text-lg sm:text-xl font-bold">Recent Attendance</h2>
                                    
                                    <a href="attendance_history.php" class="text-xs sm:text-sm text-white hover:text-green-100">
                                      
                                    </a>
                                </div>
                                
                                <?php if (empty($recent_attendance)): ?>
                                <div class="p-6 sm:p-8 text-center text-gray-500">
                                    <i class="fas fa-clipboard-check text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                                    <p class="text-base sm:text-lg">No attendance records found.</p>
                                </div>
                                <?php else: ?>
                                <div class="divide-y divide-gray-200">
                                    <?php foreach ($recent_attendance as $record): ?>
                                    <div class="p-4 sm:p-6 hover:bg-gray-50 transition-colors">
                                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-2">
                                            <div>
                                                <span class="font-medium text-gray-900 text-sm sm:text-base"><?php echo htmlspecialchars($record['cas_name']); ?></span>
                                                <span class="ml-2 text-xs text-gray-500"><?php echo date('M j, Y', strtotime($record['session_date'])); ?></span>
                                            </div>
                                            <?php
                                            $type_class = '';
                                            switch($record['cas_type']) {
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
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $type_class; ?> mt-2 sm:mt-0">
                                                <?php echo ucfirst($record['cas_type']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="flex flex-wrap gap-2 mb-2">
                                            <div class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full">
                                                <i class="fas fa-check mr-1"></i> <?php echo $record['present_count']; ?> Present
                                            </div>
                                            <div class="px-2 py-1 bg-red-100 text-red-800 text-xs rounded-full">
                                                <i class="fas fa-times mr-1"></i> <?php echo $record['absent_count']; ?> Absent
                                            </div>
                                            <div class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded-full">
                                                <i class="fas fa-exclamation mr-1"></i> <?php echo $record['excused_count']; ?> Excused
                                            </div>
                                        </div>
                                        
                                        <div class="mt-2">
                                            <a href="view_attendance.php?session_id=<?php echo $record['session_id']; ?>" class="text-purple-600 hover:text-purple-900 text-xs sm:text-sm font-medium">
                                                View Details <i class="fas fa-arrow-right ml-1"></i>
                                            </a>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Sidebar - Attendance Summary & Quick Actions -->
                        <div class="space-y-6 sm:space-y-8">
                            <!-- Attendance Summary -->
                            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                                <div class="bg-amber-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                                    <h2 class="text-lg sm:text-xl font-bold">Attendance Summary</h2>
                                </div>
                                
                                <div class="p-4 sm:p-6">
                                    <div class="grid grid-cols-2 gap-4 mb-6">
                                        <div class="bg-blue-50 p-3 sm:p-4 rounded-lg text-center">
                                            <div class="text-2xl sm:text-3xl font-bold text-blue-600"><?php echo $attendance_summary['total_sessions']; ?></div>
                                            <div class="text-gray-600 text-xs sm:text-sm">Total Sessions</div>
                                        </div>
                                        
                                        <div class="bg-green-50 p-3 sm:p-4 rounded-lg text-center">
                                            <div class="text-2xl sm:text-3xl font-bold text-green-600">
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
                            
                            <!-- Quick Actions -->
                            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                                <div class="bg-purple-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                                    <h2 class="text-lg sm:text-xl font-bold">Quick Actions</h2>
                                </div>
                                
                                <div class="p-4 sm:p-6">
                                    <a href="record_attendance.php" class="block w-full mb-3 text-center py-3 bg-green-100 hover:bg-green-200 text-green-800 rounded-lg transition-colors text-sm sm:text-base font-medium">
                                        <i class="fas fa-clipboard-check mr-2"></i> Record Attendance
                                    </a>
                                    
                                   
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
            });
        </script>
    </body>
    </html>