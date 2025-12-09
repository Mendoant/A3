<?php
// dashboard.php - Senior Manager Dashboard
require_once '../config.php';
requireLogin();

// Redirect non-Senior Managers to SCM dashboard
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERP Dashboard</title>
    <script src="../assets/forward_protection.js"></script>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <header>
        <div class="container">
            <h1>Enterprise Resource Planning Portal</h1>
            <nav>
                <span class="text-white">Welcome, <?php echo htmlspecialchars($_SESSION['FullName']); ?></span>
                <a href="../logout.php" class="logout-btn">Logout</a>
            </nav>
        </div>
    </header>

    <div class="container">
        <div class="welcome-section">
            <h2>Welcome to Your Senior Manager Dashboard</h2>
            <p><strong>User:</strong> <?php echo htmlspecialchars($_SESSION['FullName']); ?></p>
            <p><strong>Role:</strong> Senior Manager</p>
            <p><strong>User ID:</strong> <?php echo htmlspecialchars($_SESSION['UserID']); ?></p>
        </div>

        <h2>Senior Manager Features</h2>
        <p class="text-light">Access executive-level insights and management tools:</p>

        <div class="dashboard-grid">
            <a href="financial.php" class="dashboard-card">
                <h3>Financial Overview</h3>
                <p>Comprehensive financial health analysis:</p>
                <ul>
                    <li>Average financial health by company</li>
                    <li>Financial metrics by company type</li>
                    <li>Regional financial performance</li>
                    <li>Trend analysis over time</li>
                    <li>Health score rankings</li>
                </ul>
                <span class="btn-link">View Financials →</span>
            </a>

            <a href="regional_disruptions.php" class="dashboard-card">
                <h3>Regional Disruption Overview</h3>
                <p>Geographic analysis of supply chain disruptions:</p>
                <ul>
                    <li>Total disruptions by region</li>
                    <li>High-impact event tracking</li>
                    <li>Geographic heat maps</li>
                    <li>Regional risk assessment</li>
                    <li>Comparative analysis</li>
                </ul>
                <span class="btn-link">View Regional Data →</span>
            </a>

            <a href="critical_companies.php" class="dashboard-card">
                <h3>Critical Companies</h3>
                <p>Identify and monitor critical supply chain nodes:</p>
                <ul>
                    <li>Criticality score rankings</li>
                    <li>Downstream impact analysis</li>
                    <li>High-impact disruption counts</li>
                    <li>Risk concentration metrics</li>
                    <li>Strategic importance assessment</li>
                </ul>
                <span class="btn-link">View Critical Companies →</span>
            </a>

            <a href="timeline.php" class="dashboard-card">
                <h3>Disruption Timeline</h3>
                <p>Temporal analysis of supply chain events:</p>
                <ul>
                    <li>Disruption frequency over time</li>
                    <li>Trend identification</li>
                    <li>Seasonal pattern analysis</li>
                    <li>Historical comparisons</li>
                    <li>Forecasting insights</li>
                </ul>
                <span class="btn-link">View Timeline →</span>
            </a>

            <a href="companies.php" class="dashboard-card">
                <h3>Company Management</h3>
                <p>Comprehensive company information and controls:</p>
                <ul>
                    <li>View detailed company profiles</li>
                    <li>Financial status by region</li>
                    <li>Company search and filtering</li>
                    <li>Performance metrics</li>
                </ul>
                <span class="btn-link">View Companies →</span>
            </a>

            <a href="add_company.php" class="dashboard-card">
                <h3>Add New Company</h3>
                <p>Expand your supply chain network:</p>
                <ul>
                    <li>Create new company records</li>
                    <li>Set location and type</li>
                    <li>Define tier levels</li>
                    <li>Establish dependencies</li>
                </ul>
                <span class="btn-link">Add Company →</span>
            </a>

            <a href="distributors.php" class="dashboard-card">
                <h3>Distributor Rankings</h3>
                <p>Performance metrics for logistics partners:</p>
                <ul>
                    <li>Top distributors by volume</li>
                    <li>Average delay analysis</li>
                    <li>Efficiency rankings</li>
                    <li>Detailed distributor profiles</li>
                    <li>Performance comparisons</li>
                </ul>
                <span class="btn-link">View Distributors →</span>
            </a>

            <a href="disruptions.php" class="dashboard-card">
                <h3>Company Disruption Analysis</h3>
                <p>Detailed disruption impact by company:</p>
                <ul>
                    <li>All disruptions by company</li>
                    <li>Companies affected by events</li>
                    <li>Impact level analysis</li>
                    <li>Recovery time tracking</li>
                </ul>
                <span class="btn-link">View Analysis →</span>
            </a>
        </div>
    </div>
</body>
</html>