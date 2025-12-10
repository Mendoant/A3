-- distributors.php: Lines 55-65
SELECT d.CompanyID, c.CompanyName, c.TierLevel, l.ContinentName,
                    COUNT(s.ShipmentID) as TotalShipments,
                    SUM(CASE WHEN s.Quantity IS NOT NULL THEN s.Quantity ELSE 0 END) as TotalQuantity,
                    SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as OnTimeCount,
                    AVG(CASE WHEN s.ActualDate > s.PromisedDate THEN DATEDIFF(s.ActualDate, s.PromisedDate) ELSE NULL END) as AvgDelay
                FROM Distributor d
                JOIN Company c ON d.CompanyID = c.CompanyID
                JOIN Location l ON c.LocationID = l.LocationID
                LEFT JOIN Shipping s ON d.CompanyID = s.DistributorID
                [whereClause]
                GROUP BY d.CompanyID, c.CompanyName, c.TierLevel, l.ContinentName

-- distributors.php: Line 37 (Dynamic Append, decided based on previous inputs)
 AND d.CompanyID = ?

-- distributors.php: Line 43 (Dynamic Append, decided based on previous inputs)
 AND l.ContinentName = ?

-- distributors.php: Line 49 (Dynamic Append, decided based on previous inputs)
 AND c.TierLevel = ?

-- distributors.php: Line 177
SELECT d.CompanyID, c.CompanyName FROM Distributor d JOIN Company c ON d.CompanyID = c.CompanyID ORDER BY c.CompanyName

-- distributors.php: Line 181
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName
