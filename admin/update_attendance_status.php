<?php
// update_attendance_status.php - Handle AJAX requests to update attendance status
session_start();

// Security check - ensure user is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_status'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Include database connection
require_once '../includes/db_connect.php';
require_once '../includes/mailer.php';

// Get and validate JSON input
$json_input = file_get_contents('php://input');
$data = json_decode($json_input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit();
}

// Extract and validate parameters
$session_id = filter_var($data['session_id'] ?? 0, FILTER_VALIDATE_INT);
$student_id = filter_var($data['student_id'] ?? 0, FILTER_VALIDATE_INT);
$new_status = trim($data['new_status'] ?? '');

// Validation
if (!$session_id || !$student_id || empty($new_status)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

if (!in_array($new_status, ['present', 'absent', 'excused'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit();
}

try {
    // Start database transaction
    $conn->autocommit(false);
    
    // Get current attendance record with related information
    $query = "SELECT ar.status, ca.cas_name, s.first_name, s.last_name 
              FROM attendance_records ar
              INNER JOIN attendance_sessions ats ON ar.session_id = ats.session_id
              INNER JOIN cas_activities ca ON ats.cas_id = ca.cas_id
              INNER JOIN students s ON ar.student_id = s.student_id
              WHERE ar.session_id = ? AND ar.student_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $session_id, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Attendance record not found');
    }
    
    $current_record = $result->fetch_assoc();
    $old_status = $current_record['status'];
    $stmt->close();
    
    // Check if status actually changed
    if ($old_status === $new_status) {
        echo json_encode([
            'success' => true,
            'message' => 'Status unchanged',
            'status' => $new_status
        ]);
        exit();
    }
    
    // Update attendance status
    $update_query = "UPDATE attendance_records 
                     SET status = ?, updated_at = CURRENT_TIMESTAMP 
                     WHERE session_id = ? AND student_id = ?";
    
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("sii", $new_status, $session_id, $student_id);
    
    if (!$update_stmt->execute()) {
        throw new Exception('Failed to update attendance record');
    }
    $update_stmt->close();
    
    // Log the activity
    $student_name = $current_record['first_name'] . ' ' . $current_record['last_name'];
    $activity_details = "Changed attendance status for {$student_name} in {$current_record['cas_name']}: {$old_status} → {$new_status}";
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $log_query = "INSERT INTO activity_log (user_id, activity_type, activity_details, created_at, ip_address) 
                  VALUES (?, 'attendance_update', ?, NOW(), ?)";
    
    $log_stmt = $conn->prepare($log_query);
    $log_stmt->bind_param("iss", $_SESSION['user_id'], $activity_details, $user_ip);
    $log_stmt->execute();
    $log_stmt->close();
    
    // Commit transaction
    $conn->commit();
    $conn->autocommit(true);
    
    // If this change resulted in 'absent', check whether the student has
    // just reached the 2-absence warning threshold for this CAS. Done
    // after commit so the count reflects the final saved state. Needs
    // the CAS id, which isn't otherwise fetched here.
    if ($new_status === 'absent') {
        $cas_stmt = $conn->prepare("SELECT cas_id FROM attendance_sessions WHERE session_id = ?");
        $cas_stmt->bind_param("i", $session_id);
        $cas_stmt->execute();
        $cas_row = $cas_stmt->get_result()->fetch_assoc();
        $cas_stmt->close();
        
        if ($cas_row) {
            check_and_send_absence_warning($conn, $student_id, $cas_row['cas_id']);
        }
    }
    
    // Send success response
    echo json_encode([
        'success' => true,
        'message' => 'Attendance updated successfully',
        'status' => $new_status,
        'old_status' => $old_status,
        'student_name' => $student_name
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Update failed: ' . $e->getMessage()
    ]);
    
} finally {
    // Close database connection
    if (isset($conn)) {
        $conn->close();
    }
}
?>