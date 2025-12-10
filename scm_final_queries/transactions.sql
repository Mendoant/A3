-- transactions.php: Lines 29-37 (Used to update tables when transaction is recorded)
UPDATE Shipping SET 
                    ProductID = :product,
                    SourceCompanyID = :source,
                    DestinationCompanyID = :destination,
                    DistributorID = :distributor,
                    Quantity = :quantity, 
                    PromisedDate = :promised, 
                    ActualDate = :actual 
                    WHERE ShipmentID = :id

-- transactions.php: Lines 54-59 (Used to update tables when transaction is recorded)
UPDATE Receiving SET 
                    ShipmentID = :shipment,
                    ReceiverCompanyID = :receiver,
                    QuantityReceived = :quantity, 
                    ReceivedDate = :date 
                    WHERE ReceivingID = :id

-- transactions.php: Lines 62-67 (Used to update tables when transaction is recorded)
UPDATE Receiving SET 
                    ShipmentID = :shipment,
                    ReceiverCompanyID = :receiver,
                    QuantityReceived = :quantity, 
                    ReceivedDate = :date 
                    WHERE ReceivingID = :id

-- transactions.php: Lines 89-95 (Used to update tables when transaction is recorded)
UPDATE InventoryAdjustment SET 
                    CompanyID = :company,
                    ProductID = :product,
                    QuantityChange = :quantity, 
                    AdjustmentDate = :date, 
                    Reason = :reason 
                    WHERE AdjustmentID = :id

-- transactions.php: Lines 120-122
SELECT COUNT(*) as total
                         FROM Shipping s
                         JOIN Company src ON s.SourceCompanyID = src.CompanyID
                         JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID

-- transactions.php: Lines 148-149
LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID
  
-- transactions.php: Lines 152
WHERE s.PromisedDate BETWEEN :start AND :end

-- transactions.php: Lines 155
AND (s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)

-- transactions.php: Lines 158
AND (srcLoc.ContinentName = :region OR destLoc.ContinentName = :region)

-- transactions.php: Lines 161
AND (src.TierLevel = :tier OR dest.TierLevel = :tier)

-- transactions.php: Lines 171-182
SELECT s.ShipmentID, s.PromisedDate, s.ActualDate, s.Quantity,
                           s.ProductID, s.SourceCompanyID, s.DestinationCompanyID, s.DistributorID,
                           p.ProductName, p.Category,
                           src.CompanyName as SourceCompany,
                           dest.CompanyName as DestCompany,
                           dist.CompanyName as DistributorName
                    FROM Shipping s
                    JOIN Product p ON s.ProductID = p.ProductID
                    JOIN Company src ON s.SourceCompanyID = src.CompanyID
                    JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID
                    LEFT JOIN Distributor d ON s.DistributorID = d.CompanyID
                    LEFT JOIN Company dist ON d.CompanyID = dist.CompanyID

-- transactions.php: Lines 186-187
LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID

-- transactions.php: Lines 190
WHERE s.PromisedDate BETWEEN :start AND :end

-- transactions.php: Lines 194
AND (s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)

-- transactions.php: Lines 199
AND (srcLoc.ContinentName = :region OR destLoc.ContinentName = :region)

-- transactions.php: Lines 204
AND (src.TierLevel = :tier OR dest.TierLevel = :tier)
  
-- transactions.php: Lines 207
ORDER BY s.PromisedDate DESC LIMIT :limit OFFSET :offset

-- transactions.php: Lines 233-237
SELECT COUNT(*) as total
                          FROM Receiving r
                          JOIN Shipping s ON r.ShipmentID = s.ShipmentID
                          JOIN Company src ON s.SourceCompanyID = src.CompanyID
                          JOIN Company recv ON r.ReceiverCompanyID = recv.CompanyID

-- transactions.php: Lines 240-241
LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
LEFT JOIN Location recvLoc ON recv.LocationID = recvLoc.LocationID  

-- transactions.php: Lines 244
WHERE r.ReceivedDate BETWEEN :start AND :end

-- transactions.php: Lines 247
AND (s.SourceCompanyID = :companyID OR r.ReceiverCompanyID = :companyID)

-- transactions.php: Lines 250
AND (srcLoc.ContinentName = :region OR recvLoc.ContinentName = :region)

-- transactions.php: Lines 253
AND (src.TierLevel = :tier OR recv.TierLevel = :tier)
  
-- transactions.php: Lines 263-274
SELECT r.ReceivingID, r.ReceivedDate, r.QuantityReceived,
                            r.ShipmentID, r.ReceiverCompanyID,
                            s.ProductID,
                            p.ProductName, p.Category,
                            s.SourceCompanyID,
                            src.CompanyName as SourceCompany,
                            recv.CompanyName as ReceiverCompany
                     FROM Receiving r
                     JOIN Shipping s ON r.ShipmentID = s.ShipmentID
                     JOIN Product p ON s.ProductID = p.ProductID
                     JOIN Company src ON s.SourceCompanyID = src.CompanyID
                     JOIN Company recv ON r.ReceiverCompanyID = recv.CompanyID
                     
-- transactions.php: Lines 278-279
LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
LEFT JOIN Location recvLoc ON recv.LocationID = recvLoc.LocationID
  
-- transactions.php: Lines 282
WHERE r.ReceivedDate BETWEEN :start AND :end

-- transactions.php: Lines 286
AND (s.SourceCompanyID = :companyID OR r.ReceiverCompanyID = :companyID)

-- transactions.php: Lines 291
AND (srcLoc.ContinentName = :region OR recvLoc.ContinentName = :region)

-- transactions.php: Lines 296
AND (src.TierLevel = :tier OR recv.TierLevel = :tier)

 -- transactions.php: Lines 296 
ORDER BY r.ReceivedDate DESC LIMIT :limit OFFSET :offset

-- transactions.php: Lines 311-313
SELECT COUNT(*) as total
                            FROM InventoryAdjustment ia
                            JOIN Company c ON ia.CompanyID = c.CompanyID
  
-- transactions.php: Lines 316 
LEFT JOIN Location loc ON c.LocationID = loc.LocationID

-- transactions.php: Lines 319
WHERE ia.AdjustmentDate BETWEEN :start AND :end
  
-- transactions.php: Lines 322
AND ia.CompanyID = :companyID

-- transactions.php: Lines 322
AND loc.ContinentName = :region

-- transactions.php: Lines 322
AND c.TierLevel = :tier
  
-- transactions.php: Lines 338-344
SELECT ia.AdjustmentID, ia.AdjustmentDate, ia.QuantityChange, ia.Reason,
                              ia.CompanyID, ia.ProductID,
                              p.ProductName, p.Category,
                              c.CompanyName
                       FROM InventoryAdjustment ia
                       JOIN Product p ON ia.ProductID = p.ProductID
                       JOIN Company c ON ia.CompanyID = c.CompanyID

-- transactions.php: Lines 348
LEFT JOIN Location Loc ON src.LocationID = Loc.LocationID

-- transactions.php: Lines 351
WHERE ia.AdjustmentDate BETWEEN :start AND :end

-- transactions.php: Lines 355
AND ia.CompanyID = :companyID

-- transactions.php: Lines 360
AND loc.ContinentName = :region

-- transactions.php: Lines 365
AND c.TierLevel = :tier

-- transactions.php: Lines 368
ORDER BY ia.AdjustmentDate DESC LIMIT :limit OFFSET :offset

-- transactions.php: Lines 407-412
SELECT 
                    DATE_FORMAT(s.PromisedDate, '%Y-%m') as month,
                    COUNT(*) as shipment_count
                  FROM Shipping s
                  JOIN Company src ON s.SourceCompanyID = src.CompanyID
                  JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID

  -- transactions.php: Lines 415-416
LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID
  
-- transactions.php: Lines 419
WHERE s.PromisedDate BETWEEN :start AND :end

-- transactions.php: Lines 422
AND (s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)

-- transactions.php: Lines 422
AND (srcLoc.ContinentName = :region OR destLoc.ContinentName = :region)

-- transactions.php: Lines 428
AND (src.TierLevel = :tier OR dest.TierLevel = :tier)

-- transactions.php: Lines 431
GROUP BY month ORDER BY month

-- transactions.php: Lines 438-444
SELECT 
                        DATE_FORMAT(s.PromisedDate, '%Y-%m') as month,
                        COUNT(*) as total,
                        SUM(CASE WHEN s.ActualDate IS NOT NULL AND s.ActualDate <= s.PromisedDate THEN 1 ELSE 0 END) as onTime
                      FROM Shipping s
                      JOIN Company src ON s.SourceCompanyID = src.CompanyID
                      JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID

-- transactions.php: Lines 447-448
LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID

-- transactions.php: Lines 447-448
WHERE s.PromisedDate BETWEEN :start AND :end AND s.ActualDate IS NOT NULL

-- transactions.php: Lines 454
AND (s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)

-- transactions.php: Lines 457
AND (srcLoc.ContinentName = :region OR destLoc.ContinentName = :region)

-- transactions.php: Lines 460
AND (src.TierLevel = :tier OR dest.TierLevel = :tier)

-- transactions.php: Lines 463
GROUP BY month ORDER BY month

-- transactions.php: Lines 470-478
SELECT 
                        p.ProductName,
                        p.Category,
                        COUNT(*) as shipment_count,
                        SUM(s.Quantity) as total_quantity
                    FROM Shipping s
                    JOIN Product p ON s.ProductID = p.ProductID
                    JOIN Company src ON s.SourceCompanyID = src.CompanyID
                    JOIN Company dest ON s.DestinationCompanyID = dest.CompanyID

-- transactions.php: Lines 481-482
LEFT JOIN Location srcLoc ON src.LocationID = srcLoc.LocationID
LEFT JOIN Location destLoc ON dest.LocationID = destLoc.LocationID

-- transactions.php: Lines 485
WHERE s.PromisedDate BETWEEN :start AND :end

-- transactions.php: Lines 488
AND (s.SourceCompanyID = :companyID OR s.DestinationCompanyID = :companyID)

-- transactions.php: Lines 491
AND (srcLoc.ContinentName = :region OR destLoc.ContinentName = :region)

-- transactions.php: Lines 494
AND (src.TierLevel = :tier OR dest.TierLevel = :tier)

-- transactions.php: Lines 497-499
GROUP BY p.ProductID, p.ProductName, p.Category 
                      ORDER BY total_quantity DESC 
                      LIMIT 10

-- transactions.php: Lines 509-511
SELECT 
                        DATE_FORMAT(de.EventDate, '%Y-%m') as month,
                        COUNT(DISTINCT de.EventID) as total_disruptions,
                        SUM(CASE WHEN ic.ImpactLevel = 'High' THEN 1 ELSE 0 END) as high_impact
                      FROM DisruptionEvent de
                      LEFT JOIN ImpactsCompany ic ON de.EventID = ic.EventID

-- transactions.php: Lines 514
LEFT JOIN Company c ON ic.AffectedCompanyID = c.CompanyID

-- transactions.php: Lines 514
LEFT JOIN Location loc ON c.LocationID = loc.LocationID

-- transactions.php: Lines 521
WHERE de.EventDate BETWEEN :start AND :end

-- transactions.php: Lines 525
AND ic.AffectedCompanyID = :companyID
  
-- transactions.php: Lines 527
AND loc.ContinentName = :region

-- transactions.php: Lines 530
AND c.TierLevel = :tier

-- transactions.php: Lines 533
GROUP BY month ORDER BY month

-- transactions.php: Line 594
SELECT CompanyID, CompanyName FROM Company ORDER BY CompanyName

-- transactions.php: Line 595
SELECT DISTINCT ContinentName FROM Location ORDER BY ContinentName

-- transactions.php: Line 598
SELECT ProductID, ProductName, Category FROM Product ORDER BY ProductName

-- transactions.php: Line 601
SELECT c.CompanyID, c.CompanyName FROM Distributor d JOIN Company c ON d.CompanyID = c.CompanyID ORDER BY c.CompanyName

-- transactions.php: Lines 604-609
SELECT s.ShipmentID, p.ProductName, c.CompanyName as SourceCompany, s.ProductID, s.SourceCompanyID 
                                 FROM Shipping s 
                                 JOIN Product p ON s.ProductID = p.ProductID 
                                 JOIN Company c ON s.SourceCompanyID = c.CompanyID 
                                 ORDER BY s.ShipmentID DESC 
                                 LIMIT 500
