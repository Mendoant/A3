<?php
// scm/transaction_costs.php - Transaction Cost Analysis with AJAX
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config.php';
requireLogin();

if (hasRole('SeniorManager')) {
    header('Location: ../erp/dashboard.php');
    exit;
}

$pdo = getPDO();

// Get filters - default to last 6 months
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-6 months'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$companyID = isset($_GET['company_id']) ? $_GET['company_id'] : '';
$companyType = isset($_GET['company_type']) ? $_GET['company_type'] : '';
$region = isset($_GET['region']) ? $_GET['region'] : '';
$tierLevel = isset($_GET['tier']) ? $_GET['tier'] : '';

// Build where clause and joins
$where = array("s.PromisedDate BETWEEN :start AND :end");
$params = array(':start' => $startDate, ':end' => $endDate);
$joins = '';

if (!empty($companyID)) {
    $where[] = "(s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)";
    $params[':companyID'] = $companyID;
}

if (!empty($companyType)) {
    $where[] = "(source.Type = :companyType OR dest.Type = :companyType)";
    $params[':companyType'] = $companyType;
}

// Add JOINs for region and tier filtering
if (!empty($region) || !empty($tierLevel)) {
    $joins = " LEFT JOIN Location srcLoc ON source.LocationID = srcLoc.LocationID
               LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID";
    
    if (!empty($region)) {
        $where[] = "(srcLoc.ContinentName = :region OR destLoc.ContinentName = :region)";
        $params[':region'] = $region;
    }
    
    if (!empty($tierLevel)) {
        $where[] = "(source.TierLevel = :tier OR dest.TierLevel = :tier)";
        $params[':tier'] = $tierLevel;
    }
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Main query: get transaction costs with details
$sql = "SELECT 
            s.ShipmentID,
            s.PromisedDate,
            s.ActualDate,
            s.Quantity,
            p.ProductName,
            p.Category,
            source.CompanyName as SourceCompany,
            dest.CompanyName as DestCompany,
            (s.Quantity * 2.5) as shippingCost,
            CASE 
                WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                ELSE 0 
            END as delayPenalty,
            (s.Quantity * 2.5) + 
            CASE 
                WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                ELSE 0 
            END as totalCost
        FROM Shipping s
        JOIN Product p ON s.ProductID = p.ProductID
        JOIN Company source ON s.SourceCompanyID = source.CompanyID
        JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
        $joins
        $whereClause
        ORDER BY s.PromisedDate DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Summary calculations
$summarySql = "SELECT 
                SUM(s.Quantity * 2.5) as totalShippingCost,
                SUM(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                    THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                    ELSE 0 
                END) as totalDelayPenalty,
                SUM((s.Quantity * 2.5) + 
                    CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                        ELSE 0 
                    END) as totalCost,
                AVG((s.Quantity * 2.5) + 
                    CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                        ELSE 0 
                    END) as avgCostPerShipment,
                MAX((s.Quantity * 2.5) + 
                    CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                        ELSE 0 
                    END) as maxCost,
                SUM(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                    THEN 1 
                    ELSE 0 
                END) as delayedCount
            FROM Shipping s
            JOIN Product p ON s.ProductID = p.ProductID
            JOIN Company source ON s.SourceCompanyID = source.CompanyID
            JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
            $joins
            $whereClause";

$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

$totalShippingCost = floatval($summary['totalShippingCost']);
$totalDelayPenalty = floatval($summary['totalDelayPenalty']);
$totalCost = floatval($summary['totalCost']);
$avgCostPerShipment = floatval($summary['avgCostPerShipment']);
$maxCost = floatval($summary['maxCost']);
$delayedCount = intval($summary['delayedCount']);

// Cost breakdown by category
$categorySql = "SELECT 
                    p.Category,
                    COUNT(DISTINCT s.ShipmentID) as shipmentCount,
                    SUM(s.Quantity * 2.5) as categoryShippingCost,
                    SUM(CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                        ELSE 0 
                    END) as categoryDelayPenalty
                FROM Shipping s
                JOIN Product p ON s.ProductID = p.ProductID
                JOIN Company source ON s.SourceCompanyID = source.CompanyID
                JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
                $joins
                $whereClause
                GROUP BY p.Category
                ORDER BY categoryShippingCost DESC";

$stmt2 = $pdo->prepare($categorySql);
$stmt2->execute($params);
$categoryBreakdown = $stmt2->fetchAll();

// Cost trend over time (monthly aggregation)
$trendSql = "SELECT 
                DATE_FORMAT(s.PromisedDate, '%Y-%m') as month,
                SUM(s.Quantity * 2.5) as monthlyShipping,
                SUM(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                    THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                    ELSE 0 
                END) as monthlyPenalty
             FROM Shipping s
             JOIN Company source ON s.SourceCompanyID = source.CompanyID
             JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
             $joins
             $whereClause
             GROUP BY month
             ORDER BY month ASC";

$stmt3 = $pdo->prepare($trendSql);
$stmt3->execute($params);
$costTrend = $stmt3->fetchAll();

// NEW CHART: Cost by Region
$regionSql = "SELECT 
                COALESCE(srcLoc.ContinentName, 'Unknown') as region,
                SUM(s.Quantity * 2.5) as regionShippingCost,
                SUM(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                    THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                    ELSE 0 
                END) as regionDelayPenalty,
                COUNT(DISTINCT s.ShipmentID) as shipmentCount
              FROM Shipping s
              JOIN Product p ON s.ProductID = p.ProductID
              JOIN Company source ON s.SourceCompanyID = source.CompanyID
              JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
              LEFT JOIN Location srcLoc ON source.LocationID = srcLoc.LocationID
              LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID
              $whereClause
              GROUP BY srcLoc.ContinentName
              ORDER BY regionShippingCost DESC";

$stmtRegion = $pdo->prepare($regionSql);
$stmtRegion->execute($params);
$regionBreakdown = $stmtRegion->fetchAll();

// NEW CHART: Cost by Tier Level
$tierSql = "SELECT 
                CAST(CONCAT('Tier ', source.TierLevel) AS CHAR CHARACTER SET utf8mb4) as tier,
                SUM(s.Quantity * 2.5) as tierShippingCost,
                SUM(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                    THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                    ELSE 0 
                END) as tierDelayPenalty,
                COUNT(DISTINCT s.ShipmentID) as shipmentCount
            FROM Shipping s
            JOIN Product p ON s.ProductID = p.ProductID
            JOIN Company source ON s.SourceCompanyID = source.CompanyID
            JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
            $joins
            $whereClause
            GROUP BY source.TierLevel
            ORDER BY source.TierLevel";

$stmtTier = $pdo->prepare($tierSql);
$stmtTier->execute($params);
$tierBreakdown = $stmtTier->fetchAll();

// NEW CHART: Top 10 Most Expensive Routes
$routeSql = "SELECT 
                CONCAT(CAST(source.CompanyName AS CHAR CHARACTER SET utf8mb4), ' → ', CAST(dest.CompanyName AS CHAR CHARACTER SET utf8mb4)) as route,
                source.CompanyName as sourceCompany,
                dest.CompanyName as destCompany,
                COUNT(DISTINCT s.ShipmentID) as shipmentCount,
                SUM((s.Quantity * 2.5) + 
                    CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                        ELSE 0 
                    END) as routeTotalCost
             FROM Shipping s
             JOIN Product p ON s.ProductID = p.ProductID
             JOIN Company source ON s.SourceCompanyID = source.CompanyID
             JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
             $joins
             $whereClause
             GROUP BY source.CompanyID, dest.CompanyID, source.CompanyName, dest.CompanyName
             ORDER BY routeTotalCost DESC
             LIMIT 10";

$stmtRoute = $pdo->prepare($routeSql);
$stmtRoute->execute($params);
$routeBreakdown = $stmtRoute->fetchAll();

// AJAX response
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => true,
        'data' => array(
            'summary' => array(
                'totalShippingCost' => $totalShippingCost,
                'totalDelayPenalty' => $totalDelayPenalty,
                'totalCost' => $totalCost,
                'avgCostPerShipment' => $avgCostPerShipment,
                'maxCost' => $maxCost,
                'delayedCount' => $delayedCount
            ),
            'charts' => array(
                'category' => $categoryBreakdown,
                'trend' => $costTrend,
                'region' => $regionBreakdown,
                'tier' => $tierBreakdown,
                'route' => $routeBreakdown
            )
        )
    ));
    exit;
}

// Get companies and regions for dropdowns
$companies = $pdo->query("SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName")->fetchAll();
$regions = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cost Analysis - SCM</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0; }
        .metric-card { background: rgba(0,0,0,0.6); padding: 20px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); text-align: center; transition: all 0.3s; }
        .metric-card:hover { border-color: var(--purdue-gold); transform: translateY(-2px); }
        .metric-card h3 { 
            margin: 0; 
            font-size: clamp(1rem, 4vw, 1.6em); 
            color: var(--purdue-gold);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .metric-card p { margin: 8px 0 0 0; color: var(--text-light); font-size: 1rem; }
        
        .chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: 20px; margin: 30px 0; }
        .chart-container { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 12px; border: 2px solid rgba(207,185,145,0.3); }
        .chart-wrapper { position: relative; height: 350px; }
        
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        
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
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Supply Chain Management Portal</h1>
            <nav>
                <span style="color: white;">Welcome, <?php echo htmlspecialchars($_SESSION['FullName']) ?></span>
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
        <a href="transaction_costs.php" class="active">Cost Analysis</a>
        <a href="distributors.php">Distributors</a>
    </nav>

    <div class="container">
        <h2>Transaction Cost Analysis</h2>

        <!-- Filters -->
        <div class="content-section">
            <h3>Filter Options</h3>
            <form id="filterForm">
                <div class="filter-grid">
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Start Date:</label>
                        <input type="date" id="start_date" value="<?php echo $startDate ?>" required style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">End Date:</label>
                        <input type="date" id="end_date" value="<?php echo $endDate ?>" required style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Company (Optional):</label>
                        <select id="company_id" style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                            <option value="">All Companies</option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?php echo $c['CompanyID'] ?>" <?php echo $companyID == $c['CompanyID'] ? 'selected' : '' ?>>
                                    <?php echo htmlspecialchars($c['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Company Type (Optional):</label>
                        <select id="company_type" style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                            <option value="">All Types</option>
                            <option value="Manufacturer" <?php echo $companyType == 'Manufacturer' ? 'selected' : '' ?>>Manufacturer</option>
                            <option value="Distributor" <?php echo $companyType == 'Distributor' ? 'selected' : '' ?>>Distributor</option>
                            <option value="Retailer" <?php echo $companyType == 'Retailer' ? 'selected' : '' ?>>Retailer</option>
                        </select>
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Region (Optional):</label>
                        <select id="region" style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                            <option value="">All Regions</option>
                            <?php foreach ($regions as $r): ?>
                                <option value="<?php echo $r['ContinentName'] ?>" <?php echo $region == $r['ContinentName'] ? 'selected' : '' ?>>
                                    <?php echo htmlspecialchars($r['ContinentName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Tier Level (Optional):</label>
                        <select id="tier" style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                            <option value="">All Tiers</option>
                            <option value="1" <?php echo $tierLevel == '1' ? 'selected' : '' ?>>Tier 1</option>
                            <option value="2" <?php echo $tierLevel == '2' ? 'selected' : '' ?>>Tier 2</option>
                            <option value="3" <?php echo $tierLevel == '3' ? 'selected' : '' ?>>Tier 3</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 15px; display: flex; gap: 10px;">
                    <button type="button" id="clearBtn" class="btn-secondary">Clear Filters</button>
                </div>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="metrics-grid">
            <div class="metric-card">
                <h3 id="metric-total">$<?php echo number_format($totalCost, 2) ?></h3>
                <p>Total Cost</p>
            </div>
            <div class="metric-card">
                <h3 id="metric-shipping">$<?php echo number_format($totalShippingCost, 2) ?></h3>
                <p>Shipping Costs</p>
            </div>
            <div class="metric-card">
                <h3 style="color: #f44336;" id="metric-penalty">$<?php echo number_format($totalDelayPenalty, 2) ?></h3>
                <p>Delay Penalties</p>
            </div>
            <div class="metric-card">
                <h3 id="metric-avg">$<?php echo number_format($avgCostPerShipment, 2) ?></h3>
                <p>Avg Cost/Shipment</p>
            </div>
            <div class="metric-card">
                <h3 style="color: #f44336;" id="metric-delayed"><?php echo $delayedCount ?></h3>
                <p>Delayed Shipments</p>
            </div>
        </div>

        <!-- Charts -->
        <div class="chart-grid">
            <!-- Cost by Category -->
            <div class="chart-container">
                <h3 style="color: var(--purdue-gold); margin-bottom: 15px;">Cost Breakdown by Category</h3>
                <div class="chart-wrapper">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>

            <!-- Cost Trend Over Time -->
            <div class="chart-container">
                <h3 style="color: var(--purdue-gold); margin-bottom: 15px;">Cost Trend Over Time</h3>
                <div class="chart-wrapper">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
            
            <!-- Cost by Region -->
            <div class="chart-container">
                <h3 style="color: var(--purdue-gold); margin-bottom: 15px;">Cost Breakdown by Region</h3>
                <div class="chart-wrapper">
                    <canvas id="regionChart"></canvas>
                </div>
            </div>
            
            <!-- Cost by Tier Level -->
            <div class="chart-container">
                <h3 style="color: var(--purdue-gold); margin-bottom: 15px;">Cost Breakdown by Tier Level</h3>
                <div class="chart-wrapper">
                    <canvas id="tierChart"></canvas>
                </div>
            </div>
            
            <!-- Top 10 Most Expensive Routes -->
            <div class="chart-container" style="grid-column: span 2;">
                <h3 style="color: var(--purdue-gold); margin-bottom: 15px;">Top 10 Most Expensive Routes</h3>
                <div class="chart-wrapper">
                    <canvas id="routeChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        // Chart instances
        var charts = {
            category: null,
            trend: null,
            region: null,
            tier: null,
            route: null
        };
        
        // Load cost data via AJAX
        function loadCostData() {
            var params = 'ajax=1&start_date=' + encodeURIComponent(document.getElementById('start_date').value) +
                        '&end_date=' + encodeURIComponent(document.getElementById('end_date').value) +
                        '&company_id=' + encodeURIComponent(document.getElementById('company_id').value) +
                        '&company_type=' + encodeURIComponent(document.getElementById('company_type').value) +
                        '&region=' + encodeURIComponent(document.getElementById('region').value) +
                        '&tier=' + encodeURIComponent(document.getElementById('tier').value);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'transaction_costs.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        updateSummary(response.data.summary);
                        updateCharts(response.data.charts);
                    }
                }
            };
            xhr.send();
        }
        
        function updateSummary(summary) {
            document.getElementById('metric-total').textContent = '$' + formatCurrency(summary.totalCost);
            document.getElementById('metric-shipping').textContent = '$' + formatCurrency(summary.totalShippingCost);
            document.getElementById('metric-penalty').textContent = '$' + formatCurrency(summary.totalDelayPenalty);
            document.getElementById('metric-avg').textContent = '$' + formatCurrency(summary.avgCostPerShipment);
            document.getElementById('metric-delayed').textContent = summary.delayedCount;
        }
        
        function updateCharts(chartData) {
            // Category Chart
            if (charts.category) charts.category.destroy();
            var categoryLabels = chartData.category.map(function(item) { return item.Category; });
            var shippingCosts = chartData.category.map(function(item) { return parseFloat(item.categoryShippingCost); });
            var penaltyCosts = chartData.category.map(function(item) { return parseFloat(item.categoryDelayPenalty); });
            
            charts.category = new Chart(document.getElementById('categoryChart'), {
                type: 'bar',
                data: {
                    labels: categoryLabels,
                    datasets: [
                        {
                            label: 'Shipping Cost',
                            data: shippingCosts,
                            backgroundColor: '#CFB991',
                            borderColor: '#CFB991',
                            borderWidth: 1
                        },
                        {
                            label: 'Delay Penalty',
                            data: penaltyCosts,
                            backgroundColor: '#f44336',
                            borderColor: '#f44336',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: '#CFB991' } }
                    },
                    scales: {
                        x: {
                            ticks: { color: 'rgba(255,255,255,0.7)' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        y: {
                            ticks: { 
                                color: 'rgba(255,255,255,0.7)',
                                callback: function(value) { return '$' + value.toLocaleString(); }
                            },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    }
                }
            });
            
            // Trend Chart
            if (charts.trend) charts.trend.destroy();
            var trendLabels = chartData.trend.map(function(item) { return item.month; });
            var monthlyShipping = chartData.trend.map(function(item) { return parseFloat(item.monthlyShipping); });
            var monthlyPenalty = chartData.trend.map(function(item) { return parseFloat(item.monthlyPenalty); });
            var monthlyTotal = monthlyShipping.map(function(val, idx) { return val + monthlyPenalty[idx]; });
            
            charts.trend = new Chart(document.getElementById('trendChart'), {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [
                        {
                            label: 'Total Cost',
                            data: monthlyTotal,
                            borderColor: '#CFB991',
                            backgroundColor: 'rgba(207,185,145,0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.3
                        },
                        {
                            label: 'Shipping Cost',
                            data: monthlyShipping,
                            borderColor: '#2196f3',
                            backgroundColor: 'rgba(33,150,243,0.1)',
                            borderWidth: 2,
                            fill: false,
                            tension: 0.3
                        },
                        {
                            label: 'Delay Penalty',
                            data: monthlyPenalty,
                            borderColor: '#f44336',
                            backgroundColor: 'rgba(244,67,54,0.1)',
                            borderWidth: 2,
                            fill: false,
                            tension: 0.3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: '#CFB991' } }
                    },
                    scales: {
                        x: {
                            ticks: { color: 'rgba(255,255,255,0.7)' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        y: {
                            ticks: { 
                                color: 'rgba(255,255,255,0.7)',
                                callback: function(value) { return '$' + value.toLocaleString(); }
                            },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    }
                }
            });
            
            // Region Chart
            if (charts.region) charts.region.destroy();
            var regionLabels = chartData.region.map(function(item) { return item.region; });
            var regionShipping = chartData.region.map(function(item) { return parseFloat(item.regionShippingCost); });
            var regionPenalty = chartData.region.map(function(item) { return parseFloat(item.regionDelayPenalty); });
            
            charts.region = new Chart(document.getElementById('regionChart'), {
                type: 'bar',
                data: {
                    labels: regionLabels,
                    datasets: [
                        {
                            label: 'Shipping Cost',
                            data: regionShipping,
                            backgroundColor: '#CFB991'
                        },
                        {
                            label: 'Delay Penalty',
                            data: regionPenalty,
                            backgroundColor: '#f44336'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: '#CFB991' } }
                    },
                    scales: {
                        x: {
                            ticks: { color: 'rgba(255,255,255,0.7)' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        y: {
                            ticks: { 
                                color: 'rgba(255,255,255,0.7)',
                                callback: function(value) { return '$' + value.toLocaleString(); }
                            },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    }
                }
            });
            
            // Tier Chart
            if (charts.tier) charts.tier.destroy();
            var tierLabels = chartData.tier.map(function(item) { return item.tier; });
            var tierShipping = chartData.tier.map(function(item) { return parseFloat(item.tierShippingCost); });
            var tierPenalty = chartData.tier.map(function(item) { return parseFloat(item.tierDelayPenalty); });
            
            charts.tier = new Chart(document.getElementById('tierChart'), {
                type: 'doughnut',
                data: {
                    labels: tierLabels,
                    datasets: [{
                        label: 'Total Cost',
                        data: tierShipping.map(function(val, idx) { return val + tierPenalty[idx]; }),
                        backgroundColor: ['#CFB991', '#2196f3', '#4caf50']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            labels: { color: '#CFB991' },
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': $' + context.parsed.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            
            // Route Chart
            if (charts.route) charts.route.destroy();
            var routeLabels = chartData.route.map(function(item) { 
                var label = item.route;
                return label.length > 30 ? label.substring(0, 30) + '...' : label;
            });
            var routeCosts = chartData.route.map(function(item) { return parseFloat(item.routeTotalCost); });
            
            charts.route = new Chart(document.getElementById('routeChart'), {
                type: 'bar',
                data: {
                    labels: routeLabels,
                    datasets: [{
                        label: 'Total Cost',
                        data: routeCosts,
                        backgroundColor: '#CFB991'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: { labels: { color: '#CFB991' } }
                    },
                    scales: {
                        x: {
                            ticks: { 
                                color: 'rgba(255,255,255,0.7)',
                                callback: function(value) { return '$' + value.toLocaleString(); }
                            },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        y: {
                            ticks: { color: 'rgba(255,255,255,0.7)' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    }
                }
            });
        }
        
        // Clear button
        document.getElementById('clearBtn').addEventListener('click', function() {
            var today = new Date();
            var sixMonthsAgo = new Date();
            sixMonthsAgo.setMonth(today.getMonth() - 6);
            
            document.getElementById('start_date').value = sixMonthsAgo.toISOString().split('T')[0];
            document.getElementById('end_date').value = today.toISOString().split('T')[0];
            document.getElementById('company_id').value = '';
            document.getElementById('company_type').value = '';
            document.getElementById('region').value = '';
            document.getElementById('tier').value = '';
            
            loadCostData();
        });
        
        // Dynamic filter updates
        document.getElementById('start_date').addEventListener('change', loadCostData);
        document.getElementById('end_date').addEventListener('change', loadCostData);
        document.getElementById('company_id').addEventListener('change', loadCostData);
        document.getElementById('company_type').addEventListener('change', loadCostData);
        document.getElementById('region').addEventListener('change', loadCostData);
        document.getElementById('tier').addEventListener('change', loadCostData);
        
        // Helper function
        function formatCurrency(value) {
            return parseFloat(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        
        // Initialize with PHP data
        (function init() {
            var initialData = {
                summary: {
                    totalShippingCost: <?php echo $totalShippingCost ?>,
                    totalDelayPenalty: <?php echo $totalDelayPenalty ?>,
                    totalCost: <?php echo $totalCost ?>,
                    avgCostPerShipment: <?php echo $avgCostPerShipment ?>,
                    maxCost: <?php echo $maxCost ?>,
                    delayedCount: <?php echo $delayedCount ?>
                },
                charts: {
                    category: <?php echo json_encode($categoryBreakdown) ?>,
                    trend: <?php echo json_encode($costTrend) ?>,
                    region: <?php echo json_encode($regionBreakdown) ?>,
                    tier: <?php echo json_encode($tierBreakdown) ?>,
                    route: <?php echo json_encode($routeBreakdown) ?>
                }
            };
            
            updateCharts(initialData.charts);
        })();
    })();
    </script>
</body>
</html>
