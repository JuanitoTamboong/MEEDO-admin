<?php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || !isTenant()) {
    header("Location: ../login.php");
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// Get tenant info
$stmt = $db->prepare("
    SELECT t.*, u.username, u.email, u.contact 
    FROM tenants t
    JOIN users u ON t.user_id = u.id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

// Update info
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_info'])) {
    $stmt = $db->prepare("
        UPDATE tenants SET contact = ?, email = ? WHERE id = ?
    ");
    if ($stmt->execute([$_POST['contact'], $_POST['email'], $tenant['id']])) {
        $message = "Information updated successfully!";
        // Refresh data
        $stmt->execute([$user_id]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Stall - <?php echo $tenant['stall_number']; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f5f5; }
        .header { 
            background: #1a1a2e; 
            color: white; 
            padding: 15px 30px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
        }
        .container { max-width: 800px; margin: 20px auto; padding: 0 20px; }
        .card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .stall-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
        }
        .stall-number {
            font-size: 48px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        .info-item {
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .info-label {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 18px;
            font-weight: bold;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 16px;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn {
            padding: 12px 30px;
            background: #27ae60;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover { background: #229954; }
        .btn-secondary {
            background: #3498db;
            text-decoration: none;
            display: inline-block;
        }
        .message {
            padding: 15px;
            background: #d4edda;
            color: #155724;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🏪 My Stall Information</h1>
        <div>
            <span><?php echo $_SESSION['name']; ?></span>
            <a href="dashboard.php" style="color: white; margin-left: 15px;">← Back</a>
            <a href="../logout.php" style="color: white; margin-left: 15px;">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="stall-header">
            <div class="stall-number">Stall <?php echo $tenant['stall_number']; ?></div>
            <div><?php echo $tenant['section']; ?></div>
        </div>

        <?php if ($message): ?>
            <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="card">
            <h2 style="margin-bottom: 20px;">Stall Details</h2>
            
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Tenant Name</div>
                    <div class="info-value"><?php echo $tenant['name']; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Username</div>
                    <div class="info-value"><?php echo $tenant['username']; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Monthly Rent</div>
                    <div class="info-value"><?php echo formatMoney($tenant['monthly_rent']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Section</div>
                    <div class="info-value"><?php echo $tenant['section']; ?></div>
                </div>
            </div>

            <h3 style="margin: 30px 0 20px;">Update Contact Information</h3>
            
            <form method="POST">
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="text" name="contact" value="<?php echo $tenant['contact']; ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" value="<?php echo $tenant['email']; ?>" required>
                </div>
                
                <button type="submit" name="update_info" class="btn">Update Information</button>
                <a href="dashboard.php" class="btn btn-secondary" style="margin-left: 10px;">Cancel</a>
            </form>
        </div>
    </div>
</body>
</html>