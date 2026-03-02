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

$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? '';
$reportType = $_GET['type'] ?? 'monthly';

// Get all available years
$years = $db->query("SELECT DISTINCT year FROM payments ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);

// Monthly report data
$monthlyData = [];
if ($month) {
    $stmt = $db->prepare("
        SELECT p.*, t.name, t.stall_number, t.section
        FROM payments p
        JOIN tenants t ON p.tenant_id = t.id
        WHERE p.month = ? AND p.year = ?
        ORDER BY p.payment_date DESC
    ");
    $stmt->execute([$month, $year]);
    $monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalMonthly = array_sum(array_column($monthlyData, 'amount'));
}

// Annual report data
$annualData = $db->prepare("
    SELECT 
        month,
        COUNT(*) as payment_count,
        SUM(amount) as total_amount,
        COUNT(DISTINCT tenant_id) as tenant_count
    FROM payments
    WHERE year = ?
    GROUP BY month
    ORDER BY month
");
$annualData->execute([$year]);
$annualStats = $annualData->fetchAll(PDO::FETCH_ASSOC);

$annualTotal = array_sum(array_column($annualStats, 'total_amount'));
$totalPayments = array_sum(array_column($annualStats, 'payment_count'));

// Overdue report
$overdueList = getOverdueTenants();

// Collection efficiency
$totalTenants = $db->query("SELECT COUNT(*) FROM tenants WHERE status = 'active'")->fetchColumn();
$collectionRate = $totalTenants > 0 ? round(($totalPayments / ($totalTenants * 12)) * 100, 2) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Reports | MEEDO</title>
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

        .report-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 30px;
        }

        .tab-btn {
            padding: 12px 24px;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            color: var(--gray-600);
            cursor: pointer;
            transition: all 0.2s;
        }

        .tab-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .tab-btn i { margin-right: 8px; }

        .filter-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--gray-200);
            margin-bottom: 30px;
        }

        .filter-grid {
            display: flex;
            gap: 20px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
        }

        .filter-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-500);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group select, .filter-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            font-size: 14px;
            color: var(--gray-700);
            background: var(--gray-50);
            outline: none;
            transition: all 0.2s;
        }

        .filter-group select:focus, .filter-group input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .generate-btn {
            padding: 12px 30px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            height: 48px;
        }

        .generate-btn:hover {
            background: var(--primary);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid var(--gray-200);
        }

        .summary-label {
            color: var(--gray-500);
            font-size: 13px;
            margin-bottom: 8px;
        }

        .summary-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .summary-desc {
            font-size: 12px;
            color: var(--success);
            margin-top: 8px;
        }

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

        .table-header h3 i { color: var(--accent); }
        
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

        .stall-badge {
            background: var(--primary);
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .total-row {
            background: var(--gray-50);
            font-weight: 700;
        }

        .export-actions {
            display: flex;
            gap: 12px;
        }

        .export-btn {
            padding: 10px 16px;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 8px;
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

        .stat-card, .feature-card, .card, .table-container {
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
                    <a href="reports.php" class="nav-link active">
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
                    <h1>Financial Reports</h1>
                    <p>
                        <i class="far fa-calendar-alt"></i>
                        <?php echo date('l, F j, Y'); ?>
                        <i class="fas fa-circle" style="font-size: 4px; color: var(--gray-400);"></i>
                        <i class="fas fa-chart-line"></i> Collection Analytics
                    </p>
                </div>
                <div class="top-actions">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search reports...">
                    </div>
                    <div class="notification-btn" onclick="location.href='notifications.php'">
                        <i class="far fa-bell"></i>
                        <?php if ($unreadNotifications > 0): ?>
                            <span class="notification-dot"></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="report-tabs">
                <button class="tab-btn <?php echo $reportType == 'monthly' ? 'active' : ''; ?>" onclick="switchTab('monthly')">
                    <i class="fas fa-calendar-alt"></i> Monthly Report
                </button>
                <button class="tab-btn <?php echo $reportType == 'annual' ? 'active' : ''; ?>" onclick="switchTab('annual')">
                    <i class="fas fa-chart-bar"></i> Annual Report
                </button>
                <button class="tab-btn <?php echo $reportType == 'overdue' ? 'active' : ''; ?>" onclick="switchTab('overdue')">
                    <i class="fas fa-exclamation-triangle"></i> Overdue Report
                </button>
            </div>

            <div class="filter-section">
                <form method="GET" id="reportForm">
                    <input type="hidden" name="type" id="reportType" value="<?php echo $reportType; ?>">
                    <div class="filter-grid">
                        <?php if ($reportType == 'monthly'): ?>
                            <div class="filter-group">
                                <label>Month</label>
                                <select name="month">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Year</label>
                                <select name="year">
                                    <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        <?php elseif ($reportType == 'annual'): ?>
                            <div class="filter-group">
                                <label>Year</label>
                                <select name="year">
                                    <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="generate-btn">
                            <i class="fas fa-sync-alt"></i> Generate Report
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($reportType == 'monthly' && $month): ?>
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Total Collections</div>
                        <div class="summary-value"><?php echo formatMoney($totalMonthly); ?></div>
                        <div class="summary-desc"><?php echo date('F', mktime(0,0,0,$month,1)); ?> <?php echo $year; ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Number of Payments</div>
                        <div class="summary-value"><?php echo count($monthlyData); ?></div>
                        <div class="summary-desc">Transactions processed</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Average Payment</div>
                        <div class="summary-value"><?php echo count($monthlyData) > 0 ? formatMoney($totalMonthly / count($monthlyData)) : '₱0.00'; ?></div>
                        <div class="summary-desc">Per transaction</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Collection Rate</div>
                        <div class="summary-value"><?php echo $totalTenants > 0 ? round((count($monthlyData) / $totalTenants) * 100, 1) : 0; ?>%</div>
                        <div class="summary-desc">Of total tenants</div>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-file-invoice"></i> Monthly Collection Details</h3>
                        <div class="export-actions">
                            <div class="export-btn" onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> PDF</div>
                            <div class="export-btn" onclick="exportToCSV()"><i class="fas fa-file-csv"></i> CSV</div>
                            <div class="export-btn" onclick="printReport()"><i class="fas fa-print"></i> Print</div>
                        </div>
                    </div>
                    <table id="reportTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Stall #</th>
                                <th>Tenant</th>
                                <th>Section</th>
                                <th>Amount</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthlyData as $payment): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                <td><span class="stall-badge"><?php echo $payment['stall_number']; ?></span></td>
                                <td><?php echo $payment['name']; ?></td>
                                <td><?php echo $payment['section']; ?></td>
                                <td><strong><?php echo formatMoney($payment['amount']); ?></strong></td>
                                <td><?php echo $payment['reference_no'] ?? '—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="4" style="text-align: right;"><strong>Total:</strong></td>
                                <td><strong><?php echo formatMoney($totalMonthly); ?></strong></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

            <?php elseif ($reportType == 'annual'): ?>
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Annual Total</div>
                        <div class="summary-value"><?php echo formatMoney($annualTotal); ?></div>
                        <div class="summary-desc">Year <?php echo $year; ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Total Transactions</div>
                        <div class="summary-value"><?php echo $totalPayments; ?></div>
                        <div class="summary-desc">Payments processed</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Monthly Average</div>
                        <div class="summary-value"><?php echo count($annualStats) > 0 ? formatMoney($annualTotal / count($annualStats)) : '₱0.00'; ?></div>
                        <div class="summary-desc">Per month</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Collection Efficiency</div>
                        <div class="summary-value"><?php echo $collectionRate; ?>%</div>
                        <div class="summary-desc">Annual collection rate</div>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-chart-line"></i> Monthly Summary <?php echo $year; ?></h3>
                        <div class="export-actions">
                            <div class="export-btn" onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> PDF</div>
                            <div class="export-btn" onclick="exportToCSV()"><i class="fas fa-file-csv"></i> CSV</div>
                        </div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Tenants Paid</th>
                                <th>Transactions</th>
                                <th>Total Collection</th>
                                <th>% of Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $months = ['January', 'February', 'March', 'April', 'May', 'June', 
                                      'July', 'August', 'September', 'October', 'November', 'December'];
                            foreach ($annualStats as $stat): 
                                $percentage = $annualTotal > 0 ? round(($stat['total_amount'] / $annualTotal) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><strong><?php echo $months[$stat['month'] - 1]; ?></strong></td>
                                <td><?php echo $stat['tenant_count']; ?> tenants</td>
                                <td><?php echo $stat['payment_count']; ?> payments</td>
                                <td><strong><?php echo formatMoney($stat['total_amount']); ?></strong></td>
                                <td><?php echo $percentage; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="3" style="text-align: right;"><strong>Annual Total:</strong></td>
                                <td><strong><?php echo formatMoney($annualTotal); ?></strong></td>
                                <td>100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

            <?php elseif ($reportType == 'overdue'): ?>
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Overdue Accounts</div>
                        <div class="summary-value"><?php echo count($overdueList); ?></div>
                        <div class="summary-desc">Tenants with overdue payments</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Total Amount Due</div>
                        <div class="summary-value"><?php echo formatMoney(array_sum(array_column($overdueList, 'amount_due'))); ?></div>
                        <div class="summary-desc">Base rental amount</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Total Penalties</div>
                        <div class="summary-value"><?php echo formatMoney(array_sum(array_column($overdueList, 'penalty'))); ?></div>
                        <div class="summary-desc">25% penalty applied</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Total Receivable</div>
                        <div class="summary-value"><?php echo formatMoney(array_sum(array_column($overdueList, 'total_due'))); ?></div>
                        <div class="summary-desc">Including penalties</div>
                    </div>
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> Overdue Accounts with 25% Penalty</h3>
                        <div class="export-actions">
                            <div class="export-btn" onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> PDF</div>
                            <div class="export-btn" onclick="exportToCSV()"><i class="fas fa-file-csv"></i> CSV</div>
                        </div>
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overdueList as $overdue): ?>
                            <tr>
                                <td><span class="stall-badge" style="background: var(--danger);"><?php echo $overdue['stall_number']; ?></span></td>
                                <td><?php echo $overdue['name']; ?></td>
                                <td><?php echo $overdue['section']; ?></td>
                                <td><?php echo formatMoney($overdue['amount_due']); ?></td>
                                <td><?php echo $overdue['days_overdue']; ?> days</td>
                                <td style="color: var(--danger); font-weight: 600;"><?php echo formatMoney($overdue['penalty']); ?></td>
                                <td><strong style="color: var(--danger);"><?php echo formatMoney($overdue['total_due']); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="3" style="text-align: right;"><strong>Totals:</strong></td>
                                <td><strong><?php echo formatMoney(array_sum(array_column($overdueList, 'amount_due'))); ?></strong></td>
                                <td></td>
                                <td><strong style="color: var(--danger);"><?php echo formatMoney(array_sum(array_column($overdueList, 'penalty'))); ?></strong></td>
                                <td><strong style="color: var(--danger);"><?php echo formatMoney(array_sum(array_column($overdueList, 'total_due'))); ?></strong></td>
                            </tr>
                        </tfoot>
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
        function switchTab(type) {
            document.getElementById('reportType').value = type;
            document.getElementById('reportForm').submit();
        }

        function exportToPDF() {
            window.print();
        }

        function exportToCSV() {
            const rows = document.querySelectorAll('#reportTable tr');
            const csv = [];
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td, th');
                const rowData = [];
                cells.forEach(cell => {
                    let text = cell.textContent.trim().replace(/[₱,]/g, '');
                    rowData.push('"' + text + '"');
                });
                csv.push(rowData.join(','));
            });
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'report-<?php echo date('Y-m-d'); ?>.csv';
            a.click();
        }

        function printReport() {
            window.print();
        }

        document.querySelector('.search-wrapper input').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                // Implement search functionality if needed
                console.log('Searching:', this.value);
            }
        });

        document.querySelector('.notification-btn').addEventListener('click', function() {
            window.location.href = 'notifications.php';
        });
    </script>
</body>
</html>