<?php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || !isTenant()) {
    header("Location: ../login.php");
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// Get tenant info
$stmt = $db->prepare("SELECT * FROM tenants WHERE user_id = ?");
$stmt->execute([$user_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

$message = '';
$messageType = '';

// Handle repair request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_request'])) {
    $stmt = $db->prepare("
        INSERT INTO repairs (tenant_id, stall_number, issue_description) 
        VALUES (?, ?, ?)
    ");
    
    if ($stmt->execute([$tenant['id'], $tenant['stall_number'], $_POST['issue']])) {
        $message = "Repair request submitted successfully!";
        $messageType = 'success';
        
        // Notify admin
        $adminStmt = $db->query("SELECT id FROM users WHERE role = 'admin'");
        $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($admins as $admin) {
            createNotification(
                $admin['id'],
                'New Repair Request',
                "Tenant {$tenant['name']} (Stall {$tenant['stall_number']}) requested a repair: {$_POST['issue']}",
                'info'
            );
        }
        
        // Notify tenant
        createNotification(
            $user_id,
            'Repair Request Received',
            'Your repair request has been submitted and is pending review.',
            'info'
        );
    }
}

// Get repair history
$stmt = $db->prepare("
    SELECT * FROM repairs 
    WHERE tenant_id = ? 
    ORDER BY report_date DESC
");
$stmt->execute([$tenant['id']]);
$repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Repair | MEEDO Tenant Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #0f172a; --accent: #3b82f6; --success: #10b981; 
            --warning: #f59e0b; --danger: #ef4444;
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
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Page Title */
        .page-title {
            margin-bottom: 30px;
        }
        .page-title h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-900);
        }
        .page-title p {
            color: var(--gray-500);
            margin-top: 5px;
        }

        /* Request Grid */
        .request-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        /* Request Form */
        .request-card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            border: 1px solid var(--gray-200);
        }
        .stall-info {
            background: var(--gray-50);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .stall-icon {
            width: 60px;
            height: 60px;
            background: var(--accent);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        .stall-details h3 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        .stall-details p {
            color: var(--gray-500);
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
        }
        .form-group textarea {
            width: 100%;
            padding: 15px;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            font-size: 14px;
            min-height: 150px;
            resize: vertical;
            font-family: 'Inter', sans-serif;
        }
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
        }
        .btn-submit {
            width: 100%;
            padding: 16px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 40px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s;
        }
        .btn-submit:hover {
            background: var(--primary);
        }
        .message {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .message.success {
            background: #dcfce7;
            color: var(--success);
        }

        /* History Card */
        .history-card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            border: 1px solid var(--gray-200);
        }
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        .history-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-900);
        }
        .history-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .history-item {
            padding: 20px;
            background: var(--gray-50);
            border-radius: 16px;
            border-left: 3px solid;
        }
        .history-item.pending { border-left-color: var(--warning); }
        .history-item.completed { border-left-color: var(--success); }
        .history-item .issue {
            font-weight: 600;
            margin-bottom: 10px;
        }
        .history-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }
        .history-date {
            color: var(--gray-500);
        }
        .history-status {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
        }
        .history-status.pending {
            background: #fef3c7;
            color: var(--warning);
        }
        .history-status.completed {
            background: #dcfce7;
            color: var(--success);
        }
        .history-remarks {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed var(--gray-300);
            font-size: 12px;
            color: var(--gray-600);
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--gray-400);
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
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
            <h1>Repair & Maintenance Request</h1>
            <p><i class="fas fa-tools"></i> Report issues with your stall for immediate attention</p>
        </div>

        <div class="request-grid">
            <!-- Request Form -->
            <div>
                <div class="request-card">
                    <div class="stall-info">
                        <div class="stall-icon">
                            <i class="fas fa-store"></i>
                        </div>
                        <div class="stall-details">
                            <h3>Stall <?php echo $tenant['stall_number']; ?></h3>
                            <p><?php echo $tenant['section']; ?> · <?php echo $tenant['name']; ?></p>
                        </div>
                    </div>

                    <?php if ($message): ?>
                        <div class="message <?php echo $messageType; ?>">
                            <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="form-group">
                            <label><i class="fas fa-exclamation-triangle"></i> Describe the Issue *</label>
                            <textarea name="issue" required 
                                placeholder="Please describe the problem in detail. Include:
- What is the issue?
- When did it start?
- How urgent is it?
- Any other relevant details"></textarea>
                        </div>
                        <button type="submit" name="submit_request" class="btn-submit">
                            <i class="fas fa-paper-plane"></i> Submit Repair Request
                        </button>
                    </form>
                </div>
            </div>

            <!-- Request History -->
            <div>
                <div class="history-card">
                    <div class="history-header">
                        <h2><i class="fas fa-history"></i> Request History</h2>
                    </div>
                    
                    <div class="history-list">
                        <?php foreach ($repairs as $repair): ?>
                        <div class="history-item <?php echo $repair['status']; ?>">
                            <div class="issue"><?php echo $repair['issue_description']; ?></div>
                            <div class="history-meta">
                                <span class="history-date">
                                    <i class="far fa-calendar"></i> <?php echo date('M d, Y', strtotime($repair['report_date'])); ?>
                                </span>
                                <span class="history-status <?php echo $repair['status']; ?>">
                                    <?php echo ucfirst($repair['status']); ?>
                                </span>
                            </div>
                            <?php if ($repair['admin_remarks']): ?>
                            <div class="history-remarks">
                                <i class="fas fa-comment"></i> Admin: <?php echo $repair['admin_remarks']; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($repair['status'] == 'completed' && $repair['cost'] > 0): ?>
                            <div class="history-remarks">
                                <i class="fas fa-coins"></i> Cost: <?php echo formatMoney($repair['cost']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($repairs)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No repair requests yet</p>
                            <small>Use the form to submit your first request</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>