<?php
require_once 'config.php';  // Changed from '../config.php' to 'config.php'

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if (isAdmin()) {
        header("Location: admin/dashboard.php");
    } else {
        header("Location: tenant/dashboard.php");
    }
    exit;
}

$db = getDB();
$error = '';
$success = '';

// Get available sections for dropdown
$sections = ['Meat Section', 'Fish Section', 'Vegetable Section', 'Dry Goods', 'Rice Section', 'Other'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $contact = trim($_POST['contact']);
    $stall_number = trim($_POST['stall_number']);
    $section = $_POST['section'];
    $monthly_rent = floatval($_POST['monthly_rent']);
    
    // Validation
    if (empty($name) || empty($email) || empty($password) || empty($contact) || empty($stall_number) || empty($section) || empty($monthly_rent)) {
        $error = "All fields are required";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters";
    } else {
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Email already registered";
        } else {
            // Check if stall number is already taken
            $stmt = $db->prepare("SELECT id FROM tenants WHERE stall_number = ?");
            $stmt->execute([$stall_number]);
            if ($stmt->fetch()) {
                $error = "Stall number already occupied";
            } else {
                // Begin transaction
                $db->beginTransaction();
                try {
                    // Create username from email or name
                    $username = strtolower(explode('@', $email)[0]);
                    
                    // Create user account (pending approval)
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO users (username, name, email, password, role, status) VALUES (?, ?, ?, ?, 'tenant', 'pending')");
                    $stmt->execute([$username, $name, $email, $hashed_password]);
                    $user_id = $db->lastInsertId();
                    
                    // Create tenant record (pending approval)
                    $stmt = $db->prepare("
                        INSERT INTO tenants (user_id, name, stall_number, section, monthly_rent, contact, email, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([$user_id, $name, $stall_number, $section, $monthly_rent, $contact, $email]);
                    
                    // Create notification for admin
                    $adminQuery = $db->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
                    $admin = $adminQuery->fetch();
                    if ($admin) {
                        createNotification(
                            $admin['id'],
                            'New Tenant Registration',
                            "New tenant registration pending: $name (Stall $stall_number)",
                            'info'
                        );
                    }
                    
                    $db->commit();
                    $success = "Registration successful! Please proceed to the MEEDO office for payment and account approval.";
                    
                    // Clear form
                    $_POST = array();
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = "Registration failed. Please try again.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Registration | MEEDO</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #0f172a;
            --accent: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .register-container {
            max-width: 600px;
            width: 100%;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: white;
            padding: 15px 30px;
            border-radius: 50px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary), #1e293b);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        .logo-text {
            font-weight: 800;
            font-size: 24px;
            color: var(--primary);
        }
        .logo-text span { color: var(--accent); }
        
        .register-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        .register-card h2 {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 10px;
        }
        .subtitle {
            color: var(--gray-500);
            font-size: 14px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-200);
        }
        .info-box {
            background: #eff6ff;
            border: 1px solid #dbeafe;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 25px;
            display: flex;
            gap: 12px;
            color: var(--accent);
        }
        .info-box i { font-size: 24px; }
        .info-box p {
            color: var(--gray-700);
            font-size: 14px;
            line-height: 1.5;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group.full-width { grid-column: 1 / -1; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 6px;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        .btn-register {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary), #1e293b);
            color: white;
            border: none;
            border-radius: 30px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 20px 0;
            transition: all 0.2s;
        }
        .btn-register:hover {
            background: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(59,130,246,0.3);
        }
        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        .alert.error {
            background: #fee2e2;
            color: var(--danger);
            border: 1px solid #fecaca;
        }
        .alert.success {
            background: #dcfce7;
            color: var(--success);
            border: 1px solid #bbf7d0;
        }
        .login-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-200);
        }
        .login-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }
        .login-link a:hover { text-decoration: underline; }
        .footer-note {
            text-align: center;
            margin-top: 20px;
            color: white;
            font-size: 13px;
        }
        .footer-note a { color: white; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="header">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-store"></i></div>
                <div class="logo-text">MEEDO<span>.pro</span></div>
            </div>
        </div>

        <div class="register-card">
            <h2>Tenant Registration</h2>
            <div class="subtitle">Odiongan Public Market · Municipal Enterprise Development Office</div>

            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <p>After registration, please proceed to the MEEDO office for payment and account approval. Your account will be activated within 24 hours after payment confirmation.</p>
            </div>

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

            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Full Name</label>
                        <input type="text" name="name" required value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" placeholder="Enter your full name">
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" placeholder="your@email.com">
                    </div>

                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="contact" required value="<?php echo isset($_POST['contact']) ? htmlspecialchars($_POST['contact']) : ''; ?>" placeholder="09XXXXXXXXX">
                    </div>

                    <div class="form-group">
                        <label>Stall Number</label>
                        <input type="text" name="stall_number" required value="<?php echo isset($_POST['stall_number']) ? htmlspecialchars($_POST['stall_number']) : ''; ?>" placeholder="e.g., A-123">
                    </div>

                    <div class="form-group">
                        <label>Section</label>
                        <select name="section" required>
                            <option value="">Select Section</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?php echo $sec; ?>" <?php echo (isset($_POST['section']) && $_POST['section'] == $sec) ? 'selected' : ''; ?>>
                                    <?php echo $sec; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Monthly Rent (₱)</label>
                        <input type="number" step="0.01" min="0" name="monthly_rent" required value="<?php echo isset($_POST['monthly_rent']) ? htmlspecialchars($_POST['monthly_rent']) : ''; ?>" placeholder="0.00">
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" required placeholder="Minimum 6 characters">
                    </div>

                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" required placeholder="Re-enter password">
                    </div>
                </div>

                <button type="submit" class="btn-register">
                    <i class="fas fa-user-plus"></i>
                    Register as Tenant
                </button>

                <div class="login-link">
                    Already have an account? <a href="login.php">Login here</a>
                </div>
            </form>
        </div>

        <div class="footer-note">
            <i class="fas fa-copyright"></i> 2026 MEEDO · Odiongan Public Market
        </div>
    </div>
</body>
</html>