<?php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || !isAdmin()) {
    header("Location: ../login.php");
    exit;
}

$db = getDB();

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

// Get all tenants with payment status
$tenants = $db->query("
    SELECT t.*, 
           CASE WHEN p.id IS NOT NULL THEN 'Paid' ELSE 'Unpaid' END as payment_status,
           p.payment_date,
           t.monthly_rent as amount
    FROM tenants t
    LEFT JOIN payments p ON t.id = p.tenant_id 
        AND p.month = " . date('n') . " 
        AND p.year = " . date('Y') . "
    WHERE t.status = 'active'
    ORDER BY t.stall_number
")->fetchAll(PDO::FETCH_ASSOC);

// Get overdue tenants
$overdueList = getOverdueTenants();

// Get sections for filter
$sections = $db->query("SELECT DISTINCT section FROM tenants WHERE status = 'active' ORDER BY section")->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$totalStalls = count($tenants);
$paidCount = count(array_filter($tenants, fn($t) => $t['payment_status'] == 'Paid'));
$unpaidCount = $totalStalls - $paidCount;
$overdueCount = count($overdueList);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stall Monitoring | MEEDO</title>
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

        .filter-wrapper {
            display: flex;
            align-items: center;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 30px;
            padding: 10px 20px;
            gap: 10px;
        }

        .filter-wrapper i {
            color: var(--gray-400);
        }

        .filter-wrapper select {
            border: none;
            background: transparent;
            outline: none;
            font-size: 14px;
            color: var(--gray-700);
            font-weight: 500;
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

        .stat-card.paid .stat-value { color: var(--success); }
        .stat-card.unpaid .stat-value { color: var(--warning); }
        .stat-card.overdue .stat-value { color: var(--danger); }

        .table-container {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .table-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-header h3 i {
            color: var(--accent);
        }

        .view-all {
            color: var(--accent);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: var(--gray-50);
            border-radius: 30px;
            border: 1px solid var(--gray-200);
            transition: all 0.2s;
        }

        .view-all:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 14px 12px;
            color: var(--gray-500);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--gray-200);
        }

        td {
            padding: 16px 12px;
            border-bottom: 1px solid var(--gray-200);
            font-size: 14px;
            color: var(--gray-700);
        }

        .tenant-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .tenant-avatar {
            width: 40px;
            height: 40px;
            background: var(--gray-100);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 14px;
        }

        .stall-badge {
            background: var(--primary);
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-badge.paid {
            background: #dcfce7;
            color: var(--success);
        }

        .status-badge.unpaid {
            background: #fef3c7;
            color: var(--warning);
        }

        .status-badge.overdue {
            background: #fee2e2;
            color: var(--danger);
        }

        .action-btn {
            width: 36px;
            height: 36px;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
            cursor: pointer;
            transition: all 0.2s;
            margin-right: 8px;
        }

        .action-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .overdue-row {
            background: #fef2f2;
        }

        .penalty-amount {
            color: var(--danger);
            font-weight: 600;
        }

        .export-btn {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            color: var(--gray-700);
            cursor: pointer;
            transition: all 0.2s;
        }

        .export-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .export-btn:hover i { color: white; }
        .export-btn i { color: var(--accent); }

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

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
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

        .stat-card, .table-container {
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
                    <a href="stall-monitoring.php" class="nav-link active">
                        <i class="fas fa-store-alt"></i>
                        <span>Stall Monitoring</span>
                        <?php if ($unpaidCount > 0): ?>
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
                    <h1>Stall Monitoring</h1>
                    <p>
                        <i class="far fa-calendar-alt"></i>
                        <?php echo date('l, F j, Y'); ?>
                        <i class="fas fa-circle" style="font-size: 4px; color: var(--gray-400);"></i>
                        <i class="fas fa-store-alt"></i> Real-time stall updates
                    </p>
                </div>
                <div class="top-actions">
                    <div class="filter-wrapper">
                        <i class="fas fa-filter"></i>
                        <select id="sectionFilter">
                            <option value="all">All Sections</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?php echo $section['section']; ?>"><?php echo $section['section']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="notification-btn" onclick="location.href='notifications.php'">
                        <i class="far fa-bell"></i>
                        <?php if ($unreadNotifications > 0): ?>
                            <span class="notification-dot"></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

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

                <div class="stat-card paid">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-label">Paid</div>
                    <div class="stat-value"><?php echo $paidCount; ?></div>
                </div>

                <div class="stat-card unpaid">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-label">Unpaid</div>
                    <div class="stat-value"><?php echo $unpaidCount; ?></div>
                </div>

                <div class="stat-card overdue">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="stat-label">Overdue (25% Penalty)</div>
                    <div class="stat-value"><?php echo $overdueCount; ?></div>
                </div>
            </div>

            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-store"></i> All Stalls</h3>
                    <div class="export-btn" onclick="exportTable()">
                        <i class="fas fa-download"></i>
                        <span>Export</span>
                    </div>
                </div>
                <table id="stallsTable">
                    <thead>
                        <tr>
                            <th>Stall #</th>
                            <th>Tenant</th>
                            <th>Section</th>
                            <th>Contact</th>
                            <th>Monthly Rent</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tenants as $tenant): ?>
                        <tr data-section="<?php echo $tenant['section']; ?>">
                            <td><span class="stall-badge"><?php echo $tenant['stall_number']; ?></span></td>
                            <td><?php echo $tenant['name']; ?></td>
                            <td><?php echo $tenant['section']; ?></td>
                            <td><?php echo $tenant['contact'] ?: '—'; ?></td>
                            <td><strong><?php echo formatMoney($tenant['amount']); ?></strong></td>
                            <td>
                                <span class="status-badge <?php echo strtolower($tenant['payment_status']); ?>">
                                    <i class="fas fa-<?php echo $tenant['payment_status'] == 'Paid' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                                    <?php echo $tenant['payment_status']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-btn" onclick="viewTenant('<?php echo $tenant['stall_number']; ?>')">
                                    <i class="fas fa-eye"></i>
                                </div>
                                <div class="action-btn" onclick="remindTenant(<?php echo $tenant['id']; ?>)">
                                    <i class="fas fa-bell"></i>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($overdueList)): ?>
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> Overdue Accounts (25% Penalty Applied)</h3>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Stall #</th>
                            <th>Tenant</th>
                            <th>Section</th>
                            <th>Amount Due</th>
                            <th>Days Overdue</th>
                            <th>Penalty (25%)</th>
                            <th>Total Due</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($overdueList as $overdue): ?>
                        <tr class="overdue-row">
                            <td><span class="stall-badge" style="background: var(--danger);"><?php echo $overdue['stall_number']; ?></span></td>
                            <td><strong><?php echo $overdue['name']; ?></strong></td>
                            <td><?php echo $overdue['section']; ?></td>
                            <td><?php echo formatMoney($overdue['amount_due']); ?></td>
                            <td><?php echo $overdue['days_overdue']; ?> days</td>
                            <td class="penalty-amount"><?php echo formatMoney($overdue['penalty']); ?></td>
                            <td><strong style="color: var(--danger);"><?php echo formatMoney($overdue['total_due']); ?></strong></td>
                            <td>
                                <div class="action-btn" onclick="viewTenant('<?php echo $overdue['stall_number']; ?>')">
                                    <i class="fas fa-eye"></i>
                                </div>
                                <div class="action-btn" onclick="sendNotice(<?php echo $overdue['id']; ?>)">
                                    <i class="fas fa-envelope"></i>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

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
        document.getElementById('sectionFilter').addEventListener('change', function() {
            const section = this.value;
            const rows = document.querySelectorAll('#stallsTable tbody tr');
            
            rows.forEach(row => {
                if (section === 'all' || row.dataset.section === section) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        function viewTenant(stallNumber) {
            window.location.href = `stall-details.php?stall=${stallNumber}`;
        }

        function remindTenant(tenantId) {
            if (confirm('Send payment reminder to this tenant?')) {
                window.location.href = `notifications.php?remind=${tenantId}`;
            }
        }

        function sendNotice(tenantId) {
            window.location.href = `notifications.php?send=${tenantId}`;
        }

        function exportTable() {
            const rows = document.querySelectorAll('#stallsTable tr');
            const csv = [];
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td, th');
                const rowData = [];
                cells.forEach(cell => {
                    let text = cell.textContent.trim();
                    text = text.replace(/\s+/g, ' ').replace(/[₱,]/g, '');
                    rowData.push('"' + text + '"');
                });
                csv.push(rowData.join(','));
            });
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'stall-monitoring-<?php echo date('Y-m-d'); ?>.csv';
            a.click();
        }

        document.querySelector('.notification-btn').addEventListener('click', function() {
            window.location.href = 'notifications.php';
        });
    </script>
</body>
</html>