--This file documents the SQL queries found in the regional_disruptions.php file. The lines where a query 
--is found will be commented prior to the exact code pulled from the file.

--Lines 25-35
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

--Lines 76-82
SELECT l.ContinentName as region, dc.CategoryName, COUNT(DISTINCT de.EventID) as eventCount
                FROM DisruptionEvent de
                JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
                JOIN ImpactsCompany ic ON de.EventID = ic.EventID
                JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
                JOIN Location l ON c.LocationID = l.LocationID
                WHERE de.EventDate BETWEEN :start AND :end

--Line 132
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName