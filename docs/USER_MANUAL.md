# User manual
### JT Software and Hardware Solution — Integrated Business Management System

This manual follows the way work actually moves through the shop: an enquiry becomes a quotation,
the quotation becomes an installation, the installation becomes an invoice, and the invoice becomes
a payment. Each section says who can do it and where to click.

---

## 1. Signing in

Open `http://localhost/jtsoftware`, enter your username and password.

What you see depends on your role. A technician does not get the same sidebar as the owner —
modules you cannot reach are simply not shown, and typing their address gives an "area is
restricted" page instead.

Your name at the top right opens **My account**, where you change your own password and see a list
of what you have done in the system.

---

## 2. The dashboard

The first screen answers the questions you ask every morning:

- how many clients, projects in progress, completed installations, and how much money is owed;
- total sales, collections, expenses this month and the resulting profit;
- **Money in and out** — six months of collections against expenses;
- **Projects to watch** — open jobs sorted by deadline, with a progress bar;
- **Upcoming schedule**, **Low stock** and **Recent activity** down the right side.

Every figure is read from the database as the page loads. There is nothing fixed or decorative on it.

---

## 3. Clients

**Clients** → *Add client*. Name is the only required field; everything else can be filled in later.

- **Client type** (Residential, Commercial, Corporate) is used in reports and when filtering lists.
- The **Jobs** column shows installations / service jobs. The **Balance** column shows what they owe.
- **Open** shows the client profile: all their installations, service requests, quotations, invoices
  and payments on one page, with totals at the top.
- **Portal** gives a client their own sign-in. Set a username and a password, tick *Allow this client
  to sign in*, and give them the details. They then see only their own records.

A client with linked records cannot be deleted — set them to *Inactive* instead, so your history and
reports stay intact.

---

## 4. Quotations

**Quotations** → *New quotation*.

1. Choose the client and write a subject ("8-Camera CCTV System with DVR and installation").
2. Add lines. Picking a product from the inventory dropdown fills in the description and price for
   you; leave it on *Custom item* to type anything else.
3. Enter the labour / installation charge, any discount, and tax. The total updates as you type.
4. Terms and conditions start from your default text in Settings and can be edited per quotation.

**Status flow:** Draft → Sent → Approved (or Rejected). A quotation past its validity date is marked
Expired automatically.

**Approved quotations convert:**

- **To project** — creates a CCTV project with the equipment lines copied in, ready to schedule.
- **To invoice** — creates an invoice with the same lines and totals.

**View / print** opens the quotation on company letterhead. Use *Print or save as PDF* to send it.

---

## 5. CCTV installation projects

This is the heart of the system. **CCTV Projects** → *New project*.

Record the client, location, camera count and specs, recorder type and model, storage, cabling,
package name, the fee breakdown, the installation date and the deadline.

Open a project to work on it:

**Equipment and materials** — add each camera, recorder, drive, cable box and connector. Picking an
inventory product fills the price. The project's equipment cost and total cost recalculate on every
change. Nothing leaves inventory yet — planning a job should never move stock.

**Issue from stock** — press this when the crew actually takes the equipment. The system checks
there is enough on hand, deducts each line, writes an inventory movement with the running balance,
and marks the lines as issued. If something is short it tells you exactly what and by how much.

**Progress** — set a percentage, set the status, and write what happened on site. Each update is kept
with the date and the person who wrote it, giving you a full history. Setting the status to
*Completed* fills in the completion date and pushes progress to 100%.

**Status flow:** Pending → Approved → Scheduled → Ongoing → Completed (or Cancelled).

**Assigned crew** — add technicians with their role on that job ("Lead Technician", "Network
Technician"). They then see the job on their own profile and in reports.

**Before and after photos** — upload site photos with captions, grouped into Before, After and
Documentation. These settle most disputes about scope and workmanship later, and the client sees
them in the portal.

**Customer approval** — tick the box, record who approved and when.

The right column also shows linked quotations, invoices, scheduled visits and job expenses, with the
gross margin after expenses so you know what the job really earned.

---

## 6. Service requests

**Service Requests** → *Log request*. Choose the client, the type of work (CCTV troubleshooting,
camera replacement, mobile viewing setup, network, computer repair, and so on), describe the
problem, set the priority, assign a technician and set the fee.

The list sorts itself so that New and Urgent work sits at the top.

- **Complete** marks the job done and stamps the completion time.
- **Invoice** raises an invoice for the service fee on a completed job.
- Requests sent by clients through the portal are marked `portal` so you can tell them apart.

---

## 7. Schedule

**Schedule** shows a month calendar with every booking, colour-coded by type. Use the arrows to move
between months, or filter down to one technician.

*New booking* asks for a title, type, start and end, the client, the technician, and optionally the
project or service request it belongs to.

**Double-booking is caught before it is saved.** If the technician already has something at that
time, the system names the clash and refuses. If two jobs genuinely overlap, tick *Book anyway*.

Booking an installation against a project also sets that project's installation date and moves it to
*Scheduled*.

The list under the calendar shows what is coming next, with a **Done** button for each.

---

## 8. Inventory and equipment

**Inventory** lists every product with cost, price, stock on hand and stock value. Colour tells you
the state at a glance: green is fine, amber is at or below the reorder level, red is out of stock.

- **Add product** — SKU, barcode, category, supplier, unit, cost, price, opening stock, reorder level.
- **Stock in / out** — record deliveries, releases, or an adjustment after a physical count. Stock out
  will not let you release more than you have.
- **History** on any product, or the **Stock movements** tab, shows every movement with the balance
  after it, what it referenced, who recorded it and when.
- **Categories** and **Suppliers** tabs manage those lists; both show how many products are attached.

A product used in any project, quotation or invoice is set to inactive rather than deleted, so old
documents keep reading correctly.

---

## 9. Sales, invoices and payments

**Sales and Invoices** → *New invoice*, or come in from a project ("Create invoice") or a completed
service job ("Invoice") so the client, lines and charges are prefilled.

- For counter sales, leave *Take the item quantities out of inventory* ticked.
- For project invoices, the equipment was already issued from the project, so the invoice does not
  move stock again.

Open an invoice to **record a payment**: amount, date, method (Cash, GCash, bank transfer, cheque,
card), and a reference number. The payment cannot exceed the outstanding balance. The invoice
status moves itself to Partial or Paid, and the client's balance updates everywhere it appears.

Voiding a payment puts the balance back.

**Print invoice** and **Print receipt** both produce a letterhead document ready for *Save as PDF*.

**Payments** in the sidebar lists everything collected in a period, with the method breakdown and
the total still outstanding across the business.

---

## 10. Employees and attendance

**Employees** holds everyone on the team: position, department, contact details, hire date, daily
rate, and whether they go out on jobs. Linking an employee to a system account connects their field
work to their login.

An employee profile shows their project assignments, service jobs, upcoming bookings, the service
fees they have generated and their recent attendance.

**Attendance** records time in, time out and a status (Present, Late, Half Day, Field Work, Leave,
Absent) per person per day. Saving again for the same person and date updates that record rather
than duplicating it.

---

## 11. Income and expenses

**Income and Expenses** is the ledger behind the profit figure.

- **Record expense** — category (equipment purchase, transportation, labour, installation,
  operating, and so on), amount, date, vendor, how it was paid, and optionally the project it belongs
  to. Project expenses appear on that project so you can see the real margin per job.
- **Record income** — for money that is not an invoice payment: consultation fees, training, rebates.

The top of the page shows collections, other income, expenses and net profit for the period, and the
doughnut chart shows where the money went by category.

---

## 12. Reports

**Reports** has eleven reports, each with a date range, a summary strip, **Export CSV** and
**Print or save as PDF**:

| Report | Answers |
|---|---|
| CCTV installation report | What was installed, for whom, by which crew |
| Completed projects | What finished, and whether it beat the deadline |
| Pending and ongoing projects | What is open, what is overdue, pipeline value |
| Client report | Who buys most, who owes most |
| Technician performance | Jobs assigned, jobs completed, fees generated |
| Sales report | Billed, collected and outstanding per invoice |
| Payment report | Every payment, by method |
| Outstanding receivables | Who owes what, and how many days late |
| Inventory report | Stock position, value, what needs reordering |
| Expense report | Where the money went |
| Profit and loss | Income against expenses, month by month |

Printed reports carry the company letterhead, the period, and who generated them.

---

## 13. User accounts, roles and logs

**User Accounts** (owner only) creates and edits logins. Passwords must be at least 8 characters and
are hashed before they are stored. You cannot delete the account you are signed in with, or the
owner account.

**Roles and permissions** shows exactly what each role can open and change, grouped by module. Tick
or untick and save — the change applies the next time that user loads a page. The owner role always
keeps everything.

**Activity Logs** records every sign-in, create, edit, delete, payment and permission change, with
the person, the module, the time and the IP address. Filter by module, person or date and export.

**Settings** holds the business name, division, address, contact details, currency prefix, tax rate,
low stock level and the default quotation terms. These are what print on your documents. To change
the logo, replace `assets/img/logo.jpg` with your file, keeping the same name.

---

## 14. The client portal

Clients sign in at `http://localhost/jtsoftware/portal/login.php` with the username and password
you set on their record. They see only their own data, and they can only add service requests —
nothing else on the portal writes to your system.

They get: an overview with their balance and next visit, installation progress with the crew's own
updates and site photos, their quotations and invoices (printable), their visit schedule, and a form
to send a new service request, which lands in your Service Requests list marked `portal`.

---

## 15. A day in the system

1. Open the **dashboard**. Check overdue invoices and today's schedule.
2. A walk-in asks for a quote → **Clients** (add if new) → **Quotations** → build it → *Mark sent*.
3. They agree → open the quotation → *Mark approved* → **To project**.
4. **Schedule** → book the installation and assign the crew.
5. Installation day → open the project → *Issue from stock* → post progress updates → upload before
   and after photos → tick customer approval → set the status to Completed.
6. **Create invoice** from the project → print it → record the payment when it arrives.
7. Record the fuel and allowances under **Income and Expenses**, charged to that project.
8. End of month → **Reports** → Profit and loss, Outstanding receivables, Inventory report.
