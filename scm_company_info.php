<?php
// scm_company_info.php - Company Information Management
require_once 'config.php';
requireLogin();

// Redirect Senior Managers
if (hasRole('SeniorManager')) {
    header('Location: dashboard_erp.php');
    exit;
}

$pdo = getPDO();
$company = null;
$transactions = [];
$dependencies = [];
$products = [];
$message = '';

// Handle AJAX request for company list
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_companies') {
    header('Content-Type: application/json');
    $sql = "SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName";
    $stmt = $pdo->query($sql);
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($companies);
    exit;
}

// Handle company selection
if (isset($_GET['company_id']) && !empty($_GET['company_id'])) {
    $companyId = $_GET['company_id'];
    $sql = "SELECT c.*, l.CountryName, l.ContinentName, l.City, m.FactoryCapacity
            FROM Company c
            JOIN Location l ON c.LocationID = l.LocationID
            LEFT JOIN Manufacturer m ON c.CompanyID = m.CompanyID
            WHERE c.CompanyID = :companyId";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':companyId' => $companyId]);
    $company = $stmt->fetch();
    
    if ($company) {
        // Get dependencies (who they depend on)
        $sql = "SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel
                FROM Company c
                JOIN DependsOn d ON c.CompanyID = d.UpstreamCompanyID
                WHERE d.DownstreamCompanyID = :companyId";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':companyId' => $company['CompanyID']]);
        $upstream = $stmt->fetchAll();
        
        // Get dependents (who depends on them)
        $sql = "SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel
                FROM Company c
                JOIN DependsOn d ON c.CompanyID = d.DownstreamCompanyID
                WHERE d.UpstreamCompanyID = :companyId";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':companyId' => $company['CompanyID']]);
        $downstream = $stmt->fetchAll();
        
        $dependencies = [
            'upstream' => $upstream,
            'downstream' => $downstream
        ];
        
        // Get products supplied
        $sql = "SELECT p.ProductID, p.ProductName, p.Category, sp.SupplyPrice
                FROM Product p
                JOIN SuppliesProduct sp ON p.ProductID = sp.ProductID
                WHERE sp.SupplierID = :companyId";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':companyId' => $company['CompanyID']]);
        $products = $stmt->fetchAll();
        
        // Get recent transactions
        $sql = "SELECT s.ShipmentID, s.PromisedDate, s.ActualDate, s.Quantity,
                       p.ProductName,
                       source.CompanyName as SourceName,
                       dest.CompanyName as DestName
                FROM Shipping s
                JOIN Product p ON s.ProductID = p.ProductID
                JOIN Company source ON s.SourceCompanyID = source.CompanyID
                JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
                WHERE s.SourceCompanyID = :companyId OR s.DestinationCompanyID = :companyId
                ORDER BY s.PromisedDate DESC
                LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':companyId' => $company['CompanyID']]);
        $transactions = $stmt->fetchAll();
        
        // Get financial status
        $sql = "SELECT Quarter, RepYear, HealthScore
                FROM FinancialReport
                WHERE CompanyID = :companyId
                ORDER BY RepYear DESC, Quarter DESC
                LIMIT 4";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':companyId' => $company['CompanyID']]);
        $financials = $stmt->fetchAll();
    }
}

// Handle company update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_company'])) {
    $companyId = $_POST['company_id'];
    $tierLevel = $_POST['tier_level'];
    
    $sql = "UPDATE Company SET TierLevel = :tierLevel WHERE CompanyID = :companyId";
    $stmt = $pdo->prepare($sql);
    if ($stmt->execute([':tierLevel' => $tierLevel, ':companyId' => $companyId])) {
        $message = "Company updated successfully!";
        // Reload company data
        header("Location: scm_company_info.php?company_id=" . $companyId);
        exit;
    } else {
        $message = "Error updating company.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Information - SCM</title>
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .info-card {
            background: rgba(207, 185, 145, 0.1);
            padding: 20px;
            border-radius: 8px;
            border: 2px solid rgba(207, 185, 145, 0.3);
        }
        .info-card h3 {
            margin-top: 0;
            font-size: 1.2rem;
            color: var(--purdue-gold);
        }
        .info-card p {
            margin: 8px 0;
            color: var(--text-light);
        }
        .dependency-list {
            list-style: none;
            padding: 0;
        }
        .dependency-list li {
            background: rgba(0, 0, 0, 0.4);
            padding: 12px;
            margin: 8px 0;
            border-radius: 5px;
            border-left: 4px solid var(--purdue-gold);
        }
        .search-box {
            background: rgba(0, 0, 0, 0.6);
            padding: 30px;
            border-radius: 12px;
            border: 2px solid rgba(207, 185, 145, 0.3);
            margin-bottom: 30px;
        }
        .message {
            padding: 15px;
            background: rgba(207, 185, 145, 0.2);
            border: 2px solid var(--purdue-gold);
            border-radius: 8px;
            margin-bottom: 20px;
            color: var(--purdue-gold);
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
        .delay-warning {
            color: var(--error-red);
            font-weight: bold;
        }
        .on-time {
            color: #4caf50;
            font-weight: bold;
        }
        #company_select {
            width: 100%;
            padding: 10px;
            font-size: 1rem;
            border-radius: 5px;
            border: 2px solid rgba(207, 185, 145, 0.5);
            background: rgba(0, 0, 0, 0.7);
            color: white;
        }
        .loading {
            color: var(--purdue-gold);
            font-style: italic;
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
        <a href="scm_company_info.php" style="background: var(--purdue-gold); color: var(--purdue-black);">Companies</a>
        <a href="scm_kpis.php">KPIs</a>
        <a href="scm_disruptions.php">Disruptions</a>
        <a href="scm_transactions.php">Transactions</a>
        <a href="scm_transaction_costs.php">Cost Analysis</a>
        <a href="scm_distributors.php">Distributors</a>
    </div>

    <div class="container">
        <h2>Company Information</h2>
        
        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Search Form with Dropdown -->
        <div class="search-box">
            <label for="company_select">Select Company:</label>
            <select id="company_select" name="company_select">
                <option value="">-- Select a company --</option>
            </select>
            <p class="loading" id="loading_text">Loading companies...</p>
        </div>

        <?php if ($company): ?>
            <!-- Company Details -->
            <div class="content-section">
                <h3><?= htmlspecialchars($company['CompanyName']) ?></h3>
                
                <div class="info-grid">
                    <div class="info-card">
                        <h3>📍 Location</h3>
                        <p><strong>City:</strong> <?= htmlspecialchars($company['City']) ?></p>
                        <p><strong>Country:</strong> <?= htmlspecialchars($company['CountryName']) ?></p>
                        <p><strong>Continent:</strong> <?= htmlspecialchars($company['ContinentName']) ?></p>
                    </div>
                    
                    <div class="info-card">
                        <h3>🏢 Company Details</h3>
                        <p><strong>Type:</strong> <?= htmlspecialchars($company['Type']) ?></p>
                        <p><strong>Tier Level:</strong> <?= htmlspecialchars($company['TierLevel']) ?></p>
                        <?php if ($company['FactoryCapacity']): ?>
                            <p><strong>Factory Capacity:</strong> <?= number_format($company['FactoryCapacity']) ?> units</p>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($products)): ?>
                        <div class="info-card">
                            <h3>📦 Products</h3>
                            <p><strong>Product Diversity:</strong> <?= count($products) ?> products</p>
                            <?php foreach (array_slice($products, 0, 3) as $prod): ?>
                                <p>• <?= htmlspecialchars($prod['ProductName']) ?> 
                                <?php if ($prod['SupplyPrice']): ?>
                                    ($<?= number_format($prod['SupplyPrice'], 2) ?>)
                                <?php endif; ?>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($financials) && !empty($financials)): ?>
                        <div class="info-card">
                            <h3>💰 Recent Financial Health</h3>
                            <?php foreach ($financials as $fin): ?>
                                <p><?= htmlspecialchars($fin['Quarter']) ?> <?= htmlspecialchars($fin['RepYear']) ?>: 
                                    <strong><?= number_format($fin['HealthScore'], 2) ?></strong></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Update Form -->
                <div class="content-section" style="margin-top: 30px;">
                    <h3>Update Company Information</h3>
                    <form method="POST" action="scm_company_info.php" id="update_form">
                        <input type="hidden" name="company_id" value="<?= $company['CompanyID'] ?>">
                        
                        <div class="form-row">
                            <label for="tier_level">Tier Level:</label>
                            <select name="tier_level" id="tier_level">
                                <option value="1" <?= $company['TierLevel'] == '1' ? 'selected' : '' ?>>1</option>
                                <option value="2" <?= $company['TierLevel'] == '2' ? 'selected' : '' ?>>2</option>
                                <option value="3" <?= $company['TierLevel'] == '3' ? 'selected' : '' ?>>3</option>
                            </select>
                        </div>
                        
                        <button type="submit" name="update_company">Update Company</button>
                    </form>
                </div>

                <!-- Dependencies -->
                <div class="content-section" style="margin-top: 30px;">
                    <h3>Dependencies</h3>
                    
                    <div class="info-grid">
                        <div>
                            <h4>⬆️ Depends On (Upstream):</h4>
                            <?php if (!empty($dependencies['upstream'])): ?>
                                <ul class="dependency-list">
                                    <?php foreach ($dependencies['upstream'] as $dep): ?>
                                        <li>
                                            <strong><?= htmlspecialchars($dep['CompanyName']) ?></strong><br>
                                            Type: <?= htmlspecialchars($dep['Type']) ?> | Tier: <?= htmlspecialchars($dep['TierLevel']) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p>No upstream dependencies found.</p>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <h4>⬇️ Depended On By (Downstream):</h4>
                            <?php if (!empty($dependencies['downstream'])): ?>
                                <ul class="dependency-list">
                                    <?php foreach ($dependencies['downstream'] as $dep): ?>
                                        <li>
                                            <strong><?= htmlspecialchars($dep['CompanyName']) ?></strong><br>
                                            Type: <?= htmlspecialchars($dep['Type']) ?> | Tier: <?= htmlspecialchars($dep['TierLevel']) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p>No downstream dependencies found.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <?php if (!empty($transactions)): ?>
                    <div class="content-section" style="margin-top: 30px;">
                        <h3>Recent Transactions (Last 10)</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Shipment ID</th>
                                    <th>Product</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Promised Date</th>
                                    <th>Actual Date</th>
                                    <th>Quantity</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $trans): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($trans['ShipmentID']) ?></td>
                                        <td><?= htmlspecialchars($trans['ProductName']) ?></td>
                                        <td><?= htmlspecialchars($trans['SourceName']) ?></td>
                                        <td><?= htmlspecialchars($trans['DestName']) ?></td>
                                        <td><?= htmlspecialchars($trans['PromisedDate']) ?></td>
                                        <td><?= $trans['ActualDate'] ? htmlspecialchars($trans['ActualDate']) : 'Pending' ?></td>
                                        <td><?= number_format($trans['Quantity']) ?></td>
                                        <td>
                                            <?php
                                            if (!$trans['ActualDate']) {
                                                echo '<span style="color: orange;">In Transit</span>';
                                            } elseif ($trans['ActualDate'] <= $trans['PromisedDate']) {
                                                echo '<span class="on-time">✓ On Time</span>';
                                            } else {
                                                echo '<span class="delay-warning">⚠ Delayed</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            </div>
        <?php elseif (isset($_GET['company_id'])): ?>
            <div class="content-section">
                <p style="color: var(--error-red); font-size: 1.2rem;">❌ No company found with that ID. Please try again.</p>
            </div>
        <?php endif; ?>

    </div>

    <script>
        // Load companies on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadCompanies();
        });

        function loadCompanies() {
            const select = document.getElementById('company_select');
            const loadingText = document.getElementById('loading_text');
            
            fetch('scm_company_info.php?ajax=get_companies')
                .then(response => response.json())
                .then(data => {
                    // Clear loading options
                    select.innerHTML = '<option value="">-- Select a company --</option>';
                    
                    // Add companies to dropdown
                    data.forEach(company => {
                        const option = document.createElement('option');
                        option.value = company.CompanyID;
                        option.textContent = company.CompanyName;
                        
                        // Pre-select if this company is currently displayed
                        const urlParams = new URLSearchParams(window.location.search);
                        if (urlParams.get('company_id') == company.CompanyID) {
                            option.selected = true;
                        }
                        
                        select.appendChild(option);
                    });
                    
                    loadingText.style.display = 'none';
                })
                .catch(error => {
                    console.error('Error loading companies:', error);
                    loadingText.textContent = 'Error loading companies. Please refresh the page.';
                    loadingText.style.color = 'var(--error-red)';
                });
        }

        // Handle company selection
        document.getElementById('company_select').addEventListener('change', function() {
            if (this.value) {
                window.location.href = 'scm_company_info.php?company_id=' + this.value;
            }
        });

        // Refresh dropdown after form submission
        document.getElementById('update_form')?.addEventListener('submit', function() {
            // The form will redirect, but we set a flag to reload companies on return
            sessionStorage.setItem('reloadCompanies', 'true');
        });

        // Check if we need to reload companies after update
        if (sessionStorage.getItem('reloadCompanies') === 'true') {
            sessionStorage.removeItem('reloadCompanies');
            loadCompanies();
        }
    </script>
</body>
</html>
