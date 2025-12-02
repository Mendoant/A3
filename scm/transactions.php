<?php
// scm/transactions.php - All Transactions View
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config.php';
requireLogin();

if (hasRole('SeniorManager')) {
    header('Location: ../erp/dashboard.php');
    exit;
}

try {
    $pdo = getPDO();

    // Get filters - default to last 3 months
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-3 months'));
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

    // Shipping Transactions
    $shippingSql = "SELECT s.ShipmentID, s.PromisedDate, s.ActualDate, s.Quantity,
                           p.ProductName, p.Category,
                           src.CompanyName as SourceCompany,
                           dest.CompanyName as DestCompany,
                           dist.CompanyName as DistributorName
                    FROM Shipping s
                    JOIN Product p ON s.ProductID = p.ProductID
                    JOIN Company src ON s.SourceCompanyID = src.CompanyID
                    JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
                    JOIN Distributor d ON s.DistributorID = d.CompanyID
                    JOIN Company dist ON d.CompanyID = dist.CompanyID
                    WHERE s.PromisedDate BETWEEN :start AND :end
                    ORDER BY s.PromisedDate DESC";

    $stmtShip = $pdo->prepare($shippingSql);
    $stmtShip->execute(array(':start' => $startDate, ':end' => $endDate));
    $shippingData = $stmtShip->fetchAll();
    
    // Calculate status for each shipment
    $today = date('Y-m-d');
    foreach ($shippingData as &$ship) {
        if ($ship['ActualDate']) {
            $ship['Status'] = 'Delivered';
        } elseif ($ship['PromisedDate'] < $today) {
            $ship['Status'] = 'Delayed';
        } else {
            $ship['Status'] = 'In Transit';
        }
    }
    unset($ship);

    // Receiving Transactions
    $receivingSql = "SELECT r.ReceivingID, r.ReceivedDate, r.QuantityReceived,
                            p.ProductName, p.Category,
                            src.CompanyName as SourceCompany,
                            recv.CompanyName as ReceiverCompany
                     FROM Receiving r
                     JOIN Shipping s ON r.ShipmentID = s.ShipmentID
                     JOIN Product p ON s.ProductID = p.ProductID
                     JOIN Company src ON s.SourceCompanyID = src.CompanyID
                     JOIN Company recv ON r.ReceiverCompanyID = recv.CompanyID
                     WHERE r.ReceivedDate BETWEEN :start AND :end
                     ORDER BY r.ReceivedDate DESC";

    $stmtRecv = $pdo->prepare($receivingSql);
    $stmtRecv->execute(array(':start' => $startDate, ':end' => $endDate));
    $receivingData = $stmtRecv->fetchAll();

    // Inventory Adjustments
    $adjustmentsSql = "SELECT ia.AdjustmentID, ia.AdjustmentDate, ia.QuantityChange, ia.Reason,
                              p.ProductName, p.Category,
                              c.CompanyName
                       FROM InventoryAdjustment ia
                       JOIN Product p ON ia.ProductID = p.ProductID
                       JOIN Company c ON ia.CompanyID = c.CompanyID
                       ORDER BY ia.AdjustmentDate DESC
                       LIMIT 500";

    $stmtAdj = $pdo->prepare($adjustmentsSql);
    $stmtAdj->execute();
    $adjustmentsData = $stmtAdj->fetchAll();
    
    // Add Type for display
    foreach ($adjustmentsData as &$adj) {
        if ($adj['QuantityChange'] > 0) {
            $adj['Type'] = 'Restock';
        } else {
            $adj['Type'] = 'Adjustment';
        }
    }
    unset($adj);

    // Calculate summary metrics
    $totalShipping = count($shippingData);
    $totalReceiving = count($receivingData);
    $totalAdjustments = count($adjustmentsData);
    $totalTransactions = $totalShipping + $totalReceiving + $totalAdjustments;

    // Delayed shipments
    $delayed = 0;
    foreach ($shippingData as $ship) {
        if ($ship['Status'] === 'Delayed') {
            $delayed++;
        }
    }
    $delayRate = $totalShipping > 0 ? round(($delayed / $totalShipping) * 100, 1) : 0;

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transactions - SCM</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <style>
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0; }
        .metric-card { background: rgba(0,0,0,0.6); padding: 20px; border-radius: 8px; border: 2px solid rgba(207,185,145,0.3); text-align: center; transition: all 0.3s; }
        .metric-card:hover { border-color: var(--purdue-gold); transform: translateY(-2px); }
        .metric-card h3 { margin: 0; font-size: 2rem; color: var(--purdue-gold); }
        .metric-card p { margin: 8px 0 0 0; color: var(--text-light); font-size: 0.9rem; }
        
        .tab-navigation {
            display: flex;
            gap: 10px;
            margin: 30px 0 20px 0;
            border-bottom: 2px solid rgba(207,185,145,0.3);
        }
        .tab-btn {
            padding: 12px 24px;
            background: rgba(0,0,0,0.4);
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--text-light);
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s;
        }
        .tab-btn:hover {
            background: rgba(207,185,145,0.2);
            color: white;
        }
        .tab-btn.active {
            background: rgba(207,185,145,0.2);
            border-bottom-color: var(--purdue-gold);
            color: var(--purdue-gold);
            font-weight: bold;
        }
        
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        
        .table-scroll-wrapper {
            max-height: 600px;
            overflow-y: auto;
            overflow-x: auto;
            border: 2px solid rgba(207,185,145,0.3);
            border-radius: 8px;
            background: rgba(0,0,0,0.3);
            margin-top: 20px;
        }
        .table-scroll-wrapper::-webkit-scrollbar { width: 12px; height: 12px; }
        .table-scroll-wrapper::-webkit-scrollbar-track { background: rgba(0,0,0,0.5); border-radius: 6px; }
        .table-scroll-wrapper::-webkit-scrollbar-thumb { background: #CFB991; border-radius: 6px; }
        .table-scroll-wrapper::-webkit-scrollbar-thumb:hover { background: #b89968; }
        
        table { width: 100%; border-collapse: collapse; }
        thead { position: sticky; top: 0; background: rgba(0,0,0,0.9); z-index: 10; }
        th { padding: 12px; text-align: left; color: var(--purdue-gold); font-weight: bold; border-bottom: 2px solid var(--purdue-gold); white-space: nowrap; }
        td { padding: 10px 12px; border-bottom: 1px solid rgba(207,185,145,0.1); color: var(--text-light); }
        tbody tr:hover { background: rgba(207,185,145,0.1); }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: bold;
            display: inline-block;
        }
        .status-delivered { background: #4caf50; color: white; }
        .status-delayed { background: #f44336; color: white; }
        .status-in { background: #2196f3; color: white; }
        .status-intransit { background: #2196f3; color: white; }
        
        .type-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: bold;
            display: inline-block;
        }
        .type-restock { background: #4caf50; color: white; }
        .type-adjustment { background: #9c27b0; color: white; }
        
        .delayed-count { color: #f44336; font-weight: bold; }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Supply Chain Management Portal</h1>
            <nav>
                <span style="color: white;">Welcome, <?php echo htmlspecialchars($_SESSION['FullName']) ?></span>
                <a href="../logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <nav class="container" style="background: rgba(0,0,0,0.8); padding: 15px 30px; margin-bottom: 30px; border-radius: 8px; display: flex; gap: 20px; flex-wrap: wrap;">
        <a href="dashboard.php">Dashboard</a>
        <a href="companies.php">Companies</a>
        <a href="kpis.php">KPIs</a>
        <a href="disruptions.php">Disruptions</a>
        <a href="transactions.php" class="active">Transactions</a>
        <a href="transaction_costs.php">Cost Analysis</a>
        <a href="distributors.php">Distributors</a>
    </nav>

    <div class="container">
        <h2>All Transactions</h2>

        <!-- Date Filter -->
        <div class="content-section">
            <h3>Filter Transactions</h3>
            <form method="GET" id="filterForm">
                <div style="display: flex; gap: 15px; align-items: end; flex-wrap: wrap;">
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Start Date:</label>
                        <input type="date" name="start_date" id="start_date" value="<?php echo $startDate ?>" required style="padding: 8px; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">End Date:</label>
                        <input type="date" name="end_date" id="end_date" value="<?php echo $endDate ?>" required style="padding: 8px; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white;">
                    </div>
                </div>
                <div style="margin-top: 15px; display: flex; gap: 10px;">
                    <button type="submit" class="btn-primary">Apply Filter</button>
                    <button type="button" id="clearBtn" class="btn-secondary">Clear</button>
                </div>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="metrics-grid">
            <div class="metric-card">
                <h3><?php echo number_format($totalTransactions) ?></h3>
                <p>Total Transactions</p>
            </div>
            <div class="metric-card">
                <h3><?php echo number_format($totalShipping) ?></h3>
                <p>Shipping Transactions</p>
            </div>
            <div class="metric-card">
                <h3><?php echo number_format($totalReceiving) ?></h3>
                <p>Receiving Transactions</p>
            </div>
            <div class="metric-card">
                <h3><?php echo number_format($totalAdjustments) ?></h3>
                <p>Inventory Adjustments</p>
            </div>
            <div class="metric-card">
                <h3 class="delayed-count"><?php echo $delayed ?></h3>
                <p>Delayed Shipments (<?php echo $delayRate ?>%)</p>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <button class="tab-btn active" onclick="switchTab('shipping')">
                Shipping (<?php echo number_format($totalShipping) ?>)
            </button>
            <button class="tab-btn" onclick="switchTab('receiving')">
                Receiving (<?php echo number_format($totalReceiving) ?>)
            </button>
            <button class="tab-btn" onclick="switchTab('adjustments')">
                Adjustments (<?php echo number_format($totalAdjustments) ?>)
            </button>
        </div>

        <!-- Shipping Tab -->
        <div id="tab-shipping" class="tab-content active">
            <div class="content-section">
                <h3>Shipping Transactions</h3>
                <div class="table-scroll-wrapper">
                    <?php if (count($shippingData) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Shipment ID</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Source</th>
                                <th>Destination</th>
                                <th>Distributor</th>
                                <th>Quantity</th>
                                <th>Promised Date</th>
                                <th>Actual Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shippingData as $row): ?>
                            <tr>
                                <td><?php echo $row['ShipmentID'] ?></td>
                                <td><?php echo htmlspecialchars($row['ProductName']) ?></td>
                                <td><?php echo htmlspecialchars($row['Category']) ?></td>
                                <td><?php echo htmlspecialchars($row['SourceCompany']) ?></td>
                                <td><?php echo htmlspecialchars($row['DestCompany']) ?></td>
                                <td><?php echo $row['DistributorName'] ? htmlspecialchars($row['DistributorName']) : 'Direct' ?></td>
                                <td><?php echo number_format($row['Quantity']) ?></td>
                                <td><?php echo $row['PromisedDate'] ?></td>
                                <td><?php echo $row['ActualDate'] ? $row['ActualDate'] : '-' ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $row['Status'])) ?>">
                                        <?php echo htmlspecialchars($row['Status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p style="text-align: center; padding: 40px; color: rgba(255,255,255,0.5);">
                        No shipping transactions found in selected date range.
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Receiving Tab -->
        <div id="tab-receiving" class="tab-content">
            <div class="content-section">
                <h3>Receiving Transactions</h3>
                <div class="table-scroll-wrapper">
                    <?php if (count($receivingData) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Receiving ID</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Source Company</th>
                                <th>Receiver Company</th>
                                <th>Quantity Received</th>
                                <th>Received Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($receivingData as $row): ?>
                            <tr>
                                <td><?php echo $row['ReceivingID'] ?></td>
                                <td><?php echo htmlspecialchars($row['ProductName']) ?></td>
                                <td><?php echo htmlspecialchars($row['Category']) ?></td>
                                <td><?php echo htmlspecialchars($row['SourceCompany']) ?></td>
                                <td><?php echo htmlspecialchars($row['ReceiverCompany']) ?></td>
                                <td><?php echo number_format($row['QuantityReceived']) ?></td>
                                <td><?php echo $row['ReceivedDate'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p style="text-align: center; padding: 40px; color: rgba(255,255,255,0.5);">
                        No receiving transactions found in selected date range.
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Adjustments Tab -->
        <div id="tab-adjustments" class="tab-content">
            <div class="content-section">
                <h3>Inventory Adjustments</h3>
                <p style="color: rgba(255,255,255,0.6); margin-bottom: 15px;">
                    Showing most recent 500 adjustments (sorted by date)
                </p>
                <div class="table-scroll-wrapper">
                    <?php if (count($adjustmentsData) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Adjustment ID</th>
                                <th>Date</th>
                                <th>Company</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Type</th>
                                <th>Quantity Change</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adjustmentsData as $row): ?>
                            <tr>
                                <td><?php echo $row['AdjustmentID'] ?></td>
                                <td><?php echo $row['AdjustmentDate'] ?></td>
                                <td><?php echo htmlspecialchars($row['CompanyName']) ?></td>
                                <td><?php echo htmlspecialchars($row['ProductName']) ?></td>
                                <td><?php echo htmlspecialchars($row['Category']) ?></td>
                                <td>
                                    <span class="type-badge type-<?php echo strtolower($row['Type']) ?>">
                                        <?php echo htmlspecialchars($row['Type']) ?>
                                    </span>
                                </td>
                                <td style="color: <?php echo $row['QuantityChange'] > 0 ? '#4caf50' : '#f44336' ?>">
                                    <?php echo $row['QuantityChange'] > 0 ? '+' : '' ?><?php echo number_format($row['QuantityChange']) ?>
                                </td>
                                <td><?php echo $row['Reason'] ? htmlspecialchars($row['Reason']) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p style="text-align: center; padding: 40px; color: rgba(255,255,255,0.5);">
                        No inventory adjustments found.
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    function switchTab(tabName) {
        // Hide all tabs
        var tabs = document.querySelectorAll('.tab-content');
        tabs.forEach(function(tab) {
            tab.classList.remove('active');
        });
        
        // Remove active from all buttons
        var buttons = document.querySelectorAll('.tab-btn');
        buttons.forEach(function(btn) {
            btn.classList.remove('active');
        });
        
        // Show selected tab
        document.getElementById('tab-' + tabName).classList.add('active');
        
        // Activate clicked button
        event.target.classList.add('active');
    }
    
    // Clear button functionality
    document.getElementById('clearBtn').addEventListener('click', function() {
        // Reset to default date range (last 3 months)
        var today = new Date();
        var threeMonthsAgo = new Date();
        threeMonthsAgo.setMonth(today.getMonth() - 3);
        
        document.getElementById('start_date').value = threeMonthsAgo.toISOString().split('T')[0];
        document.getElementById('end_date').value = today.toISOString().split('T')[0];
        
        // Submit form
        document.getElementById('filterForm').submit();
    });
    </script>
</body>
</html>