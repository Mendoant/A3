SET @start_date := '2025-01-01';
SET @end_date   := '2025-12-31';

SELECT
  c.CompanyID AS UpstreamCompanyID,
  c.CompanyName,
  c.Type AS CompanyType,
  l.ContinentName AS Region,
  COALESCE(dsa.DownstreamCompaniesAffected, 0) AS DownstreamCompaniesAffected,
  COALESCE(hic.HighImpactCount, 0)            AS HighImpactCount,
  COALESCE(dsa.DownstreamCompaniesAffected, 0) * COALESCE(hic.HighImpactCount, 0) AS Criticality
FROM Company c
JOIN Location l 
  ON c.LocationID = l.LocationID
LEFT JOIN (
    SELECT d.UpstreamCompanyID,
           COUNT(DISTINCT d.DownstreamCompanyID) AS DownstreamCompaniesAffected
    FROM DependsOn d
    JOIN (
        SELECT ic.EventID, ic.AffectedCompanyID, ic.ImpactLevel
        FROM ImpactsCompany ic
        JOIN DisruptionEvent de ON de.EventID = ic.EventID
        WHERE de.EventDate BETWEEN @start_date AND @end_date
    ) di ON di.AffectedCompanyID = d.DownstreamCompanyID
    GROUP BY d.UpstreamCompanyID
) dsa ON dsa.UpstreamCompanyID = c.CompanyID
LEFT JOIN (
    SELECT d.UpstreamCompanyID,
           COUNT(*) AS HighImpactCount
    FROM DependsOn d
    JOIN (
        SELECT ic.EventID, ic.AffectedCompanyID, ic.ImpactLevel
        FROM ImpactsCompany ic
        JOIN DisruptionEvent de ON de.EventID = ic.EventID
        WHERE de.EventDate BETWEEN @start_date AND @end_date
    ) di ON di.AffectedCompanyID = d.DownstreamCompanyID
    WHERE di.ImpactLevel = 'High'
    GROUP BY d.UpstreamCompanyID
) hic ON hic.UpstreamCompanyID = c.CompanyID
ORDER BY Criticality DESC, c.CompanyName;
