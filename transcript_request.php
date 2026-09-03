<?php
// transcript_request.php - Self-service page: a student selects their own
// name and gets their full CAS attendance breakdown emailed to them.
//
// Security model: no login is required, but the breakdown itself is never
// shown on screen - it's only ever sent to the email address already on
// file for the selected student. Anyone can trigger a send for any name,
// but only the real owner of that inbox can ever see the actual content.
// This is the same pattern as a "forgot password" email flow.

session_start();

require_once 'includes/db_connect.php';
require_once 'includes/mailer.php';

$message = "";
$message_type = "";

// Get all active students for the dropdown
$stmt = $conn->prepare("
    SELECT student_id, first_name, last_name, grade_year
    FROM students
    WHERE is_active = 1
    ORDER BY last_name, first_name
");
$stmt->execute();
$result = $stmt->get_result();

$students = [];
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_transcript'])) {
    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    
    if ($student_id <= 0) {
        $message = "Please select your name from the list.";
        $message_type = "error";
    } else {
        // Confirm this is a real, active student (defense against a
        // tampered form value) and get their email/name
        $stmt = $conn->prepare("SELECT student_id, email, first_name FROM students WHERE student_id = ? AND is_active = 1");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $student = $result->fetch_assoc();
        $stmt->close();
        
        if (!$student) {
            $message = "That student could not be found. Please select your name from the list.";
            $message_type = "error";
        } elseif (empty($student['email'])) {
            $message = "No email address is on file for this account. Please contact your CAS Coordinator.";
            $message_type = "error";
        } elseif (is_transcript_rate_limited($student_id)) {
            $message = "A transcript was already requested for this account recently. Please wait a few minutes before requesting another.";
            $message_type = "error";
        } else {
            // Build the attendance breakdown: per-CAS counts of present/absent/excused
            $stmt = $conn->prepare("
                SELECT ca.cas_name, ar.status, COUNT(*) as cnt
                FROM attendance_records ar
                JOIN attendance_sessions ats ON ar.session_id = ats.session_id
                JOIN cas_activities ca ON ats.cas_id = ca.cas_id
                WHERE ar.student_id = ?
                GROUP BY ca.cas_name, ar.status
                ORDER BY ca.cas_name
            ");
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $breakdown_result = $stmt->get_result();
            
            $breakdown = [];
            while ($row = $breakdown_result->fetch_assoc()) {
                if (!isset($breakdown[$row['cas_name']])) {
                    $breakdown[$row['cas_name']] = ['present' => 0, 'absent' => 0, 'excused' => 0];
                }
                $breakdown[$row['cas_name']][$row['status']] = (int)$row['cnt'];
            }
            $stmt->close();
            
            if (empty($breakdown)) {
                $email_body = "
                    <p>Hi " . htmlspecialchars($student['first_name']) . ",</p>
                    <p>No attendance records were found for your account yet.</p>
                    <p style='color: #888; font-size: 13px;'>UWC Mostar CAS Platform</p>
                ";
            } else {
                $rows_html = '';
                $totals = ['present' => 0, 'absent' => 0, 'excused' => 0];
                
                foreach ($breakdown as $cas_name => $counts) {
                    $rows_html .= "
                        <tr>
                            <td style='padding: 6px 12px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($cas_name) . "</td>
                            <td style='padding: 6px 12px; border-bottom: 1px solid #eee; text-align: center; color: #16a34a;'>" . $counts['present'] . "</td>
                            <td style='padding: 6px 12px; border-bottom: 1px solid #eee; text-align: center; color: #dc2626;'>" . $counts['absent'] . "</td>
                            <td style='padding: 6px 12px; border-bottom: 1px solid #eee; text-align: center; color: #ca8a04;'>" . $counts['excused'] . "</td>
                        </tr>
                    ";
                    $totals['present'] += $counts['present'];
                    $totals['absent'] += $counts['absent'];
                    $totals['excused'] += $counts['excused'];
                }
                
                $email_body = "
                    <p>Hi " . htmlspecialchars($student['first_name']) . ",</p>
                    <p>Here is your current CAS attendance breakdown:</p>
                    <table style='border-collapse: collapse; width: 100%; max-width: 500px;'>
                        <tr style='background: #f5f5f5;'>
                            <th style='padding: 6px 12px; text-align: left;'>CAS Activity</th>
                            <th style='padding: 6px 12px; text-align: center;'>Present</th>
                            <th style='padding: 6px 12px; text-align: center;'>Absent</th>
                            <th style='padding: 6px 12px; text-align: center;'>Excused</th>
                        </tr>
                        " . $rows_html . "
                        <tr style='font-weight: bold;'>
                            <td style='padding: 6px 12px;'>Total</td>
                            <td style='padding: 6px 12px; text-align: center;'>" . $totals['present'] . "</td>
                            <td style='padding: 6px 12px; text-align: center;'>" . $totals['absent'] . "</td>
                            <td style='padding: 6px 12px; text-align: center;'>" . $totals['excused'] . "</td>
                        </tr>
                    </table>
                    <p style='color: #888; font-size: 13px; margin-top: 16px;'>UWC Mostar CAS Platform</p>
                ";
            }
            
            $email_subject = "Your CAS Attendance Transcript";
            $sent = send_email($student['email'], $email_subject, $email_body, null, 'transcript', $student_id);
            
            if ($sent) {
                $message = "Your attendance transcript has been sent to the email address on file.";
                $message_type = "success";
            } else {
                $message = "Something went wrong sending the email. Please try again, or contact your CAS Coordinator if this keeps happening.";
                $message_type = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request CAS Transcript - UWC Mostar CAS</title>
    <link rel="icon" type="image/x-icon" href="tab.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 h-full flex flex-col">
    <!-- Navigation Header -->
    <nav class="bg-white shadow-lg border-b border-gray-200 flex-shrink-0">
        <div class="mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <a href="index.html" class="flex items-center hover:opacity-80 transition-opacity">
                        <img src="850.png" alt="UWC Mostar Logo" class="h-8 w-auto mr-3">
                    </a>
                </div>
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
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 w-full">
            <div class="text-center mb-6 sm:mb-8">
                <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 mb-2 sm:mb-4">Request Your CAS Transcript</h1>
                <p class="text-gray-600 text-sm sm:text-base max-w-2xl mx-auto">
                    Select your name below to receive a full breakdown of your CAS attendance by email.
                </p>
            </div>
            
            <?php if (!empty($message)): ?>
            <div class="mb-6 sm:mb-8">
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
                            <p class="text-sm font-medium <?php echo $message_type === 'error' ? 'text-red-800' : 'text-green-800'; ?>"><?php echo htmlspecialchars($message); ?></p>
                        </div>
                        <button type="button" class="flex-shrink-0 ml-4 p-1 hover:bg-black hover:bg-opacity-10 rounded transition-colors" onclick="this.parentElement.parentElement.remove()">
                            <i class="fas fa-times <?php echo $message_type === 'error' ? 'text-red-400' : 'text-green-400'; ?>"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="bg-purple-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                    <h2 class="text-lg sm:text-xl font-bold flex items-center">
                        <i class="fas fa-envelope mr-2 sm:mr-3"></i>
                        Transcript Request
                    </h2>
                </div>
                
                <div class="p-4 sm:p-6 md:p-8">
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="space-y-6">
                        <div class="space-y-2">
                            <label for="student_id" class="block text-sm font-medium text-gray-700">
                                <i class="fas fa-user mr-2 text-gray-400"></i>
                                Select Your Name *
                            </label>
                            <select id="student_id" name="student_id" required 
                                    class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm sm:text-base transition-colors">
                                <option value="">Choose your name</option>
                                <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['student_id']; ?>">
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> (<?php echo htmlspecialchars($student['grade_year']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                Your transcript will only be sent to the email address already on file for your account - it is never shown here.
                            </p>
                        </div>
                        
                        <div class="pt-2">
                            <button type="submit" name="request_transcript" value="1"
                                    class="w-full sm:w-auto px-6 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 focus:ring-2 focus:ring-purple-500 transition-colors text-sm font-medium">
                                <i class="fas fa-paper-plane mr-2"></i>
                                Email Me My Transcript
                            </button>
                        </div>
                    </form>
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
                <div class="text-xs text-gray-400 text-center md:text-right">
                    <p>Developed by Roni Baker UWCiM25</p>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>
