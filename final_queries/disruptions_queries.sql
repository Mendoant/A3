
--disruptions.php: lines 25-28
SELECT de.EventID, de.EventDate, de.EventRecoveryDate, dc.CategoryName, ic.ImpactLevel,
                CASE WHEN de.EventRecoveryDate IS NOT NULL THEN DATEDIFF(de.EventRecoveryDate, de.EventDate) ELSE NULL END as recoveryDays
            FROM DisruptionEvent de JOIN ImpactsCompany ic ON de.EventID = ic.EventID
            JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID WHERE ic.AffectedCompanyID = ?

--disruptions.php: lines 41-43
SELECT COUNT(*) as totalEvents, SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactCount,
                    AVG(CASE WHEN de.EventRecoveryDate IS NOT NULL THEN DATEDIFF(de.EventRecoveryDate, de.EventDate) ELSE NULL END) as avgRecoveryDays
                FROM DisruptionEvent de JOIN ImpactsCompany ic ON de.EventID = ic.EventID WHERE ic.AffectedCompanyID = ?

--disruptions.php: lines 59-61
SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.City, l.CountryName, l.ContinentName, ic.ImpactLevel
            FROM ImpactsCompany ic JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
            LEFT JOIN Location l ON c.LocationID = l.LocationID WHERE ic.EventID = ?

--disruptions.php: lines 72-72
SELECT COUNT(*) as totalCompanies, SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactCount
                FROM ImpactsCompany ic WHERE ic.EventID = ?

--disruptions.php: lines 85
SELECT DATEDIFF(EventRecoveryDate, EventDate) as recoveryDays FROM DisruptionEvent WHERE EventID = ? AND EventRecoveryDate IS NOT NULL

--disruptions.php: lines 91
SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName

--disruptions.php: lines 92
SELECT de.EventID, de.EventDate, dc.CategoryName FROM DisruptionEvent de JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID ORDER BY de.EventDate DESC
