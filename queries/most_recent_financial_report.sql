SELECT
    FR.CompanyID,
    C.CompanyName,
    FR.Quarter,
    FR.RepYear,
    FR.HealthScore
FROM
    FinancialReport AS FR
JOIN
    Company AS C ON FR.CompanyID = C.CompanyID
WHERE
    -- Use a placeholder for the ID of the company assigned to the current user.
    FR.CompanyID = 5
ORDER BY
    -- Sort by year descending (most recent year first)
    FR.RepYear DESC,
    -- Then sort by quarter descending (Q4, Q3, Q2, Q1)
    FR.Quarter DESC
LIMIT 1;
