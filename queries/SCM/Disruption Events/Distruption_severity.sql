-- User-defined list of companies
-- Example: (1, 3, 7) or (101, 202, 305)

SELECT
    c.CompanyID,
    c.CompanyName,
    
    -- Severity counts
    SUM(ic.ImpactLevel = 'Low')    AS LowCount,
    SUM(ic.ImpactLevel = 'Medium') AS MediumCount,
    SUM(ic.ImpactLevel = 'High')   AS HighCount,
    
    COUNT(*) AS TotalDisruptions
FROM ImpactsCompany ic
JOIN Company c
    ON c.CompanyID = ic.AffectedCompanyID
WHERE ic.AffectedCompanyID IN (1, 3, 7)     -- <-- insert your company IDs
GROUP BY
    c.CompanyID,
    c.CompanyName
ORDER BY
    c.CompanyName;
