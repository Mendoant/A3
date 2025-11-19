<?php
// scm_transactions.php - Transaction Management
require_once 'config.php';
requireLogin();

// Redirect Senior Managers
if (hasRole('SeniorManager')) {
    header('Location: dashboard_erp.php');
    exit;
}

$pdo = getPDO();

// Get filter parameters (PHP 5.4 compatible)
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // First day of current month
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Today
$companyFilter = isset($_GET['company']) ? $_GET['company'] : '';
$transactionType = isset($_GET['type']) ? $_GET['type'] : 'all';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build the query based on filters
$whereConditions = array();
$params = array();

// Date range filter
$whereConditions[] = "s.PromisedDate BETWEEN :startDate AND :endDate";
$params[':startDate'] = $startDate;
$params[':endDate'] = $endDate;

// Company filter
if (!empty($companyFilter)) {
    $whereConditions[] = "(source.CompanyName LIKE :company OR dest.CompanyName LIKE :company)";
    $params[':company'] = '%' . $companyFilter . '%';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get shipping transactions
$sql = "SELECT 
            s.ShipmentID,
            s.PromisedDate,
            s.ActualDate,
            s.Quantity,
            p.ProductName,
            p.Category,
            source.CompanyName as SourceCompany,
            dest.CompanyName as DestCompany,
            dist.CompanyName as DistributorName,
            CASE 
                WHEN s.ActualDate IS NULL THEN 'In Transit'
                WHEN s.ActualDate <= s.PromisedDate THEN 'On Time'
                ELSE 'Delayed'
            END as Status,
            DATEDIFF(s.ActualDate, s.PromisedDate) as DelayDays
        FROM Shipping s
        JOIN Product p ON s.ProductID = p.ProductID
        JOIN Company source ON s.SourceCompanyID = source.CompanyID
        JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
        JOIN Company dist ON s.DistributorID = dist.CompanyID
        $whereClause
        ORDER BY s.PromisedDate DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shipments = $stmt->fetchAll();

// Apply status filter after fetching (easier than complex SQL)
if ($statusFilter !== 'all') {
    $shipments = array_filter($shipments, function($s) use ($statusFilter) {
        return strtolower($s['Status']) === strtolower($statusFilter);
    });
}

// Calculate summary statistics (PHP 5.4 compatible - no arrow functions)
$totalShipments = count($shipments);
$inTransit = count(array_filter($shipments, function($s) { 
    return $s['Status'] === 'In Transit'; 
}));
$onTime = count(array_filter($shipments, function($s) { 
    return $s['Status'] === 'On Time'; 
}));
$delayed = count(array_filter($shipments, function($s) { 
    return $s['Status'] === 'Delayed'; 
}));
$onTimeRate = $totalShipments > 0 ? round(($onTime / ($onTime + $delayed)) * 100, 1) : 0;

// Get all companies for dropdown
$companiesSql = "SELECT DISTINCT CompanyName FROM Company ORDER BY CompanyName";
$companiesStmt = $pdo->query($companiesSql);
$companies = $companiesStmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Management - SCM</title>
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
        .status-badge {
            padding: 6px 12px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.9rem;
            display: inline-block;
        }
        .status-on-time {
            background: #4caf50;
            color: white;
        }
        .status-delayed {
            background: #f44336;
            color: white;
        }
        .status-in-transit {
            background: #ff9800;
            color: white;
        }
        .transactions-table {
            margin-top: 30px;
            overflow-x: auto;
        }
        .transactions-table table {
            min-width: 1400px;
            width: 100%;
        }
        .highlight-row:hover {
            background: rgba(207, 185, 145, 0.15) !important;
        }
        /* Make table scrollable on smaller screens */
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
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
        <a href="scm_disruptions.php">Disruptions</a>
        <a href="scm_transactions.php" class="active">Transactions</a>
        <a href="scm_transaction_costs.php">Cost Analysis</a>
        <a href="scm_distributors.php">Distributors</a>
    </div>

    <div class="container">
        <h2>Transaction Management</h2>

        <!-- Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3><?= $totalShipments ?></h3>
                <p>Total Shipments</p>
            </div>
            <div class="stat-card">
                <h3 style="color: #ff9800;"><?= $inTransit ?></h3>
                <p>In Transit</p>
            </div>
            <div class="stat-card">
                <h3 style="color: #4caf50;"><?= $onTime ?></h3>
                <p>On Time Deliveries</p>
            </div>
            <div class="stat-card">
                <h3 style="color: #f44336;"><?= $delayed ?></h3>
                <p>Delayed</p>
            </div>
            <div class="stat-card">
                <h3><?= $onTimeRate ?>%</h3>
                <p>On-Time Rate</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <h3>Filter Transactions</h3>
            <form method="GET" action="scm_transactions.php">
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
                        <label for="company">Company:</label>
                        <input type="text" id="company" name="company" 
                               placeholder="Search company..." 
                               value="<?= htmlspecialchars($companyFilter) ?>">
                    </div>
                    
                    <div>
                        <label for="status">Status:</label>
                        <select id="status" name="status">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="in transit" <?= $statusFilter === 'in transit' ? 'selected' : '' ?>>In Transit</option>
                            <option value="on time" <?= $statusFilter === 'on time' ? 'selected' : '' ?>>On Time</option>
                            <option value="delayed" <?= $statusFilter === 'delayed' ? 'selected' : '' ?>>Delayed</option>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit">Apply Filters</button>
                    <a href="scm_transactions.php" class="btn-secondary" style="display: inline-block; padding: 14px 32px; text-decoration: none;">Clear Filters</a>
                </div>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="content-section transactions-table">
            <h3>Shipment Details (<?= count($shipments) ?> records)</h3>
            
            <?php if (count($shipments) > 0): ?>
                <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Shipment ID</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Distributor</th>
                            <th>Quantity</th>
                            <th>Promised Date</th>
                            <th>Actual Date</th>
                            <th>Delay (Days)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shipments as $shipment): ?>
                            <tr class="highlight-row">
                                <td><?= htmlspecialchars($shipment['ShipmentID']) ?></td>
                                <td><strong><?= htmlspecialchars($shipment['ProductName']) ?></strong></td>
                                <td><?= htmlspecialchars($shipment['Category']) ?></td>
                                <td><?= htmlspecialchars($shipment['SourceCompany']) ?></td>
                                <td><?= htmlspecialchars($shipment['DestCompany']) ?></td>
                                <td><?= htmlspecialchars($shipment['DistributorName']) ?></td>
                                <td><?= number_format($shipment['Quantity']) ?></td>
                                <td><?= htmlspecialchars($shipment['PromisedDate']) ?></td>
                                <td>
                                    <?php if ($shipment['ActualDate']): ?>
                                        <?= htmlspecialchars($shipment['ActualDate']) ?>
                                    <?php else: ?>
                                        <em style="color: #ff9800;">Pending</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($shipment['DelayDays'] !== null): ?>
                                        <?php if ($shipment['DelayDays'] > 0): ?>
                                            <span style="color: #f44336; font-weight: bold;">+<?= $shipment['DelayDays'] ?></span>
                                        <?php else: ?>
                                            <span style="color: #4caf50;">0</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = 'status-' . strtolower(str_replace(' ', '-', $shipment['Status']));
                                    ?>
                                    <span class="status-badge <?= $statusClass ?>">
                                        <?= htmlspecialchars($shipment['Status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-light); padding: 40px; font-size: 1.2rem;">
                    No transactions found for the selected filters. Try adjusting your search criteria.
                </p>
            <?php endif; ?>
        </div>

        <!-- Export Options -->
        <div style="margin-top: 30px; text-align: center;">
            <p style="color: var(--text-light);">
                <em>Note: Export functionality coming soon</em>
            </p>
        </div>
    </div>
</body>
</html>
