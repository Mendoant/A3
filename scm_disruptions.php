<?php
// scm_disruptions.php - Disruption Events Analytics
require_once 'config.php';
requireLogin();

// Redirect Senior Managers
if (hasRole('SeniorManager')) {
    header('Location: dashboard_erp.php');
    exit;
}

$pdo = getPDO();

// Get filter parameters (PHP 5.4 compatible)
$companyFilter = isset($_GET['company']) ? $_GET['company'] : 'all';
$regionFilter = isset($_GET['region']) ? $_GET['region'] : 'all';
$tierFilter = isset($_GET['tier']) ? $_GET['tier'] : 'all';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01'); // Start of current year
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Today

// Build WHERE conditions for filters
$whereConditions = array("de.EventDate BETWEEN :startDate AND :endDate");
$params = array(':startDate' => $startDate, ':endDate' => $endDate);

if ($companyFilter !== 'all') {
    $whereConditions[] = "c.CompanyID = :companyId";
    $params[':companyId'] = $companyFilter;
}

if ($regionFilter !== 'all') {
    $whereConditions[] = "l.ContinentName = :region";
    $params[':region'] = $regionFilter;
}

if ($tierFilter !== 'all') {
    $whereConditions[] = "c.TierLevel = :tier";
    $params[':tier'] = $tierFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// 1. Disruption Frequency (DF) by Company
$dfSql = "SELECT 
    c.CompanyID,
    c.CompanyName,
    c.Type,
    c.TierLevel,
    l.ContinentName,
    COUNT(DISTINCT de.EventID) as NumDisruptions,
    DATEDIFF(:endDate, :startDate) / 30.0 as PeriodMonths,
    COUNT(DISTINCT de.EventID) / (DATEDIFF(:endDate, :startDate) / 30.0) as DisruptionFrequency
FROM Company c
JOIN Location l ON c.LocationID = l.LocationID
JOIN ImpactsCompany ic ON c.CompanyID = ic.AffectedCompanyID
JOIN DisruptionEvent de ON ic.EventID = de.EventID
$whereClause
GROUP BY c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.ContinentName
ORDER BY DisruptionFrequency DESC
LIMIT 20";

$dfStmt = $pdo->prepare($dfSql);
$dfStmt->execute($params);
$disruptionFrequency = $dfStmt->fetchAll();

// 2. Average Recovery Time (ART)
$artSql = "SELECT 
    AVG(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as AvgRecoveryTime,
    MIN(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as MinRecoveryTime,
    MAX(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as MaxRecoveryTime,
    STDDEV(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as StdDevRecoveryTime
FROM DisruptionEvent de
JOIN ImpactsCompany ic ON de.EventID = ic.EventID
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l ON c.LocationID = l.LocationID
$whereClause
AND de.EventRecoveryDate IS NOT NULL";

$artStmt = $pdo->prepare($artSql);
$artStmt->execute($params);
$artStats = $artStmt->fetch();

// Get recovery time distribution for histogram
$artHistSql = "SELECT 
    DATEDIFF(de.EventRecoveryDate, de.EventDate) as RecoveryDays,
    COUNT(*) as Frequency
FROM DisruptionEvent de
JOIN ImpactsCompany ic ON de.EventID = ic.EventID
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l ON c.LocationID = l.LocationID
$whereClause
AND de.EventRecoveryDate IS NOT NULL
GROUP BY RecoveryDays
ORDER BY RecoveryDays";

$artHistStmt = $pdo->prepare($artHistSql);
$artHistStmt->execute($params);
$artHistogram = $artHistStmt->fetchAll();

// 3. High-Impact Disruption Rate (HDR)
$hdrSql = "SELECT 
    COUNT(CASE WHEN ic.ImpactLevel = 'High' THEN 1 END) as HighImpactCount,
    COUNT(*) as TotalDisruptions,
    (COUNT(CASE WHEN ic.ImpactLevel = 'High' THEN 1 END) / COUNT(*)) * 100 as HDR
FROM DisruptionEvent de
JOIN ImpactsCompany ic ON de.EventID = ic.EventID
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l ON c.LocationID = l.LocationID
$whereClause";

$hdrStmt = $pdo->prepare($hdrSql);
$hdrStmt->execute($params);
$hdrStats = $hdrStmt->fetch();

// 4. Total Downtime (TD) by Company
$tdSql = "SELECT 
    c.CompanyID,
    c.CompanyName,
    c.Type,
    l.ContinentName,
    SUM(DATEDIFF(COALESCE(de.EventRecoveryDate, CURDATE()), de.EventDate)) as TotalDowntimeDays,
    COUNT(DISTINCT de.EventID) as NumDisruptions
FROM Company c
JOIN Location l ON c.LocationID = l.LocationID
JOIN ImpactsCompany ic ON c.CompanyID = ic.AffectedCompanyID
JOIN DisruptionEvent de ON ic.EventID = de.EventID
$whereClause
GROUP BY c.CompanyID, c.CompanyName, c.Type, l.ContinentName
ORDER BY TotalDowntimeDays DESC
LIMIT 20";

$tdStmt = $pdo->prepare($tdSql);
$tdStmt->execute($params);
$totalDowntime = $tdStmt->fetchAll();

// 5. Regional Risk Concentration (RRC)
// Build subquery WHERE clause with de2 alias
$whereConditionsSubquery = array("de2.EventDate BETWEEN :startDate2 AND :endDate2");
$rrcParams = array(
    ':startDate' => $startDate, 
    ':endDate' => $endDate,
    ':startDate2' => $startDate, 
    ':endDate2' => $endDate
);

if ($companyFilter !== 'all') {
    $whereConditionsSubquery[] = "c2.CompanyID = :companyId2";
    $rrcParams[':companyId'] = $companyFilter;
    $rrcParams[':companyId2'] = $companyFilter;
}

if ($regionFilter !== 'all') {
    $whereConditionsSubquery[] = "l2.ContinentName = :region2";
    $rrcParams[':region'] = $regionFilter;
    $rrcParams[':region2'] = $regionFilter;
}

if ($tierFilter !== 'all') {
    $whereConditionsSubquery[] = "c2.TierLevel = :tier2";
    $rrcParams[':tier'] = $tierFilter;
    $rrcParams[':tier2'] = $tierFilter;
}

$whereClauseSubquery = 'WHERE ' . implode(' AND ', $whereConditionsSubquery);

$rrcSql = "SELECT
    l.ContinentName AS Region,
    COUNT(DISTINCT de.EventID) AS NumDisruptionsInRegion,
    t.TotalDisruptions,
    (COUNT(DISTINCT de.EventID) / t.TotalDisruptions) * 100 AS RRC
FROM DisruptionEvent de
JOIN ImpactsCompany ic ON de.EventID = ic.EventID
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l ON c.LocationID = l.LocationID
CROSS JOIN (
    SELECT COUNT(DISTINCT de2.EventID) AS TotalDisruptions
    FROM DisruptionEvent de2
    JOIN ImpactsCompany ic2 ON de2.EventID = ic2.EventID
    JOIN Company c2 ON ic2.AffectedCompanyID = c2.CompanyID
    JOIN Location l2 ON c2.LocationID = l2.LocationID
    $whereClauseSubquery
) t
$whereClause
GROUP BY l.ContinentName, t.TotalDisruptions
ORDER BY RRC DESC";

$rrcStmt = $pdo->prepare($rrcSql);
$rrcStmt->execute($rrcParams);
$regionalRisk = $rrcStmt->fetchAll();

// 6. Disruption Severity Distribution (DSD)
$dsdSql = "SELECT 
    ic.ImpactLevel,
    COUNT(*) as Count
FROM DisruptionEvent de
JOIN ImpactsCompany ic ON de.EventID = ic.EventID
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l ON c.LocationID = l.LocationID
$whereClause
GROUP BY ic.ImpactLevel
ORDER BY FIELD(ic.ImpactLevel, 'High', 'Medium', 'Low')";

$dsdStmt = $pdo->prepare($dsdSql);
$dsdStmt->execute($params);
$severityDistribution = $dsdStmt->fetchAll();

// Get filter options
$companiesSql = "SELECT DISTINCT CompanyID, CompanyName FROM Company ORDER BY CompanyName";
$companiesStmt = $pdo->query($companiesSql);
$companies = $companiesStmt->fetchAll();

$regionsSql = "SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName";
$regionsStmt = $pdo->query($regionsSql);
$regions = $regionsStmt->fetchAll(PDO::FETCH_COLUMN);

// Calculate summary metrics (PHP 5.4 compatible)
$totalDisruptions = isset($hdrStats['TotalDisruptions']) ? $hdrStats['TotalDisruptions'] : 0;
$avgRecoveryTime = round(isset($artStats['AvgRecoveryTime']) ? $artStats['AvgRecoveryTime'] : 0, 1);
$highImpactRate = round(isset($hdrStats['HDR']) ? $hdrStats['HDR'] : 0, 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disruption Analytics - SCM</title>
    <link rel="stylesheet" href="assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: rgba(0, 0, 0, 0.6);
            padding: 24px;
            border-radius: 8px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            text-align: center;
        }
        .stat-card h3 {
            margin: 0;
            font-size: 2.5rem;
            color: var(--purdue-gold);
        }
        .stat-card p {
            margin: 8px 0 0 0;
            color: var(--text-light);
            font-size: 1rem;
        }
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }
        .chart-container {
            background: rgba(0, 0, 0, 0.6);
            padding: 24px;
            border-radius: 8px;
            border: 2px solid rgba(207, 185, 145, 0.3);
        }
        .chart-container h3 {
            margin: 0 0 20px 0;
            color: var(--purdue-gold);
            font-size: 1.3rem;
        }
        .chart-wrapper {
            position: relative;
            height: 400px;
        }
        .chart-wrapper.short {
            height: 300px;
        }
        .table-container {
            max-height: 500px;
            overflow-y: auto;
        }
        table {
            font-size: 0.9rem;
        }
        .metric-label {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-top: 4px;
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
        <a href="scm_kpis.php">KPIs</a>
        <a href="scm_disruptions.php" class="active">Disruptions</a>
        <a href="scm_transactions.php">Transactions</a>
        <a href="scm_transaction_costs.php">Cost Analysis</a>
        <a href="scm_distributors.php">Distributors</a>
    </div>

    <div class="container">
        <h2>Disruption Events Analytics</h2>

        <!-- Filters -->
        <div class="filter-section">
            <h3>Filter Analytics</h3>
            <form method="GET" action="scm_disruptions.php" id="filterForm">
                <div class="filter-grid">
                    <div>
                        <label for="company">Company:</label>
                        <select id="company" name="company">
                            <option value="all" <?= $companyFilter === 'all' ? 'selected' : '' ?>>All Companies</option>
                            <?php foreach ($companies as $comp): ?>
                                <option value="<?= $comp['CompanyID'] ?>" <?= $companyFilter == $comp['CompanyID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($comp['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="region">Region:</label>
                        <select id="region" name="region">
                            <option value="all" <?= $regionFilter === 'all' ? 'selected' : '' ?>>All Regions</option>
                            <?php foreach ($regions as $region): ?>
                                <option value="<?= htmlspecialchars($region) ?>" <?= $regionFilter === $region ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($region) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="tier">Tier Level:</label>
                        <select id="tier" name="tier">
                            <option value="all" <?= $tierFilter === 'all' ? 'selected' : '' ?>>All Tiers</option>
                            <option value="1" <?= $tierFilter === '1' ? 'selected' : '' ?>>Tier 1</option>
                            <option value="2" <?= $tierFilter === '2' ? 'selected' : '' ?>>Tier 2</option>
                            <option value="3" <?= $tierFilter === '3' ? 'selected' : '' ?>>Tier 3</option>
                        </select>
                    </div>

                    <div>
                        <label for="start_date">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    </div>

                    <div>
                        <label for="end_date">End Date:</label>
                        <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                </div>

                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <a href="scm_disruptions.php" class="btn-secondary" style="display: inline-block; padding: 14px 32px; text-decoration: none;">Clear Filters</a>
                </div>
            </form>
        </div>

        <!-- Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3><?= $totalDisruptions ?></h3>
                <p>Total Disruptions</p>
                <div class="metric-label">During selected period</div>
            </div>
            <div class="stat-card">
                <h3><?= $avgRecoveryTime ?></h3>
                <p>Avg Recovery Time</p>
                <div class="metric-label">Days (ART)</div>
            </div>
            <div class="stat-card">
                <h3><?= $highImpactRate ?>%</h3>
                <p>High Impact Rate</p>
                <div class="metric-label">HDR Metric</div>
            </div>
        </div>

        <!-- Charts Row 1: DF and ART -->
        <div class="chart-grid">
            <!-- Disruption Frequency (DF) -->
            <div class="chart-container">
                <h3>📊 Disruption Frequency (DF) by Company</h3>
                <p style="color: var(--text-light); font-size: 0.9rem; margin-bottom: 16px;">
                    DF = N<sub>disruptions</sub> / T (disruptions per month)
                </p>
                <div class="chart-wrapper">
                    <canvas id="dfChart"></canvas>
                </div>
            </div>

            <!-- Average Recovery Time (ART) Histogram -->
            <div class="chart-container">
                <h3>⏱️ Average Recovery Time (ART) Distribution</h3>
                <p style="color: var(--text-light); font-size: 0.9rem; margin-bottom: 16px;">
                    ART = Average(t<sup>rec</sup> - t<sup>event</sup>) = <?= $avgRecoveryTime ?> days
                </p>
                <div class="chart-wrapper">
                    <canvas id="artChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Charts Row 2: HDR and TD -->
        <div class="chart-grid">
            <!-- Disruption Severity Distribution (DSD) -->
            <div class="chart-container">
                <h3>⚠️ Disruption Severity Distribution (DSD)</h3>
                <p style="color: var(--text-light); font-size: 0.9rem; margin-bottom: 16px;">
                    HDR = (N<sub>high-impact</sub> / N<sub>disruptions</sub>) × 100% = <?= $highImpactRate ?>%
                </p>
                <div class="chart-wrapper short">
                    <canvas id="dsdChart"></canvas>
                </div>
            </div>

            <!-- Total Downtime (TD) -->
            <div class="chart-container">
                <h3>📉 Total Downtime (TD) by Company</h3>
                <p style="color: var(--text-light); font-size: 0.9rem; margin-bottom: 16px;">
                    TD = Σ(t<sup>rec</sup> - t<sup>event</sup>) in days
                </p>
                <div class="chart-wrapper">
                    <canvas id="tdChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Regional Risk Concentration (RRC) -->
        <div class="chart-container" style="margin-bottom: 30px;">
            <h3>🗺️ Regional Risk Concentration (RRC)</h3>
            <p style="color: var(--text-light); font-size: 0.9rem; margin-bottom: 16px;">
                RRC<sub>r</sub> = N<sub>disruptions,r</sub> / N<sub>disruptions,total</sub>
            </p>
            <div class="chart-wrapper short">
                <canvas id="rrcChart"></canvas>
            </div>
        </div>

        <!-- Data Tables -->
        <div class="chart-grid">
            <!-- DF Table -->
            <div class="chart-container">
                <h3>Disruption Frequency Data</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Company</th>
                                <th>Type</th>
                                <th>Tier</th>
                                <th>Region</th>
                                <th>Count</th>
                                <th>DF</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($disruptionFrequency as $df): ?>
                                <tr>
                                    <td><?= htmlspecialchars($df['CompanyName']) ?></td>
                                    <td><?= htmlspecialchars($df['Type']) ?></td>
                                    <td><?= htmlspecialchars($df['TierLevel']) ?></td>
                                    <td><?= htmlspecialchars($df['ContinentName']) ?></td>
                                    <td><?= htmlspecialchars($df['NumDisruptions']) ?></td>
                                    <td><?= number_format($df['DisruptionFrequency'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TD Table -->
            <div class="chart-container">
                <h3>Total Downtime Data</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Company</th>
                                <th>Type</th>
                                <th>Region</th>
                                <th>Disruptions</th>
                                <th>Total Days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($totalDowntime as $td): ?>
                                <tr>
                                    <td><?= htmlspecialchars($td['CompanyName']) ?></td>
                                    <td><?= htmlspecialchars($td['Type']) ?></td>
                                    <td><?= htmlspecialchars($td['ContinentName']) ?></td>
                                    <td><?= htmlspecialchars($td['NumDisruptions']) ?></td>
                                    <td><strong><?= number_format($td['TotalDowntimeDays']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Filter logic: disable region and tier when specific company is selected
        const companySelect = document.getElementById('company');
        const regionSelect = document.getElementById('region');
        const tierSelect = document.getElementById('tier');
        const filterForm = document.getElementById('filterForm');

        function updateFilterState() {
            const isCompanySelected = companySelect.value !== 'all';
            
            if (isCompanySelected) {
                regionSelect.disabled = true;
                tierSelect.disabled = true;
                regionSelect.value = 'all';
                tierSelect.value = 'all';
                regionSelect.style.opacity = '0.5';
                tierSelect.style.opacity = '0.5';
            } else {
                regionSelect.disabled = false;
                tierSelect.disabled = false;
                regionSelect.style.opacity = '1';
                tierSelect.style.opacity = '1';
            }
        }

        // Initialize state on page load
        updateFilterState();

        // Update state when company changes
        companySelect.addEventListener('change', updateFilterState);

        // Auto-submit form when any filter changes
        const filterInputs = filterForm.querySelectorAll('select, input');
        filterInputs.forEach(input => {
            input.addEventListener('change', function() {
                filterForm.submit();
            });
        });

        // Chart.js default colors
        const colors = {
            gold: 'rgba(207, 185, 145, 0.8)',
            goldBorder: 'rgba(207, 185, 145, 1)',
            red: 'rgba(255, 68, 68, 0.8)',
            orange: 'rgba(255, 152, 0, 0.8)',
            green: 'rgba(76, 175, 80, 0.8)',
            blue: 'rgba(54, 162, 235, 0.8)'
        };

        // 1. Disruption Frequency (DF) Bar Chart
        const dfData = <?= json_encode($disruptionFrequency) ?>;
        const dfChart = new Chart(document.getElementById('dfChart'), {
            type: 'bar',
            data: {
                labels: dfData.map(d => d.CompanyName),
                datasets: [{
                    label: 'Disruption Frequency (per month)',
                    data: dfData.map(d => parseFloat(d.DisruptionFrequency)),
                    backgroundColor: colors.gold,
                    borderColor: colors.goldBorder,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'DF (disruptions/month)', color: '#fff' }, ticks: { color: '#ccc' } },
                    x: { ticks: { color: '#ccc', maxRotation: 45, minRotation: 45 } }
                },
                plugins: { legend: { labels: { color: '#fff' } } }
            }
        });

        // 2. Average Recovery Time (ART) Histogram
        const artData = <?= json_encode($artHistogram) ?>;
        const artChart = new Chart(document.getElementById('artChart'), {
            type: 'bar',
            data: {
                labels: artData.map(d => d.RecoveryDays + ' days'),
                datasets: [{
                    label: 'Frequency',
                    data: artData.map(d => d.Frequency),
                    backgroundColor: colors.blue,
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Frequency', color: '#fff' }, ticks: { color: '#ccc' } },
                    x: { title: { display: true, text: 'Recovery Time (days)', color: '#fff' }, ticks: { color: '#ccc', maxRotation: 45, minRotation: 45 } }
                },
                plugins: { legend: { labels: { color: '#fff' } } }
            }
        });

        // 3. Disruption Severity Distribution (DSD) Stacked Bar
        const dsdData = <?= json_encode($severityDistribution) ?>;
        const dsdChart = new Chart(document.getElementById('dsdChart'), {
            type: 'bar',
            data: {
                labels: ['Severity Distribution'],
                datasets: [
                    {
                        label: 'High',
                        data: [dsdData.find(d => d.ImpactLevel === 'High')?.Count || 0],
                        backgroundColor: colors.red
                    },
                    {
                        label: 'Medium',
                        data: [dsdData.find(d => d.ImpactLevel === 'Medium')?.Count || 0],
                        backgroundColor: colors.orange
                    },
                    {
                        label: 'Low',
                        data: [dsdData.find(d => d.ImpactLevel === 'Low')?.Count || 0],
                        backgroundColor: colors.green
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true, ticks: { color: '#ccc' } },
                    y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Count', color: '#fff' }, ticks: { color: '#ccc' } }
                },
                plugins: { legend: { labels: { color: '#fff' } } }
            }
        });

        // 4. Total Downtime (TD) Histogram
        const tdData = <?= json_encode($totalDowntime) ?>;
        const tdChart = new Chart(document.getElementById('tdChart'), {
            type: 'bar',
            data: {
                labels: tdData.map(d => d.CompanyName),
                datasets: [{
                    label: 'Total Downtime (days)',
                    data: tdData.map(d => d.TotalDowntimeDays),
                    backgroundColor: colors.red,
                    borderColor: 'rgba(255, 68, 68, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Days', color: '#fff' }, ticks: { color: '#ccc' } },
                    x: { ticks: { color: '#ccc', maxRotation: 45, minRotation: 45 } }
                },
                plugins: { legend: { labels: { color: '#fff' } } }
            }
        });

        // 5. Regional Risk Concentration (RRC) Heatmap-style Bar
        const rrcData = <?= json_encode($regionalRisk) ?>;
        const rrcChart = new Chart(document.getElementById('rrcChart'), {
            type: 'bar',
            data: {
                labels: rrcData.map(d => d.Region),
                datasets: [{
                    label: 'RRC (%)',
                    data: rrcData.map(d => parseFloat(d.RRC)),
                    backgroundColor: rrcData.map(d => {
                        const val = parseFloat(d.RRC);
                        if (val > 30) return colors.red;
                        if (val > 15) return colors.orange;
                        return colors.green;
                    }),
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true, max: 100, title: { display: true, text: 'RRC (%)', color: '#fff' }, ticks: { color: '#ccc' } },
                    y: { ticks: { color: '#ccc' } }
                },
                plugins: { legend: { labels: { color: '#fff' } } }
            }
        });
    </script>
</body>
</html>
