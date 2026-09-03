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

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new CAS activity
    if (isset($_POST['add_cas'])) {
        $cas_name = trim($_POST['cas_name']);
        $cas_type = $_POST['cas_type'];
        $cas_description = trim($_POST['cas_description']);
        $cas_day = $_POST['cas_day'];
        $cas_time = $_POST['cas_time'];
        $cas_location = trim($_POST['cas_location']);
        $cas_leaders = isset($_POST['cas_leaders']) ? $_POST['cas_leaders'] : [];
        
        // Validate inputs
        if (empty($cas_name) || empty($cas_type) || empty($cas_day) || empty($cas_time)) {
            $message = "Required fields are missing.";
            $message_type = "error";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Insert new CAS activity
                $stmt = $conn->prepare("INSERT INTO cas_activities (cas_name, cas_type, cas_description, cas_day, cas_time, cas_location, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt->bind_param("ssssss", $cas_name, $cas_type, $cas_description, $cas_day, $cas_time, $cas_location);
                $stmt->execute();
                
                $cas_id = $conn->insert_id;
                
                // Associate CAS leaders if selected
                if (!empty($cas_leaders)) {
                    $leader_stmt = $conn->prepare("INSERT INTO cas_leaders (cas_id, user_id) VALUES (?, ?)");
                    
                    foreach ($cas_leaders as $user_id) {
                        $leader_stmt->bind_param("ii", $cas_id, $user_id);
                        $leader_stmt->execute();
                    }
                    
                    $leader_stmt->close();
                }
                
                // Commit transaction
                $conn->commit();
                
                $message = "CAS activity added successfully.";
                $message_type = "success";
            } catch (Exception $e) {
                // Rollback in case of error
                $conn->rollback();
                $message = "Error adding CAS activity: " . $e->getMessage();
                $message_type = "error";
            }
            
            $stmt->close();
        }
    }
    
    // Update existing CAS activity
    if (isset($_POST['update_cas'])) {
        $cas_id = $_POST['cas_id'];
        $cas_name = trim($_POST['cas_name']);
        $cas_type = $_POST['cas_type'];
        $cas_description = trim($_POST['cas_description']);
        $cas_day = $_POST['cas_day'];
        $cas_time = $_POST['cas_time'];
        $cas_location = trim($_POST['cas_location']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $cas_leaders = isset($_POST['cas_leaders']) ? $_POST['cas_leaders'] : [];
        
        // Validate inputs
        if (empty($cas_name) || empty($cas_type) || empty($cas_day) || empty($cas_time)) {
            $message = "Required fields are missing.";
            $message_type = "error";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Update CAS activity
                $stmt = $conn->prepare("UPDATE cas_activities SET cas_name = ?, cas_type = ?, cas_description = ?, cas_day = ?, cas_time = ?, cas_location = ?, is_active = ? WHERE cas_id = ?");
                $stmt->bind_param("ssssssii", $cas_name, $cas_type, $cas_description, $cas_day, $cas_time, $cas_location, $is_active, $cas_id);
                $stmt->execute();
                
                // Delete all existing leader associations
                $delete_stmt = $conn->prepare("DELETE FROM cas_leaders WHERE cas_id = ?");
                $delete_stmt->bind_param("i", $cas_id);
                $delete_stmt->execute();
                $delete_stmt->close();
                
                // Add new leader associations
                if (!empty($cas_leaders)) {
                    $leader_stmt = $conn->prepare("INSERT INTO cas_leaders (cas_id, user_id) VALUES (?, ?)");
                    
                    foreach ($cas_leaders as $user_id) {
                        $leader_stmt->bind_param("ii", $cas_id, $user_id);
                        $leader_stmt->execute();
                    }
                    
                    $leader_stmt->close();
                }
                
                // Commit transaction
                $conn->commit();
                
                $message = "CAS activity updated successfully.";
                $message_type = "success";
            } catch (Exception $e) {
                // Rollback in case of error
                $conn->rollback();
                $message = "Error updating CAS activity: " . $e->getMessage();
                $message_type = "error";
            }
            
            $stmt->close();
        }
    }
    
    // Delete CAS activity
    if (isset($_POST['delete_cas'])) {
        $cas_id = $_POST['cas_id'];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Delete attendance records for this CAS
            $stmt = $conn->prepare("DELETE ar FROM attendance_records ar 
                                   JOIN attendance_sessions ats ON ar.session_id = ats.session_id 
                                   WHERE ats.cas_id = ?");
            $stmt->bind_param("i", $cas_id);
            $stmt->execute();
            $stmt->close();
            
            // Delete attendance sessions
            $stmt = $conn->prepare("DELETE FROM attendance_sessions WHERE cas_id = ?");
            $stmt->bind_param("i", $cas_id);
            $stmt->execute();
            $stmt->close();
            
            // Delete student enrollments
            $stmt = $conn->prepare("DELETE FROM student_cas_enrollment WHERE cas_id = ?");
            $stmt->bind_param("i", $cas_id);
            $stmt->execute();
            $stmt->close();
            
            // Delete CAS leaders
            $stmt = $conn->prepare("DELETE FROM cas_leaders WHERE cas_id = ?");
            $stmt->bind_param("i", $cas_id);
            $stmt->execute();
            $stmt->close();
            
            // Finally delete the CAS activity
            $stmt = $conn->prepare("DELETE FROM cas_activities WHERE cas_id = ?");
            $stmt->bind_param("i", $cas_id);
            $stmt->execute();
            $stmt->close();
            
            // Commit transaction
            $conn->commit();
            
            $message = "CAS activity deleted successfully.";
            $message_type = "success";
        } catch (Exception $e) {
            // Rollback in case of error
            $conn->rollback();
            $message = "Error deleting CAS activity: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Handle GET actions
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$cas_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cas = null;

// Get CAS activity data for edit
if ($action === 'edit' && $cas_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM cas_activities WHERE cas_id = ?");
    $stmt->bind_param("i", $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $cas = $result->fetch_assoc();
    } else {
        $message = "CAS activity not found.";
        $message_type = "error";
        $action = 'list'; // Revert to list view
    }
    
    $stmt->close();
}

// Get CAS activity data for delete confirmation
if ($action === 'delete' && $cas_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM cas_activities WHERE cas_id = ?");
    $stmt->bind_param("i", $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $cas = $result->fetch_assoc();
    } else {
        $message = "CAS activity not found.";
        $message_type = "error";
        $action = 'list'; // Revert to list view
    }
    
    $stmt->close();
}

// Get all CAS activities for list view
$cas_activities = [];
$search = isset($_GET['search']) ? $_GET['search'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$day_filter = isset($_GET['day']) ? $_GET['day'] : 'all';
$active_filter = isset($_GET['active']) ? $_GET['active'] : 'all';

if ($action === 'list') {
    // Build query with filters
    $query = "SELECT * FROM cas_activities WHERE 1=1";
    $params = [];
    $types = "";
    
    if (!empty($search)) {
        $search_term = "%" . $search . "%";
        $query .= " AND (cas_name LIKE ? OR cas_location LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "ss";
    }
    
    if ($type_filter !== 'all') {
        $query .= " AND cas_type = ?";
        $params[] = $type_filter;
        $types .= "s";
    }
    
    if ($day_filter !== 'all') {
        $query .= " AND cas_day = ?";
        $params[] = $day_filter;
        $types .= "s";
    }
    
    if ($active_filter !== 'all') {
        $query .= " AND is_active = ?";
        $active_val = ($active_filter === 'active') ? 1 : 0;
        $params[] = $active_val;
        $types .= "i";
    }
    
    $query .= " ORDER BY cas_type, cas_name";
    
    $stmt = $conn->prepare($query);
    
    // Bind parameters if any
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $cas_activities[] = $row;
    }
    
    $stmt->close();
}

// Get all CAS leaders for the form dropdown
$cas_leaders = [];
$stmt = $conn->prepare("SELECT user_id, first_name, last_name FROM users WHERE user_status = 'cas_leader'");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $cas_leaders[] = $row;
}

$stmt->close();

// Get assigned leaders for a CAS activity
$assigned_leaders = [];
if (($action === 'edit' || $action === 'delete') && $cas_id > 0) {
    $stmt = $conn->prepare("
        SELECT cl.user_id, u.first_name, u.last_name
        FROM cas_leaders cl
        JOIN users u ON cl.user_id = u.user_id
        WHERE cl.cas_id = ?
    ");
    $stmt->bind_param("i", $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $assigned_leaders[] = $row;
    }
    
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAS Activities Management - UWC Mostar CAS</title>
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
                        <img src="../850.png" alt="UWC Mostar Logo" class="h-8 w-auto mr-3">
                        <div>
                            <h1 class="text-lg font-bold text-gray-900">Admin Panel</h1>
                        </div>
                    </div>
                </div>
                
                <nav class="px-4 space-y-1">
                    <a href="/admin/dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="/admin/students" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-user-graduate mr-3 text-lg"></i>
                        Students
                    </a>
                    
                    <a href="/admin/cas_activities" class="<?php echo basename($_SERVER['PHP_SELF']) == 'cas_activities.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-alt mr-3 text-lg"></i>
                        CAS Activities
                    </a>
                    
                    <a href="/admin/users" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-users mr-3 text-lg"></i>
                        Users/Leaders
                    </a>
                    
                    <a href="/admin/attendance_report" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance_report.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-chart-bar mr-3 text-lg"></i>
                        Attendance Reports
                    </a>
                    
                    <a href="/admin/manage_absences" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_absences.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-times mr-3 text-lg"></i>
                        Absence Requests
                    </a>
                    
                    <a href="/admin/activity_log" class="<?php echo basename($_SERVER['PHP_SELF']) == 'activity_log.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-history mr-3 text-lg"></i>
                        Activity Log
                    </a>
                    
                    <a href="/admin/year_transition" class="<?php echo basename($_SERVER['PHP_SELF']) == 'year_transition.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
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
                    <a href="/admin/dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="/admin/students" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-user-graduate mr-3 text-lg"></i>
                        Students
                    </a>
                    
                    <a href="/admin/cas_activities" class="<?php echo basename($_SERVER['PHP_SELF']) == 'cas_activities.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-alt mr-3 text-lg"></i>
                        CAS Activities
                    </a>
                    
                    <a href="/admin/users" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-users mr-3 text-lg"></i>
                        Users/Leaders
                    </a>
                    
                    <a href="/admin/attendance_report" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance_report.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-chart-bar mr-3 text-lg"></i>
                        Attendance Reports
                    </a>
                    
                    <a href="/admin/manage_absences" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_absences.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-times mr-3 text-lg"></i>
                        Absence Requests
                    </a>
                    
                    <a href="/admin/activity_log" class="<?php echo basename($_SERVER['PHP_SELF']) == 'activity_log.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-history mr-3 text-lg"></i>
                        Activity Log
                    </a>
                    
                    <a href="/admin/year_transition" class="<?php echo basename($_SERVER['PHP_SELF']) == 'year_transition.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
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
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 space-y-4 sm:space-y-0">
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">
                        <?php 
                        if ($action === 'add') echo "Add New CAS Activity";
                        elseif ($action === 'edit') echo "Edit CAS Activity";
                        elseif ($action === 'delete') echo "Delete CAS Activity";
                        else echo "CAS Activities Management";
                        ?>
                    </h1>
                    
                    <?php if ($action === 'list'): ?>
                    <a href="?action=add" class="w-full sm:w-auto bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded text-center">
                        <i class="fas fa-plus mr-2"></i> Add New CAS Activity
                    </a>
                    <?php else: ?>
                    <a href="cas_activities.php" class="w-full sm:w-auto bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded text-center">
                        <i class="fas fa-arrow-left mr-2"></i> Back to List
                    </a>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($message)): ?>
                <div class="mb-6 alert-dismissible <?php echo $message_type === 'error' ? 'bg-red-100 border-red-400 text-red-700' : 'bg-green-100 border-green-400 text-green-700'; ?> px-4 py-3 rounded relative border" role="alert">
                    <span class="block sm:inline"><?php echo $message; ?></span>
                    <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($action === 'list'): ?>
                <!-- Search and Filters -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 mb-6">
                    <form action="cas_activities.php" method="GET" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
                            <div class="sm:col-span-2 lg:col-span-2 xl:col-span-2">
                                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Search by name or location..." 
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                            </div>
                            <div>
                                <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select id="type" name="type" class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                                    <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="creativity" <?php echo $type_filter === 'creativity' ? 'selected' : ''; ?>>Creativity</option>
                                    <option value="activity" <?php echo $type_filter === 'activity' ? 'selected' : ''; ?>>Activity</option>
                                    <option value="service" <?php echo $type_filter === 'service' ? 'selected' : ''; ?>>Service</option>
                                </select>
                            </div>
                            <div>
                                <label for="day" class="block text-sm font-medium text-gray-700 mb-1">Day</label>
                                <select id="day" name="day" class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                                    <option value="all" <?php echo $day_filter === 'all' ? 'selected' : ''; ?>>All Days</option>
                                    <option value="monday" <?php echo $day_filter === 'monday' ? 'selected' : ''; ?>>Monday</option>
                                    <option value="tuesday" <?php echo $day_filter === 'tuesday' ? 'selected' : ''; ?>>Tuesday</option>
                                    <option value="wednesday" <?php echo $day_filter === 'wednesday' ? 'selected' : ''; ?>>Wednesday</option>
                                    <option value="thursday" <?php echo $day_filter === 'thursday' ? 'selected' : ''; ?>>Thursday</option>
                                    <option value="friday" <?php echo $day_filter === 'friday' ? 'selected' : ''; ?>>Friday</option>
                                    <option value="saturday" <?php echo $day_filter === 'saturday' ? 'selected' : ''; ?>>Saturday</option>
                                    <option value="sunday" <?php echo $day_filter === 'sunday' ? 'selected' : ''; ?>>Sunday</option>
                                </select>
                            </div>
                            <div class="flex flex-col lg:flex-row xl:flex-col space-y-2 lg:space-y-0 lg:space-x-2 xl:space-x-0 xl:space-y-2">
                                <div class="w-full">
                                    <label for="active" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                    <select id="active" name="active" class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                                        <option value="all" <?php echo $active_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                        <option value="active" <?php echo $active_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $active_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="flex space-x-2">
                                    <button type="submit" class="flex-1 lg:w-auto px-4 sm:px-6 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                                        <i class="fas fa-search mr-2"></i> Filter
                                    </button>
                                    <a href="cas_activities.php" class="flex-1 lg:w-auto px-3 sm:px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 text-center text-sm sm:text-base">
                                        <i class="fas fa-redo"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- CAS Activities List -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
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
                                        Schedule
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Location
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Leaders & Students
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" class="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($cas_activities)): ?>
                                <tr>
                                    <td colspan="7" class="px-3 sm:px-6 py-4 text-center text-gray-500">
                                        No CAS activities found. <?php if (!empty($search) || $type_filter !== 'all' || $day_filter !== 'all' || $active_filter !== 'all'): ?><a href="cas_activities.php" class="text-green-500 hover:underline">Clear filters</a><?php endif; ?>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($cas_activities as $activity): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 sm:px-6 py-4">
                                            <div class="flex items-center">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($activity['cas_name']); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                            <?php
                                            $type_class = '';
                                            switch($activity['cas_type']) {
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
                                            <span class="px-2 inline-flex text-xs sm:text-sm leading-5 font-semibold rounded-full <?php echo $type_class; ?>">
                                                <?php echo ucfirst($activity['cas_type']); ?>
                                            </span>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                            <div class="text-xs sm:text-sm text-gray-900">
                                                <?php echo ucfirst($activity['cas_day']); ?>
                                            </div>
                                            <div class="text-xs sm:text-sm text-gray-500">
                                                <?php 
                                                $time = new DateTime($activity['cas_time']);
                                                echo $time->format('g:i A'); 
                                                ?>
                                            </div>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4">
                                            <div class="text-xs sm:text-sm text-gray-900">
                                                <?php echo !empty($activity['cas_location']) ? htmlspecialchars($activity['cas_location']) : '<span class="text-gray-400">Not specified</span>'; ?>
                                            </div>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4">
                                            <?php
                                            // Get CAS leaders for this activity
                                            $stmt = $conn->prepare("
                                                SELECT u.first_name, u.last_name 
                                                FROM cas_leaders cl
                                                JOIN users u ON cl.user_id = u.user_id
                                                WHERE cl.cas_id = ?
                                            ");
                                            $stmt->bind_param("i", $activity['cas_id']);
                                            $stmt->execute();
                                            $leaders_result = $stmt->get_result();
                                            $leaders = [];
                                            
                                            while ($leader = $leaders_result->fetch_assoc()) {
                                                $leaders[] = $leader['first_name'] . ' ' . $leader['last_name'];
                                            }
                                            $stmt->close();
                                            
                                            // Get number of students enrolled
                                            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM student_cas_enrollment WHERE cas_id = ? AND is_active = 1");
                                            $stmt->bind_param("i", $activity['cas_id']);
                                            $stmt->execute();
                                            $students_result = $stmt->get_result();
                                            $student_count = $students_result->fetch_assoc()['count'];
                                            $stmt->close();
                                            
                                            // Display leaders and student count
                                            if (!empty($leaders)) {
                                                echo '<div class="text-xs sm:text-sm text-gray-900">Leaders: ' . implode(', ', $leaders) . '</div>';
                                            } else {
                                                echo '<div class="text-xs sm:text-sm text-gray-500">No leaders assigned</div>';
                                            }
                                            
                                            echo '<div class="text-xs sm:text-sm text-gray-900 mt-1">' . $student_count . ' student' . ($student_count != 1 ? 's' : '') . ' enrolled</div>';
                                            ?>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap">
                                            <?php if ($activity['is_active']): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                Active
                                            </span>
                                            <?php else: ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                Inactive
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="cas_details.php?id=<?php echo $activity['cas_id']; ?>" class="text-green-600 hover:text-green-900 mr-2 sm:mr-3">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="?action=edit&id=<?php echo $activity['cas_id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-2 sm:mr-3">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="?action=delete&id=<?php echo $activity['cas_id']; ?>" class="text-red-600 hover:text-red-900">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($action === 'add'): ?>
                <!-- Add CAS Activity Form -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
                    <h2 class="text-lg sm:text-xl font-semibold mb-6">Add New CAS Activity</h2>
                    
                    <form action="cas_activities.php" method="POST">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label for="cas_name" class="block text-sm font-medium text-gray-700 mb-1">Activity Name</label>
                                <input type="text" id="cas_name" name="cas_name" required
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="cas_type" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select id="cas_type" name="cas_type" required
                                        class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                                    <option value="creativity">Creativity</option>
                                    <option value="activity">Activity</option>
                                    <option value="service">Service</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="cas_day" class="block text-sm font-medium text-gray-700 mb-1">Day</label>
                                <select id="cas_day" name="cas_day" required
                                        class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                                    <option value="monday">Monday</option>
                                    <option value="tuesday">Tuesday</option>
                                    <option value="wednesday">Wednesday</option>
                                    <option value="thursday">Thursday</option>
                                    <option value="friday">Friday</option>
                                    <option value="saturday">Saturday</option>
                                    <option value="sunday">Sunday</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="cas_time" class="block text-sm font-medium text-gray-700 mb-1">Time</label>
                                <input type="time" id="cas_time" name="cas_time" required
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="cas_location" class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                                <input type="text" id="cas_location" name="cas_location"
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="cas_leaders" class="block text-sm font-medium text-gray-700 mb-1">CAS Leaders</label>
                                <select id="cas_leaders" name="cas_leaders[]" multiple
                                        class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base" size="4">
                                    <?php foreach ($cas_leaders as $leader): ?>
                                    <option value="<?php echo $leader['user_id']; ?>"><?php echo htmlspecialchars($leader['first_name'] . ' ' . $leader['last_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Hold Ctrl (or Cmd) to select multiple leaders</p>
                            </div>
                        </div>
                        
                        <div class="mt-4 sm:mt-6">
                            <label for="cas_description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea id="cas_description" name="cas_description" rows="4"
                                      class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base"></textarea>
                        </div>
                        
                        <div class="mt-6 flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-2">
                            <a href="cas_activities.php" class="w-full sm:w-auto bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded text-center">
                                Cancel
                            </a>
                            <button type="submit" name="add_cas" class="w-full sm:w-auto bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                                Add CAS Activity
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                
                <?php if ($action === 'edit' && $cas): ?>
                <!-- Edit CAS Activity Form -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
                    <h2 class="text-lg sm:text-xl font-semibold mb-6">Edit CAS Activity</h2>
                    
                    <form action="cas_activities.php" method="POST">
                        <input type="hidden" name="cas_id" value="<?php echo $cas['cas_id']; ?>">
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label for="cas_name" class="block text-sm font-medium text-gray-700 mb-1">Activity Name</label>
                                <input type="text" id="cas_name" name="cas_name" value="<?php echo htmlspecialchars($cas['cas_name']); ?>" required
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="cas_type" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select id="cas_type" name="cas_type" required
                                        class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                                    <option value="creativity" <?php echo $cas['cas_type'] === 'creativity' ? 'selected' : ''; ?>>Creativity</option>
                                    <option value="activity" <?php echo $cas['cas_type'] === 'activity' ? 'selected' : ''; ?>>Activity</option>
                                    <option value="service" <?php echo $cas['cas_type'] === 'service' ? 'selected' : ''; ?>>Service</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="cas_day" class="block text-sm font-medium text-gray-700 mb-1">Day</label>
                                <select id="cas_day" name="cas_day" required
                                        class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                                    <option value="monday" <?php echo $cas['cas_day'] === 'monday' ? 'selected' : ''; ?>>Monday</option>
                                    <option value="tuesday" <?php echo $cas['cas_day'] === 'tuesday' ? 'selected' : ''; ?>>Tuesday</option>
                                    <option value="wednesday" <?php echo $cas['cas_day'] === 'wednesday' ? 'selected' : ''; ?>>Wednesday</option>
                                    <option value="thursday" <?php echo $cas['cas_day'] === 'thursday' ? 'selected' : ''; ?>>Thursday</option>
                                    <option value="friday" <?php echo $cas['cas_day'] === 'friday' ? 'selected' : ''; ?>>Friday</option>
                                    <option value="saturday" <?php echo $cas['cas_day'] === 'saturday' ? 'selected' : ''; ?>>Saturday</option>
                                    <option value="sunday" <?php echo $cas['cas_day'] === 'sunday' ? 'selected' : ''; ?>>Sunday</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="cas_time" class="block text-sm font-medium text-gray-700 mb-1">Time</label>
                                <input type="time" id="cas_time" name="cas_time" value="<?php echo $cas['cas_time']; ?>" required
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="cas_location" class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                                <input type="text" id="cas_location" name="cas_location" value="<?php echo htmlspecialchars($cas['cas_location']); ?>"
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="cas_leaders" class="block text-sm font-medium text-gray-700 mb-1">CAS Leaders</label>
                                <select id="cas_leaders" name="cas_leaders[]" multiple
                                        class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base" size="4">
                                    <?php 
                                    // Get the user_ids of assigned leaders
                                    $assigned_leader_ids = array_map(function($leader) {
                                        return $leader['user_id'];
                                    }, $assigned_leaders);
                                    
                                    foreach ($cas_leaders as $leader): 
                                    ?>
                                    <option value="<?php echo $leader['user_id']; ?>" <?php echo in_array($leader['user_id'], $assigned_leader_ids) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($leader['first_name'] . ' ' . $leader['last_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Hold Ctrl (or Cmd) to select multiple leaders</p>
                            </div>
                        </div>
                        
                        <div class="mt-4 sm:mt-6">
                            <label for="cas_description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea id="cas_description" name="cas_description" rows="4"
                                      class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base"><?php echo htmlspecialchars($cas['cas_description']); ?></textarea>
                        </div>
                        
                        <div class="mt-4">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="is_active" <?php echo $cas['is_active'] ? 'checked' : ''; ?>
                                       class="form-checkbox h-5 w-5 text-green-600">
                                <span class="ml-2 text-gray-700">Active</span>
                            </label>
                        </div>
                        
                        <div class="mt-6 flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-2">
                            <a href="cas_activities.php" class="w-full sm:w-auto bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded text-center">
                                Cancel
                            </a>
                            <button type="submit" name="update_cas" class="w-full sm:w-auto bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                                Update CAS Activity
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                    </h1>
                    
                    
                </div>
                
                <?php if (!empty($message)): ?>
                <div class="mb-6 alert-dismissible <?php echo $message_type === 'error' ? 'bg-red-100 border-red-400 text-red-700' : 'bg-green-100 border-green-400 text-green-700'; ?> px-4 py-3 rounded relative border" role="alert">
                    <span class="block sm:inline"><?php echo $message; ?></span>
                    <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
                
             
                
                <?php if ($action === 'add'): ?>
                <!-- Add CAS Activity Form -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
                    <h2 class="text-lg sm:text-xl font-semibold mb-6">Add New CAS Activity</h2>
                    
                    <form action="cas_activities.php" method="POST">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label for="cas_name" class="block text-sm font-medium text-gray-700 mb-1">Activity Name</label>
                                <input type="text" id="cas_name" name="cas_name" required
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="cas_type" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select id="cas_type" name="cas_type" required
                                        class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                                    <option value="creativity">Creativity</option>
                                    <option value="activity">Activity</option>
                                    <option value="service">Service</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="cas_day" class="block text-sm font-medium text-gray-700 mb-1">Day</label>
                                <select id="cas_day" name="cas_day" required
                                        class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                                    <option value="monday">Monday</option>
                                    <option value="tuesday">Tuesday</option>
                                    <option value="wednesday">Wednesday</option>
                                    <option value="thursday">Thursday</option>
                                    <option value="friday">Friday</option>
                                    <option value="saturday">Saturday</option>
                                    <option value="sunday">Sunday</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="cas_time" class="block text-sm font-medium text-gray-700 mb-1">Time</label>
                                <input type="time" id="cas_time" name="cas_time" required
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="cas_location" class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                                <input type="text" id="cas_location" name="cas_location"
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                            </div>
                            
                            <div>
                                <label for="cas_leaders" class="block text-sm font-medium text-gray-700 mb-1">CAS Leaders</label>
                                <select id="cas_leaders" name="cas_leaders[]" multiple
                                        class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base" size="4">
                                    <?php foreach ($cas_leaders as $leader): ?>
                                    <option value="<?php echo $leader['user_id']; ?>"><?php echo htmlspecialchars($leader['first_name'] . ' ' . $leader['last_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Hold Ctrl (or Cmd) to select multiple leaders</p>
                            </div>
                        </div>
                        
                        <div class="mt-4 sm:mt-6">
                            <label for="cas_description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea id="cas_description" name="cas_description" rows="4"
                                      class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base"></textarea>
                        </div>
                        
                        <div class="mt-6 flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-2">
                            <a href="cas_activities.php" class="w-full sm:w-auto bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded text-center">
                                Cancel
                            </a>
                            <button type="submit" name="add_cas" class="w-full sm:w-auto bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                                Add CAS Activity
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                
                
                <?php if ($action === 'delete' && $cas): ?>
                <!-- Delete CAS Activity Confirmation -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
                    <h2 class="text-lg sm:text-xl font-semibold mb-6 text-red-600">Delete CAS Activity</h2>
                    
                    <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-red-700">
                                    <strong>Warning:</strong> This action cannot be undone. This will permanently delete the CAS activity, 
                                    along with all associated attendance records and student enrollments.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gray-100 p-4 rounded-md mb-6">
                        <h3 class="font-semibold text-sm sm:text-base">CAS Activity Information</h3>
                        <div class="mt-2 space-y-1 text-sm sm:text-base">
                            <p><strong>Name:</strong> <?php echo htmlspecialchars($cas['cas_name']); ?></p>
                            <p><strong>Type:</strong> <?php echo ucfirst($cas['cas_type']); ?></p>
                            <p><strong>Schedule:</strong> <?php echo ucfirst($cas['cas_day']) . ' at ' . (new DateTime($cas['cas_time']))->format('g:i A'); ?></p>
                            <p><strong>Location:</strong> <?php echo !empty($cas['cas_location']) ? htmlspecialchars($cas['cas_location']) : 'Not specified'; ?></p>
                            <p><strong>Status:</strong> <?php echo $cas['is_active'] ? 'Active' : 'Inactive'; ?></p>
                            
                            <?php
                            // Check CAS leaders
                            if (!empty($assigned_leaders)) {
                                echo '<div class="mt-3"><strong>Leaders:</strong> ';
                                $leader_names = array_map(function($leader) {
                                    return $leader['first_name'] . ' ' . $leader['last_name'];
                                }, $assigned_leaders);
                                echo implode(', ', $leader_names);
                                echo '</div>';
                            }
                            
                            // Check student enrollments
                            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM student_cas_enrollment WHERE cas_id = ?");
                            $stmt->bind_param("i", $cas_id);
                            $stmt->execute();
                            $enroll_result = $stmt->get_result();
                            $enroll_count = $enroll_result->fetch_assoc()['count'];
                            $stmt->close();
                            
                            if ($enroll_count > 0) {
                                echo '<div class="mt-1">This CAS activity has ' . $enroll_count . ' student enrollments that will be deleted.</div>';
                            }
                            
                            // Check attendance sessions
                            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM attendance_sessions WHERE cas_id = ?");
                            $stmt->bind_param("i", $cas_id);
                            $stmt->execute();
                            $session_result = $stmt->get_result();
                            $session_count = $session_result->fetch_assoc()['count'];
                            $stmt->close();
                            
                            if ($session_count > 0) {
                                echo '<div class="mt-1">This CAS activity has ' . $session_count . ' attendance sessions that will be deleted.</div>';
                                
                                // Check attendance records
                                $stmt = $conn->prepare("
                                    SELECT COUNT(*) as count 
                                    FROM attendance_records ar
                                    JOIN attendance_sessions ats ON ar.session_id = ats.session_id
                                    WHERE ats.cas_id = ?
                                ");
                                $stmt->bind_param("i", $cas_id);
                                $stmt->execute();
                                $record_result = $stmt->get_result();
                                $record_count = $record_result->fetch_assoc()['count'];
                                $stmt->close();
                                
                                if ($record_count > 0) {
                                    echo '<div class="mt-1">This will also delete ' . $record_count . ' individual attendance records.</div>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                    
                    <form action="cas_activities.php" method="POST">
                        <input type="hidden" name="cas_id" value="<?php echo $cas['cas_id']; ?>">
                        
                        <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-2">
                            <a href="cas_activities.php" class="w-full sm:w-auto bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded text-center">
                                Cancel
                            </a>
                            <button type="submit" name="delete_cas" class="w-full sm:w-auto bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                                Delete CAS Activity
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
       <!-- Footer -->
       <footer class="bg-gray-800 text-white py-4 sm:py-6 mt-auto md:ml-64">
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

            // Add confirmation for delete links
            const deleteLinks = document.querySelectorAll('a[href*="action=delete"]');
            deleteLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (!confirm('Are you sure you want to proceed to the delete confirmation page?')) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>