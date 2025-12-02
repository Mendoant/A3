<?php
// scm/transaction_costs.php - Transaction Cost Analysis
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
$productCategory = isset($_GET['category']) ? $_GET['category'] : '';

// Build where clause
$where = array("s.PromisedDate BETWEEN :start AND :end");
$params = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $where[] = "(s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)";
    $params[':companyID'] = $companyID;
}

if (!empty($productCategory)) {
    $where[] = "p.Category = :category";
    $params[':category'] = $productCategory;
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
                THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 50
                ELSE 0 
            END as delayPenalty,
            (s.Quantity * 2.5) + 
            CASE 
                WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 50
                ELSE 0 
            END as totalCost
        FROM Shipping s
        JOIN Product p ON s.ProductID = p.ProductID
        JOIN Company source ON s.SourceCompanyID = source.CompanyID
        JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
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
                    THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 50
                    ELSE 0 
                END) as totalDelayPenalty,
                SUM((s.Quantity * 2.5) + 
                    CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 50
                        ELSE 0 
                    END) as totalCost,
                AVG((s.Quantity * 2.5) + 
                    CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 50
                        ELSE 0 
                    END) as avgCostPerShipment,
                MAX((s.Quantity * 2.5) + 
                    CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 50
                        ELSE 0 
                    END) as maxCost,
                SUM(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                    THEN 1 
                    ELSE 0 
                END) as delayedCount
            FROM Shipping s
            JOIN Product p ON s.ProductID = p.ProductID
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
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 50
                        ELSE 0 
                    END) as categoryDelayPenalty
                FROM Shipping s
                JOIN Product p ON s.ProductID = p.ProductID
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
                    THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 50
                    ELSE 0 
                END) as monthlyPenalty
             FROM Shipping s
             $whereClause
             GROUP BY month
             ORDER BY month ASC";

$stmt3 = $pdo->prepare($trendSql);
$stmt3->execute($params);
$costTrend = $stmt3->fetchAll();

// Get companies and categories for dropdowns
$companies = $pdo->query("SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName")->fetchAll();
$categories = $pdo->query("SELECT DISTINCT Category FROM Product WHERE Category IS NOT NULL ORDER BY Category")->fetchAll();
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
        .metric-card h3 { margin: 0; font-size: 2rem; color: var(--purdue-gold); }
        .metric-card p { margin: 8px 0 0 0; color: var(--text-light); font-size: 0.9rem; }
        
        .chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: 20px; margin: 30px 0; }
        .chart-container { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 12px; border: 2px solid rgba(207,185,145,0.3); }
        .chart-wrapper { position: relative; height: 350px; }
        
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
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
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Start Date:</label>
                        <input type="date" name="start_date" id="start_date" value="<?php echo $startDate ?>" required style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">End Date:</label>
                        <input type="date" name="end_date" id="end_date" value="<?php echo $endDate ?>" required style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Company (Optional):</label>
                        <select name="company_id" id="company_id" style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                            <option value="">All Companies</option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?php echo $c['CompanyID'] ?>" <?php echo $companyID == $c['CompanyID'] ? 'selected' : '' ?>>
                                    <?php echo htmlspecialchars($c['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Category (Optional):</label>
                        <select name="category" id="category" style="padding: 8px; width: 100%; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['Category'] ?>" <?php echo $productCategory == $cat['Category'] ? 'selected' : '' ?>>
                                    <?php echo htmlspecialchars($cat['Category']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 15px; display: flex; gap: 10px;">
                    <button type="submit" class="btn-primary">Apply Filters</button>
                    <button type="button" id="clearBtn" class="btn-secondary">Clear</button>
                </div>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="metrics-grid">
            <div class="metric-card">
                <h3>$<?php echo number_format($totalCost, 2) ?></h3>
                <p>Total Cost</p>
            </div>
            <div class="metric-card">
                <h3>$<?php echo number_format($totalShippingCost, 2) ?></h3>
                <p>Shipping Costs</p>
            </div>
            <div class="metric-card">
                <h3 style="color: #f44336;">$<?php echo number_format($totalDelayPenalty, 2) ?></h3>
                <p>Delay Penalties</p>
            </div>
            <div class="metric-card">
                <h3>$<?php echo number_format($avgCostPerShipment, 2) ?></h3>
                <p>Avg Cost/Shipment</p>
            </div>
            <div class="metric-card">
                <h3 style="color: #f44336;"><?php echo $delayedCount ?></h3>
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
        </div>
    </div>

    <script>
    // Prepare data from PHP
    const categoryData = <?php echo json_encode($categoryBreakdown) ?>;
    const trendData = <?php echo json_encode($costTrend) ?>;

    // Category Chart
    const categoryLabels = categoryData.map(item => item.Category);
    const shippingCosts = categoryData.map(item => parseFloat(item.categoryShippingCost));
    const penaltyCosts = categoryData.map(item => parseFloat(item.categoryDelayPenalty));

    new Chart(document.getElementById('categoryChart'), {
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
                legend: {
                    labels: { color: '#CFB991' }
                }
            },
            scales: {
                x: {
                    ticks: { color: 'rgba(255,255,255,0.7)' },
                    grid: { color: 'rgba(207,185,145,0.1)' }
                },
                y: {
                    ticks: { 
                        color: 'rgba(255,255,255,0.7)',
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    },
                    grid: { color: 'rgba(207,185,145,0.1)' }
                }
            }
        }
    });

    // Trend Chart
    const trendLabels = trendData.map(item => item.month);
    const monthlyShipping = trendData.map(item => parseFloat(item.monthlyShipping));
    const monthlyPenalty = trendData.map(item => parseFloat(item.monthlyPenalty));
    const monthlyTotal = monthlyShipping.map((val, idx) => val + monthlyPenalty[idx]);

    new Chart(document.getElementById('trendChart'), {
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
                legend: {
                    labels: { color: '#CFB991' }
                }
            },
            scales: {
                x: {
                    ticks: { color: 'rgba(255,255,255,0.7)' },
                    grid: { color: 'rgba(207,185,145,0.1)' }
                },
                y: {
                    ticks: { 
                        color: 'rgba(255,255,255,0.7)',
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    },
                    grid: { color: 'rgba(207,185,145,0.1)' }
                }
            }
        }
    });

    // Clear button functionality
    document.getElementById('clearBtn').addEventListener('click', function() {
        var today = new Date();
        var sixMonthsAgo = new Date();
        sixMonthsAgo.setMonth(today.getMonth() - 6);
        
        document.getElementById('start_date').value = sixMonthsAgo.toISOString().split('T')[0];
        document.getElementById('end_date').value = today.toISOString().split('T')[0];
        document.getElementById('company_id').value = '';
        document.getElementById('category').value = '';
        
        document.getElementById('filterForm').submit();
    });
    </script>
</body>
</html>