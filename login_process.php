<?php
// Set UTF-8 encoding at the very beginning
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
ini_set('default_charset', 'UTF-8');

// Start session for user authentication
session_start();

// Database connection
require_once 'includes/db_connect.php';

// Function to sanitize input data - MODIFIED to preserve UTF-8 characters
function sanitize_input($data, $preserve_special_chars = false) {
    $data = trim($data);
    $data = stripslashes($data);
    
    // Only apply htmlspecialchars if we don't need to preserve special characters
    if (!$preserve_special_chars) {
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    
    return $data;
}

// Process the login form
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Ensure UTF-8 handling for usernames with diacritics (č ć ž đ š etc).
    // Kept explicit here even though db_connect.php provides $conn, since
    // this line was flagged important in the original file and it's cheap
    // to call again if db_connect.php already sets it.
    $conn->set_charset("utf8mb4");
    
    // Get form data - preserve special characters for username
    $username = sanitize_input($_POST['username'], true); // Don't convert special chars
    $password = $_POST['password']; // Raw password for verification
    
    // Remember me functionality
    $remember = isset($_POST['remember-me']) ? true : false;
    
    // Debug logging (remove after testing)
    error_log("Login attempt - Username: " . $username);
    error_log("Username bytes: " . bin2hex($username));
    
    // Prepare a statement to get user data including temporary password fields
    $stmt = $conn->prepare("SELECT user_id, username, password_hash, first_name, last_name, user_status, student_id, is_temporary_password, password_changed_at FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Check if user exists
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        
        // Debug logging (remove after testing)
        error_log("Found user: " . $user['username']);
        error_log("DB username bytes: " . bin2hex($user['username']));
        
        $password_valid = false;
        $is_temp_login = false;
        
        // Check if user is using temporary password
        if ($user['is_temporary_password'] == 1) {
            // For temporary password, the password_hash field contains the temporary password
            // Check if entered password matches the temporary password (stored in password_hash)
            if (password_verify($password, $user['password_hash'])) {
                $password_valid = true;
                $is_temp_login = true;
            }
        } else {
            // Check regular password
            if (password_verify($password, $user['password_hash'])) {
                $password_valid = true;
            }
        }
        
        if ($password_valid) {
            // Password is correct, create session variables
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_status'] = $user['user_status'];
            $_SESSION['student_id'] = $user['student_id'];
            $_SESSION['logged_in'] = true;
            
            // Check if this is a temporary password login or first time login (password_changed_at is NULL)
            if ($is_temp_login || $user['password_changed_at'] === NULL) {
                $_SESSION['needs_password_change'] = true;
                // Don't set remember me cookie for temp password logins
                header("Location: change_password.php");
                exit();
            }
            
            // Remember me functionality - only for regular logins
            if ($remember) {
                // Generate secure token
                $token = bin2hex(random_bytes(32));
                
                // Store token in database
                // Clean up old tokens first
$cleanup_stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ? OR expires_at < NOW()");
$cleanup_stmt->bind_param("i", $user['user_id']);
$cleanup_stmt->execute();
$cleanup_stmt->close();

// Store token in database
$stmt = $conn->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
$stmt->bind_param("is", $user['user_id'], $token);

if (!$stmt->execute()) {
    // Log error but don't break login process
    error_log("Failed to store remember token: " . $conn->error);
}
                
                // Check if we're on HTTPS
$is_secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

setcookie("remember_user", $user['user_id'], time() + (86400 * 30), "/", "", $is_secure, true);
setcookie("remember_token", $token, time() + (86400 * 30), "/", "", $is_secure, true);
            }
            
            // Redirect based on user status
            if ($user['user_status'] == 'admin') {
                header("Location: admin/dashboard.php");
                exit();
            } else if ($user['user_status'] == 'cas_leader') {
                header("Location: cas_leader/dashboard.php");
                exit();
            } else {
                // Fallback or error
                header("Location: error.php?msg=invalid_role");
                exit();
            }
        } else {
            // Wrong password
            $_SESSION['login_error'] = "Invalid username or password";
            header("Location: login.php");
            exit();
        }
    } else {
        // User doesn't exist
        error_log("User not found: " . $username);
        $_SESSION['login_error'] = "Invalid username or password";
        header("Location: login.php");
        exit();
    }
    
    // Close connections
    $stmt->close();
    $conn->close();
} else {
    // If not a POST request, redirect to login page
    header("Location: login.php");
    exit();
}
?>