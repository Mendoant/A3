<?php
// dashboard_scm.php - Supply Chain Manager Dashboard
require_once 'config.php';
requireLogin();

// Redirect Senior Managers to their dashboard
if (hasRole('SeniorManager')) {
    header('Location: dashboard_erp.php');
    exit;
}
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