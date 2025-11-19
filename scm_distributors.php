<?php
// scm_distributors.php - Distributor Performance Analytics
require_once 'config.php';
requireLogin();

// Redirect Senior Managers
if (hasRole('SeniorManager')) {
    header('Location: dashboard_erp.php');
    exit;
}

$pdo = getPDO();
$message = '';

// Get all distributors for dropdown
$allDistributors = [];
$sql = "SELECT c.CompanyID, c.CompanyName, l.CountryName, l.ContinentName
        FROM Company c
        JOIN Location l ON c.LocationID = l.LocationID
        WHERE c.Type = 'Distributor'
        ORDER BY c.CompanyName";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$allDistributors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all locations for filter
$allLocations = [];
$sql = "SELECT DISTINCT l.CountryName, l.ContinentName
        FROM Location l
        JOIN Company c ON l.LocationID = c.LocationID
        WHERE c.Type = 'Distributor'
        ORDER BY l.ContinentName, l.CountryName";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$allLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// AJAX endpoint for getting source companies based on distributor
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_source_companies') {
    header('Content-Type: application/json');
    
    $distributorId = $_GET['distributor_id'] ?? null;
    
    if (!$distributorId) {
        // If no distributor selected, get all source companies that work with any distributor
        $sql = "SELECT DISTINCT c.CompanyID, c.CompanyName, c.Type
                FROM Company c
                JOIN Shipping s ON c.CompanyID = s.SourceCompanyID
                ORDER BY c.CompanyName";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    } else {
        // Get source companies that this distributor ships from AND have dependency relationships
        $sql = "SELECT DISTINCT c.CompanyID, c.CompanyName, c.Type
                FROM Company c
                JOIN Shipping s ON c.CompanyID = s.SourceCompanyID
                WHERE s.DistributorID = :distributorId
                AND EXISTS (
                    SELECT 1 FROM DependsOn d 
                    WHERE d.UpstreamCompanyID = c.CompanyID 
                    OR d.DownstreamCompanyID = c.CompanyID
                )
                ORDER BY c.CompanyName";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':distributorId' => $distributorId]);
    }
    
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// AJAX endpoint for getting destination companies based on distributor and optional source
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_destination_companies') {
    header('Content-Type: application/json');
    
    $distributorId = $_GET['distributor_id'] ?? null;
    $sourceId = $_GET['source_id'] ?? null;
    
    $whereClauses = [];
    $params = [];
    
    if ($distributorId) {
        $whereClauses[] = "s.DistributorID = :distributorId";
        $params[':distributorId'] = $distributorId;
    }
    
    if ($sourceId) {
        $whereClauses[] = "s.SourceCompanyID = :sourceId";
        $params[':sourceId'] = $sourceId;
        
        // Also ensure there's a dependency relationship between source and destination
        $whereClauses[] = "EXISTS (
            SELECT 1 FROM DependsOn d 
            WHERE (d.UpstreamCompanyID = :sourceId2 AND d.DownstreamCompanyID = c.CompanyID)
            OR (d.DownstreamCompanyID = :sourceId3 AND d.UpstreamCompanyID = c.CompanyID)
        )";
        $params[':sourceId2'] = $sourceId;
        $params[':sourceId3'] = $sourceId;
    } else {
        // If no source specified, just ensure destinations have dependency relationships
        $whereClauses[] = "EXISTS (
            SELECT 1 FROM DependsOn d 
            WHERE d.UpstreamCompanyID = c.CompanyID 
            OR d.DownstreamCompanyID = c.CompanyID
        )";
    }
    
    $whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    
    $sql = "SELECT DISTINCT c.CompanyID, c.CompanyName, c.Type
            FROM Company c
            JOIN Shipping s ON c.CompanyID = s.DestinationCompanyID
            {$whereClause}
            ORDER BY c.CompanyName";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Initialize results variables
$distributorStats = [];
$shipmentStatus = [];
$productStats = [];
$disruptionData = [];
$onTimeData = [];
$volumeOverTime = [];
$locationData = [];

// Function to get distributor statistics
function getDistributorStats($pdo, $filters) {
    $whereClauses = ["1=1"];
    $params = [];
    
    if (!empty($filters['distributor_id'])) {
        $whereClauses[] = "s.DistributorID = :distributorId";
        $params[':distributorId'] = $filters['distributor_id'];
    }
    
    if (!empty($filters['location'])) {
        $whereClauses[] = "l.CountryName = :location";
        $params[':location'] = $filters['location'];
    }
    
    if (!empty($filters['source_company'])) {
        $whereClauses[] = "s.SourceCompanyID = :sourceCompany";
        $params[':sourceCompany'] = $filters['source_company'];
    }
    
    if (!empty($filters['destination_company'])) {
        $whereClauses[] = "s.DestinationCompanyID = :destCompany";
        $params[':destCompany'] = $filters['destination_company'];
    }
    
    if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
        $whereClauses[] = "s.PromisedDate BETWEEN :startDate AND :endDate";
        $params[':startDate'] = $filters['start_date'];
        $params[':endDate'] = $filters['end_date'];
    }
    
    $whereClause = implode(" AND ", $whereClauses);
    
    $sql = "SELECT 
                d.CompanyID as DistributorID,
                c.CompanyName as DistributorName,
                l.CountryName,
                l.ContinentName,
                COUNT(s.ShipmentID) as TotalShipments,
                SUM(s.Quantity) as TotalVolume,
                COUNT(CASE WHEN s.ActualDate IS NULL THEN 1 END) as InTransit,
                COUNT(CASE WHEN s.ActualDate IS NOT NULL THEN 1 END) as Completed,
                COUNT(CASE WHEN s.ActualDate <= s.PromisedDate THEN 1 END) as OnTime,
                COUNT(CASE WHEN s.ActualDate > s.PromisedDate THEN 1 END) as Delayed,
                ROUND(COUNT(CASE WHEN s.ActualDate <= s.PromisedDate THEN 1 END) * 100.0 / 
                      NULLIF(COUNT(CASE WHEN s.ActualDate IS NOT NULL THEN 1 END), 0), 2) as OnTimeRate
            FROM Distributor d
            JOIN Company c ON d.CompanyID = c.CompanyID
            JOIN Location l ON c.LocationID = l.LocationID
            JOIN Shipping s ON d.CompanyID = s.DistributorID
            WHERE {$whereClause}
            GROUP BY d.CompanyID, c.CompanyName, l.CountryName, l.ContinentName
            ORDER BY TotalShipments DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get shipment status distribution
function getShipmentStatus($pdo, $filters) {
    $whereClauses = ["1=1"];
    $params = [];
    
    if (!empty($filters['distributor_id'])) {
        $whereClauses[] = "s.DistributorID = :distributorId";
        $params[':distributorId'] = $filters['distributor_id'];
    }
    
    if (!empty($filters['location'])) {
        $whereClauses[] = "l.CountryName = :location";
        $params[':location'] = $filters['location'];
    }
    
    if (!empty($filters['source_company'])) {
        $whereClauses[] = "s.SourceCompanyID = :sourceCompany";
        $params[':sourceCompany'] = $filters['source_company'];
    }
    
    if (!empty($filters['destination_company'])) {
        $whereClauses[] = "s.DestinationCompanyID = :destCompany";
        $params[':destCompany'] = $filters['destination_company'];
    }
    
    if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
        $whereClauses[] = "s.PromisedDate BETWEEN :startDate AND :endDate";
        $params[':startDate'] = $filters['start_date'];
        $params[':endDate'] = $filters['end_date'];
    }
    
    $whereClause = implode(" AND ", $whereClauses);
    
    $sql = "SELECT 
                CASE 
                    WHEN s.ActualDate IS NULL THEN 'In Transit'
                    WHEN s.ActualDate <= s.PromisedDate THEN 'Delivered On Time'
                    ELSE 'Delivered Late'
                END as Status,
                COUNT(*) as Count
            FROM Shipping s
            JOIN Distributor d ON s.DistributorID = d.CompanyID
            JOIN Company c ON d.CompanyID = c.CompanyID
            JOIN Location l ON c.LocationID = l.LocationID
            WHERE {$whereClause}
            GROUP BY Status";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get product statistics
function getProductStats($pdo, $filters) {
    $whereClauses = ["1=1"];
    $params = [];
    
    if (!empty($filters['distributor_id'])) {
        $whereClauses[] = "s.DistributorID = :distributorId";
        $params[':distributorId'] = $filters['distributor_id'];
    }
    
    if (!empty($filters['location'])) {
        $whereClauses[] = "l.CountryName = :location";
        $params[':location'] = $filters['location'];
    }
    
    if (!empty($filters['source_company'])) {
        $whereClauses[] = "s.SourceCompanyID = :sourceCompany";
        $params[':sourceCompany'] = $filters['source_company'];
    }
    
    if (!empty($filters['destination_company'])) {
        $whereClauses[] = "s.DestinationCompanyID = :destCompany";
        $params[':destCompany'] = $filters['destination_company'];
    }
    
    if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
        $whereClauses[] = "s.PromisedDate BETWEEN :startDate AND :endDate";
        $params[':startDate'] = $filters['start_date'];
        $params[':endDate'] = $filters['end_date'];
    }
    
    $whereClause = implode(" AND ", $whereClauses);
    
    $sql = "SELECT 
                p.ProductName,
                p.Category,
                COUNT(s.ShipmentID) as ShipmentCount,
                SUM(s.Quantity) as TotalQuantity
            FROM Shipping s
            JOIN Product p ON s.ProductID = p.ProductID
            JOIN Distributor d ON s.DistributorID = d.CompanyID
            JOIN Company c ON d.CompanyID = c.CompanyID
            JOIN Location l ON c.LocationID = l.LocationID
            WHERE {$whereClause}
            GROUP BY p.ProductID, p.ProductName, p.Category
            ORDER BY TotalQuantity DESC
            LIMIT 15";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to calculate disruption exposure
function getDisruptionExposure($pdo, $filters) {
    $whereClauses = ["1=1"];
    $params = [];
    
    if (!empty($filters['distributor_id'])) {
        $whereClauses[] = "d.CompanyID = :distributorId";
        $params[':distributorId'] = $filters['distributor_id'];
    }
    
    if (!empty($filters['location'])) {
        $whereClauses[] = "l.CountryName = :location";
        $params[':location'] = $filters['location'];
    }
    
    if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
        $whereClauses[] = "de.EventDate BETWEEN :startDate AND :endDate";
        $params[':startDate'] = $filters['start_date'];
        $params[':endDate'] = $filters['end_date'];
    }
    
    $whereClause = implode(" AND ", $whereClauses);
    
    $sql = "SELECT 
                d.CompanyID as DistributorID,
                c.CompanyName as DistributorName,
                COUNT(DISTINCT de.EventID) as TotalDisruptions,
                COUNT(DISTINCT CASE WHEN ic.ImpactLevel = 'High' THEN de.EventID END) as HighImpactEvents,
                (COUNT(DISTINCT de.EventID) + 2 * COUNT(DISTINCT CASE WHEN ic.ImpactLevel = 'High' THEN de.EventID END)) as DisruptionScore
            FROM Distributor d
            JOIN Company c ON d.CompanyID = c.CompanyID
            JOIN Location l ON c.LocationID = l.LocationID
            LEFT JOIN ImpactsCompany ic ON c.CompanyID = ic.AffectedCompanyID
            LEFT JOIN DisruptionEvent de ON ic.EventID = de.EventID
            WHERE {$whereClause}
            GROUP BY d.CompanyID, c.CompanyName
            ORDER BY DisruptionScore DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get volume over time for plotting
function getVolumeOverTime($pdo, $filters) {
    $whereClauses = ["1=1"];
    $params = [];
    
    if (!empty($filters['distributor_id'])) {
        $whereClauses[] = "s.DistributorID = :distributorId";
        $params[':distributorId'] = $filters['distributor_id'];
    }
    
    if (!empty($filters['location'])) {
        $whereClauses[] = "l.CountryName = :location";
        $params[':location'] = $filters['location'];
    }
    
    if (!empty($filters['source_company'])) {
        $whereClauses[] = "s.SourceCompanyID = :sourceCompany";
        $params[':sourceCompany'] = $filters['source_company'];
    }
    
    if (!empty($filters['destination_company'])) {
        $whereClauses[] = "s.DestinationCompanyID = :destCompany";
        $params[':destCompany'] = $filters['destination_company'];
    }
    
    if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
        $whereClauses[] = "s.PromisedDate BETWEEN :startDate AND :endDate";
        $params[':startDate'] = $filters['start_date'];
        $params[':endDate'] = $filters['end_date'];
    }
    
    $whereClause = implode(" AND ", $whereClauses);
    
    $sql = "SELECT 
                DATE_FORMAT(s.PromisedDate, '%Y-%m') as Month,
                COUNT(s.ShipmentID) as ShipmentCount,
                SUM(s.Quantity) as TotalVolume
            FROM Shipping s
            JOIN Distributor d ON s.DistributorID = d.CompanyID
            JOIN Company c ON d.CompanyID = c.CompanyID
            JOIN Location l ON c.LocationID = l.LocationID
            WHERE {$whereClause}
            GROUP BY Month
            ORDER BY Month";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get location distribution
function getLocationDistribution($pdo, $filters) {
    $whereClauses = ["1=1"];
    $params = [];
    
    if (!empty($filters['distributor_id'])) {
        $whereClauses[] = "s.DistributorID = :distributorId";
        $params[':distributorId'] = $filters['distributor_id'];
    }
    
    if (!empty($filters['source_company'])) {
        $whereClauses[] = "s.SourceCompanyID = :sourceCompany";
        $params[':sourceCompany'] = $filters['source_company'];
    }
    
    if (!empty($filters['destination_company'])) {
        $whereClauses[] = "s.DestinationCompanyID = :destCompany";
        $params[':destCompany'] = $filters['destination_company'];
    }
    
    if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
        $whereClauses[] = "s.PromisedDate BETWEEN :startDate AND :endDate";
        $params[':startDate'] = $filters['start_date'];
        $params[':endDate'] = $filters['end_date'];
    }
    
    $whereClause = implode(" AND ", $whereClauses);
    
    $sql = "SELECT 
                l.ContinentName,
                COUNT(s.ShipmentID) as ShipmentCount
            FROM Shipping s
            JOIN Distributor d ON s.DistributorID = d.CompanyID
            JOIN Company c ON d.CompanyID = c.CompanyID
            JOIN Location l ON c.LocationID = l.LocationID
            WHERE {$whereClause}
            GROUP BY l.ContinentName
            ORDER BY ShipmentCount DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle form submission
$filters = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($_GET)) {
    $filters = [
        'distributor_id' => $_POST['distributor_id'] ?? $_GET['distributor_id'] ?? '',
        'location' => $_POST['location'] ?? $_GET['location'] ?? '',
        'source_company' => $_POST['source_company'] ?? $_GET['source_company'] ?? '',
        'destination_company' => $_POST['destination_company'] ?? $_GET['destination_company'] ?? '',
        'start_date' => $_POST['start_date'] ?? $_GET['start_date'] ?? '',
        'end_date' => $_POST['end_date'] ?? $_GET['end_date'] ?? ''
    ];
    
    // Get all data
    $distributorStats = getDistributorStats($pdo, $filters);
    $shipmentStatus = getShipmentStatus($pdo, $filters);
    $productStats = getProductStats($pdo, $filters);
    $disruptionData = getDisruptionExposure($pdo, $filters);
    $volumeOverTime = getVolumeOverTime($pdo, $filters);
    $locationData = getLocationDistribution($pdo, $filters);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Distributor Analytics - SCM</title>
    <link rel="stylesheet" href="assets/styles.css">
    <script src="https://cdn.plot.ly/plotly-2.26.0.min.js"></script>
    <style>
        .filter-box {
            background: rgba(0, 0, 0, 0.6);
            padding: 30px;
            border-radius: 12px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            margin-bottom: 30px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            margin-bottom: 8px;
            color: var(--purdue-gold);
            font-weight: bold;
        }
        .form-group select,
        .form-group input {
            padding: 10px;
            border-radius: 5px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            background: rgba(0, 0, 0, 0.4);
            color: var(--text-light);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: rgba(207, 185, 145, 0.1);
            padding: 20px;
            border-radius: 8px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            text-align: center;
        }
        .stat-card h3 {
            color: var(--purdue-gold);
            margin: 0 0 10px 0;
            font-size: 0.9rem;
            text-transform: uppercase;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--text-light);
        }
        .stat-label {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 5px;
        }
        .plot-container {
            background: rgba(0, 0, 0, 0.6);
            padding: 20px;
            border-radius: 12px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            margin-bottom: 30px;
        }
        .plot-container h3 {
            color: var(--purdue-gold);
            margin-top: 0;
        }
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
        .nav-bar a:hover {
            background: var(--purdue-gold);
            color: var(--purdue-black);
        }
        .high-risk {
            color: #ff4444;
            font-weight: bold;
        }
        .medium-risk {
            color: #ffaa00;
            font-weight: bold;
        }
        .low-risk {
            color: #44ff44;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Supply Chain Manager Portal</h1>
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
        <a href="scm_disruptions.php">Disruptions</a>
        <a href="scm_transactions.php">Transactions</a>
        <a href="scm_transaction_costs.php">Cost Analysis</a>
        <a href="scm_distributors.php" style="background: var(--purdue-gold); color: var(--purdue-black);">Distributors</a>
    </div>

    <div class="container">
        <h2>Distributor Performance Analytics</h2>
        
        <!-- Filter Form -->
        <div class="filter-box">
            <h3>Filter Analytics</h3>
            <form method="POST" action="scm_distributors.php" id="filterForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="distributor_id">Specific Distributor (Optional):</label>
                        <select name="distributor_id" id="distributor_id">
                            <option value="">-- All Distributors --</option>
                            <?php foreach ($allDistributors as $dist): ?>
                                <option value="<?= $dist['CompanyID'] ?>" 
                                        <?= ($filters['distributor_id'] ?? '') == $dist['CompanyID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dist['CompanyName']) ?> (<?= htmlspecialchars($dist['CountryName']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="location">Location Filter (Optional):</label>
                        <select name="location" id="location">
                            <option value="">-- All Locations --</option>
                            <?php 
                            $currentContinent = '';
                            foreach ($allLocations as $loc): 
                                if ($currentContinent !== $loc['ContinentName']) {
                                    if ($currentContinent !== '') echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars($loc['ContinentName']) . '">';
                                    $currentContinent = $loc['ContinentName'];
                                }
                            ?>
                                <option value="<?= htmlspecialchars($loc['CountryName']) ?>"
                                        <?= ($filters['location'] ?? '') == $loc['CountryName'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($loc['CountryName']) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($currentContinent !== '') echo '</optgroup>'; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="source_company">Ships From (Optional):</label>
                        <select name="source_company" id="source_company" disabled>
                            <option value="">-- Select Distributor First --</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="destination_company">Ships To (Optional):</label>
                        <select name="destination_company" id="destination_company" disabled>
                            <option value="">-- Select Distributor First --</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="start_date">Start Date:</label>
                        <input type="date" 
                               name="start_date" 
                               id="start_date" 
                               value="<?= htmlspecialchars($filters['start_date'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">End Date:</label>
                        <input type="date" 
                               name="end_date" 
                               id="end_date" 
                               value="<?= htmlspecialchars($filters['end_date'] ?? '') ?>">
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit">Apply Filters</button>
                    <button type="button" onclick="window.location.href='scm_distributors.php'" 
                            style="background: rgba(207, 185, 145, 0.3);">Clear Filters</button>
                </div>
            </form>
        </div>

        <?php if (!empty($distributorStats)): ?>
            <!-- Summary Statistics -->
            <div class="stats-grid">
                <?php
                $totalShipments = array_sum(array_column($distributorStats, 'TotalShipments'));
                $totalVolume = array_sum(array_column($distributorStats, 'TotalVolume'));
                $totalInTransit = array_sum(array_column($distributorStats, 'InTransit'));
                $totalOnTime = array_sum(array_column($distributorStats, 'OnTime'));
                $totalCompleted = array_sum(array_column($distributorStats, 'Completed'));
                $overallOnTimeRate = $totalCompleted > 0 ? round(($totalOnTime / $totalCompleted) * 100, 2) : 0;
                ?>
                
                <div class="stat-card">
                    <h3>Total Shipments</h3>
                    <div class="stat-value"><?= number_format($totalShipments) ?></div>
                    <div class="stat-label">Across all filtered distributors</div>
                </div>
                
                <div class="stat-card">
                    <h3>Total Volume</h3>
                    <div class="stat-value"><?= number_format($totalVolume) ?></div>
                    <div class="stat-label">Units shipped</div>
                </div>
                
                <div class="stat-card">
                    <h3>On-Time Delivery Rate</h3>
                    <div class="stat-value" style="color: <?= $overallOnTimeRate >= 90 ? '#44ff44' : ($overallOnTimeRate >= 75 ? '#ffaa00' : '#ff4444') ?>">
                        <?= $overallOnTimeRate ?>%
                    </div>
                    <div class="stat-label"><?= number_format($totalOnTime) ?> of <?= number_format($totalCompleted) ?> delivered</div>
                </div>
                
                <div class="stat-card">
                    <h3>Currently In Transit</h3>
                    <div class="stat-value" style="color: #00bfff;"><?= number_format($totalInTransit) ?></div>
                    <div class="stat-label">Shipments en route</div>
                </div>
            </div>

            <!-- Plot 1: Shipment Volume Over Time -->
            <?php if (!empty($volumeOverTime)): ?>
            <div class="plot-container">
                <h3>📈 Shipment Volume Trends Over Time</h3>
                <div id="volumePlot"></div>
            </div>
            <?php endif; ?>

            <!-- Plot 2: Shipment Status Distribution -->
            <?php if (!empty($shipmentStatus)): ?>
            <div class="plot-container">
                <h3>📊 Shipment Status Distribution</h3>
                <div id="statusPlot"></div>
            </div>
            <?php endif; ?>

            <!-- Distributor Performance Table -->
            <div class="content-section">
                <h3>Distributor Performance Details</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Distributor</th>
                            <th>Location</th>
                            <th>Total Shipments</th>
                            <th>Total Volume</th>
                            <th>In Transit</th>
                            <th>Completed</th>
                            <th>On-Time Rate</th>
                            <th>Delayed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($distributorStats as $stat): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($stat['DistributorName']) ?></strong></td>
                                <td><?= htmlspecialchars($stat['CountryName']) ?>, <?= htmlspecialchars($stat['ContinentName']) ?></td>
                                <td><?= number_format($stat['TotalShipments']) ?></td>
                                <td><?= number_format($stat['TotalVolume']) ?></td>
                                <td style="color: #00bfff;"><?= number_format($stat['InTransit']) ?></td>
                                <td><?= number_format($stat['Completed']) ?></td>
                                <td>
                                    <span class="<?= $stat['OnTimeRate'] >= 90 ? 'low-risk' : ($stat['OnTimeRate'] >= 75 ? 'medium-risk' : 'high-risk') ?>">
                                        <?= $stat['OnTimeRate'] ?? 'N/A' ?>%
                                    </span>
                                </td>
                                <td style="color: #ff4444;"><?= number_format($stat['Delayed']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Plot 3: Product Category Distribution -->
            <?php if (!empty($productStats)): ?>
            <div class="plot-container">
                <h3>📦 Top Products Handled by Volume</h3>
                <div id="productPlot"></div>
            </div>
            <?php endif; ?>

            <!-- Plot 4: Geographic Distribution -->
            <?php if (!empty($locationData)): ?>
            <div class="plot-container">
                <h3>🌍 Geographic Distribution of Shipments</h3>
                <div id="locationPlot"></div>
            </div>
            <?php endif; ?>

            <!-- Product Breakdown Table -->
            <?php if (!empty($productStats)): ?>
            <div class="content-section">
                <h3>Products Handled</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Shipment Count</th>
                            <th>Total Quantity</th>
                            <th>Avg Quantity/Shipment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productStats as $product): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($product['ProductName']) ?></strong></td>
                                <td><?= htmlspecialchars($product['Category']) ?></td>
                                <td><?= number_format($product['ShipmentCount']) ?></td>
                                <td><?= number_format($product['TotalQuantity']) ?></td>
                                <td><?= number_format($product['TotalQuantity'] / $product['ShipmentCount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Disruption Exposure Analysis -->
            <?php if (!empty($disruptionData)): ?>
            <div class="content-section">
                <h3>Disruption Exposure Analysis</h3>
                <p style="color: rgba(255,255,255,0.7); margin-bottom: 20px;">
                    <em>Disruption Score = Total Disruptions + (2 × High Impact Events)</em>
                </p>
                <table>
                    <thead>
                        <tr>
                            <th>Distributor</th>
                            <th>Total Disruptions</th>
                            <th>High Impact Events</th>
                            <th>Disruption Score</th>
                            <th>Risk Level</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($disruptionData as $disruption): ?>
                            <?php
                            $score = $disruption['DisruptionScore'];
                            $riskClass = $score >= 10 ? 'high-risk' : ($score >= 5 ? 'medium-risk' : 'low-risk');
                            $riskLabel = $score >= 10 ? 'High Risk' : ($score >= 5 ? 'Medium Risk' : 'Low Risk');
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($disruption['DistributorName']) ?></strong></td>
                                <td><?= number_format($disruption['TotalDisruptions']) ?></td>
                                <td style="color: #ff4444;"><?= number_format($disruption['HighImpactEvents']) ?></td>
                                <td><strong><?= number_format($disruption['DisruptionScore']) ?></strong></td>
                                <td><span class="<?= $riskClass ?>"><?= $riskLabel ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($_GET)): ?>
            <div class="content-section">
                <p style="color: var(--error-red); font-size: 1.2rem;">
                    ❌ No data found matching the selected filters. Please adjust your criteria and try again.
                </p>
            </div>
        <?php else: ?>
            <div class="content-section">
                <p style="color: rgba(255,255,255,0.7); font-size: 1.1rem; text-align: center;">
                    👆 Please select filters above to view distributor analytics
                </p>
            </div>
        <?php endif; ?>

    </div>

    <script>
        // Dynamic dropdown functionality for cascading filters
        const distributorSelect = document.getElementById('distributor_id');
        const sourceSelect = document.getElementById('source_company');
        const destinationSelect = document.getElementById('destination_company');
        
        // Store preserved selections from form submission
        const preservedSource = '<?= $filters['source_company'] ?? '' ?>';
        const preservedDestination = '<?= $filters['destination_company'] ?? '' ?>';
        
        // Function to populate source companies
        function updateSourceCompanies() {
            const distributorId = distributorSelect.value;
            
            sourceSelect.disabled = true;
            sourceSelect.innerHTML = '<option value="">Loading...</option>';
            destinationSelect.disabled = true;
            destinationSelect.innerHTML = '<option value="">-- Select Source First --</option>';
            
            const url = distributorId 
                ? `scm_distributors.php?ajax=get_source_companies&distributor_id=${distributorId}`
                : `scm_distributors.php?ajax=get_source_companies`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    sourceSelect.innerHTML = '<option value="">-- All Sources --</option>';
                    
                    if (data.length === 0) {
                        sourceSelect.innerHTML = '<option value="">No source companies found</option>';
                        sourceSelect.disabled = true;
                        return;
                    }
                    
                    data.forEach(company => {
                        const option = document.createElement('option');
                        option.value = company.CompanyID;
                        option.textContent = `${company.CompanyName} (${company.Type})`;
                        
                        if (preservedSource && company.CompanyID == preservedSource) {
                            option.selected = true;
                        }
                        
                        sourceSelect.appendChild(option);
                    });
                    
                    sourceSelect.disabled = false;
                    
                    // Trigger destination update if source is selected
                    if (sourceSelect.value) {
                        updateDestinationCompanies();
                    } else {
                        updateDestinationCompanies(); // Still update to show all destinations
                    }
                })
                .catch(error => {
                    console.error('Error fetching source companies:', error);
                    sourceSelect.innerHTML = '<option value="">Error loading companies</option>';
                });
        }
        
        // Function to populate destination companies
        function updateDestinationCompanies() {
            const distributorId = distributorSelect.value;
            const sourceId = sourceSelect.value;
            
            destinationSelect.disabled = true;
            destinationSelect.innerHTML = '<option value="">Loading...</option>';
            
            let url = 'scm_distributors.php?ajax=get_destination_companies';
            if (distributorId) url += `&distributor_id=${distributorId}`;
            if (sourceId) url += `&source_id=${sourceId}`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    destinationSelect.innerHTML = '<option value="">-- All Destinations --</option>';
                    
                    if (data.length === 0) {
                        destinationSelect.innerHTML = '<option value="">No destination companies found</option>';
                        destinationSelect.disabled = true;
                        return;
                    }
                    
                    data.forEach(company => {
                        const option = document.createElement('option');
                        option.value = company.CompanyID;
                        option.textContent = `${company.CompanyName} (${company.Type})`;
                        
                        if (preservedDestination && company.CompanyID == preservedDestination) {
                            option.selected = true;
                        }
                        
                        destinationSelect.appendChild(option);
                    });
                    
                    destinationSelect.disabled = false;
                })
                .catch(error => {
                    console.error('Error fetching destination companies:', error);
                    destinationSelect.innerHTML = '<option value="">Error loading companies</option>';
                });
        }
        
        // Event listeners
        distributorSelect.addEventListener('change', function() {
            updateSourceCompanies();
        });
        
        sourceSelect.addEventListener('change', function() {
            updateDestinationCompanies();
        });
        
        // Initialize on page load
        window.addEventListener('DOMContentLoaded', function() {
            // Set default date range (last 12 months) if empty
            const startDate = document.getElementById('start_date');
            const endDate = document.getElementById('end_date');
            
            if (!startDate.value && !endDate.value) {
                const today = new Date();
                const twelveMonthsAgo = new Date();
                twelveMonthsAgo.setMonth(today.getMonth() - 12);
                
                startDate.value = twelveMonthsAgo.toISOString().split('T')[0];
                endDate.value = today.toISOString().split('T')[0];
            }
            
            // Trigger cascade if distributor is already selected
            if (distributorSelect.value || preservedSource || preservedDestination) {
                updateSourceCompanies();
            } else {
                // Still load all available companies even if no distributor selected
                updateSourceCompanies();
            }
        });

        <?php if (!empty($volumeOverTime)): ?>
        // Plot 1: Volume Over Time (Line Chart)
        var volumeData = {
            x: [<?php echo implode(',', array_map(function($d) { return "'" . $d['Month'] . "'"; }, $volumeOverTime)); ?>],
            y: [<?php echo implode(',', array_column($volumeOverTime, 'TotalVolume')); ?>],
            type: 'scatter',
            mode: 'lines+markers',
            name: 'Total Volume',
            line: {color: '#CFB991', width: 3},
            marker: {size: 8, color: '#CFB991'}
        };
        
        var shipmentCountData = {
            x: [<?php echo implode(',', array_map(function($d) { return "'" . $d['Month'] . "'"; }, $volumeOverTime)); ?>],
            y: [<?php echo implode(',', array_column($volumeOverTime, 'ShipmentCount')); ?>],
            type: 'scatter',
            mode: 'lines+markers',
            name: 'Shipment Count',
            line: {color: '#00bfff', width: 3},
            marker: {size: 8, color: '#00bfff'},
            yaxis: 'y2'
        };
        
        var volumeLayout = {
            title: {
                text: 'Monthly Shipment Trends',
                font: {color: '#CFB991', size: 16}
            },
            xaxis: {
                title: 'Month',
                color: '#ffffff',
                gridcolor: 'rgba(255,255,255,0.1)'
            },
            yaxis: {
                title: 'Total Volume (Units)',
                color: '#CFB991',
                gridcolor: 'rgba(255,255,255,0.1)'
            },
            yaxis2: {
                title: 'Shipment Count',
                color: '#00bfff',
                overlaying: 'y',
                side: 'right'
            },
            plot_bgcolor: 'rgba(0,0,0,0.4)',
            paper_bgcolor: 'rgba(0,0,0,0)',
            font: {color: '#ffffff'},
            legend: {
                font: {color: '#ffffff'},
                bgcolor: 'rgba(0,0,0,0.5)'
            }
        };
        
        Plotly.newPlot('volumePlot', [volumeData, shipmentCountData], volumeLayout, {responsive: true});
        <?php endif; ?>

        <?php if (!empty($shipmentStatus)): ?>
        // Plot 2: Shipment Status Distribution (Pie Chart)
        var statusData = [{
            values: [<?php echo implode(',', array_column($shipmentStatus, 'Count')); ?>],
            labels: [<?php echo implode(',', array_map(function($s) { return "'" . $s['Status'] . "'"; }, $shipmentStatus)); ?>],
            type: 'pie',
            marker: {
                colors: ['#00bfff', '#44ff44', '#ff4444']
            },
            textinfo: 'label+percent+value',
            textfont: {color: '#ffffff', size: 14},
            hoverinfo: 'label+value+percent'
        }];
        
        var statusLayout = {
            title: {
                text: 'Shipment Status Breakdown',
                font: {color: '#CFB991', size: 16}
            },
            plot_bgcolor: 'rgba(0,0,0,0.4)',
            paper_bgcolor: 'rgba(0,0,0,0)',
            font: {color: '#ffffff'},
            legend: {
                font: {color: '#ffffff'},
                bgcolor: 'rgba(0,0,0,0.5)'
            }
        };
        
        Plotly.newPlot('statusPlot', statusData, statusLayout, {responsive: true});
        <?php endif; ?>

        <?php if (!empty($productStats)): ?>
        // Plot 3: Product Distribution (Horizontal Bar Chart)
        var productData = [{
            y: [<?php echo implode(',', array_map(function($p) { return "'" . addslashes($p['ProductName']) . "'"; }, $productStats)); ?>],
            x: [<?php echo implode(',', array_column($productStats, 'TotalQuantity')); ?>],
            type: 'bar',
            orientation: 'h',
            marker: {
                color: '#CFB991',
                line: {color: '#8B7355', width: 1}
            },
            text: [<?php echo implode(',', array_column($productStats, 'TotalQuantity')); ?>],
            textposition: 'auto',
            hovertemplate: '<b>%{y}</b><br>Quantity: %{x:,}<extra></extra>'
        }];
        
        var productLayout = {
            title: {
                text: 'Product Distribution by Volume',
                font: {color: '#CFB991', size: 16}
            },
            xaxis: {
                title: 'Total Quantity Shipped',
                color: '#ffffff',
                gridcolor: 'rgba(255,255,255,0.1)'
            },
            yaxis: {
                color: '#ffffff',
                automargin: true
            },
            plot_bgcolor: 'rgba(0,0,0,0.4)',
            paper_bgcolor: 'rgba(0,0,0,0)',
            font: {color: '#ffffff'},
            height: Math.max(400, <?php echo count($productStats) * 30; ?>)
        };
        
        Plotly.newPlot('productPlot', productData, productLayout, {responsive: true});
        <?php endif; ?>

        <?php if (!empty($locationData)): ?>
        // Plot 4: Geographic Distribution (Bar Chart)
        var locationData = [{
            x: [<?php echo implode(',', array_map(function($l) { return "'" . $l['ContinentName'] . "'"; }, $locationData)); ?>],
            y: [<?php echo implode(',', array_column($locationData, 'ShipmentCount')); ?>],
            type: 'bar',
            marker: {
                color: ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#F7DC6F'],
                line: {color: '#ffffff', width: 1}
            },
            text: [<?php echo implode(',', array_column($locationData, 'ShipmentCount')); ?>],
            textposition: 'auto',
            textfont: {color: '#ffffff', size: 14}
        }];
        
        var locationLayout = {
            title: {
                text: 'Shipments by Continent',
                font: {color: '#CFB991', size: 16}
            },
            xaxis: {
                title: 'Continent',
                color: '#ffffff'
            },
            yaxis: {
                title: 'Number of Shipments',
                color: '#ffffff',
                gridcolor: 'rgba(255,255,255,0.1)'
            },
            plot_bgcolor: 'rgba(0,0,0,0.4)',
            paper_bgcolor: 'rgba(0,0,0,0)',
            font: {color: '#ffffff'}
        };
        
        Plotly.newPlot('locationPlot', locationData, locationLayout, {responsive: true});
        <?php endif; ?>
    </script>
</body>
</html>
