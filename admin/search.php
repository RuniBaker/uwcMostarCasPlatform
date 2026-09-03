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

// Get search parameters
$query = isset($_GET['query']) ? trim($_GET['query']) : '';
$type = isset($_GET['type']) ? $_GET['type'] : 'all';

// Validate type parameter
$valid_types = ['all', 'student', 'cas', 'user'];
if (!in_array($type, $valid_types)) {
    $type = 'all';
}

// Initialize result arrays
$students = [];
$cas_activities = [];
$users = [];

// Only perform search if a query is provided
if (!empty($query)) {
    // Prepare search term for LIKE queries
    $search_term = "%" . $query . "%";
    
    // Search students if requested
    if ($type === 'all' || $type === 'student') {
        $stmt = $conn->prepare("
            SELECT 
                s.student_id, 
                s.first_name, 
                s.last_name, 
                s.email, 
                s.grade_year,
                s.is_active,
                COUNT(DISTINCT sce.cas_id) AS cas_count
            FROM 
                students s
            LEFT JOIN 
                student_cas_enrollment sce ON s.student_id = sce.student_id AND sce.is_active = 1
            WHERE 
                (s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ?)
            GROUP BY 
                s.student_id, s.first_name, s.last_name, s.email, s.grade_year, s.is_active
            ORDER BY 
                s.is_active DESC, s.last_name, s.first_name
            LIMIT 20
        ");
        $stmt->bind_param("sss", $search_term, $search_term, $search_term);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
        
        $stmt->close();
    }
    
    // Search CAS activities if requested
    if ($type === 'all' || $type === 'cas') {
        $stmt = $conn->prepare("
            SELECT 
                ca.cas_id, 
                ca.cas_name, 
                ca.cas_type, 
                ca.cas_day,
                ca.cas_time,
                ca.is_active,
                COUNT(DISTINCT sce.student_id) AS student_count
            FROM 
                cas_activities ca
            LEFT JOIN 
                student_cas_enrollment sce ON ca.cas_id = sce.cas_id AND sce.is_active = 1
            WHERE 
                (ca.cas_name LIKE ? OR ca.cas_description LIKE ? OR ca.cas_location LIKE ?)
            GROUP BY 
                ca.cas_id, ca.cas_name, ca.cas_type, ca.cas_day, ca.cas_time, ca.is_active
            ORDER BY 
                ca.is_active DESC, ca.cas_type, ca.cas_name
            LIMIT 20
        ");
        $stmt->bind_param("sss", $search_term, $search_term, $search_term);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $cas_activities[] = $row;
        }
        
        $stmt->close();
    }
    
    // Search users if requested
    if ($type === 'all' || $type === 'user') {
        $stmt = $conn->prepare("
            SELECT 
                u.user_id, 
                u.username, 
                u.first_name, 
                u.last_name, 
                u.email, 
                u.user_status,
                s.first_name as student_first_name,
                s.last_name as student_last_name
            FROM 
                users u
            LEFT JOIN 
                students s ON u.student_id = s.student_id
            WHERE 
                (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)
            ORDER BY 
                u.user_status, u.last_name, u.first_name
            LIMIT 20
        ");
        $stmt->bind_param("ssss", $search_term, $search_term, $search_term, $search_term);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Results - UWC Mostar CAS</title>
    <link rel="icon" type="image/x-icon" href="../tab.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <?php include '../includes/admin_header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Search Results</h1>
            
            <a href="dashboard.php" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
            </a>
        </div>
        
        <!-- Search Form -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <form action="search.php" method="GET" class="flex flex-col md:flex-row space-y-4 md:space-y-0 md:space-x-4">
                <div class="flex-grow">
                    <label for="query" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                    <input type="text" id="query" name="query" value="<?php echo htmlspecialchars($query); ?>" 
                           placeholder="Search for students, CAS activities, or users..." 
                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Search In</label>
                    <select id="type" name="type" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>All Categories</option>
                        <option value="student" <?php echo $type === 'student' ? 'selected' : ''; ?>>Students</option>
                        <option value="cas" <?php echo $type === 'cas' ? 'selected' : ''; ?>>CAS Activities</option>
                        <option value="user" <?php echo $type === 'user' ? 'selected' : ''; ?>>Users & Leaders</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <i class="fas fa-search mr-2"></i> Search
                    </button>
                </div>
            </form>
        </div>
        
        <?php if (empty($query)): ?>
        <!-- No query provided -->
        <div class="bg-white rounded-lg shadow-md p-6 text-center">
            <i class="fas fa-search text-4xl text-gray-400 mb-4"></i>
            <p class="text-gray-600">Enter a search term to find students, CAS activities, or users.</p>
        </div>
        <?php else: ?>
            <?php if (empty($students) && empty($cas_activities) && empty($users)): ?>
            <!-- No results found -->
            <div class="bg-white rounded-lg shadow-md p-6 text-center">
                <i class="fas fa-search text-4xl text-gray-400 mb-4"></i>
                <p class="text-gray-600">No results found for "<?php echo htmlspecialchars($query); ?>".</p>
                <p class="text-gray-500 text-sm mt-2">Try using different keywords or search in a specific category.</p>
            </div>
            <?php else: ?>
                <!-- Results found - display them by category -->
                
                <!-- Students Results -->
                <?php if (!empty($students) && ($type === 'all' || $type === 'student')): ?>
                <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                    <div class="bg-blue-600 text-white px-6 py-4 flex justify-between items-center">
                        <h2 class="text-xl font-bold">Students (<?php echo count($students); ?>)</h2>
                        
                        <?php if ($type === 'all'): ?>
                        <a href="search.php?query=<?php echo urlencode($query); ?>&type=student" class="text-blue-100 hover:text-white text-sm">
                            View All Student Results <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Student
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Email
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Year
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        CAS Activities
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($students as $student): ?>
                                <tr class="hover:bg-gray-50 <?php echo !$student['is_active'] ? 'bg-gray-50 text-gray-400' : ''; ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                                <span class="text-blue-800 font-semibold"><?php echo substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1); ?></span>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium <?php echo !$student['is_active'] ? 'text-gray-400' : 'text-gray-900'; ?>">
                                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                </div>
                                                <div class="text-sm <?php echo !$student['is_active'] ? 'text-gray-400' : 'text-gray-500'; ?>">
                                                    ID: <?php echo $student['student_id']; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm <?php echo !$student['is_active'] ? 'text-gray-400' : 'text-gray-900'; ?>">
                                            <?php echo htmlspecialchars($student['email']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo !$student['is_active'] ? 'bg-gray-100 text-gray-600' : 'bg-blue-100 text-blue-800'; ?>">
                                            <?php echo $student['grade_year']; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($student['is_active']): ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            Active
                                        </span>
                                        <?php else: ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                            Inactive
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm <?php echo !$student['is_active'] ? 'text-gray-400' : 'text-gray-900'; ?>">
                                            <?php echo $student['cas_count']; ?> CAS activities
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <a href="student_details.php?id=<?php echo $student['student_id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="students.php?action=edit&id=<?php echo $student['student_id']; ?>" class="text-indigo-600 hover:text-indigo-900">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- CAS Activities Results -->
                <?php if (!empty($cas_activities) && ($type === 'all' || $type === 'cas')): ?>
                <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                    <div class="bg-green-600 text-white px-6 py-4 flex justify-between items-center">
                        <h2 class="text-xl font-bold">CAS Activities (<?php echo count($cas_activities); ?>)</h2>
                        
                        <?php if ($type === 'all'): ?>
                        <a href="search.php?query=<?php echo urlencode($query); ?>&type=cas" class="text-green-100 hover:text-white text-sm">
                            View All CAS Activity Results <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        CAS Activity
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Type
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Schedule
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Students
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($cas_activities as $activity): ?>
                                <tr class="hover:bg-gray-50 <?php echo !$activity['is_active'] ? 'bg-gray-50 text-gray-400' : ''; ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium <?php echo !$activity['is_active'] ? 'text-gray-400' : 'text-gray-900'; ?>">
                                            <?php echo htmlspecialchars($activity['cas_name']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $type_class = '';
                                        switch($activity['cas_type']) {
                                            case 'creativity':
                                                $type_class = !$activity['is_active'] ? 'bg-gray-100 text-gray-600' : 'bg-purple-100 text-purple-800';
                                                break;
                                            case 'activity':
                                                $type_class = !$activity['is_active'] ? 'bg-gray-100 text-gray-600' : 'bg-blue-100 text-blue-800';
                                                break;
                                            case 'service':
                                                $type_class = !$activity['is_active'] ? 'bg-gray-100 text-gray-600' : 'bg-yellow-100 text-yellow-800';
                                                break;
                                        }
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $type_class; ?>">
                                            <?php echo ucfirst($activity['cas_type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm <?php echo !$activity['is_active'] ? 'text-gray-400' : 'text-gray-900'; ?>">
                                            <?php echo ucfirst($activity['cas_day']); ?>
                                        </div>
                                        <div class="text-sm <?php echo !$activity['is_active'] ? 'text-gray-400' : 'text-gray-500'; ?>">
                                            <?php echo date('g:i A', strtotime($activity['cas_time'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
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
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm <?php echo !$activity['is_active'] ? 'text-gray-400' : 'text-gray-900'; ?>">
                                            <?php echo $activity['student_count']; ?> enrolled
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <a href="cas_details.php?id=<?php echo $activity['cas_id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="cas_activities.php?action=edit&id=<?php echo $activity['cas_id']; ?>" class="text-indigo-600 hover:text-indigo-900">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Users Results -->
                <?php if (!empty($users) && ($type === 'all' || $type === 'user')): ?>
                <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                    <div class="bg-purple-600 text-white px-6 py-4 flex justify-between items-center">
                        <h2 class="text-xl font-bold">Users & Leaders (<?php echo count($users); ?>)</h2>
                        
                        <?php if ($type === 'all'): ?>
                        <a href="search.php?query=<?php echo urlencode($query); ?>&type=user" class="text-purple-100 hover:text-white text-sm">
                            View All User Results <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        User
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Username
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Email
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Role
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Student Link
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($users as $user): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 <?php echo $user['user_status'] === 'admin' ? 'bg-red-100' : 'bg-purple-100'; ?> rounded-full flex items-center justify-center">
                                                <span class="<?php echo $user['user_status'] === 'admin' ? 'text-red-800' : 'text-purple-800'; ?> font-semibold"><?php echo substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1); ?></span>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($user['username']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($user['email']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($user['user_status'] === 'admin'): ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                            Administrator
                                        </span>
                                        <?php else: ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800">
                                            CAS Leader
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if (!empty($user['student_first_name'])): ?>
                                        <div class="text-sm text-gray-900">
                                            <?php echo htmlspecialchars($user['student_first_name'] . ' ' . $user['student_last_name']); ?>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-sm text-gray-500">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <a href="users.php?action=edit&id=<?php echo $user['user_id']; ?>" class="text-indigo-600 hover:text-indigo-900">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php include '../includes/admin_footer.php'; ?>
    
    <script>
        // Add any page-specific JavaScript here
    </script>
</body>
</html>