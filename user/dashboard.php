<?php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || !isTenant()) {
    header("Location: ../login.php");
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// Get tenant info with error checking
$stmt = $db->prepare("
    SELECT t.*, u.username, u.role 
    FROM tenants t
    JOIN users u ON t.user_id = u.id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

// If no tenant found, redirect to login
if (!$tenant) {
    header("Location: ../login.php");
    exit;
}

// Get current month payment status
$currentMonth = date('n');
$currentYear = date('Y');

$stmt = $db->prepare("
    SELECT * FROM payments 
    WHERE tenant_id = ? AND month = ? AND year = ?
");
$stmt->execute([$tenant['id'], $currentMonth, $currentYear]);
$currentPayment = $stmt->fetch(PDO::FETCH_ASSOC);

// Get payment history
$stmt = $db->prepare("
    SELECT * FROM payments 
    WHERE tenant_id = ? 
    ORDER BY year DESC, month DESC 
    LIMIT 12
");
$stmt->execute([$tenant['id']]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if overdue
$daysInMonth = date('t');
$currentDay = date('j');
$daysLeft = $daysInMonth - $currentDay;
$isOverdue = !$currentPayment && $currentDay > $daysInMonth;
$penalty = 0;

if ($isOverdue) {
    $daysOverdue = $currentDay - $daysInMonth;
    $penalty = $tenant['monthly_rent'] * 0.25;
}

// Get unread notifications
$unreadCount = getUnreadNotifications($user_id);

// Get notifications
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get repair requests
$stmt = $db->prepare("
    SELECT * FROM repairs 
    WHERE tenant_id = ? 
    ORDER BY report_date DESC 
    LIMIT 5
");
$stmt->execute([$tenant['id']]);
$repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard | MEEDO Tenant Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #0f172a;
            --primary-light: #1e293b;
            --accent: #3b82f6;
            --accent-light: #60a5fa;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #6366f1;
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
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
        }

        /* Tenant Header */
        .tenant-header {
            background: white;
            border-bottom: 1px solid var(--gray-200);
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .market-badge {
            background: var(--primary);
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
        }
        .market-badge i {
            margin-right: 6px;
            color: var(--warning);
        }
        .stall-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .stall-number {
            background: var(--accent);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .notification-icon {
            position: relative;
            width: 45px;
            height: 45px;
            background: var(--gray-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .notification-icon:hover {
            background: var(--accent);
            color: white;
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            border: 2px solid white;
        }
        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 15px 6px 6px;
            background: var(--gray-100);
            border-radius: 40px;
            cursor: pointer;
        }
        .user-avatar {
            width: 38px;
            height: 38px;
            background: var(--accent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        /* Main Content */
        .main {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        .welcome-banner::after {
            content: "🏪";
            position: absolute;
            right: 30px;
            bottom: 10px;
            font-size: 80px;
            opacity: 0.1;
        }
        .welcome-text h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .welcome-text p {
            opacity: 0.9;
            font-size: 15px;
        }
        .stall-detail {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px 25px;
            border-radius: 50px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .stall-detail i {
            margin-right: 8px;
            color: var(--warning);
        }

        /* Payment Status Card */
        .status-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
        }
        .status-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: <?php echo $currentPayment ? 'var(--success)' : ($isOverdue ? 'var(--danger)' : 'var(--warning)'); ?>;
        }
        .status-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .status-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-700);
        }
        .status-badge-large {
            padding: 8px 24px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 16px;
        }
        .status-badge-large.paid {
            background: #dcfce7;
            color: var(--success);
        }
        .status-badge-large.unpaid {
            background: #fef3c7;
            color: var(--warning);
        }
        .status-badge-large.overdue {
            background: #fee2e2;
            color: var(--danger);
        }
        .amount-display {
            display: flex;
            align-items: baseline;
            gap: 20px;
            margin: 20px 0;
        }
        .amount-label {
            color: var(--gray-500);
            font-size: 14px;
        }
        .amount-value {
            font-size: 48px;
            font-weight: 700;
            color: var(--gray-900);
        }
        .due-info {
            display: flex;
            gap: 30px;
            margin: 20px 0;
            padding: 20px 0;
            border-top: 1px solid var(--gray-200);
            border-bottom: 1px solid var(--gray-200);
        }
        .due-item {
            flex: 1;
        }
        .due-label {
            color: var(--gray-500);
            font-size: 13px;
            margin-bottom: 5px;
        }
        .due-value {
            font-size: 18px;
            font-weight: 600;
        }
        .due-value.warning { color: var(--warning); }
        .due-value.danger { color: var(--danger); }
        .due-value.success { color: var(--success); }
        .penalty-box {
            background: #fee2e2;
            border-radius: 12px;
            padding: 15px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .penalty-box i {
            font-size: 24px;
            color: var(--danger);
        }
        .penalty-text {
            flex: 1;
        }
        .penalty-text strong {
            color: var(--danger);
            font-size: 18px;
        }

        /* Action Buttons */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }
        .action-btn {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: var(--gray-700);
        }
        .action-btn:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
            box-shadow: var(--shadow-md);
        }
        .action-btn i {
            font-size: 28px;
            color: var(--accent);
            margin-bottom: 10px;
        }
        .action-btn span {
            display: block;
            font-weight: 600;
            font-size: 14px;
        }
        .action-btn small {
            display: block;
            color: var(--gray-500);
            font-size: 11px;
            margin-top: 5px;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        .card {
            background: white;
            border-radius: 20px;
            padding: 25px;
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
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-header h3 i {
            color: var(--accent);
        }
        .view-link {
            color: var(--accent);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }

        /* Payment History Table */
        .payment-table {
            width: 100%;
            border-collapse: collapse;
        }
        .payment-table th {
            text-align: left;
            padding: 12px;
            color: var(--gray-500);
            font-weight: 600;
            font-size: 12px;
            border-bottom: 1px solid var(--gray-200);
        }
        .payment-table td {
            padding: 15px 12px;
            border-bottom: 1px solid var(--gray-200);
            font-size: 14px;
        }
        .payment-status {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .payment-status.paid {
            background: #dcfce7;
            color: var(--success);
        }
        .payment-status.overdue {
            background: #fee2e2;
            color: var(--danger);
        }

        /* Notification List */
        .notification-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .notification-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: var(--gray-50);
            border-radius: 12px;
            transition: all 0.2s;
        }
        .notification-item.unread {
            background: #eff6ff;
            border-left: 3px solid var(--accent);
        }
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .notification-icon.info { background: #dbeafe; color: var(--accent); }
        .notification-icon.warning { background: #fef3c7; color: var(--warning); }
        .notification-icon.success { background: #dcfce7; color: var(--success); }
        .notification-icon.danger { background: #fee2e2; color: var(--danger); }
        .notification-content {
            flex: 1;
        }
        .notification-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 3px;
        }
        .notification-time {
            font-size: 11px;
            color: var(--gray-500);
        }

        /* Repair Requests */
        .repair-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid var(--gray-200);
        }
        .repair-item:last-child {
            border-bottom: none;
        }
        .repair-status {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
        }
        .repair-status.pending {
            background: #fef3c7;
            color: var(--warning);
        }
        .repair-status.completed {
            background: #dcfce7;
            color: var(--success);
        }
    </style>
</head>
<body>
    <div class="tenant-header">
        <div class="header-left">
            <div class="market-badge">
                <i class="fas fa-store"></i> Odiongan Public Market
            </div>
            <div class="stall-info">
                <span class="stall-number"><i class="fas fa-hashtag"></i> Stall <?php echo htmlspecialchars($tenant['stall_number']); ?></span>
            </div>
        </div>
        <div class="header-right">
            <div class="notification-icon" onclick="location.href='notifications.php'">
                <i class="far fa-bell"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="notification-badge"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </div>
            <div class="user-menu">
                <div class="user-avatar">
                    <?php echo substr($tenant['name'], 0, 1); ?>
                </div>
                <span style="font-weight: 500;"><?php echo htmlspecialchars($tenant['name']); ?></span>
                <i class="fas fa-chevron-down" style="font-size: 12px;"></i>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $tenant['name'])[0]); ?>! 👋</h1>
                <p><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></p>
            </div>
            <div class="stall-detail">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($tenant['section']); ?> · Monthly Rent: <?php echo formatMoney($tenant['monthly_rent']); ?>
            </div>
        </div>

        <!-- Payment Status Card -->
        <div class="status-card">
            <div class="status-header">
                <span class="status-title"><i class="fas fa-credit-card"></i> Current Month Payment Status (<?php echo date('F Y'); ?>)</span>
                <?php if ($currentPayment): ?>
                    <span class="status-badge-large paid"><i class="fas fa-check-circle"></i> PAID</span>
                <?php elseif ($isOverdue): ?>
                    <span class="status-badge-large overdue"><i class="fas fa-exclamation-circle"></i> OVERDUE</span>
                <?php else: ?>
                    <span class="status-badge-large unpaid"><i class="fas fa-clock"></i> UNPAID</span>
                <?php endif; ?>
            </div>

            <div class="amount-display">
                <span class="amount-label">Monthly Rent:</span>
                <span class="amount-value"><?php echo formatMoney($tenant['monthly_rent']); ?></span>
            </div>

            <div class="due-info">
                <div class="due-item">
                    <div class="due-label">Due Date</div>
                    <div class="due-value">Every <?php echo date('F t, Y'); ?></div>
                </div>
                <div class="due-item">
                    <div class="due-label">Days Left</div>
                    <?php if ($currentPayment): ?>
                        <div class="due-value success">Paid on <?php echo date('M d', strtotime($currentPayment['payment_date'])); ?></div>
                    <?php elseif ($isOverdue): ?>
                        <div class="due-value danger">Overdue by <?php echo $currentDay - $daysInMonth; ?> days</div>
                    <?php else: ?>
                        <div class="due-value warning"><?php echo $daysLeft; ?> days remaining</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isOverdue): ?>
            <div class="penalty-box">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="penalty-text">
                    <strong>25% Penalty Applied</strong>
                    <p style="font-size: 13px; margin-top: 5px;">Total amount due with penalty: <?php echo formatMoney($tenant['monthly_rent'] + $penalty); ?></p>
                </div>
                <span style="background: var(--danger); color: white; padding: 8px 16px; border-radius: 30px; font-weight: 600;">
                    +<?php echo formatMoney($penalty); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="action-grid">
            <a href="my-stall.php" class="action-btn">
                <i class="fas fa-store"></i>
                <span>My Stall</span>
                <small>View details</small>
            </a>
            <a href="payments.php" class="action-btn">
                <i class="fas fa-credit-card"></i>
                <span>Payment History</span>
                <small>View records</small>
            </a>
            <a href="request-repair.php" class="action-btn">
                <i class="fas fa-tools"></i>
                <span>Request Repair</span>
                <small>Report issue</small>
            </a>
            <a href="notifications.php" class="action-btn">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <small><?php echo $unreadCount; ?> unread</small>
            </a>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Payment History -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Payment History</h3>
                    <a href="payments.php" class="view-link">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <table class="payment-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Amount</th>
                            <th>Date Paid</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><strong><?php echo date('F', mktime(0,0,0,$payment['month'],1)); ?> <?php echo $payment['year']; ?></strong></td>
                            <td><?php echo formatMoney($payment['amount']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                            <td><span class="payment-status paid"><i class="fas fa-check-circle"></i> Paid</span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 30px; color: var(--gray-400);">
                                <i class="fas fa-receipt" style="font-size: 48px; margin-bottom: 10px;"></i>
                                <p>No payment records yet</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Notifications -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-bell"></i> Recent Notifications</h3>
                    <a href="notifications.php" class="view-link">View All <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="notification-list">
                    <?php foreach ($notifications as $notif): ?>
                    <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                        <div class="notification-icon <?php echo $notif['type']; ?>">
                            <i class="fas fa-<?php echo $notif['type'] == 'warning' ? 'exclamation' : ($notif['type'] == 'success' ? 'check' : 'info'); ?>"></i>
                        </div>
                        <div class="notification-content">
                            <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                            <div class="notification-time">
                                <i class="far fa-clock"></i> <?php echo date('M d, h:i A', strtotime($notif['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($notifications)): ?>
                    <div style="text-align: center; padding: 30px; color: var(--gray-400);">
                        <i class="fas fa-bell-slash" style="font-size: 48px; margin-bottom: 10px;"></i>
                        <p>No notifications</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Repair Requests -->
        <div class="card" style="margin-top: 20px;">
            <div class="card-header">
                <h3><i class="fas fa-tools"></i> My Repair Requests</h3>
                <a href="request-repair.php" class="view-link">New Request <i class="fas fa-plus"></i></a>
            </div>
            <?php foreach ($repairs as $repair): ?>
            <div class="repair-item">
                <div style="flex: 1;">
                    <div style="font-weight: 600; margin-bottom: 5px;"><?php echo htmlspecialchars($repair['issue_description']); ?></div>
                    <div style="font-size: 12px; color: var(--gray-500);">
                        <i class="far fa-calendar"></i> <?php echo date('M d, Y', strtotime($repair['report_date'])); ?>
                        <?php if ($repair['admin_remarks']): ?>
                            · <i class="fas fa-comment"></i> <?php echo htmlspecialchars($repair['admin_remarks']); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="repair-status <?php echo $repair['status']; ?>">
                    <?php echo ucfirst($repair['status']); ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($repairs)): ?>
            <div style="text-align: center; padding: 30px; color: var(--gray-400);">
                <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 10px;"></i>
                <p>No repair requests</p>
                <a href="request-repair.php" style="color: var(--accent); text-decoration: none; font-size: 14px;">Submit your first request →</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto refresh every 60 seconds
        setTimeout(function() {
            location.reload();
        }, 60000);
    </script>
</body>
</html>