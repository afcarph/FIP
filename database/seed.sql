-- ===========================================================================
--  Fuel Intelligence Platform — sample data
--  Load AFTER schema.sql:  mysql -u fip -p fip < database/seed.sql
--  Passwords for every demo account: "Password123!"
--  (bcrypt cost 12 hash below is shared for convenience — demo only.)
-- ===========================================================================

USE `fip`;
SET foreign_key_checks = 0;
SET @PW := '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

-- ------------------------------- Geography ---------------------------------
INSERT INTO regions (id, code, name, island_group, created_at, updated_at) VALUES
 (1,'NCR','National Capital Region','luzon',NOW(),NOW()),
 (2,'R3','Central Luzon','luzon',NOW(),NOW()),
 (3,'R4A','CALABARZON','luzon',NOW(),NOW()),
 (4,'R7','Central Visayas','visayas',NOW(),NOW()),
 (5,'R11','Davao Region','mindanao',NOW(),NOW());

INSERT INTO provinces (id, region_id, code, name, created_at, updated_at) VALUES
 (1,1,'MNL','Metro Manila',NOW(),NOW()),
 (2,2,'PAM','Pampanga',NOW(),NOW()),
 (3,3,'CAV','Cavite',NOW(),NOW()),
 (4,3,'LAG','Laguna',NOW(),NOW()),
 (5,4,'CEB','Cebu',NOW(),NOW()),
 (6,5,'DVO','Davao del Sur',NOW(),NOW());

INSERT INTO cities (id, province_id, code, name, is_city, latitude, longitude, created_at, updated_at) VALUES
 (1,1,'MKT','Makati',1,14.5547000,121.0244000,NOW(),NOW()),
 (2,1,'QZN','Quezon City',1,14.6760000,121.0437000,NOW(),NOW()),
 (3,1,'TAG','Taguig',1,14.5176000,121.0509000,NOW(),NOW()),
 (4,1,'PSG','Pasig',1,14.5764000,121.0851000,NOW(),NOW()),
 (5,1,'MNA','Manila',1,14.5995000,120.9842000,NOW(),NOW()),
 (6,2,'SFP','San Fernando',1,15.0342000,120.6839000,NOW(),NOW()),
 (7,3,'DAS','Dasmariñas',1,14.3294000,120.9367000,NOW(),NOW()),
 (8,4,'STR','Santa Rosa',1,14.3122000,121.1114000,NOW(),NOW()),
 (9,5,'CEB','Cebu City',1,10.3157000,123.8854000,NOW(),NOW()),
 (10,6,'DVO','Davao City',1,7.1907000,125.4553000,NOW(),NOW());

-- ------------------------------- Reference ---------------------------------
INSERT INTO fuel_types (id, code, name, category, octane, unit, color_hex, sort_order, is_active, created_at, updated_at) VALUES
 (1,'gasoline_ron91','Gasoline RON 91','gasoline',91,'L','#22C55E',1,1,NOW(),NOW()),
 (2,'gasoline_ron95','Gasoline RON 95','gasoline',95,'L','#16A34A',2,1,NOW(),NOW()),
 (3,'gasoline_ron97','Gasoline RON 97','gasoline',97,'L','#15803D',3,1,NOW(),NOW()),
 (4,'diesel','Diesel','diesel',NULL,'L','#F59E0B',4,1,NOW(),NOW()),
 (5,'diesel_premium','Premium Diesel','diesel',NULL,'L','#D97706',5,1,NOW(),NOW()),
 (6,'kerosene','Kerosene','diesel',NULL,'L','#8B5CF6',6,1,NOW(),NOW()),
 (7,'lpg_auto','Auto LPG','lpg',NULL,'L','#EC4899',7,1,NOW(),NOW()),
 (8,'ev_dc_fast','EV DC Fast Charge','ev',NULL,'kWh','#0EA5E9',8,1,NOW(),NOW());

INSERT INTO brands (id, code, name, color_hex, is_active, created_at, updated_at) VALUES
 (1,'petron','Petron','#0033A0',1,NOW(),NOW()),
 (2,'shell','Shell','#DD1D21',1,NOW(),NOW()),
 (3,'caltex','Caltex','#E31837',1,NOW(),NOW()),
 (4,'phoenix','Phoenix Petroleum','#F57C00',1,NOW(),NOW()),
 (5,'seaoil','SEAOIL','#003DA5',1,NOW(),NOW()),
 (6,'cleanfuel','CleanFuel','#00A651',1,NOW(),NOW()),
 (7,'unioil','Unioil','#E4002B',1,NOW(),NOW()),
 (8,'total','TotalEnergies','#ED0000',1,NOW(),NOW());

INSERT INTO amenities (id, code, name, icon, created_at, updated_at) VALUES
 (1,'convenience_store','Convenience Store','store',NOW(),NOW()),
 (2,'restroom','Restroom','toilet',NOW(),NOW()),
 (3,'atm','ATM','banknote',NOW(),NOW()),
 (4,'car_wash','Car Wash','droplets',NOW(),NOW()),
 (5,'air_water','Air & Water','wind',NOW(),NOW()),
 (6,'lubes_bay','Lube Servicing Bay','wrench',NOW(),NOW()),
 (7,'food','Food Court','utensils',NOW(),NOW()),
 (8,'ev_charging','EV Charging','zap',NOW(),NOW()),
 (9,'truck_lane','Truck Lane','truck',NOW(),NOW()),
 (10,'wifi','Free Wi-Fi','wifi',NOW(),NOW());

INSERT INTO payment_methods (id, code, name, icon, created_at, updated_at) VALUES
 (1,'cash','Cash','banknote',NOW(),NOW()),
 (2,'credit_card','Credit Card','credit-card',NOW(),NOW()),
 (3,'debit_card','Debit Card','credit-card',NOW(),NOW()),
 (4,'gcash','GCash','smartphone',NOW(),NOW()),
 (5,'maya','Maya','smartphone',NOW(),NOW()),
 (6,'fleet_card','Fleet Card','id-card',NOW(),NOW()),
 (7,'qr_ph','QR Ph','qr-code',NOW(),NOW());

INSERT INTO maintenance_types (id, code, name, category, default_interval_km, default_interval_days, icon, created_at, updated_at) VALUES
 (1,'oil_change','Engine Oil Change','preventive',5000,180,'droplet',NOW(),NOW()),
 (2,'oil_filter','Oil Filter Replacement','preventive',10000,365,'filter',NOW(),NOW()),
 (3,'air_filter','Air Filter Replacement','preventive',15000,365,'wind',NOW(),NOW()),
 (4,'fuel_filter','Fuel Filter Replacement','preventive',40000,730,'filter',NOW(),NOW()),
 (5,'tire_rotation','Tire Rotation','preventive',10000,180,'circle-dot',NOW(),NOW()),
 (6,'tire_replacement','Tire Replacement','corrective',50000,1460,'circle',NOW(),NOW()),
 (7,'brake_pads','Brake Pad Replacement','corrective',40000,730,'disc',NOW(),NOW()),
 (8,'battery','Battery Replacement','corrective',NULL,900,'battery',NOW(),NOW()),
 (9,'coolant','Coolant Flush','preventive',40000,730,'thermometer',NOW(),NOW()),
 (10,'transmission','Transmission Service','preventive',60000,1095,'settings',NOW(),NOW()),
 (11,'registration','LTO Registration Renewal','legal',NULL,365,'file-text',NOW(),NOW()),
 (12,'insurance','Insurance Renewal','legal',NULL,365,'shield',NOW(),NOW()),
 (13,'emission_test','Emission Test','inspection',NULL,365,'gauge',NOW(),NOW());

-- --------------------------------- RBAC ------------------------------------
INSERT INTO roles (id, name, guard_name, label, level, created_at, updated_at) VALUES
 (1,'super_admin','api','Super Administrator',1,NOW(),NOW()),
 (2,'system_admin','api','System Administrator',2,NOW(),NOW()),
 (3,'station_admin','api','Gas Station Administrator',3,NOW(),NOW()),
 (4,'fleet_manager','api','Fleet Manager',4,NOW(),NOW()),
 (5,'company_manager','api','Company Manager',4,NOW(),NOW()),
 (6,'driver','api','Driver',6,NOW(),NOW()),
 (7,'user','api','Registered User',7,NOW(),NOW()),
 (8,'guest','api','Guest User',9,NOW(),NOW());

-- -------------------------------- Companies --------------------------------
INSERT INTO companies (id, name, legal_name, tin, type, address_line, city_id, contact_email, contact_phone, subscription_tier, is_active, created_at, updated_at) VALUES
 (1,'Northstar Logistics','Northstar Logistics Corp.','005-123-456-000','logistics','12 Kalayaan Ave.',2,'ops@northstar.ph','+63285550101','enterprise',1,NOW(),NOW()),
 (2,'MetroHaul Trucking','MetroHaul Trucking Inc.','007-654-321-000','trucking','88 C5 Service Rd.',3,'dispatch@metrohaul.ph','+63285550202','business',1,NOW(),NOW()),
 (3,'Petron Alabang Group','PAG Retailers Inc.','009-222-333-000','station_operator','KM 21 South Superhighway',1,'admin@pag.ph','+63285550303','business',1,NOW(),NOW());

-- ---------------------------------- Users ----------------------------------
INSERT INTO users (id, company_id, first_name, last_name, email, phone, password, locale, timezone, home_city_id, status, email_verified_at, mfa_enabled, created_at, updated_at) VALUES
 (1,NULL,'Sofia','Ramos','superadmin@fip.ph','+639170000001',@PW,'en','Asia/Manila',1,'active',NOW(),1,NOW(),NOW()),
 (2,NULL,'Miguel','Torres','sysadmin@fip.ph','+639170000002',@PW,'en','Asia/Manila',2,'active',NOW(),1,NOW(),NOW()),
 (3,3,'Andrea','Lim','station@fip.ph','+639170000003',@PW,'en','Asia/Manila',1,'active',NOW(),0,NOW(),NOW()),
 (4,1,'Rafael','Cruz','fleet@fip.ph','+639170000004',@PW,'en','Asia/Manila',2,'active',NOW(),0,NOW(),NOW()),
 (5,1,'Bianca','Reyes','manager@fip.ph','+639170000005',@PW,'en','Asia/Manila',2,'active',NOW(),0,NOW(),NOW()),
 (6,1,'Jomar','Dela Cruz','driver@fip.ph','+639170000006',@PW,'en','Asia/Manila',4,'active',NOW(),0,NOW(),NOW()),
 (7,NULL,'Ella','Santos','user@fip.ph','+639170000007',@PW,'en','Asia/Manila',3,'active',NOW(),0,NOW(),NOW());

INSERT INTO model_has_roles (role_id, model_type, model_id) VALUES
 (1,'App\\Domain\\User\\Models\\User',1),
 (2,'App\\Domain\\User\\Models\\User',2),
 (3,'App\\Domain\\User\\Models\\User',3),
 (4,'App\\Domain\\User\\Models\\User',4),
 (5,'App\\Domain\\User\\Models\\User',5),
 (6,'App\\Domain\\User\\Models\\User',6),
 (7,'App\\Domain\\User\\Models\\User',7);

INSERT INTO user_preferences (user_id, theme, preferred_fuel_type_id, price_alert_threshold, alert_radius_km, created_at, updated_at) VALUES
 (1,'dark',2,60.00,5.00,NOW(),NOW()),
 (4,'system',4,55.00,10.00,NOW(),NOW()),
 (7,'light',1,58.00,3.00,NOW(),NOW());

-- ------------------------------- Gas stations -------------------------------
INSERT INTO gas_stations (id, brand_id, operator_id, managed_by, name, slug, address_line, city_id, latitude, longitude, phone, is_24_hours, has_ev_charging, status, verified_at, rating_avg, rating_count, created_at, updated_at) VALUES
 (1,1,3,3,'Petron Ayala Avenue','petron-ayala-avenue','6750 Ayala Ave., Makati',1,14.5570000,121.0230000,'+63288887001',1,1,'active',NOW(),4.50,128,NOW(),NOW()),
 (2,2,NULL,NULL,'Shell EDSA Guadalupe','shell-edsa-guadalupe','EDSA cor. Guadalupe, Makati',1,14.5665000,121.0450000,'+63288887002',1,0,'active',NOW(),4.20,96,NOW(),NOW()),
 (3,3,NULL,NULL,'Caltex Commonwealth','caltex-commonwealth','Commonwealth Ave., Quezon City',2,14.6800000,121.0700000,'+63288887003',1,0,'active',NOW(),4.10,74,NOW(),NOW()),
 (4,5,NULL,NULL,'SEAOIL BGC 32nd Street','seaoil-bgc-32nd','32nd St., BGC, Taguig',3,14.5510000,121.0490000,'+63288887004',0,1,'active',NOW(),4.60,203,NOW(),NOW()),
 (5,4,NULL,NULL,'Phoenix Ortigas Extension','phoenix-ortigas-ext','Ortigas Ext., Pasig',4,14.5800000,121.0900000,'+63288887005',1,0,'active',NOW(),3.90,41,NOW(),NOW()),
 (6,7,NULL,NULL,'Unioil España','unioil-espana','España Blvd., Manila',5,14.6100000,120.9930000,'+63288887006',0,0,'active',NOW(),4.30,58,NOW(),NOW()),
 (7,6,NULL,NULL,'CleanFuel Dasmariñas','cleanfuel-dasmarinas','Aguinaldo Hwy., Dasmariñas',7,14.3300000,120.9370000,'+63288887007',0,0,'active',NOW(),4.00,33,NOW(),NOW()),
 (8,2,NULL,NULL,'Shell Cebu Business Park','shell-cebu-business-park','Cardinal Rosales Ave., Cebu City',9,10.3180000,123.9050000,'+63328887008',1,1,'active',NOW(),4.40,87,NOW(),NOW()),
 (9,1,NULL,NULL,'Petron Davao Ecoland','petron-davao-ecoland','Quimpo Blvd., Davao City',10,7.0700000,125.6100000,'+63828887009',1,0,'active',NOW(),4.20,52,NOW(),NOW()),
 (10,8,NULL,NULL,'TotalEnergies Santa Rosa','total-santa-rosa','Old National Hwy., Santa Rosa',8,14.3120000,121.1120000,'+63498887010',0,0,'active',NOW(),4.10,29,NOW(),NOW());

INSERT INTO station_amenity (station_id, amenity_id) VALUES
 (1,1),(1,2),(1,3),(1,5),(1,8),(1,10),
 (2,1),(2,2),(2,4),(2,5),
 (3,1),(3,2),(3,5),(3,9),
 (4,1),(4,2),(4,3),(4,7),(4,8),(4,10),
 (5,2),(5,5),(5,9),
 (6,1),(6,2),
 (7,1),(7,2),(7,5),
 (8,1),(8,2),(8,3),(8,8),
 (9,1),(9,2),(9,9),
 (10,1),(10,2),(10,5);

INSERT INTO station_payment_method (station_id, payment_method_id) VALUES
 (1,1),(1,2),(1,4),(1,5),(1,6),(1,7),
 (2,1),(2,2),(2,4),(2,6),
 (3,1),(3,2),(3,4),
 (4,1),(4,2),(4,4),(4,5),(4,7),
 (5,1),(5,4),(5,6),
 (6,1),(6,4),
 (7,1),(7,4),
 (8,1),(8,2),(8,4),(8,5),
 (9,1),(9,2),(9,6),
 (10,1),(10,2),(10,4);

-- --------------------------------- Prices ----------------------------------
INSERT INTO station_prices (station_id, fuel_type_id, price, previous_price, source, confidence, effective_at, verified_at, created_at, updated_at) VALUES
 (1,1,59.4500,60.1500,'operator',1.000,NOW(),NOW(),NOW(),NOW()),
 (1,2,63.2000,63.9000,'operator',1.000,NOW(),NOW(),NOW(),NOW()),
 (1,4,57.8000,58.4000,'operator',1.000,NOW(),NOW(),NOW(),NOW()),
 (2,1,59.9000,60.6000,'crowd',0.850,NOW(),NULL,NOW(),NOW()),
 (2,2,63.7500,64.4500,'crowd',0.850,NOW(),NULL,NOW(),NOW()),
 (2,4,58.2500,58.8500,'crowd',0.850,NOW(),NULL,NOW(),NOW()),
 (3,1,58.9000,59.6000,'ocr',0.910,NOW(),NOW(),NOW(),NOW()),
 (3,4,57.4000,58.0000,'ocr',0.910,NOW(),NOW(),NOW(),NOW()),
 (4,1,58.5000,59.2000,'operator',1.000,NOW(),NOW(),NOW(),NOW()),
 (4,2,62.4000,63.1000,'operator',1.000,NOW(),NOW(),NOW(),NOW()),
 (4,4,56.9500,57.5500,'operator',1.000,NOW(),NOW(),NOW(),NOW()),
 (5,4,57.2000,57.8000,'crowd',0.780,NOW(),NULL,NOW(),NOW()),
 (6,1,58.7500,59.4500,'crowd',0.800,NOW(),NULL,NOW(),NOW()),
 (7,1,58.2000,58.9000,'operator',1.000,NOW(),NOW(),NOW(),NOW()),
 (8,1,60.1000,60.8000,'operator',1.000,NOW(),NOW(),NOW(),NOW()),
 (8,4,58.9000,59.5000,'operator',1.000,NOW(),NOW(),NOW(),NOW()),
 (9,1,60.4500,61.1500,'doe',0.950,NOW(),NOW(),NOW(),NOW()),
 (10,1,59.1000,59.8000,'crowd',0.820,NOW(),NULL,NOW(),NOW());

-- 12 weeks of nationwide DOE advisories for RON95 and diesel.
INSERT INTO price_advisories (fuel_type_id, region_id, week_start, effective_at, change_amount, direction, source, notes, created_at, updated_at) VALUES
 (2,NULL,'2026-05-11','2026-05-12 06:00:00', 0.8000,'increase','doe','Firmer MOPS on Asian demand',NOW(),NOW()),
 (2,NULL,'2026-05-18','2026-05-19 06:00:00',-0.5000,'rollback','doe','Stronger peso, softer crude',NOW(),NOW()),
 (2,NULL,'2026-05-25','2026-05-26 06:00:00', 0.3500,'increase','doe','OPEC+ output discipline',NOW(),NOW()),
 (2,NULL,'2026-06-01','2026-06-02 06:00:00', 1.2000,'increase','doe','Middle East supply risk premium',NOW(),NOW()),
 (2,NULL,'2026-06-08','2026-06-09 06:00:00',-0.9000,'rollback','doe','Risk premium unwound',NOW(),NOW()),
 (2,NULL,'2026-06-15','2026-06-16 06:00:00', 0.0000,'no_change','doe','Flat week',NOW(),NOW()),
 (2,NULL,'2026-06-22','2026-06-23 06:00:00',-0.4500,'rollback','doe','Inventory build in the US',NOW(),NOW()),
 (2,NULL,'2026-06-29','2026-06-30 06:00:00', 0.6000,'increase','doe','Peso depreciation',NOW(),NOW()),
 (2,NULL,'2026-07-06','2026-07-07 06:00:00', 0.2500,'increase','doe','Seasonal demand',NOW(),NOW()),
 (2,NULL,'2026-07-13','2026-07-14 06:00:00',-1.1000,'rollback','doe','Crude sell-off',NOW(),NOW()),
 (2,NULL,'2026-07-20','2026-07-21 06:00:00',-0.3000,'rollback','doe','Continued softening',NOW(),NOW()),
 (2,NULL,'2026-07-27','2026-07-28 06:00:00', 0.4000,'increase','doe','Freight cost pass-through',NOW(),NOW()),
 (4,NULL,'2026-07-06','2026-07-07 06:00:00', 0.3000,'increase','doe','Gasoil cracks widen',NOW(),NOW()),
 (4,NULL,'2026-07-13','2026-07-14 06:00:00',-0.9500,'rollback','doe','Crude sell-off',NOW(),NOW()),
 (4,NULL,'2026-07-20','2026-07-21 06:00:00',-0.2500,'rollback','doe','Continued softening',NOW(),NOW()),
 (4,NULL,'2026-07-27','2026-07-28 06:00:00', 0.5500,'increase','doe','Regional restocking',NOW(),NOW());

INSERT INTO market_indicators (indicator, observed_on, value, unit, source, created_at, updated_at) VALUES
 ('dubai_crude','2026-07-06',82.35000,'USD/bbl','Platts',NOW(),NOW()),
 ('dubai_crude','2026-07-13',79.10000,'USD/bbl','Platts',NOW(),NOW()),
 ('dubai_crude','2026-07-20',78.42000,'USD/bbl','Platts',NOW(),NOW()),
 ('dubai_crude','2026-07-27',80.05000,'USD/bbl','Platts',NOW(),NOW()),
 ('mops_gasoline','2026-07-06',92.10000,'USD/bbl','MOPS',NOW(),NOW()),
 ('mops_gasoline','2026-07-13',88.60000,'USD/bbl','MOPS',NOW(),NOW()),
 ('mops_gasoline','2026-07-20',87.95000,'USD/bbl','MOPS',NOW(),NOW()),
 ('mops_gasoline','2026-07-27',89.80000,'USD/bbl','MOPS',NOW(),NOW()),
 ('usd_php','2026-07-06',57.85000,'PHP','BSP',NOW(),NOW()),
 ('usd_php','2026-07-13',57.42000,'PHP','BSP',NOW(),NOW()),
 ('usd_php','2026-07-20',57.60000,'PHP','BSP',NOW(),NOW()),
 ('usd_php','2026-07-27',58.15000,'PHP','BSP',NOW(),NOW());

-- -------------------------------- Fleet data --------------------------------
INSERT INTO fleets (id, company_id, name, code, manager_id, base_city_id, monthly_fuel_budget, is_active, created_at, updated_at) VALUES
 (1,1,'Metro Manila Delivery','NS-MM',4,2,850000.00,1,NOW(),NOW()),
 (2,1,'Luzon Long Haul','NS-LH',4,6,1450000.00,1,NOW(),NOW()),
 (3,2,'MetroHaul Prime','MH-PR',NULL,3,620000.00,1,NOW(),NOW());

INSERT INTO drivers (id, user_id, company_id, fleet_id, employee_no, first_name, last_name, phone, licence_number, licence_type, licence_expiry, hired_at, safety_score, efficiency_score, status, created_at, updated_at) VALUES
 (1,6,1,1,'NS-0001','Jomar','Dela Cruz','+639170000006','N02-11-123456','Professional','2027-03-14','2023-02-01',96.50,91.20,'active',NOW(),NOW()),
 (2,NULL,1,1,'NS-0002','Arnel','Bautista','+639170000012','N02-12-654321','Professional','2026-11-30','2022-08-15',88.00,84.70,'active',NOW(),NOW()),
 (3,NULL,1,2,'NS-0003','Kristine','Padilla','+639170000013','N03-14-778899','Professional','2028-01-20','2024-01-08',94.10,89.30,'active',NOW(),NOW()),
 (4,NULL,2,3,'MH-0001','Dennis','Alvarez','+639170000014','N02-10-556677','Professional','2026-09-05','2021-05-03',79.40,76.10,'active',NOW(),NOW());

INSERT INTO vehicle_makes (id, name, created_at, updated_at) VALUES
 (1,'Toyota',NOW(),NOW()),(2,'Mitsubishi',NOW(),NOW()),(3,'Isuzu',NOW(),NOW()),
 (4,'Honda',NOW(),NOW()),(5,'Hino',NOW(),NOW()),(6,'Yamaha',NOW(),NOW());

INSERT INTO vehicle_models (id, make_id, name, body_type, default_fuel_type_id, tank_capacity, created_at, updated_at) VALUES
 (1,1,'Hilux','pickup',4,80.00,NOW(),NOW()),
 (2,1,'Vios','sedan',1,42.00,NOW(),NOW()),
 (3,2,'L300','van',4,60.00,NOW(),NOW()),
 (4,3,'N-Series NLR','truck',4,100.00,NOW(),NOW()),
 (5,5,'500 Series','truck',4,200.00,NOW(),NOW()),
 (6,6,'NMAX 155','motorcycle',1,7.10,NOW(),NOW()),
 (7,4,'Civic','sedan',2,47.00,NOW(),NOW());

INSERT INTO vehicles (id, owner_id, company_id, fleet_id, make_id, model_id, fuel_type_id, nickname, plate_number, vehicle_type, year, color, transmission, tank_capacity, current_odometer, baseline_km_per_litre, avg_km_per_litre, registration_expiry, insurance_provider, insurance_expiry, status, created_at, updated_at) VALUES
 (1,7,NULL,NULL,1,2,1,'Daily Driver','ABC 1234','car',2022,'Silver','automatic',42.00,48250.00,14.50,13.80,'2026-09-30','Malayan Insurance','2026-10-15','active',NOW(),NOW()),
 (2,7,NULL,NULL,6,6,1,'Weekend Ride','MC 5678','motorcycle',2023,'Blue','automatic',7.10,12400.00,45.00,42.30,'2026-08-20','Prudential Guarantee','2026-08-25','active',NOW(),NOW()),
 (3,NULL,1,1,2,3,4,'Delivery 01','NS 1001','van',2021,'White','manual',60.00,182300.00,9.80,8.90,'2026-11-12','FPG Insurance','2026-12-01','active',NOW(),NOW()),
 (4,NULL,1,1,3,4,4,'Delivery 02','NS 1002','truck',2020,'White','manual',100.00,264100.00,6.50,5.80,'2026-08-05','FPG Insurance','2026-09-10','active',NOW(),NOW()),
 (5,NULL,1,2,5,5,4,'Long Haul 01','NS 2001','truck',2019,'Blue','manual',200.00,410500.00,3.80,3.40,'2026-10-22','Pioneer Insurance','2026-11-05','active',NOW(),NOW()),
 (6,NULL,2,3,1,1,4,'MH Pickup','MH 3001','truck',2022,'Black','automatic',80.00,96700.00,10.20,9.60,'2027-01-18','Standard Insurance','2027-02-01','active',NOW(),NOW());

INSERT INTO vehicle_assignments (vehicle_id, driver_id, assigned_at, assigned_by, created_at, updated_at) VALUES
 (3,1,'2025-01-05 08:00:00',4,NOW(),NOW()),
 (4,2,'2025-03-11 08:00:00',4,NOW(),NOW()),
 (5,3,'2025-06-01 08:00:00',4,NOW(),NOW()),
 (6,4,'2025-02-14 08:00:00',5,NOW(),NOW());

-- ------------------------------ Fuel purchases ------------------------------
INSERT INTO fuel_purchases (vehicle_id, user_id, driver_id, station_id, fuel_type_id, litres, price_per_litre, total_cost, odometer, distance_since_last, km_per_litre, cost_per_km, is_full_tank, payment_method_id, purchased_at, anomaly_score, created_at, updated_at) VALUES
 (1,7,NULL,1,1,35.200,60.1500,2117.28,47420.00,489.00,13.89,4.3298,1,4,'2026-07-04 08:15:00',0.031,NOW(),NOW()),
 (1,7,NULL,4,1,34.500,59.2000,2042.40,47900.00,480.00,13.91,4.2550,1,4,'2026-07-14 09:02:00',0.028,NOW(),NOW()),
 (1,7,NULL,2,1,36.100,59.9000,2162.39,48250.00,350.00, 9.70,6.1783,1,2,'2026-07-24 18:40:00',0.412,NOW(),NOW()),
 (2,7,NULL,6,1, 6.800,59.4500,404.26,12180.00,300.00,44.12,1.3475,1,1,'2026-07-08 07:20:00',0.019,NOW(),NOW()),
 (2,7,NULL,6,1, 6.500,58.7500,381.88,12400.00,220.00,33.85,1.7358,1,1,'2026-07-22 07:35:00',0.144,NOW(),NOW()),
 (3,4,1,5,4,55.400,58.4000,3235.36,181450.00,505.00, 9.11,6.4066,1,6,'2026-07-06 06:10:00',0.052,NOW(),NOW()),
 (3,4,1,5,4,57.900,57.2000,3311.88,182300.00,850.00,14.68,3.8963,1,6,'2026-07-20 06:25:00',0.688,NOW(),NOW()),
 (4,4,2,3,4,92.300,58.0000,5353.40,263200.00,540.00, 5.85,9.9137,1,6,'2026-07-09 05:50:00',0.061,NOW(),NOW()),
 (4,4,2,3,4,95.100,57.4000,5458.74,264100.00,900.00, 9.46,6.0653,1,6,'2026-07-23 05:45:00',0.735,NOW(),NOW()),
 (5,4,3,9,4,185.000,61.1500,11312.75,409000.00,640.00, 3.46,17.6762,1,6,'2026-07-11 04:30:00',0.044,NOW(),NOW()),
 (5,4,3,9,4,178.400,60.4500,10784.68,410500.00,1500.00, 8.41,7.1898,1,6,'2026-07-25 04:20:00',0.812,NOW(),NOW()),
 (6,5,4,10,4,70.200,59.8000,4197.96,95900.00,700.00, 9.97,5.9971,1,2,'2026-07-12 07:05:00',0.037,NOW(),NOW()),
 (6,5,4,10,4,72.500,59.1000,4284.75,96700.00,800.00,11.03,5.3559,1,2,'2026-07-26 07:15:00',0.096,NOW(),NOW());

-- ------------------------------- Maintenance --------------------------------
INSERT INTO maintenance_records (vehicle_id, maintenance_type_id, performed_at, odometer, cost, vendor, notes, recorded_by, created_at, updated_at) VALUES
 (1,1,'2026-04-12',44100.00,2800.00,'Toyota Makati','Fully synthetic 0W-20',7,NOW(),NOW()),
 (1,5,'2026-04-12',44100.00,600.00,'Toyota Makati',NULL,7,NOW(),NOW()),
 (3,1,'2026-05-02',176800.00,4200.00,'Northstar Motorpool',NULL,4,NOW(),NOW()),
 (4,7,'2026-03-20',255400.00,12500.00,'Isuzu Balintawak','Front + rear pads',4,NOW(),NOW()),
 (5,1,'2026-06-05',401200.00,9800.00,'Hino Cabuyao',NULL,4,NOW(),NOW());

INSERT INTO maintenance_schedules (vehicle_id, maintenance_type_id, interval_km, interval_days, last_performed_at, last_odometer, due_at, due_odometer, predicted_due_at, prediction_confidence, status, created_at, updated_at) VALUES
 (1,1,5000,180,'2026-04-12',44100.00,'2026-10-09',49100.00,'2026-08-18',0.840,'due_soon',NOW(),NOW()),
 (1,11,NULL,365,NULL,NULL,'2026-09-30',NULL,NULL,NULL,'scheduled',NOW(),NOW()),
 (1,12,NULL,365,NULL,NULL,'2026-10-15',NULL,NULL,NULL,'scheduled',NOW(),NOW()),
 (3,1,5000,180,'2026-05-02',176800.00,'2026-10-29',181800.00,'2026-08-02',0.910,'overdue',NOW(),NOW()),
 (4,7,40000,730,'2026-03-20',255400.00,'2028-03-19',295400.00,'2027-06-11',0.720,'scheduled',NOW(),NOW()),
 (5,1,5000,180,'2026-06-05',401200.00,'2026-12-02',406200.00,'2026-08-09',0.930,'overdue',NOW(),NOW());

-- ------------------------------ AI artefacts --------------------------------
INSERT INTO ai_models (id, code, name, algorithm, version, metrics, trained_at, training_rows, is_active, created_at, updated_at) VALUES
 (1,'price_forecast','Weekly Pump Price Forecaster','prophet+xgboost','1.4.0','{"mae":0.2140,"rmse":0.3120,"mape":0.0041,"direction_accuracy":0.842}','2026-07-27 02:00:00',5480,1,NOW(),NOW()),
 (2,'consumption','Vehicle Consumption Predictor','xgboost','1.1.0','{"mae":0.4200,"rmse":0.6100,"r2":0.883}','2026-07-20 02:00:00',18420,1,NOW(),NOW()),
 (3,'maintenance','Predictive Maintenance Scheduler','xgboost','1.0.2','{"auc":0.911,"precision":0.842,"recall":0.789}','2026-07-13 02:00:00',9310,1,NOW(),NOW()),
 (4,'fraud','Fuel Fraud Detector','isolation_forest','1.2.0','{"precision":0.803,"recall":0.741,"f1":0.771}','2026-07-27 03:00:00',26400,1,NOW(),NOW());

INSERT INTO price_forecasts (ai_model_id, fuel_type_id, region_id, forecast_for, generated_at, direction, change_amount, predicted_price, lower_bound, upper_bound, confidence, drivers, narrative, created_at, updated_at) VALUES
 (1,2,NULL,'2026-08-03','2026-07-31 02:00:00','increase',0.5500,63.7500,63.4000,64.1000,0.812,
  '[{"factor":"MOPS gasoline","weight":0.46,"value":"+2.1%"},{"factor":"Dubai crude","weight":0.28,"value":"+2.1 USD/bbl"},{"factor":"USD/PHP","weight":0.18,"value":"58.15"},{"factor":"Seasonality","weight":0.08,"value":"neutral"}]',
  'Regional gasoline cracks widened for a second straight week while the peso weakened to 58.15. A ₱0.45–₱0.65 per-litre increase is likely to take effect Tuesday.',NOW(),NOW()),
 (1,4,NULL,'2026-08-03','2026-07-31 02:00:00','increase',0.4000,58.2000,57.9000,58.5000,0.774,
  '[{"factor":"Gasoil cracks","weight":0.44,"value":"+1.6%"},{"factor":"Dubai crude","weight":0.30,"value":"+2.1 USD/bbl"},{"factor":"USD/PHP","weight":0.19,"value":"58.15"},{"factor":"Freight","weight":0.07,"value":"stable"}]',
  'Diesel follows crude higher but a softer gasoil crack caps the move at roughly ₱0.40 per litre.',NOW(),NOW());

INSERT INTO fraud_alerts (company_id, fleet_id, vehicle_id, driver_id, fuel_purchase_id, alert_type, severity, score, evidence, status, detected_at, created_at, updated_at) VALUES
 (1,2,5,3,11,'overfill','high',0.812,'{"litres":178.4,"tank_capacity":200,"expected_max":168.0,"km_per_litre":8.41,"baseline_km_per_litre":3.80,"deviation_sigma":4.2}','open','2026-07-25 06:00:00',NOW(),NOW()),
 (1,1,4,2,9,'ghost_refuel','medium',0.735,'{"odometer_gap_km":900,"expected_gap_km":540,"time_between_fills_h":336,"gps_match":false}','investigating','2026-07-23 07:00:00',NOW(),NOW());

-- ------------------------------ Crowd reports -------------------------------
INSERT INTO price_reports (station_id, fuel_type_id, user_id, report_type, price, comment, latitude, longitude, distance_m, trust_score, status, upvotes, downvotes, created_at, updated_at) VALUES
 (2,1,7,'price',59.9000,'Board updated this morning',14.5666000,121.0451000,42,0.870,'approved',12,0,NOW(),NOW()),
 (5,4,7,'price',57.2000,NULL,14.5801000,121.0902000,28,0.810,'auto_approved',5,0,NOW(),NOW()),
 (3,NULL,6,'long_queue',NULL,'About 15 vehicles deep',14.6801000,121.0701000,60,0.640,'pending',2,0,NOW(),NOW()),
 (7,NULL,7,'shortage',NULL,'Diesel unavailable',14.3301000,120.9371000,35,0.720,'pending',8,1,NOW(),NOW());

INSERT INTO ocr_scans (user_id, station_id, image_path, raw_text, parsed_payload, engine, overall_confidence, status, created_at, updated_at) VALUES
 (7,3,'ocr/2026/07/board-3341.jpg','XTRA UNLEADED 58.90\nDIESEL 57.40\nXTRA ADVANCE 62.10',
  '[{"fuel_type":"gasoline_ron91","price":58.90,"confidence":0.94},{"fuel_type":"diesel","price":57.40,"confidence":0.91},{"fuel_type":"gasoline_ron95","price":62.10,"confidence":0.88}]',
  'tesseract',0.910,'approved',NOW(),NOW());

-- ------------------------------ Notifications -------------------------------
INSERT INTO notification_templates (code, channel, title, body, variables, created_at, updated_at) VALUES
 ('price_forecast_weekly','push','Fuel price forecast','{{direction}} of about ₱{{amount}}/L expected on {{date}} ({{confidence}}% confidence).','["direction","amount","date","confidence"]',NOW(),NOW()),
 ('price_alert_hit','push','Cheaper fuel nearby','{{station}} now sells {{fuel}} at ₱{{price}}/L — {{distance}} km away.','["station","fuel","price","distance"]',NOW(),NOW()),
 ('maintenance_due','push','Maintenance due','{{vehicle}} is due for {{service}} on {{date}}.','["vehicle","service","date"]',NOW(),NOW()),
 ('registration_expiry','push','Registration expiring','{{vehicle}} registration expires on {{date}}.','["vehicle","date"]',NOW(),NOW()),
 ('insurance_expiry','push','Insurance expiring','{{vehicle}} insurance expires on {{date}}.','["vehicle","date"]',NOW(),NOW()),
 ('fraud_alert','push','Possible fuel anomaly','{{vehicle}}: {{alert_type}} detected with {{score}} confidence.','["vehicle","alert_type","score"]',NOW(),NOW());

INSERT INTO price_alerts (user_id, fuel_type_id, station_id, city_id, condition, threshold, radius_km, is_active, created_at, updated_at) VALUES
 (7,1,NULL,3,'below',59.0000,5.00,1,NOW(),NOW()),
 (4,4,NULL,2,'below',57.5000,10.00,1,NOW(),NOW());

INSERT INTO report_definitions (code, name, description, scope, required_permission, created_at, updated_at) VALUES
 ('fuel_expense_summary','Fuel Expense Summary','Spend, litres, and efficiency by vehicle over a period.','user','reports.view',NOW(),NOW()),
 ('fleet_utilisation','Fleet Utilisation','Distance, idle days, and cost per km by vehicle.','fleet','fleet.reports',NOW(),NOW()),
 ('price_movement','Regional Price Movement','Average pump price and week-on-week change by region.','platform','reports.platform',NOW(),NOW()),
 ('station_performance','Station Performance','Price competitiveness and rating trend for a station.','station','station.reports',NOW(),NOW()),
 ('fraud_register','Fraud Alert Register','All fraud alerts with status and resolution.','company','fraud.view',NOW(),NOW());

INSERT INTO settings (`group`, `key`, value, is_public, created_at, updated_at) VALUES
 ('general','app_name','"Fuel Intelligence Platform"',1,NOW(),NOW()),
 ('general','support_email','"support@fip.ph"',1,NOW(),NOW()),
 ('pricing','crowd_auto_approve_trust','0.8',0,NOW(),NOW()),
 ('pricing','max_price_deviation_pct','0.15',0,NOW(),NOW()),
 ('ai','forecast_schedule_cron','"0 2 * * 1"',0,NOW(),NOW()),
 ('ai','min_forecast_confidence','0.6',1,NOW(),NOW()),
 ('fraud','overfill_tolerance_pct','0.05',0,NOW(),NOW());

SET foreign_key_checks = 1;
