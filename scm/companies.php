<?php
// scm/companies.php - Enhanced Company Management with Dynamic Filtering
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config.php';
requireLogin();

if (hasRole('SeniorManager')) {
    header('Location: ../erp/dashboard.php');
    exit;
}

$pdo = getPDO();

// Handle AJAX request for detailed company info
if (isset($_GET['detail_id'])) {
    try {
        $companyID = intval($_GET['detail_id']);
        $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 year'));
        $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
        
        // Get comprehensive company information
        $sql = "SELECT c.*, l.City, l.CountryName, l.ContinentName
                FROM Company c
                LEFT JOIN Location l ON c.LocationID = l.LocationID
                WHERE c.CompanyID = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($companyID));
        $company = $stmt->fetch();
        
        if ($company) {
            // Type-specific info
            if ($company['Type'] === 'Manufacturer') {
                $stmt = $pdo->prepare("SELECT FactoryCapacity FROM Manufacturer WHERE CompanyID = ?");
                $stmt->execute(array($companyID));
                $typeInfo = $stmt->fetch();
                $company['capacity'] = $typeInfo ? intval($typeInfo['FactoryCapacity']) : 0;
            } elseif ($company['Type'] === 'Distributor') {
                $stmt = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(SourceCompanyID, '-', DestinationCompanyID)) as cnt FROM Shipping WHERE DistributorID = ?");
                $stmt->execute(array($companyID));
                $typeInfo = $stmt->fetch();
                $company['uniqueRoutes'] = intval($typeInfo['cnt']);
            }
            
            // Dependencies - who they depend on
            $stmt = $pdo->prepare("SELECT c.CompanyID, c.CompanyName, c.Type FROM DependsOn d JOIN Company c ON d.UpstreamCompanyID = c.CompanyID WHERE d.DownstreamCompanyID = ?");
            $stmt->execute(array($companyID));
            $company['dependsOn'] = $stmt->fetchAll();
            
            // Who depends on them
            $stmt = $pdo->prepare("SELECT c.CompanyID, c.CompanyName, c.Type FROM DependsOn d JOIN Company c ON d.DownstreamCompanyID = c.CompanyID WHERE d.UpstreamCompanyID = ?");
            $stmt->execute(array($companyID));
            $company['dependedBy'] = $stmt->fetchAll();
            
            // Products supplied
            $stmt = $pdo->prepare("SELECT p.ProductID, p.ProductName, p.Category FROM SuppliesProduct sp JOIN Product p ON sp.ProductID = p.ProductID WHERE sp.SupplierID = ?");
            $stmt->execute(array($companyID));
            $company['products'] = $stmt->fetchAll();
            // Product diversity (count of unique categories)
            $categories = array();
            foreach ($company['products'] as $prod) {
                $categories[] = $prod['Category'];
            }
            $company['productDiversity'] = count(array_unique($categories));
            
            // Financial history (past 4 quarters)
            $stmt = $pdo->prepare("SELECT RepYear, Quarter, HealthScore FROM FinancialReport WHERE CompanyID = ? ORDER BY RepYear DESC, FIELD(Quarter, 'Q4', 'Q3', 'Q2', 'Q1') LIMIT 4");
            $stmt->execute(array($companyID));
            $company['financialHistory'] = $stmt->fetchAll();
            
            // KPIs - On-time delivery rate
            $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN ActualDate <= PromisedDate THEN 1 ELSE 0 END) as onTime FROM Shipping WHERE (SourceCompanyID = ? OR DestinationCompanyID = ?) AND ActualDate IS NOT NULL AND PromisedDate BETWEEN ? AND ?");
            $stmt->execute(array($companyID, $companyID, $startDate, $endDate));
            $delivery = $stmt->fetch();
            $company['totalDeliveries'] = intval($delivery['total']);
            $company['onTimeDeliveries'] = intval($delivery['onTime']);
            $company['onTimeRate'] = $delivery['total'] > 0 ? round(($delivery['onTime'] / $delivery['total']) * 100, 1) : 0;
            
            // KPIs - Average and stdev of delay
            $stmt = $pdo->prepare("SELECT AVG(DATEDIFF(ActualDate, PromisedDate)) as avgDelay, STDDEV(DATEDIFF(ActualDate, PromisedDate)) as stdDelay FROM Shipping WHERE (SourceCompanyID = ? OR DestinationCompanyID = ?) AND ActualDate IS NOT NULL AND ActualDate > PromisedDate AND PromisedDate BETWEEN ? AND ?");
            $stmt->execute(array($companyID, $companyID, $startDate, $endDate));
            $delayStats = $stmt->fetch();
            $company['avgDelay'] = $delayStats['avgDelay'] ? round($delayStats['avgDelay'], 1) : 0;
            $company['stdDelay'] = $delayStats['stdDelay'] ? round($delayStats['stdDelay'], 1) : 0;
            
            // Disruption events
            $stmt = $pdo->prepare("SELECT de.EventID, de.EventDate, de.EventRecoveryDate, dc.CategoryName, ic.ImpactLevel, DATEDIFF(de.EventRecoveryDate, de.EventDate) as recoveryDays FROM DisruptionEvent de JOIN ImpactsCompany ic ON de.EventID = ic.EventID JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID WHERE ic.AffectedCompanyID = ? AND de.EventDate BETWEEN ? AND ? ORDER BY de.EventDate DESC");
            $stmt->execute(array($companyID, $startDate, $endDate));
            $company['disruptions'] = $stmt->fetchAll();
            
            // Disruption distribution by category
            $disruptionCounts = array();
            foreach ($company['disruptions'] as $dis) {
                $cat = $dis['CategoryName'];
                $disruptionCounts[$cat] = isset($disruptionCounts[$cat]) ? $disruptionCounts[$cat] + 1 : 1;
            }
            $company['disruptionDistribution'] = $disruptionCounts;
            
            // Transactions - Shipping
            $stmt = $pdo->prepare("SELECT s.ShipmentID, s.PromisedDate, s.ActualDate, s.Quantity, p.ProductName, dest.CompanyName as DestName FROM Shipping s JOIN Product p ON s.ProductID = p.ProductID JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID WHERE s.SourceCompanyID = ? AND s.PromisedDate BETWEEN ? AND ? ORDER BY s.PromisedDate DESC LIMIT 50");
            $stmt->execute(array($companyID, $startDate, $endDate));
            $company['shipping'] = $stmt->fetchAll();
            
            // Transactions - Receiving
            try {
                $stmt = $pdo->prepare("SELECT r.TransactionID as ReceivingID, r.ReceivedDate, r.QuantityReceived, s.ProductID, p.ProductName, src.CompanyName as SrcName 
                    FROM Receiving r 
                    JOIN Shipping s ON r.ShipmentID = s.ShipmentID 
                    JOIN Product p ON s.ProductID = p.ProductID 
                    JOIN Company src ON s.SourceCompanyID = src.CompanyID 
                    WHERE r.ReceiverCompanyID = ? AND r.ReceivedDate BETWEEN ? AND ? 
                    ORDER BY r.ReceivedDate DESC LIMIT 50");
                $stmt->execute(array($companyID, $startDate, $endDate));
                $company['receiving'] = $stmt->fetchAll();
            } catch (Exception $e) {
                $company['receiving'] = array();
            }
            
            // Transactions - Inventory Adjustments
            try {
                $stmt = $pdo->prepare("SELECT it.TransactionID, it.QuantityChange, it.Type, p.ProductName 
                    FROM InventoryTransaction it 
                    JOIN Product p ON it.ProductID = p.ProductID 
                    WHERE it.CompanyID = ? 
                    ORDER BY it.TransactionID DESC LIMIT 50");
                $stmt->execute(array($companyID));
                $company['adjustments'] = $stmt->fetchAll();
            } catch (Exception $e) {
                $company['adjustments'] = array();
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode(array('success' => true, 'company' => $company));
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(array('success' => false, 'error' => $e->getMessage()));
        exit;
    }
}

// Handle company update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_company') {
        $companyID = $_POST['company_id'];
        $companyName = $_POST['company_name'];
        $tierLevel = $_POST['tier_level'];
        
        $sql = "UPDATE Company SET CompanyName = :name, TierLevel = :tier WHERE CompanyID = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(':name' => $companyName, ':tier' => $tierLevel, ':id' => $companyID));
        
        header('Location: companies.php?updated=1');
        exit;
    }
}

// Get filters
$companyID = isset($_GET['company_id']) ? $_GET['company_id'] : '';
$tierLevel = isset($_GET['tier']) ? $_GET['tier'] : '';
$companyType = isset($_GET['type']) ? $_GET['type'] : '';
$region = isset($_GET['region']) ? $_GET['region'] : '';

// Build query
$where = array('1=1');
$params = array();

if (!empty($companyID)) {
    $where[] = "c.CompanyID = :companyID";
    $params[':companyID'] = $companyID;
}
if (!empty($tierLevel)) {
    $where[] = "c.TierLevel = :tier";
    $params[':tier'] = $tierLevel;
}
if (!empty($companyType)) {
    $where[] = "c.Type = :type";
    $params[':type'] = $companyType;
}
if (!empty($region)) {
    $where[] = "l.ContinentName = :region";
    $params[':region'] = $region;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT c.CompanyID, c.CompanyName, c.TierLevel, c.Type, l.ContinentName, l.CountryName, l.City
        FROM Company c
        LEFT JOIN Location l ON c.LocationID = l.LocationID
        $whereClause
        ORDER BY c.CompanyName ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll();

// Get quick stats for each company
foreach ($companies as $key => $comp) {
    $cid = $comp['CompanyID'];
    
    $stmt2 = $pdo->prepare("SELECT COUNT(*) as cnt FROM Shipping WHERE SourceCompanyID = ? OR DestinationCompanyID = ?");
    $stmt2->execute(array($cid, $cid));
    $companies[$key]['totalShipments'] = $stmt2->fetch()['cnt'];
    
    $stmt3 = $pdo->prepare("SELECT COUNT(DISTINCT de.EventID) as cnt FROM DisruptionEvent de JOIN ImpactsCompany ic ON de.EventID = ic.EventID WHERE ic.AffectedCompanyID = ?");
    $stmt3->execute(array($cid));
    $companies[$key]['disruptionCount'] = $stmt3->fetch()['cnt'];
    
    $stmt4 = $pdo->prepare("SELECT HealthScore FROM FinancialReport WHERE CompanyID = ? ORDER BY RepYear DESC, FIELD(Quarter, 'Q4', 'Q3', 'Q2', 'Q1') LIMIT 1");
    $stmt4->execute(array($cid));
    $result = $stmt4->fetch();
    $companies[$key]['latestHealthScore'] = $result ? $result['HealthScore'] : null;
    
    $stmt5 = $pdo->prepare("SELECT COUNT(DISTINCT ProductID) as cnt FROM SuppliesProduct WHERE SupplierID = ?");
    $stmt5->execute(array($cid));
    $companies[$key]['productCount'] = $stmt5->fetch()['cnt'];
}

// AJAX response
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(array('success' => true, 'companies' => $companies));
    exit;
}

// Get filter options for initial page load
$allCompanies = $pdo->query("SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName")->fetchAll();
$allRegions = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Companies - SCM</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
       .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
        .company-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin: 30px 0; }
        .company-grid-wrapper { 
            max-height: 600px; 
            overflow-y: auto; 
            padding: 20px; 
            background: rgba(0,0,0,0.3);
            border: 2px solid rgba(207,185,145,0.3);
            border-radius: 8px;
            margin-top: 20px;
        }
        .company-grid-wrapper::-webkit-scrollbar { width: 12px; }
        .company-grid-wrapper::-webkit-scrollbar-track { background: rgba(0,0,0,0.5); border-radius: 6px; }
        .company-grid-wrapper::-webkit-scrollbar-thumb { background: #CFB991; border-radius: 6px; }
        .company-grid-wrapper::-webkit-scrollbar-thumb:hover { background: #b89968; }
        .company-card { background: rgba(0,0,0,0.6); padding: 24px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); transition: all 0.3s; }
        .company-card:hover { border-color: var(--purdue-gold); transform: translateY(-2px); }
        .company-card h3 { margin: 0 0 10px 0; color: var(--purdue-gold); font-size: 1.3rem; }
        .company-card .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 15px 0; font-size: 0.9rem; }
        .company-card .meta div { color: var(--text-light); }
        .company-card .meta strong { color: var(--purdue-gold); }
        .kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(207,185,145,0.3); }
        .kpi-item { text-align: center; }
        .kpi-item .value { font-size: 1.5rem; font-weight: bold; color: var(--purdue-gold); }
        .kpi-item .label { font-size: 0.75rem; color: var(--text-light); margin-top: 4px; }
        .health-score { display: inline-block; padding: 4px 12px; border-radius: 12px; font-weight: bold; font-size: 0.9rem; }
        .health-good { background: #4caf50; color: white; }
        .health-warning { background: #ff9800; color: white; }
        .health-bad { background: #f44336; color: white; }
        .tier-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; font-weight: bold; background: rgba(207,185,145,0.2); color: var(--purdue-gold); }
        .actions { margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(207,185,145,0.3); display: flex; gap: 10px; flex-wrap: wrap; }
        .actions a, .actions button { display: inline-block; padding: 8px 16px; background: var(--purdue-gold); color: black; text-decoration: none; border-radius: 4px; font-size: 0.9rem; border: none; cursor: pointer; }
        .actions a:hover, .actions button:hover { background: #d4c49e; }
        .actions .btn-details { background: rgba(207,185,145,0.3); color: var(--purdue-gold); border: 1px solid var(--purdue-gold); }
        .actions .btn-details:hover { background: rgba(207,185,145,0.4); }
        
        /* Detail Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 1000; overflow-y: auto; }
        .modal.active { display: block; }
        .modal-content { background: #1a1a1a; border: 2px solid var(--purdue-gold); border-radius: 8px; max-width: 1200px; margin: 30px auto; padding: 30px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h2 { color: var(--purdue-gold); margin: 0; }
        .close-btn { background: #f44336; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; }
        .close-btn:hover { background: #d32f2f; }
        .info-tabs { display: flex; gap: 10px; margin: 20px 0; border-bottom: 2px solid rgba(207,185,145,0.3); }
        .tab-btn { padding: 10px 20px; background: transparent; border: none; border-bottom: 3px solid transparent; color: rgba(255,255,255,0.6); cursor: pointer; font-size: 1rem; font-weight: bold; }
        .tab-btn.active { color: var(--purdue-gold); border-bottom-color: var(--purdue-gold); }
        .tab-btn:hover { color: var(--purdue-gold); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0; }
        .info-section { background: rgba(0,0,0,0.6); padding: 20px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); }
        .info-section h3 { margin-top: 0; color: var(--purdue-gold); border-bottom: 2px solid rgba(207,185,145,0.3); padding-bottom: 10px; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(207,185,145,0.1); }
        .info-label { color: rgba(255,255,255,0.7); font-weight: 600; }
        .info-value { color: var(--purdue-gold); font-weight: bold; }
        .dependency-list, .product-list { list-style: none; padding: 0; margin: 10px 0; }
        .dependency-list li, .product-list li { padding: 8px; background: rgba(207,185,145,0.1); margin: 5px 0; border-radius: 4px; }
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
        .product-badge { padding: 10px; background: rgba(207,185,145,0.2); border-radius: 4px; text-align: center; }
        .kpi-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .kpi-card-detail { background: rgba(0,0,0,0.6); padding: 20px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); text-align: center; }
        .kpi-card-detail h4 { margin: 0; font-size: 2rem; color: var(--purdue-gold); }
        .kpi-card-detail p { margin: 8px 0 0 0; color: rgba(255,255,255,0.8); }
        .transaction-table-wrapper { max-height: 400px; overflow-y: auto; border: 2px solid rgba(207,185,145,0.3); border-radius: 8px; background: rgba(0,0,0,0.3); }
        .transaction-table-wrapper::-webkit-scrollbar { width: 10px; }
        .transaction-table-wrapper::-webkit-scrollbar-track { background: rgba(0,0,0,0.5); }
        .transaction-table-wrapper::-webkit-scrollbar-thumb { background: #CFB991; border-radius: 5px; }
        table { width: 100%; border-collapse: collapse; }
        table th { background: rgba(207,185,145,0.3); color: var(--purdue-gold); padding: 10px; text-align: left; position: sticky; top: 0; }
        table td { padding: 8px 10px; border-bottom: 1px solid rgba(207,185,145,0.1); }
        .chart-container { background: rgba(0,0,0,0.6); padding: 20px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); }
        .chart-wrapper { position: relative; height: 300px; }
        .date-filter-bar { background: rgba(0,0,0,0.6); padding: 15px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .date-filter-bar label { color: var(--purdue-gold); font-weight: bold; }
        .date-filter-bar input { padding: 8px; border: 1px solid var(--purdue-gold); background: rgba(0,0,0,0.5); color: white; border-radius: 4px; }
        .date-filter-bar button { padding: 8px 20px; background: var(--purdue-gold); color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .impact-high { background: #f44336; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; }
        .impact-medium { background: #ff9800; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; }
        .impact-low { background: #4caf50; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; }
        .loading { text-align: center; padding: 40px; color: var(--purdue-gold); }
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
        <a href="companies.php" class="active">Companies</a>
        <a href="kpis.php">KPIs</a>
        <a href="disruptions.php">Disruptions</a>
        <a href="transactions.php">Transactions</a>
        <a href="transaction_costs.php">Cost Analysis</a>
        <a href="distributors.php">Distributors</a>
    </nav>

    <div class="container">
        <h2>Company Management</h2>

        <?php if (isset($_GET['updated'])): ?>
        <div style="background: rgba(76,175,80,0.2); border: 2px solid #4caf50; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <p style="margin: 0; color: #4caf50;">✓ Company updated successfully!</p>
        </div>
        <?php endif; ?>

        <div class="content-section">
            <h3>Search & Filter Companies</h3>
            <form id="filterForm">
                <div class="filter-grid">
                    <div>
                        <label>Company Name:</label>
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
                        <label>Tier Level:</label>
                        <select id="tier">
                            <option value="">All Tiers</option>
                            <option value="1" <?= $tierLevel == '1' ? 'selected' : '' ?>>Tier 1</option>
                            <option value="2" <?= $tierLevel == '2' ? 'selected' : '' ?>>Tier 2</option>
                            <option value="3" <?= $tierLevel == '3' ? 'selected' : '' ?>>Tier 3</option>
                        </select>
                    </div>
                    <div>
                        <label>Company Type:</label>
                        <select id="type">
                            <option value="">All Types</option>
                            <option value="Manufacturer" <?= $companyType == 'Manufacturer' ? 'selected' : '' ?>>Manufacturer</option>
                            <option value="Distributor" <?= $companyType == 'Distributor' ? 'selected' : '' ?>>Distributor</option>
                            <option value="Retailer" <?= $companyType == 'Retailer' ? 'selected' : '' ?>>Retailer</option>
                        </select>
                    </div>
                    <div>
                        <label>Region:</label>
                        <select id="region">
                            <option value="">All Regions</option>
                            <?php foreach ($allRegions as $r): ?>
                                <option value="<?= $r['ContinentName'] ?>" <?= $region == $r['ContinentName'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['ContinentName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="button" id="clearBtn" class="btn-secondary">Clear Filters</button>
                </div>
            </form>
        </div>

        <div style="margin: 20px 0; color: var(--text-light);">
            <p>Showing <strong id="companyCount"><?= count($companies) ?></strong> companies</p>
        </div>

        <!-- Scrollable Company Cards Container -->
        <div class="company-grid-wrapper">
            <div id="companyGrid" class="company-grid">
                <?php if (count($companies) > 0): ?>
                    <?php foreach ($companies as $comp): ?>
                    <div class="company-card">
                        <h3><?= htmlspecialchars($comp['CompanyName']) ?></h3>
                        
                        <div class="meta">
                            <div><strong>Type:</strong> <?= htmlspecialchars($comp['Type']) ?></div>
                            <div><strong>Tier:</strong> <span class="tier-badge">Tier <?= $comp['TierLevel'] ?></span></div>
                            <div><strong>Location:</strong> <?= htmlspecialchars($comp['City']) ?>, <?= htmlspecialchars($comp['CountryName']) ?></div>
                            <div><strong>Region:</strong> <?= htmlspecialchars($comp['ContinentName']) ?></div>
                        </div>
                        
                        <?php if ($comp['latestHealthScore'] !== null): ?>
                        <div style="margin-top: 10px;">
                            <strong>Financial Health:</strong> 
                            <?php 
                            $score = floatval($comp['latestHealthScore']);
                            $healthClass = 'health-bad';
                            if ($score >= 75) {
                                $healthClass = 'health-good';
                            } elseif ($score >= 50) {
                                $healthClass = 'health-warning';
                            }
                            ?>
                            <span class="health-score <?= $healthClass ?>"><?= round($score, 1) ?>/100</span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- KPI Row -->
                        <div class="kpi-row">
                            <div class="kpi-item">
                                <div class="value"><?= $comp['totalShipments'] ?></div>
                                <div class="label">Shipments</div>
                            </div>
                            <div class="kpi-item">
                                <div class="value"><?= $comp['productCount'] ?></div>
                                <div class="label">Products</div>
                            </div>
                            <div class="kpi-item">
                                <div class="value" style="color: <?= $comp['disruptionCount'] > 5 ? '#f44336' : '#CFB991' ?>">
                                    <?= $comp['disruptionCount'] ?>
                                </div>
                                <div class="label">Disruptions</div>
                            </div>
                            <div class="kpi-item">
                                <div class="value"><?= $comp['CompanyID'] ?></div>
                                <div class="label">ID</div>
                            </div>
                        </div>
                        
                        <!-- Actions -->
                        <div class="actions">
                            <button class="btn-details" onclick="showCompanyDetail(<?= $comp['CompanyID'] ?>)">📋 View Full Details</button>
                            <a href="#" onclick="openEditModal(<?= $comp['CompanyID'] ?>, '<?= addslashes($comp['CompanyName']) ?>', <?= $comp['TierLevel'] ?>); return false;">✏️ Edit</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align: center; padding: 40px; color: var(--text-light); grid-column: 1/-1;">
                        No companies found matching your search criteria.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <h3>Edit Company</h3>
            <form method="POST" action="companies.php">
                <input type="hidden" name="action" value="update_company">
                <input type="hidden" name="company_id" id="edit_company_id">
                
                <label>Company Name:</label>
                <input type="text" name="company_name" id="edit_company_name" required style="width: 100%; padding: 10px; margin: 10px 0; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px;">
                
                <label>Tier Level:</label>
                <select name="tier_level" id="edit_tier_level" required style="width: 100%; padding: 10px; margin: 10px 0; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px;">
                    <option value="1">Tier 1</option>
                    <option value="2">Tier 2</option>
                    <option value="3">Tier 3</option>
                </select>
                
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit" style="padding: 10px 20px; background: var(--purdue-gold); color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Save Changes</button>
                    <button type="button" class="btn-secondary" onclick="closeEditModal()" style="padding: 10px 20px; background: rgba(255,255,255,0.1); color: white; border: 1px solid rgba(207,185,145,0.3); border-radius: 4px; cursor: pointer;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Comprehensive Detail Modal (keeping all existing modal content) -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="detailCompanyName">Company Details</h2>
                <button class="close-btn" onclick="closeDetailModal()">✕ Close</button>
            </div>

            <div class="date-filter-bar">
                <label>Date Range for KPIs & Transactions:</label>
                <label>Start: <input type="date" id="detail_start_date" value="<?= date('Y-m-d', strtotime('-1 year')) ?>"></label>
                <label>End: <input type="date" id="detail_end_date" value="<?= date('Y-m-d') ?>"></label>

            </div>

            <div class="info-tabs">
                <button class="tab-btn active" onclick="switchDetailTab('overview')">Overview</button>
                <button class="tab-btn" onclick="switchDetailTab('dependencies')">Dependencies</button>
                <button class="tab-btn" onclick="switchDetailTab('products')">Products</button>
                <button class="tab-btn" onclick="switchDetailTab('kpis')">KPIs</button>
                <button class="tab-btn" onclick="switchDetailTab('disruptions')">Disruptions</button>
                <button class="tab-btn" onclick="switchDetailTab('transactions')">Transactions</button>
            </div>

            <div id="detail-content">
                <div class="loading">Loading company details...</div>
            </div>
        </div>
    </div>

    <script>
    var currentCompanyID = null;
    var detailChart1 = null;
    var detailChart2 = null;

    // Dynamic filtering - trigger load on any dropdown change
    (function() {
        var companySelect = document.getElementById('company_id');
        var tierSelect = document.getElementById('tier');
        var typeSelect = document.getElementById('type');
        var regionSelect = document.getElementById('region');
        var clearBtn = document.getElementById('clearBtn');
        
        // Load function to fetch filtered companies
        function load() {
            document.getElementById('companyGrid').innerHTML = '<div class="loading" style="grid-column: 1/-1;">Loading companies...</div>';
            
            var params = 'ajax=1&company_id=' + encodeURIComponent(companySelect.value) +
                        '&tier=' + encodeURIComponent(tierSelect.value) +
                        '&type=' + encodeURIComponent(typeSelect.value) +
                        '&region=' + encodeURIComponent(regionSelect.value);
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'companies.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        buildCards(response.companies);
                    }
                }
            };
            xhr.send();
        }
        
        // Build company cards from data
        function buildCards(companies) {
            document.getElementById('companyCount').textContent = companies.length;
            
            if (companies.length === 0) {
                document.getElementById('companyGrid').innerHTML = '<p style="text-align:center;padding:40px;color:var(--text-light);grid-column:1/-1">No companies found matching your filters.</p>';
                return;
            }
            
            var html = '';
            companies.forEach(function(c) {
                var healthClass = 'health-bad';
                var healthScore = c.latestHealthScore ? parseFloat(c.latestHealthScore) : null;
                if (healthScore !== null) {
                    if (healthScore >= 75) healthClass = 'health-good';
                    else if (healthScore >= 50) healthClass = 'health-warning';
                }
                
                html += '<div class="company-card">';
                html += '<h3>' + esc(c.CompanyName) + '</h3>';
                html += '<div class="meta">';
                html += '<div><strong>Type:</strong> ' + esc(c.Type) + '</div>';
                html += '<div><strong>Tier:</strong> <span class="tier-badge">Tier ' + c.TierLevel + '</span></div>';
                html += '<div><strong>Location:</strong> ' + esc(c.City) + ', ' + esc(c.CountryName) + '</div>';
                html += '<div><strong>Region:</strong> ' + esc(c.ContinentName) + '</div>';
                html += '</div>';
                
                if (healthScore !== null) {
                    html += '<div style="margin-top:10px"><strong>Financial Health:</strong> <span class="health-score ' + healthClass + '">' + healthScore.toFixed(1) + '/100</span></div>';
                }
                
                html += '<div class="kpi-row">';
                html += '<div class="kpi-item"><div class="value">' + c.totalShipments + '</div><div class="label">Shipments</div></div>';
                html += '<div class="kpi-item"><div class="value">' + c.productCount + '</div><div class="label">Products</div></div>';
                html += '<div class="kpi-item"><div class="value" style="color:' + (c.disruptionCount > 5 ? '#f44336' : '#CFB991') + '">' + c.disruptionCount + '</div><div class="label">Disruptions</div></div>';
                html += '<div class="kpi-item"><div class="value">' + c.CompanyID + '</div><div class="label">ID</div></div>';
                html += '</div>';
                
                html += '<div class="actions">';
                html += '<button class="btn-details" onclick="showCompanyDetail(' + c.CompanyID + ')">📋 View Full Details</button>';
                html += '<a href="#" onclick="openEditModal(' + c.CompanyID + ', \'' + esc(c.CompanyName).replace(/'/g, "\\'") + '\', ' + c.TierLevel + '); return false;">✏️ Edit</a>';
                html += '</div></div>';
            });
            
            document.getElementById('companyGrid').innerHTML = html;
        }
        
        // Add change event listeners to all dropdowns for dynamic filtering
        companySelect.addEventListener('change', load);
        tierSelect.addEventListener('change', load);
        typeSelect.addEventListener('change', load);
        regionSelect.addEventListener('change', load);
        
        // Clear button resets all filters and reloads
        clearBtn.addEventListener('click', function() {
            companySelect.value = '';
            tierSelect.value = '';
            typeSelect.value = '';
            regionSelect.value = '';
            load();
        });
    })();

    // Company detail modal functions (keeping all existing functionality)
    function showCompanyDetail(companyID) {
        currentCompanyID = companyID;
        
        var btns = document.querySelectorAll('#detailModal .info-tabs .tab-btn');
        btns.forEach(function(btn) { btn.classList.remove('active'); });
        btns[0].classList.add('active');
        
        document.getElementById('detailModal').classList.add('active');
        document.getElementById('detail-content').innerHTML = '<div class="loading">Loading company details...</div>';
        loadCompanyDetail();
    }

    function closeDetailModal() {
        document.getElementById('detailModal').classList.remove('active');
        if (detailChart1) detailChart1.destroy();
        if (detailChart2) detailChart2.destroy();
    }

    function refreshCompanyDetail() {
        loadCompanyDetail();
    }

    function loadCompanyDetail() {
        var startDate = document.getElementById('detail_start_date').value;
        var endDate = document.getElementById('detail_end_date').value;
        
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'companies.php?detail_id=' + currentCompanyID + '&start_date=' + startDate + '&end_date=' + endDate, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success && response.company) {
                        renderCompanyDetail(response.company);
                    } else {
                        document.getElementById('detail-content').innerHTML = 
                            '<div style="padding: 40px; text-align: center; color: #f44336;">' +
                            '<h3>Error</h3><p>' + (response.error || 'Company not found') + '</p>' +
                            '<button onclick="loadCompanyDetail()" style="padding: 10px 20px; background: #CFB991; color: #000; border: none; border-radius: 4px; cursor: pointer;">Retry</button></div>';
                    }
                } catch (e) {
                    document.getElementById('detail-content').innerHTML = 
                        '<div style="padding: 40px; text-align: center; color: #f44336;">' +
                        '<h3>Error Parsing Response</h3><p>Invalid data received.</p></div>';
                }
            }
        };
        xhr.send();
    }

    // Render comprehensive company details (keeping all existing rendering logic)
    function renderCompanyDetail(c) {
        document.getElementById('detailCompanyName').textContent = c.CompanyName;
        
        var html = '';
        
        // OVERVIEW TAB
        html += '<div id="tab-overview" class="tab-content active">';
        html += '<div class="info-grid">';
        html += '<div class="info-section"><h3>Company Information</h3>';
        html += '<div class="info-row"><span class="info-label">Company ID:</span><span class="info-value">' + c.CompanyID + '</span></div>';
        html += '<div class="info-row"><span class="info-label">Type:</span><span class="info-value">' + esc(c.Type) + '</span></div>';
        html += '<div class="info-row"><span class="info-label">Tier Level:</span><span class="info-value">Tier ' + c.TierLevel + '</span></div>';
        if (c.capacity) html += '<div class="info-row"><span class="info-label">Factory Capacity:</span><span class="info-value">' + c.capacity.toLocaleString() + ' units</span></div>';
        if (c.uniqueRoutes) html += '<div class="info-row"><span class="info-label">Unique Routes:</span><span class="info-value">' + c.uniqueRoutes + ' routes</span></div>';
        html += '</div>';
        
        html += '<div class="info-section"><h3>Address</h3>';
        html += '<div class="info-row"><span class="info-label">City:</span><span class="info-value">' + esc(c.City) + '</span></div>';
        html += '<div class="info-row"><span class="info-label">Country:</span><span class="info-value">' + esc(c.CountryName) + '</span></div>';
        html += '<div class="info-row"><span class="info-label">Region:</span><span class="info-value">' + esc(c.ContinentName) + '</span></div>';
        html += '</div>';
        
        html += '<div class="info-section"><h3>Most Recent Financial Status</h3>';
        if (c.financialHistory && c.financialHistory.length > 0) {
            var latest = c.financialHistory[0];
            var score = parseFloat(latest.HealthScore);
            var healthClass = score >= 75 ? 'health-good' : (score >= 50 ? 'health-warning' : 'health-bad');
            html += '<div class="info-row"><span class="info-label">Quarter:</span><span class="info-value">' + latest.Quarter + ' ' + latest.RepYear + '</span></div>';
            html += '<div class="info-row"><span class="info-label">Health Score:</span><span class="health-score ' + healthClass + '">' + score.toFixed(1) + '/100</span></div>';
        } else {
            html += '<p style="color: rgba(255,255,255,0.5);">No financial data available</p>';
        }
        html += '</div></div></div>';
        
        // DEPENDENCIES TAB
        html += '<div id="tab-dependencies" class="tab-content">';
        html += '<div class="info-grid">';
        html += '<div class="info-section"><h3>Depends On (Upstream Suppliers)</h3>';
        if (c.dependsOn && c.dependsOn.length > 0) {
            html += '<ul class="dependency-list">';
            c.dependsOn.forEach(function(dep) {
                html += '<li>' + esc(dep.CompanyName) + ' <small>(' + dep.Type + ')</small></li>';
            });
            html += '</ul>';
        } else {
            html += '<p style="color: rgba(255,255,255,0.5);">No upstream dependencies</p>';
        }
        html += '</div>';
        html += '<div class="info-section"><h3>Depended Upon By (Downstream Customers)</h3>';
        if (c.dependedBy && c.dependedBy.length > 0) {
            html += '<ul class="dependency-list">';
            c.dependedBy.forEach(function(dep) {
                html += '<li>' + esc(dep.CompanyName) + ' <small>(' + dep.Type + ')</small></li>';
            });
            html += '</ul>';
        } else {
            html += '<p style="color: rgba(255,255,255,0.5);">No downstream dependencies</p>';
        }
        html += '</div></div></div>';
        
        // PRODUCTS TAB
        html += '<div id="tab-products" class="tab-content">';
        html += '<div class="info-section">';
        html += '<h3>Products Supplied (' + (c.products ? c.products.length : 0) + ' products, ' + (c.productDiversity || 0) + ' categories)</h3>';
        if (c.products && c.products.length > 0) {
            html += '<div class="product-grid">';
            c.products.forEach(function(prod) {
                html += '<div class="product-badge"><strong>' + esc(prod.ProductName) + '</strong><br><small style="color: rgba(255,255,255,0.7);">' + esc(prod.Category) + '</small></div>';
            });
            html += '</div>';
        } else {
            html += '<p style="color: rgba(255,255,255,0.5); text-align: center; padding: 40px;">No products supplied</p>';
        }
        html += '</div></div>';
        
        // KPIs TAB
        html += '<div id="tab-kpis" class="tab-content">';
        html += '<div class="kpi-cards">';
        html += '<div class="kpi-card-detail"><h4>' + c.onTimeRate + '%</h4><p>On-Time Delivery</p><small style="color: rgba(255,255,255,0.6);">' + c.onTimeDeliveries + ' / ' + c.totalDeliveries + ' shipments</small></div>';
        html += '<div class="kpi-card-detail"><h4>' + c.avgDelay + '</h4><p>Avg Delay (Days)</p><small style="color: rgba(255,255,255,0.6);">±' + c.stdDelay + ' std dev</small></div>';
        html += '<div class="kpi-card-detail"><h4 style="color: ' + (c.disruptions.length > 5 ? '#f44336' : '#CFB991') + '">' + c.disruptions.length + '</h4><p>Disruption Events</p><small style="color: rgba(255,255,255,0.6);">In date range</small></div>';
        html += '</div>';
        
        html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 20px; margin: 20px 0;">';
        html += '<div class="chart-container"><h3 style="margin-top: 0; color: #CFB991;">Financial Health Over Time</h3><div class="chart-wrapper"><canvas id="detailFinancialChart"></canvas></div></div>';
        html += '<div class="chart-container"><h3 style="margin-top: 0; color: #CFB991;">Disruption Distribution</h3><div class="chart-wrapper"><canvas id="detailDisruptionChart"></canvas></div></div>';
        html += '</div></div>';
        
        // DISRUPTIONS TAB
        html += '<div id="tab-disruptions" class="tab-content">';
        html += '<div class="info-section"><h3>Disruption Events (' + c.disruptions.length + ' in date range)</h3>';
        if (c.disruptions.length > 0) {
            html += '<div class="transaction-table-wrapper"><table><thead><tr><th>Date</th><th>Category</th><th>Impact Level</th><th>Recovery Date</th><th>Recovery Time</th></tr></thead><tbody>';
            c.disruptions.forEach(function(dis) {
                var impactClass = 'impact-' + dis.ImpactLevel.toLowerCase();
                html += '<tr><td>' + formatDate(dis.EventDate) + '</td><td>' + esc(dis.CategoryName) + '</td><td><span class="' + impactClass + '">' + dis.ImpactLevel + '</span></td><td>' + (dis.EventRecoveryDate ? formatDate(dis.EventRecoveryDate) : 'Ongoing') + '</td><td>' + (dis.recoveryDays ? dis.recoveryDays + ' days' : 'N/A') + '</td></tr>';
            });
            html += '</tbody></table></div>';
        } else {
            html += '<p style="text-align: center; padding: 40px; color: rgba(255,255,255,0.5);">No disruptions in date range</p>';
        }
        html += '</div></div>';
        
        // TRANSACTIONS TAB
        html += '<div id="tab-transactions" class="tab-content">';
        html += '<div class="info-section"><h3>Transaction History</h3>';
        html += '<div style="display: flex; gap: 10px; margin: 15px 0;">';
        html += '<button class="tab-btn active" onclick="switchTransactionTab(\'shipping\')">Shipping (' + c.shipping.length + ')</button>';
        html += '<button class="tab-btn" onclick="switchTransactionTab(\'receiving\')">Receiving (' + c.receiving.length + ')</button>';
        html += '<button class="tab-btn" onclick="switchTransactionTab(\'adjustments\')">Adjustments (' + c.adjustments.length + ')</button>';
        html += '</div>';
        
        // Shipping
        html += '<div id="txn-shipping" class="transaction-table-wrapper">';
        if (c.shipping.length > 0) {
            html += '<table><thead><tr><th>ID</th><th>Product</th><th>Destination</th><th>Qty</th><th>Promised</th><th>Actual</th><th>Status</th></tr></thead><tbody>';
            c.shipping.forEach(function(txn) {
                var status = '', statusColor = '';
                if (!txn.ActualDate) { status = 'In Transit'; statusColor = '#ff9800'; }
                else if (txn.ActualDate <= txn.PromisedDate) { status = 'On Time'; statusColor = '#4caf50'; }
                else { status = 'Delayed'; statusColor = '#f44336'; }
                html += '<tr><td>' + txn.ShipmentID + '</td><td>' + esc(txn.ProductName) + '</td><td>' + esc(txn.DestName) + '</td><td>' + parseInt(txn.Quantity).toLocaleString() + '</td><td>' + formatDate(txn.PromisedDate) + '</td><td>' + (txn.ActualDate ? formatDate(txn.ActualDate) : '-') + '</td><td style="color:' + statusColor + '">' + status + '</td></tr>';
            });
            html += '</tbody></table>';
        } else {
            html += '<p style="text-align: center; padding: 40px; color: rgba(255,255,255,0.5);">No shipping transactions</p>';
        }
        html += '</div>';
        
        // Receiving
        html += '<div id="txn-receiving" class="transaction-table-wrapper" style="display: none;">';
        if (c.receiving && c.receiving.length > 0) {
            html += '<table><thead><tr><th>ID</th><th>Product</th><th>Source</th><th>Quantity</th><th>Received Date</th></tr></thead><tbody>';
            c.receiving.forEach(function(txn) {
                html += '<tr><td>' + txn.ReceivingID + '</td><td>' + esc(txn.ProductName) + '</td><td>' + esc(txn.SrcName) + '</td><td>' + parseInt(txn.QuantityReceived).toLocaleString() + '</td><td>' + formatDate(txn.ReceivedDate) + '</td></tr>';
            });
            html += '</tbody></table>';
        } else {
            html += '<p style="text-align: center; padding: 40px; color: rgba(255,255,255,0.5);">No receiving transactions</p>';
        }
        html += '</div>';
        
        // Adjustments
        html += '<div id="txn-adjustments" class="transaction-table-wrapper" style="display: none;">';
        if (c.adjustments && c.adjustments.length > 0) {
            html += '<table><thead><tr><th>ID</th><th>Product</th><th>Type</th><th>Qty Change</th></tr></thead><tbody>';
            c.adjustments.forEach(function(txn) {
                var qtyColor = txn.QuantityChange > 0 ? '#4caf50' : '#f44336';
                var qtyText = (txn.QuantityChange > 0 ? '+' : '') + parseInt(txn.QuantityChange).toLocaleString();
                html += '<tr><td>' + txn.TransactionID + '</td><td>' + esc(txn.ProductName) + '</td><td>' + esc(txn.Type) + '</td><td style="color:' + qtyColor + '">' + qtyText + '</td></tr>';
            });
            html += '</tbody></table>';
        } else {
            html += '<p style="text-align: center; padding: 40px; color: rgba(255,255,255,0.5);">No inventory adjustments</p>';
        }
        html += '</div>';
        html += '</div></div>';
        
        document.getElementById('detail-content').innerHTML = html;
        setTimeout(function() { renderDetailCharts(c); }, 100);
    }

    function renderDetailCharts(c) {
        if (c.financialHistory && c.financialHistory.length > 0) {
            var labels = [], scores = [];
            for (var i = c.financialHistory.length - 1; i >= 0; i--) {
                labels.push(c.financialHistory[i].Quarter + ' ' + c.financialHistory[i].RepYear);
                scores.push(parseFloat(c.financialHistory[i].HealthScore));
            }
            var ctx1 = document.getElementById('detailFinancialChart').getContext('2d');
            if (detailChart1) detailChart1.destroy();
            detailChart1 = new Chart(ctx1, {
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
                        y: { beginAtZero: true, max: 100, ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } },
                        x: { ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } }
                    },
                    plugins: { legend: { labels: { color: 'white' } } }
                }
            });
        }
        
        if (c.disruptionDistribution && Object.keys(c.disruptionDistribution).length > 0) {
            var labels = Object.keys(c.disruptionDistribution);
            var counts = Object.values(c.disruptionDistribution);
            var ctx2 = document.getElementById('detailDisruptionChart').getContext('2d');
            if (detailChart2) detailChart2.destroy();
            detailChart2 = new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Event Count',
                        data: counts,
                        backgroundColor: '#CFB991'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, ticks: { color: 'white', stepSize: 1 }, grid: { color: 'rgba(207,185,145,0.1)' } },
                        x: { ticks: { color: 'white' }, grid: { color: 'rgba(207,185,145,0.1)' } }
                    },
                    plugins: { legend: { labels: { color: 'white' } } }
                }
            });
        }
    }

    function switchDetailTab(tabName) {
        var tabs = document.querySelectorAll('#detailModal .tab-content');
        var btns = document.querySelectorAll('#detailModal .info-tabs .tab-btn');
        tabs.forEach(function(tab) { tab.classList.remove('active'); });
        btns.forEach(function(btn) { btn.classList.remove('active'); });
        document.getElementById('tab-' + tabName).classList.add('active');
        event.target.classList.add('active');
    }

    function switchTransactionTab(tabName) {
        var tabs = ['shipping', 'receiving', 'adjustments'];
        tabs.forEach(function(t) { document.getElementById('txn-' + t).style.display = 'none'; });
        document.getElementById('txn-' + tabName).style.display = 'block';
        var btns = document.querySelectorAll('#tab-transactions .tab-btn');
        btns.forEach(function(btn) { btn.classList.remove('active'); });
        event.target.classList.add('active');
    }

    function openEditModal(id, name, tier) {
        document.getElementById('edit_company_id').value = id;
        document.getElementById('edit_company_name').value = name;
        document.getElementById('edit_tier_level').value = tier;
        document.getElementById('editModal').style.display = 'block';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    function esc(t) {
        if (!t) return '';
        var d = document.createElement('div');
        d.textContent = t;
        return d.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        var d = new Date(dateStr);
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }
    </script>
</body>
</html>
