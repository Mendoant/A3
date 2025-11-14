SELECT
    c.CompanyName AS TargetCompany,          -- the company you input
    down.CompanyName AS DependentCompany    -- companies that depend on it
FROM Company c
LEFT JOIN DependsOn d
    ON d.UpstreamCompanyID = c.CompanyID
LEFT JOIN Company down
    ON down.CompanyID = d.DownstreamCompanyID
WHERE c.CompanyName = 'Chavez Ltd';
