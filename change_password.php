<?php
// Start session for user authentication and error messages
session_start();

// Check if user is logged in and needs to change password
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// If user doesn't need to change password, redirect to appropriate dashboard
if (!isset($_SESSION['needs_password_change']) || $_SESSION['needs_password_change'] !== true) {
    if ($_SESSION['user_status'] == 'admin') {
        header("Location: admin/dashboard.php");
        exit();
    } else if ($_SESSION['user_status'] == 'cas_leader') {
        header("Location: cas_leader/dashboard.php");
        exit();
    }
}

// Database connection
require_once 'includes/db_connect.php';

// Function to sanitize input data
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Process the password change form
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
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
    
    if (empty($errors)) {
        // Hash the new password
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update the password in database. Also clears temp_password_plaintext -
        // once a real password is set, the one-time password should no longer
        // be retrievable anywhere (export page, etc.)
        $stmt = $conn->prepare("UPDATE users SET password_hash = ?, temp_password_plaintext = NULL, is_temporary_password = 0, password_changed_at = NOW() WHERE user_id = ?");
        $stmt->bind_param("si", $password_hash, $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            // Password changed successfully
            unset($_SESSION['needs_password_change']);
            $_SESSION['password_change_success'] = "Password changed successfully! Welcome to the CAS system.";
            
            // Redirect to appropriate dashboard
            if ($_SESSION['user_status'] == 'admin') {
                header("Location: admin/dashboard.php");
                exit();
            } else if ($_SESSION['user_status'] == 'cas_leader') {
                header("Location: cas_leader/dashboard.php");
                exit();
            }
        } else {
            $errors[] = "Failed to update password. Please try again.";
        }
        
        $stmt->close();
    }
    
    // Store errors in session
    if (!empty($errors)) {
        $_SESSION['change_password_errors'] = $errors;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="../tab.ico">
    <title>UWC Mostar CAS - Change Password</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        .login-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        .login-card {
            box-shadow: 0 15px 30px 0 rgba(0,0,0,0.11), 
                        0 5px 15px 0 rgba(0,0,0,0.08);
            background-color: white;
            border-radius: 0.5rem;
            border-left: 0.5rem solid #0077b6;
        }
        .logo-container {
            text-align: center;
            margin-bottom: 2rem;
        }
        .logo {
            max-width: 200px;
            margin: 0 auto;
        }
        .form-input {
            transition: border 0.2s ease-in-out;
            background-color: #f8fafc;
        }
        .form-input:focus {
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0,119,182,0.1);
        }
        .btn-login {
            background-color: #0077b6;
            border: none;
            color: white;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-login:hover {
            background-color: #005f92;
            transform: translateY(-1px);
        }
        .btn-login:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(0,119,182,0.3);
        }
        .footer-link {
            color: #0077b6;
            text-decoration: none;
            transition: color 0.2s;
        }
        .footer-link:hover {
            color: #005f92;
            text-decoration: underline;
        }
        .password-requirements {
            background-color: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="login-container flex flex-col justify-center items-center px-4">
        <div class="login-card w-full max-w-md p-8">
            <div class="logo-container">
                <!-- UWC Mostar Logo -->
                <img src="2.png" alt="UWC Mostar Logo" class="logo">
                <h1 class="text-2xl font-bold mt-4 text-gray-800">Change Your Password</h1>
                <p class="text-sm text-gray-600 mt-2">Welcome <?php echo htmlspecialchars($_SESSION['name']); ?>! Please set your new password.</p>
            </div>
            
            <form class="mt-8 space-y-6" method="POST">
                <?php if(isset($_SESSION['change_password_errors'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <?php foreach($_SESSION['change_password_errors'] as $error): ?>
                        <div class="block"><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                    <?php unset($_SESSION['change_password_errors']); ?>
                </div>
                <?php endif; ?>
                
                <div>
                    <label for="new_password" class="block text-sm font-medium text-gray-700">New Password</label>
                    <div class="mt-1 relative">
                        <input id="new_password" name="new_password" type="password" required 
                               class="form-input block w-full px-4 py-3 border border-gray-300 rounded-md shadow-sm focus:outline-none sm:text-sm">
                        <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                            <button type="button" id="toggleNewPassword" class="text-gray-400 hover:text-gray-600 focus:outline-none">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                    <div class="mt-1 relative">
                        <input id="confirm_password" name="confirm_password" type="password" required 
                               class="form-input block w-full px-4 py-3 border border-gray-300 rounded-md shadow-sm focus:outline-none sm:text-sm">
                        <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                            <button type="button" id="toggleConfirmPassword" class="text-gray-400 hover:text-gray-600 focus:outline-none">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="password-requirements">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">
                        <i class="fas fa-info-circle mr-1"></i>Password Requirements:
                    </h3>
                    <ul class="text-xs text-gray-600 space-y-1">
                        <li><i class="fas fa-check text-green-500 mr-1"></i>At least 8 characters long</li>
                        <li><i class="fas fa-check text-green-500 mr-1"></i>Should include letters and numbers</li>
                        <li><i class="fas fa-check text-green-500 mr-1"></i>Use a unique password you haven't used before</li>
                    </ul>
                </div>
                
                <div>
                    <button type="submit" class="btn-login group relative w-full flex justify-center py-3 px-4 rounded-md text-sm">
                        <i class="fas fa-key mr-2"></i>
                        Change Password
                    </button>
                </div>
            </form>
        </div>
        
        <div class="text-center mt-8 text-sm text-gray-500">
            <p>&copy; 2025 UWC Mostar. All rights reserved.</p>
        </div>
    </div>
    
    <script>
        // Toggle password visibility for new password
        document.getElementById('toggleNewPassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('new_password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Toggle password visibility for confirm password
        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('confirm_password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (!newPassword || !confirmPassword) {
                e.preventDefault();
                alert('Please fill in both password fields');
                return false;
            }
            
            if (newPassword.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long');
                return false;
            }
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
                return false;
            }
        });
        
        // Real-time password matching indicator
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && newPassword !== confirmPassword) {
                this.style.borderColor = '#ef4444';
            } else if (confirmPassword && newPassword === confirmPassword) {
                this.style.borderColor = '#10b981';
            } else {
                this.style.borderColor = '#d1d5db';
            }
        });
    </script>
</body>
</html>