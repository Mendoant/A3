SET @companyName = 'Chavez Ltd';

SELECT 
    c.CompanyName AS TargetCompany,
    up.CompanyName AS UpstreamDependency,
    down.CompanyName AS DownstreamDependency
FROM Company c
LEFT JOIN DependsOn d1 
    ON d1.DownstreamCompanyID = c.CompanyID
LEFT JOIN Company up 
    ON up.CompanyID = d1.UpstreamCompanyID
LEFT JOIN DependsOn d2
    ON d2.UpstreamCompanyID = c.CompanyID
LEFT JOIN Company down
    ON down.CompanyID = d2.DownstreamCompanyID
WHERE c.CompanyName = @companyName;
