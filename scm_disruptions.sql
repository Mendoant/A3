<?php
// scm_disruptions.php - Disruption Events Management
require_once 'config.php';
requireLogin();

// Redirect Senior Managers
if (hasRole('SeniorManager')) {
    header('Location: dashboard_erp.php');
    exit;
}

$pdo = getPDO();

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'active';
$impactFilter = $_GET['impact'] ?? 'all';
$categoryFilter = $_GET['category'] ?? 'all';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

// Build the WHERE clause based on filters
$whereConditions = [];
$params = [];

// Status filter
if ($statusFilter === 'active') {
    $whereConditions[] = "(de.EventRecoveryDate IS NULL OR de.EventRecoveryDate >= CURDATE())";
} elseif ($statusFilter === 'resolved') {
    $whereConditions[] = "de.EventRecoveryDate < CURDATE()";
}

// Impact filter
if ($impactFilter !== 'all') {
    $whereConditions[] = "ic.ImpactLevel = :impact";
    $params[':impact'] = ucfirst($impactFilter);
}

// Category filter
if ($categoryFilter !== 'all') {
    $whereConditions[] = "dc.CategoryID = :category";
    $params[':category'] = $categoryFilter;
}

// Date range filter
if (!empty($startDate)) {
    $whereConditions[] = "de.EventDate >= :startDate";
    $params[':startDate'] = $startDate;
}
if (!empty($endDate)) {
    $whereConditions[] = "de.EventDate <= :endDate";
    $params[':endDate'] = $endDate;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get disruption events with details
$sql = "SELECT 
    de.EventID,
    de.EventDate,
    de.EventRecoveryDate,
    dc.CategoryID,
    dc.CategoryName,
    dc.Description as CategoryDescription,
    GROUP_CONCAT(DISTINCT c.CompanyName ORDER BY c.CompanyName SEPARATOR ', ') as AffectedCompanies,
    GROUP_CONCAT(DISTINCT CONCAT(c.CompanyName, ' (', ic.ImpactLevel, ')') ORDER BY c.CompanyName SEPARATOR '; ') as CompaniesWithImpact,
    COUNT(DISTINCT ic.AffectedCompanyID) as CompanyCount,
    MAX(ic.ImpactLevel) as MaxImpact,
    DATEDIFF(COALESCE(de.EventRecoveryDate, CURDATE()), de.EventDate) as DurationDays,
    CASE 
        WHEN de.EventRecoveryDate IS NULL THEN 'Active'
        WHEN de.EventRecoveryDate >= CURDATE() THEN 'Active'
        ELSE 'Resolved'
    END as Status
FROM DisruptionEvent de
JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
JOIN ImpactsCompany ic ON de.EventID = ic.EventID
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
$whereClause
GROUP BY de.EventID, de.EventDate, de.EventRecoveryDate, dc.CategoryID, dc.CategoryName, dc.Description
ORDER BY de.EventDate DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$disruptions = $stmt->fetchAll();

// Get categories for filter dropdown
$categorySql = "SELECT CategoryID, CategoryName FROM DisruptionCategory ORDER BY CategoryName";
$categoryStmt = $pdo->query($categorySql);
$categories = $categoryStmt->fetchAll();

// Calculate summary statistics
$totalDisruptions = count($disruptions);
$activeDisruptions = count(array_filter($disruptions, fn($d) => $d['Status'] === 'Active'));
$resolvedDisruptions = count(array_filter($disruptions, fn($d) => $d['Status'] === 'Resolved'));
$highImpactCount = count(array_filter($disruptions, fn($d) => $d['MaxImpact'] === 'High'));
$avgDuration = $totalDisruptions > 0 ? round(array_sum(array_column($disruptions, 'DurationDays')) / $totalDisruptions, 1) : 0;
$totalCompaniesAffected = array_sum(array_column($disruptions, 'CompanyCount'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disruption Events - SCM</title>
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
        .stat-card.active {
            border-color: #dc3545;
        }
        .stat-card.active h3 {
            color: #dc3545;
        }
        .stat-card.resolved {
            border-color: #4caf50;
        }
        .stat-card.resolved h3 {
            color: #4caf50;
        }
        .stat-card.high-impact {
            border-color: #ff9800;
        }
        .stat-card.high-impact h3 {
            color: #ff9800;
        }
        .disruption-card {
            background: rgba(0, 0, 0, 0.6);
            padding: 24px;
            border-radius: 8px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        .disruption-card:hover {
            border-color: var(--purdue-gold);
            box-shadow: 0 4px 20px rgba(207, 185, 145, 0.3);
        }
        .disruption-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .disruption-header h3 {
            margin: 0;
            color: var(--purdue-gold);
            font-size: 1.4rem;
        }
        .status-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9rem;
            display: inline-block;
        }
        .status-active {
            background: #dc3545;
            color: white;
        }
        .status-resolved {
            background: #4caf50;
            color: white;
        }
        .impact-badge {
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.85rem;
            display: inline-block;
            margin-left: 8px;
        }
        .impact-high {
            background: #ff4444;
            color: white;
        }
        .impact-medium {
            background: #ff9800;
            color: white;
        }
        .impact-low {
            background: #4caf50;
            color: white;
        }
        .disruption-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin: 16px 0;
            padding: 16px;
            background: rgba(207, 185, 145, 0.05);
            border-radius: 6px;
        }
        .detail-item {
            color: var(--text-light);
        }
        .detail-item strong {
            color: var(--purdue-gold);
            display: block;
            margin-bottom: 4px;
        }
        .affected-companies {
            margin-top: 16px;
            padding: 16px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 6px;
            border-left: 4px solid var(--purdue-gold);
        }
        .affected-companies h4 {
            margin: 0 0 12px 0;
            color: var(--purdue-gold);
            font-size: 1.1rem;
        }
        .affected-companies p {
            margin: 0;
            color: var(--text-light);
            line-height: 1.6;
        }
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            font-size: 1.2rem;
        }
        .no-results .icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
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
        <a href="scm_distributors.php">Distributors</a>
    </div>

    <div class="container">
        <h2>Disruption Events Management</h2>

        <!-- Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3><?= $totalDisruptions ?></h3>
                <p>Total Disruptions</p>
            </div>
            <div class="stat-card active">
                <h3><?= $activeDisruptions ?></h3>
                <p>Active Now</p>
            </div>
            <div class="stat-card resolved">
                <h3><?= $resolvedDisruptions ?></h3>
                <p>Resolved</p>
            </div>
            <div class="stat-card high-impact">
                <h3><?= $highImpactCount ?></h3>
                <p>High Impact</p>
            </div>
            <div class="stat-card">
                <h3><?= $avgDuration ?></h3>
                <p>Avg Duration (days)</p>
            </div>
            <div class="stat-card">
                <h3><?= $totalCompaniesAffected ?></h3>
                <p>Companies Affected</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <h3>Filter Disruptions</h3>
            <form method="GET" action="scm_disruptions.php">
                <div class="filter-grid">
                    <div>
                        <label for="status">Status:</label>
                        <select id="status" name="status">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="resolved" <?= $statusFilter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                        </select>
                    </div>

                    <div>
                        <label for="impact">Impact Level:</label>
                        <select id="impact" name="impact">
                            <option value="all" <?= $impactFilter === 'all' ? 'selected' : '' ?>>All Levels</option>
                            <option value="high" <?= $impactFilter === 'high' ? 'selected' : '' ?>>High</option>
                            <option value="medium" <?= $impactFilter === 'medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="low" <?= $impactFilter === 'low' ? 'selected' : '' ?>>Low</option>
                        </select>
                    </div>

                    <div>
                        <label for="category">Category:</label>
                        <select id="category" name="category">
                            <option value="all" <?= $categoryFilter === 'all' ? 'selected' : '' ?>>All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['CategoryID'] ?>" <?= $categoryFilter == $cat['CategoryID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['CategoryName']) ?>
                                </option>
                            <?php endforeach; ?>
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
                    <button type="submit">Apply Filters</button>
                    <a href="scm_disruptions.php" class="btn-secondary" style="display: inline-block; padding: 14px 32px; text-decoration: none;">Clear Filters</a>
                </div>
            </form>
        </div>

        <!-- Disruption List -->
        <div class="content-section">
            <h3>Disruption Events (<?= count($disruptions) ?> results)</h3>

            <?php if (count($disruptions) > 0): ?>
                <?php foreach ($disruptions as $disruption): ?>
                    <div class="disruption-card">
                        <div class="disruption-header">
                            <div>
                                <h3><?= htmlspecialchars($disruption['CategoryName']) ?></h3>
                                <p style="color: var(--text-light); margin: 4px 0 0 0; font-size: 0.95rem;">
                                    <?= htmlspecialchars($disruption['CategoryDescription']) ?>
                                </p>
                            </div>
                            <div>
                                <span class="status-badge status-<?= strtolower($disruption['Status']) ?>">
                                    <?= htmlspecialchars($disruption['Status']) ?>
                                </span>
                                <span class="impact-badge impact-<?= strtolower($disruption['MaxImpact']) ?>">
                                    <?= htmlspecialchars($disruption['MaxImpact']) ?> Impact
                                </span>
                            </div>
                        </div>

                        <div class="disruption-details">
                            <div class="detail-item">
                                <strong>Event ID</strong>
                                #<?= htmlspecialchars($disruption['EventID']) ?>
                            </div>
                            <div class="detail-item">
                                <strong>Start Date</strong>
                                <?= htmlspecialchars($disruption['EventDate']) ?>
                            </div>
                            <div class="detail-item">
                                <strong>Recovery Date</strong>
                                <?= $disruption['EventRecoveryDate'] ? htmlspecialchars($disruption['EventRecoveryDate']) : '<em>Ongoing</em>' ?>
                            </div>
                            <div class="detail-item">
                                <strong>Duration</strong>
                                <?= htmlspecialchars($disruption['DurationDays']) ?> days
                            </div>
                            <div class="detail-item">
                                <strong>Companies Affected</strong>
                                <?= htmlspecialchars($disruption['CompanyCount']) ?>
                            </div>
                        </div>

                        <div class="affected-companies">
                            <h4>📍 Affected Companies & Impact Levels</h4>
                            <p><?= htmlspecialchars($disruption['CompaniesWithImpact']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-results">
                    <div class="icon">🔍</div>
                    <p>No disruptions found matching your filters.</p>
                    <p style="font-size: 1rem; margin-top: 10px;">Try adjusting your search criteria.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
