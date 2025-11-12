-- Query 1: Distribution of disruption event counts over the past year (last 365 days), grouped by Disruption Category.

SELECT
    dc.CategoryName,
    COUNT(de.EventID) AS EventCount
FROM
    DisruptionEvent de
JOIN
    DisruptionCategory dc ON de.CategoryID = dc.CategoryID
WHERE
    -- Filter events that occurred in the last year
    de.EventDate >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
GROUP BY
    dc.CategoryName
ORDER BY
    EventCount DESC;


-- Query 2: A detailed list of all disruption events that occurred over the past year,
-- including the category, date, recovery date, and which companies were impacted.

SELECT
    de.EventID,
    dc.CategoryName AS DisruptionType,
    de.EventDate,
    de.EventRecoveryDate,
    c.CompanyName AS AffectedCompany,
    ic.ImpactLevel
FROM
    DisruptionEvent de
JOIN
    DisruptionCategory dc ON de.CategoryID = dc.CategoryID
LEFT JOIN
    ImpactsCompany ic ON de.EventID = ic.EventID
LEFT JOIN
    Company c ON ic.AffectedCompanyID = c.CompanyID
WHERE
    -- Filter events that occurred in the last year
    de.EventDate >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
ORDER BY
    de.EventDate DESC, dc.CategoryName, c.CompanyName;
