<?php
// scm/kpis.php - Key Performance Indicators with AJAX
require_once '../config.php';
requireLogin();

// kick out senior managers - they have their own dashboard
if (hasRole('SeniorManager')) {
    header('Location: ../erp/dashboard.php');
    exit;
}

$pdo = getPDO();

// Get filters - default to last year of data
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 year'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$companyID = isset($_GET['company_id']) ? $_GET['company_id'] : '';

// Build WHERE clause for our queries
$where = array("s.PromisedDate BETWEEN :start AND :end");
$params = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $where[] = "(s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)";
    $params[':companyID'] = $companyID;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// KPI 1: On-Time Delivery Rate
// only count shipments that have actualy been delivered (ActualDate is not null)
// NOTE: 'delayed' is a MySQL reserved word so we need backticks around it
$sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as onTime,
            SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate THEN 1 ELSE 0 END) as `delayed`
        FROM Shipping s
        $whereClause AND s.ActualDate IS NOT NULL";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$delivery = $stmt->fetch();

$onTimeRate = ($delivery['onTime'] + $delivery['delayed']) > 0 
    ? round(($delivery['onTime'] / ($delivery['onTime'] + $delivery['delayed'])) * 100, 1) 
    : 0;

// KPI 2: Average Delay & Standard Deviation
// only for shipments that were actualy delayed
$sql = "SELECT 
            AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) as avgDelay,
            STDDEV(DATEDIFF(s.ActualDate, s.PromisedDate)) as stdDelay
        FROM Shipping s
        $whereClause AND s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$delay = $stmt->fetch();

// KPI 3: Financial Health (only if specific company selected)
$financialHealth = array();
$companyName = '';
if (!empty($companyID)) {
    // grab last 4 quarters of financial data for this company
    $sql = "SELECT RepYear, Quarter, HealthScore
            FROM FinancialReport
            WHERE CompanyID = :companyID
            ORDER BY RepYear DESC, Quarter DESC
            LIMIT 4";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':companyID' => $companyID));
    $financialHealth = $stmt->fetchAll();
    
    // also get the company name so we can show it
    $stmt = $pdo->prepare("SELECT CompanyName FROM Company WHERE CompanyID = ?");
    $stmt->execute(array($companyID));
    $row = $stmt->fetch();
    $companyName = $row ? $row['CompanyName'] : '';
}

// KPI 4: Disruption Event Distribution by Category
// this shows what types of disruptions are happenning
$sql = "SELECT 
            dc.CategoryName,
            COUNT(DISTINCT de.EventID) as eventCount,
            SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpact
        FROM DisruptionEvent de
        JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
        LEFT JOIN ImpactsCompany ic ON de.EventID = ic.EventID
        WHERE de.EventDate BETWEEN :start AND :end";

if (!empty($companyID)) {
    $sql .= " AND ic.AffectedCompanyID = :companyID";
}

$sql .= " GROUP BY dc.CategoryName ORDER BY eventCount DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$disruptions = $stmt->fetchAll();

// calculate total disruptions for display
$totalDisruptions = 0;
foreach ($disruptions as $d) {
    $totalDisruptions += intval($d['eventCount']);
}

// AJAX response - if this is an ajax request, return json and stop
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'kpis' => array(
            'onTimeRate' => $onTimeRate,
            'totalDeliveries' => intval($delivery['total']),
            'onTimeDeliveries' => intval($delivery['onTime']),
            'delayedDeliveries' => intval($delivery['delayed']),
            'avgDelay' => $delay['avgDelay'] ? round($delay['avgDelay'], 1) : 0,
            'stdDelay' => $delay['stdDelay'] ? round($delay['stdDelay'], 1) : 0,
            'financialHealth' => $financialHealth,
            'disruptions' => $disruptions,
            'companyName' => $companyName
        )
    ));
    exit;
}

// Get all companies for the dropdown (only needed on initial page load)
$companies = $pdo->query("SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>KPIs - SCM</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .kpi-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); 
            gap: 15px; 
            margin: 20px 0; 
        }
        .kpi-card { 
            background: rgba(0,0,0,0.6); 
            padding: 20px; 
            border-radius: 8px; 
            border: 2px solid rgba(207,185,145,0.3); 
            text-align: center; 
            transition: all 0.3s; 
        }
        .kpi-card:hover { 
            border-color: var(--purdue-gold); 
            transform: translateY(-2px); 
        }
        .kpi-card h3 { 
            margin: 0; 
            font-size: 2.2rem; 
            color: var(--purdue-gold); 
        }
        .kpi-card p { 
            margin: 8px 0 0 0; 
            color: var(--text-light); 
            font-size: 0.95rem; 
            font-weight: 600;
        }
        .kpi-card small { 
            color: rgba(207,185,145,0.7); 
            font-size: 0.85rem; 
        }
        .filter-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 15px; 
            margin-top: 15px; 
        }
        .chart-container { 
            background: rgba(0,0,0,0.6); 
            padding: 20px; 
            border-radius: 12px; 
            border: 2px solid rgba(207,185,145,0.3); 
            margin: 20px 0; 
        }
        .chart-wrapper { 
            position: relative; 
            height: 300px; 
        }
        .charts-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .content-section {
            background: rgba(0,0,0,0.6);
            padding: 20px;
            border-radius: 8px;
            border: 2px solid rgba(207,185,145,0.3);
            margin-bottom: 20px;
        }
        .content-section h3 {
            margin-top: 0;
            color: var(--purdue-gold);
        }
        .loading { 
            text-align: center; 
            padding: 40px; 
            color: var(--purdue-gold); 
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Supply Chain Management Portal</h1>
            <nav>
                <span style="color: white;">Welcome, <?= htmlspecialchars($_SESSION['FullName']) ?></span>
                <a href="../logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <nav class="container" style="background: rgba(0,0,0,0.8); padding: 15px 30px; margin-bottom: 30px; border-radius: 8px; display: flex; gap: 20px; flex-wrap: wrap;">
        <a href="dashboard.php">Dashboard</a>
        <a href="companies.php">Companies</a>
        <a href="kpis.php" class="active">KPIs</a>
        <a href="disruptions.php">Disruptions</a>
        <a href="transactions.php">Transactions</a>
        <a href="transaction_costs.php">Cost Analysis</a>
        <a href="distributors.php">Distributors</a>
    </nav>

    <div class="container">
        <h2>Key Performance Indicators</h2>

        <div class="content-section">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 15px;">
                <h3 id="companyTitle" style="margin: 0; color: var(--purdue-gold);">
                    <?= $companyName ? 'KPIs for: ' . htmlspecialchars($companyName) : 'KPIs for All Companies' ?>
                </h3>
            </div>
            
            <form id="filterForm">
                <div class="filter-grid">
                    <div><label>Start Date:</label><input type="date" id="start_date" value="<?= htmlspecialchars($startDate) ?>"></div>
                    <div><label>End Date:</label><input type="date" id="end_date" value="<?= htmlspecialchars($endDate) ?>"></div>
                    <div>
                        <label>Company (Optional):</label>
                        <select id="company_id">
                            <option value="">All Companies</option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?= $c['CompanyID'] ?>" <?= $companyID == $c['CompanyID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 15px; display: flex; gap: 10px;">
                    <button type="submit">Apply Filters</button>
                    <button type="button" id="clearBtn" class="btn-secondary">Clear</button>
                </div>
            </form>
        </div>

        <!-- main KPI cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <h3 id="kpi-ontime"><?= $onTimeRate ?>%</h3>
                <p>On-Time Delivery</p>
                <small id="kpi-ontime-detail"><?= $delivery['onTime'] ?> on-time / <?= $delivery['delayed'] ?> delayed</small>
            </div>
            <div class="kpi-card">
                <h3 id="kpi-total"><?= $delivery['total'] ?></h3>
                <p>Total Deliveries</p>
                <small>Completed shipments</small>
            </div>
            <div class="kpi-card">
                <h3 id="kpi-avgdelay"><?= $delay['avgDelay'] ? round($delay['avgDelay'], 1) : 0 ?></h3>
                <p>Avg Delay (Days)</p>
                <small id="kpi-stddelay">±<?= $delay['stdDelay'] ? round($delay['stdDelay'], 1) : 0 ?> std dev</small>
            </div>
            <div class="kpi-card">
                <h3 id="kpi-disruptions"><?= $totalDisruptions ?></h3>
                <p>Disruption Events</p>
                <small>In date range</small>
            </div>
        </div>

        <!-- Charts in grid layout -->
        <div class="charts-row">
            <div class="chart-container">
                <h3 style="margin-top: 0; color: var(--purdue-gold);">Disruption Events by Category</h3>
                <div class="chart-wrapper">
                    <canvas id="disruptionChart"></canvas>
                </div>
            </div>

            <div class="chart-container" id="financialSection" style="<?= empty($financialHealth) ? 'display: none;' : '' ?>">
                <h3 style="margin-top: 0; color: var(--purdue-gold);">Financial Health Trend</h3>
                <div class="chart-wrapper">
                    <canvas id="financialChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var form = document.getElementById('filterForm');
        var disruptionChart = null; // keep track of chart instances so we can destroy and recreate them
        var financialChart = null;
        
        // load KPI data via ajax
        function load() {
            var params = 'ajax=1&start_date=' + encodeURIComponent(document.getElementById('start_date').value) +
                        '&end_date=' + encodeURIComponent(document.getElementById('end_date').value) +
                        '&company_id=' + encodeURIComponent(document.getElementById('company_id').value);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'kpis.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        var kpi = r.kpis;
                        
                        // update title based on company selection
                        document.getElementById('companyTitle').textContent = 
                            kpi.companyName ? 'KPIs for: ' + kpi.companyName : 'KPIs for All Companies';
                        
                        // update KPI cards with new data
                        document.getElementById('kpi-ontime').textContent = kpi.onTimeRate + '%';
                        document.getElementById('kpi-ontime-detail').textContent = 
                            kpi.onTimeDeliveries + ' on-time / ' + kpi.delayedDeliveries + ' delayed';
                        document.getElementById('kpi-total').textContent = kpi.totalDeliveries;
                        document.getElementById('kpi-avgdelay').textContent = kpi.avgDelay + ' days';
                        document.getElementById('kpi-stddelay').textContent = 'Std Dev: ' + kpi.stdDelay + ' days';
                        
                        // calculate total disruptions from all categories
                        var totalDisruptions = 0;
                        for (var i = 0; i < kpi.disruptions.length; i++) {
                            totalDisruptions += parseInt(kpi.disruptions[i].eventCount);
                        }
                        document.getElementById('kpi-disruptions').textContent = totalDisruptions;
                        
                        // build disruption chart data
                        var categories = [];
                        var counts = [];
                        var highImpact = [];
                        for (var i = 0; i < kpi.disruptions.length; i++) {
                            categories.push(kpi.disruptions[i].CategoryName);
                            counts.push(parseInt(kpi.disruptions[i].eventCount));
                            highImpact.push(parseInt(kpi.disruptions[i].highImpact));
                        }
                        
                        // destroy old chart if it exists (prevents memory leaks)
                        if (disruptionChart) disruptionChart.destroy();
                        
                        var ctx1 = document.getElementById('disruptionChart').getContext('2d');
                        disruptionChart = new Chart(ctx1, {
                            type: 'bar',
                            data: {
                                labels: categories,
                                datasets: [{
                                    label: 'Total Events',
                                    data: counts,
                                    backgroundColor: '#CFB991'
                                }, {
                                    label: 'High Impact',
                                    data: highImpact,
                                    backgroundColor: '#f44336'
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: { 
                                        beginAtZero: true, 
                                        ticks: { color: 'white', stepSize: 1 }, 
                                        grid: { color: 'rgba(207,185,145,0.1)' } 
                                    },
                                    x: { 
                                        ticks: { color: 'white' }, 
                                        grid: { color: 'rgba(207,185,145,0.1)' } 
                                    }
                                },
                                plugins: { legend: { labels: { color: 'white' } } }
                            }
                        });
                        
                        // financial health chart - only show if we have data (company selected)
                        if (kpi.financialHealth.length > 0) {
                            document.getElementById('financialSection').style.display = 'block';
                            
                            // reverse order so oldest quarter is on left (looks better on line charts)
                            var labels = [];
                            var scores = [];
                            for (var i = kpi.financialHealth.length - 1; i >= 0; i--) {
                                labels.push(kpi.financialHealth[i].Quarter + ' ' + kpi.financialHealth[i].RepYear);
                                scores.push(parseFloat(kpi.financialHealth[i].HealthScore));
                            }
                            
                            if (financialChart) financialChart.destroy();
                            
                            var ctx2 = document.getElementById('financialChart').getContext('2d');
                            financialChart = new Chart(ctx2, {
                                type: 'line',
                                data: {
                                    labels: labels,
                                    datasets: [{
                                        label: 'Health Score',
                                        data: scores,
                                        borderColor: '#CFB991',
                                        backgroundColor: 'rgba(207,185,145,0.2)',
                                        tension: 0.4,
                                        fill: true
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    scales: {
                                        y: { 
                                            beginAtZero: true, 
                                            max: 100, 
                                            ticks: { color: 'white' }, 
                                            grid: { color: 'rgba(207,185,145,0.1)' } 
                                        },
                                        x: { 
                                            ticks: { color: 'white' }, 
                                            grid: { color: 'rgba(207,185,145,0.1)' } 
                                        }
                                    },
                                    plugins: { legend: { labels: { color: 'white' } } }
                                }
                            });
                        } else {
                            // hide financial section if no company selected
                            document.getElementById('financialSection').style.display = 'none';
                        }
                    }
                }
            };
            xhr.send();
        }
        
        // event listeners for filter form
        form.addEventListener('submit', function(e) { 
            e.preventDefault(); 
            load(); 
            return false; // extra insurance to prevent page reload
        });
        
        document.getElementById('clearBtn').addEventListener('click', function() {
            document.getElementById('start_date').value = '<?= date('Y-m-d', strtotime('-1 year')) ?>';
            document.getElementById('end_date').value = '<?= date('Y-m-d') ?>';
            document.getElementById('company_id').value = '';
            load();
        });
        
        // CRITICAL: Initialize charts on page load with PHP data
        (function initCharts() {
            var disruptionData = <?= json_encode($disruptions) ?>;
            var financialData = <?= json_encode($financialHealth) ?>;
            
            // Build disruption chart
            var categories = [];
            var counts = [];
            var highImpact = [];
            for (var i = 0; i < disruptionData.length; i++) {
                categories.push(disruptionData[i].CategoryName);
                counts.push(parseInt(disruptionData[i].eventCount));
                highImpact.push(parseInt(disruptionData[i].highImpact));
            }
            
            var ctx1 = document.getElementById('disruptionChart').getContext('2d');
            disruptionChart = new Chart(ctx1, {
                type: 'bar',
                data: {
                    labels: categories,
                    datasets: [{
                        label: 'Total Events',
                        data: counts,
                        backgroundColor: '#CFB991'
                    }, {
                        label: 'High Impact',
                        data: highImpact,
                        backgroundColor: '#f44336'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            ticks: { color: 'white', stepSize: 1 }, 
                            grid: { color: 'rgba(207,185,145,0.1)' } 
                        },
                        x: { 
                            ticks: { color: 'white' }, 
                            grid: { color: 'rgba(207,185,145,0.1)' } 
                        }
                    },
                    plugins: { legend: { labels: { color: 'white' } } }
                }
            });
            
            // Build financial chart if data exists
            if (financialData.length > 0) {
                var labels = [];
                var scores = [];
                for (var i = financialData.length - 1; i >= 0; i--) {
                    labels.push(financialData[i].Quarter + ' ' + financialData[i].RepYear);
                    scores.push(parseFloat(financialData[i].HealthScore));
                }
                
                var ctx2 = document.getElementById('financialChart').getContext('2d');
                financialChart = new Chart(ctx2, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Health Score',
                            data: scores,
                            borderColor: '#CFB991',
                            backgroundColor: 'rgba(207,185,145,0.2)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { 
                                beginAtZero: true,
                                max: 100,
                                ticks: { color: 'white' }, 
                                grid: { color: 'rgba(207,185,145,0.1)' } 
                            },
                            x: { 
                                ticks: { color: 'white' }, 
                                grid: { color: 'rgba(207,185,145,0.1)' } 
                            }
                        },
                        plugins: { legend: { labels: { color: 'white' } } }
                    }
                });
            }
        })();
        
    })();
    </script>
</body>
</html>