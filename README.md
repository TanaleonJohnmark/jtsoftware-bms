# JT Software and Hardware Solution — Integrated Business Management System

JTSOFTWARE I.T. SERVICES · CCTV installation, service jobs, inventory, billing and reporting in one system.

Built with PHP 8, MySQL/MariaDB, and plain HTML/CSS/JavaScript. No Composer, no npm, no internet
connection required at runtime — Chart.js is bundled in `assets/vendor/`.

---

## 1. Install on XAMPP

**Step 1 — copy the folder**

Put the whole `jtsoftware` folder inside your XAMPP web root:

```
C:\xampp\htdocs\jtsoftware
```

(On macOS: `/Applications/XAMPP/htdocs/jtsoftware`. On Linux: `/opt/lampp/htdocs/jtsoftware`.)

**Step 2 — start the servers**

Open the XAMPP Control Panel and start **Apache** and **MySQL**.

**Step 3 — create the database**

1. Open <http://localhost/phpmyadmin>.
2. Click the **Import** tab.
3. Choose the file `database/jtsoftware_db.sql` from this folder.
4. Press **Go**.

The script creates the database `jtsoftware_db`, all 27 tables, and a set of sample records so you can
see how everything works before entering real data. You do **not** need to create the database first —
the script does it.

**Step 4 — check the database settings**

Open `config/database.php`. The defaults match a stock XAMPP install:

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'jtsoftware_db');
define('DB_USER', 'root');
define('DB_PASS', '');     // XAMPP's root user has no password by default
define('DB_PORT', '3306');
```

If you set a MySQL root password, put it in `DB_PASS`.

**Step 5 — open the system**

<http://localhost/jtsoftware>

---

## 2. Sign-in accounts

| Role | Username | Password | What they can reach |
|---|---|---|---|
| Super Admin / Owner | `admin` | `admin123` | Everything |
| Manager | `manager` | `manager123` | All operations and reports; not user accounts or settings |
| Staff | `staff` | `staff123` | Clients, service requests, quotations, schedule |
| Technician | `technician` | `tech123` | Assigned projects, service jobs, schedule |
| Accountant | `accountant` | `accounting123` | Invoices, payments, expenses, reports |

**Change every one of these passwords before using the system for real work.**
Sign in as `admin` → *User Accounts* → *Edit* → type a new password.

**Client portal:** <http://localhost/jtsoftware/portal/login.php>
Demo client account: `maria.santos` / `client123` (every seeded client uses the same password).

---

## 3. What is in the folder

```
jtsoftware/
├── index.php              Sends you to the dashboard or the login page
├── login.php  logout.php  Staff sign-in
├── dashboard.php          Live statistics and charts
├── clients.php            Client list, search, portal accounts
├── client_view.php        One client: projects, jobs, invoices, payments
├── projects.php           CCTV installation projects
├── project_view.php       Equipment, crew, progress, photos, approval
├── services.php           IT service requests
├── quotations.php         Quotation list, status, conversion
├── quotation_form.php     Quotation builder with live totals
├── quotation_print.php    Printable quotation
├── schedule.php           Month calendar, bookings, conflict detection
├── products.php           Inventory, stock in/out, low-stock alerts
├── categories.php  suppliers.php  stock.php
├── sales.php              Invoices
├── sale_view.php          One invoice and its payments
├── invoice_print.php      Printable invoice and receipt
├── payments.php           All payments received
├── accounting.php         Income and expense ledger
├── employees.php  employee_view.php  attendance.php
├── reports.php            Eleven reports with date filters and export
├── users.php  roles.php   Accounts and role permissions
├── activity_logs.php      Who did what, and when
├── settings.php  profile.php
├── portal/                Client-facing portal (7 pages)
├── config/                database.php, config.php
├── includes/              auth.php, header.php, footer.php, denied.php
├── assets/                css/, js/, img/logo.jpg, vendor/chart.umd.js
├── database/              jtsoftware_db.sql  (schema + sample data)
├── docs/                  USER_MANUAL.md
└── uploads/projects/      Installation photos uploaded through the system
```

---

## 4. Saving documents as PDF

Quotations, invoices, receipts and reports open as a formatted document with a
**Print or save as PDF** button. In the print dialog choose *Save as PDF* as the destination.
A dedicated print stylesheet hides the sidebar and buttons, so what comes out is a clean
single-column document on company letterhead.

Report tables and most lists also have an **Export CSV** button. CSV files open directly in Excel.

---

## 5. How the numbers stay correct

- **Nothing on the dashboard is hard-coded.** Every figure is a query against the database.
- **Stock moves once, on purpose.** Project equipment leaves inventory when you press
  *Issue from stock* on the project page. Counter sales deduct when the invoice is created,
  if you leave the tick box on. Every movement is written to `inventory_transactions` with the
  running balance, so the stock history always explains the current count.
- **Payments drive invoice status.** Recording or voiding a payment recalculates `amount_paid`,
  `balance`, and the status (Unpaid → Partial → Paid) in one place.
- **Project cost follows the equipment list.** Adding or removing an equipment line recalculates
  `equipment_cost` and `total_cost` for that project.
- **Quotations convert.** An approved quotation becomes a project (with its equipment lines copied)
  or an invoice (with its item lines copied), and is then marked Converted so it is not reused twice.

---

## 6. Security

- Passwords are stored as bcrypt hashes (`password_hash`), never as plain text.
- Every database query uses PDO prepared statements, so values can never be read as SQL.
- Every form carries a CSRF token that is checked before anything is written.
- All output is escaped on the way to the browser.
- Each page and each action is behind a permission check, so changing the URL does not get a
  technician into payroll.
- Uploaded photos are checked for a real image type and size, renamed, and stored under
  `uploads/` with an `.htaccess` that stops scripts running there.
- Sessions are HTTP-only and regenerate on sign-in.

**Before putting this on a public server:** set a MySQL password, turn off PHP's `display_errors`,
serve the site over HTTPS, and take the demo accounts out.

---

## 7. Troubleshooting

**"Cannot reach the database"** — MySQL is not running in the XAMPP panel, or the database was never
imported, or `config/database.php` has the wrong user or password.

**Photos will not upload** — make sure `uploads/projects/` exists and is writable. On Linux:
`chmod -R 775 uploads`.

**A page shows "This form expired"** — the session timed out. Reload the page and submit again.

**Apache will not start** — Skype or IIS is usually holding port 80. Change Apache's port in the
XAMPP config, then use `http://localhost:8080/jtsoftware` (or whichever port you picked).

---

## 8. Starting with empty data

The sample records are there so nothing looks blank on day one. To start clean, open phpMyAdmin,
pick `jtsoftware_db`, and empty the data tables (`clients`, `cctv_projects`, `service_requests`,
`quotations`, `quotation_items`, `sales`, `sale_items`, `payments`, `expenses`, `incomes`,
`appointments`, `project_*`, `inventory_transactions`, `activity_logs`, and optionally `products`,
`categories`, `suppliers`, `employees`).

Leave `roles`, `permissions`, `role_permissions`, `settings` and at least one row in `users` alone —
those are what the system runs on.
