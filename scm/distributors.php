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

// Build where clause for filtering
$where = array("s.PromisedDate BETWEEN :start AND :end");
$params = array(':start' => $startDate, ':end' => $endDate);

if (!empty($distributorID)) {
    $where[] = "s.DistributorID = :distributorID";
    $params[':distributorID'] = $distributorID;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Query distributor performance metrics
// this gets all the key stats we need for each distributor
$sql = "SELECT 
            c.CompanyID,
            c.CompanyName,
            COUNT(DISTINCT s.ShipmentID) as shipmentVolume,
            SUM(s.Quantity) as totalQuantity,
            SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as onTimeCount,
            SUM(CASE WHEN s.ActualDate IS NOT NULL THEN 1 ELSE 0 END) as completedCount,
            AVG(CASE WHEN s.ActualDate > s.PromisedDate THEN DATEDIFF(s.ActualDate, s.PromisedDate) ELSE 0 END) as avgDelay,
            COUNT(DISTINCT p.ProductID) as productDiversity,
            COUNT(DISTINCT CASE WHEN s.ActualDate IS NULL THEN s.ShipmentID END) as inTransitCount
        FROM Shipping s
        JOIN Company c ON s.DistributorID = c.CompanyID
        JOIN Product p ON s.ProductID = p.ProductID
        $whereClause
        GROUP BY c.CompanyID, c.CompanyName
        ORDER BY shipmentVolume DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$distributors = $stmt->fetchAll();

// Calculate additional metrics for each distributor
// doing this in php instead of sql because its easier to read/debug
foreach ($distributors as $key => $d) {
    // on-time rate percentage
    $distributors[$key]['onTimeRate'] = $d['completedCount'] > 0 
        ? round(($d['onTimeCount'] / $d['completedCount']) * 100, 1) 
        : 0;
    
    // round the avg delay to 1 decimal place
    $distributors[$key]['avgDelay'] = round($d['avgDelay'], 1);
    
    // calculate disruption exposure for this distributor
    // formula: total disruptions + (2 * high impact disruptions)
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

// Get shipment status distribution for selected distributor
// this powers the pie chart
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

// AJAX response - return json if this is an ajax call
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'distributors' => $distributors,
        'statusDistribution' => $statusDist
    ));
    exit;
}

// Get all distributors for dropdown (only needed on initial page load)
$allDistributors = $pdo->query("SELECT c.CompanyID, c.CompanyName FROM Company c JOIN Distributor d ON c.CompanyID = d.CompanyID ORDER BY c.CompanyName")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Distributors - SCM</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0; }
        .stat-card { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); text-align: center; }
        .stat-card h3 { margin: 0; font-size: 2rem; color: var(--purdue-gold); }
        .stat-card p { margin: 8px 0 0 0; color: var(--text-light); }
        .chart-container { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 12px; border: 2px solid rgba(207,185,145,0.3); margin: 20px 0; }
        .chart-wrapper { position: relative; height: 350px; }
        .loading { text-align: center; padding: 40px; color: var(--purdue-gold); }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; font-weight: bold; }
        .badge-good { background: #4caf50; color: white; }
        .badge-warning { background: #ff9800; color: white; }
        .badge-bad { background: #f44336; color: white; }
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
                    <div><label>Start Date:</label><input type="date" id="start_date" value="<?= htmlspecialchars($startDate) ?>"></div>
                    <div><label>End Date:</label><input type="date" id="end_date" value="<?= htmlspecialchars($endDate) ?>"></div>
                    <div>
                        <label>Distributor (Optional):</label>
                        <select id="distributor_id">
                            <option value="">All Distributors</option>
                            <?php foreach ($allDistributors as $d): ?>
                                <option value="<?= $d['CompanyID'] ?>" <?= $distributorID == $d['CompanyID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit">Apply Filters</button>
                    <button type="button" id="clearBtn" class="btn-secondary">Clear</button>
                </div>
            </form>
        </div>

        <!-- summary stats at the top -->
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
        </div>

        <!-- distributor performance table -->
        <div class="content-section">
            <h3>Distributor Rankings (<span id="recordCount"><?= count($distributors) ?></span> distributors)</h3>
            <div id="tableWrapper" style="overflow-x: auto;">
                <?php if (count($distributors) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Distributor</th>
                            <th>Shipment Volume</th>
                            <th>Total Quantity</th>
                            <th>On-Time Rate</th>
                            <th>Avg Delay (Days)</th>
                            <th>Products Handled</th>
                            <th>In Transit</th>
                            <th>Disruption Exposure</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($distributors as $dist): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($dist['CompanyName']) ?></strong></td>
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

        <!-- status distribution chart - only show if specific distributor selected -->
        <div id="chartsSection" style="<?= empty($distributorID) ? 'display: none;' : '' ?>">
            <div class="chart-container">
                <h3>Shipment Status Distribution</h3>
                <div class="chart-wrapper">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var form = document.getElementById('filterForm');
        var statusChart = null; // keep reference so we can destroy it later
        
        // load distributor data via ajax
        function load() {
            document.getElementById('tableWrapper').innerHTML = '<div class="loading">Loading...</div>';
            
            var params = 'ajax=1&start_date=' + encodeURIComponent(document.getElementById('start_date').value) +
                        '&end_date=' + encodeURIComponent(document.getElementById('end_date').value) +
                        '&distributor_id=' + encodeURIComponent(document.getElementById('distributor_id').value);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'distributors.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var r = JSON.parse(xhr.responseText);
                    if (r.success) {
                        var dists = r.distributors;
                        
                        // update summary stats
                        document.getElementById('stat-total').textContent = dists.length;
                        
                        var totalVolume = 0;
                        var totalRate = 0;
                        for (var i = 0; i < dists.length; i++) {
                            totalVolume += parseInt(dists[i].shipmentVolume);
                            totalRate += parseFloat(dists[i].onTimeRate);
                        }
                        
                        document.getElementById('stat-volume').textContent = num(totalVolume);
                        
                        var avgRate = dists.length > 0 ? (totalRate / dists.length).toFixed(1) : 0;
                        document.getElementById('stat-avgrate').textContent = avgRate + '%';
                        
                        // build table
                        document.getElementById('recordCount').textContent = dists.length;
                        
                        if (dists.length === 0) {
                            document.getElementById('tableWrapper').innerHTML = 
                                '<p style="text-align:center;padding:40px;color:var(--text-light)">No distributor data found.</p>';
                        } else {
                            var html = '<table><thead><tr><th>Distributor</th><th>Shipment Volume</th><th>Total Quantity</th><th>On-Time Rate</th><th>Avg Delay (Days)</th><th>Products Handled</th><th>In Transit</th><th>Disruption Exposure</th></tr></thead><tbody>';
                            
                            for (var i = 0; i < dists.length; i++) {
                                var d = dists[i];
                                var badgeClass = 'badge-bad';
                                if (d.onTimeRate >= 90) {
                                    badgeClass = 'badge-good';
                                } else if (d.onTimeRate >= 75) {
                                    badgeClass = 'badge-warning';
                                }
                                
                                html += '<tr>' +
                                    '<td><strong>' + esc(d.CompanyName) + '</strong></td>' +
                                    '<td>' + num(d.shipmentVolume) + '</td>' +
                                    '<td>' + num(d.totalQuantity) + '</td>' +
                                    '<td><span class="badge ' + badgeClass + '">' + d.onTimeRate + '%</span></td>' +
                                    '<td>' + d.avgDelay + ' days</td>' +
                                    '<td>' + d.productDiversity + '</td>' +
                                    '<td>' + d.inTransitCount + '</td>' +
                                    '<td>' + d.disruptionExposure + '</td>' +
                                    '</tr>';
                            }
                            
                            document.getElementById('tableWrapper').innerHTML = html + '</tbody></table>';
                        }
                        
                        // status distribution chart (only if specific distributor selected)
                        if (r.statusDistribution.length > 0) {
                            document.getElementById('chartsSection').style.display = 'block';
                            
                            var labels = [];
                            var counts = [];
                            var colors = [];
                            
                            for (var i = 0; i < r.statusDistribution.length; i++) {
                                var s = r.statusDistribution[i];
                                labels.push(s.status);
                                counts.push(parseInt(s.count));
                                
                                // color code based on status
                                if (s.status === 'On Time') {
                                    colors.push('#4caf50');
                                } else if (s.status === 'Delayed') {
                                    colors.push('#f44336');
                                } else {
                                    colors.push('#ff9800'); // in transit
                                }
                            }
                            
                            // destroy old chart if exists
                            if (statusChart) statusChart.destroy();
                            
                            var ctx = document.getElementById('statusChart').getContext('2d');
                            statusChart = new Chart(ctx, {
                                type: 'doughnut',
                                data: {
                                    labels: labels,
                                    datasets: [{
                                        data: counts,
                                        backgroundColor: colors
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
                            document.getElementById('chartsSection').style.display = 'none';
                        }
                    }
                }
            };
            xhr.send();
        }
        
        // utility functions
        function esc(t) { 
            if (!t) return '';
            var d = document.createElement('div'); 
            d.textContent = t; 
            return d.innerHTML; 
        }
        
        function num(n) { 
            return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); 
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
            document.getElementById('distributor_id').value = '';
            load();
        });
        
    })();
    </script>
</body>
</html>