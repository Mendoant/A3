-- Change ic.AffectedCompanyID, find a way for PHP to communicate this information directly to modulate the query
SELECT 
	ic.AffectedCompanyID As CompanyID,
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
WHERE ic.AffectedCompanyID = 10 --make sure to change
ORDER BY de.EventDate DESC;
