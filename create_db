SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS OperatesLogistics;
DROP TABLE IF EXISTS ImpactsCompany;
DROP TABLE IF EXISTS SuppliesProduct;
DROP TABLE IF EXISTS DependsOn;
DROP TABLE IF EXISTS DisruptionEvent;
DROP TABLE IF EXISTS DisruptionCategory;
DROP TABLE IF EXISTS FinancialReport;
DROP TABLE IF EXISTS InventoryAdjustment;
DROP TABLE IF EXISTS Receiving;
DROP TABLE IF EXISTS Shipping;
DROP TABLE IF EXISTS InventoryTransaction;
DROP TABLE IF EXISTS Product;
DROP TABLE IF EXISTS Retailer;
DROP TABLE IF EXISTS Distributor;
DROP TABLE IF EXISTS Manufacturer;
DROP TABLE IF EXISTS Company;
DROP TABLE IF EXISTS Location;
DROP TABLE IF EXISTS User;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE User (
    UserID INT(8) AUTO_INCREMENT NOT NULL,
    FullName VARCHAR(100) NOT NULL,
    Username VARCHAR(50) NOT NULL UNIQUE,
    Password VARCHAR(255) NOT NULL,
    Role ENUM('SupplyChainManager','SeniorManager') NOT NULL,
    PRIMARY KEY (UserID)
) ENGINE=InnoDB;


CREATE TABLE Location (
    LocationID INT(5) AUTO_INCREMENT NOT NULL,
    CountryName VARCHAR(100) NOT NULL,
    ContinentName VARCHAR(50) NOT NULL,
    PRIMARY KEY (LocationID),
    UNIQUE (CountryName)
) ENGINE=InnoDB;

CREATE TABLE Company (
    CompanyID INT(8) AUTO_INCREMENT NOT NULL,
    CompanyName VARCHAR(100) NOT NULL UNIQUE,
    LocationID INT(5) NOT NULL,
    TierLevel ENUM('1','2','3') NOT NULL DEFAULT '3',
    Type ENUM('Manufacturer','Distributor','Retailer') NOT NULL,
    PRIMARY KEY (CompanyID),
    FOREIGN KEY (LocationID) REFERENCES Location(LocationID) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Manufacturer (
    CompanyID INT(8) NOT NULL,
    FactoryCapacity INT(10) NOT NULL DEFAULT 0,
    PRIMARY KEY (CompanyID),
    FOREIGN KEY (CompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Distributor (
    CompanyID INT(8) NOT NULL,
    PRIMARY KEY (CompanyID),
    FOREIGN KEY (CompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Retailer (
    CompanyID INT(8) NOT NULL,
    PRIMARY KEY (CompanyID),
    FOREIGN KEY (CompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Product (
    ProductID INT(10) AUTO_INCREMENT NOT NULL,
    ProductName VARCHAR(100) NOT NULL UNIQUE,
    Category ENUM('Electronics','Raw Material','Component','Finished Good','Other') NOT NULL,
    PRIMARY KEY (ProductID)
) ENGINE=InnoDB;

CREATE TABLE InventoryTransaction (
    TransactionID INT(10) AUTO_INCREMENT NOT NULL,
    Type ENUM('Shipping','Receiving','Adjustment') NOT NULL,
    PRIMARY KEY (TransactionID)
) ENGINE=InnoDB;

CREATE TABLE Shipping (
    ShipmentID INT(10) AUTO_INCREMENT NOT NULL,
    TransactionID INT(10) NOT NULL,
    DistributorID INT(8) NOT NULL,
    ProductID INT(10) NOT NULL,
    SourceCompanyID INT(8) NOT NULL,
    DestinationCompanyID INT(8) NOT NULL,
    PromisedDate DATE NOT NULL,
    ActualDate DATE,
    Quantity INT NOT NULL,
    PRIMARY KEY (ShipmentID),
    FOREIGN KEY (TransactionID) REFERENCES InventoryTransaction(TransactionID) ON UPDATE CASCADE,
    FOREIGN KEY (DistributorID) REFERENCES Distributor(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (ProductID) REFERENCES Product(ProductID) ON UPDATE CASCADE,
    FOREIGN KEY (SourceCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (DestinationCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE Receiving (
    ReceivingID INT(10) AUTO_INCREMENT NOT NULL,
    TransactionID INT(10) NOT NULL,
    ShipmentID INT(10) NOT NULL,
    ReceiverCompanyID INT(8) NOT NULL,
    ReceivedDate DATE NOT NULL,
    QuantityReceived INT NOT NULL,
    PRIMARY KEY (ReceivingID),
    FOREIGN KEY (TransactionID) REFERENCES InventoryTransaction(TransactionID) ON UPDATE CASCADE,
    FOREIGN KEY (ShipmentID) REFERENCES Shipping(ShipmentID) ON UPDATE CASCADE,
    FOREIGN KEY (ReceiverCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE InventoryAdjustment (
    AdjustmentID INT(10) AUTO_INCREMENT NOT NULL,
    TransactionID INT(10) NOT NULL,
    CompanyID INT(8) NOT NULL,
    ProductID INT(10) NOT NULL,
    AdjustmentDate DATE NOT NULL,
    QuantityChange INT NOT NULL,
    Reason VARCHAR(100),
    PRIMARY KEY (AdjustmentID),
    FOREIGN KEY (TransactionID) REFERENCES InventoryTransaction(TransactionID) ON UPDATE CASCADE,
    FOREIGN KEY (CompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (ProductID) REFERENCES Product(ProductID) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE FinancialReport (
    CompanyID INT(8) NOT NULL,
    Quarter ENUM('Q1','Q2','Q3','Q4') NOT NULL,
    RepYear YEAR NOT NULL,
    HealthScore DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (CompanyID, Quarter, RepYear),
    FOREIGN KEY (CompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE DisruptionCategory (
    CategoryID INT(10) AUTO_INCREMENT NOT NULL,
    CategoryName VARCHAR(100) NOT NULL,
    Description VARCHAR(255),
    PRIMARY KEY (CategoryID),
    UNIQUE (CategoryName)
) ENGINE=InnoDB;

CREATE TABLE DisruptionEvent (
    EventID INT(10) AUTO_INCREMENT NOT NULL,
    EventDate DATE NOT NULL,
    EventRecoveryDate DATE NULL,
    CategoryID INT(10) NOT NULL,
    PRIMARY KEY (EventID),
    FOREIGN KEY (CategoryID) REFERENCES DisruptionCategory(CategoryID) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE DependsOn (
    UpstreamCompanyID INT(8) NOT NULL,
    DownstreamCompanyID INT(8) NOT NULL,
    PRIMARY KEY (UpstreamCompanyID, DownstreamCompanyID),
    FOREIGN KEY (UpstreamCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (DownstreamCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE SuppliesProduct (
    SupplierID INT(8) NOT NULL,
    ProductID INT(10) NOT NULL,
    SupplyPrice DECIMAL(10,2),
    PRIMARY KEY (SupplierID, ProductID),
    FOREIGN KEY (SupplierID) REFERENCES Company(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (ProductID) REFERENCES Product(ProductID) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ImpactsCompany (
    EventID INT(10) NOT NULL,
    AffectedCompanyID INT(8) NOT NULL,
    ImpactLevel ENUM('Low','Medium','High') NOT NULL,
    PRIMARY KEY (EventID, AffectedCompanyID),
    FOREIGN KEY (EventID) REFERENCES DisruptionEvent(EventID) ON UPDATE CASCADE,
    FOREIGN KEY (AffectedCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE OperatesLogistics (
    DistributorID INT(8) NOT NULL,
    FromCompanyID INT(8) NOT NULL,
    ToCompanyID INT(8) NOT NULL,
    PRIMARY KEY (DistributorID, FromCompanyID, ToCompanyID),
    FOREIGN KEY (DistributorID) REFERENCES Distributor(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (FromCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (ToCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
) ENGINE=InnoDB;
