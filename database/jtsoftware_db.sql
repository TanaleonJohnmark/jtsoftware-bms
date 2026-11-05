-- =====================================================================
--  JT SOFTWARE AND HARDWARE SOLUTION
--  JTSOFTWARE I.T. SERVICES - Integrated Business Management System
--  Database schema + sample records
--  Target: MySQL 5.7+ / MariaDB 10.4+ (XAMPP)
-- =====================================================================

DROP DATABASE IF EXISTS jtsoftware_db;
CREATE DATABASE jtsoftware_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE jtsoftware_db;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Access control
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(40)  NOT NULL UNIQUE,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  code   VARCHAR(60) NOT NULL UNIQUE,
  label  VARCHAR(120) NOT NULL,
  module VARCHAR(60)  NOT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id       INT NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  role_id       INT NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  email         VARCHAR(120) DEFAULT NULL,
  phone         VARCHAR(40)  DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_login    DATETIME DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_role (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- People
-- ---------------------------------------------------------------------
CREATE TABLE clients (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  client_code    VARCHAR(20)  NOT NULL UNIQUE,
  full_name      VARCHAR(140) NOT NULL,
  business_name  VARCHAR(140) DEFAULT NULL,
  client_type    ENUM('Residential','Commercial','Corporate') NOT NULL DEFAULT 'Residential',
  contact_number VARCHAR(40)  DEFAULT NULL,
  email          VARCHAR(120) DEFAULT NULL,
  address        VARCHAR(255) DEFAULT NULL,
  portal_username VARCHAR(60) DEFAULT NULL UNIQUE,
  portal_password VARCHAR(255) DEFAULT NULL,
  portal_enabled TINYINT(1) NOT NULL DEFAULT 0,
  notes          TEXT DEFAULT NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_clients_name (full_name),
  INDEX idx_clients_type (client_type)
) ENGINE=InnoDB;

CREATE TABLE employees (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  employee_code  VARCHAR(20)  NOT NULL UNIQUE,
  full_name      VARCHAR(140) NOT NULL,
  position       VARCHAR(80)  DEFAULT NULL,
  department     VARCHAR(80)  DEFAULT NULL,
  contact_number VARCHAR(40)  DEFAULT NULL,
  email          VARCHAR(120) DEFAULT NULL,
  address        VARCHAR(255) DEFAULT NULL,
  hire_date      DATE DEFAULT NULL,
  daily_rate     DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_technician  TINYINT(1) NOT NULL DEFAULT 0,
  user_id        INT DEFAULT NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_emp_tech (is_technician),
  CONSTRAINT fk_emp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE attendance (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  work_date   DATE NOT NULL,
  time_in     TIME DEFAULT NULL,
  time_out    TIME DEFAULT NULL,
  status      ENUM('Present','Late','Half Day','Absent','Field Work','Leave') NOT NULL DEFAULT 'Present',
  remarks     VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY uq_attendance (employee_id, work_date),
  CONSTRAINT fk_att_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Inventory
-- ---------------------------------------------------------------------
CREATE TABLE categories (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80) NOT NULL UNIQUE,
  description VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE suppliers (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(140) NOT NULL,
  contact_person VARCHAR(120) DEFAULT NULL,
  contact_number VARCHAR(40)  DEFAULT NULL,
  email          VARCHAR(120) DEFAULT NULL,
  address        VARCHAR(255) DEFAULT NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE products (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  sku            VARCHAR(40)  NOT NULL UNIQUE,
  barcode        VARCHAR(60)  DEFAULT NULL,
  name           VARCHAR(160) NOT NULL,
  category_id    INT DEFAULT NULL,
  supplier_id    INT DEFAULT NULL,
  unit           VARCHAR(20) NOT NULL DEFAULT 'pc',
  purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  selling_price  DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock_qty      INT NOT NULL DEFAULT 0,
  reorder_level  INT NOT NULL DEFAULT 5,
  description    TEXT DEFAULT NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_prod_cat (category_id),
  INDEX idx_prod_name (name),
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_prod_sup FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE inventory_transactions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  product_id     INT NOT NULL,
  trans_type     ENUM('in','out','adjust') NOT NULL,
  quantity       INT NOT NULL,
  unit_cost      DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance_after  INT NOT NULL DEFAULT 0,
  reference_type VARCHAR(40) DEFAULT NULL,
  reference_id   INT DEFAULT NULL,
  remarks        VARCHAR(255) DEFAULT NULL,
  user_id        INT DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inv_prod (product_id),
  INDEX idx_inv_date (created_at),
  CONSTRAINT fk_inv_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CCTV installation projects
-- ---------------------------------------------------------------------
CREATE TABLE cctv_projects (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  project_code      VARCHAR(20) NOT NULL UNIQUE,
  client_id         INT NOT NULL,
  title             VARCHAR(160) NOT NULL,
  location          VARCHAR(255) DEFAULT NULL,
  camera_count      INT NOT NULL DEFAULT 0,
  camera_specs      VARCHAR(255) DEFAULT NULL,
  recorder_type     ENUM('DVR','NVR','Hybrid','None') NOT NULL DEFAULT 'DVR',
  recorder_model    VARCHAR(120) DEFAULT NULL,
  storage_capacity  VARCHAR(60)  DEFAULT NULL,
  cable_details     VARCHAR(255) DEFAULT NULL,
  package_name      VARCHAR(120) DEFAULT NULL,
  installation_fee  DECIMAL(12,2) NOT NULL DEFAULT 0,
  equipment_cost    DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount          DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_cost        DECIMAL(12,2) NOT NULL DEFAULT 0,
  installation_date DATE DEFAULT NULL,
  deadline          DATE DEFAULT NULL,
  completed_at      DATE DEFAULT NULL,
  progress          INT NOT NULL DEFAULT 0,
  status            ENUM('Pending','Approved','Scheduled','Ongoing','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  customer_approved TINYINT(1) NOT NULL DEFAULT 0,
  approved_by_name  VARCHAR(120) DEFAULT NULL,
  approved_date     DATE DEFAULT NULL,
  notes             TEXT DEFAULT NULL,
  created_by        INT DEFAULT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_proj_client (client_id),
  INDEX idx_proj_status (status),
  CONSTRAINT fk_proj_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_proj_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE project_assignments (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  project_id     INT NOT NULL,
  employee_id    INT NOT NULL,
  role_in_project VARCHAR(80) DEFAULT 'Technician',
  assigned_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_assign (project_id, employee_id),
  CONSTRAINT fk_pa_proj FOREIGN KEY (project_id) REFERENCES cctv_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_emp  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE project_equipment (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  project_id  INT NOT NULL,
  product_id  INT DEFAULT NULL,
  description VARCHAR(180) NOT NULL,
  quantity    INT NOT NULL DEFAULT 1,
  unit_cost   DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total  DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock_deducted TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_pe_proj FOREIGN KEY (project_id) REFERENCES cctv_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pe_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE project_updates (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  progress   INT NOT NULL DEFAULT 0,
  status     VARCHAR(40) DEFAULT NULL,
  note       TEXT DEFAULT NULL,
  user_id    INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pu_proj (project_id),
  CONSTRAINT fk_pu_proj FOREIGN KEY (project_id) REFERENCES cctv_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE project_photos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  photo_type ENUM('Before','After','Documentation') NOT NULL DEFAULT 'Before',
  file_path  VARCHAR(255) NOT NULL,
  caption    VARCHAR(180) DEFAULT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pp_proj FOREIGN KEY (project_id) REFERENCES cctv_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Service requests
-- ---------------------------------------------------------------------
CREATE TABLE service_requests (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  request_no       VARCHAR(20) NOT NULL UNIQUE,
  client_id        INT NOT NULL,
  service_type     VARCHAR(80) NOT NULL,
  description      TEXT NOT NULL,
  location         VARCHAR(255) DEFAULT NULL,
  request_date     DATE NOT NULL,
  preferred_date   DATE DEFAULT NULL,
  priority         ENUM('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal',
  assigned_to      INT DEFAULT NULL,
  service_fee      DECIMAL(12,2) NOT NULL DEFAULT 0,
  status           ENUM('New','Assigned','In Progress','On Hold','Completed','Cancelled') NOT NULL DEFAULT 'New',
  completion_notes TEXT DEFAULT NULL,
  completed_at     DATETIME DEFAULT NULL,
  source           ENUM('Staff','Portal') NOT NULL DEFAULT 'Staff',
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sr_client (client_id),
  INDEX idx_sr_status (status),
  CONSTRAINT fk_sr_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_sr_emp FOREIGN KEY (assigned_to) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Quotations
-- ---------------------------------------------------------------------
CREATE TABLE quotations (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  quotation_no  VARCHAR(20) NOT NULL UNIQUE,
  client_id     INT NOT NULL,
  project_id    INT DEFAULT NULL,
  subject       VARCHAR(180) NOT NULL,
  quote_date    DATE NOT NULL,
  valid_until   DATE DEFAULT NULL,
  items_total   DECIMAL(12,2) NOT NULL DEFAULT 0,
  labor_charge  DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax           DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount  DECIMAL(12,2) NOT NULL DEFAULT 0,
  status        ENUM('Draft','Sent','Approved','Rejected','Expired','Converted') NOT NULL DEFAULT 'Draft',
  terms         TEXT DEFAULT NULL,
  notes         TEXT DEFAULT NULL,
  created_by    INT DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_q_client (client_id),
  INDEX idx_q_status (status),
  CONSTRAINT fk_q_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_q_proj FOREIGN KEY (project_id) REFERENCES cctv_projects(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE quotation_items (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  quotation_id INT NOT NULL,
  product_id   INT DEFAULT NULL,
  description  VARCHAR(200) NOT NULL,
  quantity     DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price   DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total   DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_qi_quote FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  CONSTRAINT fk_qi_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Sales, invoices, payments
-- ---------------------------------------------------------------------
CREATE TABLE sales (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no     VARCHAR(20) NOT NULL UNIQUE,
  client_id      INT NOT NULL,
  reference_type ENUM('Project','Service','Quotation','Walk-in') NOT NULL DEFAULT 'Walk-in',
  reference_id   INT DEFAULT NULL,
  sale_date      DATE NOT NULL,
  due_date       DATE DEFAULT NULL,
  items_total    DECIMAL(12,2) NOT NULL DEFAULT 0,
  labor_charge   DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount       DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax            DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_paid    DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance        DECIMAL(12,2) NOT NULL DEFAULT 0,
  status         ENUM('Unpaid','Partial','Paid','Cancelled') NOT NULL DEFAULT 'Unpaid',
  notes          TEXT DEFAULT NULL,
  created_by     INT DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sale_client (client_id),
  INDEX idx_sale_status (status),
  INDEX idx_sale_date (sale_date),
  CONSTRAINT fk_sale_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE sale_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  sale_id     INT NOT NULL,
  product_id  INT DEFAULT NULL,
  description VARCHAR(200) NOT NULL,
  quantity    DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price  DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total  DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_si_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_si_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payments (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  payment_no   VARCHAR(20) NOT NULL UNIQUE,
  sale_id      INT NOT NULL,
  client_id    INT NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  payment_date DATE NOT NULL,
  method       ENUM('Cash','GCash','Bank Transfer','Cheque','Card','Other') NOT NULL DEFAULT 'Cash',
  reference_no VARCHAR(60) DEFAULT NULL,
  received_by  INT DEFAULT NULL,
  notes        VARCHAR(255) DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pay_sale (sale_id),
  INDEX idx_pay_date (payment_date),
  CONSTRAINT fk_pay_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Accounting
-- ---------------------------------------------------------------------
CREATE TABLE expenses (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  expense_no     VARCHAR(20) NOT NULL UNIQUE,
  category       VARCHAR(80) NOT NULL,
  description    VARCHAR(255) NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  expense_date   DATE NOT NULL,
  project_id     INT DEFAULT NULL,
  vendor         VARCHAR(140) DEFAULT NULL,
  payment_method ENUM('Cash','GCash','Bank Transfer','Cheque','Card','Other') NOT NULL DEFAULT 'Cash',
  recorded_by    INT DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exp_date (expense_date),
  CONSTRAINT fk_exp_proj FOREIGN KEY (project_id) REFERENCES cctv_projects(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE incomes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  income_no   VARCHAR(20) NOT NULL UNIQUE,
  source      VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  income_date DATE NOT NULL,
  project_id  INT DEFAULT NULL,
  recorded_by INT DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inc_date (income_date),
  CONSTRAINT fk_inc_proj FOREIGN KEY (project_id) REFERENCES cctv_projects(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Scheduling, logs, settings
-- ---------------------------------------------------------------------
CREATE TABLE appointments (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  title              VARCHAR(160) NOT NULL,
  appointment_type   ENUM('Installation','Service','Site Survey','Meeting','Maintenance') NOT NULL DEFAULT 'Installation',
  client_id          INT DEFAULT NULL,
  project_id         INT DEFAULT NULL,
  service_request_id INT DEFAULT NULL,
  employee_id        INT DEFAULT NULL,
  start_datetime     DATETIME NOT NULL,
  end_datetime       DATETIME NOT NULL,
  location           VARCHAR(255) DEFAULT NULL,
  status             ENUM('Scheduled','Completed','Cancelled','Rescheduled') NOT NULL DEFAULT 'Scheduled',
  notes              TEXT DEFAULT NULL,
  created_by         INT DEFAULT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_app_start (start_datetime),
  INDEX idx_app_emp (employee_id),
  CONSTRAINT fk_app_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_app_proj FOREIGN KEY (project_id) REFERENCES cctv_projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_app_sr FOREIGN KEY (service_request_id) REFERENCES service_requests(id) ON DELETE SET NULL,
  CONSTRAINT fk_app_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT DEFAULT NULL,
  actor_type  ENUM('Staff','Client','System') NOT NULL DEFAULT 'Staff',
  actor_name  VARCHAR(120) DEFAULT NULL,
  module      VARCHAR(60) NOT NULL,
  action      VARCHAR(60) NOT NULL,
  reference_id INT DEFAULT NULL,
  description VARCHAR(255) DEFAULT NULL,
  ip_address  VARCHAR(45) DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_log_date (created_at),
  INDEX idx_log_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE settings (
  setting_key   VARCHAR(60) PRIMARY KEY,
  setting_value TEXT DEFAULT NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
-- =====================================================================
--  SAMPLE / STARTER RECORDS
-- =====================================================================
USE jtsoftware_db;

INSERT INTO roles (id, slug, name, description) VALUES
(1,'super_admin','Super Admin / Owner','Full access to every module and setting'),
(2,'manager','Manager','Operations, projects, sales and reports'),
(3,'staff','Staff','Front desk: clients, service requests, quotations, schedule'),
(4,'technician','Technician','Assigned projects, service jobs and schedule'),
(5,'accountant','Accountant','Billing, payments, expenses and financial reports');

INSERT INTO permissions (code, label, module) VALUES
('dashboard.view','View dashboard','Dashboard'),
('clients.view','View clients','Clients'),
('clients.manage','Add / edit / delete clients','Clients'),
('projects.view','View CCTV projects','Projects'),
('projects.manage','Add / edit / delete projects','Projects'),
('services.view','View service requests','Services'),
('services.manage','Add / edit / delete service requests','Services'),
('quotations.view','View quotations','Quotations'),
('quotations.manage','Add / edit / delete quotations','Quotations'),
('schedule.view','View schedule','Schedule'),
('schedule.manage','Add / edit / delete appointments','Schedule'),
('inventory.view','View inventory','Inventory'),
('inventory.manage','Add / edit / delete products and stock','Inventory'),
('sales.view','View sales and invoices','Sales'),
('sales.manage','Create invoices and record payments','Sales'),
('employees.view','View employees','Employees'),
('employees.manage','Add / edit / delete employees','Employees'),
('accounting.view','View income and expenses','Accounting'),
('accounting.manage','Record income and expenses','Accounting'),
('reports.view','View and export reports','Reports'),
('users.manage','Manage user accounts and roles','Users'),
('settings.manage','Change company settings','Settings'),
('logs.view','View activity logs','Logs');

-- Super Admin: everything
INSERT INTO role_permissions (role_id, permission_id) SELECT 1, id FROM permissions;
-- Manager: everything except user accounts and settings
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 2, id FROM permissions WHERE code NOT IN ('users.manage','settings.manage');
-- Staff
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 3, id FROM permissions WHERE code IN
  ('dashboard.view','clients.view','clients.manage','projects.view','services.view','services.manage',
   'quotations.view','quotations.manage','schedule.view','schedule.manage','inventory.view','sales.view');
-- Technician
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 4, id FROM permissions WHERE code IN
  ('dashboard.view','clients.view','projects.view','projects.manage','services.view','services.manage',
   'schedule.view','inventory.view');
-- Accountant
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 5, id FROM permissions WHERE code IN
  ('dashboard.view','clients.view','projects.view','quotations.view','sales.view','sales.manage',
   'accounting.view','accounting.manage','reports.view','inventory.view');

-- Default accounts (change these passwords after installation)
INSERT INTO users (id, role_id, full_name, username, email, phone, password_hash, status) VALUES
(1,1,'Johnmark C. Tanaleon','admin','admin@jtsoftware.ph','0917-000-0001','$2y$10$QD04FLm.OE1Lewtjxk1oH.S6GeG6vENCvbeAW6ne3uh44j6uA.p92','active'),
(2,2,'Ramon Dela Cruz','manager','manager@jtsoftware.ph','0917-000-0002','$2y$10$nlbqp2q0RfsY.XFCjK7rH.l2XCaW3ErKCqG1OzgV0LxhDaUPNwHrK','active'),
(3,3,'Liza Mendoza','staff','staff@jtsoftware.ph','0917-000-0003','$2y$10$kGtTAY6Xcqz3.Rm99G1PBeXg1FHjE3cRy3O5/qskIzNP/sIHOAimC','active'),
(4,4,'Jerome Abella','technician','tech@jtsoftware.ph','0917-000-0004','$2y$10$Jz6Hv5zjrS.0BQcwUgqe5.TvxM4QYwyDYdcmP4i/OK5K0JNo1Rg7e','active'),
(5,5,'Grace Villanueva','accountant','accountant@jtsoftware.ph','0917-000-0005','$2y$10$GeSPmVTms786RFLIvS2bA.gJGGWffKsobF/OYYej738eRIpmdYRZO','active');

INSERT INTO settings (setting_key, setting_value) VALUES
('company_name','JT Software and Hardware Solution'),
('company_division','JTSOFTWARE I.T. SERVICES'),
('company_address','Cebu City, Central Visayas, Philippines'),
('company_phone','0917-000-0000'),
('company_email','jtsoftware.itservices@gmail.com'),
('currency_symbol','PHP '),
('tax_rate','0'),
('quotation_terms','1. Prices are valid within the stated validity period.\n2. 50% downpayment is required before installation; the balance is due upon completion.\n3. Installation schedule is confirmed after the downpayment.\n4. Equipment carries the manufacturer warranty; workmanship is warranted for 6 months.\n5. Additional cabling beyond the agreed length is billed separately.'),
('low_stock_threshold','5');

-- Employees -----------------------------------------------------------
INSERT INTO employees (id, employee_code, full_name, position, department, contact_number, email, address, hire_date, daily_rate, is_technician, user_id, status) VALUES
(1,'EMP-0001','Jerome Abella','Senior CCTV Technician','Field Operations','0917-111-1001','jerome@jtsoftware.ph','Mandaue City','2023-02-01',900.00,1,4,'active'),
(2,'EMP-0002','Kevin Soriano','CCTV Technician','Field Operations','0917-111-1002','kevin@jtsoftware.ph','Talisay City','2023-06-15',750.00,1,NULL,'active'),
(3,'EMP-0003','Mark Lopez','Network Technician','Field Operations','0917-111-1003','mark@jtsoftware.ph','Cebu City','2024-01-08',800.00,1,NULL,'active'),
(4,'EMP-0004','Liza Mendoza','Office Staff','Administration','0917-111-1004','liza@jtsoftware.ph','Cebu City','2023-03-20',600.00,0,3,'active'),
(5,'EMP-0005','Grace Villanueva','Accountant','Finance','0917-111-1005','grace@jtsoftware.ph','Cebu City','2023-05-02',850.00,0,5,'active'),
(6,'EMP-0006','Ramon Dela Cruz','Operations Manager','Management','0917-111-1006','ramon@jtsoftware.ph','Lapu-Lapu City','2022-11-10',1200.00,0,2,'active');

-- Clients (portal password for all demo clients: client123) -----------
INSERT INTO clients (id, client_code, full_name, business_name, client_type, contact_number, email, address, portal_username, portal_password, portal_enabled, notes) VALUES
(1,'CL-0001','Maria Santos','Santos Residence','Residential','0932-555-0101','maria.santos@email.com','Lot 7 Blk 3, Guadalupe, Cebu City','maria.santos','$2y$10$lG.2pvWQGahNyG4GdUKiKe1Yi.DEaSyOq3uGHXuQpglyVy5FPXgb6',1,'Prefers weekend installation.'),
(2,'CL-0002','Alfredo Yap','Yap Hardware Trading','Commercial','0932-555-0102','alfredo@yaphardware.ph','A.S. Fortuna St., Mandaue City','alfredo.yap','$2y$10$lG.2pvWQGahNyG4GdUKiKe1Yi.DEaSyOq3uGHXuQpglyVy5FPXgb6',1,'Two branches; wants shared mobile viewing.'),
(3,'CL-0003','Jenny Lim','Lim Pharmacy','Commercial','0932-555-0103','jenny@limpharmacy.ph','Colon St., Cebu City','jenny.lim','$2y$10$lG.2pvWQGahNyG4GdUKiKe1Yi.DEaSyOq3uGHXuQpglyVy5FPXgb6',1,NULL),
(4,'CL-0004','Rico Fernandez','Fernandez Apartments','Corporate','0932-555-0104','rico@fernandezapt.ph','Banilad, Cebu City','rico.fernandez','$2y$10$lG.2pvWQGahNyG4GdUKiKe1Yi.DEaSyOq3uGHXuQpglyVy5FPXgb6',1,'12-door apartment, needs perimeter coverage.'),
(5,'CL-0005','Cherry Ann Go','Go Mini Mart','Commercial','0932-555-0105','cherry@gominimart.ph','Basak, Lapu-Lapu City',NULL,NULL,0,NULL),
(6,'CL-0006','Danilo Reyes','Reyes Residence','Residential','0932-555-0106','danilo.reyes@email.com','Talamban, Cebu City',NULL,NULL,0,NULL);

-- Inventory -----------------------------------------------------------
INSERT INTO categories (id,name,description) VALUES
(1,'CCTV Cameras','Dome, bullet and PTZ cameras'),
(2,'Recorders','DVR, NVR and hybrid recorders'),
(3,'Storage','Surveillance hard drives and memory'),
(4,'Cables and Accessories','Coaxial, UTP, connectors, power supplies'),
(5,'Networking','Routers, switches and access points'),
(6,'Computer Hardware','Desktop and laptop parts'),
(7,'Monitors','Display units for monitoring');

INSERT INTO suppliers (id,name,contact_person,contact_number,email,address) VALUES
(1,'Cebu Security Supply','Allan Uy','032-255-7788','sales@cebusecurity.ph','Juan Luna Ave., Mandaue City'),
(2,'Visayas Tech Distribution','Marlon Chua','032-266-1122','orders@visayastech.ph','Subangdaku, Mandaue City'),
(3,'Metro Cable Traders','Jay Ramos','0917-444-2211','metrocable@email.com','Colon St., Cebu City');

INSERT INTO products (id,sku,barcode,name,category_id,supplier_id,unit,purchase_price,selling_price,stock_qty,reorder_level,description) VALUES
(1,'CAM-DOME-2MP','4800000000011','2MP HD Dome Camera (Indoor)',1,1,'pc',750.00,1250.00,42,10,'1080p indoor dome camera with IR night vision'),
(2,'CAM-BUL-2MP','4800000000028','2MP HD Bullet Camera (Outdoor)',1,1,'pc',820.00,1350.00,38,10,'Weatherproof bullet camera, 30m IR range'),
(3,'CAM-BUL-5MP','4800000000035','5MP Colorvu Bullet Camera',1,1,'pc',1850.00,2800.00,16,6,'Full-color night vision bullet camera'),
(4,'CAM-PTZ-4MP','4800000000042','4MP PTZ Speed Dome Camera',1,1,'pc',8500.00,12500.00,4,2,'Pan-tilt-zoom camera with 25x optical zoom'),
(5,'DVR-8CH','4800000000059','8-Channel 1080p DVR',2,1,'unit',3200.00,4800.00,9,3,'Supports HDTVI/AHD/CVI/analog inputs'),
(6,'DVR-16CH','4800000000066','16-Channel 1080p DVR',2,1,'unit',5400.00,7900.00,5,2,'16-channel recorder with H.265+ compression'),
(7,'NVR-8CH','4800000000073','8-Channel 4K NVR with PoE',2,1,'unit',6800.00,9800.00,6,2,'PoE NVR for IP camera systems'),
(8,'HDD-1TB','4800000000080','1TB Surveillance Hard Drive',3,2,'pc',2400.00,3400.00,12,4,'Purple-series drive rated for 24/7 recording'),
(9,'HDD-2TB','4800000000097','2TB Surveillance Hard Drive',3,2,'pc',3600.00,4900.00,8,4,'2TB 24/7 surveillance drive'),
(10,'CBL-RG59-305','4800000000103','RG59 Siamese Cable 305m Box',4,3,'box',3800.00,5200.00,7,2,'Coaxial + power siamese cable'),
(11,'CBL-UTP-305','4800000000110','UTP CAT6 Cable 305m Box',4,3,'box',4200.00,5800.00,6,2,'Outdoor-rated CAT6 cable'),
(12,'PWR-12V10A','4800000000127','12V 10A CCTV Power Supply Box',4,1,'pc',650.00,1100.00,20,6,'9-channel centralized power supply'),
(13,'CON-BNC','4800000000134','BNC Connector (Twist-on)',4,3,'pc',25.00,55.00,180,50,'Twist-on BNC connector for RG59'),
(14,'MON-22','4800000000141','22-inch LED Monitor',7,2,'unit',4500.00,6200.00,5,2,'Monitoring display with HDMI and VGA'),
(15,'NET-ROUTER-AC','4800000000158','Dual-band AC1200 Router',5,2,'unit',1800.00,2650.00,10,3,'Wireless router for remote viewing setup'),
(16,'NET-SW-8P','4800000000165','8-Port Gigabit PoE Switch',5,2,'unit',3900.00,5500.00,4,2,'PoE switch for IP camera deployments'),
(17,'PC-SSD-480','4800000000172','480GB SATA SSD',6,2,'pc',1700.00,2500.00,11,4,'Upgrade drive for client workstations'),
(18,'PC-RAM-8GB','4800000000189','8GB DDR4 Desktop RAM',6,2,'pc',1250.00,1950.00,9,4,'DDR4 2666MHz memory module'),
(19,'ACC-JBOX','4800000000196','Camera Junction Box',4,1,'pc',120.00,250.00,45,15,'Weatherproof junction box'),
(20,'ACC-TOOL-CRIMP','4800000000202','Network Crimping Tool',6,3,'pc',380.00,650.00,6,2,'RJ45/BNC crimping tool');

-- CCTV projects -------------------------------------------------------
INSERT INTO cctv_projects (id,project_code,client_id,title,location,camera_count,camera_specs,recorder_type,recorder_model,storage_capacity,cable_details,package_name,installation_fee,equipment_cost,discount,total_cost,installation_date,deadline,completed_at,progress,status,customer_approved,approved_by_name,approved_date,notes,created_by) VALUES
(1,'PRJ-0001',2,'8-Camera CCTV System - Yap Hardware Main Branch','A.S. Fortuna St., Mandaue City',8,'2MP bullet and dome mix, 30m IR','DVR','8-Channel 1080p DVR','2TB','RG59 siamese, approx. 220m','Commercial 8-Cam Package',9000.00,28400.00,1400.00,36000.00,'2026-09-12','2026-09-14','2026-09-14',100,'Completed',1,'Alfredo Yap','2026-09-05','Mobile viewing configured on 3 phones.',1),
(2,'PRJ-0002',4,'12-Camera Perimeter System - Fernandez Apartments','Banilad, Cebu City',12,'5MP Colorvu bullet, full-color night vision','NVR','8-Channel 4K NVR with PoE','4TB (2 x 2TB)','CAT6 UTP, approx. 400m','Corporate 12-Cam IP Package',18000.00,61200.00,3200.00,76000.00,'2026-11-10','2026-11-18',NULL,45,'Ongoing',1,'Rico Fernandez','2026-10-28','Phase 1 (ground floor) finished; second floor cabling in progress.',1),
(3,'PRJ-0003',3,'4-Camera CCTV System - Lim Pharmacy','Colon St., Cebu City',4,'2MP dome cameras, indoor','DVR','8-Channel 1080p DVR','1TB','RG59 siamese, approx. 90m','Starter 4-Cam Package',4500.00,12300.00,800.00,16000.00,'2026-11-12','2026-11-13',NULL,0,'Scheduled',1,'Jenny Lim','2026-11-02','Install after store hours (7PM onwards).',1),
(4,'PRJ-0004',1,'6-Camera Home Security System - Santos Residence','Guadalupe, Cebu City',6,'2MP bullet outdoor + dome indoor','DVR','8-Channel 1080p DVR','1TB','RG59 siamese, approx. 150m','Residential 6-Cam Package',6500.00,17800.00,1300.00,23000.00,'2026-11-22','2026-11-24',NULL,0,'Approved',1,'Maria Santos','2026-11-04','Weekend schedule requested.',1),
(5,'PRJ-0005',5,'PTZ and Entrance Coverage - Go Mini Mart','Basak, Lapu-Lapu City',5,'1 PTZ + 4 bullet cameras','DVR','8-Channel 1080p DVR','2TB','RG59 siamese, approx. 120m','Commercial PTZ Package',8000.00,24100.00,0.00,32100.00,NULL,'2026-12-05',NULL,0,'Pending',0,NULL,NULL,'Waiting for owner approval on the PTZ upgrade.',1),
(6,'PRJ-0006',6,'4-Camera Home System - Reyes Residence','Talamban, Cebu City',4,'2MP bullet outdoor','DVR','8-Channel 1080p DVR','1TB','RG59 siamese, approx. 80m','Residential 4-Cam Package',4000.00,11200.00,0.00,15200.00,'2026-10-18','2026-10-19','2026-10-19',100,'Completed',1,'Danilo Reyes','2026-10-10',NULL,1);

INSERT INTO project_assignments (project_id, employee_id, role_in_project) VALUES
(1,1,'Lead Technician'),(1,2,'Technician'),
(2,1,'Lead Technician'),(2,3,'Network Technician'),(2,2,'Technician'),
(3,2,'Lead Technician'),
(4,1,'Lead Technician'),(4,3,'Technician'),
(6,2,'Lead Technician'),(6,3,'Technician');

INSERT INTO project_equipment (project_id, product_id, description, quantity, unit_cost, line_total, stock_deducted) VALUES
(1,2,'2MP HD Bullet Camera (Outdoor)',5,1350.00,6750.00,1),
(1,1,'2MP HD Dome Camera (Indoor)',3,1250.00,3750.00,1),
(1,5,'8-Channel 1080p DVR',1,4800.00,4800.00,1),
(1,9,'2TB Surveillance Hard Drive',1,4900.00,4900.00,1),
(1,10,'RG59 Siamese Cable 305m Box',1,5200.00,5200.00,1),
(1,12,'12V 10A CCTV Power Supply Box',1,1100.00,1100.00,1),
(1,13,'BNC Connector (Twist-on)',20,55.00,1100.00,1),
(2,3,'5MP Colorvu Bullet Camera',12,2800.00,33600.00,1),
(2,7,'8-Channel 4K NVR with PoE',1,9800.00,9800.00,1),
(2,9,'2TB Surveillance Hard Drive',2,4900.00,9800.00,1),
(2,11,'UTP CAT6 Cable 305m Box',1,5800.00,5800.00,1),
(2,16,'8-Port Gigabit PoE Switch',1,5500.00,5500.00,0),
(3,1,'2MP HD Dome Camera (Indoor)',4,1250.00,5000.00,0),
(3,5,'8-Channel 1080p DVR',1,4800.00,4800.00,0),
(3,8,'1TB Surveillance Hard Drive',1,3400.00,3400.00,0),
(4,2,'2MP HD Bullet Camera (Outdoor)',4,1350.00,5400.00,0),
(4,1,'2MP HD Dome Camera (Indoor)',2,1250.00,2500.00,0),
(4,5,'8-Channel 1080p DVR',1,4800.00,4800.00,0),
(4,8,'1TB Surveillance Hard Drive',1,3400.00,3400.00,0),
(6,2,'2MP HD Bullet Camera (Outdoor)',4,1350.00,5400.00,1),
(6,5,'8-Channel 1080p DVR',1,4800.00,4800.00,1),
(6,8,'1TB Surveillance Hard Drive',1,3400.00,3400.00,1);

INSERT INTO project_updates (project_id, progress, status, note, user_id, created_at) VALUES
(1,30,'Ongoing','Cable roughing-in finished on both floors.',1,'2026-09-12 11:20:00'),
(1,75,'Ongoing','All 8 cameras mounted and aimed.',1,'2026-09-13 16:40:00'),
(1,100,'Completed','DVR configured, mobile viewing tested, client signed off.',1,'2026-09-14 17:05:00'),
(2,20,'Ongoing','Ground floor cabling complete.',1,'2026-11-10 10:15:00'),
(2,45,'Ongoing','6 of 12 cameras installed and tested.',1,'2026-11-11 15:30:00'),
(6,100,'Completed','System turned over, client trained on playback.',1,'2026-10-19 16:00:00');

-- Service requests ----------------------------------------------------
INSERT INTO service_requests (id,request_no,client_id,service_type,description,location,request_date,preferred_date,priority,assigned_to,service_fee,status,completion_notes,completed_at,source) VALUES
(1,'SR-0001',2,'CCTV Troubleshooting','Camera 4 at the stockroom shows no signal since yesterday.','A.S. Fortuna St., Mandaue City','2026-10-21','2026-10-22','High',1,800.00,'Completed','Loose BNC connector replaced and re-crimped.','2026-10-22 14:10:00','Staff'),
(2,'SR-0002',3,'Mobile Viewing Setup','Needs remote viewing on two new phones.','Colon St., Cebu City','2026-11-01','2026-11-03','Normal',3,500.00,'Completed','App installed and QR-paired on both devices.','2026-11-03 10:45:00','Portal'),
(3,'SR-0003',1,'Camera Replacement','Front gate camera is blurry at night.','Guadalupe, Cebu City','2026-11-03','2026-11-07','Normal',2,1350.00,'In Progress',NULL,NULL,'Portal'),
(4,'SR-0004',4,'Network Troubleshooting','Intermittent NVR disconnection from the router.','Banilad, Cebu City','2026-11-04','2026-11-06','High',3,900.00,'Assigned',NULL,NULL,'Staff'),
(5,'SR-0005',6,'Computer Repair','Office desktop is very slow, requests SSD upgrade.','Talamban, Cebu City','2026-11-05',NULL,'Low',NULL,2500.00,'New',NULL,NULL,'Staff'),
(6,'SR-0006',5,'DVR/NVR Configuration','Recording schedule needs to be changed to motion-only.','Basak, Lapu-Lapu City','2026-11-05','2026-11-08','Normal',1,600.00,'Assigned',NULL,NULL,'Portal');

-- Quotations ----------------------------------------------------------
INSERT INTO quotations (id,quotation_no,client_id,project_id,subject,quote_date,valid_until,items_total,labor_charge,discount,tax,total_amount,status,terms,notes,created_by) VALUES
(1,'QT-0001',4,2,'12-Camera IP CCTV System with PoE NVR','2026-10-20','2026-11-20',61200.00,18000.00,3200.00,0.00,76000.00,'Converted','50% downpayment before installation; balance upon completion.','Includes 2 years free system checkup.',1),
(2,'QT-0002',1,4,'6-Camera Residential CCTV Package','2026-10-30','2026-11-30',17800.00,6500.00,1300.00,0.00,23000.00,'Approved','50% downpayment before installation; balance upon completion.',NULL,1),
(3,'QT-0003',5,5,'5-Camera System with PTZ Coverage','2026-11-02','2026-12-02',24100.00,8000.00,0.00,0.00,32100.00,'Sent','50% downpayment before installation; balance upon completion.','PTZ model can be downgraded to 2MP on request.',1),
(4,'QT-0004',3,3,'4-Camera Pharmacy CCTV Package','2026-10-28','2026-11-28',12300.00,4500.00,800.00,0.00,16000.00,'Converted','50% downpayment before installation; balance upon completion.',NULL,1),
(5,'QT-0005',6,NULL,'Office Workstation Upgrade (3 units)','2026-11-04','2026-12-04',13350.00,1500.00,0.00,0.00,14850.00,'Draft','Payment on delivery.','SSD and RAM upgrade for three desktops.',1);

INSERT INTO quotation_items (quotation_id, product_id, description, quantity, unit_price, line_total) VALUES
(1,3,'5MP Colorvu Bullet Camera',12,2800.00,33600.00),
(1,7,'8-Channel 4K NVR with PoE',1,9800.00,9800.00),
(1,9,'2TB Surveillance Hard Drive',2,4900.00,9800.00),
(1,11,'UTP CAT6 Cable 305m Box',1,5800.00,5800.00),
(1,16,'8-Port Gigabit PoE Switch',1,5500.00,5500.00),
(2,2,'2MP HD Bullet Camera (Outdoor)',4,1350.00,5400.00),
(2,1,'2MP HD Dome Camera (Indoor)',2,1250.00,2500.00),
(2,5,'8-Channel 1080p DVR',1,4800.00,4800.00),
(2,8,'1TB Surveillance Hard Drive',1,3400.00,3400.00),
(2,10,'RG59 Siamese Cable 305m Box',1,5200.00,5200.00),
(3,4,'4MP PTZ Speed Dome Camera',1,12500.00,12500.00),
(3,2,'2MP HD Bullet Camera (Outdoor)',4,1350.00,5400.00),
(3,5,'8-Channel 1080p DVR',1,4800.00,4800.00),
(3,9,'2TB Surveillance Hard Drive',1,4900.00,4900.00),
(4,1,'2MP HD Dome Camera (Indoor)',4,1250.00,5000.00),
(4,5,'8-Channel 1080p DVR',1,4800.00,4800.00),
(4,8,'1TB Surveillance Hard Drive',1,3400.00,3400.00),
(5,17,'480GB SATA SSD',3,2500.00,7500.00),
(5,18,'8GB DDR4 Desktop RAM',3,1950.00,5850.00);

-- Sales, invoices and payments ---------------------------------------
INSERT INTO sales (id,invoice_no,client_id,reference_type,reference_id,sale_date,due_date,items_total,labor_charge,discount,tax,total_amount,amount_paid,balance,status,notes,created_by) VALUES
(1,'INV-0001',2,'Project',1,'2026-09-14','2026-09-21',28400.00,9000.00,1400.00,0.00,36000.00,36000.00,0.00,'Paid','Full payment on turnover.',1),
(2,'INV-0002',6,'Project',6,'2026-10-19','2026-10-26',11200.00,4000.00,0.00,0.00,15200.00,15200.00,0.00,'Paid',NULL,1),
(3,'INV-0003',4,'Project',2,'2026-10-29','2026-11-30',61200.00,18000.00,3200.00,0.00,76000.00,38000.00,38000.00,'Partial','50% downpayment received.',1),
(4,'INV-0004',2,'Service',1,'2026-10-22','2026-10-22',0.00,800.00,0.00,0.00,800.00,800.00,0.00,'Paid',NULL,1),
(5,'INV-0005',3,'Service',2,'2026-11-03','2026-11-10',0.00,500.00,0.00,0.00,500.00,0.00,500.00,'Unpaid',NULL,1),
(6,'INV-0006',5,'Walk-in',NULL,'2026-11-02','2026-11-02',2650.00,0.00,0.00,0.00,2650.00,2650.00,0.00,'Paid','Counter sale: AC1200 router.',1);

INSERT INTO sale_items (sale_id, product_id, description, quantity, unit_price, line_total) VALUES
(1,NULL,'8-Camera CCTV System - equipment and materials',1,28400.00,28400.00),
(2,NULL,'4-Camera CCTV System - equipment and materials',1,11200.00,11200.00),
(3,NULL,'12-Camera IP System - equipment and materials',1,61200.00,61200.00),
(6,15,'Dual-band AC1200 Router',1,2650.00,2650.00);

INSERT INTO payments (payment_no, sale_id, client_id, amount, payment_date, method, reference_no, received_by, notes) VALUES
('PAY-0001',1,2,18000.00,'2026-09-05','Bank Transfer','BPI-882310',1,'50% downpayment'),
('PAY-0002',1,2,18000.00,'2026-09-14','Cash',NULL,1,'Balance on turnover'),
('PAY-0003',2,6,15200.00,'2026-10-19','GCash','GC-7781092',1,'Full payment'),
('PAY-0004',3,4,38000.00,'2026-10-29','Bank Transfer','BDO-553201',1,'50% downpayment'),
('PAY-0005',4,2,800.00,'2026-10-22','Cash',NULL,1,'Service fee'),
('PAY-0006',6,5,2650.00,'2026-11-02','Cash',NULL,1,'Counter sale');

-- Accounting ----------------------------------------------------------
INSERT INTO expenses (expense_no,category,description,amount,expense_date,project_id,vendor,payment_method,recorded_by) VALUES
('EXP-0001','Equipment Purchase','Restock: 10 bullet cameras and 1 DVR',11400.00,'2026-09-02',NULL,'Cebu Security Supply','Bank Transfer',1),
('EXP-0002','Transportation','Delivery and installation trips - Mandaue',1200.00,'2026-09-12',1,NULL,'Cash',1),
('EXP-0003','Labor Cost','Technician field allowance - PRJ-0001',3000.00,'2026-09-14',1,NULL,'Cash',1),
('EXP-0004','Equipment Purchase','12 pcs Colorvu cameras and PoE NVR',29000.00,'2026-10-25',2,'Cebu Security Supply','Bank Transfer',1),
('EXP-0005','Operating Expense','Shop electricity and internet - October',4800.00,'2026-10-31',NULL,NULL,'GCash',1),
('EXP-0006','Transportation','Fuel and toll - Banilad project',1500.00,'2026-11-10',2,NULL,'Cash',1),
('EXP-0007','Installation Expense','Mounting brackets, conduits and consumables',2300.00,'2026-11-10',2,'Metro Cable Traders','Cash',1),
('EXP-0008','Labor Cost','Technician field allowance - PRJ-0006',1800.00,'2026-10-19',6,NULL,'Cash',1),
('EXP-0009','Operating Expense','Office supplies and printing',950.00,'2026-11-03',NULL,NULL,'Cash',1);

INSERT INTO incomes (income_no,source,description,amount,income_date,project_id,recorded_by) VALUES
('INC-0001','Consultation','Site survey fee - Go Mini Mart',1000.00,'2026-11-01',NULL,1),
('INC-0002','Training','CCTV playback training - Yap Hardware staff',1500.00,'2026-09-20',1,1);

-- Schedule ------------------------------------------------------------
INSERT INTO appointments (title,appointment_type,client_id,project_id,service_request_id,employee_id,start_datetime,end_datetime,location,status,notes,created_by) VALUES
('Installation - Lim Pharmacy (4 cameras)','Installation',3,3,NULL,2,'2026-11-12 19:00:00','2026-11-12 23:00:00','Colon St., Cebu City','Scheduled','Install after store hours.',1),
('Installation Day 3 - Fernandez Apartments','Installation',4,2,NULL,1,'2026-11-13 08:00:00','2026-11-13 17:00:00','Banilad, Cebu City','Scheduled','Second floor cameras.',1),
('Service - Camera replacement at Santos Residence','Service',1,NULL,3,2,'2026-11-07 09:00:00','2026-11-07 11:00:00','Guadalupe, Cebu City','Scheduled',NULL,1),
('Service - NVR disconnection check','Service',4,NULL,4,3,'2026-11-06 13:00:00','2026-11-06 15:00:00','Banilad, Cebu City','Scheduled',NULL,1),
('Site survey - Go Mini Mart PTZ placement','Site Survey',5,5,NULL,1,'2026-11-09 10:00:00','2026-11-09 12:00:00','Basak, Lapu-Lapu City','Scheduled',NULL,1),
('Installation - Santos Residence (6 cameras)','Installation',1,4,NULL,1,'2026-11-22 08:00:00','2026-11-22 16:00:00','Guadalupe, Cebu City','Scheduled','Weekend schedule.',1),
('Recording schedule change - Go Mini Mart','Service',5,NULL,6,1,'2026-11-08 14:00:00','2026-11-08 15:30:00','Basak, Lapu-Lapu City','Scheduled',NULL,1);

INSERT INTO attendance (employee_id, work_date, time_in, time_out, status, remarks) VALUES
(1,'2026-11-04','08:02:00','17:10:00','Present',NULL),
(2,'2026-11-04','08:15:00','17:05:00','Late',NULL),
(3,'2026-11-04','07:55:00','17:00:00','Present',NULL),
(4,'2026-11-04','08:00:00','17:00:00','Present',NULL),
(1,'2026-11-05','08:00:00','18:20:00','Field Work','Fernandez Apartments installation'),
(2,'2026-11-05','08:05:00','17:00:00','Present',NULL),
(3,'2026-11-05','08:00:00','18:20:00','Field Work','Fernandez Apartments installation'),
(4,'2026-11-05','07:58:00','17:02:00','Present',NULL);

INSERT INTO activity_logs (user_id,actor_type,actor_name,module,action,reference_id,description,ip_address,created_at) VALUES
(1,'Staff','Johnmark C. Tanaleon','Projects','update',2,'Updated project PRJ-0002 progress to 45%','127.0.0.1','2026-11-11 15:30:00'),
(1,'Staff','Johnmark C. Tanaleon','Sales','create',3,'Created invoice INV-0003','127.0.0.1','2026-10-29 09:12:00'),
(3,'Staff','Liza Mendoza','Clients','create',6,'Added client CL-0006','127.0.0.1','2026-10-30 10:04:00'),
(NULL,'Client','Jenny Lim','Services','create',2,'Client submitted service request SR-0002','127.0.0.1','2026-11-01 08:40:00');

-- Stock movement history for the seeded products ----------------------
INSERT INTO inventory_transactions (product_id,trans_type,quantity,unit_cost,balance_after,reference_type,reference_id,remarks,user_id,created_at)
SELECT id,'in',stock_qty,purchase_price,stock_qty,'Opening',NULL,'Opening stock balance',1,'2026-09-01 08:00:00' FROM products;
