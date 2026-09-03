<?php
// Start session
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
session_start();

// Use the shared DB connection file (this already creates $conn)
require_once 'includes/db_connect.php';
require_once 'includes/mailer.php';

// Function to sanitize input data
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Process the forgot password form
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Get and sanitize email
    $email = sanitize_input($_POST['email']);
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['forgot_error'] = "Please enter a valid email address";
        header("Location: forgot_password.php");
        exit();
    }
    
    // Check if user exists with this email
    $stmt = $conn->prepare("SELECT user_id, username, first_name, last_name, email FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Always show success message (security best practice - don't reveal if email exists)
    // But only send email if user actually exists
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        
        // Generate secure random token
        $token = bin2hex(random_bytes(32));
        
        // Delete any existing unused tokens for this user
        $delete_stmt = $conn->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND used = 0");
        $delete_stmt->bind_param("i", $user['user_id']);
        $delete_stmt->execute();
        $delete_stmt->close();
        
        // Insert new token (expires in 1 hour)
        $insert_stmt = $conn->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
        $insert_stmt->bind_param("is", $user['user_id'], $token);
        $insert_stmt->execute();
        $insert_stmt->close();
        
        // Create reset link
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $reset_link = $protocol . "://" . $host . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
        
        // Prepare email
        $to = $user['email'];
        $subject = "Password Reset Request - UWC Mostar CAS System";
        
        // Email body (HTML)
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #0077b6; color: white; padding: 20px; text-align: center; }
                .content { background-color: #f8f9fa; padding: 30px; margin: 20px 0; }
                .button { display: inline-block; padding: 12px 30px; background-color: #0077b6; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; color: #666; font-size: 12px; margin-top: 20px; }
                .warning { background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Password Reset Request</h1>
                </div>
                <div class='content'>
                    <p>Hello " . htmlspecialchars($user['first_name']) . " " . htmlspecialchars($user['last_name']) . ",</p>
                    
                    <p>We received a request to reset your password for the UWC Mostar CAS Tracking System.</p>
                    
                    <p>Click the button below to reset your password:</p>
                    
                    <p style='text-align: center;'>
                        <a href='" . $reset_link . "' class='button'>Reset Password</a>
                    </p>
                    
                    <p>Or copy and paste this link into your browser:</p>
                    <p style='word-break: break-all; background-color: #fff; padding: 10px; border: 1px solid #ddd;'>" . $reset_link . "</p>
                    
                    <div class='warning'>
                        <strong>Important:</strong>
                        <ul>
                            <li>This link will expire in 1 hour</li>
                            <li>If you didn't request this reset, please ignore this email</li>
                            <li>For security, this link can only be used once</li>
                        </ul>
                    </div>
                    
                    <p>If you have any questions or need assistance, please contact your CAS coordinator at <a href='mailto:cas@uwcmostar.ba'>cas@uwcmostar.ba</a></p>
                </div>
                <div class='footer'>
                    <p>&copy; 2025 UWC Mostar. All rights reserved.</p>
                    <p>This is an automated message, please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        // Plain text alternative
        $plain_message = "Hello " . $user['first_name'] . " " . $user['last_name'] . ",\n\n";
        $plain_message .= "We received a request to reset your password for the UWC Mostar CAS Tracking System.\n\n";
        $plain_message .= "Click the link below to reset your password:\n";
        $plain_message .= $reset_link . "\n\n";
        $plain_message .= "IMPORTANT:\n";
        $plain_message .= "- This link will expire in 1 hour\n";
        $plain_message .= "- If you didn't request this reset, please ignore this email\n";
        $plain_message .= "- For security, this link can only be used once\n\n";
        $plain_message .= "If you have any questions, contact cas@uwcmostar.ba\n\n";
        $plain_message .= "© 2025 UWC Mostar. All rights reserved.";
        
        // Send email via the shared mailer (real authenticated SMTP, logged
        // to email_log, gets the standard signature automatically)
        $sent = send_email($to, $subject, $message, $plain_message, 'password_reset', null, null);
        
        if ($sent) {
            $_SESSION['forgot_success'] = "If an account exists with this email, a password reset link has been sent. Please check your inbox.";
        } else {
            error_log("Failed to send password reset email to: " . $email);
            $_SESSION['forgot_success'] = "If an account exists with this email, a password reset link has been sent. Please check your inbox.";
        }
    } else {
        // User doesn't exist, but show same success message (security best practice)
        $_SESSION['forgot_success'] = "If an account exists with this email, a password reset link has been sent. Please check your inbox.";
    }
    
    $stmt->close();
    
    header("Location: forgot_password.php");
    exit();
} else {
    // If not a POST request, redirect to forgot password page
    header("Location: forgot_password.php");
    exit();
}
?>