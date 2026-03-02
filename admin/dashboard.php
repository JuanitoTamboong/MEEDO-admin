<?php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || !isAdmin()) {
    header("Location: ../login.php");
    exit;
}

$db = getDB();
checkDueDates();

// Get pending approvals count with error handling
try {
    $pendingApprovals = $db->query("SELECT COUNT(*) FROM tenants WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) {
    $pendingApprovals = 0;
}

try {
    $pendingUsers = $db->query("SELECT COUNT(*) FROM users WHERE status = 'pending' AND role = 'tenant'")->fetchColumn();
} catch (PDOException $e) {
    $pendingUsers = 0;
}

$totalPending = max($pendingApprovals, $pendingUsers);

// Get statistics with error handling
try {
    $totalTenants = $db->query("SELECT COUNT(*) FROM tenants WHERE status = 'active'")->fetchColumn();
} catch (PDOException $e) {
    // If status column doesn't exist, get all tenants
    $totalTenants = $db->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
}

try {
    $newTenants = $db->query("SELECT COUNT(*) FROM tenants WHERE created_at >= DATE('now', '-30 days') AND status = 'active'")->fetchColumn();
} catch (PDOException $e) {
    $newTenants = $db->query("SELECT COUNT(*) FROM tenants WHERE created_at >= DATE('now', '-30 days')")->fetchColumn();
}

$totalStalls = $totalTenants;

$stmt = $db->prepare("SELECT COUNT(*) FROM payments WHERE month = ? AND year = ?");
$stmt->execute([date('n'), date('Y')]);
$paidThisMonth = $stmt->fetchColumn();

$totalCollected = $db->prepare("SELECT SUM(amount) FROM payments WHERE year = ?");
$totalCollected->execute([date('Y')]);
$collected = $totalCollected->fetchColumn() ?: 0;

// Get recent tenants with error handling
try {
    $recentTenants = $db->query("
        SELECT * FROM tenants 
        WHERE status = 'active'
        ORDER BY created_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentTenants = $db->query("
        SELECT * FROM tenants 
        ORDER BY created_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Get payment data for chart
$months = [];
$payments = [];
$paymentCounts = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('n', strtotime("-$i months"));
    $year = date('Y', strtotime("-$i months"));
    $monthName = date('M', strtotime("-$i months"));
    
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as count FROM payments WHERE month = ? AND year = ?");
    $stmt->execute([$month, $year]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $months[] = $monthName;
    $payments[] = (float)$data['total'];
    $paymentCounts[] = (int)$data['count'];
}

// Get unpaid tenants with error handling
try {
    $unpaidTenants = $db->prepare("
        SELECT t.* 
        FROM tenants t
        LEFT JOIN payments p ON t.id = p.tenant_id 
            AND p.month = ? AND p.year = ?
        WHERE p.id IS NULL AND t.status = 'active'
        LIMIT 5
    ");
    $unpaidTenants->execute([date('n'), date('Y')]);
    $unpaidList = $unpaidTenants->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $unpaidTenants = $db->prepare("
        SELECT t.* 
        FROM tenants t
        LEFT JOIN payments p ON t.id = p.tenant_id 
            AND p.month = ? AND p.year = ?
        WHERE p.id IS NULL
        LIMIT 5
    ");
    $unpaidTenants->execute([date('n'), date('Y')]);
    $unpaidList = $unpaidTenants->fetchAll(PDO::FETCH_ASSOC);
}

// Get overdue tenants
$overdueList = getOverdueTenants();

// Get recent activities
$activities = [];

$paymentsData = $db->query("
    SELECT 'payment' as type, p.payment_date as date, t.name, p.amount 
    FROM payments p 
    JOIN tenants t ON p.tenant_id = t.id 
    ORDER BY p.payment_date DESC 
    LIMIT 3
")->fetchAll(PDO::FETCH_ASSOC);

try {
    $repairsData = $db->query("
        SELECT 'repair' as type, r.report_date as date, t.name, r.cost as amount 
        FROM repairs r 
        JOIN tenants t ON r.tenant_id = t.id 
        WHERE r.status = 'pending'
        ORDER BY r.report_date DESC 
        LIMIT 2
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $repairsData = [];
}

$tenantsData = $db->query("
    SELECT 'tenant' as type, t.created_at as date, t.name, t.monthly_rent as amount 
    FROM tenants t 
    ORDER BY t.created_at DESC 
    LIMIT 2
")->fetchAll(PDO::FETCH_ASSOC);

if (is_array($paymentsData)) {
    foreach ($paymentsData as $item) $activities[] = $item;
}
if (is_array($repairsData)) {
    foreach ($repairsData as $item) $activities[] = $item;
}
if (is_array($tenantsData)) {
    foreach ($tenantsData as $item) $activities[] = $item;
}

usort($activities, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$activities = array_slice($activities, 0, 7);

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEEDO | Enterprise Dashboard</title>
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

        /* Modern Scrollbar */
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

        /* Layout */
        .app {
            display: flex;
            min-height: 100vh;
        }

        /* Professional Sidebar */
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

        /* Main Content */
        .main {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
        }

        /* Top Navigation */
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

        /* Stats Grid */
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

        /* Feature Grid */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 30px;
        }

        .feature-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
            transition: all 0.2s;
        }

        .feature-card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--accent);
        }

        .feature-icon {
            width: 48px;
            height: 48px;
            background: var(--gray-50);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            font-size: 20px;
            margin-bottom: 16px;
            border: 1px solid var(--gray-200);
        }

        .feature-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .feature-desc {
            font-size: 13px;
            color: var(--gray-500);
            line-height: 1.6;
            margin-bottom: 16px;
        }

        .feature-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 12px;
            border-top: 1px solid var(--gray-200);
            font-size: 13px;
            font-weight: 500;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-900);
        }

        .card-header .badge {
            background: var(--gray-100);
            color: var(--gray-600);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid var(--gray-200);
        }

        /* Chart */
        .chart-container {
            display: flex;
            align-items: flex-end;
            gap: 16px;
            height: 220px;
            margin-top: 20px;
        }

        .chart-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .chart-bar {
            width: 100%;
            background: linear-gradient(180deg, var(--accent), var(--accent-light));
            border-radius: 8px 8px 4px 4px;
            min-height: 8px;
            transition: height 0.3s;
            box-shadow: 0 4px 8px rgba(59, 130, 246, 0.2);
        }

        .chart-value {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-700);
        }

        .chart-label {
            font-size: 12px;
            color: var(--gray-500);
            font-weight: 500;
        }

        /* Activity List */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: var(--gray-50);
            border-radius: 12px;
            transition: all 0.2s;
        }

        .activity-item:hover {
            background: white;
            box-shadow: var(--shadow-sm);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .activity-icon.payment {
            background: #e0f2fe;
            color: var(--accent);
        }

        .activity-icon.repair {
            background: #fef3c7;
            color: var(--warning);
        }

        .activity-icon.tenant {
            background: #dcfce7;
            color: var(--success);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            font-size: 14px;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .activity-time {
            font-size: 11px;
            color: var(--gray-500);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Tables */
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
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
            cursor: pointer;
            transition: all 0.2s;
        }

        .action-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        /* Overdue Row */
        .overdue-row {
            background: #fef2f2;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-top: 30px;
        }

        .quick-action {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: var(--shadow-sm);
        }

        .quick-action:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            border-color: var(--accent);
        }

        .quick-action i {
            font-size: 28px;
            color: var(--accent);
            margin-bottom: 12px;
        }

        .quick-action span {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
        }

        /* Footer */
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

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .feature-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Animations */
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
        <!-- Professional Sidebar -->
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
                    <a href="dashboard.php" class="nav-link active">
                        <i class="fas fa-chart-pie"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="stall-monitoring.php" class="nav-link">
                        <i class="fas fa-store-alt"></i>
                        <span>Stall Monitoring</span>
                        <?php if (count($unpaidList) > 0): ?>
                            <span class="badge"><?php echo count($unpaidList); ?></span>
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

        <!-- Main Content -->
        <div class="main">
            <!-- Top Navigation -->
            <div class="top-nav">
                <div class="page-title">
                    <h1>Enterprise Dashboard</h1>
                    <p>
                        <i class="far fa-calendar-alt"></i>
                        <?php echo date('l, F j, Y'); ?>
                        <i class="fas fa-circle" style="font-size: 4px; color: var(--gray-400);"></i>
                        <i class="fas fa-sync-alt"></i> Updated real-time
                    </p>
                </div>
                <div class="top-actions">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search tenants, stalls...">
                    </div>
                    <div class="notification-btn">
                        <i class="far fa-bell"></i>
                        <?php if ($unreadNotifications > 0): ?>
                            <span class="notification-dot"></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-store"></i>
                        </div>
                    </div>
                    <div class="stat-label">Total Active Stalls</div>
                    <div class="stat-value"><?php echo $totalStalls; ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-arrow-up"></i>
                        <span>+<?php echo $newTenants; ?> this month</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-label">Paid This Month</div>
                    <div class="stat-value"><?php echo $paidThisMonth; ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-percent"></i>
                        <span><?php echo $totalStalls > 0 ? round(($paidThisMonth/$totalStalls)*100, 1) : 0; ?>% collection</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-label">Pending Payments</div>
                    <div class="stat-value"><?php echo count($unpaidList); ?></div>
                    <div class="stat-trend" style="color: var(--warning);">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><?php echo count($overdueList); ?> overdue</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-coins"></i>
                        </div>
                    </div>
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-value"><?php echo formatMoney($collected); ?></div>
                    <div class="stat-trend">
                        <i class="fas fa-calendar"></i>
                        <span>FY <?php echo date('Y'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Feature Grid -->
            <div class="feature-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="feature-title">Tenant Management</div>
                    <div class="feature-desc">
                        Register and manage tenant profiles across all market sections with complete stall information.
                    </div>
                    <div class="feature-meta">
                        <span><i class="fas fa-user"></i> <?php echo $totalTenants; ?> Active</span>
                        <span><i class="fas fa-store"></i> 6 Sections</span>
                    </div>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="feature-title">Payment Monitoring</div>
                    <div class="feature-desc">
                        Track paid/unpaid stalls, automatic overdue detection, and 25% penalty computation.
                    </div>
                    <div class="feature-meta">
                        <span><i class="fas fa-check-circle" style="color: var(--success);"></i> <?php echo $paidThisMonth; ?> Paid</span>
                        <span><i class="fas fa-exclamation-circle" style="color: var(--danger);"></i> <?php echo count($overdueList); ?> Overdue</span>
                    </div>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div class="feature-title">Notifications & Repairs</div>
                    <div class="feature-desc">
                        Automated alerts for payment dues and repair request updates for tenants and admins.
                    </div>
                    <div class="feature-meta">
                        <span><i class="fas fa-tools"></i> <?php echo $pendingRepairs; ?> Pending</span>
                        <span><i class="fas fa-bell"></i> <?php echo $unreadNotifications; ?> Alerts</span>
                    </div>
                </div>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Payment Chart -->
                <div class="card">
                    <div class="card-header">
                        <h3>Revenue Analytics</h3>
                        <span class="badge">Monthly Collections</span>
                    </div>
                    <div class="chart-container">
                        <?php 
                        $maxPayment = max($payments) ?: 1;
                        foreach ($payments as $index => $payment): 
                            $height = ($payment / $maxPayment) * 150;
                            $height = max(20, $height);
                        ?>
                        <div class="chart-item">
                            <div class="chart-bar" style="height: <?php echo $height; ?>px;"></div>
                            <div class="chart-value"><?php echo formatMoney($payment); ?></div>
                            <div class="chart-label"><?php echo $months[$index]; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card">
                    <div class="card-header">
                        <h3>Activity Feed</h3>
                        <span class="badge">Live</span>
                    </div>
                    <div class="activity-list">
                        <?php foreach ($activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon <?php echo $activity['type']; ?>">
                                <i class="fas fa-<?php 
                                    echo $activity['type'] == 'payment' ? 'credit-card' : 
                                        ($activity['type'] == 'repair' ? 'tools' : 'user'); 
                                ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">
                                    <?php 
                                    if ($activity['type'] == 'payment') {
                                        echo $activity['name'] . " made a payment";
                                    } elseif ($activity['type'] == 'repair') {
                                        echo $activity['name'] . " requested repair";
                                    } else {
                                        echo "New tenant: " . $activity['name'];
                                    }
                                    ?>
                                </div>
                                <div class="activity-time">
                                    <i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($activity['date'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Tenants -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-user-friends"></i> Recent Tenants</h3>
                    <a href="stall-monitoring.php" class="view-all">
                        View Directory <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Stall</th>
                            <th>Section</th>
                            <th>Monthly Rent</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTenants as $tenant): 
                            $stmt = $db->prepare("SELECT id FROM payments WHERE tenant_id = ? AND month = ? AND year = ?");
                            $stmt->execute([$tenant['id'], date('n'), date('Y')]);
                            $isPaid = $stmt->fetch();
                        ?>
                        <tr>
                            <td>
                                <div class="tenant-cell">
                                    <div class="tenant-avatar">
                                        <?php echo substr($tenant['name'], 0, 1); ?>
                                    </div>
                                    <span><?php echo $tenant['name']; ?></span>
                                </div>
                            </td>
                            <td><span class="stall-badge"><?php echo $tenant['stall_number']; ?></span></td>
                            <td><?php echo $tenant['section']; ?></td>
                            <td><strong><?php echo formatMoney($tenant['monthly_rent']); ?></strong></td>
                            <td>
                                <span class="status-badge <?php echo $isPaid ? 'paid' : 'unpaid'; ?>">
                                    <i class="fas fa-<?php echo $isPaid ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                                    <?php echo $isPaid ? 'Paid' : 'Unpaid'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-btn" onclick="location.href='stall-monitoring.php?stall=<?php echo $tenant['stall_number']; ?>'">
                                    <i class="fas fa-eye"></i>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Payment Monitoring -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-exclamation-triangle" style="color: var(--warning);"></i> Payment Collection Status</h3>
                    <a href="stall-monitoring.php" class="view-all">
                        View All <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Stall</th>
                            <th>Amount Due</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th>Penalty (25%)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $daysInMonth = date('t');
                        $currentDay = date('j');
                        $daysLeft = $daysInMonth - $currentDay;
                        
                        foreach ($unpaidList as $tenant): 
                        ?>
                        <tr>
                            <td>
                                <div class="tenant-cell">
                                    <div class="tenant-avatar">
                                        <?php echo substr($tenant['name'], 0, 1); ?>
                                    </div>
                                    <span><?php echo $tenant['name']; ?></span>
                                </div>
                            </td>
                            <td><span class="stall-badge"><?php echo $tenant['stall_number']; ?></span></td>
                            <td><strong><?php echo formatMoney($tenant['monthly_rent']); ?></strong></td>
                            <td>
                                <?php if ($daysLeft <= 0): ?>
                                    <span style="color: var(--danger); font-weight: 600;">OVERDUE</span>
                                <?php else: ?>
                                    <?php echo $daysLeft; ?> days left
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($daysLeft <= 3 && $daysLeft > 0): ?>
                                    <span class="status-badge overdue">Urgent</span>
                                <?php elseif ($daysLeft <= 7): ?>
                                    <span class="status-badge unpaid">Due Soon</span>
                                <?php elseif ($daysLeft > 7): ?>
                                    <span class="status-badge unpaid">Unpaid</span>
                                <?php else: ?>
                                    <span class="status-badge overdue">Overdue</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($daysLeft < 0): ?>
                                    <span style="color: var(--danger); font-weight: 600;"><?php echo formatMoney($tenant['monthly_rent'] * 0.25); ?></span>
                                <?php else: ?>
                                    <span style="color: var(--gray-400);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-btn" onclick="location.href='notifications.php?remind=<?php echo $tenant['id']; ?>'">
                                    <i class="fas fa-bell"></i>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php foreach ($overdueList as $overdue): ?>
                        <tr class="overdue-row">
                            <td>
                                <div class="tenant-cell">
                                    <div class="tenant-avatar" style="background: var(--danger); color: white;">
                                        <?php echo substr($overdue['name'], 0, 1); ?>
                                    </div>
                                    <span style="font-weight: 600;"><?php echo $overdue['name']; ?></span>
                                </div>
                            </td>
                            <td><span class="stall-badge" style="background: var(--danger);"><?php echo $overdue['stall_number']; ?></span></td>
                            <td><strong style="color: var(--danger);"><?php echo formatMoney($overdue['amount_due']); ?></strong></td>
                            <td><span style="color: var(--danger);">OVERDUE</span></td>
                            <td><span class="status-badge overdue">Overdue</span></td>
                            <td><span style="color: var(--danger); font-weight: 600;"><?php echo formatMoney($overdue['penalty']); ?></span></td>
                            <td>
                                <div class="action-btn" onclick="location.href='stall-monitoring.php?stall=<?php echo $overdue['stall_number']; ?>'">
                                    <i class="fas fa-eye"></i>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <div class="quick-action" onclick="location.href='register.php'">
                    <i class="fas fa-user-plus"></i>
                    <span>Add Tenant</span>
                </div>
                <div class="quick-action" onclick="location.href='stall-monitoring.php'">
                    <i class="fas fa-credit-card"></i>
                    <span>Record Payment</span>
                </div>
                <div class="quick-action" onclick="location.href='reports.php'">
                    <i class="fas fa-file-invoice"></i>
                    <span>Generate Report</span>
                </div>
                <div class="quick-action" onclick="location.href='repairs.php'">
                    <i class="fas fa-tools"></i>
                    <span>Manage Repairs</span>
                </div>
            </div>

            <!-- Footer -->
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
        // Professional interactions
        document.querySelector('.search-wrapper input').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                console.log('Search initiated:', this.value);
            }
        });

        document.querySelector('.notification-btn').addEventListener('click', function() {
            window.location.href = 'notifications.php';
        });

        // Smooth scroll behavior
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function(e) {
                if (this.classList.contains('active')) return;
                // Handle navigation
            });
        });
    </script>
</body>
</html>