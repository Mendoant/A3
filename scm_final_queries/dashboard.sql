-- dashboard.php: Lines 15-33
SELECT 
    de.EventID,
    de.EventDate,
    de.EventRecoveryDate,
    dc.CategoryName,
    dc.Description as CategoryDescription,
    GROUP_CONCAT(DISTINCT c.CompanyName ORDER BY c.CompanyName SEPARATOR ', ') as AffectedCompanies,
    COUNT(DISTINCT ic.AffectedCompanyID) as CompanyCount,
    DATEDIFF(CURDATE(), de.EventDate) as DaysSinceStart,
    MAX(ic.ImpactLevel) as MaxImpact
FROM DisruptionEvent de
JOIN DisruptionCategory dc ON de.CategoryID = dc.CategoryID
JOIN ImpactsCompany ic ON de.EventID = ic.EventID
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
WHERE de.EventRecoveryDate IS NULL 
   OR de.EventRecoveryDate >= CURDATE()
GROUP BY de.EventID, de.EventDate, de.EventRecoveryDate, dc.CategoryName, dc.Description
ORDER BY de.EventDate DESC
LIMIT 10
