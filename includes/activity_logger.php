<?php
/**
 * Activity Logger for UWC Mostar CAS Attendance Platform
 * 
 * This file contains functions to log user activities in the system.
 * Include this file in pages where activity logging is needed.
 */

/**
 * Log an activity in the database
 * 
 * @param mysqli $conn          Database connection
 * @param string $activity_type Type of activity (e.g., 'Added student', 'Updated activity')
 * @param string $details       Details of the activity
 * @param int    $user_id       ID of the user performing the action (optional, defaults to current user)
 * @return bool                 True if logging was successful, false otherwise
 */
function logActivity($conn, $activity_type, $details, $user_id = null) {
    // If user_id not provided, try to get from session
    if ($user_id === null && isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
    }
    
    // Get IP address
    $ip_address = $_SERVER['REMOTE_ADDR'];
    
    // Prepare statement
    $stmt = $conn->prepare("
        INSERT INTO activity_log 
            (user_id, activity_type, activity_details, ip_address) 
        VALUES 
            (?, ?, ?, ?)
    ");
    
    if (!$stmt) {
        return false;
    }
    
    // Bind parameters
    $stmt->bind_param("isss", $user_id, $activity_type, $details, $ip_address);
    
    // Execute query
    $result = $stmt->execute();
    
    // Close statement
    $stmt->close();
    
    return $result;
}

/**
 * Get recent activities from the database
 * 
 * @param mysqli $conn      Database connection
 * @param int    $limit     Maximum number of activities to return
 * @param string $type      Filter by activity type (optional)
 * @param int    $user_id   Filter by user ID (optional)
 * @return array            Array of activities or empty array if none found
 */
function getRecentActivities($conn, $limit = 5, $type = null, $user_id = null) {
    // Start building query
    $query = "
        SELECT 
            al.log_id,
            al.activity_type,
            al.activity_details,
            al.created_at,
            al.ip_address,
            u.username 
        FROM 
            activity_log al
        LEFT JOIN 
            users u ON al.user_id = u.user_id
        WHERE 1=1
    ";
    
    // Add filters if provided
    $params = [];
    $types = "";
    
    if ($type !== null) {
        $query .= " AND al.activity_type = ?";
        $params[] = $type;
        $types .= "s";
    }
    
    if ($user_id !== null) {
        $query .= " AND al.user_id = ?";
        $params[] = $user_id;
        $types .= "i";
    }
    
    // Add ordering and limit
    $query .= "
        ORDER BY 
            al.created_at DESC
        LIMIT ?
    ";
    $params[] = $limit;
    $types .= "i";
    
    // Prepare statement
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        return [];
    }
    
    // Bind parameters if there are any
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    // Execute query
    $stmt->execute();
    
    // Get results
    $result = $stmt->get_result();
    
    // Fetch all rows
    $activities = [];
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
    
    // Close statement
    $stmt->close();
    
    return $activities;
}

/**
 * Check if activity_log table exists
 * 
 * @param mysqli $conn Database connection
 * @return bool       True if table exists, false otherwise
 */
function activityLogTableExists($conn) {
    $result = $conn->query("SHOW TABLES LIKE 'activity_log'");
    return ($result->num_rows > 0);
}