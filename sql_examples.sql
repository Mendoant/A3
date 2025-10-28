-- sql_examples.sql
-- SQL snippets adapted to the provided create_db.sql schema.
-- Replace :start_date, :end_date, :company_id placeholders with real values in prepared statements.

-- 1) Disruption Frequency (DF) per company over observation period (months)
SELECT
  ic.AffectedCompanyID AS CompanyID,
  COUNT(*) AS Ndisruptions,
  GREATEST(TIMESTAMPDIFF(MONTH, :start_date, :end_date), 1) AS MonthsObserved,
  COUNT(*) / GREATEST(TIMESTAMPDIFF(MONTH, :start_date, :end_date), 1) AS DF_per_month
FROM ImpactsCompany ic
JOIN DisruptionEvent de ON ic.EventID = de.EventID
WHERE de.EventDate BETWEEN :start_date AND :end_date
  AND ic.AffectedCompanyID = :company_id
GROUP BY ic.AffectedCompanyID;

-- 2) Average Recovery Time (ART) per company (in days)
SELECT
  ic.AffectedCompanyID AS CompanyID,
  AVG(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS ART_days,
  STDDEV_POP(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS ART_stddev_days
FROM ImpactsCompany ic
JOIN DisruptionEvent de ON ic.EventID = de.EventID
WHERE de.EventDate BETWEEN :start_date AND :end_date
  AND de.EventRecoveryDate IS NOT NULL
GROUP BY ic.AffectedCompanyID
ORDER BY ART_days DESC;

-- 3) High-Impact Disruption Rate (HDR) per company
SELECT
  ic.AffectedCompanyID AS CompanyID,
  SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) AS Nhighimpact,
  COUNT(*) AS Ndisruptions,
  100.0 * SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) / GREATEST(COUNT(*),1) AS HDR_pct
FROM ImpactsCompany ic
JOIN DisruptionEvent de ON ic.EventID = de.EventID
WHERE de.EventDate BETWEEN :start_date AND :end_date
GROUP BY ic.AffectedCompanyID;

-- 4) Total Downtime (TD) aggregate per company (in days)
SELECT
  ic.AffectedCompanyID AS CompanyID,
  SUM(DATEDIFF(de.EventRecoveryDate, de.EventDate)) AS total_downtime_days
FROM ImpactsCompany ic
JOIN DisruptionEvent de ON ic.EventID = de.EventID
WHERE de.EventDate BETWEEN :start_date AND :end_date
  AND de.EventRecoveryDate IS NOT NULL
GROUP BY ic.AffectedCompanyID
ORDER BY total_downtime_days DESC;

-- 5) Regional Risk Concentration (RRC) - fraction of disruptions by country (based on affected company location)
SELECT
  l.CountryName,
  COUNT(*) AS Ndisruptions_region,
  (COUNT(*) / SUM(COUNT(*)) OVER ()) AS RRC_fraction
FROM ImpactsCompany ic
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l ON c.LocationID = l.LocationID
JOIN DisruptionEvent de ON ic.EventID = de.EventID
WHERE de.EventDate BETWEEN :start_date AND :end_date
GROUP BY l.CountryName
ORDER BY Ndisruptions_region DESC;

-- 6) Disruption Severity Distribution (DSD) counts low/medium/high by region
SELECT
  l.CountryName,
  SUM(ic.ImpactLevel = 'Low') AS low_count,
  SUM(ic.ImpactLevel = 'Medium') AS medium_count,
  SUM(ic.ImpactLevel = 'High') AS high_count
FROM ImpactsCompany ic
JOIN Company c ON ic.AffectedCompanyID = c.CompanyID
JOIN Location l ON c.LocationID = l.LocationID
JOIN DisruptionEvent de ON ic.EventID = de.EventID
WHERE de.EventDate BETWEEN :start_date AND :end_date
GROUP BY l.CountryName;

-- 7) On-time delivery rate for shipments by distributor/company
SELECT
  s.DistributorID,
  SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) / GREATEST(COUNT(*),1) AS on_time_rate,
  AVG(DATEDIFF(s.ActualDate, s.PromisedDate)) AS avg_delay_days,
  STDDEV_POP(DATEDIFF(s.ActualDate, s.PromisedDate)) AS stddev_delay_days
FROM Shipping s
WHERE s.PromisedDate BETWEEN :start_date AND :end_date
GROUP BY s.DistributorID
ORDER BY on_time_rate DESC;

-- 8) Criticality score per company = (# Downstream companies affected) * HighImpactCount
SELECT
  c.CompanyID,
  COALESCE(dep.downstream_count, 0) * COALESCE(dis.high_impact_count, 0) AS criticality_score,
  COALESCE(dep.downstream_count, 0) AS downstream_count,
  COALESCE(dis.high_impact_count, 0) AS high_impact_count
FROM Company c
LEFT JOIN (
  SELECT UpstreamCompanyID, COUNT(DISTINCT DownstreamCompanyID) AS downstream_count
  FROM DependsOn
  GROUP BY UpstreamCompanyID
) dep ON c.CompanyID = dep.UpstreamCompanyID
LEFT JOIN (
  SELECT ic.AffectedCompanyID AS CompanyID, SUM(ic.ImpactLevel = 'High') AS high_impact_count
  FROM ImpactsCompany ic
  JOIN DisruptionEvent de ON ic.EventID = de.EventID
  WHERE de.EventDate BETWEEN :start_date AND :end_date
  GROUP BY ic.AffectedCompanyID
) dis ON c.CompanyID = dis.CompanyID
ORDER BY criticality_score DESC
LIMIT 50;
