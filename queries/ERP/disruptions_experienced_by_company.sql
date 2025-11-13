SELECT
    ic.AffectedCompanyID AS CompanyID,
    de.EventID,
    dc.CategoryName AS DisruptionCategory,
    de.EventDate,
    de.EventRecoveryDate,
    ic.ImpactLevel
FROM ImpactsCompany ic
JOIN DisruptionEvent de
    ON ic.EventID = de.EventID
JOIN DisruptionCategory dc
    ON de.CategoryID = dc.CategoryID
WHERE ic.AffectedCompanyID = 10
ORDER BY de.EventDate DESC;
