<?php
// dashboard_erp.php - Senior Manager module skeleton (uses create_db.sql schema)
require_once 'config.php';

if (!isset($_SESSION['Username'])) {
    header('Location: index.php');
    exit;
}

$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-365 days'));
$end_date   = $_GET['end_date']   ?? date('Y-m-d');

$pdo = getPDO();

// Example: top distributors placeholder
$top_distributors = $pdo->query("SELECT c.CompanyID, c.CompanyName FROM Company c JOIN Distributor d ON c.CompanyID = d.CompanyID LIMIT 10")->fetchAll();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>ERP / Senior Manager Dashboard</title>
    <link rel="stylesheet" href="assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<?php include 'nav.php'; ?>
<main class="container">
    <h2>Senior Manager Module</h2>
    <section class="filters">
        <form method="get">
            <label>Start <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>"></label>
            <label>End <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>"></label>
            <button type="submit">Apply</button>
        </form>
    </section>

    <section id="seniorKpis">
        <h3>Average Financial Health by Company (bar)</h3>
        <canvas id="financialHealthChart" width="900" height="300"></canvas>

        <h3>Regional Disruption Overview</h3>
        <canvas id="regionalDisruptionChart" width="900" height="300"></canvas>

        <h3>Most Critical Companies</h3>
        <div id="criticalCompaniesTable">
            <!-- Table populated via SQL queries and server-side rendering -->
            <p>Critical companies table will appear here.</p>
        </div>

        <h3>Top Distributors by Shipment Volume</h3>
        <ul>
            <?php foreach ($top_distributors as $d): ?>
                <li><?= htmlspecialchars($d['CompanyName']) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
</main>

<script src="assets/app.js"></script>
<script>
// Placeholder charts for senior manager
const fctx = document.getElementById('financialHealthChart').getContext('2d');
new Chart(fctx, {
    type:'bar',
    data: { labels:['Co A','Co B','Co C'], datasets:[{label:'Avg Health', data:[78,85,61], backgroundColor:'#63B3ED'}] },
    options: { responsive:true, maintainAspectRatio:false }
});

const rctx = document.getElementById('regionalDisruptionChart').getContext('2d');
new Chart(rctx, {
    type:'bar',
    data:{ labels:['North','South','East','West'], datasets:[{label:'Disruptions', data:[12,5,7,3], backgroundColor:'#F6AD55'}] },
    options:{ responsive:true, maintainAspectRatio:false }
});
</script>
</body>
</html>
