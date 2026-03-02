<?php
// install.php - Run this once to set up the database
echo "🔧 Installing Market Management System...\n\n";

try {
    $db = new PDO('sqlite:database.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Drop existing tables
    $db->exec("DROP TABLE IF EXISTS notifications");
    $db->exec("DROP TABLE IF EXISTS repairs");
    $db->exec("DROP TABLE IF EXISTS payments");
    $db->exec("DROP TABLE IF EXISTS tenants");
    $db->exec("DROP TABLE IF EXISTS stalls");
    $db->exec("DROP TABLE IF EXISTS sections");
    $db->exec("DROP TABLE IF EXISTS users");
    
    echo "✅ Old tables dropped\n";
    
    // Create users table with status column
    $db->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            name TEXT NOT NULL,
            stall_number TEXT,
            contact TEXT,
            email TEXT,
            role TEXT DEFAULT 'tenant',
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✅ Users table created (default status: pending)\n";
    
    // Create tenants table with status column
    $db->exec("
        CREATE TABLE tenants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER UNIQUE,
            stall_id INTEGER,
            stall_number TEXT NOT NULL,
            name TEXT NOT NULL,
            section TEXT,
            contact TEXT,
            email TEXT,
            monthly_rent REAL DEFAULT 0,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");
    echo "✅ Tenants table created (default status: pending)\n";
    
    // Create payments table
    $db->exec("
        CREATE TABLE payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            month INTEGER NOT NULL,
            year INTEGER NOT NULL,
            payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            status TEXT DEFAULT 'paid',
            reference_no TEXT,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        )
    ");
    echo "✅ Payments table created\n";
    
    // Create repairs table
    $db->exec("
        CREATE TABLE repairs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            stall_number TEXT NOT NULL,
            issue_description TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            report_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            completion_date DATETIME,
            cost REAL DEFAULT 0,
            admin_remarks TEXT,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        )
    ");
    echo "✅ Repairs table created\n";
    
    // Create notifications table
    $db->exec("
        CREATE TABLE notifications (
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
    echo "✅ Notifications table created\n";
    
    // Create sections table
    $db->exec("
        CREATE TABLE sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            icon TEXT DEFAULT 'store',
            display_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✅ Sections table created\n";
    
    // Create stalls table
    $db->exec("
        CREATE TABLE stalls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stall_number TEXT UNIQUE NOT NULL,
            section TEXT NOT NULL,
            monthly_rent REAL DEFAULT 0,
            status TEXT DEFAULT 'available',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✅ Stalls table created\n";
    
    // Insert default sections
    $db->exec("INSERT INTO sections (name, icon, display_order) VALUES ('Meat Section', 'drumstick-bite', 1)");
    $db->exec("INSERT INTO sections (name, icon, display_order) VALUES ('Fish Section', 'fish', 2)");
    $db->exec("INSERT INTO sections (name, icon, display_order) VALUES ('Vegetable Section', 'carrot', 3)");
    $db->exec("INSERT INTO sections (name, icon, display_order) VALUES ('Dry Goods', 'box', 4)");
    $db->exec("INSERT INTO sections (name, icon, display_order) VALUES ('Rice Section', 'seedling', 5)");
    echo "✅ Default sections added\n";
    
    // Insert ONLY the admin user (no sample tenants)
    $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, name, role, status) VALUES (?, ?, ?, 'admin', 'active')");
    $stmt->execute(['admin', $hashed_password, 'Market Administrator']);
    
    echo "✅ Admin user created\n";
    echo "\n✅✅✅ INSTALLATION COMPLETE! ✅✅✅\n";
    echo "====================================\n";
    echo "ADMIN LOGIN:\n";
    echo "  Username: admin\n";
    echo "  Password: admin123\n\n";
    echo "⚠️  No sample tenants created.\n";
    echo "Tenants must register through the public registration page.\n";
    echo "Stalls must be added through admin/manage-stalls.php\n";
    echo "====================================\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
