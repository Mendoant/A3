<?php
// dashboard_scm.php - Supply Chain Manager Dashboard
require_once 'config.php';
requireLogin();

// Redirect Senior Managers to their dashboard
if (hasRole('SeniorManager')) {
    header('Location: dashboard_erp.php');
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCM Dashboard</title>
    <link rel="stylesheet" href="assets/styles.css">
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
            background: linear-gradient(135deg, var(--purdue-gold) 0%, var(--purdue-gold-dark) 100%);
            color: var(--purdue-black);
            text-decoration: none;
            border-radius: 5px;
            font-weight: 700;
            transition: all 0.3s;
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

        .logout-btn {
            background: #dc3545;
            color: white;
            padding: 10px 24px;
            text-decoration: none;
            border-radius: 5px;
            display: inline-block;
            font-weight: 600;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background: #c82333;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
        }

        /* Disruption Banner Styles */
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
                <a href="logout.php" class="logout-btn">Logout</a>
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
            <h2>Welcome to Your Supply Chain Manager Dashboard</h2>
            <p><strong>User:</strong> <?= htmlspecialchars($_SESSION['FullName']) ?></p>
            <p><strong>Role:</strong> Supply Chain Manager</p>
            <p><strong>User ID:</strong> <?= htmlspecialchars($_SESSION['UserID']) ?></p>
        </div>
        
        <h2>Supply Chain Manager Features</h2>
        <p style="color: var(--text-light);">Select a module to begin managing your supply chain operations:</p>

        <div class="dashboard-grid">
            <!-- Company Information -->
            <a href="scm_company_info.php" class="dashboard-card">
                <span class="icon">🏢</span>
                <h3>Company Information</h3>
                <p>Search and view detailed company information including:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>Company address and location</li>
                    <li>Company type and tier level</li>
                    <li>Dependencies (upstream/downstream)</li>
                    <li>Financial status and capacity</li>
                    <li>Products supplied and diversity</li>
                    <li>Recent transactions</li>
                </ul>
                <span class="btn-link">View Companies →</span>
            </a>

            <!-- Key Performance Indicators -->
            <a href="scm_kpis.php" class="dashboard-card">
                <span class="icon">📈</span>
                <h3>Key Performance Indicators</h3>
                <p>Monitor critical supply chain metrics:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>On-time delivery rates</li>
                    <li>Average delivery delays</li>
                    <li>Financial health status</li>
                    <li>Performance trends over time</li>
                </ul>
                <span class="btn-link">View KPIs →</span>
            </a>

            <!-- Disruption Events -->
            <a href="scm_disruptions.php" class="dashboard-card">
                <span class="icon">⚠️</span>
                <h3>Disruption Events</h3>
                <p>Track and analyze supply chain disruptions:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>Disruption frequency by company/region</li>
                    <li>Average recovery time</li>
                    <li>High-impact disruption rates</li>
                    <li>Total downtime analysis</li>
                    <li>Regional risk concentration</li>
                    <li>Severity distribution</li>
                </ul>
                <span class="btn-link">View Disruptions →</span>
            </a>

            <!-- Transactions -->
            <a href="scm_transactions.php" class="dashboard-card">
                <span class="icon">📦</span>
                <h3>Transaction Management</h3>
                <p>View and manage all supply chain transactions:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>Shipping records</li>
                    <li>Receiving confirmations</li>
                    <li>Inventory adjustments</li>
                    <li>Filter by date range and location</li>
                    <li>Track shipment status</li>
                </ul>
                <span class="btn-link">View Transactions →</span>
            </a>

            <!-- Distributor Analytics -->
            <a href="scm_distributors.php" class="dashboard-card">
                <span class="icon">🚚</span>
                <h3>Distributor Analytics</h3>
                <p>Analyze distributor performance:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>Shipment volume analysis</li>
                    <li>On-time delivery rates</li>
                    <li>Current shipment status</li>
                    <li>Products handled</li>
                    <li>Disruption exposure metrics</li>
                    <li>Performance visualizations</li>
                </ul>
                <span class="btn-link">View Distributors →</span>
            </a>

            <!-- Alerts & Notifications -->
            <div class="dashboard-card" style="cursor: default; opacity: 0.8;">
                <span class="icon">🔔</span>
                <h3>Alerts & Notifications</h3>
                <p>Real-time alerts for supply chain issues:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>Active disruptions</li>
                    <li>Delayed shipments</li>
                    <li>Low inventory warnings</li>
                    <li>System notifications</li>
                </ul>
                <span style="color: var(--purdue-gold-dark); font-style: italic;">Coming Soon</span>
            </div>
        </div>
    </div>
</body>
</html>
