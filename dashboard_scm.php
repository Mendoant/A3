<?php
// dashboard_scm.php - Supply Chain Manager module skeleton (uses create_db.sql schema)
require_once 'config.php';

// Access control
if (!isset($_SESSION['Username'])) {
    header('Location: index.php');
    exit;
}

// Default filters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-90 days'));
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$company_id = $_GET['company_id'] ?? '';

// DB connection
$pdo = getPDO();

// Fetch company list for selector
$companies = $pdo->query("SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName LIMIT 500")->fetchAll();

// Example: fetch company info if company_id provided
$company = null;
if ($company_id !== '') {
    $stmt = $pdo->prepare("SELECT c.*, l.CountryName, l.ContinentName FROM Company c JOIN Location l ON c.LocationID = l.LocationID WHERE c.CompanyID = :id LIMIT 1");
    $stmt->execute([':id' => $company_id]);
    $company = $stmt->fetch();
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>SCM Dashboard</title>
    <link rel="stylesheet" href="assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<?php include 'nav.php'; ?>
<main class="container">
    <h2>Supply Chain Manager Module</h2>
    <section class="filters">
        <form id="filterForm" method="get">
            <label>Start <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>"></label>
            <label>End <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>"></label>
            <label>Company
                <select name="company_id">
                    <option value="">-- All --</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= $c['CompanyID'] ?>" <?= ($company_id == $c['CompanyID']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['CompanyName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Apply</button>
        </form>
    </section>

    <section id="companyInfo">
        <?php if ($company): ?>
            <h3><?= htmlspecialchars($company['CompanyName']) ?></h3>
            <p>Address: <?= htmlspecialchars($company['CountryName'] . ', ' . $company['ContinentName']) ?></p>
            <p>Type/tier: <?= htmlspecialchars($company['Type']) ?> / <?= htmlspecialchars($company['TierLevel']) ?></p>
            <?php if ($company['Type'] === 'Manufacturer'): ?>
                <?php
                $stmt = $pdo->prepare("SELECT FactoryCapacity FROM Manufacturer WHERE CompanyID = :id");
                $stmt->execute([':id' => $company_id]);
                $man = $stmt->fetch();
                ?>
                <p>Factory capacity: <?= htmlspecialchars($man['FactoryCapacity'] ?? 'N/A') ?></p>
            <?php endif; ?>
        <?php else: ?>
            <p>No company selected. Use the filters above.</p>
        <?php endif; ?>
    </section>

    <section id="kpis">
        <h3>Key Performance Indicators (selectable date range)</h3>
        <div class="charts-row">
            <canvas id="onTimeChart" width="600" height="300"></canvas>
            <canvas id="delayHistogram" width="600" height="300"></canvas>
        </div>
        <div class="charts-row">
            <canvas id="disruptionFreqChart" width="600" height="300"></canvas>
            <canvas id="regionalRiskChart" width="600" height="300"></canvas>
        </div>
    </section>

    <section id="transactions">
        <h3>Transactions (Ship/Receive/Adjustments)</h3>
        <div id="transactionsList">
            <!-- You will implement AJAX endpoints to fetch transactions filtered by date/company -->
            <p>Transactions table will be loaded here.</p>
        </div>
    </section>
</main>

<script src="assets/app.js"></script>
<script>
// Chart.js placeholders - data will come from AJAX endpoints you implement later
const ctxOnTime = document.getElementById('onTimeChart').getContext('2d');
const onTimeChart = new Chart(ctxOnTime, {
    type: 'bar',
    data: {
        labels: ['Supplier A', 'Supplier B'],
        datasets: [{ label: 'On-time %', data: [95, 78], backgroundColor: ['#2b6cb0','#f6ad55'] }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales:{y:{beginAtZero:true, max:100}} }
});

const ctxDelay = document.getElementById('delayHistogram').getContext('2d');
const delayChart = new Chart(ctxDelay, {
    type: 'bar',
    data: { labels: ['-5','0','5','10','15+'], datasets: [{ label: 'Count', data: [2,10,15,8,3], backgroundColor:'#9AE6B4' }] },
    options: { responsive: true, maintainAspectRatio: false }
});

const ctxDF = document.getElementById('disruptionFreqChart').getContext('2d');
const dfChart = new Chart(ctxDF, {
    type: 'bar',
    data: { labels: ['Company A','Company B','Company C'], datasets: [{ label: 'Disruption Freq', data: [3,5,1], backgroundColor:'#F56565' }]},
    options: { responsive: true, maintainAspectRatio: false }
});

const ctxRRC = document.getElementById('regionalRiskChart').getContext('2d');
const rrcChart = new Chart(ctxRRC, {
    type: 'bar',
    data: { labels: ['Region 1','Region 2','Region 3'], datasets: [{ label: 'RRC', data: [0.5,0.3,0.2], backgroundColor:['#2b6cb0','#68D391','#F6E05E'] }] },
    options: { responsive: true, maintainAspectRatio: false, scales:{y:{beginAtZero:true}} }
});
</script>
</body>
</html>
