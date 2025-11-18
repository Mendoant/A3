<?php
// dashboard_erp.php - Senior Manager Dashboard
require_once 'config.php';
requireLogin();

// Redirect non-Senior Managers to SCM dashboard
if (!hasRole('SeniorManager')) {
    header('Location: dashboard_scm.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERP Dashboard</title>
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
            <h1>Enterprise Resource Planning Portal</h1>
            <nav>
                <span style="color: white;">Welcome, <?= htmlspecialchars($_SESSION['FullName']) ?></span>
                <a href="logout.php" class="logout-btn">Logout</a>
            </nav>
        </div>
    </header>

    <div class="container">
        <div class="welcome-section">
            <h2>Welcome to Your Senior Manager Dashboard</h2>
            <p><strong>User:</strong> <?= htmlspecialchars($_SESSION['FullName']) ?></p>
            <p><strong>Role:</strong> Senior Manager</p>
            <p><strong>User ID:</strong> <?= htmlspecialchars($_SESSION['UserID']) ?></p>
        </div>

        <h2>Senior Manager Features</h2>
        <p style="color: var(--text-light);">Access executive-level insights and management tools:</p>

        <div class="dashboard-grid">
            <!-- Financial Overview -->
            <a href="sm_financial.php" class="dashboard-card">
                <span class="icon">💰</span>
                <h3>Financial Overview</h3>
                <p>Comprehensive financial health analysis:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>Average financial health by company</li>
                    <li>Financial metrics by company type</li>
                    <li>Regional financial performance</li>
                    <li>Trend analysis over time</li>
                    <li>Health score rankings</li>
                </ul>
                <span class="btn-link">View Financials →</span>
            </a>

            <!-- Regional Disruptions -->
            <a href="sm_regional_disruptions.php" class="dashboard-card">
                <span class="icon">🌍</span>
                <h3>Regional Disruption Overview</h3>
                <p>Geographic analysis of supply chain disruptions:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>Total disruptions by region</li>
                    <li>High-impact event tracking</li>
                    <li>Geographic heat maps</li>
                    <li>Regional risk assessment</li>
                    <li>Comparative analysis</li>
                </ul>
                <span class="btn-link">View Regional Data →</span>
            </a>

            <!-- Critical Companies -->
            <a href="sm_critical_companies.php" class="dashboard-card">
                <span class="icon">🎯</span>
                <h3>Critical Companies</h3>
                <p>Identify and monitor critical supply chain nodes:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>Criticality score rankings</li>
                    <li>Downstream impact analysis</li>
                    <li>High-impact disruption counts</li>
                    <li>Risk concentration metrics</li>
                    <li>Strategic importance assessment</li>
                </ul>
                <span class="btn-link">View Critical Companies →</span>
            </a>

            <!-- Disruption Timeline -->
            <a href="sm_disruption_timeline.php" class="dashboard-card">
                <span class="icon">📅</span>
                <h3>Disruption Timeline</h3>
                <p>Temporal analysis of supply chain events:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>Disruption frequency over time</li>
                    <li>Trend identification</li>
                    <li>Seasonal pattern analysis</li>
                    <li>Historical comparisons</li>
                    <li>Forecasting insights</li>
                </ul>
                <span class="btn-link">View Timeline →</span>
            </a>

            <!-- Company Management -->
            <a href="sm_company_details.php" class="dashboard-card">
                <span class="icon">🏢</span>
                <h3>Company Management</h3>
                <p>Comprehensive company information and controls:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>View detailed company profiles</li>
                    <li>Financial status by region</li>
                    <li>Company search and filtering</li>
                    <li>Performance metrics</li>
                </ul>
                <span class="btn-link">View Companies →</span>
            </a>

            <!-- Add New Company -->
            <a href="sm_add_company.php" class="dashboard-card">
                <span class="icon">➕</span>
                <h3>Add New Company</h3>
                <p>Expand your supply chain network:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>Create new company records</li>
                    <li>Set location and type</li>
                    <li>Define tier levels</li>
                    <li>Establish dependencies</li>
                </ul>
                <span class="btn-link">Add Company →</span>
            </a>

            <!-- Distributor Rankings -->
            <a href="sm_distributors.php" class="dashboard-card">
                <span class="icon">🚛</span>
                <h3>Distributor Rankings</h3>
                <p>Performance metrics for logistics partners:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>Top distributors by volume</li>
                    <li>Average delay analysis</li>
                    <li>Efficiency rankings</li>
                    <li>Detailed distributor profiles</li>
                    <li>Performance comparisons</li>
                </ul>
                <span class="btn-link">View Distributors →</span>
            </a>

            <!-- Company Disruption Analysis -->
            <a href="sm_company_disruptions.php" class="dashboard-card">
                <span class="icon">📊</span>
                <h3>Company Disruption Analysis</h3>
                <p>Detailed disruption impact by company:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>All disruptions by company</li>
                    <li>Companies affected by events</li>
                    <li>Impact level analysis</li>
                    <li>Recovery time tracking</li>
                </ul>
                <span class="btn-link">View Analysis →</span>
            </a>

            <!-- Executive Reports -->
            <div class="dashboard-card" style="cursor: default; opacity: 0.8;">
                <span class="icon">📈</span>
                <h3>Executive Reports</h3>
                <p>Comprehensive reporting suite:</p>
                <ul style="color: var(--text-light); line-height: 1.8;">
                    <li>Custom report generation</li>
                    <li>Export capabilities</li>
                    <li>Automated insights</li>
                    <li>Predictive analytics</li>
                </ul>
                <span style="color: var(--purdue-gold-dark); font-style: italic;">Coming Soon</span>
            </div>
        </div>
    </div>
</body>
</html>