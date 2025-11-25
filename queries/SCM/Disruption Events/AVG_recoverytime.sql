SELECT
    c.CompanyID,
    c.CompanyName,
    l.ContinentName AS Region,
    c.Type AS CompanyType,
    AVG(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS AverageRecoveryTime_Days,
    COUNT(*) AS NumDisruptions
FROM ImpactsCompany ic
JOIN DisruptionEvent de 
    ON ic.EventID = de.EventID
JOIN Company c
    ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l
    ON c.LocationID = l.LocationID
WHERE de.EventRecoveryDate IS NOT NULL     -- only include recovered events
GROUP BY 
    c.CompanyID, c.CompanyName, l.ContinentName, c.Type
ORDER BY AverageRecoveryTime_Days DESC;
