<?php
// scm_transaction_costs.php - Transaction Cost Analysis
require_once 'config.php';
requireLogin();

// Redirect Senior Managers
if (hasRole('SeniorManager')) {
    header('Location: dashboard_erp.php');
    exit;
}

$pdo = getPDO();
$message = '';
$results = null;
$productBreakdown = [];

// Get all companies for initial dropdown
$allCompanies = [];
$sql = "SELECT CompanyID, CompanyName, Type FROM Company WHERE Type = 'Manufacturer'ORDER BY CompanyName";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$allCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// AJAX endpoint for getting related companies
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_related_companies') {
    header('Content-Type: application/json');
    
    $companyId = $_GET['company_id'] ?? null;
    $direction = $_GET['direction'] ?? 'both'; // 'source', 'destination', or 'both'
    
    if (!$companyId) {
        echo json_encode(['error' => 'Company ID required']);
        exit;
    }
    
    $relatedCompanies = getRelatedCompanies($pdo, $companyId, $direction);
    echo json_encode($relatedCompanies);
    exit;
}

// Function to get companies that have transactions with the selected company
function getRelatedCompanies($pdo, $companyId, $direction = 'both') {
    $companies = [];
    
    if ($direction === 'source' || $direction === 'both') {
        // Get companies that this company ships TO
        $sql = "SELECT DISTINCT c.CompanyID, c.CompanyName, c.Type
                FROM Company c
                JOIN Shipping s ON c.CompanyID = s.DestinationCompanyID
                WHERE s.SourceCompanyID = :companyId
                ORDER BY c.CompanyName";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':companyId' => $companyId]);
        $destinations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $companies = array_merge($companies, $destinations);
    }
    
    if ($direction === 'destination' || $direction === 'both') {
        // Get companies that ship TO this company
        $sql = "SELECT DISTINCT c.CompanyID, c.CompanyName, c.Type
                FROM Company c
                JOIN Shipping s ON c.CompanyID = s.SourceCompanyID
                WHERE s.DestinationCompanyID = :companyId
                ORDER BY c.CompanyName";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':companyId' => $companyId]);
        $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $companies = array_merge($companies, $sources);
    }
    
    // Remove duplicates based on CompanyID
    $uniqueCompanies = [];
    $seenIds = [];
    foreach ($companies as $company) {
        if (!in_array($company['CompanyID'], $seenIds)) {
            $uniqueCompanies[] = $company;
            $seenIds[] = $company['CompanyID'];
        }
    }
    
    return $uniqueCompanies;
}

// Function to calculate transaction costs between two companies
function getTransactionCosts($pdo, $sourceId, $destId, $startDate, $endDate) {
    $sql = "SELECT 
                c1.CompanyName AS SourceCompany,
                c2.CompanyName AS DestinationCompany,
                COUNT(s.ShipmentID) AS TotalShipments,
                SUM(s.Quantity) AS TotalQuantityShipped,
                SUM(s.Quantity * sp.SupplyPrice) AS TotalSupplyCost,
                MIN(s.ActualDate) AS FirstShipmentDate,
                MAX(s.ActualDate) AS LastShipmentDate
            FROM 
                Shipping s
                INNER JOIN SuppliesProduct sp ON s.ProductID = sp.ProductID 
                    AND s.SourceCompanyID = sp.SupplierID
                INNER JOIN Company c1 ON s.SourceCompanyID = c1.CompanyID
                INNER JOIN Company c2 ON s.DestinationCompanyID = c2.CompanyID
            WHERE 
                s.SourceCompanyID = :sourceId
                AND s.DestinationCompanyID = :destId
                AND s.ActualDate BETWEEN :startDate AND :endDate
                AND s.ActualDate IS NOT NULL
            GROUP BY 
                c1.CompanyName, c2.CompanyName";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':sourceId' => $sourceId,
        ':destId' => $destId,
        ':startDate' => $startDate,
        ':endDate' => $endDate
    ]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Function to get product breakdown
function getProductBreakdown($pdo, $sourceId, $destId, $startDate, $endDate) {
    $sql = "SELECT 
                p.ProductName,
                p.Category,
                COUNT(s.ShipmentID) AS TotalShipments,
                SUM(s.Quantity) AS TotalQuantityShipped,
                sp.SupplyPrice AS UnitPrice,
                SUM(s.Quantity * sp.SupplyPrice) AS TotalSupplyCost
            FROM 
                Shipping s
                INNER JOIN SuppliesProduct sp ON s.ProductID = sp.ProductID 
                    AND s.SourceCompanyID = sp.SupplierID
                INNER JOIN Product p ON s.ProductID = p.ProductID
                INNER JOIN Company c1 ON s.SourceCompanyID = c1.CompanyID
                INNER JOIN Company c2 ON s.DestinationCompanyID = c2.CompanyID
            WHERE 
                s.SourceCompanyID = :sourceId
                AND s.DestinationCompanyID = :destId
                AND s.ActualDate BETWEEN :startDate AND :endDate
                AND s.ActualDate IS NOT NULL
            GROUP BY 
                p.ProductName, p.Category, sp.SupplyPrice
            ORDER BY 
                TotalSupplyCost DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':sourceId' => $sourceId,
        ':destId' => $destId,
        ':startDate' => $startDate,
        ':endDate' => $endDate
    ]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['analyze_costs'])) {
    $sourceId = $_POST['source_company'] ?? null;
    $destId = $_POST['destination_company'] ?? null;
    $startDate = $_POST['start_date'] ?? null;
    $endDate = $_POST['end_date'] ?? null;
    
    if ($sourceId && $destId && $startDate && $endDate) {
        if ($sourceId == $destId) {
            $message = "Error: Source and destination companies must be different.";
        } else {
            $results = getTransactionCosts($pdo, $sourceId, $destId, $startDate, $endDate);
            $productBreakdown = getProductBreakdown($pdo, $sourceId, $destId, $startDate, $endDate);
            
            if (!$results) {
                $message = "No transactions found between these companies in the specified date range.";
            }
        }
    } else {
        $message = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Cost Analysis - SCM</title>
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        .filter-box {
            background: rgba(0, 0, 0, 0.6);
            padding: 30px;
            border-radius: 12px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            margin-bottom: 30px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            margin-bottom: 8px;
            color: var(--purdue-gold);
            font-weight: bold;
        }
        .form-group select,
        .form-group input {
            padding: 10px;
            border-radius: 5px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            background: rgba(0, 0, 0, 0.4);
            color: var(--text-light);
        }
        .results-card {
            background: rgba(207, 185, 145, 0.1);
            padding: 25px;
            border-radius: 8px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            margin-bottom: 20px;
        }
        .results-card h3 {
            color: var(--purdue-gold);
            margin-top: 0;
        }
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .stat-item {
            background: rgba(0, 0, 0, 0.4);
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }
        .stat-label {
            color: var(--purdue-gold);
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        .stat-value {
            color: var(--text-light);
            font-size: 1.5rem;
            font-weight: bold;
        }
        .message {
            padding: 15px;
            background: rgba(207, 185, 145, 0.2);
            border: 2px solid var(--purdue-gold);
            border-radius: 8px;
            margin-bottom: 20px;
            color: var(--purdue-gold);
        }
        .error-message {
            border-color: var(--error-red);
            background: rgba(255, 0, 0, 0.1);
            color: var(--error-red);
        }
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
        .nav-bar a:hover {
            background: var(--purdue-gold);
            color: var(--purdue-black);
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Supply Chain Manager Portal</h1>
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
        <a href="scm_transactions.php">Transactions</a>
        <a href="scm_transaction_costs.php">Cost Analysis</a>
        <a href="scm_distributors.php">Distributors</a>
    </div>

    <div class="container">
        <h2>Business Relationship Transaction Cost Analysis</h2>
        
        <?php if ($message): ?>
            <div class="message <?= strpos($message, 'Error') !== false ? 'error-message' : '' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Filter Form -->
        <div class="filter-box">
            <h3>Select Companies and Date Range</h3>
            <form method="POST" action="scm_transaction_costs.php" id="costAnalysisForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="source_company">Source Company (Ships From):</label>
                        <select name="source_company" id="source_company" required>
                            <option value="">-- Select Source Company --</option>
                            <?php foreach ($allCompanies as $company): ?>
                                <option value="<?= $company['CompanyID'] ?>" 
                                        <?= (isset($_POST['source_company']) && $_POST['source_company'] == $company['CompanyID']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($company['CompanyName']) ?> (<?= htmlspecialchars($company['Type']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="destination_company">Destination Company (Ships To):</label>
                        <select name="destination_company" id="destination_company" required disabled>
                            <option value="">-- Select Source Company First --</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="start_date">Start Date:</label>
                        <input type="date" 
                               name="start_date" 
                               id="start_date" 
                               value="<?= htmlspecialchars($_POST['start_date'] ?? '') ?>"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">End Date:</label>
                        <input type="date" 
                               name="end_date" 
                               id="end_date" 
                               value="<?= htmlspecialchars($_POST['end_date'] ?? '') ?>"
                               required>
                    </div>
                </div>
                
                <button type="submit" name="analyze_costs">Analyze Transaction Costs</button>
            </form>
        </div>

        <!-- Results Section -->
        <?php if ($results): ?>
            <div class="results-card">
                <h3>Summary: <?= htmlspecialchars($results['SourceCompany']) ?> → <?= htmlspecialchars($results['DestinationCompany']) ?></h3>
                
                <div class="stat-grid">
                    <div class="stat-item">
                        <div class="stat-label">Total Supply Cost</div>
                        <div class="stat-value">$<?= number_format($results['TotalSupplyCost'], 2) ?></div>
                    </div>
                    
                    <div class="stat-item">
                        <div class="stat-label">Total Shipments</div>
                        <div class="stat-value"><?= number_format($results['TotalShipments']) ?></div>
                    </div>
                    
                    <div class="stat-item">
                        <div class="stat-label">Total Quantity</div>
                        <div class="stat-value"><?= number_format($results['TotalQuantityShipped']) ?></div>
                    </div>
                    
                    <div class="stat-item">
                        <div class="stat-label">Avg Cost per Shipment</div>
                        <div class="stat-value">$<?= number_format($results['TotalSupplyCost'] / $results['TotalShipments'], 2) ?></div>
                    </div>
                    
                    <div class="stat-item">
                        <div class="stat-label">First Shipment</div>
                        <div class="stat-value" style="font-size: 1.1rem;"><?= htmlspecialchars($results['FirstShipmentDate']) ?></div>
                    </div>
                    
                    <div class="stat-item">
                        <div class="stat-label">Last Shipment</div>
                        <div class="stat-value" style="font-size: 1.1rem;"><?= htmlspecialchars($results['LastShipmentDate']) ?></div>
                    </div>
                </div>
            </div>

            <!-- Product Breakdown -->
            <?php if (!empty($productBreakdown)): ?>
                <div class="content-section">
                    <h3>Product Breakdown</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Shipments</th>
                                <th>Total Quantity</th>
                                <th>Unit Price</th>
                                <th>Total Cost</th>
                                <th>% of Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productBreakdown as $product): ?>
                                <tr>
                                    <td><?= htmlspecialchars($product['ProductName']) ?></td>
                                    <td><?= htmlspecialchars($product['Category']) ?></td>
                                    <td><?= number_format($product['TotalShipments']) ?></td>
                                    <td><?= number_format($product['TotalQuantityShipped']) ?></td>
                                    <td>$<?= number_format($product['UnitPrice'], 2) ?></td>
                                    <td><strong>$<?= number_format($product['TotalSupplyCost'], 2) ?></strong></td>
                                    <td><?= number_format(($product['TotalSupplyCost'] / $results['TotalSupplyCost']) * 100, 1) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>

    <script>
        // Dynamic dropdown functionality
        document.getElementById('source_company').addEventListener('change', function() {
            const sourceId = this.value;
            const destSelect = document.getElementById('destination_company');
            
            if (!sourceId) {
                destSelect.disabled = true;
                destSelect.innerHTML = '<option value="">-- Select Source Company First --</option>';
                return;
            }
            
            // Show loading state
            destSelect.disabled = true;
            destSelect.innerHTML = '<option value="">Loading...</option>';
            
            // Fetch related companies via AJAX
            fetch(`scm_transaction_costs.php?ajax=get_related_companies&company_id=${sourceId}&direction=source`)
                .then(response => response.json())
                .then(data => {
                    destSelect.innerHTML = '<option value="">-- Select Destination Company --</option>';
                    
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    
                    if (data.length === 0) {
                        destSelect.innerHTML = '<option value="">No related companies found</option>';
                        return;
                    }
                    
                    data.forEach(company => {
                        const option = document.createElement('option');
                        option.value = company.CompanyID;
                        option.textContent = `${company.CompanyName} (${company.Type})`;
                        
                        // Preserve selection if form was submitted
                        <?php if (isset($_POST['destination_company'])): ?>
                            if (company.CompanyID == <?= $_POST['destination_company'] ?>) {
                                option.selected = true;
                            }
                        <?php endif; ?>
                        
                        destSelect.appendChild(option);
                    });
                    
                    destSelect.disabled = false;
                })
                .catch(error => {
                    console.error('Error fetching related companies:', error);
                    destSelect.innerHTML = '<option value="">Error loading companies</option>';
                });
        });
        
        // Trigger change event on page load if source is already selected
        window.addEventListener('DOMContentLoaded', function() {
            const sourceSelect = document.getElementById('source_company');
            if (sourceSelect.value) {
                sourceSelect.dispatchEvent(new Event('change'));
            }
        });
        
        // Set default date range (last 90 days) if empty
        window.addEventListener('DOMContentLoaded', function() {
            const startDate = document.getElementById('start_date');
            const endDate = document.getElementById('end_date');
            
            if (!startDate.value) {
                const today = new Date();
                const threeMonthsAgo = new Date();
                threeMonthsAgo.setMonth(today.getMonth() - 3);
                
                startDate.value = threeMonthsAgo.toISOString().split('T')[0];
                endDate.value = today.toISOString().split('T')[0];
            }
        });
    </script>
</body>
</html>
