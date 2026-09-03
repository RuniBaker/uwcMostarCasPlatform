<?php
// includes/mailer.php
// Shared function for sending transactional email via authenticated SMTP
// through the real casplatform@uwcmostar.ba Google Workspace account,
// using PHPMailer (in PHPMailer/src/ alongside this file).
// Every automated email in this app (submission confirmations, decision
// notices, transcripts, absence warnings) goes through send_email() so
// there's one place that handles the actual sending, and one log table
// that records what happened - so "did this student actually get it"
// is answerable instead of a black box.

require_once __DIR__ . '/email_config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Send an email via authenticated SMTP (Gmail/Workspace) and log the attempt.
 *
 * @param string      $to_email   Recipient email address
 * @param string      $subject    Email subject line
 * @param string      $html_body  HTML body content
 * @param string|null $text_body  Optional plain-text fallback. If omitted, a stripped-down version of $html_body is generated automatically.
 * @param string      $email_type Short label for what kind of email this is (e.g. 'absence_confirmation', 'transcript'), stored in the log
 * @param int|null    $student_id Optional student_id this email relates to, stored in the log for easy lookup
 * @param int|null    $cas_id     Optional cas_id this email relates to (needed to tell "2 absences in Chess Club" apart from "2 absences in Debate" for the same student)
 * @return bool True if the SMTP server accepted the message, false otherwise
 */
function send_email($to_email, $subject, $html_body, $text_body = null, $email_type = 'general', $student_id = null, $cas_id = null) {
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        log_email_attempt($to_email, $subject, $email_type, $student_id, 'failed', 'Invalid recipient email address', $cas_id);
        return false;
    }

    // Signature appended to every outgoing email, regardless of type.
    // Added here (once) rather than in each individual email's HTML, so
    // it stays consistent everywhere without needing to touch every file
    // that composes a message.
    $html_body .= "<p style='color: #888; font-size: 12px; margin-top: 20px;'>Runi Baker UWCiM26</p>";

    if ($text_body === null) {
        $text_body = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html_body)));
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
        $mail->addAddress($to_email);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html_body;
        $mail->AltBody = $text_body;

        $mail->send();

        log_email_attempt($to_email, $subject, $email_type, $student_id, 'sent', null, $cas_id);
        return true;
    } catch (PHPMailerException $e) {
        log_email_attempt($to_email, $subject, $email_type, $student_id, 'failed', 'PHPMailer error: ' . $mail->ErrorInfo, $cas_id);
        return false;
    }
}

/**
 * Record an email send attempt in the email_log table. Fails silently if
 * the log table isn't reachable for some reason - a logging failure should
 * never be the reason a real email doesn't send or a page throws an error.
 */
function log_email_attempt($to_email, $subject, $email_type, $student_id, $status, $error_message, $cas_id = null) {
    global $conn;

    if (!isset($conn)) {
        return;
    }

    try {
        $stmt = $conn->prepare("
            INSERT INTO email_log (recipient_email, subject, email_type, student_id, cas_id, status, error_message, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if ($stmt) {
            $stmt->bind_param("sssiiss", $to_email, $subject, $email_type, $student_id, $cas_id, $status, $error_message);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        // Logging failure shouldn't break whatever page triggered the email
    }
}

/**
 * Check whether a student has already received a transcript email within
 * the rate-limit window, to prevent someone spamming a classmate's inbox
 * by repeatedly selecting their name on the self-service transcript page.
 *
 * @return bool True if the student is currently rate-limited (do NOT send)
 */
function is_transcript_rate_limited($student_id) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT sent_at FROM email_log
        WHERE student_id = ? AND email_type = 'transcript' AND status = 'sent'
        ORDER BY sent_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $last = $result->fetch_assoc();
    $stmt->close();

    if (!$last) {
        return false;
    }

    $seconds_since = time() - strtotime($last['sent_at']);
    return $seconds_since < TRANSCRIPT_RATE_LIMIT_SECONDS;
}

/**
 * Check whether a student has just reached 2 unexcused absences for a
 * specific CAS, and if so, send them a one-time warning email (since a
 * 3rd absence typically triggers a formal letter of concern). Only
 * 'absent' status counts - 'excused' never does.
 *
 * Call this immediately after any action that could set an attendance
 * record to 'absent': initial session recording (record_attendance.php,
 * admin_record_attendance.php) and manual status changes
 * (update_attendance_status.php).
 *
 * Sends at most once per student+CAS ever, tracked via email_log itself
 * (email_type = 'absence_warning'). This is deliberately NOT re-sent if
 * the count temporarily drops (e.g. a record gets corrected) and later
 * climbs back to 2 - the first warning already served its purpose.
 */
function check_and_send_absence_warning($conn, $student_id, $cas_id) {
    // Count current unexcused absences for this student in this CAS
    $stmt = $conn->prepare("
        SELECT COUNT(*) as absent_count
        FROM attendance_records ar
        JOIN attendance_sessions ats ON ar.session_id = ats.session_id
        WHERE ar.student_id = ? AND ats.cas_id = ? AND ar.status = 'absent'
    ");
    $stmt->bind_param("ii", $student_id, $cas_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $absent_count = (int)$result->fetch_assoc()['absent_count'];
    $stmt->close();

    if ($absent_count < 2) {
        return;
    }

    // Check if a warning has already been sent for this student+CAS
    $stmt = $conn->prepare("
        SELECT 1 FROM email_log
        WHERE student_id = ? AND cas_id = ? AND email_type = 'absence_warning' AND status = 'sent'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $student_id, $cas_id);
    $stmt->execute();
    $already_sent = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($already_sent) {
        return;
    }

    // Look up student email/name and CAS name
    $stmt = $conn->prepare("SELECT email, first_name, last_name FROM students WHERE student_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("SELECT cas_name FROM cas_activities WHERE cas_id = ?");
    $stmt->bind_param("i", $cas_id);
    $stmt->execute();
    $cas = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student || empty($student['email']) || !$cas) {
        return;
    }

    $subject = "Attendance Notice - " . htmlspecialchars($cas['cas_name']);
    $body = "
        <p>Hi " . htmlspecialchars($student['first_name']) . ",</p>
        <p>This is a notice that you now have <strong>2 unexcused absences</strong> in <strong>" . htmlspecialchars($cas['cas_name']) . "</strong>.</p>
        <p>Please note that a 3rd unexcused absence typically results in a formal letter of concern. If you believe any of these absences should be excused, please submit an absence request as soon as possible.</p>
        <p style='color: #888; font-size: 13px;'>UWC Mostar CAS Platform</p>
    ";

    send_email($student['email'], $subject, $body, null, 'absence_warning', $student_id, $cas_id);
}