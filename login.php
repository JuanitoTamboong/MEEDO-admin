<?php
require_once 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if (isAdmin()) {
        header("Location: admin/dashboard.php");
    } else {
        header("Location: user/dashboard.php");
    }
    exit;
}

$db = getDB();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login_input = trim($_POST['login_input']);
    $password = $_POST['password'];
    
    if (empty($login_input) || empty($password)) {
        $error = "Please enter both username/email and password";
    } else {
        // Check if input is email or username
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$login_input, $login_input]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            
            // Check if user is admin - redirect to admin login
            if ($user['role'] == 'admin') {
                $error = "Administrators please use the Admin Login page.";
            } else {
                // Check account status
                if (isset($user['status'])) {
                    if ($user['status'] == 'pending') {
                        $error = "Your account is pending approval. Please wait for admin confirmation.";
                    } elseif ($user['status'] == 'inactive') {
                        $error = "Your account is inactive. Please contact the MEEDO office.";
                    } else {
                        // Proceed with tenant login
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['name'] = $user['name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['stall_number'] = $user['stall_number'] ?? '';
                        
                        checkDueDates();
                        
                        header("Location: user/dashboard.php");
                        exit;
                    }
                } else {
                    // If status column doesn't exist, proceed with login
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['stall_number'] = $user['stall_number'] ?? '';
                    
                    checkDueDates();
                    
                    header("Location: user/dashboard.php");
                    exit;
                }
            }
        } else {
            $error = "Invalid username/email or password";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Login | MEEDO Market System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated Background */
        .bg-bubbles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            overflow: hidden;
        }

        .bg-bubbles li {
            position: absolute;
            list-style: none;
            display: block;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.15);
            bottom: -160px;
            animation: square 25s infinite;
            transition-timing-function: linear;
            border-radius: 50%;
        }

        .bg-bubbles li:nth-child(1) { left: 10%; width: 80px; height: 80px; animation-delay: 0s; animation-duration: 12s; }
        .bg-bubbles li:nth-child(2) { left: 20%; width: 40px; height: 40px; animation-delay: 2s; animation-duration: 10s; }
        .bg-bubbles li:nth-child(3) { left: 25%; width: 120px; height: 120px; animation-delay: 4s; }
        .bg-bubbles li:nth-child(4) { left: 40%; width: 60px; height: 60px; animation-delay: 0s; animation-duration: 18s; }
        .bg-bubbles li:nth-child(5) { left: 70%; width: 50px; height: 50px; animation-delay: 0s; }
        .bg-bubbles li:nth-child(6) { left: 80%; width: 100px; height: 100px; animation-delay: 3s; }
        .bg-bubbles li:nth-child(7) { left: 32%; width: 30px; height: 30px; animation-delay: 7s; }
        .bg-bubbles li:nth-child(8) { left: 55%; width: 70px; height: 70px; animation-delay: 15s; animation-duration: 30s; }
        .bg-bubbles li:nth-child(9) { left: 15%; width: 45px; height: 45px; animation-delay: 2s; animation-duration: 20s; }
        .bg-bubbles li:nth-child(10) { left: 90%; width: 90px; height: 90px; animation-delay: 0s; animation-duration: 11s; }

        @keyframes square {
            0%   { transform: translateY(0); opacity: 0.5; }
            100% { transform: translateY(-1200px) rotate(600deg); opacity: 0; }
        }

        .login-wrapper {
            width: 100%;
            max-width: 1300px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 40px;
            position: relative;
            z-index: 10;
            flex-wrap: wrap;
        }

        /* Left Side - Branding */
        .brand-section {
            flex: 1;
            min-width: 300px;
            max-width: 500px;
            color: white;
            padding: 40px;
            animation: fadeInLeft 0.8s ease;
        }

        @keyframes fadeInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .brand-icon {
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }

        .brand-icon i {
            font-size: 50px;
            color: white;
        }

        .brand-section h1 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 15px;
            line-height: 1.2;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .brand-section h1 span {
            background: linear-gradient(135deg, #fff, #e0e7ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .brand-description {
            font-size: 16px;
            line-height: 1.7;
            margin-bottom: 40px;
            opacity: 0.9;
            max-width: 400px;
        }

        .feature-list {
            list-style: none;
        }

        .feature-list li {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            font-size: 16px;
        }

        .feature-list li i {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(5px);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Right Side - Login Form */
        .login-card {
            flex: 1;
            min-width: 400px;
            max-width: 480px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 40px;
            padding: 50px 45px;
            box-shadow: 0 50px 80px -20px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: fadeInRight 0.8s ease;
            position: relative;
            overflow: hidden;
        }

        @keyframes fadeInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200px;
            height: 200px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            opacity: 0.1;
            z-index: 0;
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
            z-index: 1;
        }

        .login-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #667eea15, #764ba215);
            padding: 12px 25px;
            border-radius: 50px;
            margin-bottom: 25px;
            border: 1px solid #667eea30;
        }

        .login-badge i {
            color: #667eea;
            font-size: 20px;
        }

        .login-badge span {
            color: #4a5568;
            font-weight: 600;
            font-size: 14px;
            letter-spacing: 0.5px;
        }

        .login-header h2 {
            font-size: 32px;
            font-weight: 800;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .login-header p {
            color: #718096;
            font-size: 15px;
            font-weight: 500;
        }

        /* Alert Messages */
        .alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-radius: 20px;
            margin-bottom: 25px;
            font-size: 14px;
            font-weight: 500;
            animation: slideDown 0.3s ease;
            position: relative;
            z-index: 1;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert.error {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .alert.success {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .alert i {
            font-size: 20px;
        }

        /* Form Styles */
        .login-form {
            position: relative;
            z-index: 1;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #4a5568;
            font-weight: 600;
            font-size: 13px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 20px;
            color: #a0aec0;
            font-size: 18px;
            transition: all 0.3s;
            z-index: 2;
        }

        .input-wrapper input {
            width: 100%;
            padding: 18px 20px 18px 55px;
            border: 2px solid #e2e8f0;
            border-radius: 20px;
            font-size: 15px;
            transition: all 0.3s;
            background: white;
            font-family: 'Inter', sans-serif;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px #667eea20;
        }

        .input-wrapper input:focus + i {
            color: #667eea;
        }

        .input-wrapper input::placeholder {
            color: #cbd5e0;
            font-weight: 400;
        }

        /* Login Button */
        .login-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 20px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin: 30px 0 20px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 20px -5px #667eea80;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 30px -5px #667eea;
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn i {
            font-size: 18px;
            transition: transform 0.3s;
        }

        .login-btn:hover i {
            transform: translateX(5px);
        }

        /* Form Footer */
        .form-footer {
            text-align: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 2px solid #f1f5f9;
        }

        .register-link {
            color: #4a5568;
            font-size: 14px;
            font-weight: 500;
        }

        .register-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #f7fafc;
            border-radius: 50px;
            margin-top: 12px;
            transition: all 0.3s;
            border: 1px solid #e2e8f0;
        }

        .register-link a:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .register-link a:hover i {
            color: white;
        }

        .register-link a i {
            color: #667eea;
            transition: color 0.3s;
        }

        /* Admin Link */
        .admin-link {
            text-align: center;
            margin-top: 20px;
        }

        .admin-link a {
            color: #a0aec0;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #f8fafc;
            border-radius: 30px;
            transition: all 0.3s;
            border: 1px solid #e2e8f0;
        }

        .admin-link a:hover {
            background: #dc2626;
            color: white;
            border-color: #dc2626;
        }

        .admin-link a:hover i {
            color: white;
        }

        .admin-link i {
            color: #dc2626;
            transition: color 0.3s;
        }

        /* Loading State */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 900px) {
            .login-wrapper {
                flex-direction: column;
            }
            
            .brand-section {
                text-align: center;
                max-width: 100%;
            }
            
            .brand-section h1 {
                font-size: 36px;
            }
            
            .brand-description {
                margin-left: auto;
                margin-right: auto;
            }
            
            .feature-list {
                max-width: 400px;
                margin: 0 auto;
            }
            
            .login-card {
                min-width: 100%;
            }
        }

        @media (max-width: 500px) {
            .login-card {
                padding: 30px 20px;
            }
            
            .brand-section {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Animated Background -->
    <ul class="bg-bubbles">
        <li></li>
        <li></li>
        <li></li>
        <li></li>
        <li></li>
        <li></li>
        <li></li>
        <li></li>
        <li></li>
        <li></li>
    </ul>

    <div class="login-wrapper">
        <!-- Left Side - Branding -->
        <div class="brand-section">
            <div class="brand-icon">
                <i class="fas fa-store"></i>
            </div>
            <h1>Welcome to <span>MEEDO</span></h1>
            <p class="brand-description">
                Odiongan Public Market's official management system. Access your tenant dashboard, manage payments, and track your stall.
            </p>
            <ul class="feature-list">
                <li>
                    <i class="fas fa-check-circle"></i>
                    <span>View your stall details and rental information</span>
                </li>
                <li>
                    <i class="fas fa-credit-card"></i>
                    <span>Track monthly payments and dues</span>
                </li>
                <li>
                    <i class="fas fa-tools"></i>
                    <span>Submit repair and maintenance requests</span>
                </li>
                <li>
                    <i class="fas fa-bell"></i>
                    <span>Receive important notifications and reminders</span>
                </li>
            </ul>
        </div>

        <!-- Right Side - Login Form -->
        <div class="login-card">
            <div class="login-header">
                <div class="login-badge">
                    <i class="fas fa-store-alt"></i>
                    <span>TENANT PORTAL</span>
                </div>
                <h2>Welcome Back!</h2>
                <p>Sign in to your tenant account</p>
            </div>

            <!-- Error/Success Messages -->
            <?php if ($error): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" class="login-form" id="loginForm">
                <div class="form-group">
                    <label>Username or Email</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" name="login_input" required 
                               placeholder="Enter your username or email"
                               value="<?php echo isset($_POST['login_input']) ? htmlspecialchars($_POST['login_input']) : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" required 
                               placeholder="Enter your password">
                    </div>
                </div>

                <button type="submit" class="login-btn" id="submitBtn">
                    <span>Sign In to Dashboard</span>
                    <i class="fas fa-arrow-right"></i>
                </button>

                <div class="form-footer">
                    <div class="register-link">
                        <p>New tenant? Want to rent a stall?</p>
                        <a href="tenant-register.php">
                            <i class="fas fa-user-plus"></i> Register as New Tenant
                        </a>
                    </div>
                    
                    <div class="admin-link">
                        <a href="admin-login.php">
                            <i class="fas fa-shield-alt"></i> Administrator Login
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="loading"></span> Signing in...';
            return true;
        });
    </script>
</body>
</html>