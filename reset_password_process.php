<?php
// Start session
session_start();

// Use the shared DB connection file (this already creates $conn)
require_once 'includes/db_connect.php';

// Function to sanitize input data
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Process the password reset form
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Get form data
    $token = sanitize_input($_POST['token']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    $errors = array();
    
    if (strlen($new_password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    if ($new_password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    if (!preg_match('/[A-Z]/', $new_password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $new_password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $new_password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    // Validate token - check it exists, isn't used, and hasn't expired
    $stmt = $conn->prepare("SELECT prt.id, prt.user_id, u.username, u.first_name, u.last_name 
                           FROM password_reset_tokens prt 
                           JOIN users u ON prt.user_id = u.user_id 
                           WHERE prt.token = ? AND prt.used = 0 AND prt.expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows != 1) {
        $errors[] = "Invalid or expired reset token";
    }
    
    if (!empty($errors)) {
        $_SESSION['reset_error'] = implode("<br>", $errors);
        header("Location: reset_password.php?token=" . urlencode($token));
        exit();
    }
    
    // Token is valid, proceed with password reset
    $token_data = $result->fetch_assoc();
    $user_id = $token_data['user_id'];
    $stmt->close();
    
    // Hash the new password
    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Update the password
        $update_stmt = $conn->prepare("UPDATE users SET password_hash = ?, is_temporary_password = 0, password_changed_at = NOW() WHERE user_id = ?");
        $update_stmt->bind_param("si", $password_hash, $user_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Mark the token as used
        $token_stmt = $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE token = ?");
        $token_stmt->bind_param("s", $token);
        $token_stmt->execute();
        $token_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        // Success - redirect to login
        $_SESSION['password_reset_success'] = "Your password has been successfully reset! You can now log in with your new password.";
        header("Location: login.php");
        exit();
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['reset_error'] = "An error occurred while resetting your password. Please try again.";
        header("Location: reset_password.php?token=" . urlencode($token));
        exit();
    }
    
} else {
    // If not a POST request, redirect to forgot password page
    header("Location: forgot_password.php");
    exit();
}
?>