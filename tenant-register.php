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

// Get only AVAILABLE stalls
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
    
    if (empty($name) || empty($email) || empty($password) || empty($contact) || empty($stall_id)) {
        $error = "All fields are required. Please select a stall.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters";
    } else {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Email already registered";
        } else {
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
                $monthly_rent = floatval($stall['monthly_rent']);
                
                $maxRetries = 5;
                $retryCount = 0;
                $transactionCompleted = false;
                
                while (!$transactionCompleted && $retryCount < $maxRetries) {
                    try {
                        $db->beginTransaction();
                        
                        $username = strtolower(explode('@', $email)[0]);
                        $baseUsername = $username;
                        $counter = 1;
                        
                        while (true) {
                            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                            $stmt->execute([$username]);
                            if (!$stmt->fetch()) {
                                break;
                            }
                            $username = $baseUsername . $counter;
                            $counter++;
                        }
                        
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("INSERT INTO users (username, name, email, password, role, status) VALUES (?, ?, ?, ?, 'tenant', 'pending')");
                        $stmt->execute([$username, $name, $email, $hashed_password]);
                        $user_id = $db->lastInsertId();
                        
                        $stmt = $db->prepare("
                            INSERT INTO tenants (user_id, stall_id, name, stall_number, section, monthly_rent, contact, email, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                        ");
                        $stmt->execute([$user_id, $stall_id, $name, $stall['stall_number'], $stall['section'], $monthly_rent, $contact, $email]);
                        
                        $stmt = $db->prepare("UPDATE stalls SET status = 'waiting' WHERE id = ?");
                        $stmt->execute([$stall_id]);
                        
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
                        $transactionCompleted = true;
                        
                        $success = "Registration successful!<br><strong>Your Username:</strong> <code>" . htmlspecialchars($username) . "</code><br><strong>Monthly Rent:</strong> ₱" . number_format($monthly_rent, 2) . " per month.<br>Please proceed to the MEEDO office for payment and account approval.";
                        
                        $_POST = array();
                        
                    } catch (Exception $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        
                        if (strpos($e->getMessage(), 'database is locked') !== false) {
                            $retryCount++;
                            if ($retryCount >= $maxRetries) {
                                error_log("Registration failed after $maxRetries retries: " . $e->getMessage());
                                $error = "The system is currently busy. Please try again in a few moments.";
                            } else {
                                $waitTime = 200000 * pow(2, $retryCount);
                                usleep($waitTime);
                            }
                        } else {
                            error_log("Registration Error: " . $e->getMessage());
                            $error = "Registration failed: " . $e->getMessage();
                            break;
                        }
                    }
                }
                
                if (!$transactionCompleted && empty($error)) {
                    $error = "The system is temporarily busy. Please try again in a few moments.";
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
    <title>Register as Tenant | MEEDO Market</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f9fafb;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .register-wrapper {
            max-width: 1200px;
            width: 100%;
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: white;
            padding: 12px 30px;
            border-radius: 100px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .logo-text {
            font-weight: 700;
            font-size: 22px;
            color: #1e293b;
        }

        .logo-text span {
            color: #3b82f6;
        }

        /* Main Card */
        .register-card {
            background: white;
            border-radius: 32px;
            padding: 40px;
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.1);
        }

        .card-header {
            margin-bottom: 30px;
        }

        .card-header h2 {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .card-header p {
            color: #64748b;
            font-size: 15px;
        }

        /* Steps */
        .steps {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 20px 0 30px;
        }

        .step {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .step:not(:last-child)::after {
            content: '';
            flex: 1;
            height: 2px;
            background: #e2e8f0;
        }

        .step-number {
            width: 32px;
            height: 32px;
            background: #f1f5f9;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            color: #64748b;
        }

        .step.active .step-number {
            background: #3b82f6;
            color: white;
        }

        .step-text {
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
        }

        .step.active .step-text {
            color: #1e293b;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }

        .info-item {
            background: #f8fafc;
            border-radius: 16px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .info-icon {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #3b82f6;
        }

        .info-content h4 {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .info-content p {
            font-size: 12px;
            color: #64748b;
        }

        /* Selected Stall */
        .selected-stall {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 16px;
            padding: 15px 20px;
            margin-bottom: 25px;
            display: none;
            align-items: center;
            justify-content: space-between;
        }

        .selected-stall.show {
            display: flex;
        }

        .selected-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .selected-icon {
            width: 45px;
            height: 45px;
            background: #3b82f6;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .selected-details h3 {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
        }

        .selected-details p {
            font-size: 13px;
            color: #64748b;
        }

        .selected-rent {
            font-size: 20px;
            font-weight: 700;
            color: #3b82f6;
        }

        /* Alerts */
        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert.error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fee2e2;
        }

        .alert.success {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #dcfce7;
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 16px;
        }

        .input-wrapper input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }

        /* Stall Selection */
        .stall-section {
            background: #f8fafc;
            border-radius: 20px;
            padding: 20px;
            margin-top: 10px;
        }

        .section-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .tab {
            padding: 10px 18px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .tab:hover {
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .tab.active {
            background: #3b82f6;
            border-color: #3b82f6;
            color: white;
        }

        .tab.active i {
            color: white;
        }

        .stall-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stats-label {
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
        }

        .stats-badge {
            background: #3b82f6;
            color: white;
            padding: 5px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }

        .stall-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            max-height: 300px;
            overflow-y: auto;
            padding: 5px;
        }

        .stall-item {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 5px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .stall-item:hover:not(.occupied) {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px -5px rgba(59,130,246,0.3);
        }

        .stall-item.selected {
            background: #3b82f6;
            border-color: #3b82f6;
            color: white;
        }

        .stall-item.occupied {
            background: #fef2f2;
            border-color: #fee2e2;
            color: #dc2626;
            cursor: not-allowed;
            opacity: 0.7;
        }

        .stall-icon {
            width: 35px;
            height: 35px;
            background: #f1f5f9;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .stall-item.selected .stall-icon {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .stall-number {
            font-weight: 600;
            font-size: 12px;
        }

        .stall-rent {
            font-size: 11px;
            font-weight: 600;
            color: #10b981;
        }

        .stall-item.selected .stall-rent {
            color: rgba(255,255,255,0.9);
        }

        /* Password Strength */
        .strength-meter {
            margin-top: 8px;
        }

        .strength-bars {
            display: flex;
            gap: 4px;
            margin-bottom: 4px;
        }

        .strength-bar {
            height: 4px;
            flex: 1;
            background: #e2e8f0;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .strength-bar.weak { background: #ef4444; }
        .strength-bar.fair { background: #f59e0b; }
        .strength-bar.good { background: #10b981; }
        .strength-bar.strong { background: #059669; }

        .strength-text {
            font-size: 11px;
            font-weight: 500;
            color: #64748b;
        }

        /* Submit Button */
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 14px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 25px 0 20px;
            transition: all 0.2s;
        }

        .btn-submit:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -8px #3b82f6;
        }

        .btn-submit i {
            font-size: 16px;
        }

        /* Login Link */
        .login-link {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .login-link p {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .login-link a {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        /* Loading */
        .loading {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 700px) {
            .register-card {
                padding: 25px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .steps {
                display: none;
            }
            
            .selected-stall {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .selected-info {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="register-wrapper">
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
                <h2>Become a Market Vendor</h2>
                <p>Join Odiongan Public Market's growing community of entrepreneurs</p>
            </div>

            <!-- Steps -->
            <div class="steps">
                <div class="step active">
                    <div class="step-number">1</div>
                    <span class="step-text">Details</span>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <span class="step-text">Select Stall</span>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <span class="step-text">Confirm</span>
                </div>
            </div>

            <!-- Info Grid -->
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="info-content">
                        <h4>Quick Process</h4>
                        <p>Register in 2 minutes</p>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="fas fa-shield"></i>
                    </div>
                    <div class="info-content">
                        <h4>Secure</h4>
                        <p>Your data is safe</p>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-icon">
                        <i class="fas fa-headset"></i>
                    </div>
                    <div class="info-content">
                        <h4>Support</h4>
                        <p>MEEDO assistance</p>
                    </div>
                </div>
            </div>

            <!-- Selected Stall -->
            <div class="selected-stall" id="selectedStall">
                <div class="selected-info">
                    <div class="selected-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <div class="selected-details">
                        <h3 id="selectedName">Stall Selected</h3>
                        <p id="selectedSection">Section</p>
                    </div>
                </div>
                <div class="selected-rent" id="selectedRent">₱0.00/mo</div>
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

            <!-- Form -->
            <form method="POST" id="registrationForm">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Full Name</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" name="name" required value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" placeholder="Juan Dela Cruz">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <div class="input-wrapper">
                            <i class="fas fa-envelope"></i>
                            <input type="email" name="email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" placeholder="juan@email.com">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Contact No.</label>
                        <div class="input-wrapper">
                            <i class="fas fa-phone"></i>
                            <input type="text" name="contact" required value="<?php echo isset($_POST['contact']) ? htmlspecialchars($_POST['contact']) : ''; ?>" placeholder="09123456789">
                        </div>
                    </div>

                    <!-- Stall Selection -->
                    <div class="form-group full">
                        <label>Select Stall</label>
                        <div class="stall-section">
                            <?php if (!empty($sections)): ?>
                            <div class="section-tabs" id="sectionTabs">
                                <?php foreach ($sections as $index => $section): ?>
                                    <div class="tab <?php echo $index === 0 ? 'active' : ''; ?>" 
                                         onclick="selectSection('<?php echo $section['name']; ?>')">
                                        <i class="fas fa-<?php 
                                            echo $section['icon'] ?? ($section['name'] == 'Meat Section' ? 'drumstick-bite' : 
                                                ($section['name'] == 'Fish Section' ? 'fish' : 
                                                ($section['name'] == 'Vegetable Section' ? 'carrot' : 
                                                ($section['name'] == 'Dry Goods' ? 'box' : 'store')))); 
                                        ?>"></i>
                                        <?php echo $section['name']; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="stall-stats">
                                <span class="stats-label">
                                    <i class="fas fa-store"></i> Available Stalls
                                </span>
                                <span class="stats-badge" id="availableCount">0 available</span>
                            </div>

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
                                            
                                            if (empty($sectionStalls)) {
                                                echo '<div style="grid-column:1/-1; text-align:center; padding:30px; color:#94a3b8;">
                                                        <i class="fas fa-store-slash" style="font-size:30px; margin-bottom:10px;"></i>
                                                        <p>No stalls available</p>
                                                      </div>';
                                            }
                                            
                                            foreach ($sectionStalls as $stall): 
                                                $isOccupied = !is_null($stall['tenant_id']);
                                                $stallRent = floatval($stall['monthly_rent']);
                                            ?>
                                                <div class="stall-item <?php echo $isOccupied ? 'occupied' : ''; ?>" 
                                                     onclick="<?php echo !$isOccupied ? "selectStall({$stall['id']}, '{$stall['stall_number']}', '{$stall['section']}', {$stallRent})" : ''; ?>"
                                                     data-stall-id="<?php echo $stall['id']; ?>">
                                                    
                                                    <div class="stall-icon">
                                                        <i class="fas fa-<?php 
                                                            echo $section['icon'] ?? ($section['name'] == 'Meat Section' ? 'drumstick-bite' : 
                                                                ($section['name'] == 'Fish Section' ? 'fish' : 
                                                                ($section['name'] == 'Vegetable Section' ? 'carrot' : 'store'))); 
                                                        ?>"></i>
                                                    </div>
                                                    
                                                    <span class="stall-number"><?php echo $stall['stall_number']; ?></span>
                                                    <span class="stall-rent">₱<?php echo number_format($stallRent, 0); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                                <div style="text-align:center; padding:30px;">
                                    <p style="color:#ef4444;">No stalls available at the moment</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <input type="hidden" name="stall_id" id="selectedStallId">

                    <!-- Password -->
                    <div class="form-group">
                        <label>Password</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="password" id="password" required placeholder="••••••••">
                        </div>
                        <div class="strength-meter">
                            <div class="strength-bars" id="strengthBars">
                                <div class="strength-bar"></div>
                                <div class="strength-bar"></div>
                                <div class="strength-bar"></div>
                                <div class="strength-bar"></div>
                            </div>
                            <div class="strength-text" id="strengthText"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="confirm_password" id="confirmPassword" required placeholder="••••••••">
                        </div>
                        <div style="font-size:11px; margin-top:4px;" id="matchText"></div>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="fas fa-user-plus"></i>
                    <span>Create Account</span>
                </button>

                <div class="login-link">
                    <p>Already have an account?</p>
                    <a href="login.php">
                        <i class="fas fa-sign-in-alt"></i> Sign in
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentSection = '<?php echo !empty($sections) ? $sections[0]['name'] : ''; ?>';

        // Section switching
        function selectSection(section) {
            currentSection = section;
            
            document.querySelectorAll('.tab').forEach(tab => {
                if (tab.textContent.includes(section)) {
                    tab.classList.add('active');
                } else {
                    tab.classList.remove('active');
                }
            });
            
            document.querySelectorAll('.section-stalls').forEach(grid => {
                grid.style.display = 'none';
            });
            
            const sectionId = 'stalls-' + section.replace(/[^a-zA-Z0-9]/g, '');
            const activeGrid = document.getElementById(sectionId);
            if (activeGrid) {
                activeGrid.style.display = 'block';
            }
            
            updateAvailableCount(section);
        }

        // Stall selection
        function selectStall(id, number, section, rent) {
            // Remove selected class from all stalls
            document.querySelectorAll('.stall-item').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Add selected class to clicked stall
            const clickedStall = document.querySelector(`[data-stall-id="${id}"]`);
            if (clickedStall) {
                clickedStall.classList.add('selected');
            }
            
            // Update hidden input
            document.getElementById('selectedStallId').value = id;
            
            // Show selected stall info
            document.getElementById('selectedName').textContent = 'Stall ' + number;
            document.getElementById('selectedSection').textContent = section;
            document.getElementById('selectedRent').textContent = '₱' + rent.toLocaleString() + '/mo';
            document.getElementById('selectedStall').classList.add('show');
        }

        // Update available count
        function updateAvailableCount(section) {
            const grid = document.getElementById('stalls-' + section.replace(/[^a-zA-Z0-9]/g, ''));
            if (grid) {
                const available = grid.querySelectorAll('.stall-item:not(.occupied)').length;
                const badge = document.getElementById('availableCount');
                if (badge) {
                    badge.textContent = available + ' available';
                }
            }
        }

        // Password strength
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strength = calculateStrength(password);
            const bars = document.querySelectorAll('#strengthBars .strength-bar');
            const text = document.getElementById('strengthText');
            
            // Reset bars
            bars.forEach(bar => {
                bar.className = 'strength-bar';
            });
            
            if (password.length === 0) {
                text.textContent = '';
                return;
            }
            
            if (password.length < 6) {
                text.textContent = 'Too short';
                return;
            }
            
            // Set bars based on strength
            if (strength <= 2) {
                bars[0].classList.add('weak');
                text.textContent = 'Weak';
            } else if (strength <= 3) {
                bars[0].classList.add('fair');
                bars[1].classList.add('fair');
                text.textContent = 'Fair';
            } else if (strength <= 4) {
                bars[0].classList.add('good');
                bars[1].classList.add('good');
                bars[2].classList.add('good');
                text.textContent = 'Good';
            } else {
                bars[0].classList.add('strong');
                bars[1].classList.add('strong');
                bars[2].classList.add('strong');
                bars[3].classList.add('strong');
                text.textContent = 'Strong';
            }
        });

        function calculateStrength(password) {
            let strength = 0;
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]/)) strength++;
            if (password.match(/[A-Z]/)) strength++;
            if (password.match(/[0-9]/)) strength++;
            if (password.match(/[^a-zA-Z0-9]/)) strength++;
            return strength;
        }

        // Password match
        document.getElementById('confirmPassword').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirm = this.value;
            const matchText = document.getElementById('matchText');
            
            if (confirm.length === 0) {
                matchText.textContent = '';
            } else if (password === confirm) {
                matchText.textContent = '✓ Passwords match';
                matchText.style.color = '#10b981';
            } else {
                matchText.textContent = '✗ Passwords do not match';
                matchText.style.color = '#ef4444';
            }
        });

        // Form submit
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const stallId = document.getElementById('selectedStallId').value;
            
            if (!stallId) {
                e.preventDefault();
                alert('Please select a stall');
                return;
            }
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="loading"></span> Creating Account...';
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            if (currentSection) {
                updateAvailableCount(currentSection);
            }
            
            <?php if (isset($_POST['stall_id'])): ?>
            const stallId = <?php echo $_POST['stall_id']; ?>;
            const stallElement = document.querySelector(`[data-stall-id="${stallId}"]`);
            if (stallElement) {
                const number = stallElement.querySelector('.stall-number').textContent;
                const section = '<?php echo $_POST['section'] ?? ''; ?>';
                const rent = stallElement.dataset.monthlyRent || 0;
                selectStall(stallId, number, section, parseFloat(rent));
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>