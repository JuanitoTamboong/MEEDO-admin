<?php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || !isAdmin()) {
    header("Location: ../login.php");
    exit;
}

$db = getDB();
$message = '';
$error = '';

// Get counts for badges
try {
    $pendingApprovals = $db->query("SELECT COUNT(*) FROM tenants WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) {
    $pendingApprovals = 0;
}

try {
    $pendingRepairs = $db->query("SELECT COUNT(*) FROM repairs WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) {
    $pendingRepairs = 0;
}

try {
    $unreadNotifications = $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
} catch (PDOException $e) {
    $unreadNotifications = 0;
}

$totalPending = $pendingApprovals;

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['approve'])) {
        $tenant_id = $_POST['tenant_id'];
        
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT user_id, name, stall_number FROM tenants WHERE id = ?");
            $stmt->execute([$tenant_id]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tenant) {
                throw new Exception("Tenant not found");
            }
            
            $stmt = $db->prepare("UPDATE tenants SET status = 'active' WHERE id = ?");
            $stmt->execute([$tenant_id]);
            
            $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE id = ?");
            $stmt->execute([$tenant['user_id']]);
            
            createNotification(
                $tenant['user_id'],
                'Account Approved',
                "Your tenant account has been approved. You can now access the tenant portal.",
                'success'
            );
            
            $db->commit();
            $message = "Tenant approved successfully!";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error approving tenant: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject'])) {
        $tenant_id = $_POST['tenant_id'];
        
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT user_id, name FROM tenants WHERE id = ?");
            $stmt->execute([$tenant_id]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tenant) {
                throw new Exception("Tenant not found");
            }
            
            $stmt = $db->prepare("DELETE FROM tenants WHERE id = ?");
            $stmt->execute([$tenant_id]);
            
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$tenant['user_id']]);
            
            $db->commit();
            $message = "Tenant registration rejected and removed.";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error rejecting tenant: " . $e->getMessage();
        }
    }
}

// Get pending tenants
$pendingTenants = $db->query("
    SELECT t.*, u.email, u.created_at as registered_at
    FROM tenants t
    JOIN users u ON t.user_id = u.id
    WHERE t.status = 'pending' OR u.status = 'pending'
    ORDER BY t.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pendingCount = count($pendingTenants);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals | MEEDO</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0f172a;
            --primary-light: #1e293b;
            --secondary: #334155;
            --accent: #3b82f6;
            --accent-light: #60a5fa;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #6366f1;
            --light: #f8fafc;
            --dark: #020617;
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
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --sidebar-width: 280px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.5;
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--gray-100);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }

        .app {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: var(--sidebar-width);
            background: white;
            border-right: 1px solid var(--gray-200);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: var(--shadow);
            z-index: 50;
        }

        .sidebar-header {
            padding: 30px 24px;
            border-bottom: 1px solid var(--gray-200);
            background: linear-gradient(to bottom, white, var(--gray-50));
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            box-shadow: var(--shadow-md);
        }

        .logo-text {
            font-weight: 800;
            font-size: 20px;
            letter-spacing: -0.5px;
            color: var(--primary);
        }

        .logo-text span {
            color: var(--accent);
            font-weight: 400;
        }

        .office-tag {
            font-size: 11px;
            color: var(--gray-500);
            margin-top: 4px;
            letter-spacing: 0.3px;
        }

        .org-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--gray-100);
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 500;
            color: var(--gray-700);
            margin-top: 15px;
            border: 1px solid var(--gray-200);
        }

        .org-badge i {
            color: var(--accent);
        }

        .nav-section {
            padding: 24px 24px 8px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-500);
        }

        .nav-menu {
            list-style: none;
            padding: 0 16px;
        }

        .nav-item {
            margin-bottom: 4px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--gray-700);
            text-decoration: none;
            border-radius: 10px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
            position: relative;
        }

        .nav-link i {
            width: 20px;
            font-size: 18px;
            color: var(--gray-500);
            transition: all 0.2s;
        }

        .nav-link:hover {
            background: var(--gray-100);
            color: var(--primary);
        }

        .nav-link:hover i {
            color: var(--accent);
        }

        .nav-link.active {
            background: var(--primary);
            color: white;
            box-shadow: var(--shadow-md);
        }

        .nav-link.active i {
            color: white;
        }

        .nav-link .badge {
            margin-left: auto;
            background: var(--danger);
            color: white;
            padding: 3px 8px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .nav-divider {
            height: 1px;
            background: var(--gray-200);
            margin: 20px 24px;
        }

        .user-profile {
            padding: 20px 24px;
            border-top: 1px solid var(--gray-200);
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
            box-shadow: var(--shadow-sm);
        }

        .user-info h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .user-info p {
            font-size: 12px;
            color: var(--gray-500);
        }

        .main {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
        }

        .top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 16px 24px;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .page-title h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            letter-spacing: -0.3px;
        }

        .page-title p {
            color: var(--gray-500);
            font-size: 14px;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .search-wrapper {
            display: flex;
            align-items: center;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 30px;
            padding: 10px 20px;
            width: 300px;
            transition: all 0.2s;
        }

        .search-wrapper:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-wrapper i {
            color: var(--gray-400);
            margin-right: 12px;
            font-size: 16px;
        }

        .search-wrapper input {
            border: none;
            background: transparent;
            outline: none;
            width: 100%;
            font-size: 14px;
        }

        .notification-btn {
            position: relative;
            width: 48px;
            height: 48px;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-600);
            cursor: pointer;
            transition: all 0.2s;
        }

        .notification-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .notification-dot {
            position: absolute;
            top: 12px;
            right: 14px;
            width: 8px;
            height: 8px;
            background: var(--danger);
            border-radius: 50%;
            border: 2px solid white;
        }

        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .stats-icon {
            width: 64px;
            height: 64px;
            background: #fef3c7;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--warning);
            font-size: 32px;
        }

        .stats-info h2 {
            font-size: 36px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .stats-info p {
            color: var(--gray-500);
            font-size: 14px;
        }

        .approval-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
        }

        .approval-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
            transition: all 0.2s;
        }

        .approval-card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--accent);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-200);
        }

        .stall-badge {
            background: var(--warning);
            color: white;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        .pending-badge {
            background: #fef3c7;
            color: var(--warning);
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .info-row {
            display: flex;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .info-label {
            width: 100px;
            color: var(--gray-500);
            font-weight: 500;
        }

        .info-value {
            flex: 1;
            color: var(--gray-900);
            font-weight: 600;
        }

        .amount {
            font-size: 20px;
            font-weight: 700;
            color: var(--success);
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-200);
        }

        .btn-approve {
            flex: 1;
            padding: 14px;
            background: var(--success);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-approve:hover {
            background: #0d9488;
            transform: translateY(-2px);
        }

        .btn-reject {
            flex: 1;
            padding: 14px;
            background: white;
            color: var(--danger);
            border: 1px solid var(--danger);
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-reject:hover {
            background: #fee2e2;
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert.success {
            background: #dcfce7;
            color: var(--success);
            border: 1px solid #bbf7d0;
        }

        .alert.error {
            background: #fee2e2;
            color: var(--danger);
            border: 1px solid #fecaca;
        }

        .empty-state {
            grid-column: 1/-1;
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 16px;
            border: 1px solid var(--gray-200);
        }

        .empty-state i {
            font-size: 64px;
            color: var(--success);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 24px;
            color: var(--gray-900);
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--gray-500);
        }

        .footer {
            margin-top: 40px;
            padding: 24px;
            background: white;
            border-radius: 16px;
            border: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--gray-500);
            font-size: 13px;
        }

        .footer-left i {
            color: var(--accent);
            margin-right: 6px;
        }

        .footer-right {
            display: flex;
            gap: 20px;
        }

        .footer-right a {
            color: var(--gray-500);
            text-decoration: none;
            transition: color 0.2s;
        }

        .footer-right a:hover {
            color: var(--accent);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stats-card, .approval-card {
            animation: fadeIn 0.3s ease-out forwards;
        }
    </style>
</head>
<body>
    <div class="app">
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <div>
                        <div class="logo-text">MEEDO<span>.pro</span></div>
                        <div class="office-tag">Municipal Enterprise Development</div>
                    </div>
                </div>
                <div class="org-badge">
                    <i class="fas fa-building"></i>
                    Odiongan Public Market
                </div>
            </div>

            <div class="nav-section">MAIN</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-chart-pie"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="stall-monitoring.php" class="nav-link">
                        <i class="fas fa-store-alt"></i>
                        <span>Stall Monitoring</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="reports.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Financial Reports</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="manage-stalls.php" class="nav-link">
                        <i class="fas fa-cogs"></i>
                        <span>Manage Stalls</span>
                    </a>
                </li>
            </ul>

            <div class="nav-section">MANAGEMENT</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="approve-tenants.php" class="nav-link active">
                        <i class="fas fa-user-check"></i>
                        <span>Pending Approvals</span>
                        <?php if ($totalPending > 0): ?>
                            <span class="badge"><?php echo $totalPending; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="repairs.php" class="nav-link">
                        <i class="fas fa-tools"></i>
                        <span>Repair & Maintenance</span>
                        <?php if ($pendingRepairs > 0): ?>
                            <span class="badge"><?php echo $pendingRepairs; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            </ul>

            <div class="nav-divider"></div>

            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="../logout.php" class="nav-link" onclick="return confirm('Are you sure you want to logout?')">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>

            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo substr($_SESSION['name'], 0, 1); ?>
                </div>
                <div class="user-info">
                    <h4><?php echo $_SESSION['name']; ?></h4>
                    <p>Administrator · MEEDO</p>
                </div>
            </div>
        </div>

        <div class="main">
            <div class="top-nav">
                <div class="page-title">
                    <h1>Pending Approvals</h1>
                    <p>
                        <i class="far fa-calendar-alt"></i>
                        <?php echo date('l, F j, Y'); ?>
                        <i class="fas fa-circle" style="font-size: 4px; color: var(--gray-400);"></i>
                        <i class="fas fa-user-clock"></i> Review and approve registrations
                    </p>
                </div>
                <div class="top-actions">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search tenants...">
                    </div>
                    <div class="notification-btn" onclick="location.href='notifications.php'">
                        <i class="far fa-bell"></i>
                        <?php if ($unreadNotifications > 0): ?>
                            <span class="notification-dot"></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="stats-card">
                <div class="stats-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-info">
                    <h2><?php echo $pendingCount; ?></h2>
                    <p>Pending Approvals</p>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="approval-grid">
                <?php if (empty($pendingTenants)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <h3>All Caught Up!</h3>
                        <p>No pending tenant approvals at the moment.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($pendingTenants as $tenant): ?>
                <div class="approval-card">
                    <div class="card-header">
                        <span class="stall-badge">Stall <?php echo htmlspecialchars($tenant['stall_number']); ?></span>
                        <span class="pending-badge"><i class="fas fa-clock"></i> Pending</span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Tenant:</span>
                        <span class="info-value"><?php echo htmlspecialchars($tenant['name']); ?></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Section:</span>
                        <span class="info-value"><?php echo htmlspecialchars($tenant['section']); ?></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Contact:</span>
                        <span class="info-value"><?php echo htmlspecialchars($tenant['contact'] ?: 'N/A'); ?></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><?php echo htmlspecialchars($tenant['email']); ?></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Monthly Rent:</span>
                        <span class="info-value amount"><?php echo formatMoney($tenant['monthly_rent']); ?></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Registered:</span>
                        <span class="info-value"><?php echo date('M d, Y h:i A', strtotime($tenant['registered_at'])); ?></span>
                    </div>

                    <div class="action-buttons">
                        <form method="POST" style="flex: 1; display: flex; gap: 10px;">
                            <input type="hidden" name="tenant_id" value="<?php echo $tenant['id']; ?>">
                            <button type="submit" name="approve" class="btn-approve" onclick="return confirm('Approve this tenant? They will be able to login immediately.')">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button type="submit" name="reject" class="btn-reject" onclick="return confirm('Reject this registration? This will permanently delete the record.')">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="footer">
                <div class="footer-left">
                    <i class="fas fa-copyright"></i> 2026 MEEDO · Odiongan Public Market
                </div>
                <div class="footer-right">
                    <a href="#"><i class="fas fa-shield-alt"></i> Privacy</a>
                    <a href="#"><i class="fas fa-file-contract"></i> Terms</a>
                    <a href="#"><i class="fas fa-envelope"></i> Support</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.querySelector('.search-wrapper input').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                const searchTerm = this.value.toLowerCase();
                const cards = document.querySelectorAll('.approval-card');
                
                cards.forEach(card => {
                    const text = card.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            }
        });

        document.querySelector('.notification-btn').addEventListener('click', function() {
            window.location.href = 'notifications.php';
        });
    </script>
</body>
</html>