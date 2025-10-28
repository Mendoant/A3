import random
from faker import Faker
from datetime import datetime, timedelta

fake = Faker()

# ---------------------------------------------
# CONFIGURATION
# ---------------------------------------------
NUM_LOCATIONS = 50
NUM_COMPANIES = 500
NUM_PRODUCTS = 300
NUM_SHIPMENTS = 2000
NUM_RECEIVINGS = 1500
NUM_ADJUSTMENTS = 800
NUM_DISRUPTIONS = 120
NUM_DEPENDENCIES = 600
NUM_SUPPLIES = 700
NUM_IMPACTS = 400
NUM_LOGISTICS = 500
NUM_FINANCIALS = 1500

# ---------------------------------------------
# LOCATION MODEL (with aligned cities/continents)
# ---------------------------------------------
locations_map = {
    "North America": [
        ("United States", ["New York", "Los Angeles", "Chicago", "Dallas", "Houston", "Atlanta", "Seattle"]),
        ("Canada", ["Toronto", "Vancouver", "Montreal", "Calgary", "Ottawa"]),
        ("Mexico", ["Mexico City", "Monterrey", "Guadalajara"])
    ],
    "Europe": [
        ("Germany", ["Berlin", "Munich", "Hamburg", "Frankfurt", "Cologne"]),
        ("France", ["Paris", "Lyon", "Marseille", "Toulouse", "Bordeaux"]),
        ("United Kingdom", ["London", "Manchester", "Birmingham", "Glasgow", "Liverpool"]),
        ("Italy", ["Rome", "Milan", "Naples", "Turin"]),
        ("Spain", ["Madrid", "Barcelona", "Valencia", "Seville"])
    ],
    "Asia": [
        ("China", ["Beijing", "Shanghai", "Shenzhen", "Guangzhou", "Chengdu"]),
        ("India", ["Mumbai", "Delhi", "Bangalore", "Chennai", "Hyderabad"]),
        ("Japan", ["Tokyo", "Osaka", "Nagoya", "Yokohama", "Fukuoka"]),
        ("South Korea", ["Seoul", "Busan", "Incheon"]),
        ("Singapore", ["Singapore"])
    ],
    "South America": [
        ("Brazil", ["São Paulo", "Rio de Janeiro", "Brasília", "Curitiba"]),
        ("Argentina", ["Buenos Aires", "Córdoba", "Rosario"]),
        ("Chile", ["Santiago", "Valparaíso", "Concepción"]),
        ("Colombia", ["Bogotá", "Medellín", "Cali"])
    ],
    "Africa": [
        ("South Africa", ["Johannesburg", "Cape Town", "Durban", "Pretoria"]),
        ("Nigeria", ["Lagos", "Abuja", "Kano", "Ibadan"]),
        ("Egypt", ["Cairo", "Alexandria", "Giza"]),
        ("Kenya", ["Nairobi", "Mombasa", "Kisumu"])
    ],
    "Oceania": [
        ("Australia", ["Sydney", "Melbourne", "Brisbane", "Perth", "Adelaide"]),
        ("New Zealand", ["Auckland", "Wellington", "Christchurch"])
    ]
}

continent_weights = [0.25, 0.25, 0.25, 0.10, 0.10, 0.05]
continent_keys = list(locations_map.keys())

# ---------------------------------------------
# HELPER FUNCTIONS
# ---------------------------------------------
def random_location():
    continent = random.choices(continent_keys, weights=continent_weights, k=1)[0]
    country, cities = random.choice(locations_map[continent])
    city = random.choice(cities)
    return continent, country, city

def random_date(start_year=2018, end_year=2025):
    start = datetime(start_year, 1, 1)
    end = datetime(end_year, 12, 31)
    return (start + timedelta(days=random.randint(0, (end - start).days))).date()

def weighted_choice(options, weights):
    return random.choices(options, weights=weights, k=1)[0]

# ---------------------------------------------
# GENERATION START
# ---------------------------------------------
sql_lines = []
append = sql_lines.append

append("-- Supply Chain Database Data Dump\n")
append("SET FOREIGN_KEY_CHECKS=0;\n")

# ---------------------------------------------
# LOCATION
# ---------------------------------------------
append("-- LOCATION\n")
for i in range(1, NUM_LOCATIONS + 1):
    continent, country, city = random_location()
    append(f"INSERT INTO Location (LocationID, CountryName, ContinentName) "
           f"VALUES ({i}, '{country}', '{continent}');")

# ---------------------------------------------
# COMPANY
# ---------------------------------------------
append("\n-- COMPANY\n")
tiers = ['1', '2', '3']
types = ['Manufacturer', 'Distributor', 'Retailer']

for i in range(1, NUM_COMPANIES + 1):
    cname = fake.company().replace("'", "''")
    locid = random.randint(1, NUM_LOCATIONS)
    tier = weighted_choice(tiers, [0.2, 0.3, 0.5])
    ctype = weighted_choice(types, [0.4, 0.3, 0.3])
    append(f"INSERT INTO Company (CompanyID, CompanyName, LocationID, TierLevel, Type) "
           f"VALUES ({i:08d}, '{cname}', {locid}, '{tier}', '{ctype}');")

# Split by type
manufacturers = [i for i in range(1, NUM_COMPANIES+1) if random.random() < 0.4]
distributors = [i for i in range(1, NUM_COMPANIES+1) if random.random() < 0.3]
retailers = [i for i in range(1, NUM_COMPANIES+1) if i not in manufacturers and random.random() < 0.3]

append("\n-- MANUFACTURER\n")
for mid in manufacturers:
    cap = random.randint(1000, 50000)
    append(f"INSERT INTO Manufacturer (CompanyID, FactoryCapacity) VALUES ({mid:08d}, {cap});")

append("\n-- DISTRIBUTOR\n")
for did in distributors:
    append(f"INSERT INTO Distributor (CompanyID) VALUES ({did:08d});")

append("\n-- RETAILER\n")
for rid in retailers:
    append(f"INSERT INTO Retailer (CompanyID) VALUES ({rid:08d});")

# ---------------------------------------------
# PRODUCT
# ---------------------------------------------
append("\n-- PRODUCT\n")
categories = ['Electronics','Raw Material','Component','Finished Good','Other']
for pid in range(1, NUM_PRODUCTS + 1):
    pname = fake.word().capitalize() + f"_{pid}"
    category = random.choice(categories)
    append(f"INSERT INTO Product (ProductID, ProductName, Category) VALUES ({pid}, '{pname}', '{category}');")

# ---------------------------------------------
# INVENTORY TRANSACTION
# ---------------------------------------------
append("\n-- INVENTORY TRANSACTION\n")
t_types = ['Shipping','Receiving','Adjustment']
for tid in range(1, NUM_SHIPMENTS + NUM_RECEIVINGS + NUM_ADJUSTMENTS + 1):
    ttype = weighted_choice(t_types, [0.5, 0.3, 0.2])
    append(f"INSERT INTO InventoryTransaction (TransactionID, Type) VALUES ({tid}, '{ttype}');")

# ---------------------------------------------
# SHIPPING
# ---------------------------------------------
append("\n-- SHIPPING\n")
for sid in range(1, NUM_SHIPMENTS + 1):
    tid = sid
    distributor = random.choice(distributors)
    product = random.randint(1, NUM_PRODUCTS)
    src = random.choice(manufacturers)
    dst = random.choice(retailers)
    promised = random_date()
    actual = promised + timedelta(days=random.randint(0, 10))
    qty = random.randint(1, 1000)
    append(f"INSERT INTO Shipping (ShipmentID, TransactionID, DistributorID, ProductID, SourceCompanyID, "
           f"DestinationCompanyID, PromisedDate, ActualDate, Quantity) "
           f"VALUES ({sid}, {tid}, {distributor:08d}, {product}, {src:08d}, {dst:08d}, "
           f"'{promised}', '{actual}', {qty});")

# ---------------------------------------------
# RECEIVING
# ---------------------------------------------
append("\n-- RECEIVING\n")
for rid in range(1, NUM_RECEIVINGS + 1):
    tid = NUM_SHIPMENTS + rid
    sid = random.randint(1, NUM_SHIPMENTS)
    recv = random.choice(retailers)
    rdate = random_date()
    qtyr = random.randint(1, 1000)
    append(f"INSERT INTO Receiving (ReceivingID, TransactionID, ShipmentID, ReceiverCompanyID, ReceivedDate, QuantityReceived) "
           f"VALUES ({rid}, {tid}, {sid}, {recv:08d}, '{rdate}', {qtyr});")

# ---------------------------------------------
# INVENTORY ADJUSTMENT
# ---------------------------------------------
append("\n-- INVENTORY ADJUSTMENT\n")
for aid in range(1, NUM_ADJUSTMENTS + 1):
    tid = NUM_SHIPMENTS + NUM_RECEIVINGS + aid
    cid = random.randint(1, NUM_COMPANIES)
    pid = random.randint(1, NUM_PRODUCTS)
    adate = random_date()
    qchange = random.randint(-50, 50)
    reason = fake.sentence(nb_words=4).replace("'", "''")
    append(f"INSERT INTO InventoryAdjustment (AdjustmentID, TransactionID, CompanyID, ProductID, AdjustmentDate, QuantityChange, Reason) "
           f"VALUES ({aid}, {tid}, {cid:08d}, {pid}, '{adate}', {qchange}, '{reason}');")

# ---------------------------------------------
# FINANCIAL REPORT
# ---------------------------------------------
append("\n-- FINANCIAL REPORT\n")
quarters = ['Q1','Q2','Q3','Q4']
for _ in range(NUM_FINANCIALS):
    cid = random.randint(1, NUM_COMPANIES)
    quarter = random.choice(quarters)
    year = random.randint(2019, 2025)
    score = round(random.uniform(40, 100), 2)
    append(f"INSERT INTO FinancialReport (CompanyID, Quarter, RepYear, HealthScore) "
           f"VALUES ({cid:08d}, '{quarter}', {year}, {score});")

# ---------------------------------------------
# DISRUPTION CATEGORY & EVENTS
# ---------------------------------------------
append("\n-- DISRUPTION CATEGORY\n")
categories = ["Labor Strike", "Cyber Attack", "Natural Disaster", "Supplier Bankruptcy", "Transportation Failure"]
for i, cat in enumerate(categories, 1):
    append(f"INSERT INTO DisruptionCategory (CategoryID, CategoryName, Description) "
           f"VALUES ({i}, '{cat}', 'Auto-generated disruption category');")

append("\n-- DISRUPTION EVENT\n")
for eid in range(1, NUM_DISRUPTIONS + 1):
    edate = random_date(2018, 2025)
    rdate = edate + timedelta(days=random.randint(3, 30))
    catid = random.randint(1, len(categories))
    append(f"INSERT INTO DisruptionEvent (EventID, EventDate, EventRecoveryDate, CategoryID) "
           f"VALUES ({eid}, '{edate}', '{rdate}', {catid});")

# ---------------------------------------------
# DEPENDENCIES
# ---------------------------------------------
append("\n-- DEPENDS ON\n")
for _ in range(NUM_DEPENDENCIES):
    up = random.randint(1, NUM_COMPANIES)
    down = random.randint(1, NUM_COMPANIES)
    if up != down:
        append(f"INSERT INTO DependsOn (UpstreamCompanyID, DownstreamCompanyID) VALUES ({up:08d}, {down:08d});")

# ---------------------------------------------
# SUPPLIES PRODUCT
# ---------------------------------------------
append("\n-- SUPPLIES PRODUCT\n")
for _ in range(NUM_SUPPLIES):
    sid = random.randint(1, NUM_COMPANIES)
    pid = random.randint(1, NUM_PRODUCTS)
    price = round(random.uniform(10, 1000), 2)
    append(f"INSERT INTO SuppliesProduct (SupplierID, ProductID, SupplyPrice) VALUES ({sid:08d}, {pid}, {price});")

# ---------------------------------------------
# IMPACTS COMPANY
# ---------------------------------------------
append("\n-- IMPACTS COMPANY\n")
impact_levels = ['Low', 'Medium', 'High']
impact_weights = [0.6, 0.3, 0.1]
for _ in range(NUM_IMPACTS):
    eid = random.randint(1, NUM_DISRUPTIONS)
    cid = random.randint(1, NUM_COMPANIES)
    level = weighted_choice(impact_levels, impact_weights)
    append(f"INSERT INTO ImpactsCompany (EventID, AffectedCompanyID, ImpactLevel) VALUES ({eid}, {cid:08d}, '{level}');")

# ---------------------------------------------
# OPERATES LOGISTICS
# ---------------------------------------------
append("\n-- OPERATES LOGISTICS\n")
for _ in range(NUM_LOGISTICS):
    did = random.choice(distributors)
    fromc = random.randint(1, NUM_COMPANIES)
    toc = random.randint(1, NUM_COMPANIES)
    if fromc != toc:
        append(f"INSERT INTO OperatesLogistics (DistributorID, FromCompanyID, ToCompanyID) "
               f"VALUES ({did:08d}, {fromc:08d}, {toc:08d});")

append("\nSET FOREIGN_KEY_CHECKS=1;\n")

# ---------------------------------------------
# WRITE OUTPUT
# ---------------------------------------------
with open("supply_chain_data.sql", "w", encoding="utf-8") as f:
    f.write("\n".join(sql_lines))

print("✅ supply_chain_data.sql generated successfully.")

