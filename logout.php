<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection for handling remember tokens
require_once 'includes/db_connect.php';

// Check if remember-me cookie exists
if (isset($_COOKIE['remember_user']) && isset($_COOKIE['remember_token'])) {
    $user_id = $_COOKIE['remember_user'];
    $token = $_COOKIE['remember_token'];
    
    // Delete the remember-me token from database
    $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ? AND token = ?");
    $stmt->bind_param("is", $user_id, $token);
    $stmt->execute();
    
    // Delete cookies by setting their expiration in the past
    setcookie("remember_user", "", time() - 3600, "/", "", true, true);
    setcookie("remember_token", "", time() - 3600, "/", "", true, true);
}

// Update last_login timestamp in the users table
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
}

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Close database connection
$conn->close();

// Redirect to login page
header("Location: index.html");
exit();
?>