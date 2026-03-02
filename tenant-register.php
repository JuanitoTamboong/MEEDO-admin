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

// Get sections that have available stalls
$sections = $db->query("
    SELECT DISTINCT s.* 
    FROM sections s
    INNER JOIN stalls st ON s.name = st.section
    LEFT JOIN tenants t ON st.id = t.stall_id AND t.status IN ('active', 'pending')
    WHERE st.status = 'available' AND t.id IS NULL
    ORDER BY s.display_order, s.name
")->fetchAll(PDO::FETCH_ASSOC);

// Get only AVAILABLE stalls (no active or pending tenant, and status is 'available')
$stalls = $db->query("
    SELECT s.*, 
           t.name as tenant_name, 
           t.status as tenant_status,
           t.id as tenant_id,
           s.monthly_rent as stall_rent
    FROM stalls s
    LEFT JOIN tenants t ON s.id = t.stall_id AND t.status IN ('active', 'pending')
    WHERE s.status = 'available' AND t.id IS NULL
    ORDER BY s.section, s.stall_number
")->fetchAll(PDO::FETCH_ASSOC);

// Group stalls by section
$stallsBySection = [];
foreach ($stalls as $stall) {
    $stallsBySection[$stall['section']][] = $stall;
}

// Get all stalls with prices for JavaScript
$allStallsJson = json_encode(array_map(function($s) {
    return [
        'id' => $s['id'],
        'stall_number' => $s['stall_number'],
        'section' => $s['section'],
        'monthly_rent' => floatval($s['monthly_rent'])
    ];
}, $stalls));

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $contact = trim($_POST['contact']);
    $stall_id = intval($_POST['stall_id']);
    
    // Validation - no need for monthly_rent input anymore
    if (empty($name) || empty($email) || empty($password) || empty($contact) || empty($stall_id)) {
        $error = "All fields are required. Please select a stall.";
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
            // Check if stall is still available and get its monthly rent
            $stmt = $db->prepare("
                SELECT s.*, t.id as tenant_id 
                FROM stalls s
                LEFT JOIN tenants t ON s.id = t.stall_id AND t.status IN ('active', 'pending')
                WHERE s.id = ? AND t.id IS NULL
            ");
            $stmt->execute([$stall_id]);
            $stall = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$stall) {
                $error = "This stall is no longer available. Please select another stall.";
            } else {
                // Get monthly rent from the stall (automatic - no manual entry needed)
                $monthly_rent = floatval($stall['monthly_rent']);
                
                // Begin transaction
                $db->beginTransaction();
                try {
                    // Create username from email
                    $username = strtolower(explode('@', $email)[0]);
                    
                    // Create user account
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO users (username, name, email, password, role, status) VALUES (?, ?, ?, ?, 'tenant', 'pending')");
                    $stmt->execute([$username, $name, $email, $hashed_password]);
                    $user_id = $db->lastInsertId();
                    
                    // Create tenant record - using stall's monthly rent automatically
                    $stmt = $db->prepare("
                        INSERT INTO tenants (user_id, name, stall_number, section, monthly_rent, contact, email, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([$user_id, $name, $stall['stall_number'], $stall['section'], $monthly_rent, $contact, $email]);
                    
                    // Update stall status
                    $stmt = $db->prepare("UPDATE stalls SET status = 'pending' WHERE id = ?");
                    $stmt->execute([$stall_id]);
                    
                    // Create notification for admin
                    $adminQuery = $db->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
                    $admin = $adminQuery->fetch();
                    if ($admin) {
                        createNotification(
                            $admin['id'],
                            'New Tenant Registration',
                            "New tenant registration pending: $name (Stall {$stall['stall_number']} - ₱" . number_format($monthly_rent, 2) . "/month)",
                            'info'
                        );
                    }
                    
                    $db->commit();
                    $success = "Registration successful!<br><strong>Your Username:</strong> <code>" . htmlspecialchars($username) . "</code><br><strong>Monthly Rent:</strong> ₱" . number_format($monthly_rent, 2) . " per month.<br>Please proceed to the MEEDO office for payment and account approval.";
                    
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
    <title>Tenant Registration | MEEDO Market System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0f172a;
            --primary-light: #1e293b;
            --accent: #3b82f6;
            --accent-gradient: linear-gradient(135deg, #3b82f6, #8b5cf6);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --surface: #ffffff;
            --background: #f8fafc;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-tertiary: #64748b;
            --border: #e2e8f0;
            --border-light: #f1f5f9;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-md: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 25px 50px -12px rgb(0 0 0 / 0.25);
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" opacity="0.1"><circle cx="50" cy="50" r="40" fill="none" stroke="white" stroke-width="2"/></svg>') repeat;
            pointer-events: none;
        }

        .register-container {
            max-width: 1200px;
            width: 100%;
            position: relative;
            z-index: 1;
        }

        /* Header Styles */
        .header {
            text-align: center;
            margin-bottom: 30px;
            animation: slideDown 0.5s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 16px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 16px 32px;
            border-radius: 60px;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .logo-icon {
            width: 56px;
            height: 56px;
            background: var(--accent-gradient);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            box-shadow: var(--shadow-md);
        }

        .logo-text {
            font-weight: 800;
            font-size: 28px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo-text span {
            background: linear-gradient(135deg, var(--accent), #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Main Card */
        .register-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 40px;
            padding: 40px;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: fadeIn 0.5s ease 0.2s both;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-header {
            margin-bottom: 30px;
        }

        .card-header h2 {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .subtitle {
            color: var(--text-secondary);
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-light);
        }

        .subtitle i {
            color: var(--accent);
        }

        /* Info Box */
        .info-box {
            background: linear-gradient(135deg, #eff6ff, #e0f2fe);
            border-radius: 20px;
            padding: 20px 24px;
            margin-bottom: 30px;
            display: flex;
            gap: 16px;
            border: 1px solid #bae6fd;
        }

        .info-icon {
            width: 48px;
            height: 48px;
            background: white;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            font-size: 20px;
            box-shadow: var(--shadow-sm);
        }

        .info-content {
            flex: 1;
        }

        .info-content h4 {
            color: #0369a1;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .info-content p {
            color: #0284c7;
            font-size: 14px;
            line-height: 1.6;
        }

        /* Selected Stall Info Box */
        .selected-stall-info {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: none;
            border: 1px solid #6ee7b7;
            align-items: center;
            gap: 16px;
        }

        .selected-stall-info.show {
            display: flex;
        }

        .selected-stall-info .stall-icon {
            width: 48px;
            height: 48px;
            background: var(--success);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .selected-stall-info .stall-details {
            flex: 1;
        }

        .selected-stall-info .stall-details h4 {
            color: #065f46;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .selected-stall-info .stall-details p {
            color: #047857;
            font-size: 14px;
        }

        .selected-stall-info .rent-amount {
            font-size: 24px;
            font-weight: 800;
            color: var(--success);
        }

        /* Form Layout */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 4px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 8px;
            letter-spacing: 0.3px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-tertiary);
            font-size: 16px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 14px 16px 14px 45px;
            border: 1.5px solid var(--border);
            border-radius: 16px;
            font-size: 14px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            transition: all 0.2s;
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .form-group input::placeholder {
            color: var(--text-tertiary);
            opacity: 0.7;
        }

        /* Stall Selection Area */
        .stall-selection {
            background: var(--background);
            border-radius: 24px;
            padding: 24px;
            border: 1px solid var(--border-light);
            margin: 20px 0;
        }

        .section-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-light);
        }

        .section-tab {
            padding: 10px 20px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 40px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-tab i {
            font-size: 14px;
        }

        .section-tab:hover {
            border-color: var(--accent);
            color: var(--accent);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .section-tab.active {
            background: var(--accent-gradient);
            border-color: transparent;
            color: white;
            box-shadow: 0 8px 16px -4px rgba(59, 130, 246, 0.3);
        }

        .stall-counter {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .counter-label {
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .counter-badge {
            background: white;
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 600;
            color: var(--success);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }

        .counter-badge i {
            margin-right: 6px;
        }

        .stall-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 12px;
            max-height: 300px;
            overflow-y: auto;
            padding: 8px;
            border-radius: 16px;
            background: white;
        }

        .stall-item {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .stall-item:hover:not(.occupied) {
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .stall-item.selected {
            background: var(--accent-gradient);
            border-color: transparent;
            color: white;
            box-shadow: 0 8px 16px -4px rgba(59, 130, 246, 0.3);
        }

        .stall-item.occupied {
            background: #fef2f2;
            border-color: #fee2e2;
            color: var(--danger);
            cursor: not-allowed;
            opacity: 0.7;
        }

        .stall-icon {
            width: 40px;
            height: 40px;
            background: var(--background);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .stall-item.selected .stall-icon {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .stall-item.occupied .stall-icon {
            background: #fee2e2;
            color: var(--danger);
        }

        .stall-number {
            font-weight: 700;
            font-size: 12px;
        }

        .stall-rent {
            font-size: 10px;
            color: var(--success);
            font-weight: 600;
        }

        .stall-item.selected .stall-rent {
            color: rgba(255, 255, 255, 0.9);
        }

        .stall-status-badge {
            font-size: 9px;
            padding: 3px 6px;
            border-radius: 30px;
            background: var(--background);
            color: var(--text-secondary);
            font-weight: 600;
        }

        .stall-item.selected .stall-status-badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .stall-tooltip {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--text-primary);
            color: white;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s;
            pointer-events: none;
            z-index: 10;
        }

        .stall-item:hover .stall-tooltip {
            opacity: 1;
            visibility: visible;
            bottom: calc(100% + 5px);
        }

        /* Button */
        .btn-register {
            width: 100%;
            padding: 18px;
            background: var(--accent-gradient);
            color: white;
            border: none;
            border-radius: 30px;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin: 30px 0 20px;
            transition: all 0.3s;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .btn-register::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn-register:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 30px -10px rgba(59, 130, 246, 0.5);
        }

        .btn-register:hover::before {
            left: 100%;
        }

        .btn-register i {
            font-size: 18px;
        }

        /* Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert.error {
            background: #fef2f2;
            color: var(--danger);
            border: 1px solid #fee2e2;
        }

        .alert.success {
            background: #f0fdf4;
            color: var(--success);
            border: 1px solid #dcfce7;
        }

        .alert i {
            font-size: 20px;
        }

        /* Login Link */
        .login-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-light);
        }

        .login-link p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .login-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            margin-left: 5px;
            transition: all 0.2s;
        }

        .login-link a:hover {
            color: #8b5cf6;
            text-decoration: underline;
        }

        /* Footer */
        .footer-note {
            text-align: center;
            margin-top: 20px;
            color: rgba(255, 255, 255, 0.9);
            font-size: 13px;
            font-weight: 500;
            animation: fadeIn 0.5s ease 0.4s both;
        }

        .footer-note a {
            color: white;
            text-decoration: none;
            font-weight: 600;
        }

        .footer-note a:hover {
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .register-card {
                padding: 25px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .stall-grid {
                grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            }

            .logo-text {
                font-size: 22px;
            }
        }

        /* Loading Animation */
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
    </style>
</head>
<body>
    <div class="register-container">
        <!-- Header -->
        <div class="header">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-store"></i>
                </div>
                <div class="logo-text">MEEDO<span>.market</span></div>
            </div>
        </div>

        <!-- Main Card -->
        <div class="register-card">
            <div class="card-header">
                <h2>Tenant Registration</h2>
                <div class="subtitle">
                    <i class="fas fa-map-pin"></i>
                    Odiongan Public Market · Municipal Enterprise Development Office
                </div>
            </div>

            <!-- Info Box -->
            <div class="info-box">
                <div class="info-icon">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div class="info-content">
                    <h4>Registration Process</h4>
                    <p>After registration, please proceed to the MEEDO office for payment verification. Your account will be activated within 24 hours after payment confirmation. Select your preferred stall from the available options below.</p>
                </div>
            </div>

            <!-- Selected Stall Info -->
            <div class="selected-stall-info" id="selectedStallInfo">
                <div class="stall-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stall-details">
                    <h4 id="selectedStallName">Stall Selected</h4>
                    <p id="selectedStallSection">Section</p>
                </div>
                <div class="rent-amount" id="selectedStallRent">₱0.00/mo</div>
            </div>

            <!-- Alerts -->
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

            <!-- Registration Form -->
            <form method="POST" action="" id="registrationForm">
                <div class="form-grid">
                    <!-- Personal Information -->
                    <div class="form-group full-width">
                        <label>Full Name</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" name="name" required value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" placeholder="Enter your full name">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <div class="input-wrapper">
                            <i class="fas fa-envelope"></i>
                            <input type="email" name="email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" placeholder="your@email.com">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Contact Number</label>
                        <div class="input-wrapper">
                            <i class="fas fa-phone"></i>
                            <input type="text" name="contact" required value="<?php echo isset($_POST['contact']) ? htmlspecialchars($_POST['contact']) : ''; ?>" placeholder="09XXXXXXXXX">
                        </div>
                    </div>

                    <!-- Stall Selection -->
                    <div class="form-group full-width">
                        <label>Select Your Stall (Price is automatic)</label>
                        <div class="stall-selection">
                            <!-- Section Tabs -->
                            <div class="section-tabs" id="sectionTabs">
                                <?php foreach ($sections as $index => $section): ?>
                                    <div class="section-tab <?php echo $index === 0 ? 'active' : ''; ?>" 
                                         data-section="<?php echo $section['name']; ?>"
                                         onclick="selectSection('<?php echo $section['name']; ?>')">
                                        <i class="fas fa-<?php 
                                            echo $section['icon'] ?? ($section['name'] == 'Meat Section' ? 'drumstick-bite' : 
                                                ($section['name'] == 'Fish Section' ? 'fish' : 
                                                ($section['name'] == 'Vegetable Section' ? 'carrot' : 
                                                ($section['name'] == 'Dry Goods' ? 'box' : 
                                                ($section['name'] == 'Rice Section' ? 'seedling' : 'store'))))); 
                                        ?>"></i>
                                        <?php echo $section['name']; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Stall Counter -->
                            <div class="stall-counter">
                                <span class="counter-label">
                                    <i class="fas fa-store"></i> Available Stalls
                                </span>
                                <span class="counter-badge" id="availableCount">
                                    <i class="fas fa-check-circle"></i>
                                    0 available
                                </span>
                            </div>

                            <!-- Stall Grid -->
                            <div id="stallGrid">
                                <?php foreach ($sections as $section): ?>
                                    <div class="section-stalls" 
                                         id="stalls-<?php echo preg_replace('/[^a-zA-Z0-9]/', '', $section['name']); ?>" 
                                         style="display: <?php echo $section === $sections[0] ? 'block' : 'none'; ?>;">
                                        <div class="stall-grid">
                                            <?php 
                                            $sectionStalls = array_filter($stalls, function($stall) use ($section) {
                                                return $stall['section'] === $section['name'];
                                            });
                                            
                                            foreach ($sectionStalls as $stall): 
                                                $isOccupied = !is_null($stall['tenant_id']);
                                                $tenantName = $stall['tenant_name'] ?? '';
                                                $tenantStatus = $stall['tenant_status'] ?? '';
                                                $stallRent = floatval($stall['monthly_rent']);
                                            ?>
                                                <div class="stall-item <?php 
                                                    echo $isOccupied ? 'occupied' : ''; 
                                                    echo (!$isOccupied && isset($_POST['stall_id']) && $_POST['stall_id'] == $stall['id']) ? ' selected' : '';
                                                ?>" 
                                                     onclick="<?php echo !$isOccupied ? "selectStall({$stall['id']}, '{$stall['stall_number']}', '{$stall['section']}', {$stallRent}, this)" : ''; ?>"
                                                     data-stall-id="<?php echo $stall['id']; ?>"
                                                     data-monthly-rent="<?php echo $stallRent; ?>">
                                                    
                                                    <!-- Tooltip -->
                                                    <?php if ($isOccupied): ?>
                                                        <div class="stall-tooltip">
                                                            Occupied by: <?php echo $tenantName; ?> (<?php echo $tenantStatus; ?>)
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="stall-tooltip">
                                                            ₱<?php echo number_format($stallRent, 2); ?>/month
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Stall Icon -->
                                                    <div class="stall-icon">
                                                        <i class="fas fa-<?php 
                                                            echo $section['icon'] ?? ($section['name'] == 'Meat Section' ? 'drumstick-bite' : 
                                                                ($section['name'] == 'Fish Section' ? 'fish' : 
                                                                ($section['name'] == 'Vegetable Section' ? 'carrot' : 
                                                                ($section['name'] == 'Dry Goods' ? 'box' : 
                                                                ($section['name'] == 'Rice Section' ? 'seedling' : 'store'))))); 
                                                        ?>"></i>
                                                    </div>
                                                    
                                                    <!-- Stall Number -->
                                                    <span class="stall-number"><?php echo $stall['stall_number']; ?></span>
                                                    
                                                    <!-- Rent Display -->
                                                    <span class="stall-rent">₱<?php echo number_format($stallRent, 0); ?></span>
                                                    
                                                    <!-- Status Badge -->
                                                    <span class="stall-status-badge">
                                                        <?php if ($isOccupied): ?>
                                                            <i class="fas fa-lock"></i> Occupied
                                                        <?php else: ?>
                                                            <i class="fas fa-check"></i> Available
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="stall_id" id="selectedStallId" value="<?php echo isset($_POST['stall_id']) ? htmlspecialchars($_POST['stall_id']) : ''; ?>">

                    <!-- Password Fields -->
                    <div class="form-group">
                        <label>Password</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="password" required placeholder="Minimum 6 characters">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="confirm_password" required placeholder="Re-enter password">
                        </div>
                    </div>
                </div>

                <!-- Register Button -->
                <button type="submit" class="btn-register" id="submitBtn">
                    <i class="fas fa-user-plus"></i>
                    <span>Complete Registration</span>
                </button>

                <!-- Login Link -->
                <div class="login-link">
                    <p>Already have an account? <a href="login.php">Sign in here</a></p>
                </div>
            </form>
        </div>

        <!-- Footer -->
        <div class="footer-note">
            <i class="fas fa-copyright"></i> 2026 MEEDO · Odiongan Public Market · 
            <a href="#">Terms</a> · <a href="#">Privacy</a>
        </div>
    </div>

    <script>
        let currentSection = '<?php echo $sections[0]['name'] ?? ''; ?>';
        let selectedStallId = null;
        let selectedStallRent = 0;

        // Stall data from PHP
        const allStalls = <?php echo $allStallsJson; ?>;

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Sections:', currentSection);
            console.log('Stalls:', allStalls);
            
            if (currentSection) {
                updateAvailableCount(currentSection);
            }

            // If there's a pre-selected stall (from form submission), highlight it
            <?php if (isset($_POST['stall_id']) && isset($_POST['section'])): ?>
            const selectedId = <?php echo $_POST['stall_id']; ?>;
            const selectedSection = '<?php echo $_POST['section']; ?>';
            selectSection(selectedSection);
            
            setTimeout(() => {
                const stallElement = document.querySelector(`.stall-item[data-stall-id="${selectedId}"]`);
                if (stallElement && !stallElement.classList.contains('occupied')) {
                    stallElement.classList.add('selected');
                }
            }, 100);
            <?php endif; ?>
        });

        // Select section
        function selectSection(section) {
            currentSection = section;
            
            // Update tabs
            document.querySelectorAll('.section-tab').forEach(tab => {
                if (tab.dataset.section === section) {
                    tab.classList.add('active');
                } else {
                    tab.classList.remove('active');
                }
            });
            
            // Update stall grids
            document.querySelectorAll('.section-stalls').forEach(grid => {
                grid.style.display = 'none';
            });
            
            const sectionId = 'stalls-' + section.replace(/[^a-zA-Z0-9]/g, '');
            const activeGrid = document.getElementById(sectionId);
            if (activeGrid) {
                activeGrid.style.display = 'block';
            }
            
            // Update available count
            updateAvailableCount(section);
        }

        // Select stall - now includes rent
        function selectStall(stallId, stallNumber, section, monthlyRent, element) {
            // Remove selected class from all stalls
            document.querySelectorAll('.stall-item').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Add selected class to clicked stall
            element.classList.add('selected');
            
            // Update hidden input
            document.getElementById('selectedStallId').value = stallId;
            selectedStallId = stallId;
            selectedStallRent = monthlyRent;
            
            // Show selected stall info
            const stallInfo = document.getElementById('selectedStallInfo');
            document.getElementById('selectedStallName').textContent = 'Stall ' + stallNumber;
            document.getElementById('selectedStallSection').textContent = section;
            document.getElementById('selectedStallRent').textContent = '₱' + monthlyRent.toLocaleString('en-US', {minimumFractionDigits: 2}) + '/mo';
            stallInfo.classList.add('show');
            
            // Visual feedback
            element.style.transform = 'scale(0.95)';
            setTimeout(() => {
                element.style.transform = '';
            }, 200);
        }

        // Update available count
        function updateAvailableCount(section) {
            const grid = document.getElementById('stalls-' + section.replace(/[^a-zA-Z0-9]/g, ''));
            if (grid) {
                const available = grid.querySelectorAll('.stall-item:not(.occupied)').length;
                document.getElementById('availableCount').innerHTML = `
                    <i class="fas fa-check-circle"></i>
                    ${available} available ${available === 1 ? 'stall' : 'stalls'}
                `;
            }
        }

        // Form validation - simplified
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const form = this;
            const selectedStall = document.getElementById('selectedStallId').value;
            
            if (!selectedStall) {
                e.preventDefault();
                alert('Please select a stall from the available options');
                return true;
            }
            
            // Allow form to submit normally - remove the loading state blocking
            console.log('Form submitting with stall_id:', selectedStall);
        });

        // Password strength indicator
        document.querySelector('input[name="password"]').addEventListener('input', function() {
            const password = this.value;
            const strength = calculatePasswordStrength(password);
            
            // You can add visual indicator here
        });

        function calculatePasswordStrength(password) {
            let strength = 0;
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[$@#&!]+/)) strength++;
            return strength;
        }

        // Smooth scroll to selected section
        function scrollToSection(section) {
            const element = document.getElementById('stalls-' + section.replace(/[^a-zA-Z0-9]/g, ''));
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    </script>
</body>
</html>
