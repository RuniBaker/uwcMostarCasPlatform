<?php
// send_absence_notification.php - Handles sending absence notifications

// Make sure PHPMailer is included
require_once '../libs/PHPMailer/src/Exception.php';
require_once '../libs/PHPMailer/src/PHPMailer.php';
require_once '../libs/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send absence notification to a student
 * 
 * @param array $student Student information (student_id, first_name, last_name, email, cas_id, cas_name, cas_type, cas_day, cas_time)
 * @param string $notification_type Type of notification ('2_absences' or '3_absences')
 * @return boolean Whether the notification was successfully sent
 */
function sendAbsenceNotification($student, $notification_type) {
    global $conn; // Use the database connection from the parent script
    
    // Email configuration
    $admin_email = "iva.marinovic@uwcmostar.ba";
    $school_email = "automatedcasuwcmostar@gmail.com"; // Gmail address
    $school_name = "UWC Mostar CAS System";
    
    // SMTP Configuration - replace with your actual credentials
    $smtp_host = "smtp.gmail.com";
    $smtp_port = 587;
    $smtp_username = "automatedcasuwcmostar@gmail.com"; // Your Gmail address
    $smtp_password = "your-app-password-here"; // Use your App Password for Gmail
    $smtp_secure = "tls";
    
    $student_name = $student['first_name'] . ' ' . $student['last_name'];
    $student_email = $student['email'];
    $cas_name = $student['cas_name'];
    $cas_day = ucfirst($student['cas_day']);
    $cas_time = date('g:i A', strtotime($student['cas_time']));
    
    // Create new PHPMailer instance
    $mail = new PHPMailer(true); // true enables exceptions
    
    try {
        // Configure SMTP settings
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = $smtp_secure;
        $mail->Port = $smtp_port;
        
        // Set email properties
        $mail->setFrom($school_email, $school_name);
        $mail->addAddress($student_email, $student_name);
        $mail->isHTML(true);
        
        if ($notification_type === '2_absences') {
            $subject = "Attendance Warning - $cas_name";
            $message = "
            <html>
            <head>
                <title>CAS Attendance Warning</title>
                <link rel="icon" type="image/x-icon" href="../tab.ico">
            </head>
            <body>
                <p>Dear $student_name,</p>
                
                <p>Our records indicate that you have missed two sessions of your CAS activity: <strong>$cas_name</strong> 
                ($cas_day at $cas_time).</p>
                
                <p>Regular attendance is an important part of your CAS commitment. Please ensure you attend all 
                future sessions or submit an absence request if you cannot attend.</p>
                
                <p>If you believe this is an error or have any questions, please contact your CAS advisor.</p>
                
                <p>Best regards,<br>
                UWC Mostar CAS Team</p>
            </body>
            </html>
            ";
        } else if ($notification_type === '3_absences') {
            $subject = "Important - CAS Attendance Meeting Required";
            $message = "
            <html>
            <head>
                <title>CAS Attendance Meeting Required</title>
            </head>
            <body>
                <p>Dear $student_name,</p>
                
                <p>Our records indicate that you have missed three sessions of your CAS activity: <strong>$cas_name</strong> 
                ($cas_day at $cas_time).</p>
                
                <p>According to our CAS policy, you are required to schedule a meeting with your CAS advisor to discuss 
                your participation and commitment to this activity. Please arrange this meeting as soon as possible.</p>
                
                <p>If you believe this is an error or have any questions, please contact your CAS advisor.</p>
                
                <p>Best regards,<br>
                UWC Mostar CAS Team</p>
            </body>
            </html>
            ";
            
            // Add CC for 3 absences notification
            $mail->addCC($admin_email);
        }
        
        // Set email subject and body
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->AltBody = strip_tags($message); // Plain text version
        
        // Log file setup
        $log_file = __DIR__ . '/email_logs.txt';
        $log_content = "==== " . date('Y-m-d H:i:s') . " ====\n";
        $log_content .= "To: $student_email\n";
        $log_content .= "From: $school_name <$school_email>\n";
        if ($notification_type === '3_absences') {
            $log_content .= "Cc: $admin_email\n";
        }
        $log_content .= "Subject: $subject\n";
        $log_content .= "Message:\n$message\n\n";
        
        // Send the email
        $mail_sent = $mail->send();
        $log_content .= "Email was " . ($mail_sent ? "successfully sent" : "not sent") . "\n\n";
        
        // Log the email attempt
        file_put_contents($log_file, $log_content, FILE_APPEND);
        
        // Record notification in database
        $stmt = $conn->prepare("
            INSERT INTO absence_notifications (student_id, cas_id, notification_type, sent_date) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->bind_param("iis", $student['student_id'], $student['cas_id'], $notification_type);
        $stmt->execute();
        $stmt->close();
        
        return $mail_sent;
        
    } catch (Exception $e) {
        // Log the error
        $log_file = __DIR__ . '/email_errors.txt';
        $error_message = date('Y-m-d H:i:s') . ": Error sending email to " . $student_email . 
                         " (" . $notification_type . "): " . $mail->ErrorInfo . "\n";
        file_put_contents($log_file, $error_message, FILE_APPEND);
        
        return false;
    }
}
?>