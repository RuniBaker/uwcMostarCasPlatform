<?php
// Start session
session_start();

// Use the shared DB connection file (this already creates $conn)
require_once 'includes/db_connect.php';

// Get token from URL
$token = isset($_GET['token']) ? $_GET['token'] : '';
$token_valid = false;
$user_info = null;

if (!empty($token)) {
    // Validate token
    $stmt = $conn->prepare("SELECT prt.id, prt.user_id, prt.expires_at, prt.used, u.first_name, u.last_name, u.email 
                           FROM password_reset_tokens prt 
                           JOIN users u ON prt.user_id = u.user_id 
                           WHERE prt.token = ? AND prt.used = 0 AND prt.expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $token_valid = true;
        $user_info = $result->fetch_assoc();
    }
    
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UWC Mostar CAS - Reset Password</title>
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
        .password-strength {
            height: 4px;
            background-color: #e5e7eb;
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        .password-strength-bar {
            height: 100%;
            transition: width 0.3s, background-color 0.3s;
        }
    </style>
</head>
<body>
    <div class="login-container flex flex-col justify-center items-center px-4">
        <div class="login-card w-full max-w-md p-8">
            <div class="logo-container">
                <img src="2.png" alt="UWC Mostar Logo" class="logo">
                <h1 class="text-2xl font-bold mt-4 text-gray-800">Reset Your Password</h1>
                <?php if($token_valid): ?>
                <p class="text-sm text-gray-600 mt-2">Hello <?php echo htmlspecialchars($user_info['first_name']); ?>! Please enter your new password.</p>
                <?php endif; ?>
            </div>
            
            <?php if($token_valid): ?>
            <!-- Valid token - show password reset form -->
            <form class="mt-8 space-y-6" action="reset_password_process.php" method="POST">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                
                <?php if(isset($_SESSION['reset_error'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $_SESSION['reset_error']; ?></span>
                    <?php unset($_SESSION['reset_error']); ?>
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
                    <div class="password-strength">
                        <div id="strengthBar" class="password-strength-bar" style="width: 0%; background-color: #e5e7eb;"></div>
                    </div>
                    <p id="strengthText" class="text-xs text-gray-500 mt-1"></p>
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
                        <li id="req-length"><i class="fas fa-circle text-gray-400 mr-2"></i>At least 8 characters long</li>
                        <li id="req-upper"><i class="fas fa-circle text-gray-400 mr-2"></i>Contains uppercase letter</li>
                        <li id="req-lower"><i class="fas fa-circle text-gray-400 mr-2"></i>Contains lowercase letter</li>
                        <li id="req-number"><i class="fas fa-circle text-gray-400 mr-2"></i>Contains a number</li>
                    </ul>
                </div>
                
                <div>
                    <button type="submit" class="btn-login group relative w-full flex justify-center py-3 px-4 rounded-md text-sm">
                        <i class="fas fa-lock mr-2"></i>
                        Reset Password
                    </button>
                </div>
            </form>
            
            <?php else: ?>
            <!-- Invalid or expired token -->
            <div class="mt-8">
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-red-500 text-xl mt-1"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-semibold mb-2">Invalid or Expired Reset Link</h3>
                            <p class="text-sm">
                                This password reset link is either invalid, has expired, or has already been used.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="space-y-3">
                    <a href="forgot_password.php" class="btn-login group relative w-full flex justify-center py-3 px-4 rounded-md text-sm">
                        <i class="fas fa-redo mr-2"></i>
                        Request New Reset Link
                    </a>
                    
                    <a href="login.php" class="footer-link text-sm block text-center">
                        <i class="fas fa-arrow-left mr-1"></i> Back to Login
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
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
        // Toggle password visibility
        document.getElementById('toggleNewPassword')?.addEventListener('click', function() {
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
        
        document.getElementById('toggleConfirmPassword')?.addEventListener('click', function() {
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
        
        // Password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            const requirements = {
                length: password.length >= 8,
                upper: /[A-Z]/.test(password),
                lower: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[^A-Za-z0-9]/.test(password)
            };
            
            // Update requirement indicators
            document.getElementById('req-length').innerHTML = requirements.length 
                ? '<i class="fas fa-check-circle text-green-500 mr-2"></i>At least 8 characters long'
                : '<i class="fas fa-circle text-gray-400 mr-2"></i>At least 8 characters long';
            
            document.getElementById('req-upper').innerHTML = requirements.upper 
                ? '<i class="fas fa-check-circle text-green-500 mr-2"></i>Contains uppercase letter'
                : '<i class="fas fa-circle text-gray-400 mr-2"></i>Contains uppercase letter';
            
            document.getElementById('req-lower').innerHTML = requirements.lower 
                ? '<i class="fas fa-check-circle text-green-500 mr-2"></i>Contains lowercase letter'
                : '<i class="fas fa-circle text-gray-400 mr-2"></i>Contains lowercase letter';
            
            document.getElementById('req-number').innerHTML = requirements.number 
                ? '<i class="fas fa-check-circle text-green-500 mr-2"></i>Contains a number'
                : '<i class="fas fa-circle text-gray-400 mr-2"></i>Contains a number';
            
            // Calculate strength
            if (requirements.length) strength += 25;
            if (requirements.upper) strength += 25;
            if (requirements.lower) strength += 25;
            if (requirements.number) strength += 25;
            if (requirements.special) strength += 10;
            
            return Math.min(strength, 100);
        }
        
        // Update strength bar
        document.getElementById('new_password')?.addEventListener('input', function() {
            const password = this.value;
            const strength = checkPasswordStrength(password);
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            
            strengthBar.style.width = strength + '%';
            
            if (strength === 0) {
                strengthBar.style.backgroundColor = '#e5e7eb';
                strengthText.textContent = '';
            } else if (strength < 50) {
                strengthBar.style.backgroundColor = '#ef4444';
                strengthText.textContent = 'Weak password';
                strengthText.style.color = '#ef4444';
            } else if (strength < 75) {
                strengthBar.style.backgroundColor = '#f59e0b';
                strengthText.textContent = 'Medium strength';
                strengthText.style.color = '#f59e0b';
            } else {
                strengthBar.style.backgroundColor = '#10b981';
                strengthText.textContent = 'Strong password';
                strengthText.style.color = '#10b981';
            }
        });
        
        // Real-time password matching
        document.getElementById('confirm_password')?.addEventListener('input', function() {
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
        
        // Form validation
        document.querySelector('form')?.addEventListener('submit', function(e) {
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
    </script>
</body>
</html>