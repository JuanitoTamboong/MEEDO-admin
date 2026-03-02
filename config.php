<?php
session_start();

function getDB() {
    try {
        $db = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

function formatMoney($amount) {
    return '₱' . number_format($amount, 2);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'admin';
}

function isTenant() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'tenant';
}

function getTenantId() {
    if (!isset($_SESSION['user_id'])) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetchColumn();
}

function createNotification($user_id, $title, $message, $type = 'info') {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$user_id, $title, $message, $type]);
}

function getUnreadNotifications($user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

function checkDueDates() {
    $db = getDB();
    $currentMonth = date('n');
    $currentYear = date('Y');
    $currentDay = date('j');
    $daysInMonth = date('t');
    
    $tenants = $db->query("
        SELECT t.*, u.id as user_id 
        FROM tenants t
        JOIN users u ON t.user_id = u.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($tenants as $tenant) {
        $stmt = $db->prepare("
            SELECT id FROM payments 
            WHERE tenant_id = ? AND month = ? AND year = ?
        ");
        $stmt->execute([$tenant['id'], $currentMonth, $currentYear]);
        $paid = $stmt->fetch();
        
        if (!$paid) {
            if ($daysInMonth - $currentDay <= 3) {
                $checkStmt = $db->prepare("
                    SELECT id FROM notifications 
                    WHERE user_id = ? AND title LIKE '%Urgent%' 
                    AND DATE(created_at) = DATE('now')
                ");
                $checkStmt->execute([$tenant['user_id']]);
                
                if (!$checkStmt->fetch()) {
                    createNotification(
                        $tenant['user_id'],
                        '⚠️ URGENT: Payment Due',
                        'Your payment is due in ' . ($daysInMonth - $currentDay) . ' days. Please pay immediately to avoid 25% penalty.',
                        'danger'
                    );
                }
            } elseif ($daysInMonth - $currentDay <= 7) {
                $checkStmt = $db->prepare("
                    SELECT id FROM notifications 
                    WHERE user_id = ? AND title LIKE '%Reminder%' 
                    AND DATE(created_at) = DATE('now')
                ");
                $checkStmt->execute([$tenant['user_id']]);
                
                if (!$checkStmt->fetch()) {
                    createNotification(
                        $tenant['user_id'],
                        '📅 Payment Reminder',
                        'Your payment is due in ' . ($daysInMonth - $currentDay) . ' days.',
                        'warning'
                    );
                }
            }
            
            if ($currentDay > $daysInMonth) {
                $daysOverdue = $currentDay - $daysInMonth;
                $penalty = $tenant['monthly_rent'] * 0.25;
                
                $checkStmt = $db->prepare("
                    SELECT id FROM notifications 
                    WHERE user_id = ? AND title LIKE '%Overdue%' 
                    AND DATE(created_at) = DATE('now')
                ");
                $checkStmt->execute([$tenant['user_id']]);
                
                if (!$checkStmt->fetch()) {
                    createNotification(
                        $tenant['user_id'],
                        '🔴 ACCOUNT OVERDUE',
                        "Your account is overdue by $daysOverdue days. A 25% penalty (₱" . number_format($penalty, 2) . ") has been applied.",
                        'danger'
                    );
                }
            }
        }
    }
}

function calculatePenalty($amount, $daysOverdue) {
    if ($daysOverdue > 0) {
        return $amount * 0.25;
    }
    return 0;
}

function getOverdueTenants() {
    $db = getDB();
    $currentMonth = date('n');
    $currentYear = date('Y');
    $currentDay = date('j');
    $daysInMonth = date('t');
    
    $overdue = [];
    
    $tenants = $db->query("
        SELECT t.*, u.id as user_id 
        FROM tenants t
        JOIN users u ON t.user_id = u.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($tenants as $tenant) {
        $stmt = $db->prepare("
            SELECT id FROM payments 
            WHERE tenant_id = ? AND month = ? AND year = ?
        ");
        $stmt->execute([$tenant['id'], $currentMonth, $currentYear]);
        $paid = $stmt->fetch();
        
        if (!$paid && $currentDay > $daysInMonth) {
            $daysOverdue = $currentDay - $daysInMonth;
            $tenant['amount_due'] = $tenant['monthly_rent'];
            $tenant['days_overdue'] = $daysOverdue;
            $tenant['penalty'] = calculatePenalty($tenant['monthly_rent'], $daysOverdue);
            $tenant['total_due'] = $tenant['monthly_rent'] + $tenant['penalty'];
            $overdue[] = $tenant;
        }
    }
    
    return $overdue;
}

function getStatistics() {
    $db = getDB();
    $stats = [];
    
    $stats['total_tenants'] = $db->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM payments WHERE month = ? AND year = ?");
    $stmt->execute([date('n'), date('Y')]);
    $stats['paid_tenants'] = $stmt->fetchColumn();
    
    $stats['unpaid_tenants'] = $stats['total_tenants'] - $stats['paid_tenants'];
    
    $stmt = $db->prepare("SELECT SUM(amount) FROM payments WHERE year = ?");
    $stmt->execute([date('Y')]);
    $stats['total_collected'] = $stmt->fetchColumn() ?: 0;
    
    return $stats;
}

function peso($amount) {
    return formatMoney($amount);
}
?>