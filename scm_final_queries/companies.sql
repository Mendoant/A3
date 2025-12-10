-- companies.php: Line 16
SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName

-- companies.php: Lines 26-29
SELECT c.*, l.City, l.CountryName, l.ContinentName
                FROM Company c
                LEFT JOIN Location l ON c.LocationID = l.LocationID
                WHERE c.CompanyID = ?

-- companies.php: Line 37
SELECT FactoryCapacity FROM Manufacturer WHERE CompanyID = ?

-- companies.php: Line 42
SELECT COUNT(DISTINCT CONCAT(SourceCompanyID, '-', DestinationCompanyID)) as cnt FROM Shipping WHERE DistributorID = ?

-- companies.php: Line 49
SELECT c.CompanyID, c.CompanyName, c.Type FROM DependsOn d JOIN Company c ON d.UpstreamCompanyID = c.CompanyID WHERE d.DownstreamCompanyID = ?

-- companies.php: Line 54
SELECT c.CompanyID, c.CompanyName, c.Type FROM DependsOn d JOIN Company c ON d.DownstreamCompanyID = c.CompanyID WHERE d.UpstreamCompanyID = ?

-- companies.php: Line 59
SELECT p.ProductID, p.ProductName, p.Category FROM SuppliesProduct sp JOIN Product p ON sp.ProductID = p.ProductID WHERE sp.SupplierID = ?

-- companies.php: Line 70
SELECT RepYear, Quarter, HealthScore FROM FinancialReport WHERE CompanyID = ? ORDER BY RepYear DESC, FIELD(Quarter, 'Q4', 'Q3', 'Q2', 'Q1') LIMIT 4

-- companies.php: Line 75
SELECT COUNT(*) as total, SUM(CASE WHEN ActualDate <= PromisedDate THEN 1 ELSE 0 END) as onTime FROM Shipping WHERE (SourceCompanyID = ? OR DestinationCompanyID = ?) AND ActualDate IS NOT NULL AND PromisedDate BETWEEN ? AND ?

-- companies.php: Line 83
SELECT AVG(DATEDIFF(ActualDate, PromisedDate)) as avgDelay, STDDEV(DATEDIFF(ActualDate, PromisedDate)) as stdDelay FROM Shipping WHERE (SourceCompanyID = ? OR DestinationCompanyID = ?) AND ActualDate IS NOT NULL AND ActualDate > PromisedDate AND PromisedDate BETWEEN ? AND ?

-- companies.php: Line 90
SELECT de.EventID, de.EventDate, de.EventRecoveryDate, dc.CategoryName, ic.ImpactLevel, DATEDIFF(de.EventRecoveryDate, de.EventDate) as recoveryDays FROM DisruptionEvent de JOIN ImpactsCompany ic ON de.EventID = ic.EventID JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID WHERE ic.AffectedCompanyID = ? AND de.EventDate BETWEEN ? AND ? ORDER BY de.EventDate DESC

-- companies.php: Line 103
SELECT s.ShipmentID, s.PromisedDate, s.ActualDate, s.Quantity, p.ProductName, dest.CompanyName as DestName FROM Shipping s JOIN Product p ON s.ProductID = p.ProductID JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID WHERE s.SourceCompanyID = ? AND s.PromisedDate BETWEEN ? AND ? ORDER BY s.PromisedDate DESC LIMIT 50

-- companies.php: Lines 111-117
SELECT r.TransactionID as ReceivingID, r.ReceivedDate, r.QuantityReceived, s.ProductID, p.ProductName, src.CompanyName as SrcName 
                    FROM Receiving r 
                    JOIN Shipping s ON r.ShipmentID = s.ShipmentID 
                    JOIN Product p ON s.ProductID = p.ProductID 
                    JOIN Company src ON s.SourceCompanyID = src.CompanyID 
                    WHERE r.ReceiverCompanyID = ? AND r.ReceivedDate BETWEEN ? AND ? 
                    ORDER BY r.ReceivedDate DESC LIMIT 50

-- companies.php: Lines 129-133
SELECT it.TransactionID, it.QuantityChange, it.Type, p.ProductName 
                    FROM InventoryTransaction it 
                    JOIN Product p ON it.ProductID = p.ProductID 
                    WHERE it.CompanyID = ? 
                    ORDER BY it.TransactionID DESC LIMIT 50

-- companies.php: Line 164
SELECT LocationID FROM Location WHERE City = ? AND CountryName = ? AND ContinentName = ?

-- companies.php: Line 172
INSERT INTO Location (City, CountryName, ContinentName) VALUES (?, ?, ?)

-- companies.php: Line 178
UPDATE Company SET CompanyName = :name, TierLevel = :tier, Type = :type, LocationID = :location WHERE CompanyID = :id

-- companies.php: Lines 216-220
SELECT c.CompanyID, c.CompanyName, c.TierLevel, c.Type, l.ContinentName, l.CountryName, l.City
        FROM Company c
        LEFT JOIN Location l ON c.LocationID = l.LocationID
        [whereClause]
        ORDER BY c.CompanyName ASC

-- companies.php: Line 230
SELECT COUNT(*) as cnt FROM Shipping WHERE SourceCompanyID = ? OR DestinationCompanyID = ?

-- companies.php: Line 234
SELECT COUNT(DISTINCT de.EventID) as cnt FROM DisruptionEvent de JOIN ImpactsCompany ic ON de.EventID = ic.EventID WHERE ic.AffectedCompanyID = ?

-- companies.php: Line 238
SELECT HealthScore FROM FinancialReport WHERE CompanyID = ? ORDER BY RepYear DESC, FIELD(Quarter, 'Q4', 'Q3', 'Q2', 'Q1') LIMIT 1

-- companies.php: Line 248
SELECT COUNT(DISTINCT ProductID) as cnt FROM SuppliesProduct WHERE SupplierID = ?

-- companies.php: Line 256
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName
