-- =====================================================================
--  VIKAO PMS + SELF-SERVICE PORTAL — SCHEMA  (MySQL 8.0+ / MariaDB 10.6+)
--  Database: vikao_pms   (the ecommerce lives in its own DB: vikao)
-- ---------------------------------------------------------------------
--  Module prefixes
--    sys_  Administration (staff users, roles, permissions, audit, online)
--    cfg_  Configuration  (settings, bill types, rates, taxes, sequences)
--    prpt_ Property       (properties → blocks → floors → units, parties,
--                          ownership, agreements, meters, readings, docs)
--    bln_  Billing        (leases/accounts, charges, invoices, deposits)
--    fin_  Finance        (accounts, payments, allocation, credits,
--                          expenses, remittances, QuickBooks exports)
--    api_  Integrations   (providers, credentials, logs, webhooks)
--    com_  Communication  (templates, campaigns, messages, reminders)
--    sls_  Sales          (leads, activities, deals, commissions)
--    crm_  CRM            (tickets, updates, vendors, work orders, PPM)
--    sst_  Short stay     (listings, rates, bookings, housekeeping, iCal)
--    ecm_  Ecommerce link (unit ↔ listing sync, ecommerce admin log)
--    rpt_  Reports        (saved reports, AI insights)
--    prt_  Portal         (self-service accounts, notifications, push)
--  Conventions: BIGINT ids, utf8mb4, money DECIMAL(14,2), soft delete via
--  deleted_at on master data, every financial row is append-only + audited.
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
CREATE DATABASE IF NOT EXISTS vikao_pms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vikao_pms;

-- =====================================================================
-- sys_  ADMINISTRATION
-- =====================================================================
CREATE TABLE sys_roles (
  id           SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40) NOT NULL UNIQUE,
  name         VARCHAR(80) NOT NULL,
  description  VARCHAR(255) NULL,
  is_system    TINYINT(1) NOT NULL DEFAULT 0,      -- cannot be deleted
  scope_all_properties TINYINT(1) NOT NULL DEFAULT 1, -- 0 = only properties assigned to the user
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_permissions (
  id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(80) NOT NULL UNIQUE,          -- e.g. billing.invoices.edit
  module      VARCHAR(30) NOT NULL,
  name        VARCHAR(120) NOT NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  KEY idx_perm_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_role_permissions (
  role_id       SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES sys_roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES sys_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_users (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36) NOT NULL UNIQUE,
  role_id        SMALLINT UNSIGNED NOT NULL,
  first_name     VARCHAR(80) NOT NULL,
  last_name      VARCHAR(80) NULL,
  email          VARCHAR(190) NOT NULL UNIQUE,
  username       VARCHAR(60) NOT NULL UNIQUE,
  phone          VARCHAR(20) NULL,
  job_title      VARCHAR(80) NULL,
  password_hash  VARCHAR(255) NOT NULL,
  avatar_path    VARCHAR(255) NULL,
  status         ENUM('active','suspended','invited') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  theme          ENUM('system','light','dark') NOT NULL DEFAULT 'system',
  last_login_at  DATETIME NULL,
  last_seen_at   DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at     DATETIME NULL,
  CONSTRAINT fk_user_role FOREIGN KEY (role_id) REFERENCES sys_roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- property scoping for roles that don't see everything
CREATE TABLE sys_user_properties (
  user_id     BIGINT UNSIGNED NOT NULL,
  property_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- live sessions → "who is online and what they're doing"
CREATE TABLE sys_sessions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_key   CHAR(64) NOT NULL UNIQUE,
  user_id       BIGINT UNSIGNED NOT NULL,
  ip_address    VARCHAR(45) NULL,
  user_agent    VARCHAR(255) NULL,
  current_path  VARCHAR(255) NULL,
  current_title VARCHAR(160) NULL,
  started_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at      DATETIME NULL,
  KEY idx_sess_active (ended_at, last_activity),
  CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES sys_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_audit_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_type   ENUM('staff','portal','system','api') NOT NULL DEFAULT 'staff',
  actor_id     BIGINT UNSIGNED NULL,
  actor_name   VARCHAR(160) NULL,
  module       VARCHAR(30) NOT NULL,
  action       VARCHAR(60) NOT NULL,             -- create, update, delete, login, approve, allocate...
  entity_type  VARCHAR(60) NULL,
  entity_id    BIGINT UNSIGNED NULL,
  summary      VARCHAR(255) NULL,
  old_values   JSON NULL,
  new_values   JSON NULL,
  ip_address   VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_actor (actor_type, actor_id, created_at),
  KEY idx_audit_module (module, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_notifications (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  type        VARCHAR(40) NOT NULL,
  title       VARCHAR(160) NOT NULL,
  body        VARCHAR(500) NULL,
  icon        VARCHAR(40) NULL,
  link_url    VARCHAR(255) NULL,
  read_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sn_user (user_id, read_at),
  CONSTRAINT fk_sn_user FOREIGN KEY (user_id) REFERENCES sys_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_files (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid         CHAR(36) NOT NULL UNIQUE,
  disk_path    VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type    VARCHAR(100) NOT NULL,
  size_bytes   INT UNSIGNED NOT NULL,
  uploaded_by_type ENUM('staff','portal','system') NOT NULL DEFAULT 'staff',
  uploaded_by_id BIGINT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sys_rate_limits (
  bucket      VARCHAR(120) NOT NULL PRIMARY KEY,
  hits        INT UNSIGNED NOT NULL DEFAULT 0,
  window_ends DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- background jobs / scheduler runs (auto-invoicing, reminders, sync)
CREATE TABLE sys_jobs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job         VARCHAR(60) NOT NULL,
  status      ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  summary     VARCHAR(500) NULL,
  started_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  KEY idx_jobs (job, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- cfg_  CONFIGURATION
-- =====================================================================
CREATE TABLE cfg_settings (
  setting_key   VARCHAR(80) NOT NULL PRIMARY KEY,
  setting_value TEXT NULL,
  value_type    ENUM('string','int','decimal','bool','json','text') NOT NULL DEFAULT 'string',
  group_name    VARCHAR(40) NOT NULL DEFAULT 'general',
  label         VARCHAR(120) NOT NULL,
  help          VARCHAR(255) NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cfg_tax_rates (
  id        SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code      VARCHAR(20) NOT NULL UNIQUE,         -- VAT16, MRI (monthly rental income), WHT
  name      VARCHAR(80) NOT NULL,
  rate_pct  DECIMAL(6,3) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cfg_bill_types (
  id              SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(30) NOT NULL UNIQUE,
  name            VARCHAR(80) NOT NULL,
  category        ENUM('rent','service_charge','utility','deposit','penalty','short_stay','sale','other') NOT NULL,
  billing_method  ENUM('fixed','metered','percentage','one_off') NOT NULL DEFAULT 'fixed',
  frequency       ENUM('monthly','quarterly','semi_annual','annual','one_off','per_booking') NOT NULL DEFAULT 'monthly',
  default_amount  DECIMAL(14,2) NULL,
  default_unit_rate DECIMAL(14,4) NULL,          -- metered: KES per unit (m³ / kWh / kg)
  unit_label      VARCHAR(20) NULL,              -- m³, kWh, kg
  minimum_charge  DECIMAL(14,2) NULL,
  default_bill_to ENUM('tenant','owner') NOT NULL DEFAULT 'tenant',
  remit_to        ENUM('owner','agency','property_fund','provider') NOT NULL DEFAULT 'owner', -- whose money it is
  is_refundable   TINYINT(1) NOT NULL DEFAULT 0, -- deposits
  tax_rate_id     SMALLINT UNSIGNED NULL,
  allocation_priority TINYINT UNSIGNED NOT NULL DEFAULT 50, -- lower = paid first in auto-allocation
  qb_item         VARCHAR(80) NULL,              -- QuickBooks product/service
  qb_income_account VARCHAR(80) NULL,
  color_hex       CHAR(7) NOT NULL DEFAULT '#64748B',
  icon            VARCHAR(40) NOT NULL DEFAULT 'fa-file-invoice',
  is_system       TINYINT(1) NOT NULL DEFAULT 0,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_bt_tax FOREIGN KEY (tax_rate_id) REFERENCES cfg_tax_rates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metered tariff tiers (e.g. water: first 6m³ @ 120, then @ 150)
CREATE TABLE cfg_tariff_tiers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_type_id  SMALLINT UNSIGNED NOT NULL,
  property_id   BIGINT UNSIGNED NULL,            -- NULL = global
  from_units    DECIMAL(12,3) NOT NULL,
  to_units      DECIMAL(12,3) NULL,
  rate          DECIMAL(14,4) NOT NULL,
  KEY idx_tier (bill_type_id, property_id),
  CONSTRAINT fk_tier_bt FOREIGN KEY (bill_type_id) REFERENCES cfg_bill_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cfg_penalty_rules (
  id            SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(80) NOT NULL,
  property_id   BIGINT UNSIGNED NULL,
  applies_to_bill_type_id SMALLINT UNSIGNED NULL, -- NULL = any overdue invoice
  grace_days    SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  penalty_type  ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  penalty_value DECIMAL(10,2) NOT NULL,
  max_penalty   DECIMAL(14,2) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cfg_sequences (
  seq_key     VARCHAR(30) NOT NULL PRIMARY KEY,   -- invoice, receipt, lease, ticket, booking, remittance, lead, credit_note
  prefix      VARCHAR(12) NOT NULL,
  next_value  BIGINT UNSIGNED NOT NULL DEFAULT 1,
  pad_length  TINYINT UNSIGNED NOT NULL DEFAULT 5,
  reset_yearly TINYINT(1) NOT NULL DEFAULT 1,
  current_year SMALLINT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cfg_lookups (                         -- small editable lists (unit types, expense categories...)
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  list_key   VARCHAR(40) NOT NULL,
  code       VARCHAR(40) NOT NULL,
  label      VARCHAR(120) NOT NULL,
  icon       VARCHAR(40) NULL,
  meta       JSON NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_lookup (list_key, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- prpt_  PROPERTY
-- =====================================================================
CREATE TABLE prpt_properties (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL UNIQUE,
  code            VARCHAR(20) NOT NULL UNIQUE,
  name            VARCHAR(140) NOT NULL,
  property_type   ENUM('residential','commercial','mixed_use','land','short_stay') NOT NULL DEFAULT 'residential',
  management_type ENUM('full','rent_collection','service_charge_only','sales_only','short_stay') NOT NULL DEFAULT 'full',
  country_code    CHAR(2) NOT NULL DEFAULT 'KE',
  county          VARCHAR(80) NULL,
  area            VARCHAR(120) NULL,
  address         VARCHAR(255) NULL,
  latitude        DECIMAL(10,7) NULL,
  longitude       DECIMAL(10,7) NULL,
  lr_number       VARCHAR(80) NULL,              -- land reference / title
  year_built      SMALLINT UNSIGNED NULL,
  description     TEXT NULL,
  cover_path      VARCHAR(255) NULL,
  caretaker_name  VARCHAR(120) NULL,
  caretaker_phone VARCHAR(20) NULL,
  manager_user_id BIGINT UNSIGNED NULL,          -- property manager (staff)
  bank_account_note VARCHAR(255) NULL,
  invoice_day     TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- day of month auto-invoicing runs
  due_day         TINYINT UNSIGNED NOT NULL DEFAULT 5,
  status          ENUM('onboarding','active','inactive') NOT NULL DEFAULT 'active',
  ecom_location_id INT UNSIGNED NULL,            -- vikao ecommerce locations.id
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME NULL,
  CONSTRAINT fk_prop_mgr FOREIGN KEY (manager_user_id) REFERENCES sys_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prpt_blocks (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id BIGINT UNSIGNED NOT NULL,
  code        VARCHAR(20) NOT NULL,
  name        VARCHAR(80) NOT NULL,
  floors_count SMALLINT UNSIGNED NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_block (property_id, code),
  CONSTRAINT fk_block_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prpt_floors (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id BIGINT UNSIGNED NOT NULL,
  block_id    BIGINT UNSIGNED NULL,
  level       SMALLINT NOT NULL,                 -- 0 = ground, -1 = basement
  name        VARCHAR(60) NOT NULL,
  UNIQUE KEY uq_floor (property_id, block_id, level),
  CONSTRAINT fk_floor_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_floor_block FOREIGN KEY (block_id) REFERENCES prpt_blocks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prpt_units (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL UNIQUE,
  property_id     BIGINT UNSIGNED NOT NULL,
  block_id        BIGINT UNSIGNED NULL,
  floor_id        BIGINT UNSIGNED NULL,
  unit_no         VARCHAR(30) NOT NULL,
  unit_type       VARCHAR(40) NOT NULL DEFAULT 'apartment',   -- cfg_lookups(unit_type)
  usage_class     ENUM('residential','commercial') NOT NULL DEFAULT 'residential',
  purpose         ENUM('rent','sale','owner_occupied','short_stay','rent_and_sale') NOT NULL DEFAULT 'rent',
  status          ENUM('vacant','occupied','reserved','sold','owner_occupied','under_maintenance','unavailable') NOT NULL DEFAULT 'vacant',
  bedrooms        TINYINT UNSIGNED NULL,
  bathrooms       TINYINT UNSIGNED NULL,
  size_sqm        DECIMAL(10,2) NULL,
  rent_amount     DECIMAL(14,2) NULL,            -- asking / default monthly rent
  service_charge  DECIMAL(14,2) NULL,            -- default monthly service charge
  deposit_months  DECIMAL(4,1) NOT NULL DEFAULT 1,
  sale_price      DECIMAL(14,2) NULL,
  furnishing      ENUM('furnished','semi_furnished','unfurnished') NULL,
  notes           VARCHAR(500) NULL,
  ecom_listing_uuid CHAR(36) NULL,               -- published listing on vikao ecommerce
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME NULL,
  UNIQUE KEY uq_unit (property_id, unit_no),
  KEY idx_unit_status (property_id, status),
  CONSTRAINT fk_unit_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_unit_block FOREIGN KEY (block_id) REFERENCES prpt_blocks(id) ON DELETE SET NULL,
  CONSTRAINT fk_unit_floor FOREIGN KEY (floor_id) REFERENCES prpt_floors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prpt_unit_status_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  unit_id    BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status  VARCHAR(30) NOT NULL,
  reason     VARCHAR(255) NULL,
  user_id    BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_usl (unit_id, created_at),
  CONSTRAINT fk_usl_unit FOREIGN KEY (unit_id) REFERENCES prpt_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parties = every external person/company: home owners, tenants, guarantors,
-- short-stay guests, buyers. One record, many roles.
CREATE TABLE prpt_parties (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL UNIQUE,
  party_no        VARCHAR(20) NOT NULL UNIQUE,
  party_type      ENUM('individual','company') NOT NULL DEFAULT 'individual',
  first_name      VARCHAR(80) NULL,
  last_name       VARCHAR(80) NULL,
  company_name    VARCHAR(160) NULL,
  display_name    VARCHAR(160) NOT NULL,
  is_owner        TINYINT(1) NOT NULL DEFAULT 0,
  is_tenant       TINYINT(1) NOT NULL DEFAULT 0,
  is_guest        TINYINT(1) NOT NULL DEFAULT 0,
  is_buyer        TINYINT(1) NOT NULL DEFAULT 0,
  id_type         ENUM('national_id','passport','alien_id','company_reg') NULL,
  id_number       VARCHAR(40) NULL,
  kra_pin         VARCHAR(20) NULL,
  date_of_birth   DATE NULL,
  gender          ENUM('male','female','other') NULL,
  nationality     VARCHAR(60) NULL DEFAULT 'Kenyan',
  email           VARCHAR(190) NULL,
  phone           VARCHAR(20) NULL,
  alt_phone       VARCHAR(20) NULL,
  postal_address  VARCHAR(255) NULL,
  physical_address VARCHAR(255) NULL,
  occupation      VARCHAR(120) NULL,
  employer        VARCHAR(160) NULL,
  next_of_kin_name  VARCHAR(160) NULL,
  next_of_kin_phone VARCHAR(20) NULL,
  next_of_kin_relation VARCHAR(40) NULL,
  preferred_channel ENUM('email','sms','whatsapp','portal') NOT NULL DEFAULT 'email',
  notes           TEXT NULL,
  ecom_user_uuid  CHAR(36) NULL,                 -- linked vikao ecommerce account
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME NULL,
  KEY idx_party_email (email),
  KEY idx_party_phone (phone),
  KEY idx_party_roles (is_owner, is_tenant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prpt_party_bank_accounts (           -- where owners get remitted
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  party_id      BIGINT UNSIGNED NOT NULL,
  method        ENUM('bank','mpesa','paybill','cheque') NOT NULL DEFAULT 'bank',
  bank_name     VARCHAR(120) NULL,
  branch        VARCHAR(120) NULL,
  account_name  VARCHAR(160) NULL,
  account_number VARCHAR(60) NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_pba_party FOREIGN KEY (party_id) REFERENCES prpt_parties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dynamic ownership: an owner can hold a whole property, a whole block or a unit,
-- and a unit can have several co-owners (share_pct). Most specific scope wins.
CREATE TABLE prpt_ownerships (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  party_id      BIGINT UNSIGNED NOT NULL,
  scope         ENUM('property','block','unit') NOT NULL,
  property_id   BIGINT UNSIGNED NOT NULL,
  block_id      BIGINT UNSIGNED NULL,
  unit_id       BIGINT UNSIGNED NULL,
  share_pct     DECIMAL(6,3) NOT NULL DEFAULT 100,
  acquisition   ENUM('purchase','developer','inheritance','gift','other') NOT NULL DEFAULT 'purchase',
  start_date    DATE NOT NULL,
  end_date      DATE NULL,
  is_primary_contact TINYINT(1) NOT NULL DEFAULT 1,
  title_ref     VARCHAR(80) NULL,
  notes         VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_own_unit (unit_id, end_date),
  KEY idx_own_party (party_id),
  KEY idx_own_scope (property_id, scope),
  CONSTRAINT fk_own_party FOREIGN KEY (party_id) REFERENCES prpt_parties(id),
  CONSTRAINT fk_own_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_own_block FOREIGN KEY (block_id) REFERENCES prpt_blocks(id) ON DELETE CASCADE,
  CONSTRAINT fk_own_unit FOREIGN KEY (unit_id) REFERENCES prpt_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agency ↔ owner commercial terms (management fee + sales commission)
CREATE TABLE prpt_mgmt_agreements (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id     BIGINT UNSIGNED NOT NULL,
  party_id        BIGINT UNSIGNED NULL,          -- NULL = applies to every owner in the property
  fee_type        ENUM('percentage','fixed_per_occupied_unit','fixed_per_unit','flat_monthly') NOT NULL DEFAULT 'percentage',
  fee_value       DECIMAL(12,2) NOT NULL,        -- % or KES
  fee_base        ENUM('collected','invoiced') NOT NULL DEFAULT 'collected',
  fee_bill_types  VARCHAR(255) NULL,             -- CSV of bill type codes the % applies to (default RENT)
  sales_commission_type  ENUM('percentage','fixed') NULL,
  sales_commission_value DECIMAL(12,2) NULL,
  letting_fee_type  ENUM('percentage_of_rent','fixed','none') NOT NULL DEFAULT 'none', -- new-tenant placement fee
  letting_fee_value DECIMAL(12,2) NULL,
  remittance_day  TINYINT UNSIGNED NOT NULL DEFAULT 10,
  vat_on_fee      TINYINT(1) NOT NULL DEFAULT 1,
  start_date      DATE NOT NULL,
  end_date        DATE NULL,
  document_id     BIGINT UNSIGNED NULL,
  notes           VARCHAR(500) NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ma_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_ma_party FOREIGN KEY (party_id) REFERENCES prpt_parties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-property default charges (rent defaults live on the unit)
CREATE TABLE prpt_property_charges (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id   BIGINT UNSIGNED NOT NULL,
  bill_type_id  SMALLINT UNSIGNED NOT NULL,
  amount        DECIMAL(14,2) NULL,
  unit_rate     DECIMAL(14,4) NULL,
  bill_to       ENUM('tenant','owner') NULL,
  applies_to    ENUM('all','residential','commercial') NOT NULL DEFAULT 'all',
  is_mandatory  TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_pc (property_id, bill_type_id, applies_to),
  CONSTRAINT fk_pc_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_bt FOREIGN KEY (bill_type_id) REFERENCES cfg_bill_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prpt_amenities (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(100) NOT NULL,
  icon        VARCHAR(40) NOT NULL DEFAULT 'fa-star',
  description VARCHAR(255) NULL,
  access      ENUM('all','owners','tenants','booking_required','paid') NOT NULL DEFAULT 'all',
  schedule    VARCHAR(160) NULL,                 -- "Mon–Sun 6am–10pm"
  fee         DECIMAL(12,2) NULL,
  CONSTRAINT fk_am_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prpt_parking_slots (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id BIGINT UNSIGNED NOT NULL,
  code        VARCHAR(20) NOT NULL,
  slot_type   ENUM('covered','open','basement','visitor','motorbike','ev') NOT NULL DEFAULT 'open',
  unit_id     BIGINT UNSIGNED NULL,              -- allocated to a unit
  party_id    BIGINT UNSIGNED NULL,              -- or to a person
  monthly_fee DECIMAL(12,2) NULL,
  vehicle_reg VARCHAR(20) NULL,
  status      ENUM('available','allocated','reserved','inactive') NOT NULL DEFAULT 'available',
  UNIQUE KEY uq_slot (property_id, code),
  CONSTRAINT fk_ps_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_unit FOREIGN KEY (unit_id) REFERENCES prpt_units(id) ON DELETE SET NULL,
  CONSTRAINT fk_ps_party FOREIGN KEY (party_id) REFERENCES prpt_parties(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- House rules, schedules (garbage days, water rationing), notices shown in portal
CREATE TABLE prpt_notices (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id BIGINT UNSIGNED NULL,              -- NULL = all properties
  kind        ENUM('rule','schedule','notice','emergency') NOT NULL DEFAULT 'notice',
  title       VARCHAR(160) NOT NULL,
  body        TEXT NOT NULL,
  audience    ENUM('all','owners','tenants') NOT NULL DEFAULT 'all',
  starts_at   DATETIME NULL,
  ends_at     DATETIME NULL,
  is_pinned   TINYINT(1) NOT NULL DEFAULT 0,
  created_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notice_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prpt_meters (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id   BIGINT UNSIGNED NOT NULL,
  unit_id       BIGINT UNSIGNED NULL,            -- NULL = common/bulk meter
  bill_type_id  SMALLINT UNSIGNED NOT NULL,      -- water / electricity / gas
  serial_no     VARCHAR(60) NOT NULL,
  meter_type    ENUM('postpaid','prepaid','smart') NOT NULL DEFAULT 'postpaid',
  multiplier    DECIMAL(8,3) NOT NULL DEFAULT 1,
  initial_reading DECIMAL(14,3) NOT NULL DEFAULT 0,
  install_date  DATE NULL,
  status        ENUM('active','faulty','replaced','removed') NOT NULL DEFAULT 'active',
  UNIQUE KEY uq_meter (property_id, serial_no),
  KEY idx_meter_unit (unit_id),
  CONSTRAINT fk_meter_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_meter_unit FOREIGN KEY (unit_id) REFERENCES prpt_units(id) ON DELETE SET NULL,
  CONSTRAINT fk_meter_bt FOREIGN KEY (bill_type_id) REFERENCES cfg_bill_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prpt_reading_assignments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(140) NOT NULL,
  property_id   BIGINT UNSIGNED NOT NULL,
  bill_type_id  SMALLINT UNSIGNED NULL,
  assigned_to   BIGINT UNSIGNED NOT NULL,
  period_month  CHAR(7) NOT NULL,                -- 2026-11
  due_date      DATE NOT NULL,
  status        ENUM('assigned','in_progress','completed','cancelled') NOT NULL DEFAULT 'assigned',
  require_photo TINYINT(1) NOT NULL DEFAULT 1,
  notes         VARCHAR(255) NULL,
  created_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at  DATETIME NULL,
  KEY idx_ra_user (assigned_to, status),
  CONSTRAINT fk_ra_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_ra_user FOREIGN KEY (assigned_to) REFERENCES sys_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prpt_meter_readings (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meter_id        BIGINT UNSIGNED NOT NULL,
  unit_id         BIGINT UNSIGNED NULL,
  assignment_id   BIGINT UNSIGNED NULL,
  reading_date    DATE NOT NULL,
  previous_reading DECIMAL(14,3) NOT NULL,
  current_reading DECIMAL(14,3) NOT NULL,
  consumption     DECIMAL(14,3) NOT NULL,
  photo_path      VARCHAR(255) NULL,
  latitude        DECIMAL(10,7) NULL,
  longitude       DECIMAL(10,7) NULL,
  source          ENUM('field','bulk','import','portal','smart') NOT NULL DEFAULT 'bulk',
  status          ENUM('pending','approved','rejected','billed') NOT NULL DEFAULT 'approved',
  anomaly_flag    VARCHAR(60) NULL,              -- e.g. "3.4× avg consumption"
  invoice_line_id BIGINT UNSIGNED NULL,
  read_by         BIGINT UNSIGNED NULL,
  notes           VARCHAR(255) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mr_meter (meter_id, reading_date),
  KEY idx_mr_status (status),
  CONSTRAINT fk_mr_meter FOREIGN KEY (meter_id) REFERENCES prpt_meters(id) ON DELETE CASCADE,
  CONSTRAINT fk_mr_assign FOREIGN KEY (assignment_id) REFERENCES prpt_reading_assignments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prpt_reading_assignment_items (
  assignment_id BIGINT UNSIGNED NOT NULL,
  meter_id      BIGINT UNSIGNED NOT NULL,
  reading_id    BIGINT UNSIGNED NULL,
  status        ENUM('pending','done','skipped') NOT NULL DEFAULT 'pending',
  skip_reason   VARCHAR(160) NULL,
  PRIMARY KEY (assignment_id, meter_id),
  CONSTRAINT fk_rai_assign FOREIGN KEY (assignment_id) REFERENCES prpt_reading_assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_rai_meter FOREIGN KEY (meter_id) REFERENCES prpt_meters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lease / sale agreement / notice templates with {{placeholders}}
CREATE TABLE prpt_doc_templates (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL UNIQUE,
  name        VARCHAR(120) NOT NULL,
  doc_type    ENUM('lease','sale_agreement','mgmt_agreement','notice','receipt','other') NOT NULL DEFAULT 'lease',
  usage_class ENUM('residential','commercial','any') NOT NULL DEFAULT 'any',
  body_html   MEDIUMTEXT NOT NULL,
  is_default  TINYINT(1) NOT NULL DEFAULT 0,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prpt_documents (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid         CHAR(36) NOT NULL UNIQUE,
  title        VARCHAR(160) NOT NULL,
  doc_type     VARCHAR(40) NOT NULL DEFAULT 'other',   -- lease, title_deed, id_copy, mgmt_agreement, statement, notice...
  owner_type   ENUM('property','unit','party','lease','ticket','sale') NOT NULL,
  owner_id     BIGINT UNSIGNED NOT NULL,
  property_id  BIGINT UNSIGNED NULL,
  party_id     BIGINT UNSIGNED NULL,           -- who can see it in the portal
  file_path    VARCHAR(255) NULL,              -- uploaded file
  body_html    MEDIUMTEXT NULL,                -- generated document
  visible_in_portal TINYINT(1) NOT NULL DEFAULT 1,
  signed_at    DATETIME NULL,
  signed_by_name VARCHAR(160) NULL,
  created_by   BIGINT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_doc_owner (owner_type, owner_id),
  KEY idx_doc_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- bln_  BILLING
-- =====================================================================
-- A billing "account" for a unit: a tenancy, an owner-occupancy or an owner
-- account on a vacant unit. Drives which bills are raised and to whom.
CREATE TABLE bln_leases (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL UNIQUE,
  lease_no        VARCHAR(24) NOT NULL UNIQUE,
  property_id     BIGINT UNSIGNED NOT NULL,
  unit_id         BIGINT UNSIGNED NOT NULL,      -- primary unit (extra units in bln_lease_units)
  occupancy_type  ENUM('tenant_managed','tenant_owner_managed','owner_occupied','owner_vacant','anonymous','short_stay','company_let') NOT NULL DEFAULT 'tenant_managed',
  -- tenant_managed: we know the tenant and collect rent; tenant_owner_managed: owner collects rent, we bill service charge/utilities
  start_date      DATE NOT NULL,
  end_date        DATE NULL,
  move_in_date    DATE NULL,
  move_out_date   DATE NULL,
  rent_amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
  rent_frequency  ENUM('monthly','quarterly','semi_annual','annual') NOT NULL DEFAULT 'monthly',
  billing_day     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  due_day         TINYINT UNSIGNED NOT NULL DEFAULT 5,
  escalation_pct  DECIMAL(5,2) NULL,
  escalation_every_months SMALLINT UNSIGNED NULL,
  next_escalation_date DATE NULL,
  notice_period_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  status          ENUM('draft','active','notice','ended','terminated') NOT NULL DEFAULT 'active',
  termination_reason VARCHAR(255) NULL,
  template_id     INT UNSIGNED NULL,
  agreement_document_id BIGINT UNSIGNED NULL,
  auto_invoice    TINYINT(1) NOT NULL DEFAULT 1,
  last_invoiced_period CHAR(7) NULL,             -- 2026-11
  notes           TEXT NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lease_unit (unit_id, status),
  KEY idx_lease_prop (property_id, status),
  CONSTRAINT fk_lease_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id),
  CONSTRAINT fk_lease_unit FOREIGN KEY (unit_id) REFERENCES prpt_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bln_lease_units (
  lease_id BIGINT UNSIGNED NOT NULL,
  unit_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (lease_id, unit_id),
  CONSTRAINT fk_lu_lease FOREIGN KEY (lease_id) REFERENCES bln_leases(id) ON DELETE CASCADE,
  CONSTRAINT fk_lu_unit FOREIGN KEY (unit_id) REFERENCES prpt_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bln_lease_parties (
  id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lease_id  BIGINT UNSIGNED NOT NULL,
  party_id  BIGINT UNSIGNED NOT NULL,
  role      ENUM('tenant','co_tenant','owner','guarantor','occupant','payer') NOT NULL DEFAULT 'tenant',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_lp (lease_id, party_id, role),
  KEY idx_lp_party (party_id),
  CONSTRAINT fk_lp_lease FOREIGN KEY (lease_id) REFERENCES bln_leases(id) ON DELETE CASCADE,
  CONSTRAINT fk_lp_party FOREIGN KEY (party_id) REFERENCES prpt_parties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The bills we follow up on for this account, with amounts & who pays.
CREATE TABLE bln_lease_charges (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lease_id        BIGINT UNSIGNED NOT NULL,
  unit_id         BIGINT UNSIGNED NULL,
  bill_type_id    SMALLINT UNSIGNED NOT NULL,
  billing_method  ENUM('fixed','metered','percentage','one_off') NOT NULL DEFAULT 'fixed',
  amount          DECIMAL(14,2) NULL,
  unit_rate       DECIMAL(14,4) NULL,
  frequency       ENUM('monthly','quarterly','semi_annual','annual','one_off') NOT NULL DEFAULT 'monthly',
  bill_to_party_id BIGINT UNSIGNED NOT NULL,     -- tenant by default, owner for service charge, etc.
  split_pct       DECIMAL(6,3) NOT NULL DEFAULT 100, -- co-owners / shared payers can split one charge
  start_date      DATE NOT NULL,
  end_date        DATE NULL,
  last_billed_period CHAR(7) NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_lc_lease (lease_id, is_active),
  CONSTRAINT fk_lc_lease FOREIGN KEY (lease_id) REFERENCES bln_leases(id) ON DELETE CASCADE,
  CONSTRAINT fk_lc_bt FOREIGN KEY (bill_type_id) REFERENCES cfg_bill_types(id),
  CONSTRAINT fk_lc_party FOREIGN KEY (bill_to_party_id) REFERENCES prpt_parties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bln_billing_runs (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_no        VARCHAR(24) NOT NULL UNIQUE,
  period_month  CHAR(7) NOT NULL,
  property_id   BIGINT UNSIGNED NULL,
  bill_types    VARCHAR(255) NULL,
  trigger_type  ENUM('scheduled','manual') NOT NULL DEFAULT 'manual',
  status        ENUM('preview','completed','reversed') NOT NULL DEFAULT 'completed',
  invoices_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_amount  DECIMAL(16,2) NOT NULL DEFAULT 0,
  created_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bln_invoices (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL UNIQUE,
  invoice_no      VARCHAR(24) NOT NULL UNIQUE,
  lease_id        BIGINT UNSIGNED NULL,
  property_id     BIGINT UNSIGNED NOT NULL,
  unit_id         BIGINT UNSIGNED NULL,
  party_id        BIGINT UNSIGNED NOT NULL,      -- bill-to
  booking_id      BIGINT UNSIGNED NULL,          -- short stay
  deal_id         BIGINT UNSIGNED NULL,          -- sales instalment
  period_month    CHAR(7) NULL,
  period_start    DATE NULL,
  period_end      DATE NULL,
  issue_date      DATE NOT NULL,
  due_date        DATE NOT NULL,
  status          ENUM('draft','issued','partially_paid','paid','overdue','void','written_off') NOT NULL DEFAULT 'issued',
  source          ENUM('auto','manual','meter','penalty','booking','sale','deposit','opening_balance') NOT NULL DEFAULT 'manual',
  subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_total       DECIMAL(14,2) NOT NULL DEFAULT 0,
  total           DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_paid     DECIMAL(14,2) NOT NULL DEFAULT 0,
  balance         DECIMAL(14,2) NOT NULL DEFAULT 0,
  currency_code   CHAR(3) NOT NULL DEFAULT 'KES',
  run_id          BIGINT UNSIGNED NULL,
  notes           VARCHAR(500) NULL,
  sent_at         DATETIME NULL,
  void_reason     VARCHAR(255) NULL,
  qb_exported_at  DATETIME NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_inv_party (party_id, status),
  KEY idx_inv_unit (unit_id, issue_date),
  KEY idx_inv_prop (property_id, issue_date),
  KEY idx_inv_status (status, due_date),
  KEY idx_inv_lease (lease_id, period_month),
  CONSTRAINT fk_inv_party FOREIGN KEY (party_id) REFERENCES prpt_parties(id),
  CONSTRAINT fk_inv_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id),
  CONSTRAINT fk_inv_unit FOREIGN KEY (unit_id) REFERENCES prpt_units(id),
  CONSTRAINT fk_inv_lease FOREIGN KEY (lease_id) REFERENCES bln_leases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bln_invoice_lines (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id     BIGINT UNSIGNED NOT NULL,
  bill_type_id   SMALLINT UNSIGNED NOT NULL,
  lease_charge_id BIGINT UNSIGNED NULL,
  description    VARCHAR(255) NOT NULL,
  quantity       DECIMAL(14,3) NOT NULL DEFAULT 1,
  unit_price     DECIMAL(14,4) NOT NULL,
  amount         DECIMAL(14,2) NOT NULL,
  tax_amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_paid    DECIMAL(14,2) NOT NULL DEFAULT 0,  -- per-line allocation (bill-type level arrears)
  meter_reading_id BIGINT UNSIGNED NULL,
  sort_order     SMALLINT NOT NULL DEFAULT 0,
  KEY idx_il_inv (invoice_id),
  KEY idx_il_bt (bill_type_id),
  CONSTRAINT fk_il_inv FOREIGN KEY (invoice_id) REFERENCES bln_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_il_bt FOREIGN KEY (bill_type_id) REFERENCES cfg_bill_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Full timeline of every invoice: created, edited (diff), sent, paid, voided...
CREATE TABLE bln_invoice_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id  BIGINT UNSIGNED NOT NULL,
  event       ENUM('created','edited','line_added','line_removed','sent','viewed','payment','payment_reversed','credit_applied','penalty','voided','written_off','reminder','note') NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount      DECIMAL(14,2) NULL,
  balance_after DECIMAL(14,2) NULL,
  changes     JSON NULL,
  actor_type  ENUM('staff','portal','system','api') NOT NULL DEFAULT 'staff',
  actor_id    BIGINT UNSIGNED NULL,
  actor_name  VARCHAR(160) NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ie_inv (invoice_id, created_at),
  CONSTRAINT fk_ie_inv FOREIGN KEY (invoice_id) REFERENCES bln_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bln_deposits (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lease_id     BIGINT UNSIGNED NOT NULL,
  party_id     BIGINT UNSIGNED NOT NULL,
  bill_type_id SMALLINT UNSIGNED NOT NULL,       -- rent deposit, water deposit, electricity deposit
  amount_required DECIMAL(14,2) NOT NULL,
  amount_held  DECIMAL(14,2) NOT NULL DEFAULT 0,
  held_by      ENUM('agency','owner') NOT NULL DEFAULT 'agency',
  invoice_id   BIGINT UNSIGNED NULL,
  status       ENUM('pending','held','partially_refunded','refunded','forfeited','applied') NOT NULL DEFAULT 'pending',
  deductions   DECIMAL(14,2) NOT NULL DEFAULT 0,
  refunded_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  refund_date  DATE NULL,
  notes        VARCHAR(500) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dep_lease (lease_id),
  CONSTRAINT fk_dep_lease FOREIGN KEY (lease_id) REFERENCES bln_leases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bln_credit_notes (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  credit_no    VARCHAR(24) NOT NULL UNIQUE,
  invoice_id   BIGINT UNSIGNED NOT NULL,
  amount       DECIMAL(14,2) NOT NULL,
  reason       VARCHAR(255) NOT NULL,
  created_by   BIGINT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cn_inv FOREIGN KEY (invoice_id) REFERENCES bln_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- fin_  FINANCE / PAYMENTS
-- =====================================================================
CREATE TABLE fin_accounts (                        -- collection / disbursement accounts
  id            SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(30) NOT NULL UNIQUE,
  name          VARCHAR(120) NOT NULL,
  account_type  ENUM('mpesa_paybill','mpesa_till','bank','stripe','cash','cheque','other') NOT NULL,
  provider      VARCHAR(40) NULL,                -- safaricom, coop, stripe
  number        VARCHAR(60) NULL,                -- paybill / account no
  holder        ENUM('agency','owner','property') NOT NULL DEFAULT 'agency',
  owner_party_id BIGINT UNSIGNED NULL,           -- owner-held account
  is_master     TINYINT(1) NOT NULL DEFAULT 0,   -- Vikao master accounts
  integration_code VARCHAR(40) NULL,             -- api_integrations.code for live feed
  qb_account    VARCHAR(80) NULL,
  opening_balance DECIMAL(16,2) NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which accounts each property collects into (per payment method)
CREATE TABLE fin_property_accounts (
  property_id BIGINT UNSIGNED NOT NULL,
  account_id  SMALLINT UNSIGNED NOT NULL,
  account_reference_format VARCHAR(60) NULL,     -- e.g. "{unit_no}" or "{property_code}{unit_no}" for paybill account no.
  is_default  TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, account_id),
  CONSTRAINT fk_fpa_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE,
  CONSTRAINT fk_fpa_acc FOREIGN KEY (account_id) REFERENCES fin_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Raw feed from M-Pesa / Co-op / Stripe (before becoming payments)
CREATE TABLE fin_gateway_transactions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider      ENUM('mpesa','coop','stripe') NOT NULL,
  account_id    SMALLINT UNSIGNED NULL,
  transaction_id VARCHAR(80) NOT NULL,
  transaction_type VARCHAR(40) NULL,             -- PayBill, CustomerPayBillOnline, charge...
  amount        DECIMAL(14,2) NOT NULL,
  currency_code CHAR(3) NOT NULL DEFAULT 'KES',
  payer_name    VARCHAR(160) NULL,
  payer_phone   VARCHAR(20) NULL,
  bill_ref      VARCHAR(80) NULL,                -- account number the payer typed
  transacted_at DATETIME NOT NULL,
  raw_payload   JSON NULL,
  match_status  ENUM('unmatched','matched','ignored','duplicate') NOT NULL DEFAULT 'unmatched',
  payment_id    BIGINT UNSIGNED NULL,
  is_simulated  TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gw (provider, transaction_id),
  KEY idx_gw_status (match_status, transacted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fin_payments (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL UNIQUE,
  receipt_no      VARCHAR(24) NOT NULL UNIQUE,
  account_id      SMALLINT UNSIGNED NOT NULL,
  method          ENUM('mpesa','bank_transfer','card','cash','cheque','rtgs','eft','offset','other') NOT NULL,
  source          ENUM('gateway','manual','portal','import') NOT NULL DEFAULT 'manual',
  reference       VARCHAR(80) NULL,              -- M-Pesa code / cheque no / bank ref
  party_id        BIGINT UNSIGNED NULL,          -- payer (resolved)
  property_id     BIGINT UNSIGNED NULL,
  unit_id         BIGINT UNSIGNED NULL,
  lease_id        BIGINT UNSIGNED NULL,
  payer_name      VARCHAR(160) NULL,
  payer_phone     VARCHAR(20) NULL,
  amount          DECIMAL(14,2) NOT NULL,
  allocated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,  -- moved to fin_credits (overpayment)
  unallocated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  status          ENUM('unallocated','partially_allocated','allocated','reversed') NOT NULL DEFAULT 'unallocated',
  paid_at         DATETIME NOT NULL,
  gateway_txn_id  BIGINT UNSIGNED NULL,
  notes           VARCHAR(500) NULL,
  reversed_reason VARCHAR(255) NULL,
  qb_exported_at  DATETIME NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_ref (account_id, reference),
  KEY idx_pay_party (party_id, paid_at),
  KEY idx_pay_status (status, paid_at),
  KEY idx_pay_prop (property_id, paid_at),
  CONSTRAINT fk_pay_acc FOREIGN KEY (account_id) REFERENCES fin_accounts(id),
  CONSTRAINT fk_pay_party FOREIGN KEY (party_id) REFERENCES prpt_parties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How a payment was consumed: line-level so arrears by bill type are exact
CREATE TABLE fin_allocations (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id      BIGINT UNSIGNED NULL,          -- NULL when consumed from a credit
  credit_id       BIGINT UNSIGNED NULL,
  invoice_id      BIGINT UNSIGNED NOT NULL,
  invoice_line_id BIGINT UNSIGNED NOT NULL,
  bill_type_id    SMALLINT UNSIGNED NOT NULL,
  amount          DECIMAL(14,2) NOT NULL,
  allocated_by    BIGINT UNSIGNED NULL,
  mode            ENUM('manual','auto','credit') NOT NULL DEFAULT 'manual',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversed_at     DATETIME NULL,
  KEY idx_alloc_pay (payment_id),
  KEY idx_alloc_inv (invoice_id),
  KEY idx_alloc_line (invoice_line_id),
  CONSTRAINT fk_alloc_pay FOREIGN KEY (payment_id) REFERENCES fin_payments(id),
  CONSTRAINT fk_alloc_inv FOREIGN KEY (invoice_id) REFERENCES bln_invoices(id),
  CONSTRAINT fk_alloc_line FOREIGN KEY (invoice_line_id) REFERENCES bln_invoice_lines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Overpayments / prepayments held for a party, optionally earmarked for a unit + bill type.
-- The billing engine consumes open credits automatically when the next invoice is raised.
CREATE TABLE fin_credits (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id    BIGINT UNSIGNED NULL,
  party_id      BIGINT UNSIGNED NOT NULL,
  lease_id      BIGINT UNSIGNED NULL,
  unit_id       BIGINT UNSIGNED NULL,
  bill_type_id  SMALLINT UNSIGNED NULL,          -- NULL = any bill
  amount        DECIMAL(14,2) NOT NULL,
  remaining     DECIMAL(14,2) NOT NULL,
  status        ENUM('open','consumed','refunded','cancelled') NOT NULL DEFAULT 'open',
  notes         VARCHAR(255) NULL,
  created_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_credit_party (party_id, status),
  CONSTRAINT fk_credit_party FOREIGN KEY (party_id) REFERENCES prpt_parties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fin_expense_categories (
  id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  code        VARCHAR(30) NOT NULL UNIQUE,
  qb_expense_account VARCHAR(80) NULL,
  default_chargeable_to ENUM('agency','owner','property_fund','tenant') NOT NULL DEFAULT 'owner',
  is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fin_vendors (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(160) NOT NULL,
  service     VARCHAR(120) NULL,
  phone       VARCHAR(20) NULL,
  email       VARCHAR(190) NULL,
  kra_pin     VARCHAR(20) NULL,
  payment_details VARCHAR(255) NULL,
  rating      DECIMAL(3,2) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fin_expenses (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expense_no    VARCHAR(24) NOT NULL UNIQUE,
  category_id   SMALLINT UNSIGNED NOT NULL,
  vendor_id     BIGINT UNSIGNED NULL,
  expense_date  DATE NOT NULL,
  description   VARCHAR(255) NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  tax_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  property_id   BIGINT UNSIGNED NULL,
  unit_id       BIGINT UNSIGNED NULL,
  party_id      BIGINT UNSIGNED NULL,
  ticket_id     BIGINT UNSIGNED NULL,
  booking_id    BIGINT UNSIGNED NULL,
  chargeable_to ENUM('agency','owner','property_fund','tenant') NOT NULL DEFAULT 'owner',
  paid_from_account_id SMALLINT UNSIGNED NULL,
  payment_ref   VARCHAR(80) NULL,
  status        ENUM('draft','approved','paid','rejected') NOT NULL DEFAULT 'approved',
  receipt_path  VARCHAR(255) NULL,
  remittance_id BIGINT UNSIGNED NULL,           -- deducted in this owner remittance
  qb_exported_at DATETIME NULL,
  created_by    BIGINT UNSIGNED NULL,
  approved_by   BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_exp_prop (property_id, expense_date),
  CONSTRAINT fk_exp_cat FOREIGN KEY (category_id) REFERENCES fin_expense_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Owner remittances: collected − management fee − owner expenses = payable
CREATE TABLE fin_remittances (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  remittance_no   VARCHAR(24) NOT NULL UNIQUE,
  party_id        BIGINT UNSIGNED NOT NULL,
  property_id     BIGINT UNSIGNED NOT NULL,
  period_start    DATE NOT NULL,
  period_end      DATE NOT NULL,
  gross_collected DECIMAL(14,2) NOT NULL DEFAULT 0,
  mgmt_fee        DECIMAL(14,2) NOT NULL DEFAULT 0,
  mgmt_fee_vat    DECIMAL(14,2) NOT NULL DEFAULT 0,
  expenses        DECIMAL(14,2) NOT NULL DEFAULT 0,
  withholding_tax DECIMAL(14,2) NOT NULL DEFAULT 0,
  adjustments     DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_payable     DECIMAL(14,2) NOT NULL DEFAULT 0,
  status          ENUM('draft','approved','paid','cancelled') NOT NULL DEFAULT 'draft',
  paid_at         DATETIME NULL,
  payment_ref     VARCHAR(80) NULL,
  paid_from_account_id SMALLINT UNSIGNED NULL,
  breakdown       JSON NULL,
  created_by      BIGINT UNSIGNED NULL,
  approved_by     BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rem_party (party_id, period_end),
  CONSTRAINT fk_rem_party FOREIGN KEY (party_id) REFERENCES prpt_parties(id),
  CONSTRAINT fk_rem_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fin_qb_exports (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  export_type ENUM('invoices','payments','expenses','remittances','customers','journal') NOT NULL,
  date_from   DATE NOT NULL,
  date_to     DATE NOT NULL,
  rows_count  INT UNSIGNED NOT NULL DEFAULT 0,
  file_path   VARCHAR(255) NULL,
  created_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- api_  INTEGRATIONS ("silent" backend module)
-- =====================================================================
CREATE TABLE api_integrations (
  id           SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40) NOT NULL UNIQUE,      -- mpesa_vikao, coop_vikao, stripe_vikao, mpesa_ecom, stripe_ecom, smtp, sms_at, anthropic
  name         VARCHAR(100) NOT NULL,
  provider     VARCHAR(40) NOT NULL,             -- mpesa, coop, stripe, smtp, africastalking, anthropic
  scope        ENUM('pms','ecommerce','shared') NOT NULL DEFAULT 'pms',
  environment  ENUM('sandbox','live') NOT NULL DEFAULT 'sandbox',
  credentials  JSON NULL,                        -- secrets encrypted by app (enc: prefix)
  settings     JSON NULL,
  simulate     TINYINT(1) NOT NULL DEFAULT 1,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  last_success_at DATETIME NULL,
  last_error   VARCHAR(500) NULL,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_logs (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  integration_code VARCHAR(40) NOT NULL,
  direction     ENUM('outbound','inbound') NOT NULL,
  endpoint      VARCHAR(255) NOT NULL,
  http_status   SMALLINT NULL,
  request_body  MEDIUMTEXT NULL,
  response_body MEDIUMTEXT NULL,
  duration_ms   INT UNSIGNED NULL,
  success       TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_api_log (integration_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- com_  COMMUNICATION
-- =====================================================================
CREATE TABLE com_templates (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(50) NOT NULL UNIQUE,
  name        VARCHAR(120) NOT NULL,
  category    ENUM('billing','reminder','crm','lease','portal','announcement','sales','short_stay','general') NOT NULL DEFAULT 'general',
  channel     ENUM('email','sms','both','portal') NOT NULL DEFAULT 'email',
  subject     VARCHAR(200) NULL,
  body_email  MEDIUMTEXT NULL,
  body_sms    VARCHAR(640) NULL,
  is_system   TINYINT(1) NOT NULL DEFAULT 0,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE com_campaigns (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(160) NOT NULL,
  channel      ENUM('email','sms','both','portal') NOT NULL,
  template_id  INT UNSIGNED NULL,
  subject      VARCHAR(200) NULL,
  body         MEDIUMTEXT NULL,
  audience     JSON NOT NULL,                    -- {"type":"tenants","property_ids":[1],"with_arrears":true}
  status       ENUM('draft','scheduled','sending','sent','cancelled') NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL,
  recipients_count INT UNSIGNED NOT NULL DEFAULT 0,
  sent_count   INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_by   BIGINT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at      DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE com_messages (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id  BIGINT UNSIGNED NULL,
  channel      ENUM('email','sms','portal','whatsapp') NOT NULL,
  party_id     BIGINT UNSIGNED NULL,
  user_id      BIGINT UNSIGNED NULL,             -- staff recipient
  to_address   VARCHAR(190) NOT NULL,
  subject      VARCHAR(200) NULL,
  body         MEDIUMTEXT NOT NULL,
  status       ENUM('queued','sent','delivered','failed','simulated') NOT NULL DEFAULT 'queued',
  provider_ref VARCHAR(80) NULL,
  error        VARCHAR(500) NULL,
  related_type VARCHAR(40) NULL,
  related_id   BIGINT UNSIGNED NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at      DATETIME NULL,
  KEY idx_msg_party (party_id, created_at),
  KEY idx_msg_status (status),
  KEY idx_msg_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE com_reminder_rules (
  id           SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(120) NOT NULL,
  trigger_type ENUM('invoice_due','invoice_overdue','lease_expiry','deposit_refund','reading_due','booking_checkin','birthday') NOT NULL,
  offset_days  SMALLINT NOT NULL DEFAULT 0,      -- negative = before
  template_id  INT UNSIGNED NOT NULL,
  channel      ENUM('email','sms','both','portal') NOT NULL DEFAULT 'both',
  min_amount   DECIMAL(14,2) NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  last_run_at  DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- sls_  SALES
-- =====================================================================
CREATE TABLE sls_stages (
  id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60) NOT NULL,
  probability TINYINT UNSIGNED NOT NULL DEFAULT 10,
  color_hex   CHAR(7) NOT NULL DEFAULT '#64748B',
  is_won      TINYINT(1) NOT NULL DEFAULT 0,
  is_lost     TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sls_leads (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_no       VARCHAR(24) NOT NULL UNIQUE,
  full_name     VARCHAR(160) NOT NULL,
  phone         VARCHAR(20) NULL,
  email         VARCHAR(190) NULL,
  source        ENUM('walk_in','referral','vikao_ecommerce','website','social','call','event','agent','other') NOT NULL DEFAULT 'other',
  interest      ENUM('buy','rent','invest','short_stay') NOT NULL DEFAULT 'buy',
  property_id   BIGINT UNSIGNED NULL,
  unit_id       BIGINT UNSIGNED NULL,
  budget_min    DECIMAL(14,2) NULL,
  budget_max    DECIMAL(14,2) NULL,
  financing     ENUM('cash','mortgage','installments','undecided') NULL,
  stage_id      SMALLINT UNSIGNED NOT NULL,
  score         TINYINT UNSIGNED NOT NULL DEFAULT 50, -- 0-100 lead temperature
  assigned_to   BIGINT UNSIGNED NULL,
  party_id      BIGINT UNSIGNED NULL,           -- once converted
  next_follow_up_at DATETIME NULL,
  lost_reason   VARCHAR(255) NULL,
  notes         TEXT NULL,
  ecom_order_number VARCHAR(30) NULL,           -- came from a vikao reservation
  created_by    BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lead_stage (stage_id),
  KEY idx_lead_owner (assigned_to, next_follow_up_at),
  CONSTRAINT fk_lead_stage FOREIGN KEY (stage_id) REFERENCES sls_stages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sls_activities (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id     BIGINT UNSIGNED NOT NULL,
  activity_type ENUM('call','sms','email','whatsapp','meeting','site_visit','offer','note','stage_change') NOT NULL,
  summary     VARCHAR(255) NOT NULL,
  details     TEXT NULL,
  outcome     ENUM('positive','neutral','negative') NULL,
  due_at      DATETIME NULL,
  done_at     DATETIME NULL,
  user_id     BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_act_lead (lead_id, created_at),
  CONSTRAINT fk_act_lead FOREIGN KEY (lead_id) REFERENCES sls_leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sls_deals (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deal_no         VARCHAR(24) NOT NULL UNIQUE,
  lead_id         BIGINT UNSIGNED NOT NULL,
  unit_id         BIGINT UNSIGNED NOT NULL,
  buyer_party_id  BIGINT UNSIGNED NULL,
  agreed_price    DECIMAL(14,2) NOT NULL,
  deposit_amount  DECIMAL(14,2) NULL,
  payment_plan    ENUM('cash','installments','mortgage') NOT NULL DEFAULT 'cash',
  installments    SMALLINT UNSIGNED NULL,
  status          ENUM('offer','reserved','agreement_signed','paying','completed','cancelled') NOT NULL DEFAULT 'offer',
  commission_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  commission_value DECIMAL(12,2) NOT NULL DEFAULT 0,
  commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  owner_party_id  BIGINT UNSIGNED NULL,          -- seller (developer / home owner)
  expected_close_date DATE NULL,
  closed_at       DATETIME NULL,
  agreement_document_id BIGINT UNSIGNED NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_deal_lead FOREIGN KEY (lead_id) REFERENCES sls_leads(id),
  CONSTRAINT fk_deal_unit FOREIGN KEY (unit_id) REFERENCES prpt_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sls_commission_splits (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deal_id    BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NULL,               -- internal sales agent
  party_id   BIGINT UNSIGNED NULL,               -- external agent / referrer
  share_pct  DECIMAL(6,3) NOT NULL,
  amount     DECIMAL(14,2) NOT NULL,
  status     ENUM('pending','approved','paid') NOT NULL DEFAULT 'pending',
  paid_at    DATETIME NULL,
  CONSTRAINT fk_cs_deal FOREIGN KEY (deal_id) REFERENCES sls_deals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- crm_  CRM / TICKETS / MAINTENANCE
-- =====================================================================
CREATE TABLE crm_categories (
  id           SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(80) NOT NULL,
  icon         VARCHAR(40) NOT NULL DEFAULT 'fa-ticket',
  kind         ENUM('maintenance','complaint','inquiry','billing','security','request','feedback') NOT NULL DEFAULT 'maintenance',
  sla_hours    SMALLINT UNSIGNED NOT NULL DEFAULT 48,
  default_assignee BIGINT UNSIGNED NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  sort_order   SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crm_tickets (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL UNIQUE,
  ticket_no     VARCHAR(24) NOT NULL UNIQUE,
  category_id   SMALLINT UNSIGNED NOT NULL,
  party_id      BIGINT UNSIGNED NULL,           -- raised by (owner/tenant)
  property_id   BIGINT UNSIGNED NULL,
  unit_id       BIGINT UNSIGNED NULL,
  subject       VARCHAR(160) NOT NULL,
  description   TEXT NOT NULL,
  priority      ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  status        ENUM('open','assigned','in_progress','awaiting_customer','on_hold','resolved','closed','cancelled') NOT NULL DEFAULT 'open',
  source        ENUM('portal','phone','email','walk_in','staff','inspection','whatsapp') NOT NULL DEFAULT 'portal',
  assigned_to   BIGINT UNSIGNED NULL,
  vendor_id     BIGINT UNSIGNED NULL,
  access_permission ENUM('anytime','with_notice','accompanied') NULL,
  preferred_time VARCHAR(80) NULL,
  sla_due_at    DATETIME NULL,
  first_response_at DATETIME NULL,
  resolved_at   DATETIME NULL,
  closed_at     DATETIME NULL,
  cost_estimate DECIMAL(14,2) NULL,
  cost_actual   DECIMAL(14,2) NULL,
  cost_bearer   ENUM('agency','owner','tenant','property_fund') NULL,
  rating        TINYINT UNSIGNED NULL,           -- 1-5 from portal
  rating_comment VARCHAR(255) NULL,
  created_by_type ENUM('staff','portal','system') NOT NULL DEFAULT 'portal',
  created_by_id BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tkt_status (status, priority),
  KEY idx_tkt_party (party_id),
  KEY idx_tkt_assignee (assigned_to, status),
  CONSTRAINT fk_tkt_cat FOREIGN KEY (category_id) REFERENCES crm_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crm_ticket_updates (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id   BIGINT UNSIGNED NOT NULL,
  author_type ENUM('staff','portal','system') NOT NULL,
  author_id   BIGINT UNSIGNED NULL,
  author_name VARCHAR(160) NULL,
  body        TEXT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,     -- hidden from portal
  status_from VARCHAR(30) NULL,
  status_to   VARCHAR(30) NULL,
  attachment_path VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tu_ticket (ticket_id, created_at),
  CONSTRAINT fk_tu_ticket FOREIGN KEY (ticket_id) REFERENCES crm_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Planned preventive maintenance (lift service, pump service, fumigation...)
CREATE TABLE crm_maintenance_schedules (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  property_id  BIGINT UNSIGNED NOT NULL,
  title        VARCHAR(160) NOT NULL,
  category_id  SMALLINT UNSIGNED NOT NULL,
  vendor_id    BIGINT UNSIGNED NULL,
  every_days   SMALLINT UNSIGNED NOT NULL,
  next_due_date DATE NOT NULL,
  estimated_cost DECIMAL(14,2) NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_ms_prop FOREIGN KEY (property_id) REFERENCES prpt_properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Move-in / move-out / routine inspections with condition checklist
CREATE TABLE crm_inspections (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  unit_id      BIGINT UNSIGNED NOT NULL,
  lease_id     BIGINT UNSIGNED NULL,
  inspection_type ENUM('move_in','move_out','routine','pre_sale') NOT NULL,
  inspected_on DATE NOT NULL,
  inspector_id BIGINT UNSIGNED NULL,
  checklist    JSON NULL,                        -- [{"item":"Kitchen sink","condition":"good","note":""}]
  overall      ENUM('excellent','good','fair','poor') NULL,
  damages_cost DECIMAL(14,2) NULL,
  notes        TEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_insp_unit FOREIGN KEY (unit_id) REFERENCES prpt_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- sst_  SHORT STAY
-- =====================================================================
CREATE TABLE sst_listings (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  unit_id        BIGINT UNSIGNED NOT NULL UNIQUE,
  title          VARCHAR(160) NOT NULL,
  max_guests     TINYINT UNSIGNED NOT NULL DEFAULT 2,
  beds           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  base_rate      DECIMAL(12,2) NOT NULL,         -- per night
  weekend_rate   DECIMAL(12,2) NULL,
  weekly_discount_pct  DECIMAL(5,2) NULL,
  monthly_discount_pct DECIMAL(5,2) NULL,
  cleaning_fee   DECIMAL(12,2) NOT NULL DEFAULT 0,
  security_deposit DECIMAL(12,2) NOT NULL DEFAULT 0,
  extra_guest_fee DECIMAL(12,2) NULL,
  extra_guest_after TINYINT UNSIGNED NULL,
  min_nights     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  max_nights     SMALLINT UNSIGNED NULL,
  check_in_time  TIME NOT NULL DEFAULT '14:00:00',
  check_out_time TIME NOT NULL DEFAULT '10:00:00',
  house_rules    TEXT NULL,
  wifi_name      VARCHAR(80) NULL,
  wifi_password  VARCHAR(80) NULL,
  access_instructions TEXT NULL,
  tourism_levy_pct DECIMAL(5,2) NOT NULL DEFAULT 2.00,   -- Kenya tourism levy (configurable)
  vat_pct        DECIMAL(5,2) NOT NULL DEFAULT 0,
  owner_share_pct DECIMAL(5,2) NOT NULL DEFAULT 80,       -- revenue split with owner
  ical_export_token CHAR(32) NULL,
  status         ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sl_unit FOREIGN KEY (unit_id) REFERENCES prpt_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sst_rate_rules (                      -- seasonal / event pricing
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id  BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(80) NOT NULL,              -- "December high season"
  start_date  DATE NOT NULL,
  end_date    DATE NOT NULL,
  nightly_rate DECIMAL(12,2) NOT NULL,
  min_nights  SMALLINT UNSIGNED NULL,
  CONSTRAINT fk_rr_listing FOREIGN KEY (listing_id) REFERENCES sst_listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sst_channels (                        -- OTA connections via iCal
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id  BIGINT UNSIGNED NOT NULL,
  channel     ENUM('airbnb','booking_com','vrbo','expedia','vikao','other') NOT NULL,
  ical_import_url VARCHAR(500) NULL,
  commission_pct DECIMAL(5,2) NULL,
  last_synced_at DATETIME NULL,
  last_sync_status VARCHAR(120) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_ch_listing FOREIGN KEY (listing_id) REFERENCES sst_listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sst_bookings (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36) NOT NULL UNIQUE,
  booking_no     VARCHAR(24) NOT NULL UNIQUE,
  listing_id     BIGINT UNSIGNED NOT NULL,
  guest_party_id BIGINT UNSIGNED NOT NULL,
  channel        ENUM('direct','airbnb','booking_com','vrbo','expedia','vikao','walk_in','other') NOT NULL DEFAULT 'direct',
  channel_ref    VARCHAR(80) NULL,
  check_in       DATE NOT NULL,
  check_out      DATE NOT NULL,
  nights         SMALLINT UNSIGNED NOT NULL,
  adults         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  children       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  accommodation_total DECIMAL(14,2) NOT NULL,
  cleaning_fee   DECIMAL(14,2) NOT NULL DEFAULT 0,
  extra_fees     DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount       DECIMAL(14,2) NOT NULL DEFAULT 0,
  taxes          DECIMAL(14,2) NOT NULL DEFAULT 0,
  channel_commission DECIMAL(14,2) NOT NULL DEFAULT 0,
  total          DECIMAL(14,2) NOT NULL,
  security_deposit DECIMAL(14,2) NOT NULL DEFAULT 0,
  status         ENUM('inquiry','tentative','confirmed','checked_in','checked_out','cancelled','no_show') NOT NULL DEFAULT 'confirmed',
  invoice_id     BIGINT UNSIGNED NULL,
  special_requests VARCHAR(500) NULL,
  arrival_time   VARCHAR(20) NULL,
  checked_in_at  DATETIME NULL,
  checked_out_at DATETIME NULL,
  cancelled_at   DATETIME NULL,
  cancel_reason  VARCHAR(255) NULL,
  review_rating  TINYINT UNSIGNED NULL,
  review_text    VARCHAR(1000) NULL,
  created_by     BIGINT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bk_dates (listing_id, check_in, check_out),
  KEY idx_bk_status (status),
  CONSTRAINT fk_bk_listing FOREIGN KEY (listing_id) REFERENCES sst_listings(id),
  CONSTRAINT fk_bk_guest FOREIGN KEY (guest_party_id) REFERENCES prpt_parties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sst_blocked_dates (                   -- owner stays, maintenance, iCal imports
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id BIGINT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date   DATE NOT NULL,
  reason     ENUM('owner_stay','maintenance','ical_import','other') NOT NULL DEFAULT 'other',
  source_uid VARCHAR(255) NULL,
  note       VARCHAR(255) NULL,
  KEY idx_bd (listing_id, start_date),
  CONSTRAINT fk_bd_listing FOREIGN KEY (listing_id) REFERENCES sst_listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sst_housekeeping (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id   BIGINT UNSIGNED NOT NULL,
  booking_id   BIGINT UNSIGNED NULL,
  task_type    ENUM('turnover','deep_clean','inspection','linen_change','restock','maintenance') NOT NULL DEFAULT 'turnover',
  scheduled_for DATETIME NOT NULL,
  assigned_to  BIGINT UNSIGNED NULL,
  status       ENUM('pending','in_progress','done','issue') NOT NULL DEFAULT 'pending',
  checklist    JSON NULL,
  notes        VARCHAR(500) NULL,
  completed_at DATETIME NULL,
  KEY idx_hk (scheduled_for, status),
  CONSTRAINT fk_hk_listing FOREIGN KEY (listing_id) REFERENCES sst_listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- ecm_  ECOMMERCE LINK (the marketplace itself lives in DB `vikao`)
-- =====================================================================
CREATE TABLE ecm_sync_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action       VARCHAR(60) NOT NULL,             -- listing.approve, listing.reject, listing.push_unit, user.suspend...
  ecom_ref     VARCHAR(64) NULL,                 -- listing uuid / user uuid / order number
  unit_id      BIGINT UNSIGNED NULL,
  details      JSON NULL,
  user_id      BIGINT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ecm_ref (ecom_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- rpt_  REPORTS
-- =====================================================================
CREATE TABLE rpt_saved_reports (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_code VARCHAR(60) NOT NULL,
  name        VARCHAR(120) NOT NULL,
  filters     JSON NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  is_shared   TINYINT(1) NOT NULL DEFAULT 0,
  schedule    ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none',
  email_to    VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rpt_ai_insights (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope       VARCHAR(60) NOT NULL,              -- portfolio, property:3, report:arrears_aging
  period_label VARCHAR(40) NULL,
  insights    JSON NOT NULL,                     -- [{"severity":"warning","title":"...","detail":"...","action":"..."}]
  engine      ENUM('rules','llm') NOT NULL DEFAULT 'rules',
  model       VARCHAR(60) NULL,
  generated_by BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ai_scope (scope, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- prt_  SELF-SERVICE PORTAL
-- =====================================================================
CREATE TABLE prt_accounts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  party_id        BIGINT UNSIGNED NOT NULL UNIQUE,
  username        VARCHAR(40) NULL UNIQUE,       -- chosen at activation
  password_hash   VARCHAR(255) NULL,
  status          ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
  activation_token_hash CHAR(64) NULL,
  activation_expires_at DATETIME NULL,
  activation_sent_at DATETIME NULL,
  activated_at    DATETIME NULL,
  reset_token_hash CHAR(64) NULL,
  reset_expires_at DATETIME NULL,
  last_login_at   DATETIME NULL,
  last_seen_at    DATETIME NULL,
  app_installed_at DATETIME NULL,
  theme           ENUM('system','light','dark') NOT NULL DEFAULT 'system',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pa_party FOREIGN KEY (party_id) REFERENCES prpt_parties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prt_notifications (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  party_id   BIGINT UNSIGNED NOT NULL,
  type       VARCHAR(40) NOT NULL,
  title      VARCHAR(160) NOT NULL,
  body       VARCHAR(1000) NULL,
  icon       VARCHAR(40) NULL,
  link_url   VARCHAR(255) NULL,
  read_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pn_party (party_id, read_at),
  CONSTRAINT fk_pn_party FOREIGN KEY (party_id) REFERENCES prpt_parties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prt_push_subscriptions (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  endpoint   VARCHAR(500) NOT NULL,
  p256dh     VARCHAR(200) NULL,
  auth_key   VARCHAR(100) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_push (endpoint(255)),
  CONSTRAINT fk_push_acc FOREIGN KEY (account_id) REFERENCES prt_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
