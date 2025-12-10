<?php
// scm/transactions.php - All Transactions View with AJAX and Edit Functionality
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

    // Handle transaction updates
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'update_shipping') {
            $shipmentID = $_POST['shipment_id'];
            $productID = $_POST['product_id'];
            $sourceCompanyID = $_POST['source_company_id'];
            $destinationCompanyID = $_POST['destination_company_id'];
            $distributorID = !empty($_POST['distributor_id']) ? $_POST['distributor_id'] : null;
            $quantity = $_POST['quantity'];
            $promisedDate = $_POST['promised_date'];
            $actualDate = !empty($_POST['actual_date']) ? $_POST['actual_date'] : null;
            
            $sql = "UPDATE Shipping SET 
                    ProductID = :product,
                    SourceCompanyID = :source,
                    DestinationCompanyID = :destination,
                    DistributorID = :distributor,
                    Quantity = :quantity, 
                    PromisedDate = :promised, 
                    ActualDate = :actual 
                    WHERE ShipmentID = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array(
                ':product' => $productID,
                ':source' => $sourceCompanyID,
                ':destination' => $destinationCompanyID,
                ':distributor' => $distributorID,
                ':quantity' => $quantity,
                ':promised' => $promisedDate,
                ':actual' => $actualDate,
                ':id' => $shipmentID
            ));
            
            header('Location: transactions.php?updated=shipping');
            exit;
        }
        
        if ($_POST['action'] === 'update_receiving') {
            $receivingID = $_POST['receiving_id'];
            $shipmentID = $_POST['shipment_id'];
            $receiverCompanyID = $_POST['receiver_company_id'];
            $quantityReceived = $_POST['quantity_received'];
            $receivedDate = $_POST['received_date'];
            
            // Update Receiving table
            $sql = "UPDATE Receiving SET 
                    ShipmentID = :shipment,
                    ReceiverCompanyID = :receiver,
                    QuantityReceived = :quantity, 
                    ReceivedDate = :date 
                    WHERE ReceivingID = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array(
                ':shipment' => $shipmentID,
                ':receiver' => $receiverCompanyID,
                ':quantity' => $quantityReceived,
                ':date' => $receivedDate,
                ':id' => $receivingID
            ));
            
            header('Location: transactions.php?updated=receiving');
            exit;
        }
        
        if ($_POST['action'] === 'update_adjustment') {
            $adjustmentID = $_POST['adjustment_id'];
            $companyID = $_POST['company_id'];
            $productID = $_POST['product_id'];
            $quantityChange = $_POST['quantity_change'];
            $adjustmentDate = $_POST['adjustment_date'];
            $reason = $_POST['reason'];
            
            $sql = "UPDATE InventoryAdjustment SET 
                    CompanyID = :company,
                    ProductID = :product,
                    QuantityChange = :quantity, 
                    AdjustmentDate = :date, 
                    Reason = :reason 
                    WHERE AdjustmentID = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array(
                ':company' => $companyID,
                ':product' => $productID,
                ':quantity' => $quantityChange,
                ':date' => $adjustmentDate,
                ':reason' => $reason,
                ':id' => $adjustmentID
            ));
            
            header('Location: transactions.php?updated=adjustment');
            exit;
        }
    }

    // Get filters - default to last 3 months
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-3 months'));
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
    $companyID = isset($_GET['company_id']) ? $_GET['company_id'] : '';
    $region = isset($_GET['region']) ? $_GET['region'] : '';
    $tierLevel = isset($_GET['tier']) ? $_GET['tier'] : '';
    
    // Pagination parameters
    $shippingPage = isset($_GET['shipping_page']) ? max(1, intval($_GET['shipping_page'])) : 1;
    $receivingPage = isset($_GET['receiving_page']) ? max(1, intval($_GET['receiving_page'])) : 1;
    $adjustmentsPage = isset($_GET['adjustments_page']) ? max(1, intval($_GET['adjustments_page'])) : 1;
    $pageSize = 500;

    // Build WHERE conditions and JOINs for filters
    $whereParams = array(':start' => $startDate, ':end' => $endDate);
    $additionalJoins = '';
    $additionalWhere = '';
    
    if (!empty($companyID)) {
        $whereParams[':companyID'] = $companyID;
    }
    
    if (!empty($region)) {
        $whereParams[':region'] = $region;
    }
    
    if (!empty($tierLevel)) {
        $whereParams[':tier'] = $tierLevel;
    }

    // Shipping Transactions - Get total count first
    $shippingCountSql = "SELECT COUNT(*) as total
                         FROM Shipping s
                         JOIN Company src ON s.SourceCompanyID = src.CompanyID
                         JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID";
    
    if (!empty($region) || !empty($tierLevel)) {
        $shippingCountSql .= " LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
                               LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID";
    }
    
    $shippingCountSql .= " WHERE s.PromisedDate BETWEEN :start AND :end";
    
    if (!empty($companyID)) {
        $shippingCountSql .= " AND (s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)";
    }
    if (!empty($region)) {
        $shippingCountSql .= " AND (srcLoc.ContinentName = :region OR destLoc.ContinentName = :region)";
    }
    if (!empty($tierLevel)) {
        $shippingCountSql .= " AND (src.TierLevel = :tier OR dest.TierLevel = :tier)";
    }
    
    $stmtCount = $pdo->prepare($shippingCountSql);
    $stmtCount->execute($whereParams);
    $shippingTotal = $stmtCount->fetch()['total'];
    $shippingTotalPages = ceil($shippingTotal / $pageSize);
    
    // Shipping Transactions - Get paginated data
    $shippingOffset = ($shippingPage - 1) * $pageSize;
    $shippingSql = "SELECT s.ShipmentID, s.PromisedDate, s.ActualDate, s.Quantity,
                           s.ProductID, s.SourceCompanyID, s.DestinationCompanyID, s.DistributorID,
                           p.ProductName, p.Category,
                           src.CompanyName as SourceCompany,
                           dest.CompanyName as DestCompany,
                           dist.CompanyName as DistributorName
                    FROM Shipping s
                    JOIN Product p ON s.ProductID = p.ProductID
                    JOIN Company src ON s.SourceCompanyID = src.CompanyID
                    JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
                    LEFT JOIN Distributor d ON s.DistributorID = d.CompanyID
                    LEFT JOIN Company dist ON d.CompanyID = dist.CompanyID";
    
    // Add JOINs for region and tier filtering
    if (!empty($region) || !empty($tierLevel)) {
        $shippingSql .= " LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
                          LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID";
    }
    
    $shippingSql .= " WHERE s.PromisedDate BETWEEN :start AND :end";
    
    // Add company filter
    if (!empty($companyID)) {
        $shippingSql .= " AND (s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)";
    }
    
    // Add region filter
    if (!empty($region)) {
        $shippingSql .= " AND (srcLoc.ContinentName = :region OR destLoc.ContinentName = :region)";
    }
    
    // Add tier filter
    if (!empty($tierLevel)) {
        $shippingSql .= " AND (src.TierLevel = :tier OR dest.TierLevel = :tier)";
    }
    
    $shippingSql .= " ORDER BY s.PromisedDate DESC LIMIT :limit OFFSET :offset";

    $stmtShip = $pdo->prepare($shippingSql);
    // Bind pagination params separately as integers
    foreach ($whereParams as $key => $value) {
        $stmtShip->bindValue($key, $value);
    }
    $stmtShip->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmtShip->bindValue(':offset', $shippingOffset, PDO::PARAM_INT);
    $stmtShip->execute();
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

    // Receiving Transactions - Get total count first
    $receivingCountSql = "SELECT COUNT(*) as total
                          FROM Receiving r
                          JOIN Shipping s ON r.ShipmentID = s.ShipmentID
                          JOIN Company src ON s.SourceCompanyID = src.CompanyID
                          JOIN Company recv ON r.ReceiverCompanyID = recv.CompanyID";
    
    if (!empty($region) || !empty($tierLevel)) {
        $receivingCountSql .= " LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
                                LEFT JOIN Location recvLoc ON recv.LocationID = recvLoc.LocationID";
    }
    
    $receivingCountSql .= " WHERE r.ReceivedDate BETWEEN :start AND :end";
    
    if (!empty($companyID)) {
        $receivingCountSql .= " AND (s.SourceCompanyID = :companyID OR r.ReceiverCompanyID = :companyID)";
    }
    if (!empty($region)) {
        $receivingCountSql .= " AND (srcLoc.ContinentName = :region OR recvLoc.ContinentName = :region)";
    }
    if (!empty($tierLevel)) {
        $receivingCountSql .= " AND (src.TierLevel = :tier OR recv.TierLevel = :tier)";
    }
    
    $stmtCount = $pdo->prepare($receivingCountSql);
    $stmtCount->execute($whereParams);
    $receivingTotal = $stmtCount->fetch()['total'];
    $receivingTotalPages = ceil($receivingTotal / $pageSize);
    
    // Receiving Transactions - Get paginated data
    $receivingOffset = ($receivingPage - 1) * $pageSize;
    $receivingSql = "SELECT r.ReceivingID, r.ReceivedDate, r.QuantityReceived,
                            r.ShipmentID, r.ReceiverCompanyID,
                            s.ProductID,
                            p.ProductName, p.Category,
                            s.SourceCompanyID,
                            src.CompanyName as SourceCompany,
                            recv.CompanyName as ReceiverCompany
                     FROM Receiving r
                     JOIN Shipping s ON r.ShipmentID = s.ShipmentID
                     JOIN Product p ON s.ProductID = p.ProductID
                     JOIN Company src ON s.SourceCompanyID = src.CompanyID
                     JOIN Company recv ON r.ReceiverCompanyID = recv.CompanyID";
    
    // Add JOINs for region and tier filtering
    if (!empty($region) || !empty($tierLevel)) {
        $receivingSql .= " LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
                           LEFT JOIN Location recvLoc ON recv.LocationID = recvLoc.LocationID";
    }
    
    $receivingSql .= " WHERE r.ReceivedDate BETWEEN :start AND :end";
    
    // Add company filter
    if (!empty($companyID)) {
        $receivingSql .= " AND (s.SourceCompanyID = :companyID OR r.ReceiverCompanyID = :companyID)";
    }
    
    // Add region filter
    if (!empty($region)) {
        $receivingSql .= " AND (srcLoc.ContinentName = :region OR recvLoc.ContinentName = :region)";
    }
    
    // Add tier filter
    if (!empty($tierLevel)) {
        $receivingSql .= " AND (src.TierLevel = :tier OR recv.TierLevel = :tier)";
    }
    
    $receivingSql .= " ORDER BY r.ReceivedDate DESC LIMIT :limit OFFSET :offset";

    $stmtRecv = $pdo->prepare($receivingSql);
    foreach ($whereParams as $key => $value) {
        $stmtRecv->bindValue($key, $value);
    }
    $stmtRecv->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmtRecv->bindValue(':offset', $receivingOffset, PDO::PARAM_INT);
    $stmtRecv->execute();
    $receivingData = $stmtRecv->fetchAll();

    // Inventory Adjustments - Get total count first
    $adjustmentsCountSql = "SELECT COUNT(*) as total
                            FROM InventoryAdjustment ia
                            JOIN Company c ON ia.CompanyID = c.CompanyID";
    
    if (!empty($region) || !empty($tierLevel)) {
        $adjustmentsCountSql .= " LEFT JOIN Location loc ON c.LocationID = loc.LocationID";
    }
    
    $adjustmentsCountSql .= " WHERE ia.AdjustmentDate BETWEEN :start AND :end";
    
    if (!empty($companyID)) {
        $adjustmentsCountSql .= " AND ia.CompanyID = :companyID";
    }
    if (!empty($region)) {
        $adjustmentsCountSql .= " AND loc.ContinentName = :region";
    }
    if (!empty($tierLevel)) {
        $adjustmentsCountSql .= " AND c.TierLevel = :tier";
    }
    
    $stmtCount = $pdo->prepare($adjustmentsCountSql);
    $stmtCount->execute($whereParams);
    $adjustmentsTotal = $stmtCount->fetch()['total'];
    $adjustmentsTotalPages = ceil($adjustmentsTotal / $pageSize);
    
    // Inventory Adjustments - Get paginated data
    $adjustmentsOffset = ($adjustmentsPage - 1) * $pageSize;
    $adjustmentsSql = "SELECT ia.AdjustmentID, ia.AdjustmentDate, ia.QuantityChange, ia.Reason,
                              ia.CompanyID, ia.ProductID,
                              p.ProductName, p.Category,
                              c.CompanyName
                       FROM InventoryAdjustment ia
                       JOIN Product p ON ia.ProductID = p.ProductID
                       JOIN Company c ON ia.CompanyID = c.CompanyID";
    
    // Add JOINs for region filtering
    if (!empty($region) || !empty($tierLevel)) {
        $adjustmentsSql .= " LEFT JOIN Location loc ON c.LocationID = loc.LocationID";
    }
    
    $adjustmentsSql .= " WHERE ia.AdjustmentDate BETWEEN :start AND :end";
    
    // Add company filter
    if (!empty($companyID)) {
        $adjustmentsSql .= " AND ia.CompanyID = :companyID";
    }
    
    // Add region filter
    if (!empty($region)) {
        $adjustmentsSql .= " AND loc.ContinentName = :region";
    }
    
    // Add tier filter
    if (!empty($tierLevel)) {
        $adjustmentsSql .= " AND c.TierLevel = :tier";
    }
    
    $adjustmentsSql .= " ORDER BY ia.AdjustmentDate DESC LIMIT :limit OFFSET :offset";

    $stmtAdj = $pdo->prepare($adjustmentsSql);
    foreach ($whereParams as $key => $value) {
        $stmtAdj->bindValue($key, $value);
    }
    $stmtAdj->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmtAdj->bindValue(':offset', $adjustmentsOffset, PDO::PARAM_INT);
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
    $totalShipping = $shippingTotal;
    $totalReceiving = $receivingTotal;
    $totalAdjustments = $adjustmentsTotal;
    $totalTransactions = $totalShipping + $totalReceiving + $totalAdjustments;

    // Delayed shipments
    $delayed = 0;
    foreach ($shippingData as $ship) {
        if ($ship['Status'] === 'Delayed') {
            $delayed++;
        }
    }
    $delayRate = $totalShipping > 0 ? round(($delayed / $totalShipping) * 100, 1) : 0;

    // CHART DATA CALCULATIONS
    
    // 1. Shipment Volume Over Time (monthly aggregation)
    $volumeSql = "SELECT 
                    DATE_FORMAT(s.PromisedDate, '%Y-%m') as month,
                    COUNT(*) as shipment_count
                  FROM Shipping s
                  JOIN Company src ON s.SourceCompanyID = src.CompanyID
                  JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID";
    
    if (!empty($region) || !empty($tierLevel)) {
        $volumeSql .= " LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
                        LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID";
    }
    
    $volumeSql .= " WHERE s.PromisedDate BETWEEN :start AND :end";
    
    if (!empty($companyID)) {
        $volumeSql .= " AND (s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)";
    }
    if (!empty($region)) {
        $volumeSql .= " AND (srcLoc.ContinentName = :region OR destLoc.ContinentName = :region)";
    }
    if (!empty($tierLevel)) {
        $volumeSql .= " AND (src.TierLevel = :tier OR dest.TierLevel = :tier)";
    }
    
    $volumeSql .= " GROUP BY month ORDER BY month";
    
    $stmtVolume = $pdo->prepare($volumeSql);
    $stmtVolume->execute($whereParams);
    $volumeData = $stmtVolume->fetchAll();
    
    // 2. On-Time Delivery Rate Over Time (monthly)
    $onTimeRateSql = "SELECT 
                        DATE_FORMAT(s.PromisedDate, '%Y-%m') as month,
                        COUNT(*) as total,
                        SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as onTime
                      FROM Shipping s
                      JOIN Company src ON s.SourceCompanyID = src.CompanyID
                      JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID";
    
    if (!empty($region) || !empty($tierLevel)) {
        $onTimeRateSql .= " LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
                            LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID";
    }
    
    $onTimeRateSql .= " WHERE s.PromisedDate BETWEEN :start AND :end AND s.ActualDate IS NOT NULL";
    
    if (!empty($companyID)) {
        $onTimeRateSql .= " AND (s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)";
    }
    if (!empty($region)) {
        $onTimeRateSql .= " AND (srcLoc.ContinentName = :region OR destLoc.ContinentName = :region)";
    }
    if (!empty($tierLevel)) {
        $onTimeRateSql .= " AND (src.TierLevel = :tier OR dest.TierLevel = :tier)";
    }
    
    $onTimeRateSql .= " GROUP BY month ORDER BY month";
    
    $stmtOnTimeRate = $pdo->prepare($onTimeRateSql);
    $stmtOnTimeRate->execute($whereParams);
    $onTimeRateData = $stmtOnTimeRate->fetchAll();
    
    // 3. Top Products Handled (by volume)
    $productsSql = "SELECT 
                        p.ProductName,
                        p.Category,
                        COUNT(*) as shipment_count,
                        SUM(s.Quantity) as total_quantity
                    FROM Shipping s
                    JOIN Product p ON s.ProductID = p.ProductID
                    JOIN Company src ON s.SourceCompanyID = src.CompanyID
                    JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID";
    
    if (!empty($region) || !empty($tierLevel)) {
        $productsSql .= " LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
                          LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID";
    }
    
    $productsSql .= " WHERE s.PromisedDate BETWEEN :start AND :end";
    
    if (!empty($companyID)) {
        $productsSql .= " AND (s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)";
    }
    if (!empty($region)) {
        $productsSql .= " AND (srcLoc.ContinentName = :region OR destLoc.ContinentName = :region)";
    }
    if (!empty($tierLevel)) {
        $productsSql .= " AND (src.TierLevel = :tier OR dest.TierLevel = :tier)";
    }
    
    $productsSql .= " GROUP BY p.ProductID, p.ProductName, p.Category 
                      ORDER BY total_quantity DESC 
                      LIMIT 10";
    
    $stmtProducts = $pdo->prepare($productsSql);
    $stmtProducts->execute($whereParams);
    $productsData = $stmtProducts->fetchAll();
    
    // 4. Disruption Exposure Score (total disruptions + 2 * high impact events)
    $disruptionSql = "SELECT 
                        DATE_FORMAT(de.EventDate, '%Y-%m') as month,
                        COUNT(DISTINCT de.EventID) as total_disruptions,
                        SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as high_impact
                      FROM DisruptionEvent de
                      LEFT JOIN ImpactsCompany ic ON de.EventID = ic.EventID";
    
    if (!empty($companyID) || !empty($region) || !empty($tierLevel)) {
        $disruptionSql .= " LEFT JOIN Company c ON ic.AffectedCompanyID = c.CompanyID";
        
        if (!empty($region) || !empty($tierLevel)) {
            $disruptionSql .= " LEFT JOIN Location loc ON c.LocationID = loc.LocationID";
        }
    }
    
    $disruptionSql .= " WHERE de.EventDate BETWEEN :start AND :end";
    
    if (!empty($companyID)) {
        $disruptionSql .= " AND ic.AffectedCompanyID = :companyID";
    }
    if (!empty($region)) {
        $disruptionSql .= " AND loc.ContinentName = :region";
    }
    if (!empty($tierLevel)) {
        $disruptionSql .= " AND c.TierLevel = :tier";
    }
    
    $disruptionSql .= " GROUP BY month ORDER BY month";
    
    $stmtDisruption = $pdo->prepare($disruptionSql);
    $stmtDisruption->execute($whereParams);
    $disruptionData = $stmtDisruption->fetchAll();
    
    // Calculate disruption exposure scores
    foreach ($disruptionData as &$row) {
        $row['exposure_score'] = intval($row['total_disruptions']) + (2 * intval($row['high_impact']));
    }
    unset($row);

    // AJAX response
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(array(
            'success' => true,
            'data' => array(
                'shipping' => $shippingData,
                'receiving' => $receivingData,
                'adjustments' => $adjustmentsData,
                'metrics' => array(
                    'totalTransactions' => $totalTransactions,
                    'totalShipping' => $totalShipping,
                    'totalReceiving' => $totalReceiving,
                    'totalAdjustments' => $totalAdjustments,
                    'delayed' => $delayed,
                    'delayRate' => $delayRate
                ),
                'charts' => array(
                    'volume' => $volumeData,
                    'onTimeRate' => $onTimeRateData,
                    'products' => $productsData,
                    'disruption' => $disruptionData
                ),
                'pagination' => array(
                    'shipping' => array(
                        'currentPage' => $shippingPage,
                        'totalPages' => $shippingTotalPages,
                        'totalRecords' => $shippingTotal,
                        'pageSize' => $pageSize
                    ),
                    'receiving' => array(
                        'currentPage' => $receivingPage,
                        'totalPages' => $receivingTotalPages,
                        'totalRecords' => $receivingTotal,
                        'pageSize' => $pageSize
                    ),
                    'adjustments' => array(
                        'currentPage' => $adjustmentsPage,
                        'totalPages' => $adjustmentsTotalPages,
                        'totalRecords' => $adjustmentsTotal,
                        'pageSize' => $pageSize
                    )
                )
            )
        ));
        exit;
    }

    // Get all companies and regions for the dropdowns (only needed on initial page load)
    $allCompanies = $pdo->query("SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName")->fetchAll();
    $allRegions = $pdo->query("SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName")->fetchAll();
    
    // Get all products for dropdown
    $allProducts = $pdo->query("SELECT ProductID, ProductName, Category FROM Product ORDER BY ProductName")->fetchAll();
    
    // Get all distributors for dropdown
    $allDistributors = $pdo->query("SELECT c.CompanyID, c.CompanyName FROM Distributor d JOIN Company c ON d.CompanyID = c.CompanyID ORDER BY c.CompanyName")->fetchAll();
    
    // Get all shipments for receiving dropdown (ShipmentID with product and source info)
    $allShipments = $pdo->query("SELECT s.ShipmentID, p.ProductName, c.CompanyName as SourceCompany, s.ProductID, s.SourceCompanyID 
                                 FROM Shipping s 
                                 JOIN Product p ON s.ProductID = p.ProductID 
                                 JOIN Company c ON s.SourceCompanyID = c.CompanyID 
                                 ORDER BY s.ShipmentID DESC 
                                 LIMIT 500")->fetchAll();

} catch (Exception $e) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(array('success' => false, 'error' => $e->getMessage()));
        exit;
    }
    die("Database error: " . $e->getMessage());
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transactions - SCM</title>
    <link rel="stylesheet" href="../assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .content-section {
            background: rgba(0,0,0,0.6);
            padding: 20px;
            border-radius: 8px;
            border: 2px solid rgba(207,185,145,0.3);
            margin-bottom: 20px;
        }
        .content-section h3 {
            margin-top: 0;
            color: var(--purdue-gold);
        }
        
        .pagination-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: rgba(0,0,0,0.4);
            border-top: 2px solid rgba(207,185,145,0.3);
            margin-top: 10px;
        }
        
        .pagination-info {
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .pagination-buttons {
            display: flex;
            gap: 10px;
        }
        
        .page-btn {
            padding: 8px 16px;
            background: rgba(207,185,145,0.2);
            border: 1px solid var(--purdue-gold);
            color: var(--purdue-gold);
            cursor: pointer;
            border-radius: 4px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }
        
        .page-btn:hover:not(:disabled) {
            background: var(--purdue-gold);
            color: black;
        }
        
        .page-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        .charts-section {
            margin: 30px 0;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .chart-card {
            background: rgba(0,0,0,0.6);
            padding: 20px;
            border-radius: 12px;
            border: 2px solid rgba(207,185,145,0.3);
        }
        
        .chart-card h3 {
            margin: 0 0 15px 0;
            color: var(--purdue-gold);
            font-size: 1.1rem;
        }
        
        .chart-wrapper {
            position: relative;
            height: 300px;
        }

        /* Edit Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 1000; overflow-y: auto; }
        .modal.active { display: block; }
        .modal-content { background: #1a1a1a; border: 2px solid var(--purdue-gold); border-radius: 8px; max-width: 600px; margin: 30px auto; padding: 30px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h2 { color: var(--purdue-gold); margin: 0; }
        .close-btn { background: #f44336; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; }
        .close-btn:hover { background: #d32f2f; }

        .edit-btn {
            padding: 6px 12px;
            background: rgba(207,185,145,0.2);
            border: 1px solid var(--purdue-gold);
            color: var(--purdue-gold);
            cursor: pointer;
            border-radius: 4px;
            font-size: 0.85rem;
            transition: all 0.3s;
        }
        .edit-btn:hover {
            background: var(--purdue-gold);
            color: black;
        }
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

        <?php if (isset($_GET['updated'])): ?>
        <div style="background: rgba(76,175,80,0.2); border: 2px solid #4caf50; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <p style="margin: 0; color: #4caf50;">✓ Transaction updated successfully!</p>
        </div>
        <?php endif; ?>

        <!-- Date Filter -->
        <div class="content-section">
            <h3>Filter Transactions</h3>
            <form id="filterForm">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Start Date:</label>
                        <input type="date" id="start_date" value="<?php echo $startDate ?>" required style="padding: 8px; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white; width: 100%;">
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">End Date:</label>
                        <input type="date" id="end_date" value="<?php echo $endDate ?>" required style="padding: 8px; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white; width: 100%;">
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Company (Optional):</label>
                        <select id="company_id" style="padding: 8px; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white; width: 100%;">
                            <option value="">All Companies</option>
                            <?php foreach ($allCompanies as $c): ?>
                                <option value="<?= $c['CompanyID'] ?>" <?= $companyID == $c['CompanyID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Region (Optional):</label>
                        <select id="region" style="padding: 8px; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white; width: 100%;">
                            <option value="">All Regions</option>
                            <?php foreach ($allRegions as $r): ?>
                                <option value="<?= $r['ContinentName'] ?>" <?= $region == $r['ContinentName'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['ContinentName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="color: var(--text-light); display: block; margin-bottom: 5px;">Tier Level (Optional):</label>
                        <select id="tier" style="padding: 8px; border-radius: 4px; border: 1px solid rgba(207,185,145,0.3); background: rgba(0,0,0,0.5); color: white; width: 100%;">
                            <option value="">All Tiers</option>
                            <option value="1" <?= $tierLevel == '1' ? 'selected' : '' ?>>Tier 1</option>
                            <option value="2" <?= $tierLevel == '2' ? 'selected' : '' ?>>Tier 2</option>
                            <option value="3" <?= $tierLevel == '3' ? 'selected' : '' ?>>Tier 3</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 15px; display: flex; gap: 10px;">
                    <button type="button" id="clearBtn" class="btn-secondary">Clear Filter</button>
                </div>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="metrics-grid">
            <div class="metric-card">
                <h3 id="metric-total"><?php echo number_format($totalTransactions) ?></h3>
                <p>Total Transactions</p>
            </div>
            <div class="metric-card">
                <h3 id="metric-shipping"><?php echo number_format($totalShipping) ?></h3>
                <p>Shipping Transactions</p>
            </div>
            <div class="metric-card">
                <h3 id="metric-receiving"><?php echo number_format($totalReceiving) ?></h3>
                <p>Receiving Transactions</p>
            </div>
            <div class="metric-card">
                <h3 id="metric-adjustments"><?php echo number_format($totalAdjustments) ?></h3>
                <p>Inventory Adjustments</p>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-section">
            <h2 style="color: var(--purdue-gold); margin-bottom: 20px;">Transaction Analytics</h2>
            
            <div class="charts-grid">
                <!-- Shipment Volume Over Time -->
                <div class="chart-card">
                    <h3>Shipment Volume Over Time</h3>
                    <div class="chart-wrapper">
                        <canvas id="volumeChart"></canvas>
                    </div>
                </div>
                
                <!-- On-Time Delivery Rate -->
                <div class="chart-card">
                    <h3>On-Time Delivery Rate Over Time</h3>
                    <div class="chart-wrapper">
                        <canvas id="onTimeRateChart"></canvas>
                    </div>
                </div>
                
                <!-- Top Products Handled -->
                <div class="chart-card">
                    <h3>Top 10 Products by Volume</h3>
                    <div class="chart-wrapper">
                        <canvas id="productsChart"></canvas>
                    </div>
                </div>
                
                <!-- Disruption Exposure Score -->
                <div class="chart-card">
                    <h3>Disruption Exposure Score (Disruptions + 2×High Impact)</h3>
                    <div class="chart-wrapper">
                        <canvas id="disruptionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <button class="tab-btn active" data-tab="shipping">
                Shipping (<span id="tab-count-shipping"><?php echo number_format($totalShipping) ?></span>)
            </button>
            <button class="tab-btn" data-tab="receiving">
                Receiving (<span id="tab-count-receiving"><?php echo number_format($totalReceiving) ?></span>)
            </button>
            <button class="tab-btn" data-tab="adjustments">
                Adjustments (<span id="tab-count-adjustments"><?php echo number_format($totalAdjustments) ?></span>)
            </button>
        </div>

        <!-- Shipping Tab -->
        <div id="tab-shipping" class="tab-content active">
            <div class="content-section">
                <h3>Shipping Transactions</h3>
                <div class="table-scroll-wrapper" id="shipping-table-container">
                    <!-- Content will be populated by JavaScript -->
                </div>
                <div class="pagination-controls" id="shipping-pagination">
                    <div class="pagination-info" id="shipping-page-info"></div>
                    <div class="pagination-buttons">
                        <button class="page-btn" id="shipping-prev-btn" onclick="changePage('shipping', -1)">← Previous</button>
                        <button class="page-btn" id="shipping-next-btn" onclick="changePage('shipping', 1)">Next →</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Receiving Tab -->
        <div id="tab-receiving" class="tab-content">
            <div class="content-section">
                <h3>Receiving Transactions</h3>
                <div class="table-scroll-wrapper" id="receiving-table-container">
                    <!-- Content will be populated by JavaScript -->
                </div>
                <div class="pagination-controls" id="receiving-pagination">
                    <div class="pagination-info" id="receiving-page-info"></div>
                    <div class="pagination-buttons">
                        <button class="page-btn" id="receiving-prev-btn" onclick="changePage('receiving', -1)">← Previous</button>
                        <button class="page-btn" id="receiving-next-btn" onclick="changePage('receiving', 1)">Next →</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Adjustments Tab -->
        <div id="tab-adjustments" class="tab-content">
            <div class="content-section">
                <h3>Inventory Adjustments</h3>
                <p style="color: rgba(255,255,255,0.6); margin-bottom: 15px;">
                    Showing 500 adjustments per page in selected date range
                </p>
                <div class="table-scroll-wrapper" id="adjustments-table-container">
                    <!-- Content will be populated by JavaScript -->
                </div>
                <div class="pagination-controls" id="adjustments-pagination">
                    <div class="pagination-info" id="adjustments-page-info"></div>
                    <div class="pagination-buttons">
                        <button class="page-btn" id="adjustments-prev-btn" onclick="changePage('adjustments', -1)">← Previous</button>
                        <button class="page-btn" id="adjustments-next-btn" onclick="changePage('adjustments', 1)">Next →</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Shipping Modal -->
    <div id="editShippingModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2>Edit Shipping Transaction</h2>
                <button class="close-btn" onclick="closeEditModal('shipping')">✕ Close</button>
            </div>
            <form method="POST" action="transactions.php">
                <input type="hidden" name="action" value="update_shipping">
                <input type="hidden" name="shipment_id" id="edit_shipment_id">
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Product:</label>
                    <select name="product_id" id="edit_product_id" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                        <option value="">Select Product</option>
                        <?php foreach ($allProducts as $prod): ?>
                            <option value="<?= $prod['ProductID'] ?>">
                                <?= htmlspecialchars($prod['ProductName']) ?> (<?= htmlspecialchars($prod['Category']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Source Company:</label>
                        <select name="source_company_id" id="edit_source_company_id" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                            <option value="">Select Source</option>
                            <?php foreach ($allCompanies as $c): ?>
                                <option value="<?= $c['CompanyID'] ?>">
                                    <?= htmlspecialchars($c['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Destination Company:</label>
                        <select name="destination_company_id" id="edit_destination_company_id" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                            <option value="">Select Destination</option>
                            <?php foreach ($allCompanies as $c): ?>
                                <option value="<?= $c['CompanyID'] ?>">
                                    <?= htmlspecialchars($c['CompanyName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Distributor (Optional):</label>
                    <select name="distributor_id" id="edit_distributor_id" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                        <option value="">Direct (No Distributor)</option>
                        <?php foreach ($allDistributors as $dist): ?>
                            <option value="<?= $dist['CompanyID'] ?>">
                                <?= htmlspecialchars($dist['CompanyName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Quantity:</label>
                    <input type="number" name="quantity" id="edit_quantity" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Promised Date:</label>
                        <input type="date" name="promised_date" id="edit_promised_date" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                    </div>

                    <div>
                        <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Actual Date (Optional):</label>
                        <input type="date" name="actual_date" id="edit_actual_date" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                    </div>
                </div>
                
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit" style="padding: 12px 24px; background: var(--purdue-gold); color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 1rem;">💾 Save Changes</button>
                    <button type="button" onclick="closeEditModal('shipping')" style="padding: 12px 24px; background: rgba(255,255,255,0.1); color: white; border: 1px solid rgba(207,185,145,0.3); border-radius: 4px; cursor: pointer; font-size: 1rem;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Receiving Modal -->
    <div id="editReceivingModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2>Edit Receiving Transaction</h2>
                <button class="close-btn" onclick="closeEditModal('receiving')">✕ Close</button>
            </div>
            <form method="POST" action="transactions.php">
                <input type="hidden" name="action" value="update_receiving">
                <input type="hidden" name="receiving_id" id="edit_receiving_id">
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Shipment (Product & Source):</label>
                    <select name="shipment_id" id="edit_shipment_id" required onchange="updateReceivingProductInfo()" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                        <option value="">Select Shipment</option>
                        <?php foreach ($allShipments as $ship): ?>
                            <option value="<?= $ship['ShipmentID'] ?>" 
                                    data-product-id="<?= $ship['ProductID'] ?>"
                                    data-product-name="<?= htmlspecialchars($ship['ProductName']) ?>"
                                    data-source-id="<?= $ship['SourceCompanyID'] ?>"
                                    data-source-name="<?= htmlspecialchars($ship['SourceCompany']) ?>">
                                Shipment #<?= $ship['ShipmentID'] ?> - <?= htmlspecialchars($ship['ProductName']) ?> from <?= htmlspecialchars($ship['SourceCompany']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size: 0.85rem; color: rgba(255,255,255,0.6); margin: 5px 0 0 0;">This determines the Product and Source Company</p>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Product:</label>
                        <input type="text" id="display_product_name" disabled style="width: 100%; padding: 10px; background: rgba(0,0,0,0.3); border: 1px solid rgba(207,185,145,0.2); color: rgba(255,255,255,0.6); border-radius: 4px; font-size: 1rem;" value="(Select shipment first)">
                    </div>

                    <div>
                        <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Source Company:</label>
                        <input type="text" id="display_source_company" disabled style="width: 100%; padding: 10px; background: rgba(0,0,0,0.3); border: 1px solid rgba(207,185,145,0.2); color: rgba(255,255,255,0.6); border-radius: 4px; font-size: 1rem;" value="(Select shipment first)">
                    </div>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Receiver Company:</label>
                    <select name="receiver_company_id" id="edit_receiver_company_id" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                        <option value="">Select Receiver</option>
                        <?php foreach ($allCompanies as $c): ?>
                            <option value="<?= $c['CompanyID'] ?>">
                                <?= htmlspecialchars($c['CompanyName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Quantity Received:</label>
                        <input type="number" name="quantity_received" id="edit_quantity_received" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                    </div>

                    <div>
                        <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Received Date:</label>
                        <input type="date" name="received_date" id="edit_received_date" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                    </div>
                </div>
                
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit" style="padding: 12px 24px; background: var(--purdue-gold); color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 1rem;">💾 Save Changes</button>
                    <button type="button" onclick="closeEditModal('receiving')" style="padding: 12px 24px; background: rgba(255,255,255,0.1); color: white; border: 1px solid rgba(207,185,145,0.3); border-radius: 4px; cursor: pointer; font-size: 1rem;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Adjustment Modal -->
    <div id="editAdjustmentModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2>Edit Inventory Adjustment</h2>
                <button class="close-btn" onclick="closeEditModal('adjustment')">✕ Close</button>
            </div>
            <form method="POST" action="transactions.php">
                <input type="hidden" name="action" value="update_adjustment">
                <input type="hidden" name="adjustment_id" id="edit_adjustment_id">
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Company:</label>
                    <select name="company_id" id="edit_adjustment_company_id" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                        <option value="">Select Company</option>
                        <?php foreach ($allCompanies as $c): ?>
                            <option value="<?= $c['CompanyID'] ?>">
                                <?= htmlspecialchars($c['CompanyName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Product:</label>
                    <select name="product_id" id="edit_adjustment_product_id" required onchange="updateAdjustmentCategory()" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                        <option value="">Select Product</option>
                        <?php foreach ($allProducts as $prod): ?>
                            <option value="<?= $prod['ProductID'] ?>" data-category="<?= htmlspecialchars($prod['Category']) ?>">
                                <?= htmlspecialchars($prod['ProductName']) ?> (<?= htmlspecialchars($prod['Category']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Category:</label>
                    <input type="text" id="display_adjustment_category" disabled style="width: 100%; padding: 10px; background: rgba(0,0,0,0.3); border: 1px solid rgba(207,185,145,0.2); color: rgba(255,255,255,0.6); border-radius: 4px; font-size: 1rem;" value="(Select product first)">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Quantity Change:</label>
                        <input type="number" name="quantity_change" id="edit_quantity_change" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                        <p style="font-size: 0.85rem; color: rgba(255,255,255,0.6); margin: 5px 0 0 0;">Positive for restocks, negative for adjustments</p>
                    </div>

                    <div>
                        <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Type:</label>
                        <input type="text" id="display_adjustment_type" disabled style="width: 100%; padding: 10px; background: rgba(0,0,0,0.3); border: 1px solid rgba(207,185,145,0.2); color: rgba(255,255,255,0.6); border-radius: 4px; font-size: 1rem;" value="(Based on quantity)">
                        <p style="font-size: 0.85rem; color: rgba(255,255,255,0.6); margin: 5px 0 0 0;">Auto-calculated from quantity</p>
                    </div>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Adjustment Date:</label>
                    <input type="date" name="adjustment_date" id="edit_adjustment_date" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; color: var(--purdue-gold); font-weight: bold; margin-bottom: 5px;">Reason:</label>
                    <textarea name="reason" id="edit_reason" rows="3" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.5); border: 1px solid rgba(207,185,145,0.3); color: white; border-radius: 4px; font-size: 1rem;"></textarea>
                </div>
                
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit" style="padding: 12px 24px; background: var(--purdue-gold); color: #000; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 1rem;">💾 Save Changes</button>
                    <button type="button" onclick="closeEditModal('adjustment')" style="padding: 12px 24px; background: rgba(255,255,255,0.1); color: white; border: 1px solid rgba(207,185,145,0.3); border-radius: 4px; cursor: pointer; font-size: 1rem;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function() {
        var currentTab = 'shipping';
        var pages = {
            shipping: 1,
            receiving: 1,
            adjustments: 1
        };
        var paginationData = null;
        
        // Chart instances
        var charts = {
            volume: null,
            onTimeRate: null,
            products: null,
            disruption: null
        };
        
        // Edit modal functions
        window.openEditShippingModal = function(id, productID, sourceID, destID, distID, quantity, promisedDate, actualDate) {
            document.getElementById('edit_shipment_id').value = id;
            document.getElementById('edit_product_id').value = productID;
            document.getElementById('edit_source_company_id').value = sourceID;
            document.getElementById('edit_destination_company_id').value = destID;
            document.getElementById('edit_distributor_id').value = distID || '';
            document.getElementById('edit_quantity').value = quantity;
            document.getElementById('edit_promised_date').value = promisedDate;
            document.getElementById('edit_actual_date').value = actualDate || '';
            document.getElementById('editShippingModal').classList.add('active');
        };

        window.openEditReceivingModal = function(id, shipmentID, receiverID, quantity, receivedDate, productName, sourceName) {
            document.getElementById('edit_receiving_id').value = id;
            document.getElementById('edit_shipment_id').value = shipmentID;
            document.getElementById('edit_receiver_company_id').value = receiverID;
            document.getElementById('edit_quantity_received').value = quantity;
            document.getElementById('edit_received_date').value = receivedDate;
            
            // Update display fields
            document.getElementById('display_product_name').value = productName;
            document.getElementById('display_source_company').value = sourceName;
            
            document.getElementById('editReceivingModal').classList.add('active');
        };

        window.updateReceivingProductInfo = function() {
            var select = document.getElementById('edit_shipment_id');
            var selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                document.getElementById('display_product_name').value = selectedOption.getAttribute('data-product-name');
                document.getElementById('display_source_company').value = selectedOption.getAttribute('data-source-name');
            } else {
                document.getElementById('display_product_name').value = '(Select shipment first)';
                document.getElementById('display_source_company').value = '(Select shipment first)';
            }
        };

        window.openEditAdjustmentModal = function(id, companyID, productID, quantityChange, adjustmentDate, reason, category) {
            document.getElementById('edit_adjustment_id').value = id;
            document.getElementById('edit_adjustment_company_id').value = companyID;
            document.getElementById('edit_adjustment_product_id').value = productID;
            document.getElementById('edit_quantity_change').value = quantityChange;
            document.getElementById('edit_adjustment_date').value = adjustmentDate;
            document.getElementById('edit_reason').value = reason || '';
            
            // Update display fields
            document.getElementById('display_adjustment_category').value = category;
            
            // Update type display
            var type = quantityChange > 0 ? 'Restock' : 'Adjustment';
            document.getElementById('display_adjustment_type').value = type;
            
            document.getElementById('editAdjustmentModal').classList.add('active');
        };

        window.updateAdjustmentCategory = function() {
            var select = document.getElementById('edit_adjustment_product_id');
            var selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption.value) {
                document.getElementById('display_adjustment_category').value = selectedOption.getAttribute('data-category');
            } else {
                document.getElementById('display_adjustment_category').value = '(Select product first)';
            }
        };

        // Update type display when quantity changes
        document.addEventListener('DOMContentLoaded', function() {
            var quantityInput = document.getElementById('edit_quantity_change');
            if (quantityInput) {
                quantityInput.addEventListener('input', function() {
                    var qty = parseInt(this.value);
                    var typeDisplay = document.getElementById('display_adjustment_type');
                    if (typeDisplay) {
                        if (qty > 0) {
                            typeDisplay.value = 'Restock';
                        } else if (qty < 0) {
                            typeDisplay.value = 'Adjustment';
                        } else {
                            typeDisplay.value = '(Based on quantity)';
                        }
                    }
                });
            }
        });

        window.closeEditModal = function(type) {
            if (type === 'shipping') {
                document.getElementById('editShippingModal').classList.remove('active');
            } else if (type === 'receiving') {
                document.getElementById('editReceivingModal').classList.remove('active');
            } else if (type === 'adjustment') {
                document.getElementById('editAdjustmentModal').classList.remove('active');
            }
        };
        
        // Load transaction data via AJAX
        function loadTransactions() {
            var params = 'ajax=1&start_date=' + encodeURIComponent(document.getElementById('start_date').value) +
                        '&end_date=' + encodeURIComponent(document.getElementById('end_date').value) +
                        '&company_id=' + encodeURIComponent(document.getElementById('company_id').value) +
                        '&region=' + encodeURIComponent(document.getElementById('region').value) +
                        '&tier=' + encodeURIComponent(document.getElementById('tier').value) +
                        '&shipping_page=' + pages.shipping +
                        '&receiving_page=' + pages.receiving +
                        '&adjustments_page=' + pages.adjustments;
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'transactions.php?' + params, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        paginationData = response.data.pagination;
                        updateDisplay(response.data);
                        updateCharts(response.data.charts);
                    }
                }
            };
            xhr.send();
        }
        
        // Change page for a specific tab
        window.changePage = function(tab, direction) {
            pages[tab] = Math.max(1, pages[tab] + direction);
            loadTransactions();
        };
        
        function updateDisplay(data) {
            // Update metrics
            document.getElementById('metric-total').textContent = formatNumber(data.metrics.totalTransactions);
            document.getElementById('metric-shipping').textContent = formatNumber(data.metrics.totalShipping);
            document.getElementById('metric-receiving').textContent = formatNumber(data.metrics.totalReceiving);
            document.getElementById('metric-adjustments').textContent = formatNumber(data.metrics.totalAdjustments);
            
            // Update tab counts
            document.getElementById('tab-count-shipping').textContent = formatNumber(data.metrics.totalShipping);
            document.getElementById('tab-count-receiving').textContent = formatNumber(data.metrics.totalReceiving);
            document.getElementById('tab-count-adjustments').textContent = formatNumber(data.metrics.totalAdjustments);
            
            // Update tables
            renderShippingTable(data.shipping);
            renderReceivingTable(data.receiving);
            renderAdjustmentsTable(data.adjustments);
        }
        
        function renderShippingTable(data) {
            var container = document.getElementById('shipping-table-container');
            
            if (data.length === 0) {
                container.innerHTML = '<p style="text-align: center; padding: 40px; color: rgba(255,255,255,0.5);">No shipping transactions found in selected date range.</p>';
                updatePaginationControls('shipping');
                return;
            }
            
            var html = '<table><thead><tr>' +
                '<th>Shipment ID</th><th>Product</th><th>Category</th><th>Source</th><th>Destination</th>' +
                '<th>Distributor</th><th>Quantity</th><th>Promised Date</th><th>Actual Date</th><th>Status</th><th>Actions</th>' +
                '</tr></thead><tbody>';
            
            data.forEach(function(row) {
                var statusClass = row.Status.toLowerCase().replace(/\s+/g, '');
                var actualDate = row.ActualDate || '';
                var distID = row.DistributorID || '';
                
                html += '<tr>' +
                    '<td>' + row.ShipmentID + '</td>' +
                    '<td>' + escapeHtml(row.ProductName) + '</td>' +
                    '<td>' + escapeHtml(row.Category) + '</td>' +
                    '<td>' + escapeHtml(row.SourceCompany) + '</td>' +
                    '<td>' + escapeHtml(row.DestCompany) + '</td>' +
                    '<td>' + (row.DistributorName ? escapeHtml(row.DistributorName) : 'Direct') + '</td>' +
                    '<td>' + formatNumber(row.Quantity) + '</td>' +
                    '<td>' + row.PromisedDate + '</td>' +
                    '<td>' + (row.ActualDate || '-') + '</td>' +
                    '<td><span class="status-badge status-' + statusClass + '">' + escapeHtml(row.Status) + '</span></td>' +
                    '<td><button class="edit-btn" onclick="openEditShippingModal(' + 
                        row.ShipmentID + ', ' + 
                        row.ProductID + ', ' + 
                        row.SourceCompanyID + ', ' + 
                        row.DestinationCompanyID + ', ' + 
                        (distID ? distID : 'null') + ', ' + 
                        row.Quantity + ', \'' + 
                        row.PromisedDate + '\', \'' + 
                        actualDate + '\')">✏️ Edit</button></td>' +
                    '</tr>';
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
            updatePaginationControls('shipping');
        }
        
        function renderReceivingTable(data) {
            var container = document.getElementById('receiving-table-container');
            
            if (data.length === 0) {
                container.innerHTML = '<p style="text-align: center; padding: 40px; color: rgba(255,255,255,0.5);">No receiving transactions found in selected date range.</p>';
                updatePaginationControls('receiving');
                return;
            }
            
            var html = '<table><thead><tr>' +
                '<th>Receiving ID</th><th>Product</th><th>Category</th><th>Source Company</th>' +
                '<th>Receiver Company</th><th>Quantity Received</th><th>Received Date</th><th>Actions</th>' +
                '</tr></thead><tbody>';
            
            data.forEach(function(row) {
                html += '<tr>' +
                    '<td>' + row.ReceivingID + '</td>' +
                    '<td>' + escapeHtml(row.ProductName) + '</td>' +
                    '<td>' + escapeHtml(row.Category) + '</td>' +
                    '<td>' + escapeHtml(row.SourceCompany) + '</td>' +
                    '<td>' + escapeHtml(row.ReceiverCompany) + '</td>' +
                    '<td>' + formatNumber(row.QuantityReceived) + '</td>' +
                    '<td>' + row.ReceivedDate + '</td>' +
                    '<td><button class="edit-btn" onclick="openEditReceivingModal(' + 
                        row.ReceivingID + ', ' + 
                        row.ShipmentID + ', ' + 
                        row.ReceiverCompanyID + ', ' + 
                        row.QuantityReceived + ', \'' + 
                        row.ReceivedDate + '\', \'' + 
                        escapeHtml(row.ProductName).replace(/'/g, "\\'") + '\', \'' + 
                        escapeHtml(row.SourceCompany).replace(/'/g, "\\'") + '\')">✏️ Edit</button></td>' +
                    '</tr>';
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
            updatePaginationControls('receiving');
        }
        
        function renderAdjustmentsTable(data) {
            var container = document.getElementById('adjustments-table-container');
            
            if (data.length === 0) {
                container.innerHTML = '<p style="text-align: center; padding: 40px; color: rgba(255,255,255,0.5);">No inventory adjustments found.</p>';
                updatePaginationControls('adjustments');
                return;
            }
            
            var html = '<table><thead><tr>' +
                '<th>Adjustment ID</th><th>Date</th><th>Company</th><th>Product</th><th>Category</th>' +
                '<th>Type</th><th>Quantity Change</th><th>Reason</th><th>Actions</th>' +
                '</tr></thead><tbody>';
            
            data.forEach(function(row) {
                var typeClass = row.Type.toLowerCase();
                var changeColor = row.QuantityChange > 0 ? '#4caf50' : '#f44336';
                var changePrefix = row.QuantityChange > 0 ? '+' : '';
                var reason = row.Reason || '';
                
                html += '<tr>' +
                    '<td>' + row.AdjustmentID + '</td>' +
                    '<td>' + row.AdjustmentDate + '</td>' +
                    '<td>' + escapeHtml(row.CompanyName) + '</td>' +
                    '<td>' + escapeHtml(row.ProductName) + '</td>' +
                    '<td>' + escapeHtml(row.Category) + '</td>' +
                    '<td><span class="type-badge type-' + typeClass + '">' + escapeHtml(row.Type) + '</span></td>' +
                    '<td style="color: ' + changeColor + '">' + changePrefix + formatNumber(row.QuantityChange) + '</td>' +
                    '<td>' + (row.Reason ? escapeHtml(row.Reason) : '-') + '</td>' +
                    '<td><button class="edit-btn" onclick="openEditAdjustmentModal(' + 
                        row.AdjustmentID + ', ' + 
                        row.CompanyID + ', ' + 
                        row.ProductID + ', ' + 
                        row.QuantityChange + ', \'' + 
                        row.AdjustmentDate + '\', \'' + 
                        reason.replace(/'/g, "\\'") + '\', \'' + 
                        escapeHtml(row.Category).replace(/'/g, "\\'") + '\')">✏️ Edit</button></td>' +
                    '</tr>';
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
            updatePaginationControls('adjustments');
        }
        
        function updatePaginationControls(tab) {
            if (!paginationData || !paginationData[tab]) return;
            
            var info = paginationData[tab];
            var start = (info.currentPage - 1) * info.pageSize + 1;
            var end = Math.min(info.currentPage * info.pageSize, info.totalRecords);
            
            // Update info text
            var infoText = 'Showing ' + formatNumber(start) + '-' + formatNumber(end) + 
                          ' of ' + formatNumber(info.totalRecords) + ' records (Page ' + 
                          info.currentPage + ' of ' + info.totalPages + ')';
            document.getElementById(tab + '-page-info').textContent = infoText;
            
            // Update button states
            var prevBtn = document.getElementById(tab + '-prev-btn');
            var nextBtn = document.getElementById(tab + '-next-btn');
            
            prevBtn.disabled = info.currentPage <= 1;
            nextBtn.disabled = info.currentPage >= info.totalPages;
            
            // Hide pagination if only one page
            var paginationDiv = document.getElementById(tab + '-pagination');
            if (info.totalPages <= 1) {
                paginationDiv.style.display = 'none';
            } else {
                paginationDiv.style.display = 'flex';
            }
        }
        
        // Update all charts with new data
        function updateCharts(chartData) {
            // 1. Shipment Volume Chart
            if (charts.volume) charts.volume.destroy();
            var volumeLabels = chartData.volume.map(function(d) { return d.month; });
            var volumeCounts = chartData.volume.map(function(d) { return parseInt(d.shipment_count); });
            
            var ctx1 = document.getElementById('volumeChart').getContext('2d');
            charts.volume = new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: volumeLabels,
                    datasets: [{
                        label: 'Shipments',
                        data: volumeCounts,
                        borderColor: '#CFB991',
                        backgroundColor: 'rgba(207,185,145,0.2)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { 
                            beginAtZero: true,
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        x: { 
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    },
                    plugins: { legend: { labels: { color: 'white' } } }
                }
            });
            
            // 2. On-Time Delivery Rate Chart
            if (charts.onTimeRate) charts.onTimeRate.destroy();
            var onTimeLabels = chartData.onTimeRate.map(function(d) { return d.month; });
            var onTimeRates = chartData.onTimeRate.map(function(d) { 
                var total = parseInt(d.total);
                var onTime = parseInt(d.onTime);
                return total > 0 ? ((onTime / total) * 100).toFixed(1) : 0;
            });
            
            var ctx2 = document.getElementById('onTimeRateChart').getContext('2d');
            charts.onTimeRate = new Chart(ctx2, {
                type: 'line',
                data: {
                    labels: onTimeLabels,
                    datasets: [{
                        label: 'On-Time %',
                        data: onTimeRates,
                        borderColor: '#4caf50',
                        backgroundColor: 'rgba(76,175,80,0.2)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { 
                            beginAtZero: true,
                            max: 100,
                            ticks: { 
                                color: 'white',
                                callback: function(value) { return value + '%'; }
                            },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        x: { 
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    },
                    plugins: { 
                        legend: { labels: { color: 'white' } },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y + '%';
                                }
                            }
                        }
                    }
                }
            });
            
            // 4. Top Products Chart
            if (charts.products) charts.products.destroy();
            var productLabels = chartData.products.map(function(d) { 
                return d.ProductName.length > 20 ? d.ProductName.substring(0, 20) + '...' : d.ProductName; 
            });
            var productQuantities = chartData.products.map(function(d) { return parseInt(d.total_quantity); });
            
            var ctx3 = document.getElementById('productsChart').getContext('2d');
            charts.products = new Chart(ctx3, {
                type: 'bar',
                data: {
                    labels: productLabels,
                    datasets: [{
                        label: 'Total Quantity',
                        data: productQuantities,
                        backgroundColor: '#CFB991'
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
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        y: { 
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    },
                    plugins: { legend: { labels: { color: 'white' } } }
                }
            });
            
            // 5. Disruption Exposure Chart
            if (charts.disruption) charts.disruption.destroy();
            var disruptionLabels = chartData.disruption.map(function(d) { return d.month; });
            var disruptionScores = chartData.disruption.map(function(d) { return parseInt(d.exposure_score); });
            var totalDisruptions = chartData.disruption.map(function(d) { return parseInt(d.total_disruptions); });
            var highImpact = chartData.disruption.map(function(d) { return parseInt(d.high_impact); });
            
            var ctx4 = document.getElementById('disruptionChart').getContext('2d');
            charts.disruption = new Chart(ctx4, {
                type: 'line',
                data: {
                    labels: disruptionLabels,
                    datasets: [{
                        label: 'Exposure Score',
                        data: disruptionScores,
                        borderColor: '#f44336',
                        backgroundColor: 'rgba(244,67,54,0.2)',
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y'
                    }, {
                        label: 'Total Disruptions',
                        data: totalDisruptions,
                        borderColor: '#ff9800',
                        backgroundColor: 'rgba(255,152,0,0.2)',
                        tension: 0.4,
                        fill: false,
                        yAxisID: 'y'
                    }, {
                        label: 'High Impact Events',
                        data: highImpact,
                        borderColor: '#9c27b0',
                        backgroundColor: 'rgba(156,39,176,0.2)',
                        tension: 0.4,
                        fill: false,
                        yAxisID: 'y'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { 
                            beginAtZero: true,
                            position: 'left',
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        },
                        x: { 
                            ticks: { color: 'white' },
                            grid: { color: 'rgba(207,185,145,0.1)' }
                        }
                    },
                    plugins: { legend: { labels: { color: 'white' } } }
                }
            });
        }
        
        // Tab switching
        document.querySelectorAll('.tab-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var tabName = this.getAttribute('data-tab');
                
                // Hide all tabs
                document.querySelectorAll('.tab-content').forEach(function(tab) {
                    tab.classList.remove('active');
                });
                
                // Remove active from all buttons
                document.querySelectorAll('.tab-btn').forEach(function(b) {
                    b.classList.remove('active');
                });
                
                // Show selected tab
                document.getElementById('tab-' + tabName).classList.add('active');
                this.classList.add('active');
                
                currentTab = tabName;
            });
        });
        
        // Clear button
        document.getElementById('clearBtn').addEventListener('click', function() {
            var today = new Date();
            var threeMonthsAgo = new Date();
            threeMonthsAgo.setMonth(today.getMonth() - 3);
            
            document.getElementById('start_date').value = threeMonthsAgo.toISOString().split('T')[0];
            document.getElementById('end_date').value = today.toISOString().split('T')[0];
            document.getElementById('company_id').value = '';
            document.getElementById('region').value = '';
            document.getElementById('tier').value = '';
            
            // Reset all pages to 1
            pages.shipping = 1;
            pages.receiving = 1;
            pages.adjustments = 1;
            
            loadTransactions();
        });
        
        // Dynamic filter updates - reset to page 1 when filters change
        function resetAndLoad() {
            pages.shipping = 1;
            pages.receiving = 1;
            pages.adjustments = 1;
            loadTransactions();
        }
        
        document.getElementById('start_date').addEventListener('change', resetAndLoad);
        document.getElementById('end_date').addEventListener('change', resetAndLoad);
        document.getElementById('company_id').addEventListener('change', resetAndLoad);
        document.getElementById('region').addEventListener('change', resetAndLoad);
        document.getElementById('tier').addEventListener('change', resetAndLoad);
        
        // Helper functions
        function formatNumber(num) {
            return parseInt(num).toLocaleString();
        }
        
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Initialize with PHP data
        (function init() {
            var initialData = {
                shipping: <?php echo json_encode($shippingData) ?>,
                receiving: <?php echo json_encode($receivingData) ?>,
                adjustments: <?php echo json_encode($adjustmentsData) ?>,
                metrics: {
                    totalTransactions: <?php echo $totalTransactions ?>,
                    totalShipping: <?php echo $totalShipping ?>,
                    totalReceiving: <?php echo $totalReceiving ?>,
                    totalAdjustments: <?php echo $totalAdjustments ?>,
                    delayed: <?php echo $delayed ?>,
                    delayRate: <?php echo $delayRate ?>
                },
                charts: {
                    volume: <?php echo json_encode($volumeData) ?>,
                    onTimeRate: <?php echo json_encode($onTimeRateData) ?>,
                    products: <?php echo json_encode($productsData) ?>,
                    disruption: <?php echo json_encode($disruptionData) ?>
                },
                pagination: {
                    shipping: {
                        currentPage: <?php echo $shippingPage ?>,
                        totalPages: <?php echo $shippingTotalPages ?>,
                        totalRecords: <?php echo $shippingTotal ?>,
                        pageSize: <?php echo $pageSize ?>
                    },
                    receiving: {
                        currentPage: <?php echo $receivingPage ?>,
                        totalPages: <?php echo $receivingTotalPages ?>,
                        totalRecords: <?php echo $receivingTotal ?>,
                        pageSize: <?php echo $pageSize ?>
                    },
                    adjustments: {
                        currentPage: <?php echo $adjustmentsPage ?>,
                        totalPages: <?php echo $adjustmentsTotalPages ?>,
                        totalRecords: <?php echo $adjustmentsTotal ?>,
                        pageSize: <?php echo $pageSize ?>
                    }
                }
            };
            
            paginationData = initialData.pagination;
            updateDisplay(initialData);
            updateCharts(initialData.charts);
        })();
    })();
    </script>
</body>
</html>
