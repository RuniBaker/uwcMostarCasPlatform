<?php
// Start session for user authentication
session_start();

// Store debug info in session before redirects
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($debug_info)) {
    $_SESSION['debug_info'] = $debug_info;
}

// Retrieve debug info from session after redirects
if (isset($_SESSION['debug_info'])) {
    $debug_info = $_SESSION['debug_info'];
    unset($_SESSION['debug_info']);
} else {
    $debug_info = "";
}

// Check if user is logged in and is an admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_status'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Database connection
require_once '../includes/db_connect.php';

// Message handling
$message = "";
$message_type = "";

// Test database connection
$conn_test = $conn->query("SELECT 1");
if (!$conn_test) {
    $debug_info .= "Database connection test failed: " . $conn->error . " ";
    $message = "Database connection error: " . $conn->error;
    $message_type = "error";
} else {
    $debug_info .= "Database connection OK. ";
}

// Test if students table exists and is accessible
$columns = ['student_id', 'first_name', 'last_name', 'email', 'grade_year', 'is_active', 'created_at'];
$columns_exist = true;
$missing_columns = [];

$stmt = $conn->query("DESCRIBE students");
if (!$stmt) {
    $debug_info .= "Students table access failed: " . $conn->error . " ";
    $message = "Database table error: " . $conn->error;
    $message_type = "error";
} else {
    $existing_columns = [];
    while ($row = $stmt->fetch_assoc()) {
        $existing_columns[] = $row['Field'];
    }
    foreach ($columns as $col) {
        if (!in_array($col, $existing_columns)) {
            $missing_columns[] = $col;
            $columns_exist = false;
        }
    }
    if ($columns_exist) {
        $debug_info .= "Students table accessible with all required columns. ";
    } else {
        $debug_info .= "Students table missing columns: " . implode(", ", $missing_columns) . ". ";
        $message = "Database schema error: Missing columns - " . implode(", ", $missing_columns);
        $message_type = "error";
    }
}

// Debug raw POST data and headers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $debug_info .= "POST request received. Raw POST data: " . print_r($_POST, true) . " ";
    $debug_info .= "Request headers: " . print_r(getallheaders(), true) . " ";
} else {
    $debug_info .= "No POST request received. Request method: " . $_SERVER['REQUEST_METHOD'] . " ";
}

// Handle success messages from redirects
if (isset($_GET['cas_leader_created'])) {
    $message = "CAS Leader account created successfully.";
    $message_type = "success";
}

// Process form submissions (CRUD Logic)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Create CAS Leader for Student
    if (isset($_POST['create_cas_leader']) && $_POST['create_cas_leader'] === '1') {
        $debug_info .= "Creating CAS leader for student. ";
        
        $student_id = (int)($_POST['student_id'] ?? 0);
        
        if (empty($student_id)) {
            $message = "Invalid student ID.";
            $message_type = "error";
            $debug_info .= "CAS leader creation failed: invalid student ID. ";
        } else {
            // Get student details
            $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
            if (!$stmt) {
                $debug_info .= "Student fetch query preparation failed: " . $conn->error . " ";
                $message = "Database error: " . $conn->error;
                $message_type = "error";
            } else {
                $stmt->bind_param("i", $student_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    $message = "Student not found.";
                    $message_type = "error";
                    $debug_info .= "Student not found for CAS leader creation. ";
                } else {
                    $student = $result->fetch_assoc();
                    $stmt->close();
                    
                    // Check if student already has a CAS leader account
                    $stmt = $conn->prepare("SELECT user_id FROM users WHERE student_id = ?");
                    if (!$stmt) {
                        $debug_info .= "User check query preparation failed: " . $conn->error . " ";
                        $message = "Database error: " . $conn->error;
                        $message_type = "error";
                    } else {
                        $stmt->bind_param("i", $student_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows > 0) {
                            $message = "This student already has a CAS leader account.";
                            $message_type = "error";
                            $debug_info .= "Student already has CAS leader account. ";
                        } else {
                            $stmt->close();
                            
                            $username = strtolower($student['first_name']);
                            $current_year = date('Y');
                            $password = strtolower($student['first_name'] . $student['last_name']) . $current_year;

                            $debug_info .= "Generated username: $username, password: $password. ";
                            
                            // Start transaction
                            $conn->begin_transaction();
                            
                            try {
                                // Check if username already exists, if so, add a number
                                $original_username = $username;
                                $counter = 1;
                                
                                while (true) {
                                    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                                    if (!$stmt) {
                                        throw new Exception("Username check query preparation failed: " . $conn->error);
                                    }
                                    $stmt->bind_param("s", $username);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    
                                    if ($result->num_rows === 0) {
                                        $stmt->close();
                                        break; // Username is available
                                    }
                                    
                                    $stmt->close();
                                    $username = $original_username . $counter;
                                    $counter++;
                                    
                                    if ($counter > 100) {
                                        throw new Exception("Could not generate unique username");
                                    }
                                }
                                
                                $debug_info .= "Final username: $username. ";
                                
                                // Hash password
                                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                                
                                // Create CAS leader account
                                $stmt = $conn->prepare("INSERT INTO users (username, password_hash, first_name, last_name, email, user_status, student_id, created_at) VALUES (?, ?, ?, ?, ?, 'cas_leader', ?, NOW())");
                                if (!$stmt) {
                                    throw new Exception("User insert query preparation failed: " . $conn->error);
                                }
                                
                                $stmt->bind_param("sssssi", $username, $password_hash, $student['first_name'], $student['last_name'], $student['email'], $student_id);
                                
                                if ($stmt->execute()) {
                                    $new_user_id = $conn->insert_id;
                                    $conn->commit();
                                    $debug_info .= "CAS leader created successfully with ID: $new_user_id. ";
                                    
                                    $_SESSION['debug_info'] = $debug_info;
                                    
                                    $message = "CAS Leader account created successfully for " . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . 
                                              ". Username: " . htmlspecialchars($username) . 
                                              ", Password: " . htmlspecialchars($password);
                                    $message_type = "success";
                                    
                                    // Redirect to prevent form resubmission
                                    header("Location: students.php?cas_leader_created=1");
                                    exit();
                                } else {
                                    throw new Exception("User insert failed: " . $stmt->error);
                                }
                                
                            } catch (Exception $e) {
                                $conn->rollback();
                                $debug_info .= "CAS leader creation exception: " . $e->getMessage() . " ";
                                $message = "Error creating CAS leader: " . $e->getMessage();
                                $message_type = "error";
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Add new student
    $debug_info .= "Checking for add_student key: " . (array_key_exists('add_student', $_POST) ? 'Yes' : 'No') . ". ";
    if (array_key_exists('add_student', $_POST)) {
        $debug_info .= "Add student form submitted. Value of add_student: " . htmlspecialchars($_POST['add_student']) . ". ";
        
        if ($_POST['add_student'] !== '1') {
            $debug_info .= "Invalid add_student value. Expected '1', got '" . htmlspecialchars($_POST['add_student']) . "'. ";
            $message = "Form submission error: Invalid add_student value.";
            $message_type = "error";
        } else {
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $grade_year = $_POST['grade_year'] ?? '';
            
            $debug_info .= "Form data - First Name: '$first_name', Last Name: '$last_name', Email: '$email', Grade Year: '$grade_year'. ";
            
            // Validate inputs
            if (empty($first_name) || empty($last_name) || empty($email) || empty($grade_year)) {
                $message = "All fields are required.";
                $message_type = "error";
                $debug_info .= "Validation failed - empty fields detected. ";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = "Invalid email format.";
                $message_type = "error";
                $debug_info .= "Validation failed - invalid email format. ";
            } else {
                $debug_info .= "Input validation passed. ";
                
                // Check if email exists
                $stmt = $conn->prepare("SELECT student_id FROM students WHERE email = ?");
                if (!$stmt) {
                    $debug_info .= "Email check query preparation failed: " . $conn->error . " ";
                    $message = "Database error during email check: " . $conn->error;
                    $message_type = "error";
                } else {
                    $stmt->bind_param("s", $email);
                    if (!$stmt->execute()) {
                        $debug_info .= "Email check query execution failed: " . $stmt->error . " ";
                        $message = "Database error during email check execution: " . $stmt->error;
                        $message_type = "error";
                    } else {
                        $result = $stmt->get_result();
                        if ($result->num_rows > 0) {
                            $message = "A student with this email already exists.";
                            $message_type = "error";
                            $debug_info .= "Email already exists in database. ";
                        } else {
                            $debug_info .= "Email is unique, proceeding with insertion. ";
                            
                            // Insert new student
                            $stmt = $conn->prepare("INSERT INTO students (first_name, last_name, email, grade_year, is_active) VALUES (?, ?, ?, ?, 1)");
                            if (!$stmt) {
                                $debug_info .= "Insert query preparation failed: " . $conn->error . " ";
                                $message = "Database error during insert preparation: " . $conn->error;
                                $message_type = "error";
                            } else {
                                $stmt->bind_param("ssss", $first_name, $last_name, $email, $grade_year);
                                if ($stmt->execute()) {
                                    $debug_info .= "Insert successful. New student ID: " . $conn->insert_id . ". ";
                                    $_SESSION['debug_info'] = $debug_info;
                                    $message = "Student added successfully.";
                                    $message_type = "success";
                                    header("Location: students.php");
                                    exit();
                                } else {
                                    $debug_info .= "Insert failed: " . $stmt->error . " ";
                                    $message = "Error adding student: " . $stmt->error;
                                    $message_type = "error";
                                }
                            }
                        }
                        $stmt->close();
                    }
                }
            }
        }
    } else {
        $debug_info .= "Add student form NOT submitted - add_student key missing. ";
    }
    
    // Update existing student
    if (array_key_exists('update_student', $_POST) && $_POST['update_student'] === '1') {
        $debug_info .= "Update student form submitted. ";
        
        $student_id = $_POST['student_id'] ?? '';
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $grade_year = $_POST['grade_year'] ?? '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $debug_info .= "Update data - ID: $student_id, First Name: '$first_name', Last Name: '$last_name', Email: '$email', Grade Year: '$grade_year', Active: $is_active. ";
        
        // Validate inputs
        if (empty($student_id) || empty($first_name) || empty($last_name) || empty($email) || empty($grade_year)) {
            $message = "All fields are required for update.";
            $message_type = "error";
            $debug_info .= "Update validation failed - empty fields. ";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Invalid email format.";
            $message_type = "error";
            $debug_info .= "Update validation failed - invalid email format. ";
        } else {
            // Check if email exists for another student
            $stmt = $conn->prepare("SELECT student_id FROM students WHERE email = ? AND student_id != ?");
            if (!$stmt) {
                $debug_info .= "Update email check query preparation failed: " . $conn->error . " ";
                $message = "Database error during update email check: " . $conn->error;
                $message_type = "error";
            } else {
                $stmt->bind_param("si", $email, $student_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $message = "Another student with this email already exists.";
                    $message_type = "error";
                    $debug_info .= "Email conflict detected during update. ";
                } else {
                    // Update student
                    $stmt = $conn->prepare("UPDATE students SET first_name = ?, last_name = ?, email = ?, grade_year = ?, is_active = ? WHERE student_id = ?");
                    if (!$stmt) {
                        $debug_info .= "Update query preparation failed: " . $conn->error . " ";
                        $message = "Database error during update preparation: " . $conn->error;
                        $message_type = "error";
                    } else {
                        $stmt->bind_param("ssssii", $first_name, $last_name, $email, $grade_year, $is_active, $student_id);
                        if ($stmt->execute()) {
                            $debug_info .= "Update successful. Affected rows: " . $stmt->affected_rows . ". ";
                            $_SESSION['debug_info'] = $debug_info;
                            $message = "Student updated successfully.";
                            $message_type = "success";
                            header("Location: students.php");
                            exit();
                        } else {
                            $debug_info .= "Update failed: " . $stmt->error . " ";
                            $message = "Error updating student: " . $stmt->error;
                            $message_type = "error";
                        }
                    }
                    $stmt->close();
                }
            }
        }
    }
    
    // Delete student
    if (array_key_exists('delete_student', $_POST) && $_POST['delete_student'] === '1') {
        $debug_info .= "Delete student form submitted. ";
        
        $student_id = $_POST['student_id'] ?? '';
        $debug_info .= "Deleting student ID: $student_id. ";
        
        if (empty($student_id) || !is_numeric($student_id)) {
            $debug_info .= "Delete failed - student ID missing or invalid. ";
            $message = "Error: Student ID missing or invalid.";
            $message_type = "error";
        } else {
            // Start transaction with proper error handling
            $conn->autocommit(FALSE);
            
            try {
                $student_id = (int)$student_id;
                $debug_info .= "Processing deletion for student ID: $student_id. ";
                
                // Check if student exists first
                $stmt = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare student existence check: " . $conn->error);
                }
                $stmt->bind_param("i", $student_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception("Student not found with ID: $student_id");
                }
                $stmt->close();
                $debug_info .= "Student exists, proceeding with deletion. ";
                
                // Check if student is a CAS leader (has user account)
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE student_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare user check: " . $conn->error);
                }
                $stmt->bind_param("i", $student_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $debug_info .= "Student is a CAS leader. ";
                    $user_row = $result->fetch_assoc();
                    $user_id = $user_row['user_id'];
                    $stmt->close();
                    
                    // Delete from users table
                    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare users deletion: " . $conn->error);
                    }
                    $stmt->bind_param("i", $user_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to delete from users: " . $stmt->error);
                    }
                    $stmt->close();
                    $debug_info .= "Deleted from users. ";
                } else {
                    $stmt->close();
                    $debug_info .= "Student is not a CAS leader. ";
                }
                
                // Delete from attendance_records (if exists)
                $table_check = $conn->query("SHOW TABLES LIKE 'attendance_records'");
                if ($table_check && $table_check->num_rows > 0) {
                    $stmt = $conn->prepare("DELETE FROM attendance_records WHERE student_id = ?");
                    if (!$stmt) {
                        throw new Exception("Failed to prepare attendance_records deletion: " . $conn->error);
                    }
                    $stmt->bind_param("i", $student_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to delete from attendance_records: " . $stmt->error);
                    }
                    $affected_attendance = $stmt->affected_rows;
                    $stmt->close();
                    $debug_info .= "Deleted $affected_attendance records from attendance_records. ";
                }
                
                // Finally delete the student
                $stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare students deletion: " . $conn->error);
                }
                $stmt->bind_param("i", $student_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to delete from students: " . $stmt->error);
                }
                $affected_students = $stmt->affected_rows;
                $stmt->close();
                $debug_info .= "Deleted $affected_students record from students. ";
                
                if ($affected_students === 0) {
                    throw new Exception("No student was deleted - student may not exist");
                }
                
                // Commit transaction
                $conn->commit();
                $conn->autocommit(TRUE);
                $debug_info .= "Transaction committed successfully. ";
                
                $_SESSION['debug_info'] = $debug_info;
                $message = "Student deleted successfully.";
                $message_type = "success";
                
                header("Location: students.php");
                exit();
                
            } catch (Exception $e) {
                // Rollback in case of error
                $conn->rollback();
                $conn->autocommit(TRUE);
                $debug_info .= "Transaction rolled back. Error: " . $e->getMessage() . " ";
                $message = "Error deleting student: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// Handle GET actions
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$student = null;

// Get student data for edit
if ($action === 'edit' && $student_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    if (!$stmt) {
        $debug_info .= "Edit query preparation failed: " . $conn->error . " ";
        $message = "Database error: " . $conn->error;
        $message_type = "error";
        $action = 'list';
    } else {
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $student = $result->fetch_assoc();
            $debug_info .= "Student data retrieved for edit. ID: $student_id. ";
        } else {
            $message = "Student not found.";
            $message_type = "error";
            $action = 'list';
            $debug_info .= "Student not found for edit. ID: $student_id. ";
        }
        
        $stmt->close();
    }
}

// Get student data for delete confirmation
if ($action === 'delete' && $student_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    if (!$stmt) {
        $debug_info .= "Delete query preparation failed: " . $conn->error . " ";
        $message = "Database error: " . $conn->error;
        $message_type = "error";
        $action = 'list';
    } else {
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $student = $result->fetch_assoc();
            $debug_info .= "Student data retrieved for delete. ID: $student_id. ";
        } else {
            $message = "Student not found.";
            $message_type = "error";
            $action = 'list';
            $debug_info .= "Student not found for delete. ID: $student_id. ";
        }
        
        $stmt->close();
    }
}

// Get all students for list view
$students = [];
$search = isset($_GET['search']) ? $_GET['search'] : '';
$year_filter = isset($_GET['year']) ? $_GET['year'] : 'all';
$active_filter = isset($_GET['active']) ? $_GET['active'] : 'all';

if ($action === 'list') {
    // Build query with filters
    $query = "SELECT DISTINCT s.* FROM students s WHERE 1=1";
    $params = [];
    $types = "";
    
    if (!empty($search)) {
        $search_term = "%" . $search . "%";
        $query .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "sss";
    }
    
    if ($year_filter !== 'all') {
        $query .= " AND s.grade_year = ?";
        $params[] = $year_filter;
        $types .= "s";
    }
    
    if ($active_filter !== 'all') {
        $query .= " AND s.is_active = ?";
        $active_val = ($active_filter === 'active') ? 1 : 0;
        $params[] = $active_val;
        $types .= "i";
    }
    
    $query .= " ORDER BY s.last_name, s.first_name";
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        $debug_info .= "List query preparation failed: " . $conn->error . " ";
        $message = "Database error: " . $conn->error;
        $message_type = "error";
    } else {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
      while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

$stmt->close();

// Remove duplicates by student_id
$unique_students = [];
foreach ($students as $student_row) {  // Use different variable name
    $unique_students[$student_row['student_id']] = $student_row;
}
$students = array_values($unique_students);

$debug_info .= "Students loaded: " . count($students) . ". ";
    }
    
    // Check CAS leader status for each student
    foreach ($students as &$student) {
        $stmt = $conn->prepare("
            SELECT DISTINCT u.user_id, u.username 
            FROM students s 
            LEFT JOIN users u ON s.student_id = u.student_id AND u.user_status = 'cas_leader'
            WHERE s.student_id = ?
            LIMIT 1
        ");
        
        if ($stmt) {
            $stmt->bind_param("i", $student['student_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $cas_leader = $result->fetch_assoc();
                if ($cas_leader['user_id'] !== null) {
                    $student['has_cas_leader'] = true;
                    $student['cas_leader_username'] = $cas_leader['username'];
                    $student['cas_leader_user_id'] = $cas_leader['user_id'];
                } else {
                    $student['has_cas_leader'] = false;
                }
            } else {
                $student['has_cas_leader'] = false;
            }
            $stmt->close();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - UWC Mostar CAS</title>
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
                        <img src="../850.png" alt="UWC Mostar Logo" class="h-8 w-auto mr-3">
                        <div>
                            <h1 class="text-lg font-bold text-gray-900">Admin Panel</h1>
                        </div>
                    </div>
                </div>
                
                <nav class="px-4 space-y-1">
                    <a href="/admin/dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="/admin/students" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-user-graduate mr-3 text-lg"></i>
                        Students
                    </a>
                    
                    <a href="/admin/cas_activities" class="<?php echo basename($_SERVER['PHP_SELF']) == 'cas_activities.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-alt mr-3 text-lg"></i>
                        CAS Activities
                    </a>
                    
                    <a href="/admin/users" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-users mr-3 text-lg"></i>
                        Users/Leaders
                    </a>
                    
                    <a href="/admin/attendance_report" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance_report.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-chart-bar mr-3 text-lg"></i>
                        Attendance Reports
                    </a>
                    
                    <a href="/admin/manage_absences" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_absences.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-times mr-3 text-lg"></i>
                        Absence Requests
                    </a>
                    
                    <a href="/admin/activity_log" class="<?php echo basename($_SERVER['PHP_SELF']) == 'activity_log.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-history mr-3 text-lg"></i>
                        Activity Log
                    </a>
                    
                    <a href="/admin/year_transition" class="<?php echo basename($_SERVER['PHP_SELF']) == 'year_transition.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-arrow-up mr-3 text-lg"></i>
                        Year Transition
                    </a>
                </nav>
            </div>
            
            <!-- Mobile logout -->
            <div class="flex-shrink-0 px-4 py-4 border-t border-gray-200">
                <a href="../logout.php" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt mr-3 text-lg"></i>
                    Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Desktop sidebar -->
    <div class="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0 md:pt-16">
        <div class="flex-1 flex flex-col bg-white border-r border-gray-200">
            <div class="flex-1 flex flex-col pt-5 pb-4 overflow-y-auto">
                <nav class="px-4 space-y-1">
                    <a href="/admin/dashboard" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-tachometer-alt mr-3 text-lg"></i>
                        Dashboard
                    </a>
                    
                    <a href="/admin/students" class="<?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-user-graduate mr-3 text-lg"></i>
                        Students
                    </a>
                    
                    <a href="/admin/cas_activities" class="<?php echo basename($_SERVER['PHP_SELF']) == 'cas_activities.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-alt mr-3 text-lg"></i>
                        CAS Activities
                    </a>
                    
                    <a href="/admin/users" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-users mr-3 text-lg"></i>
                        Users/Leaders
                    </a>
                    
                    <a href="/admin/attendance_report" class="<?php echo basename($_SERVER['PHP_SELF']) == 'attendance_report.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-chart-bar mr-3 text-lg"></i>
                        Attendance Reports
                    </a>
                    
                    <a href="/admin/manage_absences" class="<?php echo basename($_SERVER['PHP_SELF']) == 'manage_absences.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-calendar-times mr-3 text-lg"></i>
                        Absence Requests
                    </a>
                    
                    <a href="/admin/activity_log" class="<?php echo basename($_SERVER['PHP_SELF']) == 'activity_log.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-history mr-3 text-lg"></i>
                        Activity Log
                    </a>
                    
                    <a href="/admin/year_transition" class="<?php echo basename($_SERVER['PHP_SELF']) == 'year_transition.php' ? 'bg-blue-50 border-r-4 border-blue-600 text-blue-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md">
                        <i class="fas fa-arrow-up mr-3 text-lg"></i>
                        Year Transition
                    </a>
                </nav>
            </div>
            
            <!-- Desktop logout -->
            <div class="flex-shrink-0 px-4 py-4 border-t border-gray-200">
                <a href="../logout.php" class="group flex items-center px-2 py-2 text-sm font-medium rounded-md text-red-600 hover:bg-red-50">
                    <i class="fas fa-sign-out-alt mr-3 text-lg"></i>
                    Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="md:pl-64 pt-16 min-h-screen flex flex-col bg-gray-100">
        <div class="flex-1">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6 sm:mb-8">Manage Students</h1>

                <!-- Messages -->
                <?php if (!empty($message)): ?>
                <div class="mb-6 sm:mb-8 p-4 rounded-md shadow <?php echo $message_type === 'success' ? 'bg-green-50 border-l-4 border-green-400 text-green-700' : 'bg-red-50 border-l-4 border-red-400 text-red-700'; ?>">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-circle text-red-400'; ?>"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm sm:text-base"><?php echo htmlspecialchars($message); ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

               

                <?php if ($action === 'list'): ?>
                <!-- Students List -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-blue-600 text-white px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center">
                        <h2 class="text-lg sm:text-xl font-bold">Students List</h2>
                        <a href="students.php?action=add" class="text-xs sm:text-sm bg-blue-700 hover:bg-blue-800 text-white py-1 px-2 sm:px-3 rounded">
                            <i class="fas fa-plus mr-1"></i> Add New Student
                        </a>
                    </div>
                    <div class="p-4 sm:p-6 lg:p-8">
                        <!-- Enhanced Filters Section -->
                        <form method="GET" action="students.php" class="mb-6">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                                <div>
                                    <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                    <input type="text" 
                                           id="search" 
                                           name="search" 
                                           value="<?php echo htmlspecialchars($search); ?>" 
                                           placeholder="Search by name or email..."
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base">
                                </div>
                                <div>
                                    <label for="year" class="block text-sm font-medium text-gray-700 mb-1">Academic Year</label>
                                    <select id="year" 
                                            name="year" 
                                            class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base">
                                        <option value="all" <?php echo $year_filter === 'all' ? 'selected' : ''; ?>>All Years</option>
                                        <option value="Y1" <?php echo $year_filter === 'Y1' ? 'selected' : ''; ?>>Y1</option>
                                        <option value="Y2" <?php echo $year_filter === 'Y2' ? 'selected' : ''; ?>>Y2</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="active" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                    <select id="active" 
                                            name="active" 
                                            class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base">
                                        <option value="all" <?php echo $active_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                        <option value="active" <?php echo $active_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $active_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="flex items-end space-x-2">
                                    <button type="submit" 
                                            class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base font-medium transition-colors duration-200">
                                        <i class="fas fa-search mr-2"></i>
                                        Apply Filters
                                    </button>
                                    <?php if (!empty($search) || $year_filter !== 'all' || $active_filter !== 'all'): ?>
                                    <a href="students.php" 
                                       class="px-3 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 text-sm sm:text-base transition-colors duration-200"
                                       title="Clear all filters">
                                        <i class="fas fa-times"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Filter Summary -->
                            <?php if (!empty($search) || $year_filter !== 'all' || $active_filter !== 'all'): ?>
                            <div class="bg-blue-50 border border-blue-200 rounded-md px-4 py-2 mb-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-4 text-sm text-blue-700">
                                        <span class="font-medium">Active Filters:</span>
                                        <?php if (!empty($search)): ?>
                                            <span class="bg-blue-100 px-2 py-1 rounded">
                                                Search: "<?php echo htmlspecialchars($search); ?>"
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($year_filter !== 'all'): ?>
                                            <span class="bg-blue-100 px-2 py-1 rounded">
                                                Year: <?php echo htmlspecialchars($year_filter); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($active_filter !== 'all'): ?>
                                            <span class="bg-blue-100 px-2 py-1 rounded">
                                                Status: <?php echo ucfirst($active_filter); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-sm text-blue-600 font-medium">
                                        <?php echo count($students); ?> student<?php echo count($students) !== 1 ? 's' : ''; ?> found
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </form>

                        <!-- Students Table with Enhanced CAS Leader Status -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-white">
                                <thead>
                                    <tr>
                                        <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                        <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                        <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">Year</th>
                                        <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="py-2 sm:py-3 px-2 sm:px-4 text-left bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">CAS Leader</th>
                                        <th class="py-2 sm:py-3 px-2 sm:px-4 text-right bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php if (empty($students)): ?>
                                    <tr>
                                        <td colspan="6" class="py-3 sm:py-4 px-2 sm:px-4 text-center text-gray-500 text-sm sm:text-base">No students found.</td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($students as $student): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="py-2 sm:py-4 px-2 sm:px-4 text-xs sm:text-sm text-gray-900"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                        <td class="py-2 sm:py-4 px-2 sm:px-4 text-xs sm:text-sm text-gray-500"><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td class="py-2 sm:py-4 px-2 sm:px-4 text-xs sm:text-sm text-gray-500"><?php echo htmlspecialchars($student['grade_year']); ?></td>
                                        <td class="py-2 sm:py-4 px-2 sm:px-4">
                                            <span class="inline-flex items-center px-2 sm:px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $student['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <i class="fas <?php echo $student['is_active'] ? 'fa-check-circle' : 'fa-times-circle'; ?> mr-1"></i>
                                                <?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td class="py-2 sm:py-4 px-2 sm:px-4">
                                            <?php if (isset($student['has_cas_leader']) && $student['has_cas_leader']): ?>
                                                <!-- Student has CAS leader account -->
                                                <a href="users.php?action=edit&id=<?php echo $student['cas_leader_user_id']; ?>" 
                                                   class="inline-flex items-center px-2 py-1 text-xs bg-green-100 text-green-800 rounded-full hover:bg-green-200 transition-colors" 
                                                   title="Edit CAS Leader Account">
                                                    <i class="fas fa-user-check mr-1"></i>
                                                    @<?php echo htmlspecialchars($student['cas_leader_username']); ?>
                                                </a>
                                            <?php else: ?>
                                                <!-- Student doesn't have CAS leader account -->
                                                <form method="POST" action="students.php" class="inline" onsubmit="return confirm('Create CAS Leader account for <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>?\n\nUsername: <?php echo strtolower($student['first_name']); ?>\nPassword: <?php echo strtolower($student['first_name'] . $student['last_name']) . date('Y'); ?>');">
                                                    <input type="hidden" name="create_cas_leader" value="1">
                                                    <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                                    <button type="submit" 
                                                            class="inline-flex items-center px-2 py-1 text-xs bg-purple-100 text-purple-800 rounded-full hover:bg-purple-200 transition-colors" 
                                                            title="Create CAS Leader Account">
                                                        <i class="fas fa-user-plus mr-1"></i>
                                                        Create Leader
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 sm:py-4 px-2 sm:px-4 text-right">
                                            <div class="flex items-center justify-end space-x-2">
                                                <!-- View Details Button -->
                                                <a href="student_details.php?id=<?php echo $student['student_id']; ?>" 
                                                   class="text-indigo-600 hover:text-indigo-800 p-1 rounded hover:bg-indigo-50 transition-colors" 
                                                   title="View Student Details">
                                                    <i class="fas fa-eye text-sm"></i>
                                                </a>
                                                
                                                <!-- Edit Button -->
                                                <a href="students.php?action=edit&id=<?php echo $student['student_id']; ?>" 
                                                   class="text-blue-600 hover:text-blue-800 p-1 rounded hover:bg-blue-50 transition-colors" 
                                                   title="Edit Student">
                                                    <i class="fas fa-edit text-sm"></i>
                                                </a>
                                                
                                                <!-- Delete Button -->
                                                <a href="students.php?action=delete&id=<?php echo $student['student_id']; ?>" 
                                                   class="text-red-600 hover:text-red-800 p-1 rounded hover:bg-red-50 transition-colors" 
                                                   title="Delete Student" 
                                                   onclick="return confirm('Are you sure you want to delete this student? This action cannot be undone.');">
                                                    <i class="fas fa-trash text-sm"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($action === 'add'): ?>
                <!-- Add Student Form -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-blue-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                        <h2 class="text-lg sm:text-xl font-bold">Add New Student</h2>
                    </div>
                    <div class="p-4 sm:p-6 lg:p-8">
                        <form action="students.php" method="POST" class="space-y-6">
                            <input type="hidden" name="add_student" value="1">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="first_name" class="block text-sm font-medium text-gray-700 mb-2">
                                        First Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="first_name" name="first_name" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="Enter first name">
                                </div>
                                <div>
                                    <label for="last_name" class="block text-sm font-medium text-gray-700 mb-2">
                                        Last Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="last_name" name="last_name" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="Enter last name">
                                </div>
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                        Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" id="email" name="email" required
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="student@example.com">
                                </div>
                                <div>
                                    <label for="grade_year" class="block text-sm font-medium text-gray-700 mb-2">
                                        Academic Year <span class="text-red-500">*</span>
                                    </label>
                                    <select id="grade_year" name="grade_year" required
                                            class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm sm:text-base">
                                        <option value="">Select Year</option>
                                        <option value="Y1">Y1 (Year 1)</option>
                                        <option value="Y2">Y2 (Year 2)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-md">
                                <h3 class="text-sm font-medium text-gray-700 mb-2">Additional Information</h3>
                                <p class="text-sm text-gray-600">
                                    The student will be created as <strong>Active</strong> by default. 
                                    You can modify their status later if needed.
                                </p>
                            </div>
                            <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-3 pt-6 border-t border-gray-200">
                                <a href="students.php" class="w-full sm:w-auto text-center px-4 sm:px-6 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 text-sm sm:text-base">
                                    <i class="fas fa-times mr-2"></i> Cancel
                                </a>
                                <button type="submit" name="add_student" value="1" class="w-full sm:w-auto px-4 sm:px-6 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 text-sm sm:text-base">
                                    <i class="fas fa-plus mr-2"></i> Add Student
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($action === 'edit' && $student): ?>
                <!-- Edit Student Form -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-indigo-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                        <h2 class="text-lg sm:text-xl font-bold">Edit Student: <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h2>
                    </div>
                    <div class="p-4 sm:p-6 lg:p-8">
                        <form action="students.php" method="POST" class="space-y-6">
                            <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="edit_first_name" class="block text-sm font-medium text-gray-700 mb-2">
                                        First Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="edit_first_name" name="first_name" required
                                           value="<?php echo htmlspecialchars($student['first_name']); ?>"
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="Enter first name">
                                </div>
                                <div>
                                    <label for="edit_last_name" class="block text-sm font-medium text-gray-700 mb-2">
                                        Last Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="edit_last_name" name="last_name" required
                                           value="<?php echo htmlspecialchars($student['last_name']); ?>"
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="Enter last name">
                                </div>
                                <div>
                                    <label for="edit_email" class="block text-sm font-medium text-gray-700 mb-2">
                                        Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" id="edit_email" name="email" required
                                           value="<?php echo htmlspecialchars($student['email']); ?>"
                                           class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm sm:text-base"
                                           placeholder="student@example.com">
                                </div>
                                <div>
                                    <label for="edit_grade_year" class="block text-sm font-medium text-gray-700 mb-2">
                                        Academic Year <span class="text-red-500">*</span>
                                    </label>
                                    <select id="edit_grade_year" name="grade_year" required
                                            class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm sm:text-base">
                                        <option value="Y1" <?php echo $student['grade_year'] === 'Y1' ? 'selected' : ''; ?>>Y1 (Year 1)</option>
                                        <option value="Y2" <?php echo $student['grade_year'] === 'Y2' ? 'selected' : ''; ?>>Y2 (Year 2)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="edit_is_active" name="is_active"
                                       <?php echo $student['is_active'] ? 'checked' : ''; ?>
                                       class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                                <label for="edit_is_active" class="ml-2 block text-sm font-medium text-gray-700">
                                    Active Status
                                </label>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-md">
                                <h3 class="text-sm font-medium text-gray-700 mb-2">Additional Information</h3>
                                <p class="text-sm text-gray-600">
                                    Created on: <?php echo date('F j, Y', strtotime($student['created_at'])); ?>
                                </p>
                            </div>
                            <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-3 pt-6 border-t border-gray-200">
                                <a href="students.php" class="w-full sm:w-auto text-center px-4 sm:px-6 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 text-sm sm:text-base">
                                    <i class="fas fa-times mr-2"></i> Cancel
                                </a>
                                <button type="submit" name="update_student" value="1" class="w-full sm:w-auto px-4 sm:px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm sm:text-base">
                                    <i class="fas fa-save mr-2"></i> Update Student
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($action === 'delete' && $student): ?>
                <!-- Delete Student Confirmation -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="bg-red-600 text-white px-4 sm:px-6 py-3 sm:py-4">
                        <h2 class="text-lg sm:text-xl font-bold">Delete Student: <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h2>
                    </div>
                    <div class="p-4 sm:p-6 lg:p-8">
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6 rounded-md shadow">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm sm:text-base text-yellow-700">
                                        <strong>Warning:</strong> Deleting this student will also remove all associated records, including attendance, CAS enrollments, and any user account tied to this student. This action cannot be undone.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="mb-6">
                            <h3 class="text-sm font-medium text-gray-700 mb-2">Student Details</h3>
                            <p class="text-sm text-gray-600">
                                <strong>Name:</strong> <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?><br>
                                <strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?><br>
                                <strong>Year:</strong> <?php echo $student['grade_year']; ?><br>
                                <strong>Status:</strong> <?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?>
                            </p>
                        </div>
                        <form action="students.php" method="POST">
                            <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                            <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-3 pt-6 border-t border-gray-200">
                                <a href="students.php" class="w-full sm:w-auto text-center px-4 sm:px-6 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 text-sm sm:text-base">
                                    <i class="fas fa-times mr-2"></i> Cancel
                                </a>
                                <button type="submit" name="delete_student" value="1" class="w-full sm:w-auto px-4 sm:px-6 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 text-sm sm:text-base">
                                    <i class="fas fa-trash mr-2"></i> Delete Student
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-gray-800 text-white py-4 sm:py-6 mt-auto">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col md:flex-row justify-between items-center">
                    <div class="mb-4 md:mb-0">
                        <p class="text-sm sm:text-base">© 2025 UWC Mostar CAS Tracking System</p>
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

    <!-- JavaScript -->
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
            mobileMenuButton.addEventListener('click', function() {
                mobileSidebarOverlay.classList.remove('hidden');
            });

            // Close mobile sidebar
            function closeMobileSidebar() {
                mobileSidebarOverlay.classList.add('hidden');
            }

            closeSidebarButton.addEventListener('click', closeMobileSidebar);
            mobileSidebarBackdrop.addEventListener('click', closeMobileSidebar);

            // User menu toggle
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

            // Close mobile sidebar when window is resized to desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    closeMobileSidebar();
                }
            });
        });

        // Enhanced confirmation dialog for CAS leader creation
        function confirmCasLeaderCreation(studentName, username, password) {
            return confirm(`Create CAS Leader account for ${studentName}?\n\nThis will create:\nUsername: ${username}\nPassword: ${password}\n\nThe student will be able to log in with these credentials.`);
        }

        // Auto-dismiss success messages
        document.addEventListener('DOMContentLoaded', function() {
            const successMessages = document.querySelectorAll('.bg-green-50');
            successMessages.forEach(function(message) {
                if (message.textContent.includes('CAS Leader account created successfully')) {
                    setTimeout(function() {
                        if (message && message.parentElement) {
                            message.style.opacity = '0';
                            message.style.transform = 'translateY(-10px)';
                            setTimeout(function() {
                                message.remove();
                            }, 300);
                        }
                    }, 8000); // Keep CAS leader creation messages longer
                } else {
                    setTimeout(function() {
                        if (message && message.parentElement) {
                            message.style.opacity = '0';
                            message.style.transform = 'translateY(-10px)';
                            setTimeout(function() {
                                message.remove();
                            }, 300);
                        }
                    }, 5000);
                }
            });
        });

        // Enhanced table interactions for CAS leader status
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(function(row) {
                const casLeaderBadge = row.querySelector('.bg-green-100');
                if (casLeaderBadge) {
                    row.style.borderLeft = '3px solid #10b981';
                }
            });
        });

        // Form validation for student forms
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form[method="POST"]');
            forms.forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    // Skip validation for CAS leader creation forms
                    if (form.querySelector('input[name="create_cas_leader"]')) {
                        return true;
                    }

                    const requiredFields = form.querySelectorAll('[required]');
                    let hasEmpty = false;
                    
                    requiredFields.forEach(function(field) {
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

                    // Email validation
                    const emailField = form.querySelector('input[type="email"]');
                    if (emailField && emailField.value) {
                        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!emailRegex.test(emailField.value)) {
                            alert('Please enter a valid email address!');
                            emailField.classList.add('border-red-500');
                            e.preventDefault();
                            return false;
                        }
                    }
                });
            });
        });

        // Loading states for CAS leader creation buttons
        document.addEventListener('DOMContentLoaded', function() {
            const casLeaderForms = document.querySelectorAll('form[method="POST"]');
            casLeaderForms.forEach(function(form) {
                if (form.querySelector('input[name="create_cas_leader"]')) {
                    form.addEventListener('submit', function() {
                        const button = this.querySelector('button[type="submit"]');
                        if (button) {
                            setTimeout(() => {
                                button.disabled = true;
                                const originalText = button.innerHTML;
                                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Creating...';
                                
                                // Re-enable button after 10 seconds as failsafe
                                setTimeout(() => {
                                    button.disabled = false;
                                    button.innerHTML = originalText;
                                }, 10000);
                            }, 100);
                        }
                    });
                }
            });
        });

        // Highlight newly created CAS leader accounts
        document.addEventListener('DOMContentLoaded', function() {
            if (window.location.search.includes('cas_leader_created=1')) {
                // Add a subtle animation to newly created accounts
                setTimeout(function() {
                    const greenBadges = document.querySelectorAll('.bg-green-100');
                    greenBadges.forEach(function(badge) {
                        if (badge.textContent.includes('@')) {
                            badge.style.animation = 'pulse 2s';
                            badge.style.boxShadow = '0 0 10px rgba(16, 185, 129, 0.3)';
                        }
                    });
                }, 500);

                // Clear the URL parameter after highlighting
                setTimeout(function() {
                    const url = new URL(window.location);
                    url.searchParams.delete('cas_leader_created');
                    window.history.replaceState({}, document.title, url.pathname + (url.search || ''));
                }, 3000);
            }
        });

        // Enhanced tooltips for CAS leader status
        document.addEventListener('DOMContentLoaded', function() {
            const casLeaderLinks = document.querySelectorAll('a[href*="users.php"]');
            casLeaderLinks.forEach(function(link) {
                link.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.05)';
                });
                
                link.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });
        });
    </script>

    <!-- Add CSS for animations -->
    <style>
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .transition-colors {
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }
        
        .hover\:bg-green-200:hover {
            background-color: #bbf7d0;
        }
        
        .hover\:bg-purple-200:hover {
            background-color: #e9d5ff;
        }
    </style>
</body>
</html>