import random
from faker import Faker
from datetime import date, datetime, timedelta
fake = Faker()
# Output file
OUTFILE = 'supplychain_data.sql'
random.seed(332) 
Faker.seed(332)


# ----------------------------
# Control Amount of Data Generated
# ----------------------------
NUM_COMPANIES = 60
num_manufacturers = 36
num_retailers = 8
num_distributors = 16

NUM_PRODUCTS = 50
NUM_TRANSACTIONS = 300
NUM_DISRUPTIONEVENTS = 100

# ----------------------------
# 1️⃣ Locations
# ----------------------------
continent_data = {
    "North America": {
        "United States": ["New York", "Los Angeles", "Chicago", "Houston", "Phoenix","Philadelphia", "San Antonio", "San Diego", "Dallas", "Austin"],
        "Canada": ["Toronto", "Vancouver", "Montreal", "Calgary", "Ottawa","Edmonton", "Quebec City"],
        "Mexico": ["Mexico City", "Guadalajara", "Monterrey", "Puebla", "Tijuana","Cancún"]
    },
    "Europe": {
        "United Kingdom": ["London", "Manchester", "Birmingham", "Glasgow", "Liverpool","Leeds", "Bristol"],
        "France": ["Paris", "Lyon", "Marseille", "Toulouse", "Nice", "Bordeaux","Nantes"],
        "Germany": ["Berlin", "Munich", "Hamburg", "Frankfurt", "Cologne", "Stuttgart","Düsseldorf"],
        "Italy": ["Rome", "Milan", "Naples", "Turin", "Florence", "Bologna", "Venice"],
        "Spain": ["Madrid", "Barcelona", "Valencia", "Seville", "Zaragoza", "Bilbao","Malaga"]
    },
    "Asia": {
        "China": ["Beijing", "Shanghai", "Shenzhen", "Guangzhou", "Chengdu", "Wuhan","Tianjin", "Hangzhou"],
        "Japan": ["Tokyo", "Osaka", "Nagoya", "Fukuoka", "Sapporo", "Yokohama","Hiroshima"],
        "India": ["Mumbai", "Delhi", "Bangalore", "Chennai", "Kolkata", "Hyderabad","Pune", "Ahmedabad"],
        "South Korea": ["Seoul", "Busan", "Incheon", "Daegu", "Daejeon", "Gwangju"]
    },
    "South America": {
        "Brazil": ["São Paulo", "Rio de Janeiro", "Brasília", "Salvador", "Fortaleza","Curitiba", "Recife"],
        "Argentina": ["Buenos Aires", "Córdoba", "Rosario", "Mendoza", "La Plata"],
        "Chile": ["Santiago", "Valparaíso", "Concepción", "Antofagasta"],
        "Peru": ["Lima", "Arequipa", "Trujillo", "Cusco"]
    },
    "Africa": {
        "South Africa": ["Johannesburg", "Cape Town", "Durban", "Pretoria", "Port Elizabeth"],
        "Egypt": ["Cairo", "Alexandria", "Giza", "Sharm El Sheikh", "Luxor"],
        "Nigeria": ["Lagos", "Abuja", "Kano", "Ibadan", "Port Harcourt"],
        "Kenya": ["Nairobi", "Mombasa", "Kisumu", "Nakuru"]
    },
    "Oceania": {
        "Australia": ["Sydney", "Melbourne", "Brisbane", "Perth", "Adelaide", "Canberra"],
        "New Zealand": ["Auckland", "Wellington", "Christchurch", "Hamilton", "Dunedin"]
    }
}

#Ensure that string follows MySQL format
def safe_str(s):
    return s.replace("'", "''")

#Generate random date between 2020 and 2025
def get_random_date(start_year, end_year):
    """Generates a random date within a specified year range."""
    # Define the start and end dates for the range
    start_date = date(start_year, 1, 1)
    end_date = date(end_year, 12, 31)

    # Calculate the number of days between the two dates
    time_between_dates = end_date - start_date
    days_between_dates = time_between_dates.days

    # Generate a random number of days within that range
    random_number_of_days = random.randrange(days_between_dates)

    # Add the random number of days to the start date to get a random date
    random_date = start_date + timedelta(days=random_number_of_days)
    return random_date

locations = []
for continent, countries in continent_data.items():
    for country, cities in countries.items():
        for city in cities:
            locations.append((country, continent, city))
random.shuffle(locations)

# Open file immediately and write as we go
with open(OUTFILE, 'w', encoding='utf-8') as f:

    # Write Location inserts directly
    for i, (country, continent, city) in enumerate(locations, start=1):
        line = f"INSERT INTO Location (LocationID, CountryName, ContinentName, City) VALUES ({i}, '{country}', '{continent}', '{city}');"
        f.write(line + '\n')

    # ----------------------------
    # 2️⃣ Companies
    # ----------------------------
    companies = []
    manufacturer_capacity = {}

    #Ensure tiers are properly assigned
    def assign_tier(company_type):
        if company_type == 'Manufacturer':
            return random.choices(['1','2','3'], weights=[20,35,65])[0]
        elif company_type == 'Retailer':
            return '1'
        else: # Distributor
            return '3'

    # Write Company and specific type tables directly as generated
    for i in range(1, NUM_COMPANIES+1):
        if i <= num_manufacturers:
            ctype = 'Manufacturer'
        elif i <= num_manufacturers + num_retailers:
            ctype = 'Retailer'
        else:
            ctype = 'Distributor'
        loc_id = random.randint(1, len(locations))
        tier = assign_tier(ctype)
        name = safe_str(fake.company())
        company_line = f"INSERT INTO Company (CompanyID, CompanyName, LocationID, TierLevel, Type) VALUES ({i}, '{name}', {loc_id}, '{tier}', '{ctype}');"
        f.write(company_line + '\n')

        companies.append({'id':i,'type':ctype,'tier':int(tier)})

        if ctype == 'Manufacturer':
            cap = random.randint(500,5000)
            manufacturer_capacity[i] = cap
            mline = f"INSERT INTO Manufacturer (CompanyID, FactoryCapacity) VALUES ({i}, {cap});"
            f.write(mline + '\n')
        elif ctype == 'Retailer':
            rline = f"INSERT INTO Retailer (CompanyID) VALUES ({i});"
            f.write(rline + '\n')
        else:
            dline = f"INSERT INTO Distributor (CompanyID) VALUES ({i});"
            f.write(dline + '\n')

    # ----------------------------
    # 3️⃣ Topological Rank for Acyclic Supply Chain
    # ----------------------------
    tiers = {1:[], 2:[], 3:[]}
    for c in companies:
        tiers[c['tier']].append(c)

    topo_order = []
    for t in [1,2,3]:
        random.shuffle(tiers[t])
        topo_order.extend(tiers[t])

    company_rank = {c['id']: rank for rank, c in enumerate(topo_order)}

    # ----------------------------
    # 4️⃣ Products
    # ----------------------------
    categories = ['Electronics', 'Raw Material', 'Component', 'Finished Good', 'Other']

    product_manufacturers = {}
    product_categories = {}

    for pid in range(1, NUM_PRODUCTS + 1):
        category = random.choice(categories)
        product_categories[pid] = category

        # Write product insert
        product_line = f"INSERT INTO Product (ProductID, ProductName, Category) VALUES ({pid}, 'Product_{pid}', '{category}');"
        f.write(product_line + '\n')

        # Select eligible manufacturers based on tier rules
        if category == 'Finished Good':
            eligible_manufacturers = [c['id'] for c in companies if c['type'] == 'Manufacturer' and c['tier'] == 1]
        elif category == 'Component':
            eligible_manufacturers = [c['id'] for c in companies if c['type'] == 'Manufacturer' and c['tier'] == 2]
        elif category == 'Raw Material':
            eligible_manufacturers = [c['id'] for c in companies if c['type'] == 'Manufacturer' and c['tier'] == 3]
        else:  #Electronics or Other
            eligible_manufacturers = [c['id'] for c in companies if c['type'] == 'Manufacturer']

        # Pick 1–3 manufacturers randomly from eligible list
        num_manu = min(len(eligible_manufacturers), random.randint(1, 3))
        m_ids = random.sample(eligible_manufacturers, num_manu)
        product_manufacturers[pid] = m_ids

    # ----------------------------
    # 5️⃣ SuppliesProduct
    # ----------------------------
    for pid, m_ids in product_manufacturers.items(): #Ensure manufacturers supply the products they manufacture
        for mid in m_ids:
            price = round(random.uniform(10,1000),2)
            line = f"INSERT INTO SuppliesProduct (SupplierID, ProductID, SupplyPrice) VALUES ({mid}, {pid}, {price});"
            f.write(line + '\n')

    # ----------------------------
    # 6️⃣ Inventory Transactions
    # ----------------------------
    transaction_types = ['Shipping','Adjustment'] #All shipping records here have corresponding reciving records

    shipment_id_counter = 1
    receiving_id_counter = 1
    inventory = {}
    used_capacity = {m['id']:0 for m in companies if m['type']=='Manufacturer'}

    tid = 1
    while tid <= NUM_TRANSACTIONS:
        t_type = random.choices(transaction_types, weights=[80, 20])[0] #80% are shipping/reciving, 20% are adjustments

        if t_type == 'Shipping':
            f.write(f"INSERT INTO InventoryTransaction (TransactionID, Type) VALUES ({tid}, 'Shipping');\n")
            f.write(f"INSERT INTO InventoryTransaction (TransactionID, Type) VALUES ({tid+1}, 'Receiving');\n")
            product_id = random.randint(1, NUM_PRODUCTS)
            category = product_categories[product_id]
            manufacturer_id = random.choice(product_manufacturers[product_id]) #Ensure that manufacturer actually produces the product they are shipping
            source_id = manufacturer_id
            source_type = 'Manufacturer'

            #Ensure quantity doesn't exceed capacity
            qty = random.randint(10, 500)
            available = manufacturer_capacity[source_id] - used_capacity[source_id]
            if qty > available:
                qty = max(1, available) #Ensure quantity being shipped is always at least 1
            used_capacity[source_id] += qty

            #Select destination respecting tier/product logic
            source_tier = next((c['tier'] for c in companies if c['id'] == source_id), None)
            if source_type == 'Manufacturer' and source_tier == 3:
                possible_dest = [c['id'] for c in companies if c['id'] != source_id and ((c['tier'] == 3 and c['type'] == 'Manufacturer') or (c['tier'] ==2) or (c['tier'] == 1 and c['type'] == 'Manufacturer'))]
            elif source_type == 'Manufacturer' and source_tier == 2:
                possible_dest = [c['id'] for c in companies if c['id'] != source_id and ((c['tier'] == 2) or (c['tier'] == 1 and c['type'] == 'Manufacturer'))]
            elif source_type == 'Manufacturer' and source_tier == 1:
                possible_dest = [c['id'] for c in companies if c['id'] != source_id and c['tier'] ==  1]            
            dest_id = random.choice(possible_dest)

            distributors = [c['id'] for c in companies if c['type'] == 'Distributor']
            distributor_id = random.choice(distributors)
            random_date_in_range = get_random_date(2020, 2025)
            promised = random_date_in_range + timedelta(days=random.randint(1, 30))
            actual = promised + timedelta(days=random.randint(0, 5)) 

            shipping_line = (
                f"INSERT INTO Shipping (ShipmentID, TransactionID, DistributorID, ProductID, SourceCompanyID, "
                f"DestinationCompanyID, PromisedDate, ActualDate, Quantity) VALUES "
                f"({shipment_id_counter}, {tid}, {distributor_id}, {product_id}, {source_id}, {dest_id}, "
                f"'{promised}', '{actual}', {qty});"
            )
            f.write(shipping_line + '\n')
            receiving_line = (
                f"INSERT INTO Receiving (ReceivingID, TransactionID, ShipmentID, ReceiverCompanyID, ReceivedDate, QuantityReceived) VALUES "
                f"({receiving_id_counter}, {tid + 1}, {shipment_id_counter}, {dest_id}, '{actual}', {qty});"
            )
            f.write(receiving_line + '\n')

            #Update in-memory inventory
            inventory.setdefault(dest_id, {})
            inventory[dest_id].setdefault(product_id, 0)
            inventory[dest_id][product_id] += qty

            shipment_id_counter += 1
            receiving_id_counter += 1
            tid += 2  #Increment by 2 since we created two transactions (Shipping + Receiving)

        elif t_type == 'Adjustment':
            f.write(f"INSERT INTO InventoryTransaction (TransactionID, Type) VALUES ({tid}, 'Adjustment');\n")
            company_id = random.choice([c['id'] for c in companies])
            product_id = random.randint(1, NUM_PRODUCTS)
            qty_change = random.randint(-50, 50)

            #Prevent negative inventory - adjustment will be 0 if there is no product inventory to adjust
            current_qty = inventory.get(company_id, {}).get(product_id, 0)
            if current_qty + qty_change < 0:
                qty_change = -current_qty
            random_date_in_range = get_random_date(2020, 2025)
            adjustment_line = (
                f"INSERT INTO InventoryAdjustment (AdjustmentID, TransactionID, CompanyID, ProductID, AdjustmentDate, QuantityChange, Reason) VALUES "
                f"({tid}, {tid}, {company_id}, {product_id}, '{random_date_in_range}', {qty_change}, '{fake.sentence()}');"
            )
            f.write(adjustment_line + '\n')

            inventory.setdefault(company_id, {})
            inventory[company_id].setdefault(product_id, 0)
            inventory[company_id][product_id] += qty_change

            tid += 1  #Increment by 1 since Adjustment creates only one transaction

    # ----------------------------
    # 7️⃣ Financial Reports 2020–2025
    # ----------------------------
    for c in companies: #Generates financial health reports for every company in the database
        base = random.randint(50,90)
        trend = random.choice([-3,-1,0,1,3])
        for year in range(2020,2025+1):
            for q in ['Q1','Q2','Q3','Q4']:
                score = max(0.0, min(100.0, base + (year-2020)*trend + random.uniform(-2,2)))
                fin_line = f"INSERT INTO FinancialReport (CompanyID, Quarter, RepYear, HealthScore) VALUES ({c['id']}, '{q}', {year}, {score:.2f});"
                f.write(fin_line + '\n')

    # ----------------------------
    # 8️⃣ Disruptions & Impacts
    # ----------------------------
    disruption_categories = ["Natural Disaster","Cyber Attack","Labor Strike","Supplier Bankruptcy","Transport Failure","Regulatory Change","Energy Outage","Pandemic","Port Congestion","Raw Material Shortage","Weather Event","Political Unrest","Other"]
    #Write each DisruptionCategory statment once
    for category_id, c in enumerate(disruption_categories, start=1):
        f.write(f"INSERT INTO DisruptionCategory (CategoryID, CategoryName, Description) VALUES ({category_id},'{c}','{c} related issue');\n")

    for eid in range(1, NUM_DISRUPTIONEVENTS+1):
        cat_id = random.randint(1,len(disruption_categories))
        random_date_in_range = get_random_date(2020, 2025)
        start = random_date_in_range - timedelta(days=random.randint(0,365))
        recovery = start + timedelta(days=random.randint(1,30))
        f.write(f"INSERT INTO DisruptionEvent (EventID, EventDate, EventRecoveryDate, CategoryID) VALUES ({eid}, '{start}', '{recovery}', {cat_id});\n")
        for c in random.sample(companies, k=random.randint(5,20)):
            impact = random.choices(['Low','Medium','High'], weights=[70,25,5])[0]
            f.write(f"INSERT INTO ImpactsCompany (EventID, AffectedCompanyID, ImpactLevel) VALUES ({eid},{c['id']},'{impact}');\n")

    # ----------------------------
    # 9️⃣ DependsOn (no cycles)
    # ----------------------------
    #Ensure that companies depend on only those that are of a lower tier
    for lower_rank in [2,3]:
        for c in [c for c in companies if c['tier']==lower_rank]:
            upstream_candidates = [u['id'] for u in companies if company_rank[u['id']]<company_rank[c['id']]]
            if upstream_candidates:
                for u in random.sample(upstream_candidates, k=random.randint(1,min(3,len(upstream_candidates)))):
                    f.write(f"INSERT INTO DependsOn (UpstreamCompanyID, DownstreamCompanyID) VALUES ({u},{c['id']});\n")

    # ----------------------------
    # 🔟 OperatesLogistics
    # ----------------------------
    
    # Pre-group companies by tier, excluding distributors
    companies_by_tier = {}
    for c in companies:
        if c['type'] != 'Distributor':  #Exclude distributors
            companies_by_tier.setdefault(c['tier'], []).append(c['id'])

    # Generate logistics relationships
    for dist in distributors:  # each distributor gets its own set of logistics
        num_logistics = random.randint(1, 4)
        used_pairs = set()  # track unique (from, to) pairs for this distributor

        for _ in range(num_logistics):
            # Pick random valid upstream/downstream tiers
            from_tier = random.randint(1, 2)  # upstream can’t be highest tier
            to_tier = random.randint(from_tier + 1, 3)  # downstream must be higher

            # Ensure valid tier lists exist
            if not companies_by_tier.get(from_tier) or not companies_by_tier.get(to_tier):
                continue  # skip if that tier has no companies

            # Pick unique from/to combination
            attempts = 0
            while attempts < 10:  # avoid infinite loops if limited options
                frm = random.choice(companies_by_tier[from_tier])
                to = random.choice(companies_by_tier[to_tier])
                if frm != to and (frm, to) not in used_pairs:
                    used_pairs.add((frm, to))
                    break
                attempts += 1
            else:
                continue  # skip if couldn't find a unique pair

            # Write SQL insert
            f.write(
                f"INSERT INTO OperatesLogistics (DistributorID, FromCompanyID, ToCompanyID) "
                f"VALUES ({dist}, {frm}, {to});\n"
            )

print(f"SQL file '{OUTFILE}' generated successfully!")
