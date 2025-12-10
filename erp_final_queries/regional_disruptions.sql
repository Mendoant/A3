-- regional_disruptions.php: Lines 22-32
SELECT l.ContinentName as region,
            COUNT(DISTINCT de.EventID) as totalDisruptions,
            SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactCount,
            SUM(CASE WHEN ic.ImpactLevel = 'Medium' THEN 1 ELSE 0 END) as mediumImpactCount,
            SUM(CASE WHEN ic.ImpactLevel = 'Low' THEN 1 ELSE 0 END) as lowImpactCount,
            COUNT(DISTINCT ic.AffectedCompanyID) as companiesAffected
        FROM DisruptionEvent de
        JOIN ImpactsCompany ic ON de.EventID = ic.EventID
        JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
        JOIN Location l ON c.LocationID = l.LocationID
        WHERE de.EventDate BETWEEN :start AND :end

-- regional_disruptions.php: Line 35 (Dynamic Append)
 AND ic.ImpactLevel = :impact

-- regional_disruptions.php: Line 39 (Dynamic Append)
 AND l.ContinentName = :region

-- regional_disruptions.php: Line 42 (Dynamic Append)
 GROUP BY l.ContinentName ORDER BY totalDisruptions DESC

-- regional_disruptions.php: Lines 51-57
SELECT l.ContinentName as region, dc.CategoryName, COUNT(DISTINCT de.EventID) as eventCount
                FROM DisruptionEvent de
                JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
                JOIN ImpactsCompany ic ON de.EventID = ic.EventID
                JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
                JOIN Location l ON c.LocationID = l.LocationID
                WHERE de.EventDate BETWEEN :start AND :end

-- regional_disruptions.php: Line 60 (Dynamic Append)
 AND ic.ImpactLevel = :impact

-- regional_disruptions.php: Line 63 (Dynamic Append)
 AND l.ContinentName = :region

-- regional_disruptions.php: Line 65 (Dynamic Append)
 GROUP BY l.ContinentName, dc.CategoryName ORDER BY eventCount DESC

-- regional_disruptions.php: Line 112
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName
