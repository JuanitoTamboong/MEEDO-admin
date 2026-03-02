<?php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || !isTenant()) {
    header("Location: ../login.php");
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// Mark as read
if (isset($_GET['mark_read'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$_GET['mark_read'], $user_id]);
    header("Location: notifications.php");
    exit;
}

// Mark all as read
if (isset($_GET['mark_all'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
    header("Location: notifications.php");
    exit;
}

// Get all notifications
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unread count
$unreadCount = getUnreadNotifications($user_id);

// Get tenant info
$stmt = $db->prepare("SELECT * FROM tenants WHERE user_id = ?");
$stmt->execute([$user_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | MEEDO Tenant Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #0f172a; --accent: #3b82f6; --success: #10b981; 
            --warning: #f59e0b; --danger: #ef4444; --info: #6366f1;
            --gray-50: #f9fafb; --gray-100: #f3f4f6; --gray-200: #e5e7eb;
            --gray-300: #d1d5db; --gray-400: #9ca3af; --gray-500: #6b7280;
            --gray-600: #4b5563; --gray-700: #374151; --gray-800: #1f2937;
            --gray-900: #111827;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--gray-50);
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
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .back-btn {
            width: 40px;
            height: 40px;
            background: var(--gray-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-600);
            text-decoration: none;
        }
        .market-badge {
            background: var(--primary);
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
        }
        .stall-tag {
            background: var(--accent);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
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
            max-width: 900px;
            margin: 0 auto;
        }

        /* Page Title */
        .page-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .page-title h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .mark-all {
            padding: 10px 20px;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 40px;
            color: var(--gray-600);
            text-decoration: none;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mark-all:hover {
            background: var(--accent);
            color: white;
        }

        /* Stats Card */
        .stats-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid var(--gray-200);
            display: flex;
            gap: 30px;
        }
        .stat-item {
            flex: 1;
            text-align: center;
        }
        .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 5px;
        }
        .stat-label {
            color: var(--gray-500);
            font-size: 13px;
        }

        /* Notifications List */
        .notifications-card {
            background: white;
            border-radius: 24px;
            padding: 25px;
            border: 1px solid var(--gray-200);
        }
        .notification-item {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            border-bottom: 1px solid var(--gray-200);
            transition: background 0.2s;
        }
        .notification-item:last-child {
            border-bottom: none;
        }
        .notification-item.unread {
            background: #eff6ff;
            border-radius: 12px;
            margin-bottom: 5px;
        }
        .notification-icon {
            width: 50px;
            height: 50px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        .notification-icon.info {
            background: #dbeafe;
            color: var(--accent);
        }
        .notification-icon.success {
            background: #dcfce7;
            color: var(--success);
        }
        .notification-icon.warning {
            background: #fef3c7;
            color: var(--warning);
        }
        .notification-icon.danger {
            background: #fee2e2;
            color: var(--danger);
        }
        .notification-content {
            flex: 1;
        }
        .notification-title {
            font-weight: 700;
            font-size: 16px;
            color: var(--gray-900);
            margin-bottom: 5px;
        }
        .notification-message {
            color: var(--gray-600);
            font-size: 14px;
            margin-bottom: 8px;
            line-height: 1.5;
        }
        .notification-meta {
            display: flex;
            align-items: center;
            gap: 20px;
            font-size: 12px;
            color: var(--gray-500);
        }
        .notification-meta i {
            margin-right: 4px;
        }
        .notification-badge {
            background: var(--danger);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }
        .mark-read-btn {
            width: 36px;
            height: 36px;
            background: var(--gray-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
            text-decoration: none;
        }
        .mark-read-btn:hover {
            background: var(--accent);
            color: white;
        }
        .empty-state {
            text-align: center;
            padding: 60px;
            color: var(--gray-400);
        }
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
        }
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 10px;
            color: var(--gray-600);
        }
    </style>
</head>
<body>
    <div class="tenant-header">
        <div class="header-left">
            <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i></a>
            <div class="market-badge"><i class="fas fa-store"></i> Odiongan Public Market</div>
            <span class="stall-tag">Stall <?php echo $tenant['stall_number']; ?></span>
        </div>
        <div class="header-right">
            <div class="user-menu" onclick="location.href='dashboard.php'">
                <div class="user-avatar"><?php echo substr($tenant['name'], 0, 1); ?></div>
                <span style="font-weight: 500;"><?php echo $tenant['name']; ?></span>
                <i class="fas fa-chevron-down" style="font-size: 12px;"></i>
            </div>
        </div>
    </div>

    <div class="main">
        <div class="page-title">
            <h1>
                <i class="fas fa-bell" style="color: var(--accent);"></i> 
                Notifications
                <?php if ($unreadCount > 0): ?>
                    <span style="background: var(--danger); color: white; padding: 5px 15px; border-radius: 40px; font-size: 14px;">
                        <?php echo $unreadCount; ?> unread
                    </span>
                <?php endif; ?>
            </h1>
            <?php if ($unreadCount > 0): ?>
                <a href="?mark_all=1" class="mark-all">
                    <i class="fas fa-check-double"></i> Mark All as Read
                </a>
            <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="stats-card">
            <div class="stat-item">
                <div class="stat-value"><?php echo count($notifications); ?></div>
                <div class="stat-label">Total Notifications</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $unreadCount; ?></div>
                <div class="stat-label">Unread</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">
                    <?php 
                    $latest = !empty($notifications) ? date('M d', strtotime($notifications[0]['created_at'])) : 'None';
                    echo $latest;
                    ?>
                </div>
                <div class="stat-label">Latest</div>
            </div>
        </div>

        <!-- Notifications List -->
        <div class="notifications-card">
            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <h3>No Notifications</h3>
                    <p>You're all caught up! Check back later for updates.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notif): ?>
                <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                    <div class="notification-icon <?php echo $notif['type']; ?>">
                        <i class="fas fa-<?php 
                            echo $notif['type'] == 'info' ? 'info-circle' : 
                                ($notif['type'] == 'success' ? 'check-circle' : 
                                ($notif['type'] == 'warning' ? 'exclamation-triangle' : 'bell')); 
                        ?>"></i>
                    </div>
                    <div class="notification-content">
                        <div class="notification-title">
                            <?php echo $notif['title']; ?>
                            <?php if (!$notif['is_read']): ?>
                                <span class="notification-badge">New</span>
                            <?php endif; ?>
                        </div>
                        <div class="notification-message"><?php echo $notif['message']; ?></div>
                        <div class="notification-meta">
                            <span><i class="far fa-clock"></i> <?php echo date('F d, Y h:i A', strtotime($notif['created_at'])); ?></span>
                            <span><i class="fas fa-tag"></i> <?php echo ucfirst($notif['type']); ?></span>
                        </div>
                    </div>
                    <?php if (!$notif['is_read']): ?>
                        <a href="?mark_read=<?php echo $notif['id']; ?>" class="mark-read-btn">
                            <i class="fas fa-check"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>