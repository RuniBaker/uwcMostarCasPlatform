<?php
// Start session for user authentication
session_start();

// Check if user is logged in and is a CAS leader
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_status'] !== 'cas_leader') {
    // Not logged in or not a CAS leader, redirect to login page
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

// Get session ID from URL
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;

// Check if success message should be shown
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = "Attendance recorded successfully.";
    $message_type = "success";
}

// If no session ID provided, redirect to dashboard
if ($session_id === 0) {
    header("Location: dashboard.php");
    exit();
}

// Get CAS activities led by this leader for navigation
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

// Get attendance session details
$session = null;
$stmt = $conn->prepare("
    SELECT 
        ats.session_id,
        ats.session_date,
        ats.notes as session_notes,
        ats.created_at,
        ca.cas_id,
        ca.cas_name,
        ca.cas_type,
        ca.cas_day,
        ca.cas_time,
        ca.cas_location,
        u.first_name as recorder_first_name,
        u.last_name as recorder_last_name
    FROM 
        attendance_sessions ats
    JOIN 
        cas_activities ca ON ats.cas_id = ca.cas_id
    JOIN 
        users u ON ats.recorded_by = u.user_id
    JOIN 
        cas_leaders cl ON ca.cas_id = cl.cas_id
    WHERE 
        ats.session_id = ? AND cl.user_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $session_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Session not found or not authorized, redirect to dashboard
    header("Location: dashboard.php");
    exit();
}

$session = $result->fetch_assoc();
$stmt->close();

// Get attendance records for this session
$attendance_records = [];
$stmt = $conn->prepare("
    SELECT 
        ar.record_id,
        ar.student_id,
        ar.status,
        ar.notes,
        s.first_name,
        s.last_name,
        s.grade_year
    FROM 
        attendance_records ar
    JOIN 
        students s ON ar.student_id = s.student_id
    WHERE 
        ar.session_id = ?
    ORDER BY 
        s.grade_year, s.last_name, s.first_name
");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $attendance_records[] = $row;
}
$stmt->close();

// Calculate attendance statistics
$total_students = count($attendance_records);
$present_count = 0;
$absent_count = 0;
$excused_count = 0;

foreach ($attendance_records as $record) {
    switch ($record['status']) {
        case 'present':
            $present_count++;
            break;
        case 'absent':
            $absent_count++;
            break;
        case 'excused':
            $excused_count++;
            break;
    }
}

$attendance_rate = $total_students > 0 ? round(($present_count / $total_students) * 100, 1) : 0;

// Handle form submission for editing attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_attendance'])) {
    // Check if this session belongs to a CAS activity the leader is responsible for
    $stmt = $conn->prepare("
        SELECT ats.session_id
        FROM attendance_sessions ats
        JOIN cas_activities ca ON ats.cas_id = ca.cas_id
        JOIN cas_leaders cl ON ca.cas_id = cl.cas_id
        WHERE ats.session_id = ? AND cl.user_id = ?
    ");
    $stmt->bind_param("ii", $session_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $message = "You are not authorized to update this attendance record.";
        $message_type = "error";
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update attendance records
            $attendance_status = isset($_POST['attendance_status']) ? $_POST['attendance_status'] : [];
            $attendance_notes = isset($_POST['attendance_notes']) ? $_POST['attendance_notes'] : [];
            
            $update_stmt = $conn->prepare("UPDATE attendance_records SET status = ?, notes = ? WHERE record_id = ? AND session_id = ?");
            
            foreach ($attendance_status as $record_id => $status) {
                $notes = isset($attendance_notes[$record_id]) ? trim($attendance_notes[$record_id]) : '';
                $update_stmt->bind_param("ssii", $status, $notes, $record_id, $session_id);
                $update_stmt->execute();
            }
            
            $update_stmt->close();
            
            // Update session notes if provided
            if (isset($_POST['session_notes'])) {
                $session_notes = trim($_POST['session_notes']);
                $stmt = $conn->prepare("UPDATE attendance_sessions SET notes = ? WHERE session_id = ?");
                $stmt->bind_param("si", $session_notes, $session_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // Commit transaction
            $conn->commit();
            
            $message = "Attendance updated successfully.";
            $message_type = "success";
            
            // Refresh page to show updated data
            header("Location: view_attendance.php?session_id=" . $session_id . "&success=2");
            exit();
        } catch (Exception $e) {
            // Rollback in case of error
            $conn->rollback();
            $message = "Error updating attendance: " . $e->getMessage();
            $message_type = "error";
        }
    }
    
    $stmt->close();
}

// Check if this is an updated record
if (isset($_GET['success']) && $_GET['success'] == 2) {
    $message = "Attendance updated successfully.";
    $message_type = "success";
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Attendance - UWC Mostar CAS</title>
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
                    <a href="dashboard.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="record_attendance.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
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
                    <a href="dashboard.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="record_attendance.php" class="text-gray-600 hover:bg-gray-50 hover:text-gray-900 group flex items-center px-2 py-2 text-sm font-medium rounded-md">
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
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-6 sm:mb-8">
                    <div class="flex items-center mb-4 sm:mb-0">
                        <a href="dashboard.php" class="mr-4 text-gray-600 hover:text-gray-800 text-sm sm:text-base">
                            <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
                        </a>
                        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">Attendance Details</h1>
                    </div>
                    
                    <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
                        <a href="record_attendance.php?id=<?php echo $session['cas_id']; ?>" class="w-full sm:w-auto px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg text-center text-sm sm:text-base transition-colors">
                            <i class="fas fa-clipboard-check mr-2"></i> Record New
                        </a>
                        
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
                
                <!-- Session Details -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6 sm:mb-8">
                    <div class="bg-blue-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                            <h2 class="text-lg sm:text-xl font-bold mb-2 sm:mb-0">Session Information</h2>
                            <div class="flex items-center">
                                <?php
                                $icon_class = '';
                                switch($session['cas_type']) {
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
                                <i class="fas <?php echo $icon_class; ?> mr-2"></i>
                                <span class="font-medium"><?php echo htmlspecialchars($session['cas_name']); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-4 sm:p-6">
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <!-- Basic Information -->
                            <div class="lg:col-span-2">
                                <div class="flex items-center mb-4">
                                    <?php
                                    $type_class = '';
                                    switch($session['cas_type']) {
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
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $type_class; ?> mr-3">
                                        <?php echo ucfirst($session['cas_type']); ?>
                                    </span>
                                    
                                    <span class="text-gray-600 text-sm">
                                        <?php echo ucfirst($session['cas_day']); ?> at <?php echo date('g:i A', strtotime($session['cas_time'])); ?>
                                    </span>
                                    
                                    <?php if (!empty($session['cas_location'])): ?>
                                    <span class="text-gray-600 text-sm ml-2">
                                        | <?php echo htmlspecialchars($session['cas_location']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                                    <div class="space-y-2">
                                        <div class="flex items-center">
                                            <i class="fas fa-calendar-day mr-2 text-gray-400"></i>
                                            <span class="font-medium text-gray-700">Session Date:</span>
                                            <span class="ml-1 text-gray-900"><?php echo date('F j, Y', strtotime($session['session_date'])); ?></span>
                                        </div>
                                        <div class="flex items-center">
                                            <i class="fas fa-clock mr-2 text-gray-400"></i>
                                            <span class="font-medium text-gray-700">Recorded:</span>
                                            <span class="ml-1 text-gray-900"><?php echo date('M j, Y, g:i A', strtotime($session['created_at'])); ?></span>
                                        </div>
                                        <div class="flex items-center">
                                            <i class="fas fa-user mr-2 text-gray-400"></i>
                                            <span class="font-medium text-gray-700">Recorded By:</span>
                                            <span class="ml-1 text-gray-900"><?php echo htmlspecialchars($session['recorder_first_name'] . ' ' . $session['recorder_last_name']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="space-y-2">
                                        <div class="flex items-center">
                                            <i class="fas fa-users mr-2 text-gray-400"></i>
                                            <span class="font-medium text-gray-700">Total Students:</span>
                                            <span class="ml-1 text-gray-900"><?php echo $total_students; ?></span>
                                        </div>
                                        <div class="flex items-center">
                                            <i class="fas fa-chart-line mr-2 text-gray-400"></i>
                                            <span class="font-medium text-gray-700">Attendance Rate:</span>
                                            <span class="ml-1 text-gray-900"><?php echo $attendance_rate; ?>%</span>
                                        </div>
                                        <?php if (!empty($session['session_notes'])): ?>
                                        <div class="flex items-start">
                                            <i class="fas fa-sticky-note mr-2 text-gray-400 mt-0.5"></i>
                                            <span class="font-medium text-gray-700">Notes:</span>
                                            <span class="ml-1 text-gray-900"><?php echo htmlspecialchars($session['session_notes']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Attendance Summary -->
                            <div class="lg:border-l lg:border-gray-200 lg:pl-6">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Attendance Summary</h3>
                                
                                <div class="grid grid-cols-3 gap-3 mb-4">
                                    <div class="bg-green-50 p-3 rounded-lg text-center">
                                        <div class="text-xl sm:text-2xl font-bold text-green-600"><?php echo $present_count; ?></div>
                                        <div class="text-xs text-gray-600">Present</div>
                                    </div>
                                    
                                    <div class="bg-red-50 p-3 rounded-lg text-center">
                                        <div class="text-xl sm:text-2xl font-bold text-red-600"><?php echo $absent_count; ?></div>
                                        <div class="text-xs text-gray-600">Absent</div>
                                    </div>
                                    
                                    <div class="bg-yellow-50 p-3 rounded-lg text-center">
                                        <div class="text-xl sm:text-2xl font-bold text-yellow-600"><?php echo $excused_count; ?></div>
                                        <div class="text-xs text-gray-600">Excused</div>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="flex justify-between text-xs text-gray-600 mb-1">
                                        <span>Attendance Breakdown</span>
                                        <span><?php echo $attendance_rate; ?>%</span>
                                    </div>
                                    <div class="h-3 sm:h-4 bg-gray-200 rounded-full overflow-hidden">
                                        <?php
                                        $present_percentage = $total_students > 0 ? ($present_count / $total_students) * 100 : 0;
                                        $absent_percentage = $total_students > 0 ? ($absent_count / $total_students) * 100 : 0;
                                        $excused_percentage = $total_students > 0 ? ($excused_count / $total_students) * 100 : 0;
                                        ?>
                                        <div class="flex h-full">
                                            <div class="bg-green-500 h-full" style="width: <?php echo $present_percentage; ?>%"></div>
                                            <div class="bg-red-500 h-full" style="width: <?php echo $absent_percentage; ?>%"></div>
                                            <div class="bg-yellow-500 h-full" style="width: <?php echo $excused_percentage; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Attendance Records -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-purple-600 text-white px-4 sm:px-6 py-3 sm:py-4 flex flex-col sm:flex-row sm:justify-between sm:items-center">
                        <h2 class="text-lg sm:text-xl font-bold mb-2 sm:mb-0">Student Attendance</h2>
                        
                        
                    </div>
                    
                    <?php if (empty($attendance_records)): ?>
                    <div class="p-6 sm:p-8 text-center text-gray-500">
                        <i class="fas fa-users text-3xl sm:text-4xl text-gray-300 mb-4"></i>
                        <p class="text-base sm:text-lg">No attendance records found for this session.</p>
                    </div>
                    <?php else: ?>
                    <form action="view_attendance.php?session_id=<?php echo $session_id; ?>" method="POST" id="editAttendanceForm">
                        <!-- Edit Mode Controls -->
                        <div id="editModeControls" class="px-4 sm:px-6 py-3 sm:py-4 bg-gray-100 border-b border-gray-200 hidden">
                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" onclick="setAllAttendance('present')" class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium hover:bg-green-200 transition-colors">
                                        <i class="fas fa-check mr-1"></i> All Present
                                    </button>
                                    <button type="button" onclick="setAllAttendance('absent')" class="px-3 py-1 bg-red-100 text-red-800 rounded-full text-xs font-medium hover:bg-red-200 transition-colors">
                                        <i class="fas fa-times mr-1"></i> All Absent
                                    </button>
                                    <button type="button" onclick="setAllAttendance('excused')" class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs font-medium hover:bg-yellow-200 transition-colors">
                                        <i class="fas fa-exclamation mr-1"></i> All Excused
                                    </button>
                                </div>
                                
                                <div class="flex flex-col sm:flex-row sm:items-center">
                                    <label class="text-sm text-gray-700 mr-2 mb-1 sm:mb-0">Session Notes:</label>
                                    <input type="text" name="session_notes" value="<?php echo htmlspecialchars($session['session_notes']); ?>" 
                                           class="px-3 py-1 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm w-full sm:w-64">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Mobile View -->
                        <div class="block lg:hidden">
                            <div class="divide-y divide-gray-200">
                                <?php foreach ($attendance_records as $record): ?>
                                <div class="p-4 hover:bg-gray-50 transition-colors">
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                                                <span class="text-blue-800 font-semibold text-sm"><?php echo substr($record['first_name'], 0, 1) . substr($record['last_name'], 0, 1); ?></span>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    Grade <?php echo $record['grade_year']; ?> • ID: <?php echo $record['student_id']; ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- View Mode Status -->
                                        <div class="view-mode">
                                            <?php
                                            switch ($record['status']) {
                                                case 'present':
                                                    echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800"><i class="fas fa-check mr-1"></i>Present</span>';
                                                    break;
                                                case 'absent':
                                                    echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800"><i class="fas fa-times mr-1"></i>Absent</span>';
                                                    break;
                                                case 'excused':
                                                    echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800"><i class="fas fa-exclamation mr-1"></i>Excused</span>';
                                                    break;
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Edit Mode Status -->
                                    <div class="edit-mode hidden mb-3">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Attendance Status</label>
                                        <div class="grid grid-cols-3 gap-2">
                                            <label class="flex items-center p-2 border border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50 attendance-option">
                                                <input type="radio" name="attendance_status[<?php echo $record['record_id']; ?>]" value="present" <?php echo $record['status'] === 'present' ? 'checked' : ''; ?> class="sr-only attendance-radio">
                                                <div class="w-4 h-4 border-2 border-gray-300 rounded-full mr-2 radio-circle"></div>
                                                <div class="flex-1 text-center">
                                                    <i class="fas fa-check text-green-600 mb-1"></i>
                                                    <div class="text-xs font-medium text-gray-700">Present</div>
                                                </div>
                                            </label>
                                            
                                            <label class="flex items-center p-2 border border-gray-300 rounded-lg cursor-pointer hover:bg-gray-50 attendance-option">
                                                <input type="radio" name="attendance_status[<?php echo $record['record_id']; ?>]" value="absent" <?php echo $record['status'] === 'absent' ? 'checked' : ''; ?> class="sr-only attendance-radio">
                                                <div class="w-4 h-4 border-2 border-gray-300 rounded-full mr-2 radio-circle"></div>
                                                <div class="flex-1 text-center">
                                                    <i class="fas fa-times text-red-600 mb-1"></i>
                                                    <div class="text-xs font-medium text-gray-700">Absent</div>
                                                </div>
                                            </label>
                                            
                                           
                                        </div>
                                    </div>
                                    
                                    <!-- Notes -->
                                    <div>
                                        <!-- View Mode Notes -->
                                        <div class="view-mode">
                                            <div class="text-sm text-gray-600">
                                                <span class="font-medium">Notes:</span>
                                                <?php echo !empty($record['notes']) ? htmlspecialchars($record['notes']) : '<span class="text-gray-400 italic">No notes</span>'; ?>
                                            </div>
                                        </div>
                                        
                                        <!-- Edit Mode Notes -->
                                        <div class="edit-mode hidden">
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes (Optional)</label>
                                            <textarea name="attendance_notes[<?php echo $record['record_id']; ?>]" 
                                                      rows="2" 
                                                      placeholder="Add notes for this student..."
                                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 focus:border-purple-500 resize-none"><?php echo htmlspecialchars($record['notes']); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Desktop View -->
                        <div class="hidden lg:block overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Student
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Grade
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Notes
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($attendance_records as $index => $record): ?>
                                    <tr class="<?php echo $index % 2 === 0 ? 'bg-white' : 'bg-gray-50'; ?> hover:bg-blue-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                                    <span class="text-blue-800 font-semibold"><?php echo substr($record['first_name'], 0, 1) . substr($record['last_name'], 0, 1); ?></span>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        ID: <?php echo $record['student_id']; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                Grade <?php echo $record['grade_year']; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <!-- View Mode -->
                                            <div class="view-mode">
                                                <?php
                                                switch ($record['status']) {
                                                    case 'present':
                                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800"><i class="fas fa-check mr-1"></i>Present</span>';
                                                        break;
                                                    case 'absent':
                                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800"><i class="fas fa-times mr-1"></i>Absent</span>';
                                                        break;
                                                    case 'excused':
                                                        echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800"><i class="fas fa-exclamation mr-1"></i>Excused</span>';
                                                        break;
                                                }
                                                ?>
                                            </div>
                                            
                                            <!-- Edit Mode -->
                                            <div class="edit-mode hidden">
                                                <div class="flex justify-center space-x-4">
                                                    <label class="flex items-center cursor-pointer attendance-option">
                                                        <input type="radio" name="attendance_status[<?php echo $record['record_id']; ?>]" value="present" <?php echo $record['status'] === 'present' ? 'checked' : ''; ?> class="sr-only attendance-radio">
                                                        <div class="w-4 h-4 border-2 border-gray-300 rounded-full mr-2 radio-circle"></div>
                                                        <span class="text-sm font-medium text-green-600">
                                                            <i class="fas fa-check mr-1"></i> Present
                                                        </span>
                                                    </label>
                                                    
                                                    <label class="flex items-center cursor-pointer attendance-option">
                                                        <input type="radio" name="attendance_status[<?php echo $record['record_id']; ?>]" value="absent" <?php echo $record['status'] === 'absent' ? 'checked' : ''; ?> class="sr-only attendance-radio">
                                                        <div class="w-4 h-4 border-2 border-gray-300 rounded-full mr-2 radio-circle"></div>
                                                        <span class="text-sm font-medium text-red-600">
                                                            <i class="fas fa-times mr-1"></i> Absent
                                                        </span>
                                                    </label>
                                                    
                                                    <label class="flex items-center cursor-pointer attendance-option">
                                                        <input type="radio" name="attendance_status[<?php echo $record['record_id']; ?>]" value="excused" <?php echo $record['status'] === 'excused' ? 'checked' : ''; ?> class="sr-only attendance-radio">
                                                        <div class="w-4 h-4 border-2 border-gray-300 rounded-full mr-2 radio-circle"></div>
                                                        <span class="text-sm font-medium text-yellow-600">
                                                            <i class="fas fa-exclamation mr-1"></i> Excused
                                                        </span>
                                                    </label>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <!-- View Mode -->
                                            <div class="view-mode">
                                                <div class="text-sm text-gray-900">
                                                    <?php echo !empty($record['notes']) ? htmlspecialchars($record['notes']) : '<span class="text-gray-400 italic">No notes</span>'; ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Edit Mode -->
                                            <div class="edit-mode hidden">
                                                <input type="text" name="attendance_notes[<?php echo $record['record_id']; ?>]" value="<?php echo htmlspecialchars($record['notes']); ?>" 
                                                       placeholder="Notes..."
                                                       class="w-full px-3 py-1 text-sm border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Edit Mode Actions -->
                        <div id="editModeActions" class="p-4 sm:p-6 bg-gray-50 border-t border-gray-200 flex flex-col sm:flex-row justify-between items-center space-y-4 sm:space-y-0 hidden">
                            <div class="flex items-center text-sm text-gray-600">
                                <i class="fas fa-info-circle mr-2"></i>
                                <span>Editing attendance for <?php echo count($attendance_records); ?> student<?php echo count($attendance_records) != 1 ? 's' : ''; ?></span>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-3 w-full sm:w-auto">
                                <button type="button" onclick="toggleEditMode()" class="w-full sm:w-auto px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-100 focus:ring-2 focus:ring-gray-500 transition-colors text-sm font-medium">
                                    <i class="fas fa-times mr-2"></i> Cancel
                                </button>
                                <button type="submit" name="update_attendance" class="w-full sm:w-auto px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 focus:ring-2 focus:ring-purple-500 transition-colors text-sm font-medium">
                                    <i class="fas fa-save mr-2"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </form>
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

            // Initialize radio button styling for existing checked options
            document.querySelectorAll('.attendance-radio:checked').forEach(function(radio) {
                updateRadioStyling(radio);
            });

            // Handle radio button styling
            document.querySelectorAll('.attendance-option').forEach(function(option) {
                option.addEventListener('click', function() {
                    const radio = this.querySelector('.attendance-radio');
                    
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
                    updateRadioStyling(radio);
                });
            });
        });

        function updateRadioStyling(radio) {
            const option = radio.closest('.attendance-option');
            const circle = option.querySelector('.radio-circle');
            
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
            } 
        }
        
        // Toggle edit mode
        function toggleEditMode() {
            const editModeControls = document.getElementById('editModeControls');
            const editModeActions = document.getElementById('editModeActions');
            const editButtonText = document.getElementById('editButtonText');
            const viewModeElements = document.querySelectorAll('.view-mode');
            const editModeElements = document.querySelectorAll('.edit-mode');
            
            if (editModeControls.classList.contains('hidden')) {
                // Switch to edit mode
                editModeControls.classList.remove('hidden');
                editModeActions.classList.remove('hidden');
                editButtonText.textContent = 'Cancel Editing';
                
                viewModeElements.forEach(element => {
                    element.classList.add('hidden');
                });
                
                editModeElements.forEach(element => {
                    element.classList.remove('hidden');
                });
            } else {
                // Switch back to view mode
                editModeControls.classList.add('hidden');
                editModeActions.classList.add('hidden');
                editButtonText.textContent = 'Edit Attendance';
                
                viewModeElements.forEach(element => {
                    element.classList.remove('hidden');
                });
                
                editModeElements.forEach(element => {
                    element.classList.add('hidden');
                });
            }
        }
        
        // Set all attendance to a specific status
        function setAllAttendance(status) {
            const radios = document.querySelectorAll(`input[type="radio"][value="${status}"]`);
            radios.forEach(radio => {
                radio.closest('.attendance-option').click();
            });
        }
    </script>
</body>
</html>