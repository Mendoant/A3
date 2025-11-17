SET @companyName = 'Boyd PLC';

SELECT 
    c.CompanyName,
    m.FactoryCapacity
FROM Company c
JOIN Manufacturer m 
    ON m.CompanyID = c.CompanyID
WHERE c.CompanyName = @companyName
  AND c.Type = 'Manufacturer';
