<?php
session_start();

function getDB() {
    try {
        $db = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // Enable WAL mode for better concurrency and prevent database locking
        $db->exec('PRAGMA journal_mode = wal');
        $db->exec('PRAGMA synchronous = NORMAL');
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA busy_timeout = 30000'); // Wait up to 30 seconds when database is busy
        
        return $db;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
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

// Helper function to execute queries with retry logic for locked databases
function executeWithRetry($db, $sql, $params = []) {
    $maxRetries = 3;
    $retryCount = 0;
    
    while ($retryCount < $maxRetries) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'database is locked') !== false) {
                $retryCount++;
                if ($retryCount >= $maxRetries) {
                    throw $e;
                }
                // Exponential backoff
                usleep(100000 * pow(2, $retryCount));
            } else {
                throw $e;
            }
        }
    }
}

// Function to check if a table exists
function tableExists($db, $tableName) {
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$tableName'");
    return $result->fetch() !== false;
}

// Function to get table columns
function getTableColumns($db, $tableName) {
    if (!tableExists($db, $tableName)) {
        return [];
    }
    $columns = [];
    $result = $db->query("PRAGMA table_info($tableName)");
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $columns[$row['name']] = $row;
    }
    return $columns;
}

// Function to add column if not exists
function addColumnIfNotExists($db, $tableName, $columnName, $columnDef) {
    $columns = getTableColumns($db, $tableName);
    if (!isset($columns[$columnName])) {
        try {
            $db->exec("ALTER TABLE $tableName ADD COLUMN $columnName $columnDef");
            return true;
        } catch (PDOException $e) {
            error_log("Failed to add column $columnName to $tableName: " . $e->getMessage());
            return false;
        }
    }
    return false;
}

// Initialize database tables if they don't exist
function initializeDatabase() {
    $db = getDB();
    
    // Create users table
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT DEFAULT 'tenant',
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Check and fix sections table
    if (!tableExists($db, 'sections')) {
        // Create sections table if it doesn't exist
        $db->exec("
            CREATE TABLE sections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                description TEXT,
                icon TEXT DEFAULT 'store',
                display_order INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "Created sections table<br>";
    } else {
        // Add missing columns to existing sections table
        addColumnIfNotExists($db, 'sections', 'description', 'TEXT');
        addColumnIfNotExists($db, 'sections', 'icon', "TEXT DEFAULT 'store'");
        addColumnIfNotExists($db, 'sections', 'display_order', 'INTEGER DEFAULT 0');
        addColumnIfNotExists($db, 'sections', 'created_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP');
    }
    
    // Create stalls table
    $db->exec("
        CREATE TABLE IF NOT EXISTS stalls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stall_number TEXT NOT NULL,
            section TEXT NOT NULL,
            monthly_rent DECIMAL(10,2) NOT NULL,
            status TEXT DEFAULT 'available',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (section) REFERENCES sections(name),
            UNIQUE(stall_number, section)
        )
    ");
    
    // Create tenants table
    $db->exec("
        CREATE TABLE IF NOT EXISTS tenants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER UNIQUE NOT NULL,
            stall_id INTEGER UNIQUE NOT NULL,
            name TEXT NOT NULL,
            stall_number TEXT NOT NULL,
            section TEXT NOT NULL,
            monthly_rent DECIMAL(10,2) NOT NULL,
            contact TEXT NOT NULL,
            email TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (stall_id) REFERENCES stalls(id)
        )
    ");
    
    // Create payments table
    $db->exec("
        CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            month INTEGER NOT NULL,
            year INTEGER NOT NULL,
            payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            status TEXT DEFAULT 'paid',
            reference_number TEXT,
            notes TEXT,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        )
    ");
    
    // Create notifications table
    $db->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            type TEXT DEFAULT 'info',
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");
    
    // Insert default sections if they don't exist
    $sections = [
        ['Meat Section', 'Fresh meat and poultry', 'drumstick-bite', 1],
        ['Fish Section', 'Fresh fish and seafood', 'fish', 2],
        ['Vegetable Section', 'Fresh vegetables and fruits', 'carrot', 3],
        ['Dry Goods', 'Rice, canned goods, and other dry items', 'box', 4],
        ['Rice Section', 'Rice and grains', 'seedling', 5]
    ];
    
    // Check if sections already have data
    $count = $db->query("SELECT COUNT(*) FROM sections")->fetchColumn();
    
    if ($count == 0) {
        // Insert sections only if table is empty
        $insertStmt = $db->prepare("INSERT INTO sections (name, description, icon, display_order) VALUES (?, ?, ?, ?)");
        foreach ($sections as $section) {
            try {
                $insertStmt->execute($section);
            } catch (PDOException $e) {
                error_log("Failed to insert section: " . $e->getMessage());
            }
        }
    }
    
    // Create default admin if not exists
    $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, name, email, password, role, status) VALUES (?, ?, ?, ?, 'admin', 'active')");
        $stmt->execute(['admin', 'System Administrator', 'admin@meedo.gov.ph', $hashed_password]);
        
        // Get the new admin ID
        $adminId = $db->lastInsertId();
        
        // Create admin notification if notifications table exists
        if (tableExists($db, 'notifications')) {
            createNotification($adminId, 'Welcome to MEEDO', 'Your admin account has been created successfully. Default password: admin123', 'success');
        }
    }
}

// Call initialization on every page load
try {
    initializeDatabase();
} catch (PDOException $e) {
    error_log("Database initialization failed: " . $e->getMessage());
    // Don't die here, just log the error
}
?>