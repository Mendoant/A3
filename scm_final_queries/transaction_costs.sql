-- transaction_costs.php: Lines 58-85
SELECT 
            s.ShipmentID,
            s.PromisedDate,
            s.ActualDate,
            s.Quantity,
            p.ProductName,
            p.Category,
            source.CompanyName as SourceCompany,
            dest.CompanyName as DestCompany,
            (s.Quantity * 2.5) as shippingCost,
            CASE 
                WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                ELSE 0 
            END as delayPenalty,
            (s.Quantity * 2.5) + 
            CASE 
                WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                ELSE 0 
            END as totalCost
        FROM Shipping s
        JOIN Product p ON s.ProductID = p.ProductID
        JOIN Company source ON s.SourceCompanyID = source.CompanyID
        JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
        [joins]
        [whereClause]
        ORDER BY s.PromisedDate DESC

-- transaction_costs.php: Lines 92-127
SELECT 
                SUM(s.Quantity * 2.5) as totalShippingCost,
                SUM(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                    THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                    ELSE 0 
                END) as totalDelayPenalty,
                SUM((s.Quantity * 2.5) + 
                    CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                        ELSE 0 
                    END) as totalCost,
                AVG((s.Quantity * 2.5) + 
                    CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                        ELSE 0 
                    END) as avgCostPerShipment,
                MAX((s.Quantity * 2.5) + 
                    CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                        ELSE 0 
                    END) as maxCost,
                SUM(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                    THEN 1 
                    ELSE 0 
                END) as delayedCount
            FROM Shipping s
            JOIN Product p ON s.ProductID = p.ProductID
            JOIN Company source ON s.SourceCompanyID = source.CompanyID
            JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
            [joins]
            [whereClause]

-- transaction_costs.php: Lines 141-157
SELECT 
                    p.Category,
                    COUNT(DISTINCT s.ShipmentID) as shipmentCount,
                    SUM(s.Quantity * 2.5) as categoryShippingCost,
                    SUM(CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                        ELSE 0 
                    END) as categoryDelayPenalty
                FROM Shipping s
                JOIN Product p ON s.ProductID = p.ProductID
                JOIN Company source ON s.SourceCompanyID = source.CompanyID
                JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
                [joins]
                [whereClause]
                GROUP BY p.Category
                ORDER BY categoryShippingCost DESC

-- transaction_costs.php: Lines 164-178
SELECT 
                DATE_FORMAT(s.PromisedDate, '%Y-%m') as month,
                SUM(s.Quantity * 2.5) as monthlyShipping,
                SUM(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                    THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                    ELSE 0 
                END) as monthlyPenalty
             FROM Shipping s
             JOIN Company source ON s.SourceCompanyID = source.CompanyID
             JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
             [joins]
             [whereClause]
             GROUP BY month
             ORDER BY month ASC

-- transaction_costs.php: Lines 185-202
SELECT 
                COALESCE(srcLoc.ContinentName, 'Unknown') as region,
                SUM(s.Quantity * 2.5) as regionShippingCost,
                SUM(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                    THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                    ELSE 0 
                END) as regionDelayPenalty,
                COUNT(DISTINCT s.ShipmentID) as shipmentCount
              FROM Shipping s
              JOIN Product p ON s.ProductID = p.ProductID
              JOIN Company source ON s.SourceCompanyID = source.CompanyID
              JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
              LEFT JOIN Location srcLoc ON source.LocationID = srcLoc.LocationID
              LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID
              [whereClause]
              GROUP BY srcLoc.ContinentName
              ORDER BY regionShippingCost DESC

-- transaction_costs.php: Lines 209-225
SELECT 
                CAST(CONCAT('Tier ', source.TierLevel) AS CHAR CHARACTER SET utf8mb4) as tier,
                SUM(s.Quantity * 2.5) as tierShippingCost,
                SUM(CASE 
                    WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                    THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                    ELSE 0 
                END) as tierDelayPenalty,
                COUNT(DISTINCT s.ShipmentID) as shipmentCount
            FROM Shipping s
            JOIN Product p ON s.ProductID = p.ProductID
            JOIN Company source ON s.SourceCompanyID = source.CompanyID
            JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
            [joins]
            [whereClause]
            GROUP BY source.TierLevel
            ORDER BY source.TierLevel

-- transaction_costs.php: Lines 232-251
SELECT 
                CONCAT(CAST(source.CompanyName AS CHAR CHARACTER SET utf8mb4), ' → ', CAST(dest.CompanyName AS CHAR CHARACTER SET utf8mb4)) as route,
                source.CompanyName as sourceCompany,
                dest.CompanyName as destCompany,
                COUNT(DISTINCT s.ShipmentID) as shipmentCount,
                SUM((s.Quantity * 2.5) + 
                    CASE 
                        WHEN s.ActualDate IS NOT NULL AND s.ActualDate > s.PromisedDate 
                        THEN DATEDIFF(s.ActualDate, s.PromisedDate) * 10
                        ELSE 0 
                    END) as routeTotalCost
             FROM Shipping s
             JOIN Product p ON s.ProductID = p.ProductID
             JOIN Company source ON s.SourceCompanyID = source.CompanyID
             JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
             [joins]
             [whereClause]
             GROUP BY source.CompanyID, dest.CompanyID, source.CompanyName, dest.CompanyName
             ORDER BY routeTotalCost DESC
             LIMIT 10

-- transaction_costs.php: Line 284
SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName

-- transaction_costs.php: Line 285
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName
