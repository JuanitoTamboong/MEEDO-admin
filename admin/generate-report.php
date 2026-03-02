<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

/* ===== GET FILTER PARAMETERS ===== */
$fileType = $_GET['file'] ?? 'csv';
$section = $_GET['section'] ?? 'All';
$month = $_GET['month'] ?? 'All';
$year = $_GET['year'] ?? 'All';

/* ===== GET TENANT DATA FROM DATABASE ===== */
$db = getDB();

// Build query based on filters
$query = "SELECT * FROM tenants WHERE status = 'Paid'";
$params = [];

if ($section !== 'All') {
    $query .= " AND section = ?";
    $params[] = $section;
}

if ($month !== 'All') {
    $query .= " AND strftime('%m', due_date) = ?";
    $params[] = $month;
}

if ($year !== 'All') {
    $query .= " AND strftime('%Y', due_date) = ?";
    $params[] = $year;
}

$query .= " ORDER BY section, stall_number";
$stmt = $db->prepare($query);
$stmt->execute($params);
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== CALCULATE TOTALS ===== */
$totals = [];
$grandTotal = 0;
foreach($tenants as $t){
    $sec = $t['section'];
    if(!isset($totals[$sec])) $totals[$sec] = 0;
    $totals[$sec] += $t['amount'];
    $grandTotal += $t['amount'];
}

/* ===== HELPER: GET MONTH RANGE ===== */
function getMonthRange($tenants){
    $months = [];
    foreach($tenants as $t){
        $m = date("m", strtotime($t['due_date']));
        $months[$m] = true;
    }
    ksort($months);
    return array_keys($months);
}

/* ===== PREPARE PERIOD TEXT ===== */
$period = "";
if($month=="All"){
    $months = getMonthRange($tenants);
    if(!empty($months)){
        $period = "From ".date("F", mktime(0,0,0,min($months),1))." to ".date("F", mktime(0,0,0,max($months),1));
    } else {
        $period = "No data";
    }
} else {
    $period = date("F", mktime(0,0,0,$month,1));
}

/* ===== PESO FORMAT HELPER ===== */
function peso($amount){
    return "₱".number_format($amount,2);
}

/* ===== CSV EXPORT ===== */
if($fileType=='csv'){
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report.csv"');

    // UTF-8 BOM for Excel to display ₱ correctly
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output','w');

    // Report header
    fputcsv($output, ["Odiongan Public Market Report"]);
    fputcsv($output, ["Section:", $section]);
    fputcsv($output, ["Period:", $period, "Year:", $year]);
    fputcsv($output, []); // empty line

    // Table headers
    fputcsv($output, ['Name','Stall Number','Section','Amount','Due Date','Payment Status']);

    // Table rows
    foreach($tenants as $t){
        fputcsv($output, [
            $t['name'],
            $t['stall_number'],
            $t['section'],
            peso($t['amount']),
            date('Y-m-d', strtotime($t['due_date'])),
            $t['status']
        ]);
    }

    // Section totals
    fputcsv($output, []); // empty line
    fputcsv($output, ["Total Collected Per Section"]);
    foreach($totals as $secName => $amount){
        fputcsv($output, [$secName, peso($amount)]);
    }

    // Grand total
    fputcsv($output, ["Grand Total", peso($grandTotal)]);

    fclose($output);
    exit;
}

/* ===== WORD EXPORT ===== */
if($fileType=='word'){
    header('Content-Type: application/vnd.ms-word; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report.doc"');

    echo "<meta charset='UTF-8'>"; // ensure ₱ displays correctly
    echo "<h1>Odiongan Public Market Report</h1>";
    echo "<p><strong>Section:</strong> $section</p>";
    echo "<p><strong>Period:</strong> $period</p>";
    echo "<p><strong>Year:</strong> $year</p>";

    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Name</th><th>Stall Number</th><th>Section</th><th>Amount</th><th>Due Date</th><th>Payment Status</th></tr>";

    foreach($tenants as $t){
        echo "<tr>";
        echo "<td>{$t['name']}</td>";
        echo "<td>{$t['stall_number']}</td>";
        echo "<td>{$t['section']}</td>";
        echo "<td>".peso($t['amount'])."</td>";
        echo "<td>".date('Y-m-d', strtotime($t['due_date']))."</td>";
        echo "<td>{$t['status']}</td>";
        echo "</tr>";
    }
    echo "</table><br>";

    echo "<h3>Total Collected Per Section:</h3>";
    echo "<ul>";
    foreach($totals as $secName => $amount){
        echo "<li>$secName: ".peso($amount)."</li>";
    }
    echo "</ul>";

    echo "<h2>Grand Total: ".peso($grandTotal)."</h2>";
    exit;
}
?>
