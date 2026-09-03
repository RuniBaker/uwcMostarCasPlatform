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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UWC Mostar CAS - Forgot Password</title>
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
        .info-box {
            background-color: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 0.5rem;
            padding: 1rem;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <div class="login-container flex flex-col justify-center items-center px-4">
        <div class="login-card w-full max-w-md p-8">
            <div class="logo-container">
                <!-- UWC Mostar Logo -->
                <img src="2.png" alt="UWC Mostar Logo" class="logo">
                <h1 class="text-2xl font-bold mt-4 text-gray-800">Forgot Your Password?</h1>
                <p class="text-sm text-gray-600 mt-2">Enter your email address and we'll send you a link to reset your password.</p>
            </div>
            
            <form class="mt-8 space-y-6" action="forgot_password_process.php" method="POST">
                <?php if(isset($_SESSION['forgot_error'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $_SESSION['forgot_error']; ?></span>
                    <?php unset($_SESSION['forgot_error']); ?>
                </div>
                <?php endif; ?>
                
                <?php if(isset($_SESSION['forgot_success'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $_SESSION['forgot_success']; ?></span>
                    <?php unset($_SESSION['forgot_success']); ?>
                </div>
                <?php endif; ?>
                
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                    <div class="mt-1">
                        <input id="email" name="email" type="email" autocomplete="email" required 
                               class="form-input block w-full px-4 py-3 border border-gray-300 rounded-md shadow-sm focus:outline-none sm:text-sm"
                               placeholder="your.email@uwcmostar.ba">
                    </div>
                </div>
                
                <div class="info-box">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-info-circle text-blue-500 text-lg mt-1"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-gray-600">
                                A password reset link will be sent to your registered email address. 
                                The link will be valid for 1 hour.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div>
                    <button type="submit" class="btn-login group relative w-full flex justify-center py-3 px-4 rounded-md text-sm">
                        <i class="fas fa-paper-plane mr-2"></i>
                        Send Reset Link
                    </button>
                </div>
                
                <div class="text-center">
                    <a href="login.php" class="footer-link text-sm">
                        <i class="fas fa-arrow-left mr-1"></i> Back to Login
                    </a>
                </div>
            </form>
            
            <div class="mt-8 text-center">
                <a href="index.html" class="footer-link text-sm">
                    <i class="fas fa-home mr-1"></i> Return to Homepage
                </a>
            </div>
        </div>
        
        <div class="text-center mt-8 text-sm text-gray-500">
            <p>&copy; 2025 UWC Mostar. All rights reserved.</p>
        </div>
    </div>
    
    <script>
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            
            if (!email) {
                e.preventDefault();
                alert('Please enter your email address');
                return false;
            }
            
            // Basic email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }
        });
    </script>
</body>
</html>
