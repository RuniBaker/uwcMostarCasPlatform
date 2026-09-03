<?php
// request_absence.php - The form accessible to students

// Start session
session_start();

// Database connection
require_once 'includes/db_connect.php';

// Message handling
$message = "";
$message_type = "";

// Get all active CAS activities
$stmt = $conn->prepare("
    SELECT 
        cas_id,
        cas_name,
        cas_type,
        cas_day,
        cas_time,
        cas_location
    FROM 
        cas_activities
    WHERE 
        is_active = 1
    ORDER BY 
        cas_name
");
$stmt->execute();
$result = $stmt->get_result();

$cas_activities = [];
while ($row = $result->fetch_assoc()) {
    $cas_activities[] = $row;
}
$stmt->close();

// Get students for selected CAS (if CAS is selected)
$students = [];
$selected_cas_id = isset($_POST['cas_id']) ? (int)$_POST['cas_id'] : (isset($_GET['cas_id']) ? (int)$_GET['cas_id'] : 0);

if ($selected_cas_id > 0) {
    $stmt = $conn->prepare("
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            s.grade_year
        FROM 
            students s
        INNER JOIN 
            student_cas_enrollment sce ON s.student_id = sce.student_id
        WHERE 
            s.is_active = 1 
            AND sce.cas_id = ? 
            AND sce.is_active = 1
        ORDER BY 
            s.last_name, s.first_name
    ");
    $stmt->bind_param("i", $selected_cas_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $cas_id = isset($_POST['cas_id']) ? (int)$_POST['cas_id'] : 0;
    $cas_name = '';
    $absence_date = isset($_POST['absence_date']) ? $_POST['absence_date'] : '';
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
    $staff_confirmer = isset($_POST['staff_confirmer']) ? trim($_POST['staff_confirmer']) : '';
    
    // Validate input
    $errors = [];
    
    if ($student_id <= 0) {
        $errors[] = "Please select a student.";
    }
    
    if ($cas_id <= 0) {
        $errors[] = "Please select a CAS activity.";
    } else {
        // Get CAS name
        $stmt = $conn->prepare("SELECT cas_name FROM cas_activities WHERE cas_id = ?");
        $stmt->bind_param("i", $cas_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $cas_name = $row['cas_name'];
        }
        $stmt->close();
    }
    
    if (empty($absence_date)) {
        $errors[] = "Please select the date of absence.";
    } else {
        // Validate date format
        $date_obj = DateTime::createFromFormat('Y-m-d', $absence_date);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $absence_date) {
            $errors[] = "Invalid date format. Please use the date picker.";
        }
    }
    
    if (empty($reason)) {
        $errors[] = "Please provide a reason for your absence.";
    }
    
    if (empty($staff_confirmer)) {
        $errors[] = "Please provide the name of a staff member who can confirm your reason.";
    }
    
    // Check if student is enrolled in the CAS activity
    $stmt = $conn->prepare("
        SELECT 1 FROM student_cas_enrollment 
        WHERE student_id = ? AND cas_id = ? AND is_active = 1
    ");
    $stmt->bind_param("ii", $student_id, $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $errors[] = "The selected student is not enrolled in this CAS activity.";
    }
    $stmt->close();
    
    // Check for a duplicate request: same student, same CAS, same date, already
    // pending or approved. This catches accidental double-submits (double-clicks,
    // resubmitted forms, flaky connections) without blocking someone who wants to
    // resubmit after an earlier request for that date was declined.
    if (empty($errors) && $student_id > 0 && $cas_id > 0 && !empty($absence_date)) {
        $stmt = $conn->prepare("
            SELECT status FROM absence_requests
            WHERE student_id = ? AND cas_id = ? AND absence_date = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->bind_param("iis", $student_id, $cas_id, $absence_date);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($existing = $result->fetch_assoc()) {
            if ($existing['status'] !== 'declined') {
                $errors[] = "You've already submitted a request for this CAS activity on this date (currently " . $existing['status'] . "). There's no need to submit it again.";
            }
        }
        $stmt->close();
    }
    
    // If validation passes, insert the request
    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO absence_requests 
            (student_id, cas_id, cas_name, absence_date, reason, staff_confirmer) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iissss", $student_id, $cas_id, $cas_name, $absence_date, $reason, $staff_confirmer);
        
        if ($stmt->execute()) {
            $message = "Your absence request has been submitted successfully. An administrator will review it.";
            $message_type = "success";
            
            // Clear form data except CAS selection
            $student_id = 0;
            $absence_date = '';
            $reason = '';
            $staff_confirmer = '';
        } else {
            $message = "Error submitting your request: " . $stmt->error;
            $message_type = "error";
        }
        
        $stmt->close();
    } else {
        $message = "Please correct the following errors:<br>" . implode("<br>", $errors);
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request CAS Absence - UWC Mostar CAS</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <!-- Date picker CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
    <style>
        /* Custom styles for better mobile experience */
        .form-input:focus {
            box-shadow: 0 0 0 3px rgba(147, 51, 234, 0.1);
        }
        
        /* Custom date picker styling */
        .flatpickr-calendar {
            font-size: 14px;
        }
        
        @media (max-width: 640px) {
            .flatpickr-calendar {
                font-size: 16px; /* Prevent zoom on iOS */
            }
        }

        /* Loading spinner styles */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="bg-gray-100 h-full flex flex-col">
    <!-- Navigation Header -->
    <nav class="bg-white shadow-lg border-b border-gray-200 flex-shrink-0">
        <div class="mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <!-- Left side: Logo -->
                <div class="flex items-center">
                    <a href="index.html" class="flex items-center hover:opacity-80 transition-opacity">
                        <img src="850.png" alt="UWC Mostar Logo" class="h-8 w-auto mr-3">
                    </a>
                </div>
                
                <!-- Right side: Home link -->
                <div class="flex items-center">
                    <a href="index.html" class="text-purple-600 hover:text-purple-800 transition-colors font-medium text-sm sm:text-base">
                        <i class="fas fa-home mr-1 sm:mr-2"></i>
                        <span class="hidden sm:inline">Home</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 w-full">
            <!-- Header -->
            <div class="text-center mb-6 sm:mb-8">
                <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 mb-2 sm:mb-4">Request CAS Absence</h1>
                <p class="text-gray-600 text-sm sm:text-base max-w-2xl mx-auto">
                    Fill out this form to request an excuse for a CAS absence. Your request will be reviewed by an administrator.
                </p>
            </div>
            
            <!-- Alert Messages -->
            <?php if (!empty($message)): ?>
            <div class="mb-6 sm:mb-8 mx-auto max-w-2xl">
                <div class="p-4 rounded-lg border-l-4 <?php echo $message_type === 'error' ? 'bg-red-50 border-red-400' : 'bg-green-50 border-green-400'; ?> shadow-md" role="alert">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <?php if ($message_type === 'error'): ?>
                                <i class="fas fa-exclamation-circle text-red-400 text-lg"></i>
                            <?php else: ?>
                                <i class="fas fa-check-circle text-green-400 text-lg"></i>
                            <?php endif; ?>
                        </div>
                        <div class="ml-3 flex-1">
                            <p class="text-sm font-medium <?php echo $message_type === 'error' ? 'text-red-800' : 'text-green-800'; ?>"><?php echo $message; ?></p>
                        </div>
                        <button type="button" class="flex-shrink-0 ml-4 p-1 hover:bg-black hover:bg-opacity-10 rounded transition-colors" onclick="this.parentElement.parentElement.remove()">
                            <i class="fas fa-times <?php echo $message_type === 'error' ? 'text-red-400' : 'text-green-400'; ?>"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Form Container -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden max-w-2xl mx-auto">
                <div class="bg-purple-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                    <h2 class="text-lg sm:text-xl font-bold flex items-center">
                        <i class="fas fa-file-alt mr-2 sm:mr-3"></i>
                        Absence Request Form
                    </h2>
                </div>
                
                <div class="p-4 sm:p-6 md:p-8">
                    <!-- Step 1: CAS Selection Form -->
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="space-y-6" id="cas-selection-form">
                        <!-- CAS Activity Selection (Always visible) -->
                        <div class="space-y-2">
                            <label for="cas_id" class="block text-sm font-medium text-gray-700">
                                <i class="fas fa-clipboard-list mr-2 text-gray-400"></i>
                                Step 1: Select Your CAS Activity *
                            </label>
                            <select id="cas_id" name="cas_id" required 
                                    class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm sm:text-base transition-colors"
                                    onchange="loadStudents()">
                                <option value="">Choose your CAS activity first</option>
                                <?php foreach ($cas_activities as $activity): ?>
                                <option value="<?php echo $activity['cas_id']; ?>" <?php echo $selected_cas_id == $activity['cas_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($activity['cas_name']); ?> 
                                    <span class="text-gray-500">
                                        (<?php echo ucfirst($activity['cas_day']); ?> at <?php echo date('g:i A', strtotime($activity['cas_time'])); ?>
                                        <?php if (!empty($activity['cas_location'])): ?>
                                            - <?php echo htmlspecialchars($activity['cas_location']); ?>
                                        <?php endif; ?>)
                                    </span>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($selected_cas_id == 0): ?>
                            <p class="text-xs text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                Please select your CAS activity to see the list of enrolled students
                            </p>
                            <?php endif; ?>
                        </div>
                    </form>

                    <!-- Step 2: Student Selection and Rest of Form (Only visible after CAS selection) -->
                    <?php if ($selected_cas_id > 0): ?>
                    <div id="student-form-section" class="mt-6 pt-6 border-t border-gray-200">
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="space-y-6" id="absence-request-form">
                            <!-- Hidden CAS ID -->
                            <input type="hidden" name="cas_id" value="<?php echo $selected_cas_id; ?>">
                            
                            <!-- Selected CAS Display -->
                            <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                                <h3 class="text-sm font-medium text-purple-800 mb-2">
                                    <i class="fas fa-check-circle mr-2"></i>
                                    Selected CAS Activity
                                </h3>
                                <?php 
                                $selected_cas = array_filter($cas_activities, function($activity) use ($selected_cas_id) {
                                    return $activity['cas_id'] == $selected_cas_id;
                                });
                                $selected_cas = reset($selected_cas);
                                ?>
                                <p class="text-purple-700">
                                    <strong><?php echo htmlspecialchars($selected_cas['cas_name']); ?></strong><br>
                                    <span class="text-sm">
                                        <?php echo ucfirst($selected_cas['cas_day']); ?> at <?php echo date('g:i A', strtotime($selected_cas['cas_time'])); ?>
                                        <?php if (!empty($selected_cas['cas_location'])): ?>
                                            - <?php echo htmlspecialchars($selected_cas['cas_location']); ?>
                                        <?php endif; ?>
                                    </span>
                                </p>
                                <button type="button" onclick="changeCAS()" class="mt-2 text-xs text-purple-600 hover:text-purple-800 underline">
                                    Change CAS Activity
                                </button>
                            </div>

                            <!-- Student Selection -->
                            <div class="space-y-2">
                                <label for="student_id" class="block text-sm font-medium text-gray-700">
                                    <i class="fas fa-user mr-2 text-gray-400"></i>
                                    Step 2: Find Your Name *
                                </label>
                                <?php if (empty($students)): ?>
                                    <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                        <div class="flex">
                                            <div class="flex-shrink-0">
                                                <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                                            </div>
                                            <div class="ml-3">
                                                <p class="text-sm text-yellow-700">
                                                    No students found enrolled in this CAS activity. Please contact your CAS coordinator if you believe this is an error.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <select id="student_id" name="student_id" required 
                                            class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm sm:text-base transition-colors">
                                        <option value="">Select your name from the list below</option>
                                        <?php foreach ($students as $student): ?>
                                        <option value="<?php echo $student['student_id']; ?>" <?php echo isset($student_id) && $student_id == $student['student_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> (<?php echo $student['grade_year']; ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-xs text-gray-500">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Only students enrolled in the selected CAS activity are shown (<?php echo count($students); ?> students)
                                    </p>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($students)): ?>
                            <!-- Date Selection -->
                            <div class="space-y-2">
                                <label for="absence_date" class="block text-sm font-medium text-gray-700">
                                    <i class="fas fa-calendar-alt mr-2 text-gray-400"></i>
                                    Step 3: Date of Absence *
                                </label>
                                <div class="relative">
                                    <input type="text" id="absence_date" name="absence_date" required 
                                           value="<?php echo isset($absence_date) ? $absence_date : ''; ?>"
                                           placeholder="Select date" 
                                           class="form-input w-full px-4 py-3 pr-12 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm sm:text-base transition-colors"
                                           readonly>
                                    <div class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 pointer-events-none">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    You can request an excuse for a past, current, or upcoming absence
                                </p>
                            </div>
                            
                            <!-- Reason for Absence -->
                            <div class="space-y-2">
                                <label for="reason" class="block text-sm font-medium text-gray-700">
                                    <i class="fas fa-comment-alt mr-2 text-gray-400"></i>
                                    Step 4: Reason for Absence *
                                </label>
                                <textarea id="reason" name="reason" rows="4" required 
                                          class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm sm:text-base transition-colors resize-none" 
                                          placeholder="Please explain why you missed your CAS activity (e.g., illness, family emergency, academic commitment, etc.)"><?php echo isset($reason) ? htmlspecialchars($reason) : ''; ?></textarea>
                                <p class="text-xs text-gray-500">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Be specific and honest about your reason for missing the activity
                                </p>
                            </div>
                            
                            <!-- Staff Confirmer -->
                            <div class="space-y-2">
                                <label for="staff_confirmer" class="block text-sm font-medium text-gray-700">
                                    <i class="fas fa-user-tie mr-2 text-gray-400"></i>
                                    Step 5: Staff Member Who Can Confirm *
                                </label>
                                <input type="text" id="staff_confirmer" name="staff_confirmer" required 
                                       value="<?php echo isset($staff_confirmer) ? htmlspecialchars($staff_confirmer) : ''; ?>"
                                       placeholder="Enter full name (e.g., Dr. Smith, Ms. Johnson)" 
                                       class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm sm:text-base transition-colors">
                                <p class="text-xs text-gray-500">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Enter the name of a teacher, tutor, nurse, or other staff member who can verify your absence reason
                                </p>
                            </div>
                            
                            <!-- Terms and Submit -->
                            <div class="border-t border-gray-200 pt-6 space-y-4">
                                <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded-r-lg">
                                    <div class="flex">
                                        <div class="flex-shrink-0">
                                            <i class="fas fa-info-circle text-blue-400"></i>
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm text-blue-700">
                                                <strong>Important:</strong> By submitting this form, you confirm that all information provided is true and accurate. 
                                                False information may result in disciplinary action.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="flex flex-col sm:flex-row justify-between items-center space-y-4 sm:space-y-0">
                                    <div class="text-xs sm:text-sm text-gray-500">
                                        <i class="fas fa-clock mr-1"></i>
                                        Your request will be reviewed within 24-48 hours
                                    </div>
                                    
                                    <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-3 w-full sm:w-auto">
                                        <a href="index.html" 
                                           class="w-full sm:w-auto px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 focus:ring-2 focus:ring-gray-500 transition-colors text-center text-sm font-medium">
                                            <i class="fas fa-times mr-2"></i>
                                            Cancel
                                        </a>
                                        <button type="submit" name="submit_request" value="1"
                                                class="w-full sm:w-auto px-6 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 focus:ring-2 focus:ring-purple-500 transition-colors text-center text-sm font-medium">
                                            <i class="fas fa-paper-plane mr-2"></i>
                                            Submit Request
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Additional Information -->
            <div class="max-w-2xl mx-auto mt-6 sm:mt-8">
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-r-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800 mb-2">Need Help?</h3>
                            <div class="text-sm text-yellow-700 space-y-1">
                                <p>• Contact your CAS Coordinator if you have questions about this form</p>
                                <p>• For urgent matters, visit the main office during school hours</p>
                                <p>• Email: <a href="mailto:cas@uwcmostar.ba" class="underline hover:no-underline">cas@uwcmostar.ba</a></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white py-6 sm:py-8 flex-shrink-0 mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-4 md:mb-0 text-center md:text-left">
                    <div class="flex items-center justify-center md:justify-start mb-2">
                        <img src="850.png" alt="UWC Mostar Logo" class="h-8 w-auto mr-3">
                        <span class="font-bold text-lg">UWC Mostar CAS</span>
                    </div>
                    <p class="text-sm text-gray-400">&copy; 2025 UWC Mostar CAS Tracking System. All rights reserved.</p>
                </div>
                
                <div class="flex flex-col sm:flex-row items-center space-y-2 sm:space-y-0 sm:space-x-6">
                    <div class="flex space-x-4">
                        <a href="https://www.uwcmostar.ba/" target="_blank" class="text-gray-300 hover:text-white transition-colors">
                            <i class="fas fa-globe text-lg"></i>
                        </a>
                        <a href="https://www.facebook.com/uwcmostar/" target="_blank" class="text-gray-300 hover:text-white transition-colors">
                            <i class="fab fa-facebook text-lg"></i>
                        </a>
                        <a href="https://www.instagram.com/uwcmostar/" target="_blank" class="text-gray-300 hover:text-white transition-colors">
                            <i class="fab fa-instagram text-lg"></i>
                        </a>
                    </div>
                    <div class="text-xs text-gray-400 text-center sm:text-right">
                        <p>Developed by Roni Baker UWCiM25</p>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- Date picker library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
    
    <script>
        // Load students when CAS is selected
        function loadStudents() {
            const casSelect = document.getElementById('cas_id');
            const selectedCasId = casSelect.value;
            
            if (selectedCasId) {
                // Submit the form to reload with students for selected CAS
                document.getElementById('cas-selection-form').submit();
            }
        }

        // Function to change CAS (go back to step 1)
        function changeCAS() {
            window.location.href = window.location.pathname;
        }

        // Initialize date picker
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('absence_date');
            if (dateInput) {
                flatpickr("#absence_date", {
                    dateFormat: "Y-m-d",
                    altInput: true,
                    altFormat: "F j, Y", // Human-readable format (e.g., January 1, 2025)
                    defaultDate: "today",
                    allowInput: false,
                    clickOpens: true,
                    locale: {
                        firstDayOfWeek: 1 // Start week on Monday
                    }
                });
            }
            
            // Form validation feedback
            const form = document.getElementById('absence-request-form');
            if (form) {
                const inputs = form.querySelectorAll('input, select, textarea');
                
                inputs.forEach(input => {
                    input.addEventListener('blur', function() {
                        if (this.hasAttribute('required') && !this.value.trim()) {
                            this.classList.add('border-red-300');
                            this.classList.remove('border-gray-300');
                        } else {
                            this.classList.remove('border-red-300');
                            this.classList.add('border-gray-300');
                        }
                    });
                    input.addEventListener('input', function() {
                        if (this.classList.contains('border-red-300') && this.value.trim()) {
                            this.classList.remove('border-red-300');
                            this.classList.add('border-gray-300');
                        }
                    });
                });
                
                // Form submission validation
                form.addEventListener('submit', function(e) {
                    let hasErrors = false;
                    
                    inputs.forEach(input => {
                        if (input.hasAttribute('required') && !input.value.trim()) {
                            input.classList.add('border-red-300');
                            input.classList.remove('border-gray-300');
                            hasErrors = true;
                        }
                    });
                    
                    if (hasErrors) {
                        e.preventDefault();
                        const firstError = form.querySelector('.border-red-300');
                        if (firstError) {
                            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            firstError.focus();
                        }
                    }
                });
            }

            // Auto-save CAS selection in session storage for better UX
            const casSelect = document.getElementById('cas_id');
            if (casSelect) {
                // Load saved CAS selection if available
                const savedCasId = sessionStorage.getItem('selected_cas_id');
                if (savedCasId && !casSelect.value) {
                    casSelect.value = savedCasId;
                }

                // Save CAS selection when changed
                casSelect.addEventListener('change', function() {
                    if (this.value) {
                        sessionStorage.setItem('selected_cas_id', this.value);
                    } else {
                        sessionStorage.removeItem('selected_cas_id');
                    }
                });
            }

            // Clear session storage when form is successfully submitted
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('success') === '1') {
                sessionStorage.removeItem('selected_cas_id');
            }

            // Enhanced form interaction for better mobile experience
            const selectElements = document.querySelectorAll('select');
            selectElements.forEach(select => {
                select.addEventListener('focus', function() {
                    this.style.transform = 'scale(1.02)';
                });
                
                select.addEventListener('blur', function() {
                    this.style.transform = 'scale(1)';
                });
            });

            // Add loading state to CAS selection
            if (casSelect) {
                casSelect.addEventListener('change', function() {
                    if (this.value) {
                        // Show loading indicator
                        const originalHTML = this.parentElement.innerHTML;
                        const loadingHTML = `
                            <div class="flex items-center justify-center p-4">
                                <div class="spinner mr-3"></div>
                                <span class="text-gray-600">Loading students for selected CAS...</span>
                            </div>
                        `;
                        
                        // Add loading state (will be replaced by page reload)
                        setTimeout(() => {
                            if (document.getElementById('cas_id').value === this.value) {
                                const container = document.querySelector('.max-w-2xl');
                                if (container && !document.getElementById('student-form-section')) {
                                    const loadingDiv = document.createElement('div');
                                    loadingDiv.innerHTML = loadingHTML;
                                    loadingDiv.className = 'mt-6 pt-6 border-t border-gray-200';
                                    container.querySelector('.p-4').appendChild(loadingDiv);
                                }
                            }
                        }, 100);
                    }
                });
            }

            // Smooth scroll to student section when it appears
            const studentFormSection = document.getElementById('student-form-section');
            if (studentFormSection) {
                // Scroll to student section on page load if CAS is selected
                setTimeout(() => {
                    studentFormSection.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'start' 
                    });
                }, 300);
            }

            // Add keyboard navigation improvements
            document.addEventListener('keydown', function(e) {
                // Allow Enter key to submit CAS selection
                if (e.key === 'Enter' && e.target.id === 'cas_id' && e.target.value) {
                    e.preventDefault();
                    loadStudents();
                }
                
                // Allow Escape key to clear selection and go back
                if (e.key === 'Escape') {
                    if (document.getElementById('student-form-section')) {
                        changeCAS();
                    }
                }
            });

            // Add visual feedback for required fields
            const requiredInputs = document.querySelectorAll('input[required], select[required], textarea[required]');
            requiredInputs.forEach(input => {
                const label = document.querySelector(`label[for="${input.id}"]`);
                if (label && !label.querySelector('.text-red-500')) {
                    const asterisk = label.querySelector('*') || document.createElement('span');
                    if (!asterisk.textContent.includes('*')) {
                        asterisk.textContent = ' *';
                        asterisk.className = 'text-red-500';
                        label.appendChild(asterisk);
                    }
                }
            });

            // Add progress indicator
            const steps = ['cas_id', 'student_id', 'absence_date', 'reason', 'staff_confirmer'];
            let currentStep = 0;
            
            function updateProgress() {
                let completedSteps = 0;
                steps.forEach((stepId, index) => {
                    const element = document.getElementById(stepId);
                    if (element && element.value && element.value.trim()) {
                        completedSteps = index + 1;
                    }
                });
                
                // Update progress visually if needed
                const progressElements = document.querySelectorAll('[data-step]');
                progressElements.forEach((el, index) => {
                    if (index < completedSteps) {
                        el.classList.add('completed');
                    } else {
                        el.classList.remove('completed');
                    }
                });
            }

            // Monitor form completion
            requiredInputs.forEach(input => {
                input.addEventListener('input', updateProgress);
                input.addEventListener('change', updateProgress);
            });

            // Initial progress update
            updateProgress();

            // Add tooltips for better UX
            const tooltipElements = document.querySelectorAll('[title]');
            tooltipElements.forEach(element => {
                element.addEventListener('mouseenter', function() {
                    // Could add custom tooltip implementation here
                });
            });

            // Improve accessibility
            const formSections = document.querySelectorAll('.space-y-2');
            formSections.forEach((section, index) => {
                section.setAttribute('role', 'group');
                section.setAttribute('aria-labelledby', `section-${index}`);
            });

            // Add form auto-save (optional - stores in localStorage)
            const formData = {};
            const autoSaveInputs = document.querySelectorAll('#absence-request-form input, #absence-request-form textarea, #absence-request-form select');
            
            function saveFormData() {
                autoSaveInputs.forEach(input => {
                    if (input.name && input.value) {
                        formData[input.name] = input.value;
                    }
                });
                localStorage.setItem('absence_form_draft', JSON.stringify(formData));
            }

            function loadFormData() {
                const savedData = localStorage.getItem('absence_form_draft');
                if (savedData) {
                    try {
                        const data = JSON.parse(savedData);
                        autoSaveInputs.forEach(input => {
                            if (input.name && data[input.name] && !input.value) {
                                input.value = data[input.name];
                            }
                        });
                    } catch (e) {
                        console.log('Error loading form data:', e);
                    }
                }
            }

            // Auto-save every 5 seconds
            if (autoSaveInputs.length > 0) {
                setInterval(saveFormData, 5000);
                
                // Load form data on page load
                loadFormData();
                
                // Clear saved data on successful submission
                const absenceForm = document.getElementById('absence-request-form');
                if (absenceForm) {
                    absenceForm.addEventListener('submit', function() {
                        localStorage.removeItem('absence_form_draft');
                    });
                }
            }

            // Add confirmation dialog for form submission
            const submitButton = document.querySelector('button[name="submit_request"]');
            if (submitButton) {
                submitButton.addEventListener('click', function(e) {
                    const studentName = document.getElementById('student_id');
                    const casName = document.querySelector('.bg-purple-50 strong');
                    const absenceDate = document.getElementById('absence_date');
                    
                    if (studentName && studentName.value && casName && absenceDate && absenceDate.value) {
                        const studentText = studentName.options[studentName.selectedIndex].text;
                        const casText = casName.textContent;
                        const dateText = absenceDate.value;
                        
                        const confirmMessage = `Please confirm your absence request:\n\nStudent: ${studentText}\nCAS Activity: ${casText}\nDate: ${dateText}\n\nAre you sure you want to submit this request?`;
                        
                        if (!confirm(confirmMessage)) {
                            e.preventDefault();
                        }
                    }
                });
            }
        });

        // Add utility functions for better UX
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 ${
                type === 'success' ? 'bg-green-500 text-white' : 
                type === 'error' ? 'bg-red-500 text-white' : 
                'bg-blue-500 text-white'
            }`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }

        // Add form validation helper
        function validateForm() {
            const requiredFields = document.querySelectorAll('#absence-request-form [required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value || !field.value.trim()) {
                    field.classList.add('border-red-300');
                    isValid = false;
                } else {
                    field.classList.remove('border-red-300');
                }
            });
            
            return isValid;
        }

        // Add responsive table helper for mobile
        function makeTablesResponsive() {
            const tables = document.querySelectorAll('table');
            tables.forEach(table => {
                if (!table.parentElement.classList.contains('overflow-x-auto')) {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'overflow-x-auto';
                    table.parentNode.insertBefore(wrapper, table);
                    wrapper.appendChild(table);
                }
            });
        }

        // Call responsive table function
        document.addEventListener('DOMContentLoaded', makeTablesResponsive);

        // Add print functionality
        function printForm() {
            window.print();
        }

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+Enter to submit form
            if (e.ctrlKey && e.key === 'Enter') {
                const submitBtn = document.querySelector('button[name="submit_request"]');
                if (submitBtn && !submitBtn.disabled) {
                    submitBtn.click();
                }
            }
            
            // Ctrl+R to reset form
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                if (confirm('Are you sure you want to reset the form?')) {
                    document.getElementById('absence-request-form')?.reset();
                    changeCAS();
                }
            }
        });
    </script>
</body>
</html>