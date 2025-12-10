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

// Get current disruptions
$disruptionSql = "SELECT 
    de.EventID,
    de.EventDate,
    de.EventRecoveryDate,
    dc.CategoryName,
    dc.Description as CategoryDescription,
    GROUP_CONCAT(DISTINCT c.CompanyName ORDER BY c.CompanyName SEPARATOR ', ') as AffectedCompanies,
    COUNT(DISTINCT ic.AffectedCompanyID) as CompanyCount,
    DATEDIFF(CURDATE(), de.EventDate) as DaysSinceStart,
    MAX(ic.ImpactLevel) as MaxImpact
FROM DisruptionEvent de
JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
JOIN ImpactsCompany ic ON de.EventID = ic.EventID
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
WHERE de.EventRecoveryDate IS NULL 
   OR de.EventRecoveryDate >= CURDATE()
GROUP BY de.EventID, de.EventDate, de.EventRecoveryDate, dc.CategoryName, dc.Description
ORDER BY de.EventDate DESC
LIMIT 10";

$disruptionStmt = $pdo->prepare($disruptionSql);
$disruptionStmt->execute();
$currentDisruptions = $disruptionStmt->fetchAll();


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
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL";

$params2 = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $sql2 .= " AND c.CompanyID = :companyID";
    $params2[':companyID'] = $companyID;
}
if (!empty($region)) {
    $sql2 .= " AND l.ContinentName = :region";
    $params2[':region'] = $region;
}
if (!empty($tierLevel)) {
    $sql2 .= " AND c.TierLevel = :tier";
    $params2[':tier'] = $tierLevel;
}

$stmt2 = $pdo->prepare($sql2);
$stmt2->execute($params2);
$recovery = $stmt2->fetch();
$avgRecoveryTime = $recovery['avgRecoveryTime'] ? round($recovery['avgRecoveryTime'], 1) : 0;

// METRIC 3: High-Impact Disruption Rate (HDR)
// HDR = (High impact events / Total events) * 100%
$sql3 = "SELECT 
            COUNT(DISTINCT de.EventID) as totalEvents,
            SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactEvents
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end";

$params3 = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $sql3 .= " AND c.CompanyID = :companyID";
    $params3[':companyID'] = $companyID;
}
if (!empty($region)) {
    $sql3 .= " AND l.ContinentName = :region";
    $params3[':region'] = $region;
}
if (!empty($tierLevel)) {
    $sql3 .= " AND c.TierLevel = :tier";
    $params3[':tier'] = $tierLevel;
}

$stmt3 = $pdo->prepare($sql3);
$stmt3->execute($params3);
$impact = $stmt3->fetch();

$totalEvents = intval($impact['totalEvents']);
$highImpactEvents = intval($impact['highImpactEvents']);
$hdr = $totalEvents > 0 ? round(($highImpactEvents / $totalEvents) * 100, 1) : 0;

// METRIC 4: Total Downtime (TD)
// TD = sum of all recovery times
$sql4 = "SELECT 
            SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as totalDowntime
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL";

$params4 = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $sql4 .= " AND c.CompanyID = :companyID";
    $params4[':companyID'] = $companyID;
}
if (!empty($region)) {
    $sql4 .= " AND l.ContinentName = :region";
    $params4[':region'] = $region;
}
if (!empty($tierLevel)) {
    $sql4 .= " AND c.TierLevel = :tier";
    $params4[':tier'] = $tierLevel;
}

$stmt4 = $pdo->prepare($sql4);
$stmt4->execute($params4);
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
         WHERE de.EventDate BETWEEN :start AND :end";

$params5 = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $sql5 .= " AND c.CompanyID = :companyID";
    $params5[':companyID'] = $companyID;
}
if (!empty($region)) {
    $sql5 .= " AND l.ContinentName = :region";
    $params5[':region'] = $region;
}
if (!empty($tierLevel)) {
    $sql5 .= " AND c.TierLevel = :tier";
    $params5[':tier'] = $tierLevel;
}

$sql5 .= " GROUP BY l.ContinentName ORDER BY regionDisruptions DESC";

$stmt5 = $pdo->prepare($sql5);
$stmt5->execute($params5);
$regions = $stmt5->fetchAll();

// METRIC 6: Disruption Severity Distribution by Month
$sql6 = "SELECT 
            DATE_FORMAT(de.EventDate, '%Y-%m') as period,
            ic.ImpactLevel,
            COUNT(DISTINCT de.EventID) as eventCount
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end";

$params6 = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $sql6 .= " AND c.CompanyID = :companyID";
    $params6[':companyID'] = $companyID;
}
if (!empty($region)) {
    $sql6 .= " AND l.ContinentName = :region";
    $params6[':region'] = $region;
}
if (!empty($tierLevel)) {
    $sql6 .= " AND c.TierLevel = :tier";
    $params6[':tier'] = $tierLevel;
}

$sql6 .= " GROUP BY DATE_FORMAT(de.EventDate, '%Y-%m'), ic.ImpactLevel
           ORDER BY period, ic.ImpactLevel";

$stmt6 = $pdo->prepare($sql6);
$stmt6->execute($params6);
$severityDist = $stmt6->fetchAll();

// Get all recovery times for ART histogram
$sql7 = "SELECT DATEDIFF(de.EventRecoveryDate, de.EventDate) as recoveryDays
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL
         AND DATEDIFF(de.EventRecoveryDate, de.EventDate) >= 0";

$params7 = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $sql7 .= " AND c.CompanyID = :companyID";
    $params7[':companyID'] = $companyID;
}
if (!empty($region)) {
    $sql7 .= " AND l.ContinentName = :region";
    $params7[':region'] = $region;
}
if (!empty($tierLevel)) {
    $sql7 .= " AND c.TierLevel = :tier";
    $params7[':tier'] = $tierLevel;
}

$stmt7 = $pdo->prepare($sql7);
$stmt7->execute($params7);
$recoveryTimes = $stmt7->fetchAll(PDO::FETCH_COLUMN);

// Get downtime by category for TD histogram
$sql8 = "SELECT dc.CategoryName,
                SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as totalDowntime
         FROM DisruptionEvent de
         JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL";

$params8 = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $sql8 .= " AND c.CompanyID = :companyID";
    $params8[':companyID'] = $companyID;
}
if (!empty($region)) {
    $sql8 .= " AND l.ContinentName = :region";
    $params8[':region'] = $region;
}
if (!empty($tierLevel)) {
    $sql8 .= " AND c.TierLevel = :tier";
    $params8[':tier'] = $tierLevel;
}

$sql8 .= " GROUP BY dc.CategoryName ORDER BY totalDowntime DESC";

$stmt8 = $pdo->prepare($sql8);
$stmt8->execute($params8);
$downtimeByCategory = $stmt8->fetchAll();

$sql8 = "SELECT l.ContinentName as RegionName,
                SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as totalDowntime
         FROM DisruptionEvent de
         JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL";

$params8 = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $sql8 .= " AND c.CompanyID = :companyID";
    $params8[':companyID'] = $companyID;
}
if (!empty($region)) {
    $sql8 .= " AND l.ContinentName = :region";
    $params8[':region'] = $region;
}
if (!empty($tierLevel)) {
    $sql8 .= " AND c.TierLevel = :tier";
    $params8[':tier'] = $tierLevel;
}

$sql8 .= " GROUP BY l.ContinentName ORDER BY totalDowntime DESC";

$stmt8 = $pdo->prepare($sql8);
$stmt8->execute($params8);
$downtimeByRegion = $stmt8->fetchAll();

$sql9 = "SELECT c.TierLevel,
                SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) as totalDowntime
         FROM DisruptionEvent de
         JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end
         AND de.EventRecoveryDate IS NOT NULL";

$params9 = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $sql9 .= " AND c.CompanyID = :companyID";
    $params9[':companyID'] = $companyID;
}
if (!empty($region)) {
    $sql9 .= " AND l.ContinentName = :region";
    $params9[':region'] = $region;
}
if (!empty($tierLevel)) {
    $sql9 .= " AND c.TierLevel = :tier";
    $params9[':tier'] = $tierLevel;
}

$sql9 .= " GROUP BY c.TierLevel ORDER BY totalDowntime DESC";

$stmt9 = $pdo->prepare($sql9);
$stmt9->execute($params9);
$downtimeByTier = $stmt9->fetchAll();

// Get data for Map (Country-level disruptions)
// We need country name to map bubbles
$sqlMap = "SELECT l.CountryName,
                COUNT(DISTINCT de.EventID) as eventCount
         FROM DisruptionEvent de
         JOIN ImpactsCompany ic ON de.EventID = ic.EventID
         JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
         JOIN Location l ON c.LocationID = l.LocationID
         WHERE de.EventDate BETWEEN :start AND :end";

$paramsMap = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $sqlMap .= " AND c.CompanyID = :companyID";
    $paramsMap[':companyID'] = $companyID;
}
if (!empty($region)) {
    $sqlMap .= " AND l.ContinentName = :region";
    $paramsMap[':region'] = $region;
}
if (!empty($tierLevel)) {
    $sqlMap .= " AND c.TierLevel = :tier";
    $paramsMap[':tier'] = $tierLevel;
}

$sqlMap .= " GROUP BY l.CountryName ORDER BY eventCount DESC";

$stmtMap = $pdo->prepare($sqlMap);
$stmtMap->execute($paramsMap);
$mapData = $stmtMap->fetchAll();

// Get HDR data by category for bar chart
$sql10 = "SELECT dc.CategoryName,
                 COUNT(DISTINCT de.EventID) as totalEvents,
                 SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactEvents
          FROM DisruptionEvent de
          JOIN ImpactsCompany ic ON de.EventID = ic.EventID
          JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
          JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
          JOIN Location l ON c.LocationID = l.LocationID
          WHERE de.EventDate BETWEEN :start AND :end";

$params10 = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $sql10 .= " AND c.CompanyID = :companyID";
    $params10[':companyID'] = $companyID;
}
if (!empty($region)) {
    $sql10 .= " AND l.ContinentName = :region";
    $params10[':region'] = $region;
}
if (!empty($tierLevel)) {
    $sql10 .= " AND c.TierLevel = :tier";
    $params10[':tier'] = $tierLevel;
}

$sql10 .= " GROUP BY dc.CategoryName ORDER BY dc.CategoryName";

$stmt10 = $pdo->prepare($sql10);
$stmt10->execute($params10);
$hdrByCategory = $stmt10->fetchAll();

// Get all disruption events with details
$sqlEvents = "SELECT 
                de.EventID,
                de.EventDate,
                de.EventRecoveryDate,
                dc.CategoryName,
                dc.Description as CategoryDescription,
                GROUP_CONCAT(DISTINCT c.CompanyName ORDER BY c.CompanyName SEPARATOR ', ') as AffectedCompanies,
                COUNT(DISTINCT ic.AffectedCompanyID) as CompanyCount,
                MAX(ic.ImpactLevel) as MaxImpact,
                CASE 
                    WHEN de.EventRecoveryDate IS NULL THEN DATEDIFF(CURDATE(), de.EventDate)
                    ELSE DATEDIFF(de.EventRecoveryDate, de.EventDate)
                END as Duration,
                CASE 
                    WHEN de.EventRecoveryDate IS NULL THEN 'Ongoing'
                    WHEN de.EventRecoveryDate >= CURDATE() THEN 'Ongoing'
                    ELSE 'Recovered'
                END as Status
            FROM DisruptionEvent de
            JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
            JOIN ImpactsCompany ic ON de.EventID = ic.EventID
            JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
            JOIN Location l ON c.LocationID = l.LocationID
            WHERE de.EventDate BETWEEN :start AND :end";

$paramsEvents = array(':start' => $startDate, ':end' => $endDate);

if (!empty($companyID)) {
    $sqlEvents .= " AND c.CompanyID = :companyID";
    $paramsEvents[':companyID'] = $companyID;
}
if (!empty($region)) {
    $sqlEvents .= " AND l.ContinentName = :region";
    $paramsEvents[':region'] = $region;
}
if (!empty($tierLevel)) {
    $sqlEvents .= " AND c.TierLevel = :tier";
    $paramsEvents[':tier'] = $tierLevel;
}

$sqlEvents .= " GROUP BY de.EventID, de.EventDate, de.EventRecoveryDate, dc.CategoryName, dc.Description
                ORDER BY de.EventDate DESC";

$stmtEvents = $pdo->prepare($sqlEvents);
$stmtEvents->execute($paramsEvents);
$disruptionEvents = $stmtEvents->fetchAll();

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
            'downtimeByRegion' => $downtimeByRegion, //added for more data visibility
            'downtimeByTier' => $downtimeByTier, //same as above
            'mapData' => $mapData,
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
    <script src="https://cdn.plot.ly/plotly-2.27.0.min.js"></script>
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
        .disruption-banner-container {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 0;
            margin-bottom: 20px;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
            box-shadow: 0 4px 20px rgba(220, 53, 69, 0.5);
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
.disruption-banner-header {
            background: rgba(0, 0, 0, 0.3);
            padding: 12px 20px;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
        }
.disruption-banner-header .icon {
            font-size: 1.5rem;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.1); }
        }
.disruption-banner-scroll {
            overflow: hidden;
            position: relative;
            height: 60px;
            display: flex;
            align-items: center;
        }
.disruption-banner-content {
            display: flex;
            gap: 60px;
            animation: scroll-left 30s linear infinite;
            white-space: nowrap;
            padding: 0 20px;
        }
.disruption-banner-content:hover {
            animation-play-state: paused;
        }
        @keyframes scroll-left {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
.disruption-item {
            display: inline-flex;
            align-items: center;
            gap: 15px;
            padding: 10px 20px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
.disruption-item strong {
            color: #FFD700;
        }
.disruption-item .badge {
            background: rgba(255, 255, 255, 0.3);
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: bold;
        }
        .impact-high { background: #ff4444 !important; }
        .impact-medium { background: #ff9800 !important; }
        .impact-low { background: #4caf50 !important; }
        .disruption-count {
            background: rgba(255, 255, 255, 0.3);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: bold;
        }
        .status-ongoing { color: #ff6b6b; font-weight: bold; }
        .status-recovered { color: #4caf50; font-weight: bold; }
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
         <?php if (!empty($currentDisruptions)): ?>
        <div class="disruption-banner-container">
            <div class="disruption-banner-header">
                <span class="icon">⚠️</span>
                <span>ACTIVE DISRUPTIONS</span>
                <span class="disruption-count"><?= count($currentDisruptions) ?> Active</span>
                <span style="font-size: 0.85rem; font-weight: normal; margin-left: auto;">
                    Hover to pause • 
                </span>
            </div>

            <div class="disruption-banner-scroll">
                <div class="disruption-banner-content">
                    <?php foreach ($currentDisruptions as $disruption): ?>
                        <div class="disruption-item">
                            <strong><?= htmlspecialchars($disruption['CategoryName']) ?></strong>
                            <span>•</span>
                            <span><?= htmlspecialchars($disruption['CompanyCount']) ?> companies affected</span>
                            <span>•</span>
                            <span class="badge impact-<?= strtolower($disruption['MaxImpact']) ?>">
                                <?= htmlspecialchars($disruption['MaxImpact']) ?> Impact
                            </span>
                            <span>•</span>
                            <span><?= htmlspecialchars($disruption['DaysSinceStart']) ?> days ongoing</span>
                            <?php if ($disruption['CompanyCount'] <= 3): ?>
                                <span>•</span>
                                <span style="font-size: 0.9rem;"><?= htmlspecialchars($disruption['AffectedCompanies']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php foreach ($currentDisruptions as $disruption): ?>
                        <div class="disruption-item">
                            <strong><?= htmlspecialchars($disruption['CategoryName']) ?></strong>
                            <span>•</span>
                            <span><?= htmlspecialchars($disruption['CompanyCount']) ?> companies affected</span>
                            <span>•</span>
                            <span class="badge impact-<?= strtolower($disruption['MaxImpact']) ?>">
                                <?= htmlspecialchars($disruption['MaxImpact']) ?> Impact
                            </span>
                            <span>•</span>
                            <span><?= htmlspecialchars($disruption['DaysSinceStart']) ?> days ongoing</span>
                            <?php if ($disruption['CompanyCount'] <= 3): ?>
                                <span>•</span>
                                <span style="font-size: 0.9rem;"><?= htmlspecialchars($disruption['AffectedCompanies']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="content-section">
            <h3>Filter Disruptions</h3>
            <form id="filterForm">
                <div class="filter-grid">
                    <div><label style="color: var(--text-light); display: block; margin-bottom: 5px;">Start Date:</label>
                    <input type="date" id="start_date" value="<?= htmlspecialchars($startDate) ?>" required style="padding: 8px; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                </div>
                    <div><label style="color: var(--text-light); display: block; margin-bottom: 5px;">End Date:</label>
                    <input type="date" id="end_date" value="<?= htmlspecialchars($endDate) ?>" required style="padding: 8px; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                </div>
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
                <div style="margin-top: 15px; display: flex; gap: 10px;">
                    <button type="button" id="clearBtn" class="btn-secondary">Clear Filter</button>
                </div>
            </form>
        </div>

        <div class="metrics-grid">
            <div class="metric-card">
                <h3 id="metric-total"><?= $totalEvents ?></h3>
                <p>Total Disruptions</p>
                <small>In selected period</small>
            </div>
            <div class="metric-card">
                <h3 id="metric-high" style="color: #f44336;"><?= $hdr ?>%</h3>
                <p>High Impact Disruption Rate
                </p>
                <small id="metric-hdr"><?= $highImpactEvents ?> total events</small>
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

        

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: 20px;">
            <div class="chart-container">
                <h3>Disruption Frequency by Company (DF) - Top 15</h3>
                <div class="chart-wrapper">
                    <canvas id="dfBarChart"></canvas>
                </div>
            </div>

            <div class="chart-container">
                <h3>Average Recovery Time Distribution (ART)</h3>
                <div class="chart-wrapper">
                    <canvas id="artHistogram"></canvas>
                </div>
            </div>
            <div class="chart-container">
                <h3>High-Impact Disruption Rate (HDR) by Category</h3>
                <div class="chart-wrapper">
                    <canvas id="hdrChart"></canvas>
                </div>
            </div>
            <div class="chart-container">
                <h3>Total Downtime Distribution (TD) by Disruption Category</h3>
                <div class="chart-wrapper">
                    <canvas id="tdHistogram"></canvas>
                </div>
            </div>
            <div class="chart-container">
                <h3>Total Downtime Distribution (TD) by Region</h3>
                <div class="chart-wrapper">
                    <canvas id="tdHistogramRegion"></canvas>
                </div>
            </div>
            <div class="chart-container">
                <h3>Total Downtime Distribution (TD) by Tier</h3>
                <div class="chart-wrapper">
                    <canvas id="tdHistogramTier"></canvas>
                </div>
            </div>

            
            <div class="chart-container">
                <h3>Regional Risk Concentration (RRC) - Heatmap</h3>
                <div id="regionalMap" style="width:100%;height:350px;"></div>
            </div>
            <div class="chart-container">
                <h3>Disruption Severity Distribution (DSD) Monthly Trend</h3>
                <div class="chart-wrapper">
                    <canvas id="severityChart"></canvas>
                </div>
            </div>

            
        </div>

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

        <!-- Disruption Events Detail List -->
        <div class="content-section" style="margin-top: 30px;">
            <h3>Disruption Events Detail</h3>
            <div class="table-scroll-wrapper">
                <div id="eventsTableWrapper" style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Event ID</th>
                                <th>Category</th>
                                <th>Event Date</th>
                                <th>Recovery Date</th>
                                <th>Duration (Days)</th>
                                <th>Status</th>
                                <th>Companies Affected</th>
                                <th>Max Impact</th>
                            </tr>
                        </thead>
                        <tbody id="eventsTableBody">
                            <?php if (count($disruptionEvents) > 0): ?>
                                <?php foreach ($disruptionEvents as $event): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($event['EventID']) ?></td>
                                        <td><strong><?= htmlspecialchars($event['CategoryName']) ?></strong></td>
                                        <td><?= htmlspecialchars(date('M d, Y', strtotime($event['EventDate']))) ?></td>
                                        <td><?= $event['EventRecoveryDate'] ? htmlspecialchars(date('M d, Y', strtotime($event['EventRecoveryDate']))) : '<span style="color: #ff6b6b;">N/A</span>' ?></td>
                                        <td><?= htmlspecialchars($event['Duration']) ?></td>
                                        <td><span class="status-<?= strtolower($event['Status']) ?>"><?= htmlspecialchars($event['Status']) ?></span></td>
                                        <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($event['AffectedCompanies']) ?>">
                                            <?= htmlspecialchars($event['AffectedCompanies']) ?> 
                                            <?php if ($event['CompanyCount'] > 3): ?>
                                                <small>(<?= $event['CompanyCount'] ?> total)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge impact-<?= strtolower($event['MaxImpact']) ?>" style="padding: 4px 10px; border-radius: 4px; font-size: 0.85rem; font-weight: bold;">
                                                <?= htmlspecialchars($event['MaxImpact']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-light);">
                                        No disruption events found for the selected filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
(function() {
    var form = document.getElementById('filterForm');
    
    // Store chart instances globally so we can destroy and recreate them
    var hdrChart = null;
    var severityChart = null;
    var artHistogramChart = null;
    var tdHistogramChart = null;
    var tdHistogramRegion = null;
    var tdHistogramTier = null;
    var dfBarChart = null;
    // Map doesn't use Chart.js instance, but we can manage it if needed
    
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
                    
                    // Update metric cards
                    document.getElementById('metric-total').textContent = m.totalEvents;
                    document.getElementById('metric-high').textContent = m.hdr+ '%';
                    document.getElementById('metric-hdr').textContent = m.highImpactEvents + ' total Events';
                    document.getElementById('metric-recovery').textContent = m.avgRecoveryTime;
                    document.getElementById('metric-downtime').textContent = m.totalDowntime;
                    
                    // Update all charts
                    updateRegionalMap(m.mapData);
                    updateHDRChart(m.hdrByCategory);
                    updateSeverityChart(m.severityDist);
                    updateARTHistogram(m.recoveryTimes);
                    updateTDHistogram(m.downtimeByCategory);
                    updateTDHistogramRegion(m.downtimeByRegion);
                    updateTDHistogramTier(m.downtimeByTier);
                    updateDFBarChart(m.companies);
                    
                    // Rebuild tables
                    buildTable(m.companies);
                    buildEventsTable(m.disruptionEvents);
                }
            }
        };
        xhr.send();
    }
    
    // Update Regional Map (Bubble Map)
    function updateRegionalMap(mapData) {
        if (!mapData || mapData.length === 0) {
            Plotly.purge('regionalMap');
            return;
        }

        var locations = [];
        var z = [];
        var text = [];

        for(var i=0; i < mapData.length; i++) {
            locations.push(mapData[i].CountryName);
            var count = parseInt(mapData[i].eventCount);
            z.push(count);
            text.push(mapData[i].CountryName + ': ' + count + ' Disruptions');
        }

        var data = [{
            type: 'scattergeo',
            mode: 'markers',
            locations: locations,
            locationmode: 'country names',
            text: text,
            marker: {
                size: z,
                // Scale the bubbles so they are visible but not huge
                sizeref: 0.1, 
                sizemode: 'area',
                color: z,
                colorscale: [
                    [0, 'rgb(207, 185, 145)'], // Light Gold
                    [1, 'rgb(220, 53, 69)']    // Red/Danger
                ],
                cmin: 0,
                cmax: Math.max.apply(null, z),
                line: {
                    color: 'white',
                    width: 1
                },
                colorbar: {
                    title: 'Disruptions',
                    thickness: 10,
                    len: 0.8,
                    tickfont: { color: 'white' },
                    titlefont: { color: 'white' }
                }
            }
        }];

        var layout = {
            geo: {
                scope: 'world',
                resolution: 50,
                showland: true,
                landcolor: '#2E2E2E',
                showocean: true,
                oceancolor: '#111111',
                showlakes: true,
                lakecolor: '#111111',
                bgcolor: 'rgba(0,0,0,0)',
                projection: {
                    type: 'equirectangular'
                }
            },
            paper_bgcolor: 'rgba(0,0,0,0)',
            plot_bgcolor: 'rgba(0,0,0,0)',
            margin: { l: 0, r: 0, t: 0, b: 0 },
            showlegend: false
        };

        var config = { responsive: true, displayModeBar: false };

        Plotly.newPlot('regionalMap', data, layout, config);
    }
    
    // Update HDR Chart
    function updateHDRChart(hdrData) {
    if (hdrChart) hdrChart.destroy();
    
    if (!hdrData || hdrData.length === 0) {
        return;
    }
    
    // Calculate rates and create sortable array
    var chartData = [];
    for (var i = 0; i < hdrData.length; i++) {
        var total = parseInt(hdrData[i].totalEvents);
        var high = parseInt(hdrData[i].highImpactEvents);
        var rate = total > 0 ? (high / total) * 100 : 0;
        chartData.push({
            label: hdrData[i].CategoryName,
            rate: rate
        });
    }
    
    // Sort by rate from highest to lowest
    chartData.sort(function(a, b) {
        return b.rate - a.rate;
    });
    
    // Extract sorted data
    var labels = [];
    var rates = [];
    var colors = [];
    for (var i = 0; i < chartData.length; i++) {
        labels.push(chartData[i].label);
        rates.push(chartData[i].rate);
        colors.push(chartData[i].rate > 40 ? '#f44336' : (chartData[i].rate > 20 ? '#ff9800' : '#4caf50'));
    }
    
    var ctx = document.getElementById('hdrChart').getContext('2d');
    hdrChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'High-Impact Rate (%)',
                data: rates,
                backgroundColor: colors
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
                legend: { labels: { color: 'white' } }
            }
        }
    });
}
    
    // Update Severity Chart
    function updateSeverityChart(severityData) {
    if (severityChart) severityChart.destroy();
    
    if (!severityData || severityData.length === 0) {
        return;
    }
    
    var colorMap = {'Low': '#4caf50', 'Medium': '#ff9800', 'High': '#f44336'};
    var severityLevels = ['Low', 'Medium', 'High'];
    
    // Extract unique time periods
    var periods = [...new Set(severityData.map(item => item.period))].sort();
    
    // Create a dataset for each severity level
    var datasets = severityLevels.map(function(level) {
        return {
            label: level,
            data: periods.map(function(period) {
                var item = severityData.find(d => d.ImpactLevel === level && d.period === period);
                return item ? parseInt(item.eventCount) : 0;
            }),
            backgroundColor: colorMap[level]
        };
    });
    
    var ctx = document.getElementById('severityChart').getContext('2d');
    severityChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: periods,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    stacked: true,
                    ticks: { color: 'white' }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: { color: 'white' }
                }
            },
            plugins: { 
                legend: { 
                    labels: { color: 'white' },
                    position: 'bottom'
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            }
        }
    });
}
    
    // Update ART Histogram
    function updateARTHistogram(recoveryData) {
        if (artHistogramChart) artHistogramChart.destroy();
        
        if (!recoveryData || recoveryData.length === 0) {
            return;
        }
        
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
        
        var ctx = document.getElementById('artHistogram').getContext('2d');
        artHistogramChart = new Chart(ctx, {
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
    
    // Update TD Histogram
    function updateTDHistogram(downtimeData) {
        if (tdHistogramChart) tdHistogramChart.destroy();
        
        if (!downtimeData || downtimeData.length === 0) {
            return;
        }
        
        var labels = [];
        var values = [];
        
        for (var i = 0; i < downtimeData.length; i++) {
            labels.push(downtimeData[i].CategoryName);
            values.push(parseInt(downtimeData[i].totalDowntime));
        }
        
        var ctx = document.getElementById('tdHistogram').getContext('2d');
        tdHistogramChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Downtime (Days)',
                    data: values,
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
    
    function updateTDHistogramRegion(downtimeDataRegion) {
    if (tdHistogramRegion) tdHistogramRegion.destroy();
    
    if (!downtimeDataRegion || downtimeDataRegion.length === 0) {
        return;
    }
    
    // Aggregate downtime by region
    var regionTotals = {};
    for (var i = 0; i < downtimeDataRegion.length; i++) {
        var region = downtimeDataRegion[i].RegionName; 
        var downtime = parseInt(downtimeDataRegion[i].totalDowntime);
        
        if (regionTotals[region]) {
            regionTotals[region] += downtime;
        } else {
            regionTotals[region] = downtime;
        }
    }
    
    // Convert to arrays and sort by downtime (highest to lowest)
    var chartData = [];
    for (var region in regionTotals) {
        chartData.push({
            label: region,
            value: regionTotals[region]
        });
    }
    chartData.sort(function(a, b) {
        return b.value - a.value;
    });
    
    var labels = [];
    var values = [];
    for (var i = 0; i < chartData.length; i++) {
        labels.push(chartData[i].label);
        values.push(chartData[i].value);
    }
    
    var ctx = document.getElementById('tdHistogramRegion').getContext('2d');
    tdHistogramRegion = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Total Downtime (Days)',
                data: values,
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

function updateTDHistogramTier(downtimeDataTier) {
    if (tdHistogramTier) tdHistogramTier.destroy();
    
    if (!downtimeDataTier || downtimeDataTier.length === 0) {
        return;
    }
    
    // Create sortable array
    var chartData = [];
    for (var i = 0; i < downtimeDataTier.length; i++) {
        chartData.push({
            label: 'Tier ' + downtimeDataTier[i].TierLevel,
            value: parseInt(downtimeDataTier[i].totalDowntime)
        });
    }
    
    // Sort by downtime from highest to lowest
    chartData.sort(function(a, b) {
        return b.value - a.value;
    });
    
    var labels = [];
    var values = [];
    for (var i = 0; i < chartData.length; i++) {
        labels.push(chartData[i].label);
        values.push(chartData[i].value);
    }
    
    var ctx = document.getElementById('tdHistogramTier').getContext('2d');
    tdHistogramTier = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Total Downtime (Days)',
                data: values,
                backgroundColor: '#ff9800'
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

    // Update DF Bar Chart
    function updateDFBarChart(companyData) {
        if (dfBarChart) dfBarChart.destroy();
        
        if (!companyData || companyData.length === 0) {
            return;
        }
        
        var start = new Date(document.getElementById('start_date').value);
        var end = new Date(document.getElementById('end_date').value);
        var months = Math.max(1, Math.round((end - start) / (1000 * 60 * 60 * 24 * 30)));
        
        var labels = [];
        var freqData = [];
        var topCompanies = companyData.slice(0, 10);
        
        for (var i = 0; i < topCompanies.length; i++) {
            labels.push(topCompanies[i].CompanyName);
            var freq = months > 0 ? topCompanies[i].disruptionCount / months : 0;
            freqData.push(freq);
        }
        
        var ctx = document.getElementById('dfBarChart').getContext('2d');
        dfBarChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Disruptions per Month',
                    data: freqData,
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
    
    // Build the company table
    function buildTable(companies) {
        if (!companies || companies.length === 0) {
            document.getElementById('tableWrapper').innerHTML = 
                '<p style="text-align:center;padding:40px;color:var(--text-light)">No disruption data found.</p>';
            return;
        }
        
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
    
    // Build the disruption events table
    function buildEventsTable(events) {
        if (!events || events.length === 0) {
            document.getElementById('eventsTableBody').innerHTML = 
                '<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-light)">No disruption events found.</td></tr>';
            return;
        }
        
        var html = '';
        for (var i = 0; i < events.length; i++) {
            var e = events[i];
            var eventDate = new Date(e.EventDate);
            var recoveryDate = e.EventRecoveryDate ? new Date(e.EventRecoveryDate) : null;
            
            var eventDateStr = eventDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            var recoveryDateStr = recoveryDate ? recoveryDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '<span style="color: #ff6b6b;">N/A</span>';
            
            var statusClass = e.Status === 'Ongoing' ? 'status-ongoing' : 'status-recovered';
            
            var companiesDisplay = esc(e.AffectedCompanies);
            if (e.CompanyCount > 3) {
                companiesDisplay += ' <small>(' + e.CompanyCount + ' total)</small>';
            }
            
            html += '<tr>' +
                '<td>' + e.EventID + '</td>' +
                '<td><strong>' + esc(e.CategoryName) + '</strong></td>' +
                '<td>' + eventDateStr + '</td>' +
                '<td>' + recoveryDateStr + '</td>' +
                '<td>' + e.Duration + '</td>' +
                '<td><span class="' + statusClass + '">' + esc(e.Status) + '</span></td>' +
                '<td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="' + esc(e.AffectedCompanies) + '">' + 
                    companiesDisplay + 
                '</td>' +
                '<td><span class="badge impact-' + e.MaxImpact.toLowerCase() + '" style="padding: 4px 10px; border-radius: 4px; font-size: 0.85rem; font-weight: bold;">' + 
                    esc(e.MaxImpact) + 
                '</span></td>' +
                '</tr>';
        }
        
        document.getElementById('eventsTableBody').innerHTML = html;
    }
    
    // Utility function for escaping HTML
    function esc(t) { 
        if (!t) return '';
        var d = document.createElement('div'); 
        d.textContent = t; 
        return d.innerHTML; 
    }
    
    // Event listeners
    form.addEventListener('submit', function(e) { 
        e.preventDefault(); 
        return false;
    });
    
    document.getElementById('clearBtn').addEventListener('click', function(e) {
        e.preventDefault();
        document.getElementById('start_date').value = '<?= date('Y-m-d', strtotime('-1 year')) ?>';
        document.getElementById('end_date').value = '<?= date('Y-m-d') ?>';
        document.getElementById('company_id').value = '';
        document.getElementById('region').value = '';
        document.getElementById('tier').value = '';
        load();
    });
    
    // Dynamic filter updates - trigger load() on any filter change
    document.getElementById('start_date').addEventListener('change', load);
    document.getElementById('end_date').addEventListener('change', load);
    document.getElementById('company_id').addEventListener('change', load);
    document.getElementById('region').addEventListener('change', load);
    document.getElementById('tier').addEventListener('change', load);
    
    // Initialize charts on page load with PHP data
    (function initCharts() {
        var mapData = <?= json_encode($mapData) ?>;
        var hdrData = <?= json_encode($hdrByCategory) ?>;
        var severityData = <?= json_encode($severityDist) ?>;
        var recoveryData = <?= json_encode($recoveryTimes) ?>;
        var downtimeData = <?= json_encode($downtimeByCategory) ?>;
        var downtimeDataRegion = <?= json_encode($downtimeByRegion) ?>;
        var downtimeDataTier = <?= json_encode($downtimeByTier) ?>;
        var companyData = <?= json_encode($companies) ?>;
        var eventsData = <?= json_encode($disruptionEvents) ?>;
        
        updateRegionalMap(mapData);
        updateHDRChart(hdrData);
        updateSeverityChart(severityData);
        updateARTHistogram(recoveryData);
        updateTDHistogram(downtimeData);
        updateTDHistogramRegion(downtimeDataRegion);
        updateTDHistogramTier(downtimeDataTier);
        updateDFBarChart(companyData);
        buildEventsTable(eventsData);
    })();
    
})();
</script>
</body>
</html>
