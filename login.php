<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $db = getDB();
    $login_input = trim($_POST['username']);
    
    // Check if input is email or username - try both
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$login_input, $login_input]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($_POST['password'], $user['password'])) {
        
        // Check if status column exists and verify account status
        if (isset($user['status'])) {
            if ($user['status'] == 'pending') {
                $error = "Your account is pending approval. Please wait for admin confirmation or proceed to the MEEDO office.";
            } elseif ($user['status'] == 'inactive') {
                $error = "Your account is inactive. Please contact the MEEDO office for assistance.";
            } else {
                // Proceed with login
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['stall_number'] = $user['stall_number'] ?? '';
                
                checkDueDates();
                
                if ($user['role'] == 'admin') {
                    header("Location: admin/dashboard.php");
                } else {
                    header("Location: user/dashboard.php");
                }
                exit;
            }
        } else {
            // If status column doesn't exist, proceed with login (backward compatibility)
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['stall_number'] = $user['stall_number'] ?? '';
            
            checkDueDates();
            
            if ($user['role'] == 'admin') {
                header("Location: admin/dashboard.php");
            } else {
                header("Location: user/dashboard.php");
            }
            exit;
        }
    } else {
        $error = "Invalid username/email or password";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Odiongan Public Market Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; 
        }
        
        body { 
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); 
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0; 
                transform: translateY(20px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        .header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            padding: 40px 30px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .header::after {
            content: "🏪";
            position: absolute;
            right: 20px;
            bottom: 10px;
            font-size: 60px;
            opacity: 0.1;
        }
        
        .header i { 
            font-size: 56px; 
            margin-bottom: 15px; 
            color: #f59e0b;
        }
        
        .header h1 { 
            font-size: 26px; 
            margin-bottom: 5px; 
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .header p { 
            opacity: 0.8; 
            font-size: 14px; 
            font-weight: 400;
        }
        
        .form-container { 
            padding: 40px; 
        }
        
        .form-group { 
            margin-bottom: 20px; 
        }
        
        .form-group label { 
            display: block; 
            margin-bottom: 8px; 
            color: #4b5563;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .input-group i {
            position: absolute;
            left: 16px;
            color: #9ca3af;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .input-group input { 
            width: 100%; 
            padding: 14px 15px 14px 48px; 
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f9fafb;
        }
        
        .input-group input:focus {
            outline: none;
            border-color: #3b82f6;
            background: white;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        
        .input-group input:focus + i {
            color: #3b82f6;
        }
        
        button { 
            width: 100%; 
            padding: 14px; 
            background: #3b82f6; 
            color: white; 
            border: none; 
            border-radius: 12px; 
            cursor: pointer; 
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        button:hover { 
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.5);
        }
        
        .error { 
            background: #fee2e2; 
            color: #dc2626; 
            padding: 14px; 
            border-radius: 12px; 
            margin-bottom: 20px;
            text-align: center;
            font-size: 14px;
            border: 1px solid #fecaca;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .error i {
            font-size: 18px;
        }
        
        .info-box {
            margin-top: 25px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
        }
        
        .info-box h3 {
            color: #1e293b;
            font-size: 14px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }
        
        .info-box h3 i {
            color: #3b82f6;
        }
        
        .credential-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }
        
        .credential-item:last-child { 
            border-bottom: none; 
        }
        
        .role-badge {
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        
        .role-badge.admin { 
            background: #dc2626; 
            color: white; 
        }
        
        .admin-note {
            margin-top: 15px;
            padding: 10px;
            background: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 8px;
            color: #856404;
            font-size: 13px;
            text-align: center;
        }
        
        .register-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
        .register-link p {
            color: #6b7280;
            font-size: 14px;
        }
        
        .register-link a {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #eff6ff;
            border-radius: 30px;
            margin-top: 8px;
            transition: all 0.3s;
        }
        
        .register-link a:hover {
            background: #3b82f6;
            color: white;
        }
        
        .register-link a:hover i {
            color: white;
        }
        
        .register-link a i {
            color: #3b82f6;
            transition: all 0.3s;
        }
        
        .market-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            margin-top: 10px;
            color: #f59e0b;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="header">
            <i class="fas fa-store"></i>
            <h1>Odiongan Public Market</h1>
            <p>MEEDO Management System</p>
            <div class="market-badge">
                <i class="fas fa-map-pin"></i> Municipal Economic Enterprise Development Office
            </div>
        </div>
        
        <div class="form-container">
            <?php if (isset($error)): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>Username or Email</label>
                    <div class="input-group">
                        <i class="fas fa-user"></i>
                        <input type="text" name="username" required placeholder="Enter username or email">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" required placeholder="Enter your password">
                    </div>
                </div>
                
                <button type="submit">
                    <i class="fas fa-sign-in-alt"></i> Login to Dashboard
                </button>
            </form>
            
            <div class="info-box">
                <h3><i class="fas fa-info-circle"></i> Administrator Access Only</h3>
                <div class="credential-item">
                    <span><i class="fas fa-user-shield"></i> <strong>Administrator</strong></span>
                    <span class="role-badge admin">ADMIN</span>
                </div>
                <div class="credential-item">
                    <span>Username: <code>admin</code></span>
                    <span>Password: <code>admin123</code></span>
                </div>
                <div class="admin-note">
                    <i class="fas fa-exclamation-triangle"></i> Tenants must register first and wait for admin approval.
                </div>
            </div>
            
            <!-- Registration Link for New Tenants -->
            <div class="register-link">
                <p>New tenant? Want to rent a stall?</p>
                <a href="tenant-register.php">
                    <i class="fas fa-user-plus"></i> Register as New Tenant
                </a>
            </div>
        </div>
    </div>
</body>
</html>