-- Put your company IDs here
-- e.g. (1, 3, 7) or (101, 202, 305)
SELECT
    c.CompanyID,
    c.CompanyName,
    SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS TotalDowntime_Days,
    COUNT(*) AS NumDisruptionsCounted
FROM ImpactsCompany ic
JOIN DisruptionEvent de
    ON de.EventID = ic.EventID
JOIN Company c
    ON c.CompanyID = ic.AffectedCompanyID
WHERE ic.AffectedCompanyID IN (1, 3, 7)   -- <-- user-defined company list
  AND de.EventRecoveryDate IS NOT NULL    -- only disruptions with known recovery
GROUP BY
    c.CompanyID,
    c.CompanyName
ORDER BY
    TotalDowntime_Days DESC;
