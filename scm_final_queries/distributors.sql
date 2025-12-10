-- distributors.php: Lines 38-59
SELECT 
            c.CompanyID,
            c.CompanyName,
            c.TierLevel,
            loc.ContinentName as Region,
            COUNT(DISTINCT s.ShipmentID) as shipmentVolume,
            SUM(s.Quantity) as totalQuantity,
            SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as onTimeCount,
            SUM(CASE WHEN s.ActualDate IS NOT NULL THEN 1 ELSE 0 END) as completedCount,
            AVG(CASE WHEN s.ActualDate > s.PromisedDate THEN DATEDIFF(s.ActualDate, s.PromisedDate) ELSE 0 END) as avgDelay,
            COUNT(DISTINCT p.ProductID) as productDiversity,
            COUNT(DISTINCT CASE WHEN s.ActualDate IS NULL THEN s.ShipmentID END) as inTransitCount,
            COUNT(DISTINCT s.SourceCompanyID) as uniqueSourceCompanies,
            COUNT(DISTINCT s.DestinationCompanyID) as uniqueDestCompanies,
            SUM(s.Quantity * 2.5) as estimatedRevenue
        FROM Shipping s
        JOIN Company c ON s.DistributorID = c.CompanyID
        JOIN Product p ON s.ProductID = p.ProductID
        LEFT JOIN Location loc ON c.LocationID = loc.LocationID
        [whereClause]
        GROUP BY c.CompanyID, c.CompanyName, c.TierLevel, loc.ContinentName
        ORDER BY shipmentVolume DESC

-- distributors.php: Lines 76-82
SELECT 
                        COUNT(DISTINCT de.EventID) as totalDisruptions,
                        SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpact
                    FROM DisruptionEvent de
                    JOIN ImpactsCompany ic ON de.EventID = ic.EventID
                    WHERE ic.AffectedCompanyID = :companyID
                    AND de.EventDate BETWEEN :start AND :end

-- distributors.php: Lines 104-112
SELECT 
                COALESCE(loc.ContinentName, 'Unknown') as region,
                COUNT(DISTINCT s.ShipmentID) as shipmentCount
              FROM Shipping s
              JOIN Company c ON s.DistributorID = c.CompanyID
              LEFT JOIN Location loc ON c.LocationID = loc.LocationID
              [whereClause]
              GROUP BY loc.ContinentName
              ORDER BY shipmentCount DESC

-- distributors.php: Lines 119-132
SELECT 
                c.TierLevel as tier,
                COUNT(DISTINCT s.ShipmentID) as shipmentCount,
                AVG(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate 
                    THEN 100 
                    ELSE 0 
                END) as avgOnTimeRate
            FROM Shipping s
            JOIN Company c ON s.DistributorID = c.CompanyID
            LEFT JOIN Location loc ON c.LocationID = loc.LocationID
            [whereClause]
            GROUP BY c.TierLevel
            ORDER BY c.TierLevel

-- distributors.php: Lines 139-147
SELECT 
                DATE_FORMAT(s.PromisedDate, '%Y-%m') as month,
                COUNT(DISTINCT s.ShipmentID) as shipmentCount
              FROM Shipping s
              JOIN Company c ON s.DistributorID = c.CompanyID
              LEFT JOIN Location loc ON c.LocationID = loc.LocationID
              [whereClause]
              GROUP BY month
              ORDER BY month

-- distributors.php: Lines 154-165
SELECT 
                CASE 
                    WHEN s.ActualDate IS NULL THEN 'In Transit'
                    WHEN s.ActualDate <= s.PromisedDate THEN 'On Time'
                    ELSE 'Delayed'
                END as status,
                COUNT(*) as count
            FROM Shipping s
            JOIN Company c ON s.DistributorID = c.CompanyID
            LEFT JOIN Location loc ON c.LocationID = loc.LocationID
            [whereClause]
            GROUP BY status

-- distributors.php: Line 190
SELECT c.CompanyID, c.CompanyName FROM Company c JOIN Distributor d ON c.CompanyID = d.CompanyID ORDER BY c.CompanyName

-- distributors.php: Line 191
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName
