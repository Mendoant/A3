-- Query 1: Calculate Disruption Exposure Score for a Company over a User-Defined Time Period
-- The score is defined as: (Total Disruptions) + 2 * (High Impact Events)
-- Parameters required for this query:
-- :company_id (INT) - The ID of the company being analyzed
-- :start_date (DATE) - The start date of the time period (inclusive)
-- :end_date (DATE) - The end date of the time period (inclusive)

SELECT
    c.CompanyName,
    -- Calculate the exposure score: (Total Disruptions) + 2 * (High Impact Events)
    -- SUM(CASE WHEN ia.ImpactLevel IN ('Low', 'Medium', 'High') THEN 1 ELSE 0 END) is the total count of disruptions.
    -- SUM(CASE WHEN ia.ImpactLevel = 'High' THEN 2 ELSE 0 END) is the weighted count of High impact events.
    COALESCE(SUM(CASE
        WHEN ia.ImpactLevel = 'High' THEN 3  -- 1 for total + 2 for high impact = 3
        WHEN ia.ImpactLevel IN ('Low', 'Medium') THEN 1 -- 1 for total
        ELSE 0
    END), 0) AS DisruptionExposureScore
FROM
    Company c
LEFT JOIN
    ImpactsCompany ia ON c.CompanyID = ia.AffectedCompanyID
LEFT JOIN
    DisruptionEvent de ON ia.EventID = de.EventID
WHERE
    c.CompanyID = :company_id
    AND de.EventDate BETWEEN :start_date AND :end_date
GROUP BY
    c.CompanyName;

-- Note on Query 1: The calculation is simplified using a single CASE statement:
-- If Impact is 'High': Count as 3 (1 for total + 2 for high weight)
-- If Impact is 'Low' or 'Medium': Count as 1 (1 for total)
-- This approach satisfies the definition: (Total Disruptions) + 2 * (High Impact Events).