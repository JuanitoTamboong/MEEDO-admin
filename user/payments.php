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

// Get all payments
$stmt = $db->prepare("
    SELECT * FROM payments 
    WHERE tenant_id = ? 
    ORDER BY year DESC, month DESC
");
$stmt->execute([$tenant['id']]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$totalPaid = array_sum(array_column($payments, 'amount'));
$paymentCount = count($payments);
$lastPayment = !empty($payments) ? $payments[0] : null;

// Check current status
$currentMonth = date('n');
$currentYear = date('Y');
$currentPaid = false;
foreach ($payments as $p) {
    if ($p['month'] == $currentMonth && $p['year'] == $currentYear) {
        $currentPaid = true;
        break;
    }
}

// Check if overdue
$daysInMonth = date('t');
$currentDay = date('j');
$isOverdue = !$currentPaid && $currentDay > $daysInMonth;
$penalty = $isOverdue ? $tenant['monthly_rent'] * 0.25 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History | MEEDO Tenant Portal</title>
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
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Status Banner */
        .status-banner {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        .status-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: <?php echo $currentPaid ? 'var(--success)' : ($isOverdue ? 'var(--danger)' : 'var(--warning)'); ?>;
        }
        .status-info h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: var(--gray-700);
        }
        .status-info .amount {
            font-size: 32px;
            font-weight: 700;
            color: var(--gray-900);
        }
        .status-badge {
            padding: 10px 30px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 16px;
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid var(--gray-200);
        }
        .stat-label {
            color: var(--gray-500);
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
        }

        /* Payment Table */
        .payment-card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            border: 1px solid var(--gray-200);
        }
        .payment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        .payment-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: var(--gray-900);
        }
        .filter-select {
            padding: 8px 16px;
            border: 1px solid var(--gray-200);
            border-radius: 30px;
            font-size: 13px;
            background: var(--gray-50);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            text-align: left;
            padding: 15px;
            color: var(--gray-500);
            font-weight: 600;
            font-size: 12px;
            border-bottom: 1px solid var(--gray-200);
        }
        td {
            padding: 15px;
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
        .payment-status.pending {
            background: #fef3c7;
            color: var(--warning);
        }
        .reference {
            color: var(--gray-500);
            font-size: 12px;
        }

        /* Overdue Alert */
        .overdue-alert {
            background: #fee2e2;
            border-radius: 16px;
            padding: 20px;
            margin-top: 30px;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .overdue-alert i {
            font-size: 32px;
            color: var(--danger);
        }
        .overdue-alert p {
            flex: 1;
            color: var(--danger);
            font-weight: 500;
        }
        .overdue-alert .penalty {
            background: var(--danger);
            color: white;
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 600;
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
        <!-- Status Banner -->
        <div class="status-banner">
            <div class="status-info">
                <h3>Current Month (<?php echo date('F Y'); ?>)</h3>
                <div class="amount"><?php echo formatMoney($tenant['monthly_rent']); ?></div>
            </div>
            <?php if ($currentPaid): ?>
                <span class="status-badge paid"><i class="fas fa-check-circle"></i> PAID</span>
            <?php elseif ($isOverdue): ?>
                <span class="status-badge overdue"><i class="fas fa-exclamation-circle"></i> OVERDUE +25%</span>
            <?php else: ?>
                <span class="status-badge unpaid"><i class="fas fa-clock"></i> UNPAID</span>
            <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Payments</div>
                <div class="stat-value"><?php echo $paymentCount; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Amount Paid</div>
                <div class="stat-value"><?php echo formatMoney($totalPaid); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Last Payment</div>
                <div class="stat-value">
                    <?php echo $lastPayment ? date('M Y', mktime(0,0,0,$lastPayment['month'],1,$lastPayment['year'])) : 'None'; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Monthly Rent</div>
                <div class="stat-value"><?php echo formatMoney($tenant['monthly_rent']); ?></div>
            </div>
        </div>

        <!-- Payment History -->
        <div class="payment-card">
            <div class="payment-header">
                <h2><i class="fas fa-history" style="color: var(--accent);"></i> Payment History</h2>
                <select class="filter-select" id="yearFilter">
                    <option value="">All Years</option>
                    <?php 
                    $years = array_unique(array_column($payments, 'year'));
                    rsort($years);
                    foreach ($years as $year): 
                    ?>
                    <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <table id="paymentTable">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Year</th>
                        <th>Amount</th>
                        <th>Payment Date</th>
                        <th>Reference</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                    <tr data-year="<?php echo $payment['year']; ?>">
                        <td><strong><?php echo date('F', mktime(0,0,0,$payment['month'],1)); ?></strong></td>
                        <td><?php echo $payment['year']; ?></td>
                        <td><?php echo formatMoney($payment['amount']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                        <td><span class="reference"><?php echo $payment['reference_no'] ?: '—'; ?></span></td>
                        <td><span class="payment-status paid"><i class="fas fa-check-circle"></i> Paid</span></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px;">
                            <i class="fas fa-receipt" style="font-size: 48px; color: var(--gray-300); margin-bottom: 10px;"></i>
                            <p style="color: var(--gray-500);">No payment records found</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($isOverdue): ?>
        <!-- Overdue Alert -->
        <div class="overdue-alert">
            <i class="fas fa-exclamation-triangle"></i>
            <p>
                <strong>Account Overdue!</strong> Your payment is <?php echo $currentDay - $daysInMonth; ?> days late. 
                A 25% penalty has been applied to your account.
            </p>
            <span class="penalty">+<?php echo formatMoney($penalty); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Filter by year
        document.getElementById('yearFilter').addEventListener('change', function() {
            const year = this.value;
            const rows = document.querySelectorAll('#paymentTable tbody tr');
            
            rows.forEach(row => {
                if (year === '' || row.dataset.year === year) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>