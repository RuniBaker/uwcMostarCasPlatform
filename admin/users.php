<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_status'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Database connection
require_once '../includes/db_connect.php';
require_once '../includes/temp_password_helper.php';
require_once '../includes/mailer.php';

// Message handling
$message = "";
$message_type = "";

// Debug mode - set to false in production
$debug = true;

// Handle export request
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    // Completely suppress all output until we're ready
    ob_start();
    
    try {
        // Which account type to export: 'admin', 'cas_leader', or both if unspecified
        $export_type = isset($_GET['type']) ? $_GET['type'] : 'all';
        if (!in_array($export_type, ['admin', 'cas_leader', 'all'], true)) {
            $export_type = 'all';
        }
        
        // Get users for export, filtered to the requested account type
        $export_query = "SELECT u.*, s.first_name as student_first_name, s.last_name as student_last_name 
                        FROM users u 
                        LEFT JOIN students s ON u.student_id = s.student_id 
                        WHERE ";
        
        $params = [];
        $types = "";
        
        if ($export_type === 'all') {
            $export_query .= "u.user_status IN ('admin', 'cas_leader')";
        } else {
            $export_query .= "u.user_status = ?";
            $params[] = $export_type;
            $types .= "s";
        }
        
        // Apply search filter if present
        if (!empty($_GET['search'])) {
            $search_term = "%" . $_GET['search'] . "%";
            $export_query .= " AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $types .= "sss";
        }
        
        $export_query .= " ORDER BY 
            CASE u.user_status 
                WHEN 'admin' THEN 1 
                WHEN 'cas_leader' THEN 2 
            END,
            u.last_name, u.first_name";
        
        $export_stmt = $conn->prepare($export_query);
        if (!$export_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        if (!empty($params)) {
            $export_stmt->bind_param($types, ...$params);
        }
        
        $export_stmt->execute();
        $export_result = $export_stmt->get_result();
        
        // Build CSV content in memory first
        $csv_content = '';
        
        // Add headers
        $csv_content .= "First Name,Last Name,Username,Generated Password,User Type,Email,Student Link\n";
        
        // Add data rows
        while ($user = $export_result->fetch_assoc()) {
            // Read the actual password that was generated at account creation
            // (stored in temp_password_plaintext) rather than trying to
            // recompute it - with random generation there's no formula to
            // reverse. If it's NULL, the user has already set their real
            // password, so there's nothing left to show for that account.
            $generated_password = !empty($user['temp_password_plaintext']) 
                ? $user['temp_password_plaintext'] 
                : 'Already changed';
            
            // Determine student link text
            $student_link = 'No student linked';
            if ($user['student_id'] && $user['student_first_name'] && $user['student_last_name']) {
                $student_link = $user['student_first_name'] . ' ' . $user['student_last_name'];
            }
            
            // Escape CSV values
            $row = [
                '"' . str_replace('"', '""', $user['first_name']) . '"',
                '"' . str_replace('"', '""', $user['last_name']) . '"',
                '"' . str_replace('"', '""', $user['username']) . '"',
                '"' . str_replace('"', '""', $generated_password) . '"',
                '"' . str_replace('"', '""', ucfirst(str_replace('_', ' ', $user['user_status']))) . '"',
                '"' . str_replace('"', '""', $user['email']) . '"',
                '"' . str_replace('"', '""', $student_link) . '"'
            ];
            
            $csv_content .= implode(',', $row) . "\n";
        }
        
        $export_stmt->close();
        
        // Clear any buffered output
        ob_end_clean();
        
        // Filename reflects which account type was exported
        $filename_prefix = $export_type === 'admin' ? 'admin_accounts' : ($export_type === 'cas_leader' ? 'cas_leader_accounts' : 'user_accounts');
        
        // Now send headers and content
        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename="' . $filename_prefix . '_' . date('Y-m-d_H-i-s') . '.csv"');
        header('Content-Length: ' . strlen($csv_content));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        // Output the CSV
        echo $csv_content;
        exit();
        
    } catch (Exception $e) {
        // Clear buffer and show error
        ob_end_clean();
        if ($debug) error_log("Export error: " . $e->getMessage());
        $message = "Error exporting data: " . $e->getMessage();
        $message_type = "error";
    }
}
if ($debug && $_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST data received: " . print_r($_POST, true));
}

// Handle GET parameters for actions
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle POST form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($debug) {
        error_log("Processing POST request with action keys: " . implode(', ', array_keys($_POST)));
    }
    
    // Create new Admin account
    if (isset($_POST['create_admin']) && $_POST['create_admin'] === '1') {
        if ($debug) error_log("Creating new admin user");
        
        $username = trim($_POST['username'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if ($debug) {
            error_log("Admin form data - Username: $username, Email: $email, First: $first_name, Last: $last_name");
        }
        
        // Validate inputs
        if (empty($username) || empty($first_name) || empty($last_name) || empty($email)) {
            $message = "All required fields must be filled out.";
            $message_type = "error";
            if ($debug) error_log("Admin validation failed: empty fields");
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Invalid email format.";
            $message_type = "error";
            if ($debug) error_log("Admin validation failed: invalid email");
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Check if username already exists
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $message = "Username already exists. Please choose a different username.";
                    $message_type = "error";
                    if ($debug) error_log("Admin username already exists: $username");
                } else {
                    // Check if email already exists
                    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $message = "Email already exists. Please use a different email address.";
                        $message_type = "error";
                        if ($debug) error_log("Admin email already exists: $email");
                    } else {
                        // Use the same random-password helper as CAS leader creation
                        $result = createUserWithTempPassword($conn, $username, $first_name, $last_name, $email, 'admin', null);
                        
                        if ($result['success']) {
                            $conn->commit();
                            $conn->autocommit(true);
                            
                            if ($debug) error_log("Admin user created successfully with ID: " . $result['user_id']);
                            
                            // Email the new admin their login credentials
                            if (!empty($email)) {
                                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                                $login_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/login.php";
                                $welcome_subject = "Your CAS Platform Administrator Account";
                                $welcome_body = "
                                    <p>Hi " . htmlspecialchars($first_name) . ",</p>
                                    <p>An administrator account has been created for you on the UWC Mostar CAS Platform.</p>
                                    <table style='border-collapse: collapse; margin: 16px 0;'>
                                        <tr><td style='padding: 4px 12px 4px 0; color: #666;'>Username:</td><td><strong>" . htmlspecialchars($username) . "</strong></td></tr>
                                        <tr><td style='padding: 4px 12px 4px 0; color: #666;'>Temporary password:</td><td><strong>" . htmlspecialchars($result['temporary_password']) . "</strong></td></tr>
                                    </table>
                                    <p>Log in at <a href='" . htmlspecialchars($login_url) . "'>" . htmlspecialchars($login_url) . "</a>. You'll be asked to set your own password the first time you sign in.</p>
                                ";
                                send_email($email, $welcome_subject, $welcome_body, null, 'account_welcome', null, null);
                            }
                            
                            // Redirect to prevent form resubmission
                            header("Location: " . $_SERVER['PHP_SELF'] . "?success=admin_temp&temp_pass=" . urlencode($result['temporary_password']));
                            exit();
                        } else {
                            $message = $result['message'];
                            $message_type = "error";
                            if ($debug) error_log("Admin temp password creation failed: " . $result['message']);
                        }
                    }
                }
                
                $stmt->close();
                $conn->rollback();
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error creating admin: " . $e->getMessage();
                $message_type = "error";
                if ($debug) error_log("Exception in admin create: " . $e->getMessage());
            }
        }
    }
    
    /// Create new CAS Leader account
if (isset($_POST['create_leader']) && $_POST['create_leader'] === '1') {
    if ($debug) error_log("Creating new CAS leader");
    
    $username = trim($_POST['username'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $student_id = !empty($_POST['student_id']) ? (int)$_POST['student_id'] : null;
    
    // Check if temporary password option is selected
    $use_temp_password = isset($_POST['use_temp_password']) && $_POST['use_temp_password'] === '1';
    
    if ($debug) {
        error_log("Form data - Username: $username, Email: $email, First: $first_name, Last: $last_name, Use temp password: " . ($use_temp_password ? 'Yes' : 'No'));
    }
    
    // Validate inputs
    if (empty($username) || empty($first_name) || empty($last_name) || empty($email)) {
        $message = "All required fields must be filled out.";
        $message_type = "error";
        if ($debug) error_log("Validation failed: empty fields");
    } elseif (!$use_temp_password && (empty($_POST['password']) || empty($_POST['confirm_password']))) {
        $message = "Password is required when not using temporary password.";
        $message_type = "error";
    } elseif (!$use_temp_password && $_POST['password'] !== $_POST['confirm_password']) {
        $message = "Passwords do not match.";
        $message_type = "error";
    } elseif (!$use_temp_password && strlen($_POST['password']) < 8) {
        $message = "Password must be at least 8 characters long.";
        $message_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
        $message_type = "error";
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Check if username already exists
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $message = "Username already exists. Please choose a different username.";
                $message_type = "error";
                if ($debug) error_log("Username already exists: $username");
                $conn->rollback();
            } else {
                // Check if email already exists
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $message = "Email already exists. Please use a different email address.";
                    $message_type = "error";
                    if ($debug) error_log("Email already exists: $email");
                    $conn->rollback();
                } else {
                    // Check student assignment if applicable
                    if ($student_id) {
                        $stmt = $conn->prepare("SELECT user_id FROM users WHERE student_id = ?");
                        if (!$stmt) {
                            throw new Exception("Prepare failed: " . $conn->error);
                        }
                        
                        $stmt->bind_param("i", $student_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows > 0) {
                            $message = "Selected student already has a user account.";
                            $message_type = "error";
                            $conn->rollback();
                            if ($debug) error_log("Student already has account: $student_id");
                            goto skip_creation;
                        }
                    }
                    
                    if ($use_temp_password) {
                        // Use the helper function to create user with temporary password
                        $result = createUserWithTempPassword($conn, $username, $first_name, $last_name, $email, 'cas_leader', $student_id);
                        
                        if ($result['success']) {
                            // Commit the transaction - this branch previously redirected
                            // without committing, meaning the account creation could be
                            // silently discarded when the transaction rolled back at
                            // connection close.
                            $conn->commit();
                            $conn->autocommit(true);
                            
                            $message = "CAS Leader account created successfully with temporary password: <strong>" . $result['temporary_password'] . "</strong><br><small>Make sure to share this password securely with the user. They will be required to change it on first login.</small>";
                            $message_type = "success";
                            if ($debug) error_log("User created with temp password: " . $result['user_id']);
                            
                            // Email the new leader their login credentials
                            if (!empty($email)) {
                                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                                $login_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/login.php";
                                $welcome_subject = "Your CAS Platform Account";
                                $welcome_body = "
                                    <p>Hi " . htmlspecialchars($first_name) . ",</p>
                                    <p>An account has been created for you on the UWC Mostar CAS Platform.</p>
                                    <table style='border-collapse: collapse; margin: 16px 0;'>
                                        <tr><td style='padding: 4px 12px 4px 0; color: #666;'>Username:</td><td><strong>" . htmlspecialchars($username) . "</strong></td></tr>
                                        <tr><td style='padding: 4px 12px 4px 0; color: #666;'>Temporary password:</td><td><strong>" . htmlspecialchars($result['temporary_password']) . "</strong></td></tr>
                                    </table>
                                    <p>Log in at <a href='" . htmlspecialchars($login_url) . "'>" . htmlspecialchars($login_url) . "</a>. You'll be asked to set your own password the first time you sign in.</p>
                                ";
                                send_email($email, $welcome_subject, $welcome_body, null, 'account_welcome', null, null);
                            }
                            
                            // Redirect to prevent form resubmission
                            header("Location: " . $_SERVER['PHP_SELF'] . "?success=cas_leader_temp&temp_pass=" . urlencode($result['temporary_password']));
                            exit();
                        } else {
                            $message = $result['message'];
                            $message_type = "error";
                            if ($debug) error_log("Temp password creation failed: " . $result['message']);
                        }
                    } else {
                        // Create with regular password (existing functionality)
                        $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        if ($debug) error_log("Password hashed successfully");
                        
                        // Insert new user with regular password
                        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, first_name, last_name, email, user_status, student_id, is_temporary_password, created_at) VALUES (?, ?, ?, ?, ?, 'cas_leader', ?, 0, NOW())");
                        if (!$stmt) {
                            throw new Exception("Prepare failed: " . $conn->error);
                        }
                        
                        $stmt->bind_param("sssssi", $username, $password_hash, $first_name, $last_name, $email, $student_id);
                        
                        if ($stmt->execute()) {
                            $new_user_id = $conn->insert_id;
                            $message = "CAS Leader account created successfully with custom password.";
                            $message_type = "success";
                            $conn->commit();
                            if ($debug) error_log("User created successfully with ID: $new_user_id");
                            
                            // Redirect to prevent form resubmission
                            header("Location: " . $_SERVER['PHP_SELF'] . "?success=cas_leader");
                            exit();
                        } else {
                            throw new Exception("Execute failed: " . $stmt->error);
                        }
                    }
                }
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error creating CAS leader: " . $e->getMessage();
            $message_type = "error";
            if ($debug) error_log("Exception in create: " . $e->getMessage());
        }
    }
    
    skip_creation:
}
    
    // Update existing user (Admin or CAS Leader)
    if (isset($_POST['update_user']) && $_POST['update_user'] === '1') {
        if ($debug) error_log("Updating user");
        
        $user_id = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $user_status = $_POST['user_status'] ?? '';
        $student_id = !empty($_POST['student_id']) ? (int)$_POST['student_id'] : null;
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');
        
        if ($debug) {
            error_log("Update data - ID: $user_id, Username: $username, Email: $email, Status: $user_status");
        }
        
        // Validate inputs
        if (empty($user_id) || empty($username) || empty($first_name) || empty($last_name) || empty($email) || empty($user_status)) {
            $message = "All required fields must be filled out.";
            $message_type = "error";
            if ($debug) error_log("Update validation failed: empty fields");
        } elseif (!in_array($user_status, ['admin', 'cas_leader'])) {
            $message = "Invalid user status.";
            $message_type = "error";
            if ($debug) error_log("Update validation failed: invalid status");
        } elseif (!empty($new_password) && $new_password !== $confirm_password) {
            $message = "New passwords do not match.";
            $message_type = "error";
            if ($debug) error_log("Update validation failed: passwords don't match");
        } elseif (!empty($new_password) && strlen($new_password) < 8) {
            $message = "New password must be at least 8 characters long.";
            $message_type = "error";
            if ($debug) error_log("Update validation failed: password too short");
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Check if username already exists for another user
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                
                $stmt->bind_param("si", $username, $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $message = "Username already exists. Please choose a different username.";
                    $message_type = "error";
                    if ($debug) error_log("Update failed: username exists for another user");
                } else {
                    // Check if email already exists for another user
                    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    
                    $stmt->bind_param("si", $email, $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $message = "Email already exists. Please use a different email address.";
                        $message_type = "error";
                        if ($debug) error_log("Update failed: email exists for another user");
                    } else {
                        // Update user
                        if (!empty($new_password)) {
                            // Hash new password and update. Also clear any stale
                            // temp_password_plaintext - if it's still set from account
                            // creation, it no longer reflects this user's real password
                            // and shouldn't keep showing up on the export page.
                            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                            $stmt = $conn->prepare("UPDATE users SET username = ?, password_hash = ?, temp_password_plaintext = NULL, first_name = ?, last_name = ?, email = ?, user_status = ?, student_id = ? WHERE user_id = ?");
                            if (!$stmt) {
                                throw new Exception("Prepare failed: " . $conn->error);
                            }
                            $stmt->bind_param("ssssssii", $username, $password_hash, $first_name, $last_name, $email, $user_status, $student_id, $user_id);
                        } else {
                            // Update without changing password
                            $stmt = $conn->prepare("UPDATE users SET username = ?, first_name = ?, last_name = ?, email = ?, user_status = ?, student_id = ? WHERE user_id = ?");
                            if (!$stmt) {
                                throw new Exception("Prepare failed: " . $conn->error);
                            }
                            $stmt->bind_param("sssssii", $username, $first_name, $last_name, $email, $user_status, $student_id, $user_id);
                        }
                        
                        if ($stmt->execute()) {
                            $affected_rows = $stmt->affected_rows;
                            if ($affected_rows > 0) {
                                $message = "User updated successfully.";
                                $message_type = "success";
                                $conn->commit();
                                if ($debug) error_log("User updated successfully. Affected rows: $affected_rows");
                                
                                // Redirect to prevent form resubmission
                                header("Location: " . $_SERVER['PHP_SELF'] . "?updated=1");
                                exit();
                            } else {
                                $message = "No changes were made or user not found.";
                                $message_type = "error";
                                if ($debug) error_log("Update failed: no rows affected");
                            }
                        } else {
                            throw new Exception("Execute failed: " . $stmt->error);
                        }
                    }
                }
                
                $stmt->close();
                $conn->rollback();
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error updating user: " . $e->getMessage();
                $message_type = "error";
                if ($debug) error_log("Exception in update: " . $e->getMessage());
            }
        }
    }
    
    // Delete user (Admin or CAS Leader)
    if (isset($_POST['delete_user']) && $_POST['delete_user'] === '1') {
        if ($debug) error_log("Deleting user");
        
        $user_id = (int)($_POST['user_id'] ?? 0);
        
        if (empty($user_id)) {
            $message = "Invalid user ID.";
            $message_type = "error";
            if ($debug) error_log("Delete failed: invalid user ID");
        } else {
            // Prevent deleting yourself
            if ($user_id == $_SESSION['user_id']) {
                $message = "You cannot delete your own account.";
                $message_type = "error";
                if ($debug) error_log("Delete failed: trying to delete own account");
            } else {
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Get user info before deletion
                    $stmt = $conn->prepare("SELECT user_status FROM users WHERE user_id = ?");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $user_to_delete = $result->fetch_assoc();
                    $stmt->close();
                    
                    if (!$user_to_delete) {
                        $message = "User not found.";
                        $message_type = "error";
                        $conn->rollback();
                    } else {
                        // Check if this is the last admin
                        if ($user_to_delete['user_status'] === 'admin') {
                            $stmt = $conn->prepare("SELECT COUNT(*) as admin_count FROM users WHERE user_status = 'admin'");
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $admin_count = $result->fetch_assoc()['admin_count'];
                            $stmt->close();
                            
                            if ($admin_count <= 1) {
                                $message = "Cannot delete the last admin account. Create another admin first.";
                                $message_type = "error";
                                $conn->rollback();
                                if ($debug) error_log("Delete failed: trying to delete last admin");
                                goto delete_cleanup;
                            }
                        }
                        
                        // Handle data reassignment for CAS leaders
                        if ($user_to_delete['user_status'] === 'cas_leader') {
                            $system_user_id = null;
                            
                            // Try to find an admin user to assign records to
                            $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_status = 'admin' LIMIT 1");
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($result->num_rows > 0) {
                                $system_user = $result->fetch_assoc();
                                $system_user_id = $system_user['user_id'];
                                if ($debug) error_log("Found admin user to assign records to: $system_user_id");
                            }
                            $stmt->close();
                            
                            if ($system_user_id) {
                                // Reassign attendance sessions to the admin user
                                $stmt = $conn->prepare("UPDATE attendance_sessions SET recorded_by = ? WHERE recorded_by = ?");
                                if ($stmt) {
                                    $stmt->bind_param("ii", $system_user_id, $user_id);
                                    $stmt->execute();
                                    $sessions_updated = $stmt->affected_rows;
                                    $stmt->close();
                                    if ($debug) error_log("Updated attendance_sessions: $sessions_updated rows reassigned to user $system_user_id");
                                }
                            }
                            
                            // Delete from cas_leaders table
                            $stmt = $conn->prepare("DELETE FROM cas_leaders WHERE user_id = ?");
                            if ($stmt) {
                                $stmt->bind_param("i", $user_id);
                                $stmt->execute();
                                $cas_leaders_deleted = $stmt->affected_rows;
                                $stmt->close();
                                if ($debug) error_log("Deleted from cas_leaders: $cas_leaders_deleted rows");
                            }
                        }
                        
                        // Delete remember tokens if table exists
                        $table_check = $conn->query("SHOW TABLES LIKE 'remember_tokens'");
                        if ($table_check && $table_check->num_rows > 0) {
                            $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
                            if ($stmt) {
                                $stmt->bind_param("i", $user_id);
                                $stmt->execute();
                                $tokens_deleted = $stmt->affected_rows;
                                $stmt->close();
                                if ($debug) error_log("Deleted from remember_tokens: $tokens_deleted rows");
                            }
                        }
                        
                        // Finally delete the user
                        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                        if (!$stmt) {
                            throw new Exception("Prepare failed: " . $conn->error);
                        }
                        $stmt->bind_param("i", $user_id);
                        
                        if ($stmt->execute()) {
                            $users_deleted = $stmt->affected_rows;
                            if ($users_deleted > 0) {
                                $success_message = "User deleted successfully.";
                                if (isset($sessions_updated) && $sessions_updated > 0) {
                                    $success_message .= " Note: $sessions_updated attendance session records were reassigned to an admin user.";
                                }
                                $message = $success_message;
                                $message_type = "success";
                                $conn->commit();
                                if ($debug) error_log("User deleted successfully. Rows deleted: $users_deleted");
                                
                                // Redirect to prevent form resubmission
                                header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1");
                                exit();
                            } else {
                                $message = "User not found or could not be deleted.";
                                $message_type = "error";
                                if ($debug) error_log("Delete failed: no user rows affected");
                                $conn->rollback();
                            }
                        } else {
                            throw new Exception("Execute failed: " . $stmt->error);
                        }
                        
                        $stmt->close();
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "Error deleting user: " . $e->getMessage();
                    $message_type = "error";
                    if ($debug) error_log("Exception in delete: " . $e->getMessage());
                }
            }
        }
        
        delete_cleanup:
    }
    
    // Assign CAS Leader to Activity (only for CAS leaders)
    if (isset($_POST['assign_activity']) && $_POST['assign_activity'] === '1') {
        if ($debug) error_log("Assigning leader to activity");
        
        $leader_user_id = (int)($_POST['leader_user_id'] ?? 0);
        $cas_id = (int)($_POST['cas_id'] ?? 0);
        
        // Validate inputs
        if (empty($leader_user_id) || empty($cas_id)) {
            $message = "Please select both a leader and a CAS activity.";
            $message_type = "error";
            if ($debug) error_log("Assignment failed: empty fields");
        } else {
            try {
                // Check if selected user is a CAS leader
                $stmt = $conn->prepare("SELECT user_status FROM users WHERE user_id = ? AND user_status = 'cas_leader'");
                $stmt->bind_param("i", $leader_user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    $message = "Selected user is not a CAS leader.";
                    $message_type = "error";
                    if ($debug) error_log("Assignment failed: user is not a CAS leader");
                } else {
                    // Check if this assignment already exists
                    $stmt = $conn->prepare("SELECT cas_leader_id FROM cas_leaders WHERE user_id = ? AND cas_id = ?");
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param("ii", $leader_user_id, $cas_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $message = "This leader is already assigned to the selected CAS activity.";
                        $message_type = "error";
                        if ($debug) error_log("Assignment failed: already exists");
                    } else {
                        // Insert new assignment
                        $stmt = $conn->prepare("INSERT INTO cas_leaders (user_id, cas_id, created_at) VALUES (?, ?, NOW())");
                        if (!$stmt) {
                            throw new Exception("Prepare failed: " . $conn->error);
                        }
                        $stmt->bind_param("ii", $leader_user_id, $cas_id);
                        
                        if ($stmt->execute()) {
                            $assignment_id = $conn->insert_id;
                            $message = "CAS Leader assigned to activity successfully.";
                            $message_type = "success";
                            if ($debug) error_log("Assignment created with ID: $assignment_id");
                            // After successful assignment creation, check if this leader is also a student
// and automatically enroll them in the CAS activity they're leading
$check_student_stmt = $conn->prepare("SELECT student_id FROM users WHERE user_id = ? AND student_id IS NOT NULL");
$check_student_stmt->bind_param("i", $leader_user_id);
$check_student_stmt->execute();
$student_result = $check_student_stmt->get_result();

if ($student_result->num_rows > 0) {
    $student_data = $student_result->fetch_assoc();
    $student_id = $student_data['student_id'];
    
    // Check if this student is already enrolled in this CAS activity
    $check_enrollment_stmt = $conn->prepare("SELECT enrollment_id FROM student_cas_enrollment WHERE student_id = ? AND cas_id = ?");
    $check_enrollment_stmt->bind_param("ii", $student_id, $cas_id);
    $check_enrollment_stmt->execute();
    $enrollment_result = $check_enrollment_stmt->get_result();
    
    if ($enrollment_result->num_rows === 0) {
        // Student is not enrolled, so enroll them automatically
        $auto_enroll_stmt = $conn->prepare("INSERT INTO student_cas_enrollment (student_id, cas_id, enrollment_date, is_active) VALUES (?, ?, CURRENT_DATE(), 1)");
        $auto_enroll_stmt->bind_param("ii", $student_id, $cas_id);
        
        if ($auto_enroll_stmt->execute()) {
            $message .= " The leader has also been automatically enrolled as a student in this CAS activity.";
            if ($debug) error_log("Auto-enrolled student leader in CAS activity");
        }
        $auto_enroll_stmt->close();
    } elseif ($enrollment_result->num_rows > 0) {
        // Check if enrollment is inactive and reactivate it
        $enrollment_data = $enrollment_result->fetch_assoc();
        $check_active_stmt = $conn->prepare("SELECT is_active FROM student_cas_enrollment WHERE enrollment_id = ?");
        $check_active_stmt->bind_param("i", $enrollment_data['enrollment_id']);
        $check_active_stmt->execute();
        $active_result = $check_active_stmt->get_result();
        $active_data = $active_result->fetch_assoc();
        
        if ($active_data['is_active'] == 0) {
            // Reactivate the enrollment
            $reactivate_stmt = $conn->prepare("UPDATE student_cas_enrollment SET is_active = 1 WHERE enrollment_id = ?");
            $reactivate_stmt->bind_param("i", $enrollment_data['enrollment_id']);
            
            if ($reactivate_stmt->execute()) {
                $message .= " The leader's student enrollment in this CAS activity has been reactivated.";
                if ($debug) error_log("Reactivated student leader enrollment in CAS activity");
            }
            $reactivate_stmt->close();
        }
        $check_active_stmt->close();
    }
    
    $check_enrollment_stmt->close();
}
$check_student_stmt->close();
                            // Redirect to prevent form resubmission
                            header("Location: " . $_SERVER['PHP_SELF'] . "?assigned=1");
                            exit();
                        } else {
                            throw new Exception("Execute failed: " . $stmt->error);
                        }
                    }
                }
                $stmt->close();
            } catch (Exception $e) {
                $message = "Error assigning leader to activity: " . $e->getMessage();
                $message_type = "error";
                if ($debug) error_log("Exception in assignment: " . $e->getMessage());
            }
        }
    }
    
    // Remove leader from activity
    if (isset($_POST['remove_assignment']) && $_POST['remove_assignment'] === '1') {
        if ($debug) error_log("Removing assignment");
        
        $cas_leader_id = (int)($_POST['cas_leader_id'] ?? 0);
        
        if (empty($cas_leader_id)) {
            $message = "Invalid assignment ID.";
            $message_type = "error";
            if ($debug) error_log("Remove failed: invalid assignment ID");
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM cas_leaders WHERE cas_leader_id = ?");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("i", $cas_leader_id);
                
                if ($stmt->execute()) {
                    $deleted_rows = $stmt->affected_rows;
                    if ($deleted_rows > 0) {
                        $message = "Leader removed from activity successfully.";
                        $message_type = "success";
                        if ($debug) error_log("Assignment removed. Rows deleted: $deleted_rows");
                        
                        // Redirect to prevent form resubmission
                        header("Location: " . $_SERVER['PHP_SELF'] . "?removed=1");
                        exit();
                    } else {
                        $message = "Assignment not found.";
                        $message_type = "error";
                        if ($debug) error_log("Remove failed: no rows affected");
                    }
                } else {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                $stmt->close();
            } catch (Exception $e) {
                $message = "Error removing leader from activity: " . $e->getMessage();
                $message_type = "error";
                if ($debug) error_log("Exception in remove: " . $e->getMessage());
            }
        }
    }
}

// Handle success messages from redirects
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'admin') {
        $message = "Admin account created successfully.";
        $message_type = "success";
    } elseif ($_GET['success'] === 'cas_leader') {
        $message = "CAS Leader account created successfully.";
        $message_type = "success";
    } elseif ($_GET['success'] === 'cas_leader_temp') {
        $temp_pass = isset($_GET['temp_pass']) ? $_GET['temp_pass'] : '[password]';
        $message = "CAS Leader account created successfully! Temporary password: " . $temp_pass . " - please share this securely with the user. They must change it on first login.";
        $message_type = "success";
    } elseif ($_GET['success'] === 'admin_temp') {
        $temp_pass = isset($_GET['temp_pass']) ? $_GET['temp_pass'] : '[password]';
        $message = "Admin account created successfully! Temporary password: " . $temp_pass . " - please share this securely with the user. They must change it on first login.";
        $message_type = "success";
    }
}
if (isset($_GET['updated'])) {
    $message = "User updated successfully.";
    $message_type = "success";
}
if (isset($_GET['deleted'])) {
    $message = "User deleted successfully.";
    $message_type = "success";
}
if (isset($_GET['assigned'])) {
    $message = "CAS Leader assigned to activity successfully.";
    $message_type = "success";
}
if (isset($_GET['removed'])) {
    $message = "Leader removed from activity successfully.";
    $message_type = "success";
}

// Get user data for edit
$user = null;
if ($action === 'edit' && $user_id > 0) {
    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? AND user_status IN ('admin', 'cas_leader')");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if ($debug) error_log("User data loaded for edit: " . $user['username']);
        } else {
            $message = "User not found.";
            $message_type = "error";
            $action = 'list';
            if ($debug) error_log("User not found for edit: $user_id");
        }
        
        $stmt->close();
    } catch (Exception $e) {
        $message = "Error loading user data: " . $e->getMessage();
        $message_type = "error";
        if ($debug) error_log("Exception loading user for edit: " . $e->getMessage());
    }
}

// Get user data for delete confirmation
if ($action === 'delete' && $user_id > 0) {
    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? AND user_status IN ('admin', 'cas_leader')");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if ($debug) error_log("User data loaded for delete: " . $user['username']);
        } else {
            $message = "User not found.";
            $message_type = "error";
            $action = 'list';
            if ($debug) error_log("User not found for delete: $user_id");
        }
        
        $stmt->close();
    } catch (Exception $e) {
        $message = "Error loading user data: " . $e->getMessage();
        $message_type = "error";
        if ($debug) error_log("Exception loading user for delete: " . $e->getMessage());
    }
}

// Get all users for list view (both admins and CAS leaders)
$all_users = [];
$search = isset($_GET['search']) ? $_GET['search'] : '';

if ($action === 'list') {
    try {
        // Build query with search
        $query = "SELECT u.*, s.first_name as student_first_name, s.last_name as student_last_name 
                  FROM users u 
                  LEFT JOIN students s ON u.student_id = s.student_id 
                  WHERE u.user_status IN ('admin', 'cas_leader')";

        $params = [];
        $types = "";
        
        if (!empty($search)) {
            $search_term = "%" . $search . "%";
            $query .= " AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $types .= "ssss";
        }

        $query .= " ORDER BY 
            CASE u.user_status 
                WHEN 'admin' THEN 1 
                WHEN 'cas_leader' THEN 2 
            END,
            u.last_name, u.first_name";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $all_users[] = $row;
        }
        
        $stmt->close();
        if ($debug) error_log("Loaded " . count($all_users) . " users");
    } catch (Exception $e) {
        $message = "Error loading users: " . $e->getMessage();
        $message_type = "error";
        if ($debug) error_log("Exception loading users: " . $e->getMessage());
    }
}

// Get eligible students for dropdown (students without user accounts)
$eligible_students = [];
try {
    $query = "SELECT s.student_id, s.first_name, s.last_name 
              FROM students s 
              LEFT JOIN users u ON s.student_id = u.student_id 
              WHERE u.user_id IS NULL OR u.user_id = ?
              AND s.is_active = 1
              ORDER BY s.last_name, s.first_name";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id); // If user_id is 0, it won't match any existing user
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $eligible_students[] = $row;
    }
    $stmt->close();
    if ($debug) error_log("Loaded " . count($eligible_students) . " eligible students");
} catch (Exception $e) {
    if ($debug) error_log("Exception loading students: " . $e->getMessage());
}

// Get CAS activities for assignment dropdown
$cas_activities = [];
try {
    $stmt = $conn->prepare("SELECT cas_id, cas_name FROM cas_activities ORDER BY cas_name");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $cas_activities[] = $row;
    }
    $stmt->close();
    if ($debug) error_log("Loaded " . count($cas_activities) . " CAS activities");
} catch (Exception $e) {
    if ($debug) error_log("Exception loading activities: " . $e->getMessage());
}

// Get CAS leaders only for activity assignments
$cas_leaders_only = [];
try {
    $stmt = $conn->prepare("SELECT user_id, first_name, last_name FROM users WHERE user_status = 'cas_leader' ORDER BY last_name, first_name");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $cas_leaders_only[] = $row;
    }
    $stmt->close();
    if ($debug) error_log("Loaded " . count($cas_leaders_only) . " CAS leaders for assignments");
} catch (Exception $e) {
    if ($debug) error_log("Exception loading CAS leaders: " . $e->getMessage());
}

// Get leader assignments for the assignments section
$leader_assignments = [];
try {
    $stmt = $conn->prepare("
        SELECT cl.cas_leader_id, cl.user_id, cl.cas_id, cl.created_at,
               u.first_name as leader_first_name, u.last_name as leader_last_name, u.email,
               ca.cas_name,
               s.first_name as student_first_name, s.last_name as student_last_name
        FROM cas_leaders cl
        JOIN users u ON cl.user_id = u.user_id
        JOIN cas_activities ca ON cl.cas_id = ca.cas_id
        LEFT JOIN students s ON u.student_id = s.student_id
        ORDER BY ca.cas_name, u.last_name, u.first_name
    ");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $leader_assignments[] = $row;
    }
    $stmt->close();
    if ($debug) error_log("Loaded " . count($leader_assignments) . " leader assignments");
} catch (Exception $e) {
    if ($debug) error_log("Exception loading assignments: " . $e->getMessage());
}
?>

<!-- Add debug information if debug mode is on -->
<?php if ($debug && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<div style="background: #f0f0f0; padding: 10px; margin: 10px; border: 1px solid #ccc; font-family: monospace; font-size: 12px;">
    <strong>DEBUG INFO:</strong><br>
    Action: <?php echo $action; ?><br>
    POST Keys: <?php echo implode(', ', array_keys($_POST)); ?><br>
    Current File: <?php echo basename($_SERVER['PHP_SELF']); ?><br>
    User ID: <?php echo $user_id; ?><br>
    <?php if (!empty($message)): ?>
    Message: <?php echo $message; ?> (<?php echo $message_type; ?>)<br>
    <?php endif; ?>
</div>
<?php endif; ?>

<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php 
        if ($action === 'create') echo "Create New CAS Leader";
        elseif ($action === 'create_admin') echo "Create New Admin";
        elseif ($action === 'edit') echo "Edit User";
        elseif ($action === 'delete') echo "Delete User";
        else echo "User Management";
        ?> - UWC Mostar CAS
    </title>
    <link rel="icon" type="image/x-icon" href="../tab.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 h-full">

<!-- Navigation Header -->
<nav class="bg-white shadow-lg border-b border-gray-200 fixed top-0 left-0 right-0 z-50">
        <div class="mx-auto px-4">
            <div class="flex justify-between items-center h-16">
                <!-- Left side: Logo and Mobile Menu Button -->
                <div class="flex items-center">
                    <!-- Mobile menu button -->
                    <button type="button" class="md:hidden inline-flex items-center justify-center p-2 rounded-md text-gray-600 hover:text-gray-900 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500 mr-3" id="mobile-menu-button">
                        <span class="sr-only">Open main menu</span>
                        <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    
                    <!-- Logo -->
                    <div class="flex items-center">
                        <img src="../850.png" alt="UWC Mostar Logo" class="h-8 w-auto mr-3">
                    </div>
                </div>

                <!-- Right side: User menu -->
                <div class="flex items-center space-x-4">
                    <!-- Admin Badge -->
                    <span class="hidden sm:inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                        Administrator
                    </span>
                    
                    <!-- User dropdown -->
                    <div class="relative">
                        <button class="flex items-center text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500" id="user-menu-button">
                            <span class="sr-only">Open user menu</span>
                            <div class="h-8 w-8 rounded-full bg-red-600 flex items-center justify-center text-white">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <span class="hidden md:ml-2 md:block text-gray-700 font-medium"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span>
                            <svg class="hidden md:block ml-1 h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                        
                        <!-- User dropdown menu -->
                        <div class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 ring-1 ring-black ring-opacity-5 z-50" id="user-menu-dropdown">
                            <div class="px-4 py-2 text-sm text-gray-700 border-b border-gray-100">
                                <p class="font-medium"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></p>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($_SESSION['email'] ?? 'admin@uwcmostar.ba'); ?></p>
                            </div>
                            <a href="../logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                                <i class="fas fa-sign-out-alt mr-2"></i>
                                Sign out
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Mobile sidebar overlay -->
    <div class="fixed inset-0 z-40 md:hidden hidden" id="mobile-sidebar-overlay">
        <div class="fixed inset-0 bg-gray-600 bg-opacity-75" id="mobile-sidebar-backdrop"></div>
        <div class="relative flex-1 flex flex-col max-w-xs w-full bg-white">
            <div class="absolute top-0 right-0 -mr-12 pt-2">
                <button type="button" class="ml-1 flex items-center justify-center h-10 w-10 rounded-full focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white" id="close-sidebar-button">
                    <span class="sr-only">Close sidebar</span>
                    <svg class="h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            
            <!-- Mobile navigation -->
            <div class="flex-1 h-0 pt-5 pb-4 overflow-y-auto">
                <div class="px-4 mb-6">
                    <div class="flex items-center">
                        <div class="h-8 w-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold mr-3">UWC</div>
                        <div>
                            <h1 class="text-lg font-bold text-gray-900">Admin Panel</h1>
                        </div>
                    </div>
                </div>
                
                <nav class="px-4 space-y-1">
                    <a href="dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="students" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' || basename($_SERVER['PHP_SELF']) == 'student_details.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-user-graduate mr-3 text-lg"></i>
                        Students
                    </a>
                    
                    <a href="cas_activities" class="<?php echo basename($_SERVER['PHP_SELF']) == 'cas_activities.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-alt mr-3 text-lg"></i>
                        CAS Activities
                    </a>
                    
                    <a href="<?php echo basename($_SERVER['PHP_SELF']); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-users mr-3 text-lg"></i>
                        User Management
                    </a>
                    
                    <a href="attendance_report" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance_report.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-chart-bar mr-3 text-lg"></i>
                        Attendance Reports
                    </a>
                    
                    <a href="manage_absences" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_absences.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-times mr-3 text-lg"></i>
                        Absence Requests
                    </a>
                    
                    <a href="activity_log" class="<?php echo basename($_SERVER['PHP_SELF']) == 'activity_log.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-history mr-3 text-lg"></i>
                        Activity Log
                    </a>
                     <a href="year_transition" class="<?php echo basename($_SERVER['PHP_SELF']) == 'year_transition.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-arrow-up mr-3 text-lg"></i>
                        Year Transition
                    </a>
                </nav>
            </div>
        </div>
    </div>

    <!-- Desktop sidebar -->
    <div class="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0 z-40">
        <div class="flex flex-col flex-1 bg-white border-r border-gray-200">
            <div class="flex-1 flex flex-col pt-5 pb-4">
                <div class="px-4 mb-6">
                    <div class="flex items-center">
                        <div class="h-8 w-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold mr-3">UWC</div>
                        <div>
                            <h1 class="text-lg font-bold text-gray-900">Admin Panel</h1>
                        </div>
                    </div>
                </div>
                
                <nav class="px-4 space-y-1">
                    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="students" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' || basename($_SERVER['PHP_SELF']) == 'student_details.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-user-graduate mr-3 text-lg"></i>
                        Students
                    </a>
                    
                    <a href="cas_activities" class="<?php echo basename($_SERVER['PHP_SELF']) == 'cas_activities.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-alt mr-3 text-lg"></i>
                        CAS Activities
                    </a>
                    
                    <a href="<?php echo basename($_SERVER['PHP_SELF']); ?>" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-users mr-3 text-lg"></i>
                        User Management
                    </a>
                    
                    <a href="attendance_report" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance_report.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-chart-bar mr-3 text-lg"></i>
                        Attendance Reports
                    </a>
                    
                    <a href="manage_absences" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_absences.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-times mr-3 text-lg"></i>
                        Absence Requests
                    </a>
                    
                    <a href="activity_log" class="<?php echo basename($_SERVER['PHP_SELF']) == 'activity_log.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-history mr-3 text-lg"></i>
                        Activity Log
                    </a>
                    <a href="year_transition" class="<?php echo basename($_SERVER['PHP_SELF']) == 'year_transition.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?>group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-arrow-up mr-3 text-lg"></i>
                        Year Transition
                    </a>
                </nav>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="md:pl-64 pt-16 min-h-screen flex flex-col bg-gray-100">
        <div class="flex-1 pb-20">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
                <!-- Page Header -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 sm:mb-8 space-y-4 sm:space-y-0">
                    <div class="flex items-center">
                        <?php if ($action !== 'list'): ?>
                        <a href="<?php echo basename($_SERVER['PHP_SELF']); ?>" class="mr-3 sm:mr-4 text-gray-600 hover:text-gray-800 transition-colors">
                            <i class="fas fa-arrow-left text-lg sm:text-xl"></i>
                        </a>
                        <?php endif; ?>
                        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">
                            <?php 
                            if ($action === 'create') echo "Create New CAS Leader";
                            elseif ($action === 'create_admin') echo "Create New Admin";
                            elseif ($action === 'edit') echo "Edit User";
                            elseif ($action === 'delete') echo "Delete User";
                            else echo "User Management";
                            ?>
                        </h1>
                    </div>
                    
                    <?php if ($action === 'list'): ?>
                    <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-3 w-full sm:w-auto">
                        <a href="?export=excel&type=admin<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                        class="w-full sm:w-auto bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-3 sm:px-4 rounded transition-colors text-center text-sm sm:text-base">
                         <i class="fas fa-download mr-2"></i> Export Admins
                        </a>
                        <a href="?export=excel&type=cas_leader<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                        class="w-full sm:w-auto bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-3 sm:px-4 rounded transition-colors text-center text-sm sm:text-base">
                         <i class="fas fa-download mr-2"></i> Export CAS Leaders
                        </a>
                        <a href="?action=create" class="w-full sm:w-auto bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-3 sm:px-4 rounded transition-colors text-center text-sm sm:text-base">
                        <i class="fas fa-plus mr-2"></i> Create CAS Leader
                         </a>
                         <a href="?action=create_admin" class="w-full sm:w-auto bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-3 sm:px-4 rounded transition-colors text-center text-sm sm:text-base">
            <i class="fas fa-user-shield mr-2"></i> Create Admin
        </a>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Success/Error Messages -->
                <?php if (!empty($message)): ?>
                <div class="mb-6 alert-dismissible <?php echo $message_type === 'error' ? 'bg-red-100 border-red-400 text-red-700' : 'bg-green-100 border-green-400 text-green-700'; ?> px-4 py-3 rounded relative border" role="alert">
                    <span class="block sm:inline"><?php echo htmlspecialchars($message); ?></span>
                    <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($action === 'create_admin'): ?>
                <!-- Create Admin Form -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-red-600 text-white px-4 sm:px-6 py-4">
                        <h2 class="text-lg sm:text-xl font-bold">Create New Administrator Account</h2>
                    </div>
                    
                    <div class="p-4 sm:p-6 lg:p-8">
                        <form action="<?php echo basename($_SERVER['PHP_SELF']); ?>" method="POST" class="space-y-6">
                            <input type="hidden" name="create_admin" value="1">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
                                        Username <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="username" name="username" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="Enter admin username">
                                </div>
                                
                                <div></div> <!-- Empty div for grid spacing -->
                                
                                <div>
                                    <label for="first_name" class="block text-sm font-medium text-gray-700 mb-2">
                                        First Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="first_name" name="first_name" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="Enter first name">
                                </div>
                                
                                <div>
                                    <label for="last_name" class="block text-sm font-medium text-gray-700 mb-2">
                                        Last Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="last_name" name="last_name" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="Enter last name">
                                </div>
                                
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                        Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" id="email" name="email" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="admin@example.com">
                                </div>
                                
                                <div></div> <!-- Empty div for grid spacing -->
                            </div>
                            
                            <div class="bg-blue-50 p-4 rounded-md border-l-4 border-blue-400">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-key text-blue-400 text-lg"></i>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm text-blue-700">
                                            A random temporary password will be generated automatically and emailed to this address. They'll be required to set their own password on first login.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-red-50 p-4 rounded-md border-l-4 border-red-400">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exclamation-triangle text-red-400 text-lg"></i>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-sm font-medium text-red-800 mb-2">Administrator Account Information</h3>
                                        <ul class="text-sm text-red-700 space-y-1">
                                            <li><strong>Full Access:</strong> Complete system administration privileges</li>
                                            <li><strong>User Management:</strong> Can create, edit, and delete all user accounts</li>
                                            <li><strong>System Control:</strong> Access to all administrative functions</li>
                                            <li><strong>Security Note:</strong> Admin accounts should only be given to trusted personnel</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-3 pt-6 border-t border-gray-200">
                                <a href="<?php echo basename($_SERVER['PHP_SELF']); ?>" class="w-full sm:w-auto text-center px-4 sm:px-6 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 transition-colors text-sm sm:text-base">
                                    <i class="fas fa-times mr-2"></i> Cancel
                                </a>
                                <button type="submit" class="w-full sm:w-auto px-4 sm:px-6 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 transition-colors text-sm sm:text-base">
                                    <i class="fas fa-user-shield mr-2"></i> Create Administrator
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($action === 'create'): ?>
                <!-- Create CAS Leader Form -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-purple-600 text-white px-4 sm:px-6 py-4">
                        <h2 class="text-lg sm:text-xl font-bold">Create New CAS Leader Account</h2>
                    </div>
                    
                    <div class="p-4 sm:p-6 lg:p-8">
                        <form action="<?php echo basename($_SERVER['PHP_SELF']); ?>" method="POST" class="space-y-6">
                            <input type="hidden" name="create_leader" value="1">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
                                        Username <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="username" name="username" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="Enter username">
                                </div>
                                
                                <div>
                                    <label for="student_id" class="block text-sm font-medium text-gray-700 mb-2">
                                        Link to Student (Optional)
                                    </label>
                                    <select id="student_id" name="student_id"
                                            class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base">
                                        <option value="">No student link</option>
                                        <?php foreach ($eligible_students as $student): ?>
                                        <option value="<?php echo $student['student_id']; ?>">
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-xs text-gray-500 mt-1">If selected, this CAS leader will be linked to a student profile</p>
                                </div>
                                
                                <div>
                                    <label for="first_name" class="block text-sm font-medium text-gray-700 mb-2">
                                        First Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="first_name" name="first_name" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="Enter first name">
                                </div>
                                
                                <div>
                                    <label for="last_name" class="block text-sm font-medium text-gray-700 mb-2">
                                        Last Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="last_name" name="last_name" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="Enter last name">
                                </div>
                                
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                        Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" id="email" name="email" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="leader@example.com">
                                </div>
                                
                                <div></div> <!-- Empty div for grid spacing -->
                                
                                <div class="bg-blue-50 p-4 rounded-md">
    <h3 class="text-sm font-medium text-gray-700 mb-3">Password Setup Options</h3>
    
    <div class="space-y-3">
        <label class="flex items-start">
            <input type="radio" id="use_temp_password" name="password_option" value="temp" class="form-radio h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 mt-1" checked>
            <div class="ml-3">
                <div class="text-sm font-medium text-gray-700">Generate temporary password (Recommended)</div>
                <div class="text-xs text-gray-500 mt-1">
                    System will create a secure temporary password using the user's name and current year. 
                    User will be required to change it on first login.
                </div>
            </div>
        </label>
        
        <label class="flex items-start">
            <input type="radio" id="use_custom_password" name="password_option" value="custom" class="form-radio h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 mt-1">
            <div class="ml-3">
                <div class="text-sm font-medium text-gray-700">Set custom password</div>
                <div class="text-xs text-gray-500 mt-1">
                    Manually set a password for this user account.
                </div>
            </div>
        </label>
    </div>
</div>

<!-- Password fields (initially hidden) -->
<div id="password_fields" class="grid grid-cols-1 md:grid-cols-2 gap-6" style="display: none;">
    <div>
        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
            Password <span class="text-red-500">*</span>
        </label>
        <input type="password" id="password" name="password" minlength="8"
               class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base"
               placeholder="Enter password">
        <p class="text-xs text-gray-500 mt-1">Minimum 8 characters</p>
    </div>
    
    <div>
        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">
            Confirm Password <span class="text-red-500">*</span>
        </label>
        <input type="password" id="confirm_password" name="confirm_password" minlength="8"
               class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base"
               placeholder="Confirm password">
    </div>
</div>

<!-- Hidden input for temporary password flag -->
<input type="hidden" id="use_temp_password_flag" name="use_temp_password" value="1">
                            
                            <div class="bg-gray-50 p-4 rounded-md">
                                <h3 class="text-sm font-medium text-gray-700 mb-2">CAS Leader Account Information</h3>
                                <ul class="text-sm text-gray-600 space-y-1">
                                    <li><strong>CAS Leader:</strong> Can manage their assigned CAS activities and student attendance</li>
                                    <li><strong>Login Access:</strong> This account will be able to log into the system</li>
                                    <li><strong>Student Link:</strong> Optionally link to an existing student profile if the leader is also a student</li>
                                </ul>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-3 pt-6 border-t border-gray-200">
                                <a href="<?php echo basename($_SERVER['PHP_SELF']); ?>" class="w-full sm:w-auto text-center px-4 sm:px-6 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 transition-colors text-sm sm:text-base">
                                    <i class="fas fa-times mr-2"></i> Cancel
                                </a>
                                <button type="submit" class="w-full sm:w-auto px-4 sm:px-6 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500 transition-colors text-sm sm:text-base">
                                    <i class="fas fa-plus mr-2"></i> Create CAS Leader
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($action === 'edit' && $user): ?>
                <!-- Edit User Form -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="<?php echo $user['user_status'] === 'admin' ? 'bg-red-600' : 'bg-indigo-600'; ?> text-white px-4 sm:px-6 py-4">
                        <h2 class="text-lg sm:text-xl font-bold">
                            Edit <?php echo ucfirst(str_replace('_', ' ', $user['user_status'])); ?>: 
                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                        </h2>
                    </div>
                    
                    <div class="p-4 sm:p-6 lg:p-8">
                        <form action="<?php echo basename($_SERVER['PHP_SELF']); ?>" method="POST" class="space-y-6">
                            <input type="hidden" name="update_user" value="1">
                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
                                        Username <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base">
                                </div>
                                
                                <div>
                                    <label for="user_status" class="block text-sm font-medium text-gray-700 mb-2">
                                        User Type <span class="text-red-500">*</span>
                                    </label>
                                    <select id="user_status" name="user_status" required
                                            class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base">
                                        <option value="admin" <?php echo $user['user_status'] === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                        <option value="cas_leader" <?php echo $user['user_status'] === 'cas_leader' ? 'selected' : ''; ?>>CAS Leader</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="first_name" class="block text-sm font-medium text-gray-700 mb-2">
                                        First Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base">
                                </div>
                                
                                <div>
                                    <label for="last_name" class="block text-sm font-medium text-gray-700 mb-2">
                                        Last Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base">
                                </div>
                                
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                        Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base">
                                </div>
                                
                                <div id="student_link_section" style="display: <?php echo $user['user_status'] === 'cas_leader' ? 'block' : 'none'; ?>;">
                                    <label for="student_id" class="block text-sm font-medium text-gray-700 mb-2">
                                        Link to Student (Optional)
                                    </label>
                                    <select id="student_id" name="student_id"
                                            class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base">
                                        <option value="">No student link</option>
                                        <?php foreach ($eligible_students as $student): ?>
                                        <option value="<?php echo $student['student_id']; ?>" <?php echo $user['student_id'] == $student['student_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="text-xs text-gray-500 mt-1">Only available for CAS Leaders</p>
                                </div>
                            </div>
                            
                            <div class="bg-gray-50 p-4 rounded-md border-t border-gray-200">
                                <h3 class="text-lg font-medium text-gray-900 mb-4">Change Password (Optional)</h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                                        <input type="password" id="new_password" name="new_password" minlength="8"
                                               class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base"
                                               placeholder="Leave blank to keep current password">
                                        <p class="text-xs text-gray-500 mt-1">Minimum 8 characters</p>
                                    </div>
                                    
                                    <div>
                                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                                        <input type="password" id="confirm_password" name="confirm_password"
                                               class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base"
                                               placeholder="Confirm new password">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-blue-50 p-4 rounded-md">
                                <h3 class="text-sm font-medium text-gray-700 mb-2">Account Information</h3>
                                <p class="text-sm text-gray-600">
                                    Created on: <?php echo date('F j, Y', strtotime($user['created_at'])); ?><br>
                                    Last login: <?php echo $user['last_login'] ? date('F j, Y g:i a', strtotime($user['last_login'])) : 'Never'; ?><br>
                                    Current Status: <span class="font-medium <?php echo $user['user_status'] === 'admin' ? 'text-red-600' : 'text-purple-600'; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $user['user_status'])); ?>
                                    </span>
                                </p>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-3 pt-6 border-t border-gray-200">
                                <a href="<?php echo basename($_SERVER['PHP_SELF']); ?>" class="w-full sm:w-auto text-center px-4 sm:px-6 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 transition-colors text-sm sm:text-base">
                                    <i class="fas fa-times mr-2"></i> Cancel
                                </a>
                                <button type="submit" class="w-full sm:w-auto px-4 sm:px-6 py-2 <?php echo $user['user_status'] === 'admin' ? 'bg-red-600 hover:bg-red-700 focus:ring-red-500' : 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-500'; ?> text-white rounded-md focus:outline-none focus:ring-2 transition-colors text-sm sm:text-base">
                                    <i class="fas fa-save mr-2"></i> Update User
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?><?php if ($action === 'delete' && $user): ?>
                <!-- Delete User Confirmation -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-red-600 text-white px-4 sm:px-6 py-4">
                        <h2 class="text-lg sm:text-xl font-bold">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            Delete <?php echo ucfirst(str_replace('_', ' ', $user['user_status'])); ?> Confirmation
                        </h2>
                    </div>
                    
                    <div class="p-4 sm:p-6 lg:p-8">
                        <!-- Warning Alert -->
                        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6 rounded-md">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-triangle text-red-400 text-lg"></i>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-red-800">
                                        Warning: This action cannot be undone!
                                    </h3>
                                    <p class="text-sm text-red-700 mt-2">
                                        This will permanently delete the <?php echo str_replace('_', ' ', $user['user_status']); ?> account and remove:
                                    </p>
                                    <ul class="text-sm text-red-700 mt-2 list-disc list-inside space-y-1">
                                        <li>User login credentials</li>
                                        <?php if ($user['user_status'] === 'cas_leader'): ?>
                                        <li>All CAS activity assignments</li>
                                        <?php endif; ?>
                                        <li>All system access and permissions</li>
                                        <li>All historical data</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <!-- User Information Card -->
                        <div class="bg-gray-50 rounded-lg p-4 sm:p-6 mb-6">
                            <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-4 sm:space-y-0 sm:space-x-6">
                                <div class="flex-shrink-0">
                                    <div class="h-16 w-16 bg-gradient-to-br <?php echo $user['user_status'] === 'admin' ? 'from-red-400 to-red-600' : 'from-purple-400 to-purple-600'; ?> rounded-full flex items-center justify-center shadow-lg">
                                        <?php if ($user['user_status'] === 'admin'): ?>
                                        <i class="fas fa-user-shield text-white text-2xl"></i>
                                        <?php else: ?>
                                        <span class="text-white font-bold text-xl">
                                            <?php echo substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="flex-grow">
                                    <h3 class="text-lg font-semibold text-gray-900 mb-2">
                                        <?php echo ucfirst(str_replace('_', ' ', $user['user_status'])); ?> Information
                                    </h3>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                                        <div>
                                            <span class="font-medium text-gray-700">Name:</span>
                                            <span class="ml-2"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                                        </div>
                                        <div>
                                            <span class="font-medium text-gray-700">Username:</span>
                                            <span class="ml-2">@<?php echo htmlspecialchars($user['username']); ?></span>
                                        </div>
                                        <div>
                                            <span class="font-medium text-gray-700">Email:</span>
                                            <span class="ml-2"><?php echo htmlspecialchars($user['email']); ?></span>
                                        </div>
                                        <div>
                                            <span class="font-medium text-gray-700">Created:</span>
                                            <span class="ml-2"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></span>
                                        </div>
                                        <div>
                                            <span class="font-medium text-gray-700">Last Login:</span>
                                            <span class="ml-2"><?php echo $user['last_login'] ? date('M j, Y', strtotime($user['last_login'])) : 'Never'; ?></span>
                                        </div>
                                        <div>
                                            <span class="font-medium text-gray-700">Status:</span>
                                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php echo $user['user_status'] === 'admin' ? 'bg-red-100 text-red-800' : 'bg-purple-100 text-purple-800'; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $user['user_status'])); ?>
                                            </span>
                                        </div>
                                        <?php if ($user['student_id']): ?>
                                        <div class="sm:col-span-2">
                                            <span class="font-medium text-gray-700">Student Link:</span>
                                            <span class="ml-2 text-blue-600">Yes (Student Account Linked)</span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Impact Assessment -->
                        <?php if ($user['user_status'] === 'cas_leader'): ?>
                        <?php
                        // Check for CAS activities led by this user
                        try {
                            $stmt = $conn->prepare("
                                SELECT ca.cas_name 
                                FROM cas_leaders cl
                                JOIN cas_activities ca ON cl.cas_id = ca.cas_id
                                WHERE cl.user_id = ?
                            ");
                            $stmt->bind_param("i", $user['user_id']);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            
                            if ($result->num_rows > 0): ?>
                            <div class="bg-amber-50 border-l-4 border-amber-400 p-4 mb-6 rounded-md">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-calendar-alt text-amber-400 text-lg"></i>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-sm font-medium text-amber-800">
                                            Current Activity Assignments
                                        </h3>
                                        <p class="text-sm text-amber-700 mt-2"><strong>Leading CAS Activities:</strong></p>
                                        <ul class="list-disc ml-6 text-sm text-amber-700">
                                            <?php while ($row = $result->fetch_assoc()): ?>
                                            <li><?php echo htmlspecialchars($row['cas_name']); ?></li>
                                            <?php endwhile; ?>
                                        </ul>
                                        <p class="text-sm text-amber-700 mt-2">These activities will need new leaders assigned after deletion.</p>
                                    </div>
                                </div>
                            </div>
                            <?php endif; 
                            $stmt->close();
                        } catch (Exception $e) {
                            if ($debug) error_log("Error checking assignments: " . $e->getMessage());
                        }
                        ?>
                        <?php elseif ($user['user_status'] === 'admin'): ?>
                        <!-- Admin deletion warning -->
                        <div class="bg-orange-50 border-l-4 border-orange-400 p-4 mb-6 rounded-md">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-shield-alt text-orange-400 text-lg"></i>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-orange-800">
                                        Administrator Account Deletion
                                    </h3>
                                    <p class="text-sm text-orange-700 mt-2">
                                        You are about to delete an administrator account. This will remove all administrative privileges for this user.
                                        Make sure there are other admin accounts available to manage the system.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <form action="<?php echo basename($_SERVER['PHP_SELF']); ?>" method="POST" class="space-y-4">
                            <input type="hidden" name="delete_user" value="1">
                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                            
                            <div class="bg-gray-50 p-4 rounded-md">
                                <label class="inline-flex items-center">
                                    <input type="checkbox" id="confirm_delete" class="form-checkbox h-4 w-4 text-red-600 rounded focus:ring-red-500 border-gray-300" required>
                                    <span class="ml-2 text-sm text-gray-700">
                                        I understand that this action is permanent and cannot be undone
                                    </span>
                                </label>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-3 pt-6 border-t border-gray-200">
                                <a href="<?php echo basename($_SERVER['PHP_SELF']); ?>" class="w-full sm:w-auto text-center px-4 sm:px-6 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 transition-colors text-sm sm:text-base">
                                    <i class="fas fa-times mr-2"></i> Cancel
                                </a>
                                <button type="submit" id="delete_button" disabled
                                        class="w-full sm:w-auto px-4 sm:px-6 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 transition-colors text-sm sm:text-base disabled:opacity-50 disabled:cursor-not-allowed">
                                    <i class="fas fa-trash mr-2"></i> Delete <?php echo ucfirst(str_replace('_', ' ', $user['user_status'])); ?> Permanently
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($action === 'list'): ?>
                <!-- Search and Filters -->
                <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 mb-6 sm:mb-8">
                    <form action="<?php echo basename($_SERVER['PHP_SELF']); ?>" method="GET" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search Users</label>
                                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Search by name, username, or email..." 
                                       class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 text-sm sm:text-base">
                            </div>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row justify-end space-y-2 sm:space-y-0 sm:space-x-3">
                            <button type="submit" class="w-full sm:w-auto px-4 sm:px-6 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500 text-sm sm:text-base transition-colors">
                                <i class="fas fa-search mr-2"></i> Search
                            </button>
                            <a href="<?php echo basename($_SERVER['PHP_SELF']); ?>" class="w-full sm:w-auto px-4 sm:px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 text-center text-sm sm:text-base transition-colors">
                                <i class="fas fa-redo mr-2"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Statistics -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6 sm:mb-8">
                    <?php
                    $total_users = count($all_users);
                    $total_admins = count(array_filter($all_users, function($u) { return $u['user_status'] === 'admin'; }));
                    $total_leaders = count(array_filter($all_users, function($u) { return $u['user_status'] === 'cas_leader'; }));
                    $leaders_with_students = count(array_filter($all_users, function($u) { return $u['user_status'] === 'cas_leader' && !empty($u['student_id']); }));
                    ?>
                    
                    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-blue-500">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 rounded-full bg-blue-100 text-blue-500 mr-3 sm:mr-4">
                                <i class="fas fa-users text-lg sm:text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs sm:text-sm text-gray-500 uppercase">Total Users</p>
                                <p class="text-xl sm:text-2xl font-bold"><?php echo $total_users; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-red-500">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 rounded-full bg-red-100 text-red-500 mr-3 sm:mr-4">
                                <i class="fas fa-user-shield text-lg sm:text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs sm:text-sm text-gray-500 uppercase">Administrators</p>
                                <p class="text-xl sm:text-2xl font-bold"><?php echo $total_admins; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-purple-500">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 rounded-full bg-purple-100 text-purple-500 mr-3 sm:mr-4">
                                <i class="fas fa-chalkboard-teacher text-lg sm:text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs sm:text-sm text-gray-500 uppercase">CAS Leaders</p>
                                <p class="text-xl sm:text-2xl font-bold"><?php echo $total_leaders; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 border-green-500">
                        <div class="flex items-center">
                            <div class="p-2 sm:p-3 rounded-full bg-green-100 text-green-500 mr-3 sm:mr-4">
                                <i class="fas fa-user-graduate text-lg sm:text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs sm:text-sm text-gray-500 uppercase">Student Leaders</p>
                                <p class="text-xl sm:text-2xl font-bold"><?php echo $leaders_with_students; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Users List -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6 sm:mb-8">
                    <div class="bg-gradient-to-r from-purple-600 to-red-600 text-white px-4 sm:px-6 py-4 flex flex-col sm:flex-row justify-between items-start sm:items-center space-y-2 sm:space-y-0">
                        <h2 class="text-lg sm:text-xl font-bold">
                            User Accounts (Admins & CAS Leaders)
                            <?php if (!empty($search)): ?>
                                <span class="text-sm font-normal">(Filtered Results)</span>
                            <?php endif; ?>
                        </h2>
                        
                        <div class="flex items-center space-x-3">
                            <?php if (!empty($all_users)): ?>
                            <a href="?export=excel&type=admin<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                               class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm transition-colors"
                               title="Export admin accounts to Excel/CSV">
                                <i class="fas fa-download mr-1"></i> Export Admins
                            </a>
                            <a href="?export=excel&type=cas_leader<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                               class="bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-1 rounded text-sm transition-colors"
                               title="Export CAS leader accounts to Excel/CSV">
                                <i class="fas fa-download mr-1"></i> Export CAS Leaders
                            </a>
                            <div class="text-sm text-white text-opacity-90">
                                Showing <?php echo count($all_users); ?> user<?php echo count($all_users) !== 1 ? 's' : ''; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (empty($all_users)): ?>
                    <div class="p-8 sm:p-12 text-center text-gray-500">
                        <i class="fas fa-users text-4xl sm:text-6xl text-gray-300 mb-4"></i>
                        <h3 class="text-lg sm:text-xl font-semibold mb-2">No Users Found</h3>
                        <p class="text-sm sm:text-base mb-4">
                            <?php if (!empty($search)): ?>
                                No users match your search criteria.
                            <?php else: ?>
                                There are no users in the system yet.
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($search)): ?>
                        <a href="<?php echo basename($_SERVER['PHP_SELF']); ?>" class="text-purple-600 hover:text-purple-800 font-medium">
                            <i class="fas fa-undo mr-1"></i> Clear search
                        </a>
                        <?php else: ?>
                        <div class="space-x-3">
                            <a href="?action=create" class="inline-block bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded transition-colors">
                                <i class="fas fa-plus mr-2"></i> Create CAS Leader
                            </a>
                            <a href="?action=create_admin" class="inline-block bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded transition-colors">
                                <i class="fas fa-user-shield mr-2"></i> Create Admin
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        User Info
                                    </th>
                                    <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Username/Email
                                    </th>
                                    <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        User Type
                                    </th>
                                    <th scope="col" class="hidden lg:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Student Profile
                                    </th>
                                    <th scope="col" class="hidden xl:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Last Login
                                    </th>
                                    <th scope="col" class="px-4 sm:px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($all_users as $user_item): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 <?php echo $user_item['user_status'] === 'admin' ? 'bg-gradient-to-br from-red-400 to-red-600' : 'bg-gradient-to-br from-purple-400 to-purple-600'; ?> rounded-full flex items-center justify-center shadow-sm">
                                                <?php if ($user_item['user_status'] === 'admin'): ?>
                                                <i class="fas fa-user-shield text-white text-sm"></i>
                                                <?php else: ?>
                                                <span class="text-white font-semibold text-sm">
                                                    <?php echo substr($user_item['first_name'], 0, 1) . substr($user_item['last_name'], 0, 1); ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($user_item['first_name'] . ' ' . $user_item['last_name']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    Created: <?php echo date('M j, Y', strtotime($user_item['created_at'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">@<?php echo htmlspecialchars($user_item['username']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user_item['email']); ?></div>
                                    </td>
                                    
                                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $user_item['user_status'] === 'admin' ? 'bg-red-100 text-red-800' : 'bg-purple-100 text-purple-800'; ?>">
                                            <?php echo $user_item['user_status'] === 'admin' ? 'Administrator' : 'CAS Leader'; ?>
                                        </span>
                                    </td>
                                    
                                    <td class="hidden lg:table-cell px-6 py-4 whitespace-nowrap">
                                        <?php if ($user_item['student_id']): ?>
                                            <div class="text-sm font-medium text-blue-600">
                                                <?php echo htmlspecialchars($user_item['student_first_name'] . ' ' . $user_item['student_last_name']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">Student Account Linked</div>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-400 italic">No student linked</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="hidden xl:table-cell px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo $user_item['last_login'] ? date('M j, Y', strtotime($user_item['last_login'])) : 'Never'; ?>
                                        </div>
                                        <?php if ($user_item['last_login']): ?>
                                        <div class="text-xs text-gray-500">
                                            <?php echo date('g:i a', strtotime($user_item['last_login'])); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                        <div class="flex justify-center items-center space-x-1">
                                            <a href="?action=edit&id=<?php echo $user_item['user_id']; ?>" 
                                               class="inline-flex items-center justify-center w-8 h-8 text-indigo-600 hover:text-indigo-900 hover:bg-indigo-50 rounded-full transition-colors" 
                                               title="Edit User">
                                                <i class="fas fa-edit text-sm"></i>
                                            </a>
                                            <?php if ($user_item['user_id'] != $_SESSION['user_id']): ?>
                                            <a href="?action=delete&id=<?php echo $user_item['user_id']; ?>" 
                                               class="inline-flex items-center justify-center w-8 h-8 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors" 
                                               title="Delete User"
                                               onclick="return confirm('Are you sure you want to proceed to delete confirmation?')">
                                                <i class="fas fa-trash text-sm"></i>
                                            </a>
                                            <?php else: ?>
                                            <span class="inline-flex items-center justify-center w-8 h-8 text-gray-400 cursor-not-allowed rounded-full" 
                                                  title="Cannot delete your own account">
                                                <i class="fas fa-ban text-sm"></i>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- CAS Leader Activity Assignments Section (only show if there are CAS leaders) -->
                <?php if (!empty($cas_leaders_only)): ?>
                <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6 sm:mb-8">
                    <div class="bg-indigo-600 text-white px-4 sm:px-6 py-4">
                        <h2 class="text-lg sm:text-xl font-bold">CAS Activity Assignments</h2>
                        <p class="text-sm text-indigo-100 mt-1">Assign CAS Leaders to manage specific activities</p>
                    </div>
                    
                    <div class="p-4 sm:p-6">
                        <!-- Assignment Form -->
                        <div class="bg-gray-50 rounded-lg p-4 mb-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Assign Leader to Activity</h3>
                            <form action="<?php echo basename($_SERVER['PHP_SELF']); ?>" method="POST" class="space-y-4">
                                <input type="hidden" name="assign_activity" value="1">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label for="leader_user_id" class="block text-sm font-medium text-gray-700 mb-2">
                                            Select CAS Leader <span class="text-red-500">*</span>
                                        </label>
                                        <select id="leader_user_id" name="leader_user_id" required
                                               class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm sm:text-base">
                                            <option value="">Choose a CAS Leader...</option>
                                            <?php foreach ($cas_leaders_only as $leader): ?>
                                            <option value="<?php echo $leader['user_id']; ?>">
                                                <?php echo htmlspecialchars($leader['first_name'] . ' ' . $leader['last_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label for="cas_id" class="block text-sm font-medium text-gray-700 mb-2">
                                            Select CAS Activity <span class="text-red-500">*</span>
                                        </label>
                                        <select id="cas_id" name="cas_id" required
                                               class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm sm:text-base">
                                            <option value="">Choose a CAS Activity...</option>
                                            <?php foreach ($cas_activities as $activity): ?>
                                            <option value="<?php echo $activity['cas_id']; ?>">
                                                <?php echo htmlspecialchars($activity['cas_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="flex justify-end">
                                    <button type="submit" class="px-4 sm:px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-colors text-sm sm:text-base">
                                        <i class="fas fa-plus mr-2"></i> Assign Leader
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Current Assignments -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Current Activity Assignments</h3>
                            
                            <?php if (empty($leader_assignments)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-calendar-alt text-4xl text-gray-300 mb-4"></i>
                                <h4 class="text-lg font-semibold mb-2">No Assignments Yet</h4>
                                <p class="text-sm">No CAS leaders have been assigned to activities yet.</p>
                            </div>
                            <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                CAS Activity
                                            </th>
                                            <th scope="col" class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Assigned Leader
                                            </th>
                                            <th scope="col" class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Assigned Date
                                            </th>
                                            <th scope="col" class="px-4 sm:px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($leader_assignments as $assignment): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($assignment['cas_name']); ?>
                                                </div>
                                            </td>
                                            
                                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-8 w-8 bg-gradient-to-br from-purple-400 to-purple-600 rounded-full flex items-center justify-center shadow-sm">
                                                        <span class="text-white font-semibold text-xs">
                                                            <?php echo substr($assignment['leader_first_name'], 0, 1) . substr($assignment['leader_last_name'], 0, 1); ?>
                                                        </span>
                                                    </div>
                                                    <div class="ml-3">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($assignment['leader_first_name'] . ' ' . $assignment['leader_last_name']); ?>
                                                        </div>
                                                        <div class="text-sm text-gray-500">
                                                            <?php echo htmlspecialchars($assignment['email']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            
                                            <td class="hidden md:table-cell px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">
                                                    <?php echo date('M j, Y', strtotime($assignment['created_at'])); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo date('g:i a', strtotime($assignment['created_at'])); ?>
                                                </div>
                                            </td>
                                            
                                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                <div class="flex justify-center">
                                                    <form action="<?php echo basename($_SERVER['PHP_SELF']); ?>" method="POST" class="inline" 
                                                          onsubmit="return confirm('Are you sure you want to remove this assignment?')">
                                                        <input type="hidden" name="remove_assignment" value="1">
                                                        <input type="hidden" name="cas_leader_id" value="<?php echo $assignment['cas_leader_id']; ?>">
                                                        <button type="submit" 
                                                                class="inline-flex items-center justify-center w-8 h-8 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                                                title="Remove Assignment">
                                                            <i class="fas fa-times text-sm"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-gray-800 text-white py-4 sm:py-6 mt-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col md:flex-row justify-between items-center">
                    <div class="mb-4 md:mb-0">
                        <p class="text-sm sm:text-base">&copy; 2025 UWC Mostar CAS Tracking System</p>
                    </div>
                    <div class="flex space-x-4">
                        <a href="../index.html" class="text-gray-300 hover:text-white text-sm sm:text-base">Home</a>
                        <a href="../index.html#about" class="text-gray-300 hover:text-white text-sm sm:text-base">About</a>
                        <a href="../index.html#contact" class="text-gray-300 hover:text-white text-sm sm:text-base">Contact</a>
                    </div>
                </div>
                <div class="border-t border-gray-700 mt-4 pt-4 text-center">
                    <p class="text-xs sm:text-sm text-gray-400">All rights reserved. (Roni Baker UWCiM25)</p>
                </div>
            </div>
        </footer>
    </div>
    
    <script>
        // Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileSidebarOverlay = document.getElementById('mobile-sidebar-overlay');
            const closeSidebarButton = document.getElementById('close-sidebar-button');
            const mobileSidebarBackdrop = document.getElementById('mobile-sidebar-backdrop');
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenuDropdown = document.getElementById('user-menu-dropdown');

            // Open mobile sidebar
            if (mobileMenuButton) {
                mobileMenuButton.addEventListener('click', function() {
                    mobileSidebarOverlay.classList.remove('hidden');
                });
            }

            // Close mobile sidebar
            function closeMobileSidebar() {
                if (mobileSidebarOverlay) {
                    mobileSidebarOverlay.classList.add('hidden');
                }
            }

            if (closeSidebarButton) {
                closeSidebarButton.addEventListener('click', closeMobileSidebar);
            }
            
            if (mobileSidebarBackdrop) {
                mobileSidebarBackdrop.addEventListener('click', closeMobileSidebar);
            }

            // User menu toggle
            if (userMenuButton && userMenuDropdown) {
                userMenuButton.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userMenuDropdown.classList.toggle('hidden');
                });

                // Close user menu when clicking outside
                document.addEventListener('click', function(e) {
                    if (!userMenuButton.contains(e.target) && !userMenuDropdown.contains(e.target)) {
                        userMenuDropdown.classList.add('hidden');
                    }
                });
            }

            // Close mobile sidebar when window is resized to desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeMobileSidebar();
                }
            });
        });

        // Show/hide student link section based on user type selection
        document.addEventListener('DOMContentLoaded', function() {
            const userStatusSelect = document.getElementById('user_status');
            const studentLinkSection = document.getElementById('student_link_section');
            
            if (userStatusSelect && studentLinkSection) {
                userStatusSelect.addEventListener('change', function() {
                    if (this.value === 'cas_leader') {
                        studentLinkSection.style.display = 'block';
                    } else {
                        studentLinkSection.style.display = 'none';
                        // Clear student selection when switching to admin
                        const studentSelect = document.getElementById('student_id');
                        if (studentSelect) {
                            studentSelect.value = '';
                        }
                    }
                });
            }
        });

        // Add export button loading state
        document.addEventListener('DOMContentLoaded', function() {
            const exportLinks = document.querySelectorAll('a[href*="export=excel"]');
            exportLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    // Show loading state
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Generating...';
                    this.classList.add('opacity-75', 'cursor-not-allowed');
                    
                    // Re-enable after download starts (estimated time)
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.classList.remove('opacity-75', 'cursor-not-allowed');
                    }, 3000);
                });
            });
        });

        // Form validation and password matching
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form[method="POST"]');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            const password = form.querySelector('input[name="password"]');
            const confirmPassword = form.querySelector('input[name="confirm_password"]');
            const newPassword = form.querySelector('input[name="new_password"]');
            const useTempPassword = form.querySelector('input[name="use_temp_password"]');
            
            // Skip password validation if using temporary password
            if (useTempPassword && useTempPassword.value === '1') {
                return; // Don't validate passwords for temp password option
            }
            
            // For create form
            if (password && confirmPassword && password.style.display !== 'none') {
                if (password.value !== confirmPassword.value) {
                    alert('Passwords do not match!');
                    e.preventDefault();
                    return false;
                }
                if (password.value.length < 8) {
                    alert('Password must be at least 8 characters long!');
                    e.preventDefault();
                    return false;
                }
            }
            
            // For edit form
            if (newPassword && confirmPassword) {
                if (newPassword.value && newPassword.value !== confirmPassword.value) {
                    alert('New passwords do not match!');
                    e.preventDefault();
                    return false;
                }
                if (newPassword.value && newPassword.value.length < 8) {
                    alert('New password must be at least 8 characters long!');
                    e.preventDefault();
                    return false;
                }
            }
            
            // Check required fields (but skip hidden password fields)
            const requiredFields = form.querySelectorAll('[required]');
            let hasEmpty = false;
            requiredFields.forEach(function(field) {
                // Skip validation for hidden password fields
                if (field.type === 'password' && field.closest('#password_fields').style.display === 'none') {
                    return;
                }
                if (!field.value.trim()) {
                    field.classList.add('border-red-500');
                    hasEmpty = true;
                } else {
                    field.classList.remove('border-red-500');
                }
            });
            
            if (hasEmpty) {
                alert('Please fill in all required fields!');
                e.preventDefault();
                return false;
            }
        });
    });
});

        // Delete confirmation checkbox handling
        document.addEventListener('DOMContentLoaded', function() {
            const confirmCheckbox = document.getElementById('confirm_delete');
            const deleteButton = document.getElementById('delete_button');
            
            if (confirmCheckbox && deleteButton) {
                confirmCheckbox.addEventListener('change', function() {
                    deleteButton.disabled = !this.checked;
                    if (this.checked) {
                        deleteButton.classList.remove('opacity-50', 'cursor-not-allowed');
                    } else {
                        deleteButton.classList.add('opacity-50', 'cursor-not-allowed');
                    }
                });
            }
        });

        // Loading states for buttons
        document.addEventListener('DOMContentLoaded', function() {
            const submitButtons = document.querySelectorAll('button[type="submit"]');
            submitButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const form = this.closest('form');
                    if (form && form.checkValidity()) {
                        // Only add loading state if form is valid
                        setTimeout(() => {
                            this.disabled = true;
                            const originalText = this.innerHTML;
                            this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                            
                            // Re-enable button after 5 seconds as failsafe
                            setTimeout(() => {
                                this.disabled = false;
                                this.innerHTML = originalText;
                            }, 5000);
                        }, 100);
                    }
                });
            });
        });

        // Auto-dismiss success messages
        document.addEventListener('DOMContentLoaded', function() {
            const successMessages = document.querySelectorAll('.bg-green-100');
            successMessages.forEach(function(message) {
                setTimeout(function() {
                    if (message && message.parentElement) {
                        message.style.opacity = '0';
                        message.style.transform = 'translateY(-10px)';
                        setTimeout(function() {
                            message.remove();
                        }, 300);
                    }
                }, 5000);
            });
        });

        // Username validation and formatting
        document.addEventListener('DOMContentLoaded', function() {
            const usernameInput = document.getElementById('username');
            if (usernameInput) {
                usernameInput.addEventListener('input', function() {
                    // Remove spaces and convert to lowercase
                    this.value = this.value.replace(/\s/g, '').toLowerCase();
                    
                    // Basic username validation
                    const usernameRegex = /^[a-z0-9_]+$/;
                    if (this.value && !usernameRegex.test(this.value)) {
                        this.classList.add('border-yellow-500');
                        this.title = 'Username should only contain lowercase letters, numbers, and underscores';
                    } else {
                        this.classList.remove('border-yellow-500');
                        this.title = '';
                    }
                });
            }
        });

        // Email validation
        document.addEventListener('DOMContentLoaded', function() {
            const emailInputs = document.querySelectorAll('input[type="email"]');
            emailInputs.forEach(function(input) {
                input.addEventListener('blur', function() {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (this.value && !emailRegex.test(this.value)) {
                        this.classList.add('border-red-500');
                    } else {
                        this.classList.remove('border-red-500');
                    }
                });
            });
        });

        // Enhanced table interactions
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(function(row) {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8fafc';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            });
        });

        // Form field focus management
        document.addEventListener('DOMContentLoaded', function() {
            const formInputs = document.querySelectorAll('input, select, textarea');
            formInputs.forEach(function(input) {
                input.addEventListener('focus', function() {
                    this.style.boxShadow = '0 0 0 3px rgba(124, 58, 237, 0.1)';
                });
                
                input.addEventListener('blur', function() {
                    this.style.boxShadow = '';
                });
            });
        });

        // Enhanced confirmation dialogs
        document.addEventListener('DOMContentLoaded', function() {
            const deleteLinks = document.querySelectorAll('a[href*="action=delete"]');
            deleteLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    const row = this.closest('tr');
                    if (row) {
                        const nameElement = row.querySelector('.font-medium');
                        const userName = nameElement ? nameElement.textContent.trim() : 'this user';
                        if (!confirm(`Are you sure you want to proceed to delete ${userName}?`)) {
                            e.preventDefault();
                        }
                    }
                });
            });

            const assignmentForms = document.querySelectorAll('form[method="POST"]');
            assignmentForms.forEach(function(form) {
                if (form.querySelector('[name="remove_assignment"]')) {
                    form.addEventListener('submit', function(e) {
                        const row = this.closest('tr');
                        if (row) {
                            const leaderElement = row.querySelector('.font-medium');
                            const activityElement = row.cells[0].querySelector('.font-medium');
                            const leaderName = leaderElement ? leaderElement.textContent.trim() : 'this leader';
                            const activityName = activityElement ? activityElement.textContent.trim() : 'this activity';
                            
                            if (!confirm(`Are you sure you want to remove ${leaderName} from ${activityName}?\n\nThis action cannot be undone.`)) {
                                e.preventDefault();
                            }
                        }
                    });
                }
            });
        });

        // Debug mode indicator (remove in production)
        <?php if ($debug): ?>
        console.log('User Management Debug Mode: ON');
        console.log('Current action:', '<?php echo $action; ?>');
        console.log('Total users loaded:', <?php echo count($all_users); ?>);
        console.log('Total admins:', <?php echo isset($total_admins) ? $total_admins : 0; ?>);
        console.log('Total CAS leaders:', <?php echo isset($total_leaders) ? $total_leaders : 0; ?>);
        console.log('Total assignments loaded:', <?php echo count($leader_assignments); ?>);
        <?php endif; ?>

        // Auto-clear URL parameters after displaying messages
        if (window.location.search.includes('success=') || 
            window.location.search.includes('updated=') || 
            window.location.search.includes('deleted=') || 
            window.location.search.includes('assigned=') || 
            window.location.search.includes('removed=')) {
            
            // Clear the URL parameters after 2 seconds
            setTimeout(function() {
                const url = new URL(window.location);
                url.searchParams.delete('success');
                url.searchParams.delete('updated');
                url.searchParams.delete('deleted');
                url.searchParams.delete('assigned');
                url.searchParams.delete('removed');
                window.history.replaceState({}, document.title, url.pathname + (url.search || ''));
            }, 2000);
        }
        document.addEventListener('DOMContentLoaded', function() {
    const tempPasswordRadio = document.getElementById('use_temp_password');
    const customPasswordRadio = document.getElementById('use_custom_password');
    const passwordFields = document.getElementById('password_fields');
    const tempPasswordFlag = document.getElementById('use_temp_password_flag');
    const passwordInputs = passwordFields.querySelectorAll('input[type="password"]');
    
    // Handle radio button changes
    function handlePasswordOptionChange() {
        if (tempPasswordRadio.checked) {
            passwordFields.style.display = 'none';
            tempPasswordFlag.value = '1';
            // Remove required attributes from password fields
            passwordInputs.forEach(input => {
                input.removeAttribute('required');
                input.value = '';
            });
        } else {
            passwordFields.style.display = 'grid';
            tempPasswordFlag.value = '0';
            // Add required attributes to password fields
            passwordInputs.forEach(input => {
                input.setAttribute('required', 'required');
            });
        }
    }
    
    if (tempPasswordRadio && customPasswordRadio) {
        tempPasswordRadio.addEventListener('change', handlePasswordOptionChange);
        customPasswordRadio.addEventListener('change', handlePasswordOptionChange);
        
        // Initialize the form state
        handlePasswordOptionChange();
    }
});

    </script>
</body>
</html>