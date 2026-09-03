<?php
/**
 * Password Reset System Configuration
 * 
 * Copy this file to config.php and update the values below
 * Then include it at the top of each password reset PHP file
 */

// =============================================================================
// DATABASE CONFIGURATION
// =============================================================================
define('DB_HOST', 'localhost');
define('DB_USER', 'uwcmosta_admin');
define('DB_PASS', 'Weloveuwc123');
define('DB_NAME', 'uwcmosta_cas_attendance_tracking');

// =============================================================================
// EMAIL CONFIGURATION
// =============================================================================

// Email method: 'mail' for PHP mail() or 'smtp' for SMTP
define('EMAIL_METHOD', 'mail');

// SMTP Settings (only used if EMAIL_METHOD is 'smtp')
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@uwcmostar.ba');
define('SMTP_PASSWORD', 'your-app-password');
define('SMTP_ENCRYPTION', 'tls'); // 'tls' or 'ssl'

// Email addresses
define('EMAIL_FROM_ADDRESS', 'noreply@uwcmostar.ba');
define('EMAIL_FROM_NAME', 'CAS System');
define('EMAIL_REPLY_TO', 'cas@uwcmostar.ba');

// =============================================================================
// SECURITY SETTINGS
// =============================================================================

// Token expiration time in hours
define('TOKEN_EXPIRY_HOURS', 1);

// Password requirements
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', false);

// Rate limiting (optional - requires additional implementation)
define('MAX_RESET_REQUESTS_PER_HOUR', 3);
define('MAX_RESET_REQUESTS_PER_DAY', 5);

// =============================================================================
// APPLICATION SETTINGS
// =============================================================================

// Base URL of your application (no trailing slash)
// This will be auto-detected if left empty
define('BASE_URL', '');

// Logo path (relative to base URL)
define('LOGO_PATH', '2.png');

// School name
define('SCHOOL_NAME', 'UWC Mostar');

// CAS contact information
define('CAS_EMAIL', 'cas@uwcmostar.ba');
define('CAS_PHONE', '+387 36 570 502');

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Get the base URL of the application
 */
function get_base_url() {
    if (BASE_URL !== '') {
        return BASE_URL;
    }
    
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $script = dirname($_SERVER['PHP_SELF']);
    
    return $protocol . "://" . $host . $script;
}

/**
 * Sanitize user input
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Validate password against configured requirements
 */
function validate_password($password) {
    $errors = array();
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = "Password must be at least " . PASSWORD_MIN_LENGTH . " characters long";
    }
    
    if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return $errors;
}

/**
 * Get database connection
 */
function get_db_connection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        return null;
    }
    
    return $conn;
}

/**
 * Send email using configured method
 */
function send_email($to, $subject, $html_message, $plain_message = '') {
    if (EMAIL_METHOD === 'smtp') {
        return send_smtp_email($to, $subject, $html_message, $plain_message);
    } else {
        return send_php_mail($to, $subject, $html_message, $plain_message);
    }
}

/**
 * Send email using PHP mail() function
 */
function send_php_mail($to, $subject, $html_message, $plain_message = '') {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . EMAIL_FROM_NAME . " <" . EMAIL_FROM_ADDRESS . ">" . "\r\n";
    $headers .= "Reply-To: " . EMAIL_REPLY_TO . "\r\n";
    
    return mail($to, $subject, $html_message, $headers);
}

/**
 * Send email using SMTP (requires PHPMailer)
 */
function send_smtp_email($to, $subject, $html_message, $plain_message = '') {
    // Check if PHPMailer is available
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PHPMailer not found. Please install it via Composer or use 'mail' method.");
        return false;
    }
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port       = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo(EMAIL_REPLY_TO);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_message;
        $mail->AltBody = $plain_message ?: strip_tags($html_message);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("SMTP Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>
