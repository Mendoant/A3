<?php
// scm/dashboard.php - Supply Chain Management Dashboard
require_once '../config.php';
requireLogin();

// kick out senior managers - they have erp module
if (hasRole('SeniorManager')) {
    header('Location: ../erp/dashboard.php');
    exit;
}
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
            display: flex;
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
        <div class="welcome-section">
            <h2>Welcome to Supply Chain Management</h2>
            <p><strong>User:</strong> <?= htmlspecialchars($_SESSION['FullName']) ?></p>
            <p><strong>Role:</strong> <?= htmlspecialchars($_SESSION['Role']) ?></p>
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
