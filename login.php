<?php
// Start session for user authentication and error messages
session_start();

// Check if user is already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    // Redirect based on user status
    if ($_SESSION['user_status'] == 'admin') {
        header("Location: admin/dashboard.php");
        exit();
    } else if ($_SESSION['user_status'] == 'cas_leader') {
        header("Location: cas_leader/dashboard.php");
        exit();
    }
}

// Check for "Remember Me" cookie
if (!isset($_SESSION['logged_in']) && isset($_COOKIE['remember_user']) && isset($_COOKIE['remember_token'])) {
    // Connect to database
    $conn = new mysqli('localhost', 'uwc_cas_user', 'your_secure_password', 'cas_attendance_tracking');
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Get user_id and token from cookies
    $user_id = $_COOKIE['remember_user'];
    $token = $_COOKIE['remember_token'];
    
    // Check if token is valid
    $stmt = $conn->prepare("SELECT u.user_id, u.username, u.first_name, u.last_name, u.user_status, u.student_id 
                           FROM users u 
                           JOIN remember_tokens rt ON u.user_id = rt.user_id 
                           WHERE rt.token = ? AND rt.user_id = ? AND rt.expires_at > NOW()");
    $stmt->bind_param("si", $token, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        
        // Set session variables
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_status'] = $user['user_status'];
        $_SESSION['student_id'] = $user['student_id'];
        $_SESSION['logged_in'] = true;
        
        // Redirect based on user status
        if ($user['user_status'] == 'admin') {
            header("Location: admin/dashboard.php");
            exit();
        } else if ($user['user_status'] == 'cas_leader') {
            header("Location: cas_leader/dashboard.php");
            exit();
        }
    }
    
    $stmt->close();
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UWC Mostar CAS - Login</title>
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
    </style>
</head>
<body>
    <div class="login-container flex flex-col justify-center items-center px-4">
        <div class="login-card w-full max-w-md p-8">
            <div class="logo-container">
                <!-- UWC Mostar Logo -->
                <img src="2.png" alt="UWC Mostar Logo" class="logo">
                <h1 class="text-2xl font-bold mt-4 text-gray-800">CAS Tracking System</h1>
            </div>
            
            <form class="mt-8 space-y-6" action="login_process.php" method="POST">
                <?php if(isset($_SESSION['login_error'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $_SESSION['login_error']; ?></span>
                    <?php unset($_SESSION['login_error']); ?>
                </div>
                <?php endif; ?>
                
                <?php if(isset($_SESSION['password_reset_success'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $_SESSION['password_reset_success']; ?></span>
                    <?php unset($_SESSION['password_reset_success']); ?>
                </div>
                <?php endif; ?>
                
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                    <div class="mt-1">
                        <input id="username" name="username" type="text" autocomplete="username" required 
                               class="form-input block w-full px-4 py-3 border border-gray-300 rounded-md shadow-sm focus:outline-none sm:text-sm">
                    </div>
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                    <div class="mt-1 relative">
                        <input id="password" name="password" type="password" autocomplete="current-password" required 
                               class="form-input block w-full px-4 py-3 border border-gray-300 rounded-md shadow-sm focus:outline-none sm:text-sm">
                        <div class="absolute inset-y-0 right-0 flex items-center pr-3">
                            <button type="button" id="togglePassword" class="text-gray-400 hover:text-gray-600 focus:outline-none">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input id="remember-me" name="remember-me" type="checkbox" 
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <label for="remember-me" class="ml-2 block text-sm text-gray-700">
                            Remember me
                        </label>
                    </div>
                    
                    <div class="text-sm">
                        <a href="forgot_password.php" class="footer-link">
                            Forgot your password?
                        </a>
                    </div>
                </div>
                
                <div>
                    <button type="submit" class="btn-login group relative w-full flex justify-center py-3 px-4 rounded-md text-sm">
                        Sign in
                    </button>
                </div>
                
                <div class="text-center text-sm text-gray-500 mt-6">
                    <p>First time logging in? Contact your CAS coordinator</p>
                </div>
            </form>
            
            <div class="mt-8 text-center">
                <a href="index.html" class="footer-link text-sm">
                    <i class="fas fa-arrow-left mr-1"></i> Return to Homepage
                </a>
            </div>
        </div>
        
        <div class="text-center mt-8 text-sm text-gray-500">
            <p>&copy; 2025 UWC Mostar. All rights reserved.</p>
        </div>
    </div>
    
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
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
        
        // Form validation (client-side)
        document.querySelector('form').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault(); // Prevent form submission
                alert('Please enter both username and password');
                return false;
            }
            // If validation passes, the form will submit to login_process.php
        });
    </script>
</body>
</html>