SET @companyName = 'Davis PLC';

SELECT
    d.CompanyName AS DistributorName,
    fromC.CompanyName AS FromCompany,
    toC.CompanyName AS ToCompany
FROM Company d
JOIN Distributor dist
    ON dist.CompanyID = d.CompanyID
JOIN OperatesLogistics ol
    ON ol.DistributorID = d.CompanyID
JOIN Company fromC
    ON fromC.CompanyID = ol.FromCompanyID
JOIN Company toC
    ON toC.CompanyID = ol.ToCompanyID
WHERE d.CompanyName = @companyName;
