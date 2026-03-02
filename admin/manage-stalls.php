<?php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || !isAdmin()) {
    header("Location: ../login.php");
    exit;
}

$db = getDB();
$error = '';
$success = '';

// Get counts for badges (same as stall monitoring)
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

// Handle add section
if (isset($_POST['add_section'])) {
    $name = trim($_POST['section_name']);
    $icon = trim($_POST['section_icon']);
    $display_order = intval($_POST['display_order']);
    
    if (!empty($name)) {
        $stmt = $db->prepare("INSERT INTO sections (name, icon, display_order) VALUES (?, ?, ?)");
        if ($stmt->execute([$name, $icon, $display_order])) {
            $success = "Section added successfully";
        } else {
            $error = "Failed to add section";
        }
    }
}

// Handle add stalls
if (isset($_POST['add_stalls'])) {
    $section = trim($_POST['section']);
    $prefix = trim($_POST['prefix']);
    $start = intval($_POST['start_number']);
    $end = intval($_POST['end_number']);
    $base_rent = floatval($_POST['base_rent']);
    
    if (!empty($section) && !empty($prefix) && $start > 0 && $end >= $start) {
        $db->beginTransaction();
        try {
            for ($i = $start; $i <= $end; $i++) {
                $stallNumber = $prefix . '-' . str_pad($i, 3, '0', STR_PAD_LEFT);
                $stmt = $db->prepare("INSERT INTO stalls (stall_number, section, monthly_rent, status) VALUES (?, ?, ?, 'available')");
                $stmt->execute([$stallNumber, $section, $base_rent]);
            }
            $db->commit();
            $success = "Stalls added successfully";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to add stalls: " . $e->getMessage();
        }
    }
}

// Handle delete stall
if (isset($_GET['delete_stall'])) {
    $stall_id = intval($_GET['delete_stall']);
    
    // Check if stall is occupied
    $stmt = $db->prepare("SELECT id FROM tenants WHERE stall_id = ? AND status IN ('active', 'pending')");
    $stmt->execute([$stall_id]);
    if ($stmt->fetch()) {
        $error = "Cannot delete occupied stall";
    } else {
        $stmt = $db->prepare("DELETE FROM stalls WHERE id = ?");
        if ($stmt->execute([$stall_id])) {
            $success = "Stall deleted successfully";
        }
    }
}

// Handle toggle stall status
if (isset($_GET['toggle_status'])) {
    $stall_id = intval($_GET['toggle_status']);
    $stmt = $db->prepare("UPDATE stalls SET status = CASE WHEN status = 'available' THEN 'maintenance' ELSE 'available' END WHERE id = ?");
    $stmt->execute([$stall_id]);
    $success = "Stall status updated";
}

// Get all sections
$sections = $db->query("SELECT * FROM sections ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);

// Get all stalls with tenant info
$stalls = $db->query("
    SELECT s.*, 
           t.name as tenant_name, 
           t.status as tenant_status,
           t.id as tenant_id
    FROM stalls s
    LEFT JOIN tenants t ON s.id = t.stall_id AND t.status IN ('active', 'pending')
    ORDER BY s.section, s.stall_number
")->fetchAll(PDO::FETCH_ASSOC);

// Group stalls by section
$stallsBySection = [];
foreach ($stalls as $stall) {
    $stallsBySection[$stall['section']][] = $stall;
}

// Statistics
$totalStalls = count($stalls);
$availableStalls = count(array_filter($stalls, fn($s) => !$s['tenant_id'] && $s['status'] == 'available'));
$occupiedStalls = count(array_filter($stalls, fn($s) => $s['tenant_id']));
$maintenanceStalls = count(array_filter($stalls, fn($s) => !$s['tenant_id'] && $s['status'] == 'maintenance'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Stalls | MEEDO</title>
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
            background: var(--gray-50);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
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

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--success);
            font-weight: 500;
        }

        .stat-trend i {
            font-size: 12px;
        }

        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-header h2 i {
            color: var(--accent);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .form-group {
            margin-bottom: 16px;
        }

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
            padding: 10px 14px;
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* Stall Grid */
        .stall-section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title i {
            color: var(--accent);
        }

        .stall-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
        }

        .stall-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 14px;
            padding: 16px;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }

        .stall-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--accent);
        }

        .stall-card.available {
            border-left: 4px solid var(--success);
        }

        .stall-card.occupied {
            border-left: 4px solid var(--danger);
            background: #fef2f2;
        }

        .stall-card.maintenance {
            border-left: 4px solid var(--warning);
            background: #fffbeb;
        }

        .stall-card.pending {
            border-left: 4px solid var(--accent);
            background: #eff6ff;
        }

        .stall-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .stall-number {
            font-weight: 700;
            font-size: 16px;
            color: var(--gray-900);
        }

        .stall-status {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        .stall-status.available {
            background: #dcfce7;
            color: var(--success);
        }

        .stall-status.occupied {
            background: #fee2e2;
            color: var(--danger);
        }

        .stall-status.maintenance {
            background: #fef3c7;
            color: var(--warning);
        }

        .stall-status.pending {
            background: #dbeafe;
            color: var(--accent);
        }

        .stall-info {
            font-size: 13px;
            color: var(--gray-600);
            margin: 12px 0;
            min-height: 40px;
        }

        .stall-info i {
            width: 16px;
            color: var(--gray-400);
        }

        .stall-rent {
            font-weight: 700;
            color: var(--accent);
            font-size: 16px;
            margin: 12px 0;
            padding-top: 8px;
            border-top: 1px dashed var(--gray-200);
        }

        .stall-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        .stall-action {
            flex: 1;
            padding: 8px;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            background: white;
            color: var(--gray-600);
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            text-decoration: none;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .stall-action:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .stall-action.delete:hover {
            background: var(--danger);
            border-color: var(--danger);
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
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

        .stat-card, .card, .stall-card {
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
                        <?php 
                        $unpaidCount = $db->query("SELECT COUNT(*) FROM tenants t LEFT JOIN payments p ON t.id = p.tenant_id AND p.month = " . date('n') . " AND p.year = " . date('Y') . " WHERE p.id IS NULL AND t.status = 'active'")->fetchColumn();
                        if ($unpaidCount > 0): ?>
                            <span class="badge"><?php echo $unpaidCount; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="reports.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Financial Reports</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="manage-stalls.php" class="nav-link active">
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
                    <h1>Manage Stalls</h1>
                    <p>
                        <i class="far fa-calendar-alt"></i>
                        <?php echo date('l, F j, Y'); ?>
                        <i class="fas fa-circle" style="font-size: 4px; color: var(--gray-400);"></i>
                        <i class="fas fa-cogs"></i> Configure market sections and stalls
                    </p>
                </div>
                <div class="top-actions">
                    <div class="notification-btn" onclick="location.href='notifications.php'">
                        <i class="far fa-bell"></i>
                        <?php if ($unreadNotifications > 0): ?>
                            <span class="notification-dot"></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-store"></i>
                        </div>
                    </div>
                    <div class="stat-label">Total Stalls</div>
                    <div class="stat-value"><?php echo $totalStalls; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="color: var(--success);">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-label">Available</div>
                    <div class="stat-value"><?php echo $availableStalls; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="color: var(--danger);">
                            <i class="fas fa-lock"></i>
                        </div>
                    </div>
                    <div class="stat-label">Occupied</div>
                    <div class="stat-value"><?php echo $occupiedStalls; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="color: var(--warning);">
                            <i class="fas fa-tools"></i>
                        </div>
                    </div>
                    <div class="stat-label">Maintenance</div>
                    <div class="stat-value"><?php echo $maintenanceStalls; ?></div>
                </div>
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

            <!-- Add Section Form -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-layer-group"></i> Add New Section</h2>
                </div>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Section Name</label>
                            <input type="text" name="section_name" placeholder="e.g., Meat Section" required>
                        </div>
                        <div class="form-group">
                            <label>Icon Class</label>
                            <input type="text" name="section_icon" placeholder="e.g., drumstick-bite" value="store">
                        </div>
                        <div class="form-group">
                            <label>Display Order</label>
                            <input type="number" name="display_order" value="0" required>
                        </div>
                        <div class="form-group" style="display: flex; align-items: flex-end;">
                            <button type="submit" name="add_section" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add Section
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Add Stalls Form -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-plus-circle"></i> Add Multiple Stalls</h2>
                </div>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Section</label>
                            <select name="section" required>
                                <option value="">Select Section</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?php echo $section['name']; ?>">
                                        <?php echo $section['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Stall Prefix</label>
                            <input type="text" name="prefix" placeholder="e.g., M" required>
                        </div>
                        <div class="form-group">
                            <label>Start Number</label>
                            <input type="number" name="start_number" min="1" value="1" required>
                        </div>
                        <div class="form-group">
                            <label>End Number</label>
                            <input type="number" name="end_number" min="1" value="10" required>
                        </div>
                        <div class="form-group">
                            <label>Base Monthly Rent (₱)</label>
                            <input type="number" name="base_rent" step="0.01" value="1000" required>
                        </div>
                        <div class="form-group" style="display: flex; align-items: flex-end;">
                            <button type="submit" name="add_stalls" class="btn btn-primary">
                                <i class="fas fa-store"></i> Generate Stalls
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Stalls Display -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-store-alt"></i> Current Stalls</h2>
                </div>
                
                <?php foreach ($stallsBySection as $section => $sectionStalls): 
                    $available = count(array_filter($sectionStalls, fn($s) => !$s['tenant_id'] && $s['status'] == 'available'));
                    $occupied = count(array_filter($sectionStalls, fn($s) => $s['tenant_id']));
                    $maintenance = count(array_filter($sectionStalls, fn($s) => !$s['tenant_id'] && $s['status'] == 'maintenance'));
                ?>
                    <div class="stall-section">
                        <h3 class="section-title">
                            <i class="fas fa-<?php 
                                // Try to get icon from sections table
                                $sectionIcon = 'store';
                                foreach ($sections as $sec) {
                                    if ($sec['name'] == $section) {
                                        $sectionIcon = $sec['icon'];
                                        break;
                                    }
                                }
                                echo $sectionIcon; 
                            ?>"></i>
                            <?php echo $section; ?>
                            <span style="font-size: 14px; color: var(--gray-500); margin-left: 10px; font-weight: normal;">
                                (<?php echo count($sectionStalls); ?> total · 
                                <span style="color: var(--success);"><?php echo $available; ?> available</span> · 
                                <span style="color: var(--danger);"><?php echo $occupied; ?> occupied</span> · 
                                <span style="color: var(--warning);"><?php echo $maintenance; ?> maintenance</span>)
                            </span>
                        </h3>
                        
                        <div class="stall-grid">
                            <?php foreach ($sectionStalls as $stall): 
                                $status = $stall['tenant_id'] ? 'occupied' : $stall['status'];
                                $tenantInfo = $stall['tenant_name'] ? $stall['tenant_name'] . ' (' . $stall['tenant_status'] . ')' : 'No tenant';
                            ?>
                                <div class="stall-card <?php echo $status; ?>">
                                    <div class="stall-header">
                                        <span class="stall-number"><?php echo $stall['stall_number']; ?></span>
                                        <span class="stall-status <?php echo $status; ?>">
                                            <?php 
                                            if ($status == 'occupied') echo 'Occupied';
                                            else if ($status == 'available') echo 'Available';
                                            else if ($status == 'maintenance') echo 'Maintenance';
                                            else if ($status == 'pending') echo 'Pending';
                                            ?>
                                        </span>
                                    </div>
                                    
                                    <div class="stall-info">
                                        <?php if ($stall['tenant_name']): ?>
                                            <i class="fas fa-user"></i> <?php echo $stall['tenant_name']; ?><br>
                                            <small style="color: var(--gray-400);"><?php echo ucfirst($stall['tenant_status']); ?></small>
                                        <?php else: ?>
                                            <i class="fas fa-check-circle" style="color: var(--success);"></i> No tenant assigned
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="stall-rent">
                                        ₱<?php echo number_format($stall['monthly_rent'], 2); ?><span style="font-size: 12px; color: var(--gray-400);">/month</span>
                                    </div>
                                    
                                    <div class="stall-actions">
                                        <?php if (!$stall['tenant_id']): ?>
                                            <a href="?toggle_status=<?php echo $stall['id']; ?>" class="stall-action">
                                                <i class="fas fa-<?php echo $stall['status'] == 'maintenance' ? 'check' : 'tools'; ?>"></i>
                                                <?php echo $stall['status'] == 'maintenance' ? 'Set Available' : 'Maintenance'; ?>
                                            </a>
                                            <a href="?delete_stall=<?php echo $stall['id']; ?>" 
                                               class="stall-action delete" 
                                               onclick="return confirm('Are you sure you want to delete stall <?php echo $stall['stall_number']; ?>? This action cannot be undone.')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        <?php else: ?>
                                            <span class="stall-action" style="opacity: 0.5; cursor: not-allowed; background: var(--gray-100);">
                                                <i class="fas fa-lock"></i> Occupied
                                            </span>
                                            <a href="stall-details.php?stall=<?php echo $stall['stall_number']; ?>" class="stall-action">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($stalls)): ?>
                    <div style="text-align: center; padding: 60px 20px; color: var(--gray-400);">
                        <i class="fas fa-store-slash" style="font-size: 64px; margin-bottom: 20px;"></i>
                        <h3 style="color: var(--gray-600);">No Stalls Added Yet</h3>
                        <p style="margin-top: 10px;">Use the forms above to add sections and stalls.</p>
                    </div>
                <?php endif; ?>
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
        document.querySelector('.notification-btn').addEventListener('click', function() {
            window.location.href = 'notifications.php';
        });

        // Add animation to cards
        const cards = document.querySelectorAll('.stall-card');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.05}s`;
        });
    </script>
</body>
</html>