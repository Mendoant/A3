-- disruptions.php: Lines 24-28
SELECT de.EventID, de.EventDate, de.EventRecoveryDate, dc.CategoryName, ic.ImpactLevel,
                CASE WHEN de.EventRecoveryDate IS NOT NULL THEN DATEDIFF(de.EventRecoveryDate, de.EventDate) ELSE NULL END as recoveryDays
            FROM DisruptionEvent de JOIN ImpactsCompany ic ON de.EventID = ic.EventID
            JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID WHERE ic.AffectedCompanyID = [company_id]

-- disruptions.php: Line 31
 AND ic.ImpactLevel = [impact_level]

-- disruptions.php: Line 32
 AND de.EventDate >= [start_date]

-- disruptions.php: Line 33
 AND de.EventDate <= [end_date]

-- disruptions.php: Line 34
 ORDER BY de.EventDate DESC

-- disruptions.php: Lines 38-40
SELECT COUNT(*) as totalEvents, SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactCount,
                    AVG(CASE WHEN de.EventRecoveryDate IS NOT NULL THEN DATEDIFF(de.EventRecoveryDate, de.EventDate) ELSE NULL END) as avgRecoveryDays
                FROM DisruptionEvent de JOIN ImpactsCompany ic ON de.EventID = ic.EventID WHERE ic.AffectedCompanyID = [company_id]

-- disruptions.php: Line 42
 AND ic.ImpactLevel = [impact_level]

-- disruptions.php: Line 43
 AND de.EventDate >= [start_date]

-- disruptions.php: Line 44
 AND de.EventDate <= [end_date]

-- disruptions.php: Lines 52-54
SELECT c.CompanyID, c.CompanyName, c.Type, c.TierLevel, l.City, l.CountryName, l.ContinentName, ic.ImpactLevel
            FROM ImpactsCompany ic JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
            LEFT JOIN Location l ON c.LocationID = l.LocationID WHERE ic.EventID = [event_id]

-- disruptions.php: Line 57
 AND ic.ImpactLevel = [impact_level]

-- disruptions.php: Line 58
 ORDER BY ic.ImpactLevel DESC, c.CompanyName

-- disruptions.php: Lines 62-63
SELECT COUNT(*) as totalCompanies, SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as highImpactCount
                FROM ImpactsCompany ic WHERE ic.EventID = [event_id]

-- disruptions.php: Line 65
 AND ic.ImpactLevel = [impact_level]

-- disruptions.php: Line 70
SELECT DATEDIFF(EventRecoveryDate, EventDate) as recoveryDays FROM DisruptionEvent WHERE EventID = [event_id] AND EventRecoveryDate IS NOT NULL

-- disruptions.php: Line 75
SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName

-- disruptions.php: Line 76
SELECT de.EventID, de.EventDate, dc.CategoryName FROM DisruptionEvent de JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID ORDER BY de.EventDate DESC
