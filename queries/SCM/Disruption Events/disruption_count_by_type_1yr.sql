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



