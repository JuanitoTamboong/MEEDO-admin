<?php
require_once 'config.php';

$db = getDB();

echo "=== SECTIONS ===\n";
$sections = $db->query("SELECT * FROM sections")->fetchAll(PDO::FETCH_ASSOC);
print_r($sections);

echo "\n=== STALLS ===\n";
$stalls = $db->query("SELECT * FROM stalls")->fetchAll(PDO::FETCH_ASSOC);
print_r($stalls);

echo "\n=== USERS ===\n";
$users = $db->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
print_r($users);
?>
