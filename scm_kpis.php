<?php
// scm_kpis.php - Key Performance Indicators
require_once 'config.php';
requireLogin();

// Redirect Senior Managers
if (hasRole('SeniorManager')) {
    header('Location: dashboard_erp.php');
    exit;
}

$pdo = getPDO();

// Get filter parameters
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-90 days')); // Last 90 days
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$companyFilter = $_GET['company_id'] ?? 'all';

// Build WHERE clause
$whereConditions = ["s.PromisedDate BETWEEN :startDate AND :endDate"];
$params = [':startDate' => $startDate, ':endDate' => $endDate];

if ($companyFilter !== 'all') {
    $whereConditions[] = "(s.SourceCompanyID = :companyId OR s.DestinationCompanyID = :companyId)";
    $params[':companyId'] = $companyFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// 1. On-Time Delivery Rate
$onTimeSQL = "SELECT 
                COUNT(*) as total_delivered,
                SUM(CASE WHEN ActualDate <= PromisedDate THEN 1 ELSE 0 END) as on_time_count
              FROM Shipping s
              $whereClause AND ActualDate IS NOT NULL";
$stmt = $pdo->prepare($onTimeSQL);
$stmt->execute($params);
$onTimeData = $stmt->fetch();

$totalDelivered = $onTimeData['total_delivered'] ?? 0;
$onTimeCount = $onTimeData['on_time_count'] ?? 0;
$onTimeRate = $totalDelivered > 0 ? round(($onTimeCount / $totalDelivered) * 100, 2) : 0;

// 2. Average Delay and Standard Deviation
$delaySQL = "SELECT 
                AVG(DATEDIFF(ActualDate, PromisedDate)) as avg_delay,
                STDDEV(DATEDIFF(ActualDate, PromisedDate)) as std_delay,
                MIN(DATEDIFF(ActualDate, PromisedDate)) as min_delay,
                MAX(DATEDIFF(ActualDate, PromisedDate)) as max_delay
             FROM Shipping s
             $whereClause AND ActualDate IS NOT NULL";
$stmt = $pdo->prepare($delaySQL);
$stmt->execute($params);
$delayData = $stmt->fetch();

$avgDelay = round($delayData['avg_delay'] ?? 0, 2);
$stdDelay = round($delayData['std_delay'] ?? 0, 2);
$minDelay = $delayData['min_delay'] ?? 0;
$maxDelay = $delayData['max_delay'] ?? 0;

// 3. Financial Health Status - Recent Quarter
$financialSQL = "SELECT 
                    c.CompanyID,
                    c.CompanyName,
                    f.HealthScore,
                    f.Quarter,
                    f.RepYear
                 FROM Company c
                 LEFT JOIN FinancialReport f ON c.CompanyID = f.CompanyID
                 WHERE (f.RepYear, f.Quarter) = (
                     SELECT RepYear, Quarter 
                     FROM FinancialReport 
                     ORDER BY RepYear DESC, 
                              FIELD(Quarter, 'Q4', 'Q3', 'Q2', 'Q1') ASC 
                     LIMIT 1
                 )
                 ORDER BY f.HealthScore DESC
                 LIMIT 10";
$stmt = $pdo->query($financialSQL);
$topCompanies = $stmt->fetchAll();

// 4. Company Performance Breakdown
$companyKPISQL = "SELECT 
                    c.CompanyID,
                    c.CompanyName,
                    c.Type,
                    COUNT(s.ShipmentID) as total_shipments,
                    SUM(CASE WHEN s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as on_time,
                    AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) as avg_delay,
                    (SUM(CASE WHEN s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) / COUNT(s.ShipmentID) * 100) as on_time_rate
                  FROM Company c
                  JOIN Shipping s ON (c.CompanyID = s.SourceCompanyID OR c.CompanyID = s.DestinationCompanyID)
                  $whereClause AND s.ActualDate IS NOT NULL
                  GROUP BY c.CompanyID
                  HAVING total_shipments >= 1
                  ORDER BY on_time_rate DESC, total_shipments DESC
                  LIMIT 15";
$stmt = $pdo->prepare($companyKPISQL);
$stmt->execute($params);
$companyPerformance = $stmt->fetchAll();

// 5. Monthly Trend (last 6 months)
$trendSQL = "SELECT 
                DATE_FORMAT(s.PromisedDate, '%Y-%m') as month,
                COUNT(*) as total_shipments,
                SUM(CASE WHEN s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as on_time,
                AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) as avg_delay
             FROM Shipping s
             WHERE s.PromisedDate >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                   AND s.ActualDate IS NOT NULL
             GROUP BY month
             ORDER BY month DESC";
$stmt = $pdo->query($trendSQL);
$monthlyTrend = $stmt->fetchAll();

// Get all companies for dropdown
$companiesSql = "SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName";
$companiesStmt = $pdo->query($companiesSql);
$companies = $companiesStmt->fetchAll();

// Calculate overall statistics
$totalShipments = $totalDelivered;
$inTransitSQL = "SELECT COUNT(*) as in_transit FROM Shipping s $whereClause AND ActualDate IS NULL";
$stmt = $pdo->prepare($inTransitSQL);
$stmt->execute($params);
$inTransit = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Key Performance Indicators - SCM</title>
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        .nav-bar {
            background: rgba(0, 0, 0, 0.8);
            padding: 15px 30px;
            margin-bottom: 30px;
            border-radius: 8px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .nav-bar a {
            color: var(--purdue-gold);
            text-decoration: none;
            padding: 10px 20px;
            border: 2px solid var(--purdue-gold);
            border-radius: 5px;
            transition: all 0.3s;
        }
        .nav-bar a:hover, .nav-bar a.active {
            background: var(--purdue-gold);
            color: var(--purdue-black);
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }
        .kpi-card {
            background: rgba(0, 0, 0, 0.6);
            padding: 32px;
            border-radius: 12px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            text-align: center;
            transition: all 0.3s;
        }
        .kpi-card:hover {
            border-color: var(--purdue-gold);
            transform: translateY(-5px);
            box-shadow: 0 12px 32px rgba(207, 185, 145, 0.3);
        }
        .kpi-card .kpi-value {
            font-size: 3rem;
            font-weight: bold;
            color: var(--purdue-gold);
            margin: 16px 0;
        }
        .kpi-card .kpi-label {
            font-size: 1.1rem;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .kpi-card .kpi-subtitle {
            font-size: 0.9rem;
            color: var(--purdue-gold-dark);
            margin-top: 8px;
        }
        .filter-section {
            background: rgba(0, 0, 0, 0.6);
            padding: 30px;
            border-radius: 12px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            margin-bottom: 30px;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .filter-buttons {
            margin-top: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .btn-reset {
            background: transparent;
            color: var(--purdue-gold);
            border: 2px solid var(--purdue-gold);
            padding: 14px 32px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: inline-block;
            font-family: 'Inter', Arial, sans-serif;
        }
        .btn-reset:hover {
            background: var(--purdue-gold);
            color: var(--purdue-black);
            box-shadow: 0 6px 20px rgba(207, 185, 145, 0.4);
        }
        .trend-card {
            background: rgba(207, 185, 145, 0.1);
            padding: 16px;
            margin: 12px 0;
            border-radius: 8px;
            border-left: 4px solid var(--purdue-gold);
        }
        .good-performance {
            color: #4caf50;
            font-weight: bold;
        }
        .poor-performance {
            color: #f44336;
            font-weight: bold;
        }
        .average-performance {
            color: #ff9800;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Supply Chain Management Portal</h1>
            <nav>
                <span style="color: white;">Welcome, <?= htmlspecialchars($_SESSION['FullName']) ?></span>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <div class="nav-bar container">
        <a href="dashboard_scm.php">Dashboard</a>
        <a href="scm_company_info.php">Companies</a>
        <a href="scm_kpis.php" class="active">KPIs</a>
        <a href="scm_disruptions.php">Disruptions</a>
        <a href="scm_transactions.php">Transactions</a>
        <a href="scm_distributors.php">Distributors</a>
    </div>

    <div class="container">
        <h2>Key Performance Indicators</h2>

        <!-- Filters -->
        <div class="filter-section">
            <h3>Filter KPIs</h3>
            <form method="GET" action="scm_kpis.php">
                <div class="filter-grid">
                    <div>
                        <label for="start_date">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    
                    <div>
                        <label for="end_date">End Date:</label>
                        <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    
                    <div>
                        <label for="company_id">Company:</label>
                        <select id="company_id" name="company_id">
                            <option value="all">All Companies</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= $company['CompanyID'] ?>" 
                                        <?= $companyFilter == $company['CompanyID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($company['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="filter-buttons">
                    <button type="submit">Apply Filters</button>
                    <a href="scm_kpis.php" class="btn-reset">Reset Filters</a>
                </div>
            </form>
        </div>

        <!-- Main KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">On-Time Delivery Rate</div>
                <div class="kpi-value"><?= $onTimeRate ?>%</div>
                <div class="kpi-subtitle"><?= $onTimeCount ?> / <?= $totalDelivered ?> on-time</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label">Average Delay</div>
                <div class="kpi-value" style="<?= $avgDelay > 0 ? 'color: #f44336;' : 'color: #4caf50;' ?>">
                    <?= $avgDelay ?> days
                </div>
                <div class="kpi-subtitle">Std Dev: <?= $stdDelay ?> days</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label">Total Shipments</div>
                <div class="kpi-value"><?= $totalShipments ?></div>
                <div class="kpi-subtitle"><?= $inTransit ?> still in transit</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label">Delay Range</div>
                <div class="kpi-value" style="font-size: 1.8rem;">
                    <?= $minDelay ?> to <?= $maxDelay ?>
                </div>
                <div class="kpi-subtitle">days (min to max)</div>
            </div>
        </div>

        <!-- Monthly Trend -->
        <div class="content-section">
            <h3>📈 Monthly Performance Trend (Last 6 Months)</h3>
            <?php if (!empty($monthlyTrend)): ?>
                <?php foreach ($monthlyTrend as $trend): 
                    $monthOnTimeRate = round(($trend['on_time'] / $trend['total_shipments']) * 100, 1);
                ?>
                    <div class="trend-card">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: var(--purdue-gold); font-size: 1.2rem;">
                                    <?= date('F Y', strtotime($trend['month'] . '-01')) ?>
                                </strong>
                            </div>
                            <div style="text-align: right;">
                                <div>
                                    <span style="font-size: 1.1rem;">On-Time Rate: </span>
                                    <span class="<?= $monthOnTimeRate >= 90 ? 'good-performance' : ($monthOnTimeRate >= 70 ? 'average-performance' : 'poor-performance') ?>">
                                        <?= $monthOnTimeRate ?>%
                                    </span>
                                </div>
                                <div style="color: var(--text-light); font-size: 0.9rem;">
                                    <?= $trend['total_shipments'] ?> shipments | 
                                    Avg Delay: <?= round($trend['avg_delay'], 1) ?> days
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-light); padding: 20px;">No trend data available</p>
            <?php endif; ?>
        </div>

        <!-- Company Performance Rankings -->
        <div class="content-section" style="margin-top: 40px;">
            <h3>🏆 Top Performing Companies (by On-Time Rate)</h3>
            <?php if (!empty($companyPerformance)): ?>
                <table id="performanceTable">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Company Name</th>
                            <th>Type</th>
                            <th>Total Shipments</th>
                            <th>On-Time Deliveries</th>
                            <th>On-Time Rate</th>
                            <th>Avg Delay (days)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($companyPerformance as $perf): 
                            $companyOnTimeRate = $perf['total_shipments'] > 0 ? round(($perf['on_time'] / $perf['total_shipments']) * 100, 1) : null;
                        ?>
                            <tr>
                                <td><strong><?= $rank++ ?></strong></td>
                                <td><?= htmlspecialchars($perf['CompanyName']) ?></td>
                                <td><?= htmlspecialchars($perf['Type']) ?></td>
                                <td><?= $perf['total_shipments'] ?></td>
                                <td><?= $perf['on_time'] ?></td>
                                <td>
                                    <?php if ($companyOnTimeRate !== null): ?>
                                        <span class="<?= $companyOnTimeRate >= 90 ? 'good-performance' : ($companyOnTimeRate >= 70 ? 'average-performance' : 'poor-performance') ?>">
                                            <?= $companyOnTimeRate ?>%
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-light); font-style: italic;">--</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($perf['avg_delay'] !== null && $perf['total_shipments'] > 0): ?>
                                        <?= round($perf['avg_delay'], 2) ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-light); font-style: italic;">--</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-light); padding: 20px;">No performance data available for selected period</p>
            <?php endif; ?>
        </div>

        <!-- Financial Health Status -->
        <?php if (!empty($topCompanies)): ?>
            <div class="content-section" style="margin-top: 40px;">
                <h3>💰 Top Companies by Financial Health (Most Recent Quarter)</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Company Name</th>
                            <th>Health Score</th>
                            <th>Quarter</th>
                            <th>Year</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($topCompanies as $company): 
                            $healthColor = $company['HealthScore'] >= 80 ? '#4caf50' : 
                                          ($company['HealthScore'] >= 60 ? '#ff9800' : '#f44336');
                        ?>
                            <tr>
                                <td><strong><?= $rank++ ?></strong></td>
                                <td><?= htmlspecialchars($company['CompanyName']) ?></td>
                                <td>
                                    <strong style="color: <?= $healthColor ?>; font-size: 1.2rem;">
                                        <?= round($company['HealthScore'], 2) ?>
                                    </strong>
                                </td>
                                <td><?= htmlspecialchars($company['Quarter']) ?></td>
                                <td><?= htmlspecialchars($company['RepYear']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>