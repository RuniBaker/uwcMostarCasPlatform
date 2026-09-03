<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_status'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Database connection
require_once '../includes/db_connect.php';

echo "<h2>Debug: CAS Leader Enrollment Status</h2>";

// First, let's see what the actual data looks like
echo "<h3>Raw Data Check</h3>";

$debug_query = "
    SELECT 
        cl.cas_leader_id,
        cl.user_id,
        cl.cas_id,
        u.first_name,
        u.last_name,
        u.student_id,
        ca.cas_name,
        sce.enrollment_id,
        sce.is_active as enrollment_active,
        sce.enrollment_date
    FROM cas_leaders cl
    JOIN users u ON cl.user_id = u.user_id
    JOIN cas_activities ca ON cl.cas_id = ca.cas_id
    LEFT JOIN student_cas_enrollment sce ON u.student_id = sce.student_id AND cl.cas_id = sce.cas_id
    WHERE u.student_id IS NOT NULL
    ORDER BY u.last_name, u.first_name, ca.cas_name
    LIMIT 10
";

$debug_result = $conn->query($debug_query);
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Leader</th><th>Student ID</th><th>CAS Activity</th><th>Enrollment ID</th><th>Is Active</th><th>Enrollment Date</th></tr>";

while ($row = $debug_result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
    echo "<td>" . ($row['student_id'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($row['cas_name']) . "</td>";
    echo "<td>" . ($row['enrollment_id'] ?? 'NULL') . "</td>";
    echo "<td>" . ($row['enrollment_active'] ?? 'NULL') . "</td>";
    echo "<td>" . ($row['enrollment_date'] ?? 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Now let's try to fix the missing enrollments:</h3>";

// Find leaders who need enrollment
$fix_query = "
    SELECT 
        cl.user_id,
        cl.cas_id,
        u.first_name,
        u.last_name,
        u.student_id,
        ca.cas_name
    FROM cas_leaders cl
    JOIN users u ON cl.user_id = u.user_id
    JOIN cas_activities ca ON cl.cas_id = ca.cas_id
    LEFT JOIN student_cas_enrollment sce ON u.student_id = sce.student_id AND cl.cas_id = sce.cas_id
    WHERE u.student_id IS NOT NULL
    AND sce.enrollment_id IS NULL
    ORDER BY u.last_name, u.first_name, ca.cas_name
";

$fix_result = $conn->query($fix_query);
echo "<p>Found " . $fix_result->num_rows . " leaders who need to be enrolled:</p>";

if ($fix_result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Leader Name</th><th>Student ID</th><th>CAS Activity</th><th>Action Result</th></tr>";
    
    while ($row = $fix_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
        echo "<td>" . $row['student_id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['cas_name']) . "</td>";
        
        // Try to enroll them
        $enroll_stmt = $conn->prepare("INSERT INTO student_cas_enrollment (student_id, cas_id, enrollment_date, is_active) VALUES (?, ?, CURRENT_DATE(), 1)");
        $enroll_stmt->bind_param("ii", $row['student_id'], $row['cas_id']);
        
        if ($enroll_stmt->execute()) {
            echo "<td style='color: green;'>✓ Successfully Enrolled</td>";
        } else {
            echo "<td style='color: red;'>✗ Failed: " . $enroll_stmt->error . "</td>";
        }
        $enroll_stmt->close();
        
        echo "</tr>";
    }
    echo "</table>";
}

// Now check for inactive enrollments that need reactivation
echo "<h3>Checking for inactive enrollments to reactivate:</h3>";

$reactivate_query = "
    SELECT 
        cl.user_id,
        cl.cas_id,
        u.first_name,
        u.last_name,
        u.student_id,
        ca.cas_name,
        sce.enrollment_id
    FROM cas_leaders cl
    JOIN users u ON cl.user_id = u.user_id
    JOIN cas_activities ca ON cl.cas_id = ca.cas_id
    JOIN student_cas_enrollment sce ON u.student_id = sce.student_id AND cl.cas_id = sce.cas_id
    WHERE u.student_id IS NOT NULL
    AND sce.is_active = 0
    ORDER BY u.last_name, u.first_name, ca.cas_name
";

$reactivate_result = $conn->query($reactivate_query);
echo "<p>Found " . $reactivate_result->num_rows . " inactive enrollments to reactivate:</p>";

if ($reactivate_result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Leader Name</th><th>Student ID</th><th>CAS Activity</th><th>Enrollment ID</th><th>Action Result</th></tr>";
    
    while ($row = $reactivate_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
        echo "<td>" . $row['student_id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['cas_name']) . "</td>";
        echo "<td>" . $row['enrollment_id'] . "</td>";
        
        // Try to reactivate them
        $reactivate_stmt = $conn->prepare("UPDATE student_cas_enrollment SET is_active = 1 WHERE enrollment_id = ?");
        $reactivate_stmt->bind_param("i", $row['enrollment_id']);
        
        if ($reactivate_stmt->execute()) {
            if ($reactivate_stmt->affected_rows > 0) {
                echo "<td style='color: green;'>✓ Successfully Reactivated</td>";
            } else {
                echo "<td style='color: orange;'>⚠ No changes made (may already be active)</td>";
            }
        } else {
            echo "<td style='color: red;'>✗ Failed: " . $reactivate_stmt->error . "</td>";
        }
        $reactivate_stmt->close();
        
        echo "</tr>";
    }
    echo "</table>";
}

echo "<br><h3>Final Status Check:</h3>";
$final_check_query = "
    SELECT 
        u.first_name,
        u.last_name,
        ca.cas_name,
        CASE 
            WHEN sce.enrollment_id IS NULL THEN 'Not Enrolled'
            WHEN sce.is_active = 1 THEN 'Enrolled & Active'
            ELSE 'Enrolled but Inactive'
        END as status
    FROM cas_leaders cl
    JOIN users u ON cl.user_id = u.user_id
    JOIN cas_activities ca ON cl.cas_id = ca.cas_id
    LEFT JOIN student_cas_enrollment sce ON u.student_id = sce.student_id AND cl.cas_id = sce.cas_id
    WHERE u.student_id IS NOT NULL
    ORDER BY u.last_name, u.first_name, ca.cas_name
    LIMIT 20
";

$final_result = $conn->query($final_check_query);
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Leader Name</th><th>CAS Activity</th><th>Enrollment Status</th></tr>";

while ($row = $final_result->fetch_assoc()) {
    $row_class = '';
    if ($row['status'] === 'Enrolled & Active') {
        $row_class = 'style="background-color: #d4fcd4;"';
    } elseif ($row['status'] === 'Not Enrolled') {
        $row_class = 'style="background-color: #fcd4d4;"';
    }
    
    echo "<tr $row_class>";
    echo "<td>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
    echo "<td>" . htmlspecialchars($row['cas_name']) . "</td>";
    echo "<td>" . $row['status'] . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<br><a href='users.php'>← Back to User Management</a>";
?>