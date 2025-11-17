SET @companyName = 'Chavez Ltd';

SELECT
    c.CompanyName AS TargetCompany,          
    down.CompanyName AS DependentCompany    
FROM Company c
LEFT JOIN DependsOn d
    ON d.UpstreamCompanyID = c.CompanyID
LEFT JOIN Company down
    ON down.CompanyID = d.DownstreamCompanyID
WHERE c.CompanyName = @companyName;
