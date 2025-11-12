-- This query shows information associated with Event 3. We still need to find a way to have a user input change the WHERE de.EVENTID = 3 line
SELECT 
    de.EventID,
    dc.CategoryName AS DisruptionCategory,
    de.EventDate,
    de.EventRecoveryDate,
    c.CompanyID,
    c.CompanyName,
    c.Type AS CompanyType,
    c.TierLevel,
    l.CountryName,
    l.ContinentName,
    ic.ImpactLevel
FROM DisruptionEvent de
JOIN DisruptionCategory dc 
    ON de.CategoryID = dc.CategoryID
JOIN ImpactsCompany ic 
    ON de.EventID = ic.EventID
JOIN Company c 
    ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l 
    ON c.LocationID = l.LocationID
WHERE de.EventID = 3
ORDER BY ic.ImpactLevel DESC, c.CompanyName;
