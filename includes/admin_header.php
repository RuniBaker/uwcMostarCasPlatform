<?php
// Determine current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- Top Navigation Bar - Lighter gray color -->
<nav class="bg-gray-200 text-gray-800 shadow-lg">
    <div class="mx-auto px-2">
        <div class="flex justify-between items-center h-16">
            <div class="flex items-center">
                <a href="/admin/dashboard" class="flex items-center">
                    <img src="../850.png" alt="UWC Mostar Logo" class="h-8">
                   
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- Main Content Wrapper -->
<div class="flex">
    <!-- Sidebar - Lighter gray color -->
    <div class="bg-gray-200 text-gray-800 w-64 py-4 min-h-screen">
        <div class="px-4 mb-6">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold">Admin Panel</h2>
                
                <!-- Toggle mobile sidebar button (only visible on mobile) -->
                <button id="closeSidebarBtn" class="lg:hidden text-gray-800 focus:outline-none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        
        <!-- Navigation Links -->
        <nav>
            <ul class="space-y-1">
                <li>
                    <a href="/admin/dashboard" class="block py-2 px-4 <?php echo $current_page == 'dashboard.php' ? 'bg-gray-300 text-indigo-700 font-medium' : 'hover:bg-gray-300 hover:text-indigo-600'; ?> rounded">
                        <i class="fas fa-tachometer-alt w-6 mr-2"></i> Dashboard
                    </a>
                </li>
                
                <li>
                    <a href="/admin/students" class="block py-2 px-4 <?php echo $current_page == 'students.php' ? 'bg-gray-300 text-indigo-700 font-medium' : 'hover:bg-gray-300 hover:text-indigo-600'; ?> rounded">
                        <i class="fas fa-user-graduate w-6 mr-2"></i> Students
                    </a>
                </li>
                
                <li>
                    <a href="/admin/cas_activities" class="block py-2 px-4 <?php echo $current_page == 'cas_activities.php' ? 'bg-gray-300 text-indigo-700 font-medium' : 'hover:bg-gray-300 hover:text-indigo-600'; ?> rounded">
                        <i class="fas fa-calendar-alt w-6 mr-2"></i> CAS Activities
                    </a>
                </li>
                
                <li>
                    <a href="/admin/users" class="block py-2 px-4 <?php echo $current_page == 'users.php' ? 'bg-gray-300 text-indigo-700 font-medium' : 'hover:bg-gray-300 hover:text-indigo-600'; ?> rounded">
                        <i class="fas fa-users w-6 mr-2"></i> Users/Leaders
                    </a>
                </li>
                
                <li>
                    <a href="/admin/attendance_report" class="block py-2 px-4 <?php echo $current_page == 'attendance_report.php' ? 'bg-gray-300 text-indigo-700 font-medium' : 'hover:bg-gray-300 hover:text-indigo-600'; ?> rounded">
                        <i class="fas fa-chart-bar w-6 mr-2"></i> Attendance Reports
                    </a>
                </li>
                
                <li>
                    <a href="/admin/manage_absences" class="block py-2 px-4 <?php echo $current_page == 'manage_absences.php' ? 'bg-gray-300 text-indigo-700 font-medium' : 'hover:bg-gray-300 hover:text-indigo-600'; ?> rounded">
                        <i class="fas fa-calendar-times w-6 mr-2"></i> Absence Requests
                        <?php if (isset($pendingRequestsCount) && $pendingRequestsCount > 0): ?>
                        <span class="inline-flex items-center justify-center px-2 py-1 ml-2 text-xs font-bold leading-none text-white bg-indigo-600 rounded-full">
                            <?php echo $pendingRequestsCount; ?>
                        </span>
                        <?php endif; ?>
                    </a>
                </li>
                
                <li>
                    <a href="/admin/activity_log" class="block py-2 px-4 <?php echo $current_page == 'activity_log.php' ? 'bg-gray-300 text-indigo-700 font-medium' : 'hover:bg-gray-300 hover:text-indigo-600'; ?> rounded">
                        <i class="fas fa-history w-6 mr-2"></i> Activity Log
                    </a>
                </li>
                
                <li>
                    <a href="/admin/year_transition" class="block py-2 px-4 <?php echo $current_page == 'year_transition.php' ? 'bg-gray-300 text-indigo-700 font-medium' : 'hover:bg-gray-300 hover:text-indigo-600'; ?> rounded">
                        <i class="fas fa-arrow-up w-6 mr-2"></i> Year Transition
                    </a>
                </li>
                
                <li class="mt-6 px-4">
                    <div class="border-t border-gray-300 pt-4">
                        <a href="../logout.php" class="block py-2 px-4 text-red-500 hover:bg-gray-300 rounded">
                            <i class="fas fa-sign-out-alt w-6 mr-2"></i> Logout
                        </a>
                    </div>
                </li>
            </ul>
        </nav>
    </div>
    
    <!-- Main Content Area -->
    <div class="flex-1 overflow-x-hidden overflow-y-auto">