<?php
// scm/dashboard.php - Supply Chain Management Dashboard
require_once '../config.php';
requireLogin();

// kick out senior managers - they have erp module
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SCM Dashboard</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-top: 40px;
        }
        
        .dashboard-card {
            background: rgba(0, 0, 0, 0.6);
            padding: 32px;
            border-radius: 12px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            box-shadow: 0 8px 32px rgba(207, 185, 145, 0.2);
            transition: all 0.3s ease;
            text-decoration: none;
            display: block;
            flex-direction: column;
            cursor: pointer;
        }
        
        .dashboard-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 16px 48px rgba(207, 185, 145, 0.4);
            border-color: var(--purdue-gold);
        }
        
        .dashboard-card h3 {
            margin-top: 0;
            font-size: 1.5rem;
            color: var(--purdue-gold);
            margin-bottom: 16px;
        }
        
        .dashboard-card p {
            color: var(--text-light);
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        .dashboard-card .icon {
            font-size: 3rem;
            margin-bottom: 16px;
            display: block;
        }
        
        .dashboard-card .btn-link {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--purdue-gold) 0%, #d4c49e 100%);
            color: var(--purdue-black);
            text-decoration: none;
            border-radius: 5px;
            font-weight: 700;
            transition: all 0.3s;
            margin-top: auto;
            text-align: center;
        }
        
        .dashboard-card .btn-link:hover {
            box-shadow: 0 6px 20px rgba(207, 185, 145, 0.6);
        }
        
        .welcome-section {
            background: rgba(0, 0, 0, 0.6);
            padding: 40px;
            border-radius: 12px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            margin-bottom: 40px;
        }
        
        .welcome-section h2 {
            margin-top: 0;
        }

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


    
    <div class="container">
    <?php if (!empty($currentDisruptions)): ?>
        <div class="disruption-banner-container">
            <div class="disruption-banner-header">
                <span class="icon">⚠️</span>
                <span>ACTIVE DISRUPTIONS</span>
                <span class="disruption-count"><?= count($currentDisruptions) ?> Active</span>
                <span style="font-size: 0.85rem; font-weight: normal; margin-left: auto;">
                    Hover to pause • <a href="scm_disruptions.php" style="color: #FFD700; text-decoration: underline;">View Details</a>
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
        <div class="welcome-section">
            <h2>Welcome to Supply Chain Management</h2>
            <p><strong>User:</strong> <?= htmlspecialchars($_SESSION['FullName']) ?></p>
            <p><strong>Role:</strong> <?= htmlspecialchars($_SESSION['Role']) ?></p>
            <p><strong>User ID:</strong> <?php echo htmlspecialchars($_SESSION['UserID']); ?></p>
        </div>

        <h2>SCM Features</h2>
        <p style="color: var(--text-light);">Access supply chain management tools:</p>

        <div class="dashboard-grid">
            <!-- Companies -->
            <a href="companies.php" class="dashboard-card">
                <h3>Company Management</h3>
                <p>View and manage company information, search companies, update details, and analyze company performance metrics.</p>
                <span class="btn-link">View Companies →</span>
            </a>

            <!-- KPIs -->
            <a href="kpis.php" class="dashboard-card">
                <h3>Key Performance Indicators</h3>
                <p>Track on-time delivery rates, average recovery time, disruption metrics, and supply chain health scores.</p>
                <span class="btn-link">View KPIs →</span>
            </a>

            <!-- Disruptions -->
            <a href="disruptions.php" class="dashboard-card">
                <h3>Disruption Analysis</h3>
                <p>Analyze disruption frequency, recovery times, regional risk, and severity distribution across the supply chain.</p>
                <span class="btn-link">View Disruptions →</span>
            </a>

            <!-- Transactions -->
            <a href="transactions.php" class="dashboard-card">
                <h3>Transaction Management</h3>
                <p>Monitor shipments, track delivery status, analyze delays, and review transaction details by company and date.</p>
                <span class="btn-link">View Transactions →</span>
            </a>

            <!-- Cost Analysis -->
            <a href="transaction_costs.php" class="dashboard-card">
                <h3>Cost Analysis</h3>
                <p>Review shipping costs, delay penalties, cost trends, and analyze expenses by product category and time period.</p>
                <span class="btn-link">View Costs →</span>
            </a>

            <!-- Distributors -->
            <a href="distributors.php" class="dashboard-card">
                <h3>Distributor Performance</h3>
                <p>Track distributor rankings, on-time rates, shipment volumes, and disruption exposure across logistics partners.</p>
                <span class="btn-link">View Distributors →</span>
            </a>
        </div>
    </div>
</body>
</html>
