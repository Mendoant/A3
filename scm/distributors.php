<?php
// scm/distributors.php - Distributor Performance Analysis with AJAX
require_once '../config.php';
requireLogin();

// kick out senior managers - they use the erp module
if (hasRole('SeniorManager')) {
    header('Location: ../erp/dashboard.php');
    exit;
}

$pdo = getPDO();

// Get filters - default to last year of data
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 year'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$distributorID = isset($_GET['distributor_id']) ? $_GET['distributor_id'] : '';
$region = isset($_GET['region']) ? $_GET['region'] : '';
$tierLevel = isset($_GET['tier']) ? $_GET['tier'] : '';

// Build where clause for filtering
$where = array("s.PromisedDate BETWEEN :start AND :end");
$params = array(':start' => $startDate, ':end' => $endDate);
$joins = '';

if (!empty($distributorID)) {
    $where[] = "s.DistributorID = :distributorID";
    $params[':distributorID'] = $distributorID;
}

// Add JOINs for region and tier filtering
if (!empty($region) || !empty($tierLevel)) {
    $joins = " LEFT JOIN Location loc ON c.LocationID = loc.LocationID";
    
    if (!empty($region)) {
        $where[] = "loc.ContinentName = :region";
        $params[':region'] = $region;
    }
    
    if (!empty($tierLevel)) {
        $where[] = "c.TierLevel = :tier";
        $params[':tier'] = $tierLevel;
    }
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Query distributor performance metrics
$sql = "SELECT 
            c.CompanyID,
            c.CompanyName,
            c.TierLevel,
            loc.ContinentName as Region,
            COUNT(DISTINCT s.ShipmentID) as shipmentVolume,
            SUM(s.Quantity) as totalQuantity,
            SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as onTimeCount,
            SUM(CASE WHEN s.ActualDate IS NOT NULL THEN 1 ELSE 0 END) as completedCount,
            AVG(CASE WHEN s.ActualDate > s.PromisedDate THEN DATEDIFF(s.ActualDate, s.PromisedDate) ELSE 0 END) as avgDelay,
            COUNT(DISTINCT p.ProductID) as productDiversity,
            COUNT(DISTINCT CASE WHEN s.ActualDate IS NULL THEN s.ShipmentID END) as inTransitCount,
            COUNT(DISTINCT s.SourceCompanyID) as uniqueSourceCompanies,
            COUNT(DISTINCT s.DestinationCompanyID) as uniqueDestCompanies,
            SUM(s.Quantity * 2.5) as estimatedRevenue
        FROM Shipping s
        JOIN Company c ON s.DistributorID = c.CompanyID
        JOIN Product p ON s.ProductID = p.ProductID
        LEFT JOIN Location loc ON c.LocationID = loc.LocationID
        $whereClause
        GROUP BY c.CompanyID, c.CompanyName, c.TierLevel, loc.ContinentName
        ORDER BY shipmentVolume DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$distributors = $stmt->fetchAll();

// Calculate additional metrics for each distributor
foreach ($distributors as $key => $d) {
    // on-time rate percentage
    $distributors[$key]['onTimeRate'] = $d['completedCount'] > 0 
        ? round(($d['onTimeCount'] / $d['completedCount']) * 100, 1) 
        : 0;
    
    // round the avg delay to 1 decimal place
    $distributors[$key]['avgDelay'] = round($d['avgDelay'], 1);
    
    // calculate disruption exposure for this distributor
    $disruptSql = "SELECT 
                        COUNT(DISTINCT de.EventID) as totalDisruptions,
                        SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpact
                    FROM DisruptionEvent de
                    JOIN ImpactsCompany ic ON de.EventID = ic.EventID
                    WHERE ic.AffectedCompanyID = :companyID
                    AND de.EventDate BETWEEN :start AND :end";
    
    $stmt2 = $pdo->prepare($disruptSql);
    $stmt2->execute(array(
        ':companyID' => $d['CompanyID'],
        ':start' => $startDate,
        ':end' => $endDate
    ));
    $disruption = $stmt2->fetch();
    
    $totalDisrupt = $disruption['totalDisruptions'] ? intval($disruption['totalDisruptions']) : 0;
    $highImpact = $disruption['highImpact'] ? intval($disruption['highImpact']) : 0;
    $distributors[$key]['disruptionExposure'] = $totalDisrupt + (2 * $highImpact);
}

// CHART 1: Shipment Volume by Distributor (Top 10)
$volumeData = array_slice($distributors, 0, 10);

// CHART 2: On-Time Performance Comparison
$performanceData = $distributors;

// CHART 3: Regional Distribution of Shipments
$regionSql = "SELECT 
                loc.ContinentName as region,
                COUNT(DISTINCT s.ShipmentID) as shipmentCount
              FROM Shipping s
              JOIN Company c ON s.DistributorID = c.CompanyID
              LEFT JOIN Location loc ON c.LocationID = loc.LocationID
              $whereClause
              GROUP BY loc.ContinentName
              ORDER BY shipmentCount DESC";

$stmtRegion = $pdo->prepare($regionSql);
$stmtRegion->execute($params);
$regionalData = $stmtRegion->fetchAll();

// CHART 4: Tier Level Distribution
$tierSql = "SELECT 
                c.TierLevel as tier,
                COUNT(DISTINCT s.ShipmentID) as shipmentCount,
                AVG(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate 
                    THEN 100 
                    ELSE 0 
                END) as avgOnTimeRate
            FROM Shipping s
            JOIN Company c ON s.DistributorID = c.CompanyID
            $whereClause
            GROUP BY c.TierLevel
            ORDER BY c.TierLevel";

$stmtTier = $pdo->prepare($tierSql);
$stmtTier->execute($params);
$tierData = $stmtTier->fetchAll();

// CHART 5: Shipment Volume Over Time
$trendSql = "SELECT 
                DATE_FORMAT(s.PromisedDate, '%Y-%m') as month,
                COUNT(DISTINCT s.ShipmentID) as shipmentCount
              FROM Shipping s
              JOIN Company c ON s.DistributorID = c.CompanyID
              $joins
              $whereClause
              GROUP BY month
              ORDER BY month";

$stmtTrend = $pdo->prepare($trendSql);
$stmtTrend->execute($params);
$trendData = $stmtTrend->fetchAll();

// Get shipment status distribution for selected distributor
$statusDist = array();
if (!empty($distributorID)) {
    $sql = "SELECT 
                CASE 
                    WHEN s.ActualDate IS NULL THEN 'In Transit'
                    WHEN s.ActualDate <= s.PromisedDate THEN 'On Time'
                    ELSE 'Delayed'
                END as status,
                COUNT(*) as count
            FROM Shipping s
            WHERE s.DistributorID = :distributorID 
            AND s.PromisedDate BETWEEN :start AND :end
            GROUP BY status";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':distributorID' => $distributorID, ':start' => $startDate, ':end' => $endDate));
    $statusDist = $stmt->fetchAll();
}

// AJAX response
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'distributors' => $distributors,
        'charts' => array(
            'volume' => $volumeData,
            'performance' => $performanceData,
            'regional' => $regionalData,
            'tier' => $tierData,
            'trend' => $trendData,
            'status' => $statusDist
        )
    ));
    exit;
}

// Get all distributors and regions for dropdowns
$allDistributors = $pdo->query("SELECT c.CompanyID, c.CompanyName FROM Company c JOIN Distributor d ON c.CompanyID = d.CompanyID ORDER BY c.CompanyName")->fetchAll();
$allRegions = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Distributors - SCM</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .filter-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); 
            gap: 15px; 
            margin-bottom: 20px; 
        }
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 20px; 
            margin: 30px 0; 
        }
        .stat-card { 
            background: rgba(0,0,0,0.6); 
            padding: 24px; 
            border-radius: 8px; 
            border: 2px solid rgba(207,185,145,0.3); 
            text-align: center; 
            transition: all 0.3s;
        }
        .stat-card:hover {
            border-color: var(--purdue-gold);
            transform: translateY(-2px);
        }
        .stat-card h3 { 
            margin: 0; 
            font-size: clamp(0.9rem, 4vw, 2rem); 
            color: var(--purdue-gold);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .stat-card p { margin: 8px 0 0 0; color: var(--text-light); font-size: 0.9rem; }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .chart-container { 
            background: rgba(0,0,0,0.6); 
            padding: 24px; 
            border-radius: 12px; 
            border: 2px solid rgba(207,185,145,0.3); 
        }
        .chart-container h3 {
            margin: 0 0 15px 0;
            color: var(--purdue-gold);
        }
        .chart-wrapper { 
            position: relative; 
            height: 350px; 
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
        
        .badge { 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 0.85rem; 
            font-weight: bold; 
        }
        .badge-good { background: #4caf50; color: white; }
        .badge-warning { background: #ff9800; color: white; }
        .badge-bad { background: #f44336; color: white; }
        
        table { width: 100%; border-collapse: collapse; }
        th { 
            padding: 12px; 
            text-align: left; 
            color: var(--purdue-gold); 
            font-weight: bold; 
            border-bottom: 2px solid var(--purdue-gold); 
            white-space: nowrap; 
        }
        td { 
            padding: 10px 12px; 
            border-bottom: 1px solid rgba(207,185,145,0.1); 
            color: var(--text-light); 
        }
        tbody tr:hover { 
            background: rgba(207,185,145,0.1); 
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
        <a href="kpis.php">KPIs</a>
        <a href="disruptions.php">Disruptions</a>
        <a href="transactions.php">Transactions</a>
        <a href="transaction_costs.php">Cost Analysis</a>
        <a href="distributors.php" class="active">Distributors</a>
    </nav>

    <div class="container">
        <h2>Distributor Performance Analysis</h2>

        <div class="content-section">
            <h3>Filter Data</h3>
            <form id="filterForm">
                <div class="filter-grid">
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Start Date:</label>
                        <input type="date" id="start_date" value="<?= htmlspecialchars($startDate) ?>" style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">End Date:</label>
                        <input type="date" id="end_date" value="<?= htmlspecialchars($endDate) ?>" style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Distributor (Optional):</label>
                        <select id="distributor_id" style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                            <option value="">All Distributors</option>
                            <?php foreach ($allDistributors as $d): ?>
                                <option value="<?= $d['CompanyID'] ?>" <?= $distributorID == $d['CompanyID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Region (Optional):</label>
                        <select id="region" style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                            <option value="">All Regions</option>
                            <?php foreach ($allRegions as $r): ?>
                                <option value="<?= $r['ContinentName'] ?>" <?= $region == $r['ContinentName'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['ContinentName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Tier Level (Optional):</label>
                        <select id="tier" style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                            <option value="">All Tiers</option>
                            <option value="1" <?= $tierLevel == '1' ? 'selected' : '' ?>>Tier 1</option>
                            <option value="2" <?= $tierLevel == '2' ? 'selected' : '' ?>>Tier 2</option>
                            <option value="3" <?= $tierLevel == '3' ? 'selected' : '' ?>>Tier 3</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 15px; display: flex; gap: 10px;">
                    <button type="button" id="clearBtn" class="btn-secondary">Clear Filters</button>
                </div>
            </form>
        </div>

        <!-- Summary stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3 id="stat-total"><?= count($distributors) ?></h3>
                <p>Active Distributors</p>
            </div>
            <div class="stat-card">
                <h3 id="stat-volume">
                    <?php 
                    $totalVolume = 0;
                    foreach ($distributors as $d) {
                        $totalVolume += $d['shipmentVolume'];
                    }
                    echo number_format($totalVolume);
                    ?>
                </h3>
                <p>Total Shipments</p>
            </div>
            <div class="stat-card">
                <h3 id="stat-avgrate">
                    <?php 
                    if (count($distributors) > 0) {
                        $totalRate = 0;
                        foreach ($distributors as $d) {
                            $totalRate += $d['onTimeRate'];
                        }
                        echo round($totalRate / count($distributors), 1);
                    } else {
                        echo '0';
                    }
                    ?>%
                </h3>
                <p>Avg On-Time Rate</p>
            </div>
            <div class="stat-card">
                <h3 id="stat-revenue">
                    $<?php 
                    $totalRevenue = 0;
                    foreach ($distributors as $d) {
                        $totalRevenue += $d['estimatedRevenue'];
                    }
                    echo number_format($totalRevenue, 2);
                    ?>
                </h3>
                <p>Est. Total Revenue</p>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <!-- Shipment Volume by Distributor -->
            <div class="chart-container">
                <h3>Top 10 Distributors by Volume</h3>
                <div class="chart-wrapper">
                    <canvas id="volumeChart"></canvas>
                </div>
            </div>
            
            <!-- Regional Distribution -->
            <div class="chart-container">
                <h3>Shipments by Region</h3>
                <div class="chart-wrapper">
                    <canvas id="regionalChart"></canvas>
                </div>
            </div>
            
            <!-- Tier Performance -->
            <div class="chart-container">
                <h3>Performance by Tier Level</h3>
                <div class="chart-wrapper">
                    <canvas id="tierChart"></canvas>
                </div>
            </div>
            
            <!-- Status Distribution (only if specific distributor selected) -->
            <div class="chart-container" id="statusChartContainer" style="<?= empty($distributorID) ? 'display: none;' : '' ?>">
                <h3>Shipment Status Distribution</h3>
                <div class="chart-wrapper">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            
            <!-- Shipment Trend Over Time -->
            <div class="chart-container" style="grid-column: span 2;">
                <h3>Shipment Volume Trend</h3>
                <div class="chart-wrapper">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Distributor performance table -->
        <div class="content-section">
            <h3>Distributor Rankings (<span id="recordCount"><?= count($distributors) ?></span> distributors)</h3>
            <div id="tableWrapper" style="overflow-x: auto;">
                <?php if (count($distributors) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Distributor</th>
                            <th>Region</th>
                            <th>Tier</th>
                            <th>Shipments</th>
                            <th>Total Quantity</th>
                            <th>On-Time Rate</th>
                            <th>Avg Delay</th>
                            <th>Products</th>
                            <th>Routes</th>
                            <th>In Transit</th>
                            <th>Disruption Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($distributors as $dist): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($dist['CompanyName']) ?></strong></td>
                            <td><?= htmlspecialchars($dist['Region'] ?: 'Unknown') ?></td>
                            <td>Tier <?= $dist['TierLevel'] ?></td>
                            <td><?= number_format($dist['shipmentVolume']) ?></td>
                            <td><?= number_format($dist['totalQuantity']) ?></td>
                            <td>
                                <?php 
                                $badgeClass = 'badge-bad';
                                if ($dist['onTimeRate'] >= 90) {
                                    $badgeClass = 'badge-good';
                                } elseif ($dist['onTimeRate'] >= 75) {
                                    $badgeClass = 'badge-warning';
                                }
                                ?>
                                <span class="badge <?= $badgeClass ?>">
                                    <?= $dist['onTimeRate'] ?>%
                                </span>
                            </td>
                            <td><?= $dist['avgDelay'] ?> days</td>
                            <td><?= $dist['productDiversity'] ?></td>
                            <td><?= $dist['uniqueSourceCompanies'] + $dist['uniqueDestCompanies'] ?></td>
                            <td><?= $dist['inTransitCount'] ?></td>
                            <td><?= $dist['disruptionExposure'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="text-align: center; padding: 40px; color: var(--text-light);">No distributor data found for the selected filters.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var charts = {
            volume: null,
            regional: null,
            tier: null,
            status: null,
            trend: null
        };
        
        // Load distributor data via AJAX
        function loadData() {
            var params = 'ajax=1&start_date=' + encodeURIComponent(document.getElementById('start_date').value) +
                        '&end_date=' + encodeURIComponent(document.getElementById('end_date').value) +
                        '&distributor_id=' + encodeURIComponent(document.getElementById('distributor_id').value) +
                        '&region=' + encodeURIComponent(document.getElementById('region').value) +
                        '&tier=' + encodeURIComponent(document.getElementById('tier').value);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'distributors.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        updateSummary(r.distributors);
                        updateTable(r.distributors);
                        updateCharts(r.charts);
                    }
                }
            };
            xhr.send();
        }
        
        function updateSummary(dists) {
            document.getElementById('stat-total').textContent = dists.length;
            
            var totalVolume = 0;
            var totalRate = 0;
            var totalRevenue = 0;
            
            for (var i = 0; i < dists.length; i++) {
                totalVolume += parseInt(dists[i].shipmentVolume);
                totalRate += parseFloat(dists[i].onTimeRate);
                totalRevenue += parseFloat(dists[i].estimatedRevenue);
            }
            
            document.getElementById('stat-volume').textContent = num(totalVolume);
            
            var avgRate = dists.length > 0 ? (totalRate / dists.length).toFixed(1) : 0;
            document.getElementById('stat-avgrate').textContent = avgRate + '%';
            
            document.getElementById('stat-revenue').textContent = '$' + totalRevenue.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        
        function updateTable(dists) {
            document.getElementById('recordCount').textContent = dists.length;
            
            if (dists.length === 0) {
                document.getElementById('tableWrapper').innerHTML = 
                    '<p style="text-align:center;padding:40px;color:var(--text-light)">No distributor data found.</p>';
                return;
            }
            
            var html = '<table><thead><tr><th>Distributor</th><th>Region</th><th>Tier</th><th>Shipments</th><th>Total Quantity</th><th>On-Time Rate</th><th>Avg Delay</th><th>Products</th><th>Routes</th><th>In Transit</th><th>Disruption Score</th></tr></thead><tbody>';
            
            for (var i = 0; i < dists.length; i++) {
                var d = dists[i];
                var badgeClass = 'badge-bad';
                if (d.onTimeRate >= 90) {
                    badgeClass = 'badge-good';
                } else if (d.onTimeRate >= 75) {
                    badgeClass = 'badge-warning';
                }
                
                var routes = parseInt(d.uniqueSourceCompanies) + parseInt(d.uniqueDestCompanies);
                
                html += '<tr>' +
                    '<td><strong>' + esc(d.CompanyName) + '</strong></td>' +
                    '<td>' + esc(d.Region || 'Unknown') + '</td>' +
                    '<td>Tier ' + d.TierLevel + '</td>' +
                    '<td>' + num(d.shipmentVolume) + '</td>' +
                    '<td>' + num(d.totalQuantity) + '</td>' +
                    '<td><span class="badge ' + badgeClass + '">' + d.onTimeRate + '%</span></td>' +
                    '<td>' + d.avgDelay + ' days</td>' +
                    '<td>' + d.productDiversity + '</td>' +
                    '<td>' + routes + '</td>' +
                    '<td>' + d.inTransitCount + '</td>' +
                    '<td>' + d.disruptionExposure + '</td>' +
                    '</tr>';
            }
            
            document.getElementById('tableWrapper').innerHTML = html + '</tbody></table>';
        }
        
        function updateCharts(chartData) {
            // Volume Chart
            if (charts.volume) charts.volume.destroy();
            var volumeLabels = chartData.volume.map(function(d) { return d.CompanyName; });
            var volumeCounts = chartData.volume.map(function(d) { return parseInt(d.shipmentVolume); });
            
            charts.volume = new Chart(document.getElementById('volumeChart'), {
                type: 'bar',
                data: {
                    labels: volumeLabels,
                    datasets: [{
                        label: 'Shipments',
                        data: volumeCounts,
                        backgroundColor: '#CFB991'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: { legend: { labels: { color: 'white' } } },
                    scales: {
                        x: {
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        y: {
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    }
                }
            });
            
            // Regional Chart
            if (charts.regional) charts.regional.destroy();
            var regionLabels = chartData.regional.map(function(d) { return d.region || 'Unknown'; });
            var regionCounts = chartData.regional.map(function(d) { return parseInt(d.shipmentCount); });
            
            charts.regional = new Chart(document.getElementById('regionalChart'), {
                type: 'pie',
                data: {
                    labels: regionLabels,
                    datasets: [{
                        data: regionCounts,
                        backgroundColor: ['#CFB991', '#2196f3', '#4caf50', '#ff9800', '#9c27b0', '#f44336']
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
            
            // Tier Chart
            if (charts.tier) charts.tier.destroy();
            var tierLabels = chartData.tier.map(function(d) { return 'Tier ' + d.tier; });
            var tierCounts = chartData.tier.map(function(d) { return parseInt(d.shipmentCount); });
            var tierRates = chartData.tier.map(function(d) { return parseFloat(d.avgOnTimeRate).toFixed(1); });
            
            charts.tier = new Chart(document.getElementById('tierChart'), {
                type: 'bar',
                data: {
                    labels: tierLabels,
                    datasets: [{
                        label: 'Shipment Count',
                        data: tierCounts,
                        backgroundColor: '#CFB991',
                        yAxisID: 'y'
                    }, {
                        label: 'Avg On-Time Rate (%)',
                        data: tierRates,
                        backgroundColor: '#4caf50',
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: 'white' } } },
                    scales: {
                        y: {
                            type: 'linear',
                            position: 'left',
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        y1: {
                            type: 'linear',
                            position: 'right',
                            max: 100,
                            ticks: { color: 'white' },
                            grid: { display: false }
                        },
                        x: {
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    }
                }
            });
            
            // Status Chart (only if specific distributor selected)
            if (chartData.status.length > 0) {
                document.getElementById('statusChartContainer').style.display = 'block';
                
                if (charts.status) charts.status.destroy();
                
                var statusLabels = chartData.status.map(function(d) { return d.status; });
                var statusCounts = chartData.status.map(function(d) { return parseInt(d.count); });
                var statusColors = statusLabels.map(function(status) {
                    if (status === 'On Time') return '#4caf50';
                    if (status === 'Delayed') return '#f44336';
                    return '#ff9800';
                });
                
                charts.status = new Chart(document.getElementById('statusChart'), {
                    type: 'doughnut',
                    data: {
                        labels: statusLabels,
                        datasets: [{
                            data: statusCounts,
                            backgroundColor: statusColors
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
            } else {
                document.getElementById('statusChartContainer').style.display = 'none';
            }
            
            // Trend Chart
            if (charts.trend) charts.trend.destroy();
            var trendLabels = chartData.trend.map(function(d) { return d.month; });
            var trendCounts = chartData.trend.map(function(d) { return parseInt(d.shipmentCount); });
            
            charts.trend = new Chart(document.getElementById('trendChart'), {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [{
                        label: 'Shipments',
                        data: trendCounts,
                        borderColor: '#CFB991',
                        backgroundColor: 'rgba(207,185,145,0.2)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: 'white' } } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        x: {
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    }
                }
            });
        }
        
        // Utility functions
        function esc(t) { 
            if (!t) return '';
            var d = document.createElement('div'); 
            d.textContent = t; 
            return d.innerHTML; 
        }
        
        function num(n) { 
            return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); 
        }
        
        // Clear button
        document.getElementById('clearBtn').addEventListener('click', function() {
            document.getElementById('start_date').value = '<?= date('Y-m-d', strtotime('-1 year')) ?>';
            document.getElementById('end_date').value = '<?= date('Y-m-d') ?>';
            document.getElementById('distributor_id').value = '';
            document.getElementById('region').value = '';
            document.getElementById('tier').value = '';
            loadData();
        });
        
        // Dynamic filter updates
        document.getElementById('start_date').addEventListener('change', loadData);
        document.getElementById('end_date').addEventListener('change', loadData);
        document.getElementById('distributor_id').addEventListener('change', loadData);
        document.getElementById('region').addEventListener('change', loadData);
        document.getElementById('tier').addEventListener('change', loadData);
        
        // Initialize with PHP data
        (function init() {
            var initialData = {
                distributors: <?php echo json_encode($distributors) ?>,
                charts: {
                    volume: <?php echo json_encode($volumeData) ?>,
                    performance: <?php echo json_encode($performanceData) ?>,
                    regional: <?php echo json_encode($regionalData) ?>,
                    tier: <?php echo json_encode($tierData) ?>,
                    trend: <?php echo json_encode($trendData) ?>,
                    status: <?php echo json_encode($statusDist) ?>
                }
            };
            
            updateCharts(initialData.charts);
        })();
    })();
    </script>
</body>
</html>
