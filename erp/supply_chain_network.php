<?php
// supply_chain_network.php - Supply Chain Network Analysis
require_once '../config.php';
requireLogin();

// Redirect non-Senior Managers
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$filterTier = isset($_GET['filter_tier']) ? $_GET['filter_tier'] : '';
$filterType = isset($_GET['filter_type']) ? $_GET['filter_type'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supply Chain Network - ERP Dashboard</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .filter-section {
            background: rgba(0, 0, 0, 0.6);
            padding: 24px;
            border-radius: 12px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            margin-bottom: 24px;
        }
        
        .filter-section form {
            display: flex;
            gap: 16px;
            align-items: end;
            flex-wrap: wrap;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-group label {
            color: var(--purdue-gold);
            font-weight: 600;
        }
        
        .form-group select {
            padding: 10px;
            border-radius: 5px;
            border: 1px solid rgba(207, 185, 145, 0.3);
            background: rgba(0, 0, 0, 0.4);
            color: white;
            font-size: 1rem;
        }
        
        .btn-filter {
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--purdue-gold) 0%, var(--purdue-gold-dark) 100%);
            color: var(--purdue-black);
            border: none;
            border-radius: 5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-filter:hover {
            box-shadow: 0 6px 20px rgba(207, 185, 145, 0.6);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: rgba(207, 185, 145, 0.1);
            padding: 20px;
            border-radius: 8px;
            border: 1px solid rgba(207, 185, 145, 0.3);
        }
        
        .stat-card h4 {
            color: var(--purdue-gold);
            margin: 0 0 8px 0;
            font-size: 0.9rem;
        }
        
        .stat-card .value {
            color: white;
            font-size: 1.8rem;
            font-weight: 700;
        }
        
        .chart-card {
            background: rgba(0, 0, 0, 0.6);
            padding: 24px;
            border-radius: 12px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            margin-bottom: 24px;
        }
        
        .chart-card h3 {
            color: var(--purdue-gold);
            margin-top: 0;
            margin-bottom: 20px;
        }
        
        .chart-wrapper {
            position: relative;
            height: 400px;
        }
        
        .network-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        
        .network-table th,
        .network-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(207, 185, 145, 0.2);
        }
        
        .network-table th {
            background: rgba(207, 185, 145, 0.2);
            color: var(--purdue-gold);
            font-weight: 700;
        }
        
        .network-table td {
            color: white;
        }
        
        .network-table tr:hover {
            background: rgba(207, 185, 145, 0.1);
        }
        
        .back-link {
            display: inline-block;
            padding: 12px 24px;
            background: rgba(207, 185, 145, 0.2);
            color: var(--purdue-gold);
            text-decoration: none;
            border-radius: 5px;
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        
        .back-link:hover {
            background: rgba(207, 185, 145, 0.3);
        }
        
        .complexity-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .complexity-high {
            background: rgba(220, 53, 69, 0.3);
            color: #ff6b7a;
            border: 1px solid #dc3545;
        }
        
        .complexity-medium {
            background: rgba(255, 193, 7, 0.3);
            color: #ffc107;
            border: 1px solid #ffc107;
        }
        
        .complexity-low {
            background: rgba(40, 167, 69, 0.3);
            color: #5cb85c;
            border: 1px solid #28a745;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Supply Chain Network Analysis</h1>
            <nav>
                <span style="color: white;">Welcome, <?php echo htmlspecialchars($_SESSION['FullName']); ?></span>
                <a href="../logout.php" class="logout-btn">Logout</a>
            </nav>
        </div>
    </header>

    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        
        <div class="filter-section">
            <form method="GET" action="">
                <div class="form-group">
                    <label for="filter_tier">Filter by Tier:</label>
                    <select id="filter_tier" name="filter_tier">
                        <option value="">All Tiers</option>
                        <option value="1" <?php echo $filterTier === '1' ? 'selected' : ''; ?>>Tier 1</option>
                        <option value="2" <?php echo $filterTier === '2' ? 'selected' : ''; ?>>Tier 2</option>
                        <option value="3" <?php echo $filterTier === '3' ? 'selected' : ''; ?>>Tier 3</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="filter_type">Filter by Type:</label>
                    <select id="filter_type" name="filter_type">
                        <option value="">All Types</option>
                        <option value="Manufacturer" <?php echo $filterType === 'Manufacturer' ? 'selected' : ''; ?>>Manufacturer</option>
                        <option value="Distributor" <?php echo $filterType === 'Distributor' ? 'selected' : ''; ?>>Distributor</option>
                        <option value="Retailer" <?php echo $filterType === 'Retailer' ? 'selected' : ''; ?>>Retailer</option>
                    </select>
                </div>
                
                <button type="submit" class="btn-filter">Apply Filter</button>
            </form>
        </div>

        <?php
        $pdo = getPDO();
        
        // Build query with filters
        $whereClause = "WHERE 1=1";
        $params = array();
        
        if ($filterTier !== '') {
            $whereClause .= " AND c.TierLevel = :filterTier";
            $params[':filterTier'] = $filterTier;
        }
        
        if ($filterType !== '') {
            $whereClause .= " AND c.Type = :filterType";
            $params[':filterType'] = $filterType;
        }
        
        // Query: Network complexity and dependencies
        $sql = "
            SELECT 
                c.CompanyID,
                c.CompanyName,
                c.Type,
                c.TierLevel,
                l.ContinentName as Region,
                (SELECT COUNT(*) FROM DependsOn dep WHERE dep.DownstreamCompanyID = c.CompanyID) as SupplierCount,
                (SELECT COUNT(*) FROM DependsOn dep WHERE dep.UpstreamCompanyID = c.CompanyID) as CustomerCount,
                (SELECT COUNT(DISTINCT sp.ProductID) FROM SuppliesProduct sp WHERE sp.SupplierID = c.CompanyID) as ProductDiversity,
                ((SELECT COUNT(*) FROM DependsOn dep WHERE dep.DownstreamCompanyID = c.CompanyID) + 
                 (SELECT COUNT(*) FROM DependsOn dep WHERE dep.UpstreamCompanyID = c.CompanyID)) as TotalConnections
            FROM Company c
            LEFT JOIN Location l ON c.LocationID = l.LocationID
            " . $whereClause . "
            ORDER BY TotalConnections DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $network_data = array();
        $company_names = array();
        $connection_counts = array();
        
        $total_companies = 0;
        $total_connections = 0;
        $total_suppliers = 0;
        $total_customers = 0;
        $max_connections = 0;
        $most_connected = '';
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $network_data[] = $row;
            
            if (count($company_names) < 15) {
                $company_names[] = $row['CompanyName'];
                $connection_counts[] = (int)$row['TotalConnections'];
            }
            
            $total_companies++;
            $total_connections += $row['TotalConnections'];
            $total_suppliers += $row['SupplierCount'];
            $total_customers += $row['CustomerCount'];
            
            if ($row['TotalConnections'] > $max_connections) {
                $max_connections = $row['TotalConnections'];
                $most_connected = $row['CompanyName'];
            }
        }
        
        $avg_connections = $total_companies > 0 ? round($total_connections / $total_companies, 1) : 0;
        
        // Query: Product diversity across network
        $sql_products = "
            SELECT 
                p.Category,
                COUNT(DISTINCT p.ProductID) as ProductCount,
                COUNT(DISTINCT sp.SupplierID) as SupplierCount
            FROM Product p
            LEFT JOIN SuppliesProduct sp ON p.ProductID = sp.ProductID
            GROUP BY p.Category
            ORDER BY ProductCount DESC
        ";
        
        $stmt_products = $pdo->query($sql_products);
        $product_categories = array();
        $product_counts = array();
        
        while ($row = $stmt_products->fetch(PDO::FETCH_ASSOC)) {
            $product_categories[] = $row['Category'];
            $product_counts[] = (int)$row['ProductCount'];
        }
        ?>

        <div class="stats-grid">
            <div class="stat-card">
                <h4>Total Companies in Network</h4>
                <div class="value"><?php echo $total_companies; ?></div>
            </div>
            <div class="stat-card">
                <h4>Total Connections</h4>
                <div class="value"><?php echo $total_connections; ?></div>
            </div>
            <div class="stat-card">
                <h4>Avg Connections per Company</h4>
                <div class="value"><?php echo $avg_connections; ?></div>
            </div>
            <div class="stat-card">
                <h4>Most Connected</h4>
                <div class="value" style="font-size: 1.2rem;"><?php echo htmlspecialchars($most_connected); ?></div>
                <small style="color: var(--purdue-gold);"><?php echo $max_connections; ?> connections</small>
            </div>
        </div>

        <div class="chart-card">
            <h3>Top 15 Most Connected Companies</h3>
            <div class="chart-wrapper">
                <canvas id="connectionChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>Product Diversity Across Categories</h3>
            <div class="chart-wrapper">
                <canvas id="productChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>Network Complexity Analysis</h3>
            <table class="network-table">
                <thead>
                    <tr>
                        <th>Company Name</th>
                        <th>Type</th>
                        <th>Tier</th>
                        <th>Region</th>
                        <th>Suppliers</th>
                        <th>Customers</th>
                        <th>Total Connections</th>
                        <th>Product Diversity</th>
                        <th>Complexity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($network_data as $data) {
                        // Determine complexity level
                        $complexity = 'Low';
                        $complexityClass = 'complexity-low';
                        
                        if ($data['TotalConnections'] >= 10) {
                            $complexity = 'High';
                            $complexityClass = 'complexity-high';
                        } elseif ($data['TotalConnections'] >= 5) {
                            $complexity = 'Medium';
                            $complexityClass = 'complexity-medium';
                        }
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($data['CompanyName']); ?></strong></td>
                        <td><?php echo htmlspecialchars($data['Type']); ?></td>
                        <td>Tier <?php echo $data['TierLevel']; ?></td>
                        <td><?php echo htmlspecialchars($data['Region']); ?></td>
                        <td><?php echo $data['SupplierCount']; ?></td>
                        <td><?php echo $data['CustomerCount']; ?></td>
                        <td><strong style="color: var(--purdue-gold);"><?php echo $data['TotalConnections']; ?></strong></td>
                        <td><?php echo $data['ProductDiversity']; ?> products</td>
                        <td><span class="complexity-badge <?php echo $complexityClass; ?>"><?php echo $complexity; ?></span></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        var purdueGold = '#CFB991';
        var goldDark = '#9d8661';

        // Connection Chart
        var ctxConnection = document.getElementById('connectionChart').getContext('2d');
        new Chart(ctxConnection, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($company_names); ?>,
                datasets: [{
                    label: 'Total Connections',
                    data: <?php echo json_encode($connection_counts); ?>,
                    backgroundColor: purdueGold,
                    borderColor: goldDark,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { color: 'white' },
                        grid: { color: 'rgba(207, 185, 145, 0.1)' }
                    },
                    y: {
                        ticks: { 
                            color: 'white',
                            font: { size: 10 }
                        },
                        grid: { color: 'rgba(207, 185, 145, 0.1)' }
                    }
                },
                plugins: {
                    legend: {
                        labels: { color: 'white' }
                    }
                }
            }
        });

        // Product Chart
        var ctxProduct = document.getElementById('productChart').getContext('2d');
        new Chart(ctxProduct, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($product_categories); ?>,
                datasets: [{
                    label: 'Products',
                    data: <?php echo json_encode($product_counts); ?>,
                    backgroundColor: [
                        '#CFB991',
                        '#9d8661',
                        '#F0C85C',
                        '#B8860B',
                        '#DAA520'
                    ],
                    borderColor: '#000',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { color: 'white' }
                    }
                }
            }
        });
    </script>
</body>
</html>