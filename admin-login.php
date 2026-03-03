<?php
require_once 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isAdmin()) {
    header("Location: admin/dashboard.php");
    exit;
}

$db = getDB();
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login_input = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($login_input) || empty($password)) {
        $error = "Please enter both username and password";
    } else {
        // Check if input is email or username
        $stmt = $db->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND role = 'admin'");
        $stmt->execute([$login_input, $login_input]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            // Check account status
            if (isset($user['status']) && $user['status'] != 'active') {
                $error = "Your account is " . $user['status'] . ". Please contact support.";
            } else {
                // Proceed with admin login
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                
                header("Location: admin/dashboard.php");
                exit;
            }
        } else {
            $error = "Invalid admin credentials";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | MEEDO Market System</title>
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
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated Background */
        .bg-grid {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.05) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: 1;
            animation: gridMove 20s linear infinite;
        }

        @keyframes gridMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }

        .bg-glow {
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(239, 68, 68, 0.2) 0%, transparent 70%);
            border-radius: 50%;
            top: -250px;
            right: -250px;
            z-index: 2;
            animation: pulse 5s ease-in-out infinite;
        }

        .bg-glow-2 {
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            bottom: -200px;
            left: -200px;
            z-index: 2;
            animation: pulse 7s ease-in-out infinite reverse;
        }

        @keyframes pulse {
            0%, 100% { opacity: 0.5; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.1); }
        }

        .login-wrapper {
            width: 100%;
            max-width: 1400px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 60px;
            position: relative;
            z-index: 10;
            flex-wrap: wrap;
        }

        /* Left Side - Stats & Info */
        .stats-section {
            flex: 1;
            min-width: 350px;
            max-width: 550px;
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

        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 12px 24px;
            border-radius: 50px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .admin-badge i {
            color: #ef4444;
            font-size: 24px;
        }

        .admin-badge span {
            font-weight: 600;
            letter-spacing: 1px;
        }

        .stats-section h1 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 20px;
            line-height: 1.2;
            text-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .stats-section h1 span {
            background: linear-gradient(135deg, #ef4444, #f97316);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
            margin: 40px 0;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 25px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.12);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: rgba(239, 68, 68, 0.2);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }

        .stat-icon i {
            font-size: 24px;
            color: #ef4444;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            font-weight: 500;
        }

        .security-note {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 16px;
            padding: 16px 20px;
            margin-top: 30px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .security-note i {
            font-size: 30px;
            color: #10b981;
        }

        .security-note p {
            font-size: 14px;
            line-height: 1.6;
            opacity: 0.9;
        }

        /* Right Side - Login Form */
        .login-card {
            flex: 1;
            min-width: 400px;
            max-width: 480px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 40px;
            padding: 50px 45px;
            box-shadow: 0 50px 80px -20px rgba(0, 0, 0, 0.5);
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
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #ef4444, #f97316, #eab308);
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }

        .lock-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            box-shadow: 0 20px 30px -10px #ef4444;
        }

        .lock-icon i {
            font-size: 40px;
            color: white;
        }

        .login-header h2 {
            font-size: 32px;
            font-weight: 800;
            color: #1e1b4b;
            margin-bottom: 8px;
        }

        .login-header p {
            color: #64748b;
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

        .alert i {
            font-size: 20px;
        }

        /* Form Styles */
        .login-form {
            position: relative;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #334155;
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
            color: #94a3b8;
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
            background: #f8fafc;
            font-family: 'Inter', sans-serif;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: #ef4444;
            background: white;
            box-shadow: 0 0 0 4px #ef444420;
        }

        .input-wrapper input:focus + i {
            color: #ef4444;
        }

        /* Login Button */
        .login-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
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
            box-shadow: 0 10px 20px -5px #ef4444;
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
            box-shadow: 0 20px 30px -5px #ef4444;
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn i {
            font-size: 18px;
        }

        /* Admin Credentials Box */
        .credentials-box {
            background: #f1f5f9;
            border-radius: 20px;
            padding: 20px;
            margin: 25px 0;
            border: 1px dashed #94a3b8;
        }

        .credentials-box h4 {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #334155;
            font-size: 14px;
            margin-bottom: 15px;
        }

        .credentials-box h4 i {
            color: #ef4444;
        }

        .cred-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #cbd5e1;
            font-size: 14px;
        }

        .cred-item:last-child {
            border-bottom: none;
        }

        .cred-label {
            color: #64748b;
            font-weight: 500;
        }

        .cred-value {
            background: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-weight: 700;
            color: #0f172a;
            font-family: monospace;
            border: 1px solid #cbd5e1;
        }

        /* Form Footer */
        .form-footer {
            text-align: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 2px solid #f1f5f9;
        }

        .tenant-link {
            margin-bottom: 15px;
        }

        .tenant-link a {
            color: #ef4444;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #fef2f2;
            border-radius: 30px;
            transition: all 0.3s;
            border: 1px solid #fecaca;
        }

        .tenant-link a:hover {
            background: #ef4444;
            color: white;
        }

        .back-link a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: color 0.3s;
        }

        .back-link a:hover {
            color: #ef4444;
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
            
            .stats-section {
                text-align: center;
                max-width: 100%;
            }
            
            .stats-grid {
                max-width: 400px;
                margin: 30px auto;
            }
            
            .security-note {
                text-align: left;
            }
            
            .login-card {
                min-width: 100%;
            }
        }

        @media (max-width: 500px) {
            .login-card {
                padding: 30px 20px;
            }
            
            .stats-section {
                padding: 20px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-card {
                text-align: left;
            }
        }
    </style>
</head>
<body>
    <!-- Animated Background -->
    <div class="bg-grid"></div>
    <div class="bg-glow"></div>
    <div class="bg-glow-2"></div>

    <div class="login-wrapper">
        <!-- Left Side - Stats & Info -->
        <div class="stats-section">
            <div class="admin-badge">
                <i class="fas fa-shield-alt"></i>
                <span>ADMINISTRATOR ACCESS</span>
            </div>
            
            <h1>Manage <span>MEEDO</span> Market System</h1>
            
            <div class="stats-grid">
                <?php
                // Get real statistics
                $stats = getStatistics();
                $tenantsCount = $stats['total_tenants'] ?? 0;
                $paidCount = $stats['paid_tenants'] ?? 0;
                $pendingPayments = $stats['unpaid_tenants'] ?? 0;
                $collected = $stats['total_collected'] ?? 0;
                ?>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <div class="stat-value"><?php echo $tenantsCount; ?></div>
                    <div class="stat-label">Total Tenants</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $paidCount; ?></div>
                    <div class="stat-label">Paid This Month</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $pendingPayments; ?></div>
                    <div class="stat-label">Pending Payments</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="stat-value">₱<?php echo number_format($collected, 0); ?></div>
                    <div class="stat-label">Collected (YTD)</div>
                </div>
            </div>
            
            <div class="security-note">
                <i class="fas fa-shield"></i>
                <p>This portal is strictly for authorized MEEDO personnel. All access is logged and monitored for security purposes.</p>
            </div>
        </div>

        <!-- Right Side - Login Form -->
        <div class="login-card">
            <div class="login-header">
                <div class="lock-icon">
                    <i class="fas fa-lock"></i>
                </div>
                <h2>Admin Login</h2>
                <p>Enter your credentials to access the admin panel</p>
            </div>

            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" class="login-form" id="loginForm">
                <div class="form-group">
                    <label>Username or Email</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user-shield"></i>
                        <input type="text" name="username" required 
                               placeholder="Enter admin username or email"
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
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

                <!-- Admin Credentials (for demo purposes) -->
                <div class="credentials-box">
                    <h4><i class="fas fa-key"></i> Demo Admin Credentials</h4>
                    <div class="cred-item">
                        <span class="cred-label">Username</span>
                        <span class="cred-value">admin</span>
                    </div>
                    <div class="cred-item">
                        <span class="cred-label">Password</span>
                        <span class="cred-value">admin123</span>
                    </div>
                </div>

                <button type="submit" class="login-btn" id="submitBtn">
                    <span>Access Admin Dashboard</span>
                    <i class="fas fa-arrow-right"></i>
                </button>

                <div class="form-footer">
                    <div class="tenant-link">
                        <a href="login.php">
                            <i class="fas fa-store"></i> Switch to Tenant Login
                        </a>
                    </div>
                    
                    <div class="back-link">
                        <a href="index.php">
                            <i class="fas fa-arrow-left"></i> Back to Home
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
            submitBtn.innerHTML = '<span class="loading"></span> Authenticating...';
            return true;
        });
    </script>
</body>
</html>