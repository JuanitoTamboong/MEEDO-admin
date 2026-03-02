<?php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || !isAdmin()) {
    header("Location: ../login.php");
    exit;
}

$db = getDB();
$message = '';

// Get counts for badges
try {
    $pendingApprovals = $db->query("SELECT COUNT(*) FROM tenants WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) {
    $pendingApprovals = 0;
}

try {
    $pendingRepairsCount = $db->query("SELECT COUNT(*) FROM repairs WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) {
    $pendingRepairsCount = 0;
}

try {
    $inProgressRepairsCount = $db->query("SELECT COUNT(*) FROM repairs WHERE status = 'in_progress'")->fetchColumn();
} catch (PDOException $e) {
    $inProgressRepairsCount = 0;
}

try {
    $completedRepairsCount = $db->query("SELECT COUNT(*) FROM repairs WHERE status = 'completed'")->fetchColumn();
} catch (PDOException $e) {
    $completedRepairsCount = 0;
}

try {
    $unreadNotifications = $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
} catch (PDOException $e) {
    $unreadNotifications = 0;
}

$totalPending = $pendingApprovals;

// Handle repair actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_status'])) {
        $stmt = $db->prepare("
            UPDATE repairs 
            SET status = ?, completion_date = CURRENT_TIMESTAMP, cost = ?, admin_remarks = ? 
            WHERE id = ?
        ");
        if ($stmt->execute([$_POST['status'], $_POST['cost'] ?: 0, $_POST['remarks'], $_POST['repair_id']])) {
            $message = "Repair request updated successfully!";
            
            // Notify tenant
            $repair = $db->prepare("SELECT tenant_id FROM repairs WHERE id = ?");
            $repair->execute([$_POST['repair_id']]);
            $tenantId = $repair->fetchColumn();
            
            $tenant = $db->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $tenant->execute([$tenantId]);
            $userId = $tenant->fetchColumn();
            
            createNotification(
                $userId,
                'Repair Request Updated',
                "Your repair request has been marked as {$_POST['status']}." . ($_POST['remarks'] ? " Remarks: {$_POST['remarks']}" : ""),
                $_POST['status'] == 'completed' ? 'success' : 'info'
            );
        }
    }
}

// Get all repairs
$pendingRepairs = $db->query("
    SELECT r.*, t.name, t.stall_number, t.section, t.contact
    FROM repairs r
    JOIN tenants t ON r.tenant_id = t.id
    WHERE r.status = 'pending'
    ORDER BY r.report_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$inProgressRepairs = $db->query("
    SELECT r.*, t.name, t.stall_number, t.section, t.contact
    FROM repairs r
    JOIN tenants t ON r.tenant_id = t.id
    WHERE r.status = 'in_progress'
    ORDER BY r.report_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$completedRepairs = $db->query("
    SELECT r.*, t.name, t.stall_number, t.section, t.contact
    FROM repairs r
    JOIN tenants t ON r.tenant_id = t.id
    WHERE r.status = 'completed'
    ORDER BY r.completion_date DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'pending' => count($pendingRepairs),
    'in_progress' => count($inProgressRepairs),
    'completed' => $db->query("SELECT COUNT(*) FROM repairs WHERE status = 'completed'")->fetchColumn(),
    'total_cost' => $db->query("SELECT SUM(cost) FROM repairs WHERE status = 'completed'")->fetchColumn() ?: 0
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repair & Maintenance | MEEDO</title>
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
            transition: all 0.2s;
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--accent);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            border: 1px solid var(--gray-200);
        }

        .stat-label {
            color: var(--gray-500);
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            background: white;
            padding: 8px;
            border-radius: 40px;
            border: 1px solid var(--gray-200);
            width: fit-content;
        }

        .tab {
            padding: 10px 24px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-500);
            cursor: pointer;
            transition: all 0.2s;
        }

        .tab:hover {
            color: var(--accent);
        }

        .tab.active {
            background: var(--primary);
            color: white;
        }

        .repair-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .repair-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
            transition: all 0.2s;
        }

        .repair-card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--accent);
        }

        .repair-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--gray-200);
        }

        .stall-badge {
            background: var(--primary);
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-badge.pending {
            background: #fef3c7;
            color: var(--warning);
        }

        .status-badge.progress {
            background: #dbeafe;
            color: var(--accent);
        }

        .status-badge.completed {
            background: #dcfce7;
            color: var(--success);
        }
        
        .repair-body {
            margin-bottom: 15px;
        }

        .tenant-name {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .issue-desc {
            background: var(--gray-50);
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            color: var(--gray-700);
            margin: 10px 0;
        }

        .repair-meta {
            display: flex;
            gap: 15px;
            font-size: 12px;
            color: var(--gray-500);
            margin: 10px 0;
        }

        .repair-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding-top: 12px;
            border-top: 1px solid var(--gray-200);
        }

        .action-btn {
            padding: 8px 16px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .action-btn:hover {
            background: var(--primary);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            width: 400px;
            max-width: 90%;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            font-size: 20px;
            font-weight: 700;
        }

        .close-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--gray-100);
            border: none;
            cursor: pointer;
            font-size: 18px;
        }

        .close-btn:hover {
            background: var(--gray-200);
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

        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #dcfce7;
            color: var(--success);
            border: 1px solid #bbf7d0;
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

        .stat-card, .repair-card {
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
                    <a href="approve-tenants.php" class="nav-link">
                        <i class="fas fa-user-check"></i>
                        <span>Pending Approvals</span>
                        <?php if ($totalPending > 0): ?>
                            <span class="badge"><?php echo $totalPending; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="repairs.php" class="nav-link active">
                        <i class="fas fa-tools"></i>
                        <span>Repair & Maintenance</span>
                        <?php if ($pendingRepairsCount > 0): ?>
                            <span class="badge"><?php echo $pendingRepairsCount; ?></span>
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
                    <h1>Repair & Maintenance</h1>
                    <p>
                        <i class="far fa-calendar-alt"></i>
                        <?php echo date('l, F j, Y'); ?>
                        <i class="fas fa-circle" style="font-size: 4px; color: var(--gray-400);"></i>
                        <i class="fas fa-tools"></i> Manage repair requests
                    </p>
                </div>
                <div class="top-actions">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search repairs...">
                    </div>
                    <div class="notification-btn" onclick="location.href='notifications.php'">
                        <i class="far fa-bell"></i>
                        <?php if ($unreadNotifications > 0): ?>
                            <span class="notification-dot"></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: #fef3c7; color: var(--warning);">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?php echo $stats['pending']; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: #dbeafe; color: var(--accent);">
                            <i class="fas fa-spinner"></i>
                        </div>
                    </div>
                    <div class="stat-label">In Progress</div>
                    <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: #dcfce7; color: var(--success);">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-label">Completed</div>
                    <div class="stat-value"><?php echo $stats['completed']; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: #fee2e2; color: var(--danger);">
                            <i class="fas fa-coins"></i>
                        </div>
                    </div>
                    <div class="stat-label">Total Cost</div>
                    <div class="stat-value"><?php echo formatMoney($stats['total_cost']); ?></div>
                </div>
            </div>

            <div class="tabs">
                <div class="tab active" onclick="showTab('pending')">Pending (<?php echo $stats['pending']; ?>)</div>
                <div class="tab" onclick="showTab('progress')">In Progress (<?php echo $stats['in_progress']; ?>)</div>
                <div class="tab" onclick="showTab('completed')">Completed</div>
            </div>

            <div id="pendingTab" class="tab-content">
                <div class="repair-grid">
                    <?php foreach ($pendingRepairs as $repair): ?>
                    <div class="repair-card">
                        <div class="repair-header">
                            <span class="stall-badge"><?php echo htmlspecialchars($repair['stall_number']); ?></span>
                            <span class="status-badge pending">Pending</span>
                        </div>
                        <div class="repair-body">
                            <div class="tenant-name">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($repair['name']); ?>
                            </div>
                            <div class="repair-meta">
                                <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($repair['report_date'])); ?></span>
                                <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($repair['section']); ?></span>
                            </div>
                            <div class="issue-desc">
                                <strong>Issue:</strong><br>
                                <?php echo nl2br(htmlspecialchars($repair['issue_description'])); ?>
                            </div>
                        </div>
                        <div class="repair-footer">
                            <button class="action-btn" onclick='openUpdateModal(<?php echo $repair['id']; ?>, "<?php echo htmlspecialchars($repair['stall_number']); ?>", "<?php echo htmlspecialchars(addslashes($repair['name'])); ?>")'>
                                <i class="fas fa-check"></i> Update Status
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($pendingRepairs)): ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 50px; color: var(--gray-400);">
                        <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 16px;"></i>
                        <p>No pending repair requests</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="progressTab" class="tab-content" style="display: none;">
                <div class="repair-grid">
                    <?php foreach ($inProgressRepairs as $repair): ?>
                    <div class="repair-card">
                        <div class="repair-header">
                            <span class="stall-badge"><?php echo htmlspecialchars($repair['stall_number']); ?></span>
                            <span class="status-badge progress">In Progress</span>
                        </div>
                        <div class="repair-body">
                            <div class="tenant-name"><?php echo htmlspecialchars($repair['name']); ?></div>
                            <div class="repair-meta">
                                <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($repair['report_date'])); ?></span>
                            </div>
                            <div class="issue-desc"><?php echo nl2br(htmlspecialchars($repair['issue_description'])); ?></div>
                        </div>
                        <div class="repair-footer">
                            <button class="action-btn" onclick='openUpdateModal(<?php echo $repair['id']; ?>, "<?php echo htmlspecialchars($repair['stall_number']); ?>", "<?php echo htmlspecialchars(addslashes($repair['name'])); ?>")'>
                                <i class="fas fa-check"></i> Complete
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="completedTab" class="tab-content" style="display: none;">
                <div class="repair-grid">
                    <?php foreach ($completedRepairs as $repair): ?>
                    <div class="repair-card">
                        <div class="repair-header">
                            <span class="stall-badge"><?php echo htmlspecialchars($repair['stall_number']); ?></span>
                            <span class="status-badge completed">Completed</span>
                        </div>
                        <div class="repair-body">
                            <div class="tenant-name"><?php echo htmlspecialchars($repair['name']); ?></div>
                            <div class="issue-desc"><?php echo nl2br(htmlspecialchars($repair['issue_description'])); ?></div>
                            <div class="repair-meta">
                                <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y', strtotime($repair['completion_date'])); ?></span>
                                <span><i class="fas fa-coins"></i> <?php echo formatMoney($repair['cost']); ?></span>
                            </div>
                            <?php if (!empty($repair['admin_remarks'])): ?>
                            <div style="font-size: 12px; color: var(--gray-500); margin-top: 8px;">
                                <i class="fas fa-comment"></i> <?php echo nl2br(htmlspecialchars($repair['admin_remarks'])); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
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

    <div id="updateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Repair Request</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST">
                <input type="hidden" name="repair_id" id="repairId">
                <div style="margin-bottom: 20px;">
                    <p><strong>Stall:</strong> <span id="modalStall"></span></p>
                    <p><strong>Tenant:</strong> <span id="modalTenant"></span></p>
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Status</label>
                    <select name="status" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--gray-200);">
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Cost (₱)</label>
                    <input type="number" name="cost" step="0.01" min="0" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--gray-200);">
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Remarks</label>
                    <textarea name="remarks" rows="3" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid var(--gray-200);"></textarea>
                </div>
                <button type="submit" name="update_status" style="width: 100%; padding: 12px; background: var(--accent); color: white; border: none; border-radius: 30px; font-weight: 600; cursor: pointer;">
                    Update Request
                </button>
            </form>
        </div>
    </div>

    <script>
        function showTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
            
            if (tab === 'pending') {
                document.querySelector('.tab').classList.add('active');
                document.getElementById('pendingTab').style.display = 'block';
            } else if (tab === 'progress') {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('progressTab').style.display = 'block';
            } else {
                document.querySelectorAll('.tab')[2].classList.add('active');
                document.getElementById('completedTab').style.display = 'block';
            }
        }

        function openUpdateModal(id, stall, tenant) {
            document.getElementById('repairId').value = id;
            document.getElementById('modalStall').textContent = stall;
            document.getElementById('modalTenant').textContent = tenant;
            document.getElementById('updateModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('updateModal').classList.remove('active');
        }

        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function(e) {
            const searchTerm = this.value.toLowerCase();
            const cards = document.querySelectorAll('.repair-card');
            
            cards.forEach(card => {
                const text = card.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Notification button
        document.querySelector('.notification-btn').addEventListener('click', function() {
            window.location.href = 'notifications.php';
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('updateModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>