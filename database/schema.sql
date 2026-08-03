-- ===========================================================================
--  Fuel Intelligence Platform — canonical MySQL 8.0 schema
--  Engine: InnoDB   Charset: utf8mb4   Collation: utf8mb4_0900_ai_ci
--
--  Conventions
--    * Surrogate PK `id` BIGINT UNSIGNED AUTO_INCREMENT on every table.
--    * `created_at` / `updated_at` on every mutable table.
--    * `deleted_at` (soft delete) on every business entity.
--    * Money stored as DECIMAL(10,4) — PH pump prices carry 2–4 decimals.
--    * Coordinates stored as DECIMAL(10,7)/(10,7) plus a generated POINT
--      column with a SPATIAL index for radius search.
--    * All FKs are explicit and named `fk_<table>_<column>`.
--    * Enumerations are stored as VARCHAR + CHECK to stay migration friendly.
-- ===========================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET foreign_key_checks = 0;

CREATE DATABASE IF NOT EXISTS `fip`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_0900_ai_ci;
USE `fip`;

-- ===========================================================================
-- 1. GEOGRAPHY REFERENCE
-- ===========================================================================

CREATE TABLE regions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(16)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  island_group  VARCHAR(16)  NOT NULL DEFAULT 'luzon',
  created_at    TIMESTAMP NULL DEFAULT NULL,
  updated_at    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_regions_code (code),
  CONSTRAINT chk_regions_island CHECK (island_group IN ('luzon','visayas','mindanao'))
) ENGINE=InnoDB;

CREATE TABLE provinces (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  region_id   BIGINT UNSIGNED NOT NULL,
  code        VARCHAR(16)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  updated_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provinces_code (code),
  KEY idx_provinces_region (region_id),
  CONSTRAINT fk_provinces_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cities (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  province_id  BIGINT UNSIGNED NOT NULL,
  code         VARCHAR(16)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  is_city      TINYINT(1)   NOT NULL DEFAULT 1,
  latitude     DECIMAL(10,7) NULL,
  longitude    DECIMAL(10,7) NULL,
  created_at   TIMESTAMP NULL DEFAULT NULL,
  updated_at   TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cities_code (code),
  KEY idx_cities_province (province_id),
  KEY idx_cities_name (name),
  CONSTRAINT fk_cities_province FOREIGN KEY (province_id) REFERENCES provinces (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===========================================================================
-- 2. IDENTITY, RBAC & SECURITY
-- ===========================================================================

CREATE TABLE companies (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(180) NOT NULL,
  legal_name        VARCHAR(180) NULL,
  tin               VARCHAR(32)  NULL,
  industry          VARCHAR(80)  NULL,
  type              VARCHAR(24)  NOT NULL DEFAULT 'logistics',
  address_line      VARCHAR(255) NULL,
  city_id           BIGINT UNSIGNED NULL,
  contact_email     VARCHAR(180) NULL,
  contact_phone     VARCHAR(32)  NULL,
  logo_path         VARCHAR(255) NULL,
  subscription_tier VARCHAR(24)  NOT NULL DEFAULT 'free',
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        TIMESTAMP NULL DEFAULT NULL,
  updated_at        TIMESTAMP NULL DEFAULT NULL,
  deleted_at        TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_companies_city (city_id),
  KEY idx_companies_active (is_active, deleted_at),
  CONSTRAINT fk_companies_city FOREIGN KEY (city_id) REFERENCES cities (id) ON DELETE SET NULL,
  CONSTRAINT chk_companies_type CHECK (type IN ('logistics','trucking','taxi','tnvs','delivery','government','station_operator','other')),
  CONSTRAINT chk_companies_tier CHECK (subscription_tier IN ('free','starter','business','enterprise'))
) ENGINE=InnoDB;

CREATE TABLE users (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id            BIGINT UNSIGNED NULL,
  first_name            VARCHAR(80)  NOT NULL,
  last_name             VARCHAR(80)  NOT NULL,
  email                 VARCHAR(180) NOT NULL,
  phone                 VARCHAR(32)  NULL,
  password              VARCHAR(255) NULL,           -- NULL for pure-OAuth accounts
  avatar_path           VARCHAR(255) NULL,
  locale                VARCHAR(8)   NOT NULL DEFAULT 'en',
  timezone              VARCHAR(48)  NOT NULL DEFAULT 'Asia/Manila',
  home_city_id          BIGINT UNSIGNED NULL,
  status                VARCHAR(16)  NOT NULL DEFAULT 'active',
  email_verified_at     TIMESTAMP NULL DEFAULT NULL,
  phone_verified_at     TIMESTAMP NULL DEFAULT NULL,
  mfa_enabled           TINYINT(1)   NOT NULL DEFAULT 0,
  mfa_secret            VARBINARY(512) NULL,          -- AES-256-GCM ciphertext
  mfa_recovery_codes    VARBINARY(2048) NULL,
  biometric_enabled     TINYINT(1)   NOT NULL DEFAULT 0,
  last_login_at         TIMESTAMP NULL DEFAULT NULL,
  last_login_ip         VARBINARY(16) NULL,
  failed_login_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until          TIMESTAMP NULL DEFAULT NULL,
  remember_token        VARCHAR(100) NULL,
  created_at            TIMESTAMP NULL DEFAULT NULL,
  updated_at            TIMESTAMP NULL DEFAULT NULL,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_company (company_id),
  KEY idx_users_status (status, deleted_at),
  KEY idx_users_city (home_city_id),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL,
  CONSTRAINT fk_users_city FOREIGN KEY (home_city_id) REFERENCES cities (id) ON DELETE SET NULL,
  CONSTRAINT chk_users_status CHECK (status IN ('active','pending','suspended','banned'))
) ENGINE=InnoDB;

CREATE TABLE oauth_accounts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  provider      VARCHAR(24)  NOT NULL,
  provider_uid  VARCHAR(191) NOT NULL,
  email         VARCHAR(180) NULL,
  raw_payload   JSON NULL,
  created_at    TIMESTAMP NULL DEFAULT NULL,
  updated_at    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oauth_provider_uid (provider, provider_uid),
  KEY idx_oauth_user (user_id),
  CONSTRAINT fk_oauth_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_oauth_provider CHECK (provider IN ('google','apple','facebook'))
) ENGINE=InnoDB;

CREATE TABLE user_devices (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,
  device_uuid    VARCHAR(64)  NOT NULL,
  device_name    VARCHAR(120) NULL,
  platform       VARCHAR(16)  NOT NULL DEFAULT 'web',
  fcm_token      VARCHAR(255) NULL,
  biometric_key  TEXT NULL,                 -- device public key for biometric challenge
  last_seen_at   TIMESTAMP NULL DEFAULT NULL,
  is_trusted     TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NULL DEFAULT NULL,
  updated_at     TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_device (user_id, device_uuid),
  KEY idx_user_devices_fcm (fcm_token(64)),
  CONSTRAINT fk_user_devices_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_user_devices_platform CHECK (platform IN ('web','ios','android'))
) ENGINE=InnoDB;

CREATE TABLE user_preferences (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                  BIGINT UNSIGNED NOT NULL,
  theme                    VARCHAR(12) NOT NULL DEFAULT 'system',
  preferred_fuel_type_id   BIGINT UNSIGNED NULL,
  price_alert_threshold    DECIMAL(6,2) NULL,
  alert_radius_km          DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  notify_price_alerts      TINYINT(1) NOT NULL DEFAULT 1,
  notify_maintenance       TINYINT(1) NOT NULL DEFAULT 1,
  notify_ai_insights       TINYINT(1) NOT NULL DEFAULT 1,
  notify_marketing         TINYINT(1) NOT NULL DEFAULT 0,
  quiet_hours_start        TIME NULL,
  quiet_hours_end          TIME NULL,
  created_at               TIMESTAMP NULL DEFAULT NULL,
  updated_at               TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_preferences_user (user_id),
  CONSTRAINT fk_user_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_user_pref_theme CHECK (theme IN ('light','dark','system'))
) ENGINE=InnoDB;

-- Spatie permission tables ---------------------------------------------------

CREATE TABLE roles (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(64)  NOT NULL,
  guard_name  VARCHAR(32)  NOT NULL DEFAULT 'api',
  label       VARCHAR(120) NULL,
  level       SMALLINT UNSIGNED NOT NULL DEFAULT 10,   -- lower = more privileged
  created_at  TIMESTAMP NULL DEFAULT NULL,
  updated_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name_guard (name, guard_name)
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(96) NOT NULL,
  guard_name  VARCHAR(32) NOT NULL DEFAULT 'api',
  group_name  VARCHAR(48) NULL,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  updated_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_name_guard (name, guard_name),
  KEY idx_permissions_group (group_name)
) ENGINE=InnoDB;

CREATE TABLE role_has_permissions (
  permission_id BIGINT UNSIGNED NOT NULL,
  role_id       BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (permission_id, role_id),
  KEY idx_rhp_role (role_id),
  CONSTRAINT fk_rhp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE,
  CONSTRAINT fk_rhp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE model_has_roles (
  role_id    BIGINT UNSIGNED NOT NULL,
  model_type VARCHAR(191) NOT NULL,
  model_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, model_id, model_type),
  KEY idx_mhr_model (model_id, model_type),
  CONSTRAINT fk_mhr_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE model_has_permissions (
  permission_id BIGINT UNSIGNED NOT NULL,
  model_type    VARCHAR(191) NOT NULL,
  model_id      BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (permission_id, model_id, model_type),
  KEY idx_mhp_model (model_id, model_type),
  CONSTRAINT fk_mhp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
  email      VARCHAR(180) NOT NULL,
  token      VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (email)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(180) NOT NULL,
  user_id       BIGINT UNSIGNED NULL,
  ip_address    VARBINARY(16) NOT NULL,
  user_agent    VARCHAR(255) NULL,
  succeeded     TINYINT(1) NOT NULL DEFAULT 0,
  failure_reason VARCHAR(64) NULL,
  attempted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_attempts_email_time (email, attempted_at),
  KEY idx_login_attempts_ip_time (ip_address, attempted_at),
  CONSTRAINT fk_login_attempts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE jwt_blacklist (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  jti         VARCHAR(64) NOT NULL,
  user_id     BIGINT UNSIGNED NULL,
  expires_at  TIMESTAMP NOT NULL,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jwt_blacklist_jti (jti),
  KEY idx_jwt_blacklist_expiry (expires_at)
) ENGINE=InnoDB;

-- ===========================================================================
-- 3. FUEL & STATION REFERENCE
-- ===========================================================================

CREATE TABLE fuel_types (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(24)  NOT NULL,     -- gasoline_ron91, diesel, ...
  name        VARCHAR(80)  NOT NULL,
  category    VARCHAR(16)  NOT NULL,     -- gasoline | diesel | lpg | ev
  octane      SMALLINT UNSIGNED NULL,
  unit        VARCHAR(8)   NOT NULL DEFAULT 'L',
  color_hex   CHAR(7)      NOT NULL DEFAULT '#2563EB',
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  updated_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fuel_types_code (code),
  CONSTRAINT chk_fuel_types_category CHECK (category IN ('gasoline','diesel','lpg','ev','cng'))
) ENGINE=InnoDB;

CREATE TABLE brands (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(24)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  logo_path   VARCHAR(255) NULL,
  website     VARCHAR(180) NULL,
  color_hex   CHAR(7)      NOT NULL DEFAULT '#0F172A',
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  updated_at  TIMESTAMP NULL DEFAULT NULL,
  deleted_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_brands_code (code)
) ENGINE=InnoDB;

CREATE TABLE amenities (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(32) NOT NULL,
  name       VARCHAR(80) NOT NULL,
  icon       VARCHAR(48) NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_amenities_code (code)
) ENGINE=InnoDB;

CREATE TABLE payment_methods (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(32) NOT NULL,
  name       VARCHAR(80) NOT NULL,
  icon       VARCHAR(48) NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_methods_code (code)
) ENGINE=InnoDB;

CREATE TABLE gas_stations (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  brand_id       BIGINT UNSIGNED NOT NULL,
  operator_id    BIGINT UNSIGNED NULL,          -- owning company
  managed_by     BIGINT UNSIGNED NULL,          -- station admin user
  name           VARCHAR(180) NOT NULL,
  slug           VARCHAR(200) NOT NULL,
  address_line   VARCHAR(255) NOT NULL,
  city_id        BIGINT UNSIGNED NOT NULL,
  postal_code    VARCHAR(12)  NULL,
  latitude       DECIMAL(10,7) NOT NULL,
  longitude      DECIMAL(10,7) NOT NULL,
  location       POINT GENERATED ALWAYS AS (ST_SRID(POINT(longitude, latitude), 4326)) STORED NOT NULL,
  phone          VARCHAR(32)  NULL,
  is_24_hours    TINYINT(1)   NOT NULL DEFAULT 0,
  has_ev_charging TINYINT(1)  NOT NULL DEFAULT 0,
  status         VARCHAR(16)  NOT NULL DEFAULT 'active',
  verified_at    TIMESTAMP NULL DEFAULT NULL,
  rating_avg     DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  rating_count   INT UNSIGNED NOT NULL DEFAULT 0,
  created_by     BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NULL DEFAULT NULL,
  updated_at     TIMESTAMP NULL DEFAULT NULL,
  deleted_at     TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gas_stations_slug (slug),
  KEY idx_gas_stations_brand (brand_id),
  KEY idx_gas_stations_city (city_id),
  KEY idx_gas_stations_status (status, deleted_at),
  SPATIAL KEY spx_gas_stations_location (location),
  CONSTRAINT fk_gas_stations_brand FOREIGN KEY (brand_id) REFERENCES brands (id),
  CONSTRAINT fk_gas_stations_city FOREIGN KEY (city_id) REFERENCES cities (id),
  CONSTRAINT fk_gas_stations_operator FOREIGN KEY (operator_id) REFERENCES companies (id) ON DELETE SET NULL,
  CONSTRAINT fk_gas_stations_manager FOREIGN KEY (managed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_gas_stations_status CHECK (status IN ('active','temporarily_closed','permanently_closed','pending_review')),
  CONSTRAINT chk_gas_stations_lat CHECK (latitude BETWEEN -90 AND 90),
  CONSTRAINT chk_gas_stations_lng CHECK (longitude BETWEEN -180 AND 180)
) ENGINE=InnoDB;

CREATE TABLE station_amenity (
  station_id BIGINT UNSIGNED NOT NULL,
  amenity_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (station_id, amenity_id),
  KEY idx_station_amenity_amenity (amenity_id),
  CONSTRAINT fk_sa_station FOREIGN KEY (station_id) REFERENCES gas_stations (id) ON DELETE CASCADE,
  CONSTRAINT fk_sa_amenity FOREIGN KEY (amenity_id) REFERENCES amenities (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE station_payment_method (
  station_id        BIGINT UNSIGNED NOT NULL,
  payment_method_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (station_id, payment_method_id),
  KEY idx_spm_method (payment_method_id),
  CONSTRAINT fk_spm_station FOREIGN KEY (station_id) REFERENCES gas_stations (id) ON DELETE CASCADE,
  CONSTRAINT fk_spm_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE station_hours (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  station_id  BIGINT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,     -- 0 = Sunday
  opens_at    TIME NULL,
  closes_at   TIME NULL,
  is_closed   TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_station_hours (station_id, day_of_week),
  CONSTRAINT fk_station_hours_station FOREIGN KEY (station_id) REFERENCES gas_stations (id) ON DELETE CASCADE,
  CONSTRAINT chk_station_hours_dow CHECK (day_of_week BETWEEN 0 AND 6)
) ENGINE=InnoDB;

CREATE TABLE station_photos (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  station_id  BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NULL,
  path        VARCHAR(255) NOT NULL,
  caption     VARCHAR(180) NULL,
  is_primary  TINYINT(1) NOT NULL DEFAULT 0,
  status      VARCHAR(16) NOT NULL DEFAULT 'pending',
  created_at  TIMESTAMP NULL DEFAULT NULL,
  updated_at  TIMESTAMP NULL DEFAULT NULL,
  deleted_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_station_photos_station (station_id, status),
  CONSTRAINT fk_station_photos_station FOREIGN KEY (station_id) REFERENCES gas_stations (id) ON DELETE CASCADE,
  CONSTRAINT fk_station_photos_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_station_photos_status CHECK (status IN ('pending','approved','rejected'))
) ENGINE=InnoDB;

CREATE TABLE station_ratings (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  station_id  BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  rating      TINYINT UNSIGNED NOT NULL,
  comment     VARCHAR(500) NULL,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  updated_at  TIMESTAMP NULL DEFAULT NULL,
  deleted_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_station_ratings (station_id, user_id),
  CONSTRAINT fk_station_ratings_station FOREIGN KEY (station_id) REFERENCES gas_stations (id) ON DELETE CASCADE,
  CONSTRAINT fk_station_ratings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_station_ratings_value CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- ===========================================================================
-- 4. PRICE INTELLIGENCE
-- ===========================================================================

-- Current price per station × fuel type (hot table, read heavy).
CREATE TABLE station_prices (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  station_id     BIGINT UNSIGNED NOT NULL,
  fuel_type_id   BIGINT UNSIGNED NOT NULL,
  price          DECIMAL(10,4) NOT NULL,
  previous_price DECIMAL(10,4) NULL,
  change_amount  DECIMAL(10,4) GENERATED ALWAYS AS (price - COALESCE(previous_price, price)) STORED,
  source         VARCHAR(16) NOT NULL DEFAULT 'operator',
  confidence     DECIMAL(4,3) NOT NULL DEFAULT 1.000,
  effective_at   TIMESTAMP NOT NULL,
  reported_by    BIGINT UNSIGNED NULL,
  verified_at    TIMESTAMP NULL DEFAULT NULL,
  created_at     TIMESTAMP NULL DEFAULT NULL,
  updated_at     TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_station_prices (station_id, fuel_type_id),
  KEY idx_station_prices_fuel_price (fuel_type_id, price),
  KEY idx_station_prices_effective (effective_at),
  CONSTRAINT fk_station_prices_station FOREIGN KEY (station_id) REFERENCES gas_stations (id) ON DELETE CASCADE,
  CONSTRAINT fk_station_prices_fuel FOREIGN KEY (fuel_type_id) REFERENCES fuel_types (id),
  CONSTRAINT fk_station_prices_reporter FOREIGN KEY (reported_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_station_prices_source CHECK (source IN ('operator','doe','crowd','ocr','import','ai_estimate')),
  CONSTRAINT chk_station_prices_price CHECK (price > 0 AND price < 1000)
) ENGINE=InnoDB;

-- Append-only history; partitioned by month in production.
CREATE TABLE fuel_price_history (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  station_id    BIGINT UNSIGNED NOT NULL,
  fuel_type_id  BIGINT UNSIGNED NOT NULL,
  region_id     BIGINT UNSIGNED NULL,
  price         DECIMAL(10,4) NOT NULL,
  source        VARCHAR(16) NOT NULL DEFAULT 'operator',
  recorded_on   DATE NOT NULL,
  recorded_at   TIMESTAMP NOT NULL,
  PRIMARY KEY (id, recorded_on),
  KEY idx_fph_station_fuel_date (station_id, fuel_type_id, recorded_on),
  KEY idx_fph_region_fuel_date (region_id, fuel_type_id, recorded_on),
  KEY idx_fph_date (recorded_on)
) ENGINE=InnoDB
PARTITION BY RANGE (YEAR(recorded_on) * 100 + MONTH(recorded_on)) (
  PARTITION p202601 VALUES LESS THAN (202602),
  PARTITION p202602 VALUES LESS THAN (202603),
  PARTITION p202603 VALUES LESS THAN (202604),
  PARTITION p202604 VALUES LESS THAN (202605),
  PARTITION p202605 VALUES LESS THAN (202606),
  PARTITION p202606 VALUES LESS THAN (202607),
  PARTITION p202607 VALUES LESS THAN (202608),
  PARTITION p202608 VALUES LESS THAN (202609),
  PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- Weekly DOE oil price adjustment advisories (the "Tuesday rollback").
CREATE TABLE price_advisories (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fuel_type_id   BIGINT UNSIGNED NOT NULL,
  region_id      BIGINT UNSIGNED NULL,          -- NULL = nationwide
  week_start     DATE NOT NULL,
  effective_at   TIMESTAMP NOT NULL,
  change_amount  DECIMAL(10,4) NOT NULL,        -- +increase / -rollback per litre
  direction      VARCHAR(16) NOT NULL,
  source         VARCHAR(24) NOT NULL DEFAULT 'doe',
  source_url     VARCHAR(255) NULL,
  notes          VARCHAR(500) NULL,
  created_at     TIMESTAMP NULL DEFAULT NULL,
  updated_at     TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_price_advisories (fuel_type_id, region_id, week_start),
  KEY idx_price_advisories_week (week_start),
  CONSTRAINT fk_price_advisories_fuel FOREIGN KEY (fuel_type_id) REFERENCES fuel_types (id),
  CONSTRAINT fk_price_advisories_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE CASCADE,
  CONSTRAINT chk_price_advisories_direction CHECK (direction IN ('increase','rollback','no_change'))
) ENGINE=InnoDB;

-- Crowd-sourced submissions awaiting moderation.
CREATE TABLE price_reports (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  station_id     BIGINT UNSIGNED NOT NULL,
  fuel_type_id   BIGINT UNSIGNED NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  report_type    VARCHAR(24) NOT NULL DEFAULT 'price',
  price          DECIMAL(10,4) NULL,
  photo_path     VARCHAR(255) NULL,
  comment        VARCHAR(500) NULL,
  latitude       DECIMAL(10,7) NULL,
  longitude      DECIMAL(10,7) NULL,
  distance_m     INT UNSIGNED NULL,             -- reporter distance from station
  trust_score    DECIMAL(4,3) NOT NULL DEFAULT 0.500,
  status         VARCHAR(16) NOT NULL DEFAULT 'pending',
  moderated_by   BIGINT UNSIGNED NULL,
  moderated_at   TIMESTAMP NULL DEFAULT NULL,
  rejection_reason VARCHAR(180) NULL,
  upvotes        INT UNSIGNED NOT NULL DEFAULT 0,
  downvotes      INT UNSIGNED NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NULL DEFAULT NULL,
  updated_at     TIMESTAMP NULL DEFAULT NULL,
  deleted_at     TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_price_reports_station (station_id, status),
  KEY idx_price_reports_user (user_id),
  KEY idx_price_reports_status_created (status, created_at),
  CONSTRAINT fk_price_reports_station FOREIGN KEY (station_id) REFERENCES gas_stations (id) ON DELETE CASCADE,
  CONSTRAINT fk_price_reports_fuel FOREIGN KEY (fuel_type_id) REFERENCES fuel_types (id) ON DELETE SET NULL,
  CONSTRAINT fk_price_reports_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_price_reports_moderator FOREIGN KEY (moderated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_price_reports_type CHECK (report_type IN ('price','shortage','closure','long_queue','wrong_info')),
  CONSTRAINT chk_price_reports_status CHECK (status IN ('pending','approved','rejected','auto_approved','flagged'))
) ENGINE=InnoDB;

CREATE TABLE price_report_votes (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  price_report_id  BIGINT UNSIGNED NOT NULL,
  user_id          BIGINT UNSIGNED NOT NULL,
  vote             TINYINT NOT NULL,          -- +1 / -1
  created_at       TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_price_report_votes (price_report_id, user_id),
  CONSTRAINT fk_prv_report FOREIGN KEY (price_report_id) REFERENCES price_reports (id) ON DELETE CASCADE,
  CONSTRAINT fk_prv_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_prv_vote CHECK (vote IN (-1, 1))
) ENGINE=InnoDB;

-- OCR scans of physical price boards.
CREATE TABLE ocr_scans (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NOT NULL,
  station_id      BIGINT UNSIGNED NULL,
  image_path      VARCHAR(255) NOT NULL,
  raw_text        TEXT NULL,
  parsed_payload  JSON NULL,                  -- [{fuel_type, price, confidence, bbox}]
  engine          VARCHAR(24) NOT NULL DEFAULT 'tesseract',
  overall_confidence DECIMAL(4,3) NULL,
  status          VARCHAR(16) NOT NULL DEFAULT 'processing',
  reviewed_by     BIGINT UNSIGNED NULL,
  reviewed_at     TIMESTAMP NULL DEFAULT NULL,
  error_message   VARCHAR(255) NULL,
  created_at      TIMESTAMP NULL DEFAULT NULL,
  updated_at      TIMESTAMP NULL DEFAULT NULL,
  deleted_at      TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ocr_scans_user (user_id),
  KEY idx_ocr_scans_status (status, created_at),
  CONSTRAINT fk_ocr_scans_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_ocr_scans_station FOREIGN KEY (station_id) REFERENCES gas_stations (id) ON DELETE SET NULL,
  CONSTRAINT fk_ocr_scans_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_ocr_scans_status CHECK (status IN ('processing','parsed','needs_review','approved','rejected','failed'))
) ENGINE=InnoDB;

-- Exogenous market signals feeding the forecasting models.
CREATE TABLE market_indicators (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  indicator     VARCHAR(32) NOT NULL,   -- dubai_crude, brent, wti, usd_php, mops_gasoline
  observed_on   DATE NOT NULL,
  value         DECIMAL(14,5) NOT NULL,
  unit          VARCHAR(16) NOT NULL DEFAULT 'USD/bbl',
  source        VARCHAR(48) NOT NULL,
  created_at    TIMESTAMP NULL DEFAULT NULL,
  updated_at    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_market_indicators (indicator, observed_on),
  KEY idx_market_indicators_date (observed_on)
) ENGINE=InnoDB;

-- ===========================================================================
-- 5. VEHICLES, DRIVERS & FLEETS
-- ===========================================================================

CREATE TABLE vehicle_makes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(80) NOT NULL,
  logo_path  VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vehicle_makes_name (name)
) ENGINE=InnoDB;

CREATE TABLE vehicle_models (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  make_id       BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(80) NOT NULL,
  body_type     VARCHAR(32) NULL,
  default_fuel_type_id BIGINT UNSIGNED NULL,
  tank_capacity DECIMAL(6,2) NULL,
  created_at    TIMESTAMP NULL DEFAULT NULL,
  updated_at    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vehicle_models (make_id, name),
  CONSTRAINT fk_vehicle_models_make FOREIGN KEY (make_id) REFERENCES vehicle_makes (id) ON DELETE CASCADE,
  CONSTRAINT fk_vehicle_models_fuel FOREIGN KEY (default_fuel_type_id) REFERENCES fuel_types (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE fleets (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  code        VARCHAR(32)  NULL,
  manager_id  BIGINT UNSIGNED NULL,
  base_city_id BIGINT UNSIGNED NULL,
  monthly_fuel_budget DECIMAL(14,2) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  updated_at  TIMESTAMP NULL DEFAULT NULL,
  deleted_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fleets_company_code (company_id, code),
  KEY idx_fleets_manager (manager_id),
  CONSTRAINT fk_fleets_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_fleets_manager FOREIGN KEY (manager_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_fleets_city FOREIGN KEY (base_city_id) REFERENCES cities (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE drivers (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            BIGINT UNSIGNED NULL,        -- NULL for non-app drivers
  company_id         BIGINT UNSIGNED NULL,
  fleet_id           BIGINT UNSIGNED NULL,
  employee_no        VARCHAR(40) NULL,
  first_name         VARCHAR(80) NOT NULL,
  last_name          VARCHAR(80) NOT NULL,
  phone              VARCHAR(32) NULL,
  licence_number     VARCHAR(40) NULL,
  licence_type       VARCHAR(16) NULL,
  licence_expiry     DATE NULL,
  hired_at           DATE NULL,
  safety_score       DECIMAL(5,2) NOT NULL DEFAULT 100.00,
  efficiency_score   DECIMAL(5,2) NOT NULL DEFAULT 100.00,
  status             VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at         TIMESTAMP NULL DEFAULT NULL,
  updated_at         TIMESTAMP NULL DEFAULT NULL,
  deleted_at         TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_drivers_company_employee (company_id, employee_no),
  KEY idx_drivers_fleet (fleet_id),
  KEY idx_drivers_user (user_id),
  KEY idx_drivers_licence_expiry (licence_expiry),
  CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_drivers_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_drivers_fleet FOREIGN KEY (fleet_id) REFERENCES fleets (id) ON DELETE SET NULL,
  CONSTRAINT chk_drivers_status CHECK (status IN ('active','on_leave','suspended','resigned'))
) ENGINE=InnoDB;

CREATE TABLE vehicles (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id           BIGINT UNSIGNED NULL,        -- private owner
  company_id         BIGINT UNSIGNED NULL,
  fleet_id           BIGINT UNSIGNED NULL,
  make_id            BIGINT UNSIGNED NULL,
  model_id           BIGINT UNSIGNED NULL,
  fuel_type_id       BIGINT UNSIGNED NOT NULL,
  nickname           VARCHAR(80) NULL,
  plate_number       VARCHAR(16) NOT NULL,
  vin                VARCHAR(32) NULL,
  engine_number      VARCHAR(32) NULL,
  vehicle_type       VARCHAR(24) NOT NULL DEFAULT 'car',
  year               SMALLINT UNSIGNED NULL,
  color              VARCHAR(32) NULL,
  transmission       VARCHAR(16) NULL,
  engine_displacement_cc INT UNSIGNED NULL,
  tank_capacity      DECIMAL(6,2) NULL,
  current_odometer   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  baseline_km_per_litre DECIMAL(6,2) NULL,
  avg_km_per_litre   DECIMAL(6,2) NULL,
  registration_expiry DATE NULL,
  insurance_provider VARCHAR(120) NULL,
  insurance_policy_no VARCHAR(64) NULL,
  insurance_expiry   DATE NULL,
  photo_path         VARCHAR(255) NULL,
  status             VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at         TIMESTAMP NULL DEFAULT NULL,
  updated_at         TIMESTAMP NULL DEFAULT NULL,
  deleted_at         TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vehicles_plate (plate_number, deleted_at),
  KEY idx_vehicles_owner (owner_id),
  KEY idx_vehicles_company (company_id),
  KEY idx_vehicles_fleet (fleet_id),
  KEY idx_vehicles_reg_expiry (registration_expiry),
  KEY idx_vehicles_ins_expiry (insurance_expiry),
  CONSTRAINT fk_vehicles_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_vehicles_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_vehicles_fleet FOREIGN KEY (fleet_id) REFERENCES fleets (id) ON DELETE SET NULL,
  CONSTRAINT fk_vehicles_make FOREIGN KEY (make_id) REFERENCES vehicle_makes (id) ON DELETE SET NULL,
  CONSTRAINT fk_vehicles_model FOREIGN KEY (model_id) REFERENCES vehicle_models (id) ON DELETE SET NULL,
  CONSTRAINT fk_vehicles_fuel FOREIGN KEY (fuel_type_id) REFERENCES fuel_types (id),
  CONSTRAINT chk_vehicles_type CHECK (vehicle_type IN ('car','suv','van','motorcycle','tricycle','jeepney','truck','bus','trailer','ev')),
  CONSTRAINT chk_vehicles_status CHECK (status IN ('active','in_maintenance','inactive','sold'))
) ENGINE=InnoDB;

CREATE TABLE vehicle_assignments (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id   BIGINT UNSIGNED NOT NULL,
  driver_id    BIGINT UNSIGNED NOT NULL,
  assigned_at  TIMESTAMP NOT NULL,
  released_at  TIMESTAMP NULL DEFAULT NULL,
  assigned_by  BIGINT UNSIGNED NULL,
  notes        VARCHAR(255) NULL,
  created_at   TIMESTAMP NULL DEFAULT NULL,
  updated_at   TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_va_vehicle_active (vehicle_id, released_at),
  KEY idx_va_driver_active (driver_id, released_at),
  CONSTRAINT fk_va_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE CASCADE,
  CONSTRAINT fk_va_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE CASCADE,
  CONSTRAINT fk_va_assigner FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE odometer_readings (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id  BIGINT UNSIGNED NOT NULL,
  reading     DECIMAL(12,2) NOT NULL,
  source      VARCHAR(16) NOT NULL DEFAULT 'manual',
  recorded_at TIMESTAMP NOT NULL,
  recorded_by BIGINT UNSIGNED NULL,
  photo_path  VARCHAR(255) NULL,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_odo_vehicle_time (vehicle_id, recorded_at),
  CONSTRAINT fk_odo_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE CASCADE,
  CONSTRAINT fk_odo_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_odo_source CHECK (source IN ('manual','fuel_log','telematics','ocr'))
) ENGINE=InnoDB;

CREATE TABLE vehicle_documents (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id   BIGINT UNSIGNED NOT NULL,
  type         VARCHAR(24) NOT NULL,
  number       VARCHAR(64) NULL,
  issued_on    DATE NULL,
  expires_on   DATE NULL,
  file_path    VARCHAR(255) NULL,
  notes        VARCHAR(255) NULL,
  created_at   TIMESTAMP NULL DEFAULT NULL,
  updated_at   TIMESTAMP NULL DEFAULT NULL,
  deleted_at   TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_vehicle_documents_vehicle (vehicle_id, type),
  KEY idx_vehicle_documents_expiry (expires_on),
  CONSTRAINT fk_vehicle_documents_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE CASCADE,
  CONSTRAINT chk_vehicle_documents_type CHECK (type IN ('registration','insurance','emission','franchise','inspection','other'))
) ENGINE=InnoDB;

-- ===========================================================================
-- 6. EXPENSES & TRIPS
-- ===========================================================================

CREATE TABLE fuel_purchases (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id        BIGINT UNSIGNED NOT NULL,
  user_id           BIGINT UNSIGNED NOT NULL,
  driver_id         BIGINT UNSIGNED NULL,
  station_id        BIGINT UNSIGNED NULL,
  fuel_type_id      BIGINT UNSIGNED NOT NULL,
  litres            DECIMAL(9,3) NOT NULL,
  price_per_litre   DECIMAL(10,4) NOT NULL,
  total_cost        DECIMAL(12,2) NOT NULL,
  odometer          DECIMAL(12,2) NULL,
  distance_since_last DECIMAL(10,2) NULL,
  km_per_litre      DECIMAL(6,2) NULL,
  cost_per_km       DECIMAL(8,4) NULL,
  is_full_tank      TINYINT(1) NOT NULL DEFAULT 1,
  payment_method_id BIGINT UNSIGNED NULL,
  receipt_path      VARCHAR(255) NULL,
  notes             VARCHAR(255) NULL,
  latitude          DECIMAL(10,7) NULL,
  longitude         DECIMAL(10,7) NULL,
  purchased_at      TIMESTAMP NOT NULL,
  anomaly_score     DECIMAL(4,3) NULL,
  created_at        TIMESTAMP NULL DEFAULT NULL,
  updated_at        TIMESTAMP NULL DEFAULT NULL,
  deleted_at        TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_fp_vehicle_time (vehicle_id, purchased_at),
  KEY idx_fp_user_time (user_id, purchased_at),
  KEY idx_fp_station (station_id),
  KEY idx_fp_driver (driver_id),
  KEY idx_fp_anomaly (anomaly_score),
  CONSTRAINT fk_fp_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE CASCADE,
  CONSTRAINT fk_fp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_fp_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE SET NULL,
  CONSTRAINT fk_fp_station FOREIGN KEY (station_id) REFERENCES gas_stations (id) ON DELETE SET NULL,
  CONSTRAINT fk_fp_fuel FOREIGN KEY (fuel_type_id) REFERENCES fuel_types (id),
  CONSTRAINT fk_fp_payment FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id) ON DELETE SET NULL,
  CONSTRAINT chk_fp_litres CHECK (litres > 0),
  CONSTRAINT chk_fp_total CHECK (total_cost >= 0)
) ENGINE=InnoDB;

CREATE TABLE trips (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id         BIGINT UNSIGNED NOT NULL,
  driver_id          BIGINT UNSIGNED NULL,
  fleet_id           BIGINT UNSIGNED NULL,
  reference_no       VARCHAR(40) NULL,
  origin_label       VARCHAR(180) NULL,
  origin_lat         DECIMAL(10,7) NULL,
  origin_lng         DECIMAL(10,7) NULL,
  destination_label  VARCHAR(180) NULL,
  destination_lat    DECIMAL(10,7) NULL,
  destination_lng    DECIMAL(10,7) NULL,
  distance_km        DECIMAL(10,2) NULL,
  duration_minutes   INT UNSIGNED NULL,
  fuel_consumed_l    DECIMAL(9,3) NULL,
  fuel_cost          DECIMAL(12,2) NULL,
  toll_cost          DECIMAL(12,2) NULL,
  started_at         TIMESTAMP NULL DEFAULT NULL,
  ended_at           TIMESTAMP NULL DEFAULT NULL,
  status             VARCHAR(16) NOT NULL DEFAULT 'planned',
  created_at         TIMESTAMP NULL DEFAULT NULL,
  updated_at         TIMESTAMP NULL DEFAULT NULL,
  deleted_at         TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_trips_vehicle_time (vehicle_id, started_at),
  KEY idx_trips_fleet_time (fleet_id, started_at),
  KEY idx_trips_driver (driver_id),
  CONSTRAINT fk_trips_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE CASCADE,
  CONSTRAINT fk_trips_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE SET NULL,
  CONSTRAINT fk_trips_fleet FOREIGN KEY (fleet_id) REFERENCES fleets (id) ON DELETE SET NULL,
  CONSTRAINT chk_trips_status CHECK (status IN ('planned','in_progress','completed','cancelled'))
) ENGINE=InnoDB;

CREATE TABLE route_plans (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            BIGINT UNSIGNED NOT NULL,
  vehicle_id         BIGINT UNSIGNED NULL,
  trip_id            BIGINT UNSIGNED NULL,
  origin_label       VARCHAR(180) NOT NULL,
  destination_label  VARCHAR(180) NOT NULL,
  origin_lat         DECIMAL(10,7) NOT NULL,
  origin_lng         DECIMAL(10,7) NOT NULL,
  destination_lat    DECIMAL(10,7) NOT NULL,
  destination_lng    DECIMAL(10,7) NOT NULL,
  optimize_for       VARCHAR(16) NOT NULL DEFAULT 'cost',
  selected_option    SMALLINT UNSIGNED NULL,
  options_payload    JSON NOT NULL,             -- [{polyline, distance, duration, fuel, toll, stations}]
  estimated_savings  DECIMAL(10,2) NULL,
  created_at         TIMESTAMP NULL DEFAULT NULL,
  updated_at         TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_route_plans_user (user_id, created_at),
  CONSTRAINT fk_route_plans_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_route_plans_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE SET NULL,
  CONSTRAINT fk_route_plans_trip FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE SET NULL,
  CONSTRAINT chk_route_plans_optimize CHECK (optimize_for IN ('cost','time','fuel','balanced'))
) ENGINE=InnoDB;

-- ===========================================================================
-- 7. MAINTENANCE
-- ===========================================================================

CREATE TABLE maintenance_types (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                VARCHAR(32) NOT NULL,
  name                VARCHAR(120) NOT NULL,
  category            VARCHAR(24) NOT NULL DEFAULT 'preventive',
  default_interval_km INT UNSIGNED NULL,
  default_interval_days INT UNSIGNED NULL,
  icon                VARCHAR(48) NULL,
  created_at          TIMESTAMP NULL DEFAULT NULL,
  updated_at          TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_maintenance_types_code (code),
  CONSTRAINT chk_maintenance_types_category CHECK (category IN ('preventive','corrective','legal','inspection'))
) ENGINE=InnoDB;

CREATE TABLE maintenance_records (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id          BIGINT UNSIGNED NOT NULL,
  maintenance_type_id BIGINT UNSIGNED NOT NULL,
  performed_at        DATE NOT NULL,
  odometer            DECIMAL(12,2) NULL,
  cost                DECIMAL(12,2) NULL,
  vendor              VARCHAR(180) NULL,
  invoice_path        VARCHAR(255) NULL,
  notes               VARCHAR(500) NULL,
  recorded_by         BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP NULL DEFAULT NULL,
  updated_at          TIMESTAMP NULL DEFAULT NULL,
  deleted_at          TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_mr_vehicle_date (vehicle_id, performed_at),
  KEY idx_mr_type (maintenance_type_id),
  CONSTRAINT fk_mr_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE CASCADE,
  CONSTRAINT fk_mr_type FOREIGN KEY (maintenance_type_id) REFERENCES maintenance_types (id),
  CONSTRAINT fk_mr_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE maintenance_schedules (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_id          BIGINT UNSIGNED NOT NULL,
  maintenance_type_id BIGINT UNSIGNED NOT NULL,
  interval_km         INT UNSIGNED NULL,
  interval_days       INT UNSIGNED NULL,
  last_performed_at   DATE NULL,
  last_odometer       DECIMAL(12,2) NULL,
  due_at              DATE NULL,
  due_odometer        DECIMAL(12,2) NULL,
  predicted_due_at    DATE NULL,               -- from the AI model
  prediction_confidence DECIMAL(4,3) NULL,
  status              VARCHAR(16) NOT NULL DEFAULT 'scheduled',
  created_at          TIMESTAMP NULL DEFAULT NULL,
  updated_at          TIMESTAMP NULL DEFAULT NULL,
  deleted_at          TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ms_vehicle_type (vehicle_id, maintenance_type_id, deleted_at),
  KEY idx_ms_due (due_at, status),
  CONSTRAINT fk_ms_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE CASCADE,
  CONSTRAINT fk_ms_type FOREIGN KEY (maintenance_type_id) REFERENCES maintenance_types (id),
  CONSTRAINT chk_ms_status CHECK (status IN ('scheduled','due_soon','overdue','completed','skipped'))
) ENGINE=InnoDB;

-- ===========================================================================
-- 8. AI ARTEFACTS
-- ===========================================================================

CREATE TABLE ai_models (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(48) NOT NULL,     -- price_forecast, consumption, maintenance, fraud
  name          VARCHAR(120) NOT NULL,
  algorithm     VARCHAR(48) NOT NULL,     -- prophet, xgboost, isolation_forest, lstm
  version       VARCHAR(24) NOT NULL,
  artefact_path VARCHAR(255) NULL,
  hyperparameters JSON NULL,
  metrics       JSON NULL,                -- {mae, rmse, mape, r2}
  trained_at    TIMESTAMP NULL DEFAULT NULL,
  training_rows INT UNSIGNED NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NULL DEFAULT NULL,
  updated_at    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ai_models_code_version (code, version),
  KEY idx_ai_models_active (code, is_active)
) ENGINE=InnoDB;

CREATE TABLE price_forecasts (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ai_model_id    BIGINT UNSIGNED NULL,
  fuel_type_id   BIGINT UNSIGNED NOT NULL,
  region_id      BIGINT UNSIGNED NULL,
  forecast_for   DATE NOT NULL,             -- effective week start
  generated_at   TIMESTAMP NOT NULL,
  direction      VARCHAR(16) NOT NULL,
  change_amount  DECIMAL(10,4) NOT NULL,
  predicted_price DECIMAL(10,4) NULL,
  lower_bound    DECIMAL(10,4) NULL,
  upper_bound    DECIMAL(10,4) NULL,
  confidence     DECIMAL(4,3) NOT NULL,
  drivers        JSON NULL,                 -- [{factor, weight, value}]
  narrative      VARCHAR(1000) NULL,
  actual_change  DECIMAL(10,4) NULL,        -- back-filled for accuracy tracking
  absolute_error DECIMAL(10,4) NULL,
  created_at     TIMESTAMP NULL DEFAULT NULL,
  updated_at     TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_price_forecasts (fuel_type_id, region_id, forecast_for, generated_at),
  KEY idx_price_forecasts_week (forecast_for),
  CONSTRAINT fk_pf_model FOREIGN KEY (ai_model_id) REFERENCES ai_models (id) ON DELETE SET NULL,
  CONSTRAINT fk_pf_fuel FOREIGN KEY (fuel_type_id) REFERENCES fuel_types (id),
  CONSTRAINT fk_pf_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE CASCADE,
  CONSTRAINT chk_pf_direction CHECK (direction IN ('increase','rollback','no_change')),
  CONSTRAINT chk_pf_confidence CHECK (confidence BETWEEN 0 AND 1)
) ENGINE=InnoDB;

CREATE TABLE ai_predictions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ai_model_id   BIGINT UNSIGNED NULL,
  subject_type  VARCHAR(64) NOT NULL,       -- Vehicle, Fleet, User
  subject_id    BIGINT UNSIGNED NOT NULL,
  prediction_type VARCHAR(48) NOT NULL,     -- consumption, maintenance, demand
  horizon_days  SMALLINT UNSIGNED NULL,
  payload       JSON NOT NULL,
  confidence    DECIMAL(4,3) NULL,
  generated_at  TIMESTAMP NOT NULL,
  expires_at    TIMESTAMP NULL DEFAULT NULL,
  created_at    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ai_predictions_subject (subject_type, subject_id, prediction_type),
  KEY idx_ai_predictions_generated (generated_at),
  CONSTRAINT fk_ai_predictions_model FOREIGN KEY (ai_model_id) REFERENCES ai_models (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE ai_chat_sessions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  title       VARCHAR(180) NULL,
  context     JSON NULL,                   -- vehicles/location snapshot given to the model
  token_usage INT UNSIGNED NOT NULL DEFAULT 0,
  last_message_at TIMESTAMP NULL DEFAULT NULL,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  updated_at  TIMESTAMP NULL DEFAULT NULL,
  deleted_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_acs_user (user_id, last_message_at),
  CONSTRAINT fk_acs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ai_chat_messages (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id  BIGINT UNSIGNED NOT NULL,
  role        VARCHAR(12) NOT NULL,
  content     MEDIUMTEXT NOT NULL,
  tool_calls  JSON NULL,
  tokens      INT UNSIGNED NULL,
  latency_ms  INT UNSIGNED NULL,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_acm_session (session_id, created_at),
  CONSTRAINT fk_acm_session FOREIGN KEY (session_id) REFERENCES ai_chat_sessions (id) ON DELETE CASCADE,
  CONSTRAINT chk_acm_role CHECK (role IN ('system','user','assistant','tool'))
) ENGINE=InnoDB;

CREATE TABLE fraud_alerts (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id     BIGINT UNSIGNED NULL,
  fleet_id       BIGINT UNSIGNED NULL,
  vehicle_id     BIGINT UNSIGNED NULL,
  driver_id      BIGINT UNSIGNED NULL,
  fuel_purchase_id BIGINT UNSIGNED NULL,
  alert_type     VARCHAR(32) NOT NULL,     -- overfill, ghost_refuel, price_mismatch, odometer_rollback
  severity       VARCHAR(12) NOT NULL DEFAULT 'medium',
  score          DECIMAL(4,3) NOT NULL,
  evidence       JSON NULL,
  status         VARCHAR(16) NOT NULL DEFAULT 'open',
  resolved_by    BIGINT UNSIGNED NULL,
  resolved_at    TIMESTAMP NULL DEFAULT NULL,
  resolution_note VARCHAR(500) NULL,
  detected_at    TIMESTAMP NOT NULL,
  created_at     TIMESTAMP NULL DEFAULT NULL,
  updated_at     TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_fraud_company_status (company_id, status),
  KEY idx_fraud_vehicle (vehicle_id),
  KEY idx_fraud_detected (detected_at),
  CONSTRAINT fk_fraud_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_fraud_fleet FOREIGN KEY (fleet_id) REFERENCES fleets (id) ON DELETE SET NULL,
  CONSTRAINT fk_fraud_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE SET NULL,
  CONSTRAINT fk_fraud_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE SET NULL,
  CONSTRAINT fk_fraud_purchase FOREIGN KEY (fuel_purchase_id) REFERENCES fuel_purchases (id) ON DELETE SET NULL,
  CONSTRAINT fk_fraud_resolver FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_fraud_severity CHECK (severity IN ('low','medium','high','critical')),
  CONSTRAINT chk_fraud_status CHECK (status IN ('open','investigating','confirmed','dismissed'))
) ENGINE=InnoDB;

-- ===========================================================================
-- 9. NOTIFICATIONS & REPORTS
-- ===========================================================================

CREATE TABLE notification_templates (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(48) NOT NULL,
  channel     VARCHAR(16) NOT NULL DEFAULT 'push',
  title       VARCHAR(180) NOT NULL,
  body        VARCHAR(500) NOT NULL,
  variables   JSON NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  updated_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notification_templates (code, channel),
  CONSTRAINT chk_nt_channel CHECK (channel IN ('push','email','sms','in_app'))
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id           CHAR(36) NOT NULL,
  user_id      BIGINT UNSIGNED NOT NULL,
  type         VARCHAR(96) NOT NULL,
  category     VARCHAR(32) NOT NULL DEFAULT 'general',
  title        VARCHAR(180) NOT NULL,
  body         VARCHAR(500) NOT NULL,
  data         JSON NULL,
  action_url   VARCHAR(255) NULL,
  priority     VARCHAR(12) NOT NULL DEFAULT 'normal',
  read_at      TIMESTAMP NULL DEFAULT NULL,
  sent_at      TIMESTAMP NULL DEFAULT NULL,
  created_at   TIMESTAMP NULL DEFAULT NULL,
  updated_at   TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_notifications_user_read (user_id, read_at, created_at),
  KEY idx_notifications_category (category),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_notifications_priority CHECK (priority IN ('low','normal','high','urgent'))
) ENGINE=InnoDB;

CREATE TABLE price_alerts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  fuel_type_id  BIGINT UNSIGNED NOT NULL,
  station_id    BIGINT UNSIGNED NULL,
  city_id       BIGINT UNSIGNED NULL,
  condition     VARCHAR(16) NOT NULL DEFAULT 'below',
  threshold     DECIMAL(10,4) NOT NULL,
  radius_km     DECIMAL(5,2) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  last_fired_at TIMESTAMP NULL DEFAULT NULL,
  created_at    TIMESTAMP NULL DEFAULT NULL,
  updated_at    TIMESTAMP NULL DEFAULT NULL,
  deleted_at    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_price_alerts_user (user_id, is_active),
  KEY idx_price_alerts_fuel (fuel_type_id, is_active),
  CONSTRAINT fk_price_alerts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_price_alerts_fuel FOREIGN KEY (fuel_type_id) REFERENCES fuel_types (id),
  CONSTRAINT fk_price_alerts_station FOREIGN KEY (station_id) REFERENCES gas_stations (id) ON DELETE CASCADE,
  CONSTRAINT fk_price_alerts_city FOREIGN KEY (city_id) REFERENCES cities (id) ON DELETE CASCADE,
  CONSTRAINT chk_price_alerts_condition CHECK (condition IN ('below','above','any_change'))
) ENGINE=InnoDB;

CREATE TABLE report_definitions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(48) NOT NULL,
  name         VARCHAR(180) NOT NULL,
  description  VARCHAR(500) NULL,
  scope        VARCHAR(24) NOT NULL DEFAULT 'user',
  default_params JSON NULL,
  required_permission VARCHAR(96) NULL,
  created_at   TIMESTAMP NULL DEFAULT NULL,
  updated_at   TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_definitions_code (code),
  CONSTRAINT chk_report_definitions_scope CHECK (scope IN ('user','fleet','company','station','platform'))
) ENGINE=InnoDB;

CREATE TABLE report_runs (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_definition_id  BIGINT UNSIGNED NOT NULL,
  requested_by          BIGINT UNSIGNED NOT NULL,
  company_id            BIGINT UNSIGNED NULL,
  params                JSON NULL,
  format                VARCHAR(8) NOT NULL DEFAULT 'pdf',
  period_start          DATE NULL,
  period_end            DATE NULL,
  status                VARCHAR(16) NOT NULL DEFAULT 'queued',
  file_path             VARCHAR(255) NULL,
  file_size             INT UNSIGNED NULL,
  row_count             INT UNSIGNED NULL,
  error_message         VARCHAR(500) NULL,
  started_at            TIMESTAMP NULL DEFAULT NULL,
  completed_at          TIMESTAMP NULL DEFAULT NULL,
  expires_at            TIMESTAMP NULL DEFAULT NULL,
  created_at            TIMESTAMP NULL DEFAULT NULL,
  updated_at            TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_report_runs_user (requested_by, created_at),
  KEY idx_report_runs_status (status),
  CONSTRAINT fk_report_runs_definition FOREIGN KEY (report_definition_id) REFERENCES report_definitions (id),
  CONSTRAINT fk_report_runs_user FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_report_runs_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT chk_report_runs_format CHECK (format IN ('pdf','xlsx','csv','json')),
  CONSTRAINT chk_report_runs_status CHECK (status IN ('queued','running','completed','failed','expired'))
) ENGINE=InnoDB;

-- ===========================================================================
-- 10. AUDIT & OBSERVABILITY
-- ===========================================================================

CREATE TABLE audit_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NULL,
  impersonator_id BIGINT UNSIGNED NULL,
  event         VARCHAR(48) NOT NULL,        -- created | updated | deleted | restored | login | export
  auditable_type VARCHAR(96) NOT NULL,
  auditable_id  BIGINT UNSIGNED NULL,
  old_values    JSON NULL,
  new_values    JSON NULL,
  url           VARCHAR(255) NULL,
  ip_address    VARBINARY(16) NULL,
  user_agent    VARCHAR(255) NULL,
  request_id    CHAR(36) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_auditable (auditable_type, auditable_id),
  KEY idx_audit_user_time (user_id, created_at),
  KEY idx_audit_event_time (event, created_at)
) ENGINE=InnoDB;

CREATE TABLE api_request_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NULL,
  method        VARCHAR(8)  NOT NULL,
  path          VARCHAR(255) NOT NULL,
  status_code   SMALLINT UNSIGNED NOT NULL,
  duration_ms   INT UNSIGNED NOT NULL,
  ip_address    VARBINARY(16) NULL,
  user_agent    VARCHAR(255) NULL,
  request_id    CHAR(36) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_arl_path_time (path, created_at),
  KEY idx_arl_user_time (user_id, created_at),
  KEY idx_arl_status (status_code)
) ENGINE=InnoDB;

CREATE TABLE settings (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group`     VARCHAR(48) NOT NULL DEFAULT 'general',
  `key`       VARCHAR(96) NOT NULL,
  value       JSON NULL,
  is_public   TINYINT(1) NOT NULL DEFAULT 0,
  updated_by  BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP NULL DEFAULT NULL,
  updated_at  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings (`group`, `key`)
) ENGINE=InnoDB;

-- Laravel infrastructure tables ----------------------------------------------

CREATE TABLE jobs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue        VARCHAR(191) NOT NULL,
  payload      LONGTEXT NOT NULL,
  attempts     TINYINT UNSIGNED NOT NULL,
  reserved_at  INT UNSIGNED NULL,
  available_at INT UNSIGNED NOT NULL,
  created_at   INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_jobs_queue (queue)
) ENGINE=InnoDB;

CREATE TABLE failed_jobs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid       VARCHAR(191) NOT NULL,
  connection TEXT NOT NULL,
  queue      TEXT NOT NULL,
  payload    LONGTEXT NOT NULL,
  exception  LONGTEXT NOT NULL,
  failed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_failed_jobs_uuid (uuid)
) ENGINE=InnoDB;

CREATE TABLE cache (
  `key`      VARCHAR(191) NOT NULL,
  `value`    MEDIUMTEXT NOT NULL,
  expiration INT NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB;

SET foreign_key_checks = 1;

-- ===========================================================================
-- 11. REPORTING VIEWS
-- ===========================================================================

CREATE OR REPLACE VIEW v_station_current_prices AS
SELECT
  gs.id            AS station_id,
  gs.name          AS station_name,
  b.name           AS brand_name,
  c.name           AS city_name,
  r.name           AS region_name,
  ft.code          AS fuel_code,
  ft.name          AS fuel_name,
  sp.price,
  sp.change_amount,
  sp.source,
  sp.effective_at,
  gs.latitude,
  gs.longitude
FROM station_prices sp
JOIN gas_stations gs ON gs.id = sp.station_id AND gs.deleted_at IS NULL
JOIN brands       b  ON b.id = gs.brand_id
JOIN cities       c  ON c.id = gs.city_id
JOIN provinces    p  ON p.id = c.province_id
JOIN regions      r  ON r.id = p.region_id
JOIN fuel_types   ft ON ft.id = sp.fuel_type_id
WHERE gs.status = 'active';

CREATE OR REPLACE VIEW v_regional_price_summary AS
SELECT
  r.id            AS region_id,
  r.name          AS region_name,
  ft.id           AS fuel_type_id,
  ft.code         AS fuel_code,
  ROUND(AVG(sp.price), 4) AS avg_price,
  MIN(sp.price)   AS min_price,
  MAX(sp.price)   AS max_price,
  COUNT(*)        AS station_count,
  MAX(sp.effective_at) AS last_updated_at
FROM station_prices sp
JOIN gas_stations gs ON gs.id = sp.station_id AND gs.deleted_at IS NULL AND gs.status = 'active'
JOIN cities    c  ON c.id = gs.city_id
JOIN provinces p  ON p.id = c.province_id
JOIN regions   r  ON r.id = p.region_id
JOIN fuel_types ft ON ft.id = sp.fuel_type_id
GROUP BY r.id, r.name, ft.id, ft.code;

CREATE OR REPLACE VIEW v_vehicle_efficiency AS
SELECT
  v.id                       AS vehicle_id,
  v.plate_number,
  v.company_id,
  v.fleet_id,
  COUNT(fp.id)               AS fill_ups,
  ROUND(SUM(fp.litres), 2)   AS total_litres,
  ROUND(SUM(fp.total_cost), 2) AS total_cost,
  ROUND(AVG(fp.km_per_litre), 2) AS avg_km_per_litre,
  ROUND(AVG(fp.cost_per_km), 4)  AS avg_cost_per_km,
  MAX(fp.purchased_at)       AS last_fill_up_at
FROM vehicles v
LEFT JOIN fuel_purchases fp
  ON fp.vehicle_id = v.id AND fp.deleted_at IS NULL
WHERE v.deleted_at IS NULL
GROUP BY v.id, v.plate_number, v.company_id, v.fleet_id;
