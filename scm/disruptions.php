<?php
// scm/disruptions.php - Disruption Event Analysis with AJAX
require_once '../config.php';
requireLogin();

// redirect senior managers to their own module
if (hasRole('SeniorManager')) {
    header('Location: ../erp/dashboard.php');
    exit;
}

$pdo = getPDO();

// Get filters - default to last year
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 year'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$companyID = isset($_GET['company_id']) ? $_GET['company_id'] : '';
$region = isset($_GET['region']) ? $_GET['region'] : '';
$tierLevel = isset($_GET['tier']) ? $_GET['tier'] : '';

// Build base query params
$params = array(':start' => $startDate, ':end' => $endDate);

// METRIC 1: Disruption Frequency (DF)
// DF = Number of disruptions / Time period
// we're counting events per company
$sql = "SELECT 
            c.CompanyID,
            c.CompanyName,
            c.TierLevel,
            l.ContinentName,
            COUNT(DISTINCT de.EventID) as disruptionCount
        FROM Company c
        LEFT JOIN Location l ON c.LocationID = l.LocationID
        LEFT JOIN ImpactsCompany ic ON c.CompanyID = ic.AffectedCompanyID
        LEFT JOIN DisruptionEvent de ON ic.EventID = de.EventID 
            AND de.EventDate BETWEEN :start AND :end
        WHERE 1=1";

// add filters if provided
if (!empty($companyID)) {
    $sql .= " AND c.CompanyID = :companyID";
    $params[':companyID'] = $companyID;
}

if (!empty($region)) {
    $sql .= " AND l.ContinentName = :region";
    $params[':region'] = $region;
}

if (!empty($tierLevel)) {
    $sql .= " AND c.TierLevel = :tier";
    $params[':tier'] = $tierLevel;
}

$sql .= " GROUP BY c.CompanyID, c.CompanyName, c.TierLevel, l.ContinentName
          ORDER BY disruptionCount DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll();

// METRIC 2: Average Recovery Time (ART)
// ART = average time between event start and recovery
$sql2 = "SELECT 
            AVG(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as avgRecoveryTime
         FROM DisruptionEvent de
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL";

$stmt2 = $pdo->prepare($sql2);
$stmt2->execute(array(':start' => $startDate, ':end' => $endDate));
$recovery = $stmt2->fetch();
$avgRecoveryTime = $recovery['avgRecoveryTime'] ? round($recovery['avgRecoveryTime'], 1) : 0;

// METRIC 3: High-Impact Disruption Rate (HDR)
// HDR = (High impact events / Total events) * 100%
$sql3 = "SELECT 
            COUNT(DISTINCT de.EventID) as totalEvents,
            SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactEvents
         FROM DisruptionEvent de
         LEFT JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         WHERE de.EventDate BETWEEN :start AND :end";

$stmt3 = $pdo->prepare($sql3);
$stmt3->execute(array(':start' => $startDate, ':end' => $endDate));
$impact = $stmt3->fetch();

$totalEvents = intval($impact['totalEvents']);
$highImpactEvents = intval($impact['highImpactEvents']);
$hdr = $totalEvents > 0 ? round(($highImpactEvents / $totalEvents) * 100, 1) : 0;

// METRIC 4: Total Downtime (TD)
// TD = sum of all recovery times
$sql4 = "SELECT 
            SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as totalDowntime
         FROM DisruptionEvent de
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL";

$stmt4 = $pdo->prepare($sql4);
$stmt4->execute(array(':start' => $startDate, ':end' => $endDate));
$downtime = $stmt4->fetch();
$totalDowntime = $downtime['totalDowntime'] ? intval($downtime['totalDowntime']) : 0;

// METRIC 5: Regional Risk Concentration (RRC)
// RRC = disruptions in region / total disruptions
$sql5 = "SELECT 
            l.ContinentName,
            COUNT(DISTINCT de.EventID) as regionDisruptions
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         GROUP BY l.ContinentName
         ORDER BY regionDisruptions DESC";

$stmt5 = $pdo->prepare($sql5);
$stmt5->execute(array(':start' => $startDate, ':end' => $endDate));
$regions = $stmt5->fetchAll();

// METRIC 6: Disruption Severity Distribution (DSD)
// counts of low/medium/high severity events
$sql6 = "SELECT 
            ic.ImpactLevel,
            COUNT(DISTINCT de.EventID) as eventCount
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         WHERE de.EventDate BETWEEN :start AND :end
         GROUP BY ic.ImpactLevel";

$stmt6 = $pdo->prepare($sql6);
$stmt6->execute(array(':start' => $startDate, ':end' => $endDate));
$severityDist = $stmt6->fetchAll();

// Get all recovery times for ART histogram
$sql7 = "SELECT DATEDIFF(de.EventRecoveryDate, de.EventDate) as recoveryDays
         FROM DisruptionEvent de
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL
         AND DATEDIFF(de.EventRecoveryDate, de.EventDate) >= 0";

$stmt7 = $pdo->prepare($sql7);
$stmt7->execute(array(':start' => $startDate, ':end' => $endDate));
$recoveryTimes = $stmt7->fetchAll(PDO::FETCH_COLUMN);

// Get downtime by category for TD histogram
$sql8 = "SELECT dc.CategoryName,
                SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as totalDowntime
         FROM DisruptionEvent de
         JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL
         GROUP BY dc.CategoryName
         ORDER BY totalDowntime DESC";

$stmt8 = $pdo->prepare($sql8);
$stmt8->execute(array(':start' => $startDate, ':end' => $endDate));
$downtimeByCategory = $stmt8->fetchAll();

// Get data for RRC heatmap (Region x Category matrix)
$sql9 = "SELECT l.ContinentName as Region,
                dc.CategoryName as Category,
                COUNT(DISTINCT de.EventID) as eventCount
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
         WHERE de.EventDate BETWEEN :start AND :end
         GROUP BY l.ContinentName, dc.CategoryName
         ORDER BY l.ContinentName, dc.CategoryName";

$stmt9 = $pdo->prepare($sql9);
$stmt9->execute(array(':start' => $startDate, ':end' => $endDate));
$heatmapData = $stmt9->fetchAll();

// Get HDR data by category for bar chart
$sql10 = "SELECT dc.CategoryName,
                 COUNT(DISTINCT de.EventID) as totalEvents,
                 SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactEvents
          FROM DisruptionEvent de
          JOIN ImpactsCompany ic ON de.EventID = ic.EventID
          JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
          WHERE de.EventDate BETWEEN :start AND :end
          GROUP BY dc.CategoryName
          ORDER BY dc.CategoryName";

$stmt10 = $pdo->prepare($sql10);
$stmt10->execute(array(':start' => $startDate, ':end' => $endDate));
$hdrByCategory = $stmt10->fetchAll();

// AJAX response
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'metrics' => array(
            'companies' => $companies,
            'avgRecoveryTime' => $avgRecoveryTime,
            'totalEvents' => $totalEvents,
            'highImpactEvents' => $highImpactEvents,
            'hdr' => $hdr,
            'totalDowntime' => $totalDowntime,
            'regions' => $regions,
            'severityDist' => $severityDist,
            'recoveryTimes' => $recoveryTimes,
            'downtimeByCategory' => $downtimeByCategory,
            'heatmapData' => $heatmapData,
            'hdrByCategory' => $hdrByCategory
        )
    ));
    exit;
}

// Get filter options for dropdowns (only on initial load)
$allCompanies = $pdo->query("SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName")->fetchAll();
$allRegions = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Disruptions - SCM</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 30px 0; }
        .metric-card { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); text-align: center; transition: all 0.3s; }
        .metric-card:hover { border-color: var(--purdue-gold); transform: translateY(-2px); }
        .metric-card h3 { margin: 0; font-size: 2.5rem; color: var(--purdue-gold); }
        .metric-card p { margin: 8px 0 0 0; color: var(--text-light); font-size: 0.9rem; }
        .metric-card small { color: rgba(207,185,145,0.7); font-size: 0.85rem; display: block; margin-top: 4px; }
        .chart-container { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 12px; border: 2px solid rgba(207,185,145,0.3); margin: 20px 0; }
        .chart-wrapper { position: relative; height: 350px; }
        .alert-box { background: rgba(220, 53, 69, 0.2); border: 2px solid #dc3545; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .alert-box h4 { color: #ff6b6b; margin: 0 0 10px 0; }
        .alert-box ul { margin: 10px 0; padding-left: 20px; }
        .alert-box li { color: var(--text-light); margin: 5px 0; }
        .loading { text-align: center; padding: 40px; color: var(--purdue-gold); }
        .table-scroll-wrapper {
            max-height: 500px;
            overflow-y: auto;
            border: 2px solid rgba(207,185,145,0.3);
            border-radius: 8px;
            background: rgba(0,0,0,0.3);
        }
        .table-scroll-wrapper::-webkit-scrollbar { width: 12px; }
        .table-scroll-wrapper::-webkit-scrollbar-track { background: rgba(0,0,0,0.5); border-radius: 6px; }
        .table-scroll-wrapper::-webkit-scrollbar-thumb { background: #CFB991; border-radius: 6px; }
        .table-scroll-wrapper::-webkit-scrollbar-thumb:hover { background: #b89968; }
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
        <a href="kpis.php">KPIs</a>
        <a href="disruptions.php" class="active">Disruptions</a>
        <a href="transactions.php">Transactions</a>
        <a href="transaction_costs.php">Cost Analysis</a>
        <a href="distributors.php">Distributors</a>
    </nav>

    <div class="container">
        <h2>Disruption Event Analysis</h2>

        <div class="content-section">
            <h3>Filter Disruptions</h3>
            <form id="filterForm">
                <div class="filter-grid">
                    <div><label>Start Date:</label><input type="date" id="start_date" value="<?= htmlspecialchars($startDate) ?>"></div>
                    <div><label>End Date:</label><input type="date" id="end_date" value="<?= htmlspecialchars($endDate) ?>"></div>
                    <div>
                        <label>Company (Optional):</label>
                        <select id="company_id">
                            <option value="">All Companies</option>
                            <?php foreach ($allCompanies as $c): ?>
                                <option value="<?= $c['CompanyID'] ?>" <?= $companyID == $c['CompanyID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Region (Optional):</label>
                        <select id="region">
                            <option value="">All Regions</option>
                            <?php foreach ($allRegions as $r): ?>
                                <option value="<?= $r['ContinentName'] ?>" <?= $region == $r['ContinentName'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['ContinentName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Tier Level (Optional):</label>
                        <select id="tier">
                            <option value="">All Tiers</option>
                            <option value="1" <?= $tierLevel == '1' ? 'selected' : '' ?>>Tier 1</option>
                            <option value="2" <?= $tierLevel == '2' ? 'selected' : '' ?>>Tier 2</option>
                            <option value="3" <?= $tierLevel == '3' ? 'selected' : '' ?>>Tier 3</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit">Apply Filters</button>
                    <button type="button" id="clearBtn" class="btn-secondary">Clear</button>
                </div>
            </form>
        </div>

        <!-- Key Metrics Cards -->
        <div class="metrics-grid">
            <div class="metric-card">
                <h3 id="metric-total"><?= $totalEvents ?></h3>
                <p>Total Disruptions</p>
                <small>In selected period</small>
            </div>
            <div class="metric-card">
                <h3 id="metric-high" style="color: #f44336;"><?= $highImpactEvents ?></h3>
                <p>High Impact Events</p>
                <small id="metric-hdr"><?= $hdr ?>% of total</small>
            </div>
            <div class="metric-card">
                <h3 id="metric-recovery"><?= $avgRecoveryTime ?></h3>
                <p>Avg Recovery (Days)</p>
                <small>Time to restore operations</small>
            </div>
            <div class="metric-card">
                <h3 id="metric-downtime"><?= $totalDowntime ?></h3>
                <p>Total Downtime (Days)</p>
                <small>Cumulative across all events</small>
            </div>
        </div>

        <!-- Alerts for ongoing/new disruptions -->
        <?php
        // check for recent disruptions (last 7 days)
        $recentSql = "SELECT 
                        de.EventID,
                        de.EventDate,
                        dc.CategoryName,
                        COUNT(DISTINCT ic.AffectedCompanyID) as companiesAffected
                      FROM DisruptionEvent de
                      JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
                      LEFT JOIN ImpactsCompany ic ON de.EventID = ic.EventID
                      WHERE de.EventDate >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                      AND (de.EventRecoveryDate IS NULL OR de.EventRecoveryDate >= CURDATE())
                      GROUP BY de.EventID, de.EventDate, dc.CategoryName
                      ORDER BY de.EventDate DESC
                      LIMIT 5";
        $recentStmt = $pdo->query($recentSql);
        $recentEvents = $recentStmt->fetchAll();
        
        if (count($recentEvents) > 0):
        ?>
        <div class="alert-box">
            <h4>⚠️ Recent/Ongoing Disruptions (Last 7 Days)</h4>
            <ul>
                <?php foreach ($recentEvents as $event): ?>
                <li>
                    <strong><?= htmlspecialchars($event['CategoryName']) ?></strong> on <?= $event['EventDate'] ?>
                    - Affecting <?= $event['companiesAffected'] ?> companies
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Charts Row -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: 20px;">
            <!-- Regional Risk Concentration Heatmap -->
            <div class="chart-container">
                <h3>Regional Risk Concentration (RRC) - Heatmap</h3>
                <div class="chart-wrapper">
                    <canvas id="regionalHeatmap"></canvas>
                </div>
            </div>

            <!-- High-Impact Disruption Rate (HDR) by Category -->
            <div class="chart-container">
                <h3>High-Impact Disruption Rate (HDR) by Category</h3>
                <div class="chart-wrapper">
                    <canvas id="hdrChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Second Charts Row -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: 20px; margin-top: 20px;">
            <!-- Disruption Severity Distribution Chart -->
            <div class="chart-container">
                <h3>Disruption Severity Distribution (DSD)</h3>
                <div class="chart-wrapper">
                    <canvas id="severityChart"></canvas>
                </div>
            </div>

            <!-- Average Recovery Time Histogram -->
            <div class="chart-container">
                <h3>Average Recovery Time Distribution (ART)</h3>
                <div class="chart-wrapper">
                    <canvas id="artHistogram"></canvas>
                </div>
            </div>
        </div>

        <!-- Third Charts Row for TD and DF -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: 20px; margin-top: 20px;">
            <!-- Total Downtime Histogram -->
            <div class="chart-container">
                <h3>Total Downtime Distribution (TD)</h3>
                <div class="chart-wrapper">
                    <canvas id="tdHistogram"></canvas>
                </div>
            </div>

            <!-- Disruption Frequency Bar Chart -->
            <div class="chart-container">
                <h3>Disruption Frequency by Company (DF) - Top 15</h3>
                <div class="chart-wrapper">
                    <canvas id="dfBarChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Company Disruption Frequency Table -->
        <div class="content-section">
            <h3>Disruption Frequency by Company (DF)</h3>
            <div class="table-scroll-wrapper">
                <div id="tableWrapper" style="overflow-x: auto;">
                <?php if (count($companies) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Region</th>
                            <th>Tier Level</th>
                            <th>Disruption Count</th>
                            <th>Frequency Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // calculate time period in months for frequency calc
                        $start = new DateTime($startDate);
                        $end = new DateTime($endDate);
                        $interval = $start->diff($end);
                        $months = ($interval->y * 12) + $interval->m + 1;
                        
                        foreach ($companies as $comp): 
                            $freq = $months > 0 ? round($comp['disruptionCount'] / $months, 2) : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($comp['CompanyName']) ?></strong></td>
                            <td><?= htmlspecialchars($comp['ContinentName']) ?></td>
                            <td>Tier <?= $comp['TierLevel'] ?></td>
                            <td><?= $comp['disruptionCount'] ?></td>
                            <td><?= $freq ?> / month</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="text-align: center; padding: 40px; color: var(--text-light);">No disruption data found for the selected filters.</p>
                <?php endif; ?>
            </div><!-- end tableWrapper -->
            </div><!-- end table-scroll-wrapper -->
        </div>
    </div>

    <script>
    (function() {
        var form = document.getElementById('filterForm');
        var regionalChart = null;
        var severityChart = null;
        
        // load disruption data via ajax
        function load() {
            var params = 'ajax=1&start_date=' + encodeURIComponent(document.getElementById('start_date').value) +
                        '&end_date=' + encodeURIComponent(document.getElementById('end_date').value) +
                        '&company_id=' + encodeURIComponent(document.getElementById('company_id').value) +
                        '&region=' + encodeURIComponent(document.getElementById('region').value) +
                        '&tier=' + encodeURIComponent(document.getElementById('tier').value);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'disruptions.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        var m = r.metrics;
                        
                        // update metric cards
                        document.getElementById('metric-total').textContent = m.totalEvents;
                        document.getElementById('metric-high').textContent = m.highImpactEvents;
                        document.getElementById('metric-hdr').textContent = m.hdr + '% of total';
                        document.getElementById('metric-recovery').textContent = m.avgRecoveryTime + ' days';
                        document.getElementById('metric-downtime').textContent = m.totalDowntime + ' days';
                        
                        // regional chart
                        var regionLabels = [];
                        var regionCounts = [];
                        for (var i = 0; i < m.regions.length; i++) {
                            regionLabels.push(m.regions[i].ContinentName);
                            regionCounts.push(parseInt(m.regions[i].regionDisruptions));
                        }
                        
                        if (regionalChart) regionalChart.destroy();
                        
                        var ctx1 = document.getElementById('regionalChart').getContext('2d');
                        regionalChart = new Chart(ctx1, {
                            type: 'bar',
                            data: {
                                labels: regionLabels,
                                datasets: [{
                                    label: 'Disruptions',
                                    data: regionCounts,
                                    backgroundColor: '#CFB991'
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: { beginAtZero: true, ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } },
                                    x: { ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } }
                                },
                                plugins: { legend: { labels: { color: 'white' } } }
                            }
                        });
                        
                        // severity distribution chart (stacked bar or pie)
                        var severityLabels = [];
                        var severityCounts = [];
                        var severityColors = [];
                        
                        for (var i = 0; i < m.severityDist.length; i++) {
                            var s = m.severityDist[i];
                            severityLabels.push(s.ImpactLevel);
                            severityCounts.push(parseInt(s.eventCount));
                            
                            // color code by severity
                            if (s.ImpactLevel === 'High') {
                                severityColors.push('#f44336');
                            } else if (s.ImpactLevel === 'Medium') {
                                severityColors.push('#ff9800');
                            } else {
                                severityColors.push('#4caf50');
                            }
                        }
                        
                        if (severityChart) severityChart.destroy();
                        
                        var ctx2 = document.getElementById('severityChart').getContext('2d');
                        severityChart = new Chart(ctx2, {
                            type: 'doughnut',
                            data: {
                                labels: severityLabels,
                                datasets: [{
                                    data: severityCounts,
                                    backgroundColor: severityColors
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: { 
                                    legend: { 
                                        labels: { color: 'white' },
                                        position: 'bottom'
                                    } 
                                }
                            }
                        });
                        
                        // rebuild table
                        buildTable(m.companies);
                    }
                }
            };
            xhr.send();
        }
        
        // build the company table
        function buildTable(companies) {
            if (companies.length === 0) {
                document.getElementById('tableWrapper').innerHTML = 
                    '<p style="text-align:center;padding:40px;color:var(--text-light)">No disruption data found.</p>';
                return;
            }
            
            // calculate time period in months
            var start = new Date(document.getElementById('start_date').value);
            var end = new Date(document.getElementById('end_date').value);
            var months = Math.max(1, Math.round((end - start) / (1000 * 60 * 60 * 24 * 30)));
            
            var html = '<table><thead><tr><th>Company</th><th>Region</th><th>Tier Level</th><th>Disruption Count</th><th>Frequency Rate</th></tr></thead><tbody>';
            
            for (var i = 0; i < companies.length; i++) {
                var c = companies[i];
                var freq = (c.disruptionCount / months).toFixed(2);
                
                html += '<tr>' +
                    '<td><strong>' + esc(c.CompanyName) + '</strong></td>' +
                    '<td>' + esc(c.ContinentName) + '</td>' +
                    '<td>Tier ' + c.TierLevel + '</td>' +
                    '<td>' + c.disruptionCount + '</td>' +
                    '<td>' + freq + ' / month</td>' +
                    '</tr>';
            }
            
            document.getElementById('tableWrapper').innerHTML = html + '</tbody></table>';
        }
        
        // utility function
        function esc(t) { 
            if (!t) return '';
            var d = document.createElement('div'); 
            d.textContent = t; 
            return d.innerHTML; 
        }
        
        // event listeners
        form.addEventListener('submit', function(e) { 
            e.preventDefault(); 
            load(); 
            return false;
        });
        
        document.getElementById('clearBtn').addEventListener('click', function() {
            document.getElementById('start_date').value = '<?= date('Y-m-d', strtotime('-1 year')) ?>';
            document.getElementById('end_date').value = '<?= date('Y-m-d') ?>';
            document.getElementById('company_id').value = '';
            document.getElementById('region').value = '';
            document.getElementById('tier').value = '';
            load();
        });
        
        // CRITICAL: Initialize charts on page load with PHP data
        (function initCharts() {
            var heatmapData = <?= json_encode($heatmapData) ?>;
            var hdrData = <?= json_encode($hdrByCategory) ?>;
            var severityData = <?= json_encode($severityDist) ?>;
            
            // Regional Risk Concentration Heatmap (Region x Category matrix)
            // Build matrix structure
            var regions = [];
            var categories = [];
            var matrix = {};
            
            // Extract unique regions and categories
            for (var i = 0; i < heatmapData.length; i++) {
                var region = heatmapData[i].Region;
                var category = heatmapData[i].Category;
                
                if (regions.indexOf(region) === -1) regions.push(region);
                if (categories.indexOf(category) === -1) categories.push(category);
                
                if (!matrix[region]) matrix[region] = {};
                matrix[region][category] = parseInt(heatmapData[i].eventCount);
            }
            
            // Create datasets for stacked bar chart (simulating heatmap)
            var heatmapDatasets = [];
            var categoryColors = {
                'Natural Disaster': '#f44336',
                'Geopolitical': '#ff9800', 
                'Cyber Attack': '#9c27b0',
                'Supply Shortage': '#2196f3',
                'Labor Strike': '#4caf50',
                'Transportation': '#CFB991'
            };
            
            for (var i = 0; i < categories.length; i++) {
                var cat = categories[i];
                var data = [];
                
                for (var j = 0; j < regions.length; j++) {
                    var reg = regions[j];
                    data.push(matrix[reg] && matrix[reg][cat] ? matrix[reg][cat] : 0);
                }
                
                heatmapDatasets.push({
                    label: cat,
                    data: data,
                    backgroundColor: categoryColors[cat] || '#CFB991'
                });
            }
            
            var ctx1 = document.getElementById('regionalHeatmap').getContext('2d');
            regionalChart = new Chart(ctx1, {
                type: 'bar',
                data: {
                    labels: regions,
                    datasets: heatmapDatasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { 
                            stacked: true,
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        y: { 
                            stacked: true,
                            beginAtZero: true,
                            ticks: { color: 'white', stepSize: 1 },
                            grid: { color: 'rgba(207,185,145,0.1)' },
                            title: { display: true, text: 'Number of Disruptions', color: 'white' }
                        }
                    },
                    plugins: { 
                        legend: { 
                            labels: { color: 'white' },
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    var label = context.dataset.label || '';
                                    return label + ': ' + context.parsed.y + ' events';
                                }
                            }
                        }
                    }
                }
            });
            
            // High-Impact Disruption Rate (HDR) by Category
            var hdrLabels = [];
            var hdrRates = [];
            var hdrColors = [];
            
            for (var i = 0; i < hdrData.length; i++) {
                hdrLabels.push(hdrData[i].CategoryName);
                var total = parseInt(hdrData[i].totalEvents);
                var high = parseInt(hdrData[i].highImpactEvents);
                var rate = total > 0 ? (high / total) * 100 : 0;
                hdrRates.push(rate);
                hdrColors.push(rate > 40 ? '#f44336' : (rate > 20 ? '#ff9800' : '#4caf50'));
            }
            
            var ctx2 = document.getElementById('hdrChart').getContext('2d');
            new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: hdrLabels,
                    datasets: [{
                        label: 'High-Impact Rate (%)',
                        data: hdrRates,
                        backgroundColor: hdrColors
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
                            grid: { color: 'rgba(207,185,145,0.1)' },
                            title: { display: true, text: 'Percentage (%)', color: 'white' }
                        },
                        x: { 
                            ticks: { color: 'white', maxRotation: 45, minRotation: 45 },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    },
                    plugins: { 
                        legend: { labels: { color: 'white' } },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'HDR: ' + context.parsed.y.toFixed(1) + '%';
                                }
                            }
                        }
                    }
                }
            });
            
            // Severity Distribution Chart
            var severityLabels = [];
            var severityCounts = [];
            var severityColors = [];
            var colorMap = {'Low': '#4caf50', 'Medium': '#ff9800', 'High': '#f44336'};
            
            for (var i = 0; i < severityData.length; i++) {
                severityLabels.push(severityData[i].ImpactLevel);
                severityCounts.push(parseInt(severityData[i].eventCount));
                severityColors.push(colorMap[severityData[i].ImpactLevel] || '#CFB991');
            }
            
            var ctx2 = document.getElementById('severityChart').getContext('2d');
            severityChart = new Chart(ctx2, {
                type: 'pie',
                data: {
                    labels: severityLabels,
                    datasets: [{
                        data: severityCounts,
                        backgroundColor: severityColors
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { 
                            labels: { color: 'white' },
                            position: 'bottom'
                        } 
                    }
                }
            });
            
            // ART Histogram
            var recoveryData = <?= json_encode($recoveryTimes) ?>;
            if (recoveryData && recoveryData.length > 0) {
                // Create histogram bins
                var bins = [0, 5, 10, 15, 20, 30, 60, 90];
                var binLabels = ['0-5', '5-10', '10-15', '15-20', '20-30', '30-60', '60-90', '90+'];
                var binCounts = new Array(binLabels.length).fill(0);
                
                for (var i = 0; i < recoveryData.length; i++) {
                    var days = parseInt(recoveryData[i]);
                    for (var j = 0; j < bins.length; j++) {
                        if (j === bins.length - 1 || days < bins[j + 1]) {
                            binCounts[j]++;
                            break;
                        }
                    }
                }
                
                var ctx3 = document.getElementById('artHistogram').getContext('2d');
                new Chart(ctx3, {
                    type: 'bar',
                    data: {
                        labels: binLabels,
                        datasets: [{
                            label: 'Number of Events',
                            data: binCounts,
                            backgroundColor: '#CFB991'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                ticks: { color: 'white', stepSize: 1 }, 
                                grid: { color: 'rgba(207,185,145,0.1)' },
                                title: { display: true, text: 'Frequency', color: 'white' }
                            },
                            x: { 
                                ticks: { color: 'white' }, 
                                grid: { color: 'rgba(207,185,145,0.1)' },
                                title: { display: true, text: 'Recovery Days', color: 'white' }
                            }
                        },
                        plugins: { legend: { labels: { color: 'white' } } }
                    }
                });
            }
            
            // TD Histogram (Downtime by Category)
            var downtimeData = <?= json_encode($downtimeByCategory) ?>;
            if (downtimeData && downtimeData.length > 0) {
                var tdLabels = [];
                var tdValues = [];
                
                for (var i = 0; i < downtimeData.length; i++) {
                    tdLabels.push(downtimeData[i].CategoryName);
                    tdValues.push(parseInt(downtimeData[i].totalDowntime));
                }
                
                var ctx4 = document.getElementById('tdHistogram').getContext('2d');
                new Chart(ctx4, {
                    type: 'bar',
                    data: {
                        labels: tdLabels,
                        datasets: [{
                            label: 'Total Downtime (Days)',
                            data: tdValues,
                            backgroundColor: '#f44336'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        scales: {
                            x: { 
                                beginAtZero: true, 
                                ticks: { color: 'white' }, 
                                grid: { color: 'rgba(207,185,145,0.1)' },
                                title: { display: true, text: 'Total Downtime (Days)', color: 'white' }
                            },
                            y: { 
                                ticks: { color: 'white' }, 
                                grid: { color: 'rgba(207,185,145,0.1)' }
                            }
                        },
                        plugins: { legend: { labels: { color: 'white' } } }
                    }
                });
            }
            
            // DF Bar Chart (Disruption Frequency by Company)
            var companyData = <?= json_encode($companies) ?>;
            if (companyData && companyData.length > 0) {
                var companyLabels = [];
                var companyFreq = [];
                var timeMonths = <?= $months ?>;
                
                // Take top 10 companies for readability in grid layout
                var topCompanies = companyData.slice(0, 10);
                
                for (var i = 0; i < topCompanies.length; i++) {
                    companyLabels.push(topCompanies[i].CompanyName);
                    var freq = timeMonths > 0 ? topCompanies[i].disruptionCount / timeMonths : 0;
                    companyFreq.push(freq);
                }
                
                var ctx5 = document.getElementById('dfBarChart').getContext('2d');
                new Chart(ctx5, {
                    type: 'bar',
                    data: {
                        labels: companyLabels,
                        datasets: [{
                            label: 'Disruptions per Month',
                            data: companyFreq,
                            backgroundColor: '#CFB991'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        scales: {
                            x: { 
                                beginAtZero: true, 
                                ticks: { color: 'white' }, 
                                grid: { color: 'rgba(207,185,145,0.1)' },
                                title: { display: true, text: 'Events per Month', color: 'white' }
                            },
                            y: { 
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