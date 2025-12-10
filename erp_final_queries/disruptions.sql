-- disruptions.php: Lines 30-35
SELECT de.EventID, de.EventDate, de.EventRecoveryDate, dc.CategoryName, ic.ImpactLevel,
                CASE WHEN de.EventRecoveryDate IS NOT NULL THEN DATEDIFF(de.EventRecoveryDate, de.EventDate) ELSE NULL END as recoveryDays
            FROM DisruptionEvent de 
            JOIN ImpactsCompany ic ON de.EventID = ic.EventID
            JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID 
            WHERE ic.AffectedCompanyID = ?

-- disruptions.php: Line 40 (Dynamic Append)
 AND ic.ImpactLevel = ?

-- disruptions.php: Line 44 (Dynamic Append)
 AND de.EventDate >= ?

-- disruptions.php: Line 48 (Dynamic Append)
 AND de.EventDate <= ?

-- disruptions.php: Line 52 (Dynamic Append)
 ORDER BY de.EventDate DESC

-- disruptions.php: Lines 60-65
SELECT COUNT(*) as totalEvents, 
                              SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactCount,
                              AVG(CASE WHEN de.EventRecoveryDate IS NOT NULL THEN DATEDIFF(de.EventRecoveryDate, de.EventDate) ELSE NULL END) as avgRecoveryDays
                       FROM DisruptionEvent de 
                       JOIN ImpactsCompany ic ON de.EventID = ic.EventID 
                       WHERE ic.AffectedCompanyID = ?

-- disruptions.php: Line 70 (Dynamic Append)
 AND ic.ImpactLevel = ?

-- disruptions.php: Line 74 (Dynamic Append)
 AND de.EventDate >= ?

-- disruptions.php: Line 78 (Dynamic Append)
 AND de.EventDate <= ?

-- disruptions.php: Lines 100-104
SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.City, l.CountryName, l.ContinentName, ic.ImpactLevel
            FROM ImpactsCompany ic 
            JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
            LEFT JOIN Location l ON c.LocationID = l.LocationID 
            WHERE ic.EventID = ?

-- disruptions.php: Line 109 (Dynamic Append)
 AND ic.ImpactLevel = ?

-- disruptions.php: Line 112 (Dynamic Append)
 ORDER BY ic.ImpactLevel DESC, c.CompanyName

-- disruptions.php: Lines 120-123
SELECT COUNT(*) as totalCompanies, 
                              SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactCount
                       FROM ImpactsCompany ic 
                       WHERE ic.EventID = ?

-- disruptions.php: Line 128 (Dynamic Append)
 AND ic.ImpactLevel = ?

-- disruptions.php: Line 140
SELECT DATEDIFF(EventRecoveryDate, EventDate) as recoveryDays FROM DisruptionEvent WHERE EventID = ? AND EventRecoveryDate IS NOT NULL

-- disruptions.php: Line 154
SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName

-- disruptions.php: Line 155
SELECT de.EventID, de.EventDate, dc.CategoryName FROM DisruptionEvent de JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID ORDER BY de.EventDate DESC
