<?php
// product_analysis.php - Product Supply Chain Analysis
require_once '../config.php';
requireLogin();

// Redirect non-Senior Managers
if (!hasRole('SeniorManager')) {
    header('Location: ../scm/dashboard.php');
    exit;
}

$filterCategory = isset($_GET['filter_category']) ? $_GET['filter_category'] : '';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-6 months'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Analysis - ERP Dashboard</title>
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
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            align-items: end;
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
        
        .form-group input,
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
        
        .product-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        
        .product-table th,
        .product-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(207, 185, 145, 0.2);
        }
        
        .product-table th {
            background: rgba(207, 185, 145, 0.2);
            color: var(--purdue-gold);
            font-weight: 700;
        }
        
        .product-table td {
            color: white;
        }
        
        .product-table tr:hover {
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
        
        .category-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            background: rgba(207, 185, 145, 0.2);
            color: var(--purdue-gold);
            border: 1px solid rgba(207, 185, 145, 0.5);
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Product Analysis</h1>
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
                    <label for="filter_category">Category:</label>
                    <select id="filter_category" name="filter_category">
                        <option value="">All Categories</option>
                        <option value="Electronics" <?php echo $filterCategory === 'Electronics' ? 'selected' : ''; ?>>Electronics</option>
                        <option value="Raw Material" <?php echo $filterCategory === 'Raw Material' ? 'selected' : ''; ?>>Raw Material</option>
                        <option value="Component" <?php echo $filterCategory === 'Component' ? 'selected' : ''; ?>>Component</option>
                        <option value="Finished Good" <?php echo $filterCategory === 'Finished Good' ? 'selected' : ''; ?>>Finished Good</option>
                        <option value="Other" <?php echo $filterCategory === 'Other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="start_date">Start Date:</label>
                    <input type="date" id="start_date" name="start_date" 
                           value="<?php echo htmlspecialchars($startDate); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="end_date">End Date:</label>
                    <input type="date" id="end_date" name="end_date" 
                           value="<?php echo htmlspecialchars($endDate); ?>" required>
                </div>
                
                <button type="submit" class="btn-filter">Apply Filter</button>
            </form>
        </div>

        <?php
        $pdo = getPDO();
        
        // Build WHERE clause
        $whereClause = "WHERE 1=1";
        $params = array(':startDate' => $startDate, ':endDate' => $endDate);
        
        if ($filterCategory !== '') {
            $whereClause .= " AND p.Category = :filterCategory";
            $params[':filterCategory'] = $filterCategory;
        }
        
        // Query: Product flow analysis
        $sql = "
            SELECT 
                p.ProductID,
                p.ProductName,
                p.Category,
                COUNT(DISTINCT sp.SupplierID) as SupplierCount,
                COUNT(DISTINCT s.ShipmentID) as TotalShipments,
                SUM(s.Quantity) as TotalVolume,
                AVG(s.Quantity) as AvgShipmentSize,
                COUNT(DISTINCT s.DestinationCompanyID) as UniqueDestinations
            FROM Product p
            LEFT JOIN SuppliesProduct sp ON p.ProductID = sp.ProductID
            LEFT JOIN Shipping s ON p.ProductID = s.ProductID AND s.PromisedDate BETWEEN :startDate AND :endDate
            " . $whereClause . "
            GROUP BY p.ProductID, p.ProductName, p.Category
            ORDER BY TotalVolume DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $product_data = array();
        $product_names = array();
        $volumes = array();
        
        $total_products = 0;
        $total_shipments = 0;
        $total_volume = 0;
        $top_product = '';
        $top_volume = 0;
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $product_data[] = $row;
            
            if (count($product_names) < 10 && $row['TotalVolume'] > 0) {
                $product_names[] = $row['ProductName'];
                $volumes[] = (int)$row['TotalVolume'];
            }
            
            $total_products++;
            $total_shipments += $row['TotalShipments'];
            $total_volume += $row['TotalVolume'];
            
            if ($row['TotalVolume'] > $top_volume) {
                $top_volume = $row['TotalVolume'];
                $top_product = $row['ProductName'];
            }
        }
        
        // Query: Category distribution
        $sql_category = "
            SELECT 
                p.Category,
                COUNT(DISTINCT p.ProductID) as ProductCount,
                COUNT(DISTINCT sp.SupplierID) as SupplierCount
            FROM Product p
            LEFT JOIN SuppliesProduct sp ON p.ProductID = sp.ProductID
            GROUP BY p.Category
            ORDER BY ProductCount DESC
        ";
        
        $stmt_category = $pdo->query($sql_category);
        $categories = array();
        $category_counts = array();
        
        while ($row = $stmt_category->fetch(PDO::FETCH_ASSOC)) {
            $categories[] = $row['Category'];
            $category_counts[] = (int)$row['ProductCount'];
        }
        ?>

        <div class="stats-grid">
            <div class="stat-card">
                <h4>Total Products</h4>
                <div class="value"><?php echo $total_products; ?></div>
            </div>
            <div class="stat-card">
                <h4>Total Shipments</h4>
                <div class="value"><?php echo number_format($total_shipments); ?></div>
            </div>
            <div class="stat-card">
                <h4>Total Volume Moved</h4>
                <div class="value"><?php echo number_format($total_volume); ?></div>
            </div>
            <div class="stat-card">
                <h4>Top Product</h4>
                <div class="value" style="font-size: 1.2rem;"><?php echo htmlspecialchars($top_product ? $top_product : 'N/A'); ?></div>
                <small style="color: var(--purdue-gold);"><?php echo number_format($top_volume); ?> units</small>
            </div>
        </div>

        <div class="chart-card">
            <h3>Top 10 Products by Volume</h3>
            <div class="chart-wrapper">
                <canvas id="volumeChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>Product Distribution by Category</h3>
            <div class="chart-wrapper">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h3>Product Supply Chain Details</h3>
            <table class="product-table">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Suppliers</th>
                        <th>Total Shipments</th>
                        <th>Total Volume</th>
                        <th>Avg Shipment Size</th>
                        <th>Unique Destinations</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($product_data as $data): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($data['ProductName']); ?></strong></td>
                        <td><span class="category-badge"><?php echo htmlspecialchars($data['Category']); ?></span></td>
                        <td><?php echo $data['SupplierCount']; ?></td>
                        <td><?php echo number_format($data['TotalShipments']); ?></td>
                        <td><strong style="color: var(--purdue-gold);"><?php echo number_format($data['TotalVolume']); ?></strong></td>
                        <td><?php echo $data['AvgShipmentSize'] ? round($data['AvgShipmentSize']) : 'N/A'; ?></td>
                        <td><?php echo $data['UniqueDestinations']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        var purdueGold = '#CFB991';
        var goldDark = '#9d8661';

        // Volume Chart
        var ctxVolume = document.getElementById('volumeChart').getContext('2d');
        new Chart(ctxVolume, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($product_names); ?>,
                datasets: [{
                    label: 'Total Volume',
                    data: <?php echo json_encode($volumes); ?>,
                    backgroundColor: purdueGold,
                    borderColor: goldDark,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: 'white' },
                        grid: { color: 'rgba(207, 185, 145, 0.1)' }
                    },
                    x: {
                        ticks: { 
                            color: 'white',
                            maxRotation: 45,
                            minRotation: 45
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

        // Category Chart
        var ctxCategory = document.getElementById('categoryChart').getContext('2d');
        new Chart(ctxCategory, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode($categories); ?>,
                datasets: [{
                    label: 'Products',
                    data: <?php echo json_encode($category_counts); ?>,
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