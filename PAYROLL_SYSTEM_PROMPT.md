# Fresh Laravel 10 Automated Payroll System Prompt

You are a senior Laravel 10 engineer. Build a fresh payroll system from a new Laravel 10 installation that automates the manual payroll process currently done in `Payroll sample.xlsx`.

## Goal

Create a web-based payroll system for Central Philippines State University that can automatically compute payroll, group payroll by campus and fund cluster, handle missing attendance logs with approved appeals, route payroll for review before finalization, and generate printable payroll reports matching the manual Excel payroll format.

The system must support multiple Campus Payroll Administrators, one per assigned campus, and University Payroll Administrators who check and approve payroll before campus payroll administrators can print final payroll.

## Direct HRIS Database Integration

The payroll system connects to the existing HRIS MySQL database through Laravel's dedicated `hris` connection. It must not call an HRIS HTTP API.

Configure the connection independently from the payroll database:

```env
HRIS_DB_CONNECTION=mysql
HRIS_DB_HOST=127.0.0.1
HRIS_DB_PORT=3306
HRIS_DB_DATABASE=dbcpsuhris
HRIS_DB_USERNAME=root
HRIS_DB_PASSWORD=
```

The integration reads only the payroll-relevant fields from:

- `employees` for active employee records and campus assignment
- `dtrs` for AM, PM, and overtime punches
- `official_times` for employee work schedules

All HRIS access must use `DB::connection('hris')`. Services must issue read-only queries, keep credentials only in server environment configuration, log connection/read attempts without credentials, and block payroll submission when the required attendance read is unavailable. Employee records are synchronized into the payroll database; employees are never created by seeders.

## Source Workbook To Model

Use the manual Excel file as the process reference:

`Payroll sample.xlsx`

The workbook has these payroll/fund-cluster tabs:

- `PT` - Part-time payroll
- `INC` - Income / internally generated funds payroll
- `MDS` - GAA / MDS payroll
- `PROJ` - Project fund payroll
- `BUSTYPE` - Business-type income payroll
- `YEARBOOK` - Yearbook/project-based payroll
- `SUPPORT SERVICES` - Support service personnel payroll

The system must let administrators configure and maintain these payroll templates and fund clusters instead of hardcoding them.

## Fund Cluster / Fund Source Handling

Create a `fund_clusters` module with fields:

- Code
- Name
- Payroll template type
- Fund source name
- Campus
- Active status
- Sorting order
- Default signatories
- Default deduction rules

Seed the following fund source examples from the workbook:

- GAA
- Common Fund
- Allocation for Administrative
- Allocation for Instruction
- CAF Lab Fund
- Graduate School-Tuition
- Tuition-Production
- Internet Fund
- Medical-Dental Fund
- Guidance Fee
- Registration Fund
- Construction of Grad School-Phase I Additional Works
- Construction of Science Complex-Phase 2
- Construction of Athlete's Housing
- CPSU Food Services
- Water System
- Income from Dormitory
- Project Based
- International Affairs and Linkages Office

Fund clusters must be selectable during payroll generation. Each generated payroll must store the fund cluster used, not just display it.

## Final System Roles

Implement Role-Based Access Control with campus-level data isolation.

The CPSU Payroll Management System must have only four user roles:

1. `Super Administrator`
2. `University Payroll Administrator`
3. `Campus Payroll Administrator`
4. `Auditor / Accounting Viewer`

Employees are payroll records only. Employees must not have system user accounts or login access.

### Super Administrator

- Has complete control over the entire system.
- Can access all campuses, employees, payroll records, reports, users, and settings.
- Can create, update, activate, and deactivate system users.
- Can assign roles, permissions, and campuses.
- Can manage campuses, departments, offices, positions, salary grades, deductions, allowances, benefits, and payroll settings.
- Can prepare, review, approve, return, reject, reopen, consolidate, lock, release, cancel, and export payroll.
- Can access complete system and payroll audit logs.
- Can override payroll restrictions with a required reason.
- Is not restricted to a campus.
- `campus_id` must be `NULL`.

All critical actions, overrides, permission changes, and payroll reopening must require remarks and must be recorded in the audit log.

### University Payroll Administrator

- Can access employees and payroll records from all CPSU campuses.
- Can create and manage university-wide payroll periods.
- Can review payroll submitted by Campus Payroll Administrators.
- Can validate salaries, attendance adjustments, deductions, allowances, benefits, taxes, gross pay, and net pay.
- Can approve, reject, or return submitted payroll with required remarks.
- Can consolidate approved payroll from all campuses.
- Can lock and release finalized payroll.
- Can generate and export university-wide payroll reports.
- Can view payroll approval histories and audit logs.
- Can reopen payroll only when explicitly granted permission.
- Is not restricted to a campus.
- `campus_id` must be `NULL`.

Restrictions:

- Cannot directly edit a submitted campus payroll.
- Payroll with errors must be returned to the Campus Payroll Administrator for correction.
- Cannot manage Super Administrator accounts.
- Cannot modify roles, permissions, or critical system settings.
- Cannot permanently delete historical, approved, locked, or released payroll.

### Campus Payroll Administrator

- Must be assigned to exactly one campus.
- Can access only employees and payroll records belonging to the assigned campus.
- Can prepare campus payroll.
- Can encode or import attendance, absences, tardiness, overtime, allowances, deductions, benefits, and adjustments.
- Can validate payroll computations before submission.
- Can save payroll as a draft.
- Can submit payroll to the University Payroll Administrator.
- Can view submission status, remarks, and approval history.
- Can correct payroll that has been returned.
- Can resubmit corrected payroll.
- Can generate authorized campus-level payroll reports.
- `campus_id` is required.

Restrictions:

- Cannot access another campus.
- Cannot change their own campus assignment.
- Cannot approve their own payroll.
- Cannot consolidate university-wide payroll.
- Cannot edit payroll while it is submitted or under review.
- Cannot edit approved, locked, released, or cancelled payroll.
- Cannot manage university-wide configurations, roles, or permissions.

### Auditor / Accounting Viewer

- Has read-only access to authorized payroll records.
- Can view approved, locked, and released payroll.
- Can view salaries, deductions, allowances, benefits, taxes, gross pay, and net pay.
- Can view payroll summaries, financial reports, approval histories, and audit logs.
- Can export authorized reports.
- May have university-wide or campus-restricted access.

Campus assignment:

- `campus_id` may be `NULL` for university-wide access.
- `campus_id` is required when access is restricted to one campus.

Restrictions:

- Cannot create or modify payroll.
- Cannot submit, approve, reject, return, reopen, lock, release, or cancel payroll.
- Cannot manage employees, users, roles, permissions, or settings.

### Campus Isolation Rules

- Every user except Super Administrator and university-wide University Payroll Administrator must be constrained by `campus_id`.
- A Campus Payroll Administrator must always have exactly one non-null `campus_id`.
- A user with `campus_id = NULL` is university-wide only when the role permits university-wide access.
- All employee, attendance, payroll, appeal, report, and audit-log queries must enforce campus scope through policies, gates, query scopes, and tests.
- Do not rely only on UI hiding for campus restrictions; enforce access in controllers, services, policies, API endpoints, exports, and queued jobs.
- All role changes, campus assignment changes, and permission overrides require remarks and audit logs.

## Core Modules

Build these modules:

1. Authentication and role management
2. Campus management
3. Employee management
4. Employment type management
5. Fund cluster management
6. Attendance/DTR import and review
7. Missing log appeal management
8. Payroll period management
9. Payroll generation
10. Payroll review workflow
11. Payroll adjustment and override workflow
12. Payroll reports and printable forms
13. Audit logs
14. System settings

## Employee Data

Employee records must include:

- Employee ID
- Full name
- Campus
- Office/college/department
- Designation/position
- Employment type: regular, job order, part-time, project-based, support service, etc.
- Salary grade or daily/monthly rate
- Monthly salary
- Rate per day
- Rate per hour
- Rate per minute
- Fund cluster or default fund source
- Tax status
- BIR sworn declaration status
- SSS, PhilHealth, Pag-IBIG deduction settings
- Other recurring deductions
- Active/inactive status

## Payroll Periods

Payroll periods must support:

- Semi-monthly payroll, such as July 1-15, 2026
- Monthly payroll
- Custom date ranges
- Campus-specific payroll periods
- Payroll type-specific periods
- Locking after submission
- Reopening only by authorized users

## Attendance And Missing Logs

The system must compute rendered days from DTR/attendance logs.

Attendance rules:

- Import or receive attendance logs from biometric/DTR source.
- Compute present days, absent days, late minutes, undertime minutes, and rendered days.
- Missing time-in or time-out should be flagged automatically.
- Employees with missing logs can submit appeal documents outside the system, but they do not log in to the payroll system.
- Campus Payroll Administrators can encode or import missing-log appeals on behalf of employees assigned to their campus.
- University Payroll Administrators and Super Administrators can encode, import, review, or correct missing-log appeal records for any campus within their allowed permissions.
- Approved appeals can mark missing logs as present or valid official time.
- Approved appeals must affect payroll computation.
- Rejected appeals must not affect payroll computation.
- All appeal changes must have reviewer, timestamp, reason, and attachment support.

Missing log statuses:

- No issue
- Missing AM in
- Missing AM out
- Missing PM in
- Missing PM out
- Missing whole day
- Under review
- Appeal approved
- Appeal rejected
- Manually adjusted

## Payroll Computation

Automate computations currently done manually in the Excel sheets.

Common payroll columns:

- No.
- Name
- Designation
- Fund source / specific fund source
- Monthly salary
- No. of rendered days
- Rate per day
- Gross income for the period
- Deduction: late
- Deduction: undertime
- Deduction: absent
- Salary differential
- Earned for the period
- Tax 10%
- Tax 5%
- Tax 3%
- Tax 2%
- Tax refund, if applicable
- SSS
- PhilHealth
- Pag-IBIG
- NSCA MPC
- Project deduction
- Graduate school deduction
- Other deductions
- Total deduction
- Net amount received
- Remarks

Formula requirements:

- `rate_per_day = monthly_salary / configured_working_days`
- `rate_per_hour = rate_per_day / configured_hours_per_day`
- `rate_per_minute = rate_per_hour / 60`
- `gross_income = rate_per_day * rendered_days`
- `late_deduction = rate_per_minute * late_minutes`
- `undertime_deduction = rate_per_minute * undertime_minutes`
- `absent_deduction = rate_per_day * absent_days`
- `earned_for_period = gross_income + salary_differential - late_deduction - undertime_deduction - absent_deduction`
- `total_deduction = tax + sss + philhealth + pagibig + nsca_mpc + project + graduate_school + other_deductions`
- `net_amount_received = earned_for_period - total_deduction`

The working-days divisor must be configurable because the workbook uses values like `22` and `23`.

Tax rates must be configurable per employee, payroll type, or fund cluster. The workbook includes 10%, 5%, 3%, and 2% tax columns. Employees with `WITH BIR SWORN DECLARATION` or `NOT REQUIRED/SENIOR CITIZEN` remarks may have special tax behavior.

## Flexible Payroll Columns

The manual payroll changes over time. Payroll administrators sometimes add another column for a new deduction, tax refund, salary differential, allowance, additional salary, project deduction, or other adjustment. The system must support this without code changes.

Create configurable payroll columns per payroll template and fund cluster.

Each configurable column must support:

- Column key
- Display label
- Column group: employee info, salary/addition, attendance deduction, statutory deduction, other deduction, net pay, remarks, hidden computation
- Type: text, money, number, percentage, date, boolean, computed formula, remarks
- Direction: addition, deduction, neutral/display-only
- Formula expression, when computed
- Manual input allowed or locked
- Default value
- Required or optional
- Show in draft, final PDF, Excel export, or internal computation only
- Sort order
- Width for PDF/Excel output
- Fund cluster applicability
- Payroll template applicability
- Effective date range
- Active/inactive status

Examples of flexible columns:

- Salary differential
- Additional salary
- Honorarium
- Allowance
- Tax refund
- Late deduction
- Undertime deduction
- Absent deduction
- SSS
- SSS arrears
- PhilHealth
- Pag-IBIG
- NSCA MPC
- Project deduction
- Graduate school deduction
- Other deduction
- Refund
- Adjustment

Formula engine requirements:

- Formula columns must reference stable column keys, not visual column letters.
- Example: `earned_for_period = gross_income + salary_differential + additional_salary - late_deduction - undertime_deduction - absent_deduction`.
- Example: `total_deduction = SUM(columns where direction = deduction)`.
- Example: `net_amount_received = earned_for_period + SUM(additions) - SUM(deductions)`.
- Validate formulas before saving a payroll template.
- Store computed values as payroll-line snapshots at generation time.
- When a template changes, existing approved/printed payroll must not change.
- Draft payroll may be recalculated only by authorized users and must keep recalculation history.

The payroll table UI, PDF export, and Excel export must render dynamic columns automatically based on the selected payroll template and fund cluster.

## Payroll Generation Workflow

Campus payroll administrator flow:

1. Select campus.
2. Select payroll period.
3. Select payroll type/template: `PT`, `INC`, `MDS`, `PROJ`, `BUSTYPE`, `YEARBOOK`, or `SUPPORT SERVICES`.
4. Select fund cluster or fund source.
5. Load eligible employees.
6. System computes rendered days from attendance and approved appeals.
7. System computes gross income, deductions, earned amount, and net amount.
8. System flags employees with missing logs, unresolved appeals, missing salary rates, missing deduction settings, or negative net pay.
9. Administrator reviews the draft.
10. Administrator can add justified manual adjustments.
11. Administrator submits payroll to the University Payroll Administrator for checking.

The draft payroll must have a review screen before final submission. The review screen must show:

- Total employees
- Total gross
- Total deductions
- Total net
- Employees with missing logs
- Employees with approved appeals
- Employees with unresolved appeals
- Employees with manual adjustments
- Employees with missing fund source
- Employees with negative or zero net pay

## University Payroll Review Workflow

Statuses:

- Draft
- For Campus Review
- Submitted to University Payroll
- Under University Payroll Review
- Returned for Correction
- Corrected and Resubmitted
- Approved for Printing
- Printed
- Cancelled

Reviewer actions:

- Open submitted payroll.
- View all computation details.
- View attendance issues and appeal decisions.
- Add comments per payroll or per employee line.
- Return to campus for correction.
- Approve for printing.

Campus administrator actions after return:

- View reviewer comments.
- Correct payroll lines, appeals, rates, or deductions.
- Add response notes.
- Resubmit.

Printing rules:

- Drafts cannot be printed as final.
- Returned payroll cannot be printed.
- Only `Approved for Printing` payroll can generate final printable payroll.
- Printed payroll must save print timestamp and printed by user.
- Reprinting must be logged and require a reason.

## Printable Payroll Output

Generate printable payroll reports that match the manual Excel layouts as closely as technically possible and suitable for official use. The PDF and Excel output must preserve the manual forms' structure, headers, column order, grouped deduction headings, subtotals, grand totals, remarks, signatory blocks, page numbering, and spacing.

Output formats:

- PDF
- Excel export

Report requirements:

- Header: `GENERAL PAYROLL`
- Institution: `CENTRAL PHILIPPINES STATE UNIVERSITY`
- Campus/office/college
- Payroll period
- Acknowledgement statement
- Employee payroll table
- Subtotals per section/fund source
- Grand total
- Prepared by
- Certified correct
- Certified funds available
- Approved by
- Page numbers
- Remarks

Template fidelity requirements:

- Build a configurable report-template engine for each payroll type: `PT`, `INC`, `MDS`, `PROJ`, `BUSTYPE`, `YEARBOOK`, and `SUPPORT SERVICES`.
- Support different header row layouts per template.
- Support grouped headers, such as a parent `DEDUCTION` heading over multiple deduction columns.
- Support dynamic columns while keeping totals and signatories aligned.
- Support landscape and portrait paper sizes where needed.
- Support repeating table headers on every page.
- Support page totals and grand totals.
- Support subtotal rows per section, fund source, office, or campus.
- Support long fund-source names without breaking the official layout.
- Support manual remarks such as `WITH BIR SWORN DECLARATION` and `NOT REQUIRED/SENIOR CITIZEN`.
- Support draft watermark for non-final exports.
- Support exact final formatting snapshots for approved payroll so reprints remain identical.

PDF implementation requirements:

- Use a dedicated export service, not controller-rendered ad hoc HTML.
- Use versioned Blade PDF templates or another maintainable PDF rendering layer.
- Keep per-template CSS isolated and tested.
- Include visual regression checks or PDF snapshot tests for each payroll template.
- Compare generated PDF totals against payroll-line database totals before allowing final export.
- If an administrator adds a new dynamic column, the PDF must include it automatically when the column is marked `show_in_pdf`.
- If a new column cannot fit on the current page width, the system must warn the user and offer layout options: reduce font size, landscape/legal paper, split deduction schedule, or Excel-only annex.

Signatory labels from workbook:

- Prepared By
- Payroll In-Charge
- Certified Correct: Services have been duly rendered as stated.
- HRMO/OIC-HRMO
- Certified: Funds available in the amount of PHP
- Accountant

Make signatories configurable by campus, fund cluster, and payroll type.

## Data Integrity And Audit

Every generated payroll must store a snapshot of:

- Employee name
- Position
- Campus
- Fund cluster
- Salary/rate used
- Attendance totals used
- Appeal decisions used
- Deductions used
- Formula version
- User who generated
- User who submitted
- Reviewer decisions

Do not calculate final reports only from live employee settings because past payroll must remain reproducible even if an employee's salary or deduction settings change later.

Audit log must capture:

- Payroll generated
- Payroll line edited
- Manual adjustment added
- Missing log appeal approved/rejected
- Payroll submitted
- Payroll returned
- Payroll approved
- Payroll printed/reprinted
- Fund cluster changed
- Deduction setting changed

## Suggested Database Tables

Create Laravel migrations for:

- users
- roles
- permissions
- campuses
- employees
- employee_salary_rates
- employee_deduction_settings
- fund_clusters
- payroll_templates
- payroll_template_columns
- payroll_periods
- attendance_logs
- attendance_summaries
- missing_log_appeals
- payroll_batches
- payroll_lines
- payroll_line_deductions
- payroll_line_adjustments
- payroll_reviews
- payroll_review_comments
- payroll_signatories
- payroll_print_logs
- audit_logs
- system_settings

Important relationships:

- The `roles` seed must contain only: `Super Administrator`, `University Payroll Administrator`, `Campus Payroll Administrator`, and `Auditor / Accounting Viewer`.
- The `users` table must include `campus_id` as nullable, with application validation enforcing role-specific campus rules.
- Employee records must be stored in `employees` only and must not be authenticatable users.
- Do not create employee login routes, employee guards, employee passwords, or employee user accounts in the payroll system.
- Campus has many users and employees.
- Employee belongs to campus and default fund cluster.
- Payroll batch belongs to campus, period, template, fund cluster, creator, and current reviewer.
- Payroll batch has many payroll lines.
- Payroll line belongs to employee and stores snapshot values.
- Payroll line has many deductions and adjustments.
- Missing log appeal belongs to employee, attendance date, payroll period, creator, and approver.
- Review comments can belong to a payroll batch or a specific payroll line.

## UI Requirements

Build the payroll project with the same CPSU Common Supply Management System visual
language. The UI must be detailed, polished, and consistent enough that it looks like
the payroll module was added to the existing CSMS app, not built as a different system.
Keep visual variation within about 10% unless a payroll-specific workflow requires a
small adjustment.

Do not use Bootstrap, AdminLTE, Material UI, DaisyUI, Flowbite, or a separate admin
template. Use Laravel Blade, Tailwind, Alpine.js, Lucide icons, DataTables, Tom Select,
Flatpickr, SweetAlert2, AOS, CountUp.js, and Chart.js in the same style described below.

### Frontend Stack

Use these frontend libraries and load them from local `public/vendor` paths where
possible so the application works offline:

- Tailwind Play build: `public/vendor/tailwind/tailwind.js`
- Alpine.js: `public/vendor/alpine/alpine.min.js`, loaded last with `defer`
- Lucide icons: `public/vendor/lucide/lucide.min.js`
- jQuery: `public/vendor/jquery/jquery.min.js`
- DataTables core/responsive/Tailwind CSS: `public/vendor/datatables/*`
- Tom Select: `public/vendor/tom-select/tom-select.complete.min.js` and `public/vendor/tom-select/tom-select.min.css`
- Flatpickr: `public/vendor/flatpickr/flatpickr.min.js` and `public/vendor/flatpickr/flatpickr.min.css`
- SweetAlert2: `public/vendor/sweetalert2/sweetalert2.all.min.js`
- AOS: `public/vendor/aos/aos.js` and `public/vendor/aos/aos.css`
- CountUp.js: `public/vendor/countup/countUp.umd.js`
- Chart.js: `public/vendor/chartjs/chart.umd.min.js`
- Font: self-hosted Inter from `public/vendor/fonts/inter/inter-400.woff2` through `inter-800.woff2`

If local files are not yet present in the new payroll project, copy these vendor assets
or install equivalent local copies. Do not rely on internet CDNs for the final system.

### CPSU Color Palette

Use this exact palette in Tailwind config and CSS variables:

```js
tailwind.config = {
  theme: {
    extend: {
      colors: {
        cpsu: {
          green: '#0B6E2E',
          'green-dark': '#074A1F',
          gold: '#FFD500',
          'gold-dark': '#E6BF00',
          black: '#1A1A1A',
          bg: '#F7F8F5',
          border: '#E3E6DE',
          danger: '#DC2626',
          success: '#16A34A',
        },
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
    },
  },
};
```

Also define matching CSS variables:

```css
:root {
  --cpsu-green: #0B6E2E;
  --cpsu-green-dark: #074A1F;
  --cpsu-gold: #FFD500;
  --cpsu-gold-dark: #E6BF00;
  --cpsu-black: #1A1A1A;
  --cpsu-white: #FFFFFF;
  --cpsu-gray-bg: #F7F8F5;
  --cpsu-border: #E3E6DE;
  --cpsu-danger: #DC2626;
  --cpsu-success: #16A34A;
}
body {
  background: var(--cpsu-gray-bg);
  color: var(--cpsu-black);
  font-family: 'Inter', system-ui, sans-serif;
}
[x-cloak] { display: none !important; }
```

Use neutral white/gray surfaces with CPSU green as the main action color and CPSU gold
as the secondary/accent color. Avoid purple/blue dominant admin templates, dark themes,
large gradients, or unrelated decoration.

### Logo and Branding

Use the same CPSU seal/logo path everywhere:

- File path: `public/images/cpsu-logo.png`
- Blade call: `asset('images/cpsu-logo.png')`
- Alt text: `CPSU Seal` or `CPSU`

Use the logo in:

- Login page brand block
- Sidebar brand header
- Printable payroll headers when a seal is needed
- Apple touch icon: `<link rel="apple-touch-icon" href="{{ asset('images/cpsu-logo.png') }}">`

Set:

- `theme-color`: `#0B6E2E`
- App name in UI: `CPSU Payroll Management System`
- Small brand line: `Central Philippines State University`

### Layout Shell

Create two Blade layouts:

- `layouts.guest` for login and unauthenticated pages
- `layouts.app` for authenticated dashboard/workflow pages

Authenticated layout appearance:

- Body background: `#F7F8F5`
- Fixed left sidebar on desktop: width `w-64`, color `bg-cpsu-green`, text white
- Sidebar mobile behavior: slides in from left, `transition-transform duration-200`, dark `bg-black/40` backdrop
- Content column: `lg:pl-64 min-h-screen flex flex-col`
- Topbar: sticky at top, `h-16 bg-white/95 backdrop-blur border-b border-cpsu-border`
- Main content padding: `p-4 lg:p-6`
- Footer: small centered gray text, top border, `px-6 py-4 text-xs text-gray-400 border-t border-cpsu-border`
- Page title in topbar: `text-base lg:text-lg font-bold text-cpsu-black truncate`
- Optional subheader: `text-xs text-gray-500 truncate`

Sidebar appearance:

- Brand block height `h-16`, border bottom `border-white/10`
- Logo: `h-10 w-10 rounded-full bg-white/95 object-contain p-0.5`
- Brand text: `CPSU` in white and module acronym in `text-cpsu-gold`
- Nav links: `flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium`
- Nav icons: Lucide, `w-[18px] h-[18px]`
- Active nav item: `bg-white/10 text-white border-l-4 border-cpsu-gold pl-2`
- Inactive nav item: `text-white/80 hover:bg-white/10 hover:text-white border-l-4 border-transparent pl-2`
- Bottom user identity: gold avatar circle with initials, user name, role text

Topbar user menu:

- Avatar: `h-8 w-8 rounded-full bg-cpsu-green text-white`
- User menu button uses hover background `hover:bg-cpsu-bg` and `active:scale-95`
- Dropdown panel: `absolute right-0 mt-2 w-60 bg-white rounded-xl border border-cpsu-border shadow-lg overflow-hidden z-50`
- Dropdown animation: Alpine transition `opacity-0 -translate-y-1 scale-95` to `opacity-100 translate-y-0 scale-100`
- Sign-out action: red text `text-cpsu-danger hover:bg-red-50`, Lucide `log-out`

### Login Page

The login page must closely match the existing CSMS login screen.

Guest page shell:

```html
<body class="min-h-screen flex items-center justify-center p-4"
      style="background:
        radial-gradient(1200px 500px at 100% 0%, rgba(255,213,0,.10), transparent 60%),
        radial-gradient(1000px 600px at 0% 100%, rgba(11,110,46,.12), transparent 55%),
        #F7F8F5;">
```

Login content:

- Main width: `w-full max-w-md`
- Outer Alpine state: `x-data="{ loading: false }"`
- Page entrance: `data-aos="fade-up"`
- Logo container: `h-20 w-20 rounded-full bg-white shadow-md ring-4 ring-cpsu-gold/70 flex items-center justify-center overflow-hidden mb-3`
- Logo image: `h-full w-full object-contain p-1`
- Main heading: centered, `text-lg font-extrabold text-cpsu-green leading-tight`
- Small school text: `text-xs text-gray-500 mt-1`
- Login card: `bg-white rounded-2xl border border-cpsu-border shadow-sm p-6 sm:p-8`
- Card title: `Welcome back`, `text-base font-bold text-cpsu-black`
- Card subtitle: `text-sm text-gray-500`
- Email/password fields include Lucide icons positioned `absolute left-3 top-1/2 -translate-y-1/2`
- Inputs: `w-full rounded-lg border border-cpsu-border pl-9 pr-3 py-2.5 text-sm focus:border-cpsu-green focus:ring-2 focus:ring-cpsu-green/20 outline-none`
- Password has an Alpine show/hide toggle with `eye` and `eye-off` icons
- Remember checkbox: `rounded border-cpsu-border text-cpsu-green focus:ring-cpsu-green/30`
- Submit button: `w-full bg-cpsu-green hover:bg-cpsu-green-dark text-white font-semibold rounded-lg py-2.5 transition-all active:scale-95 disabled:opacity-70 flex items-center justify-center gap-2`
- Loading state: spinner icon plus text `Signing in...`
- Error alert: `rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2 flex items-start gap-2` with Lucide `alert-circle`
- Footer text: centered, `text-[11px] text-gray-400 mt-6`

### Cards and Panels

Use white cards everywhere for dashboards, forms, workflow summaries, and reports.

Default card:

```html
<div class="bg-white rounded-xl border border-cpsu-border shadow-sm p-5">
```

Interactive card:

```html
<div class="bg-white rounded-xl border border-cpsu-border p-5 shadow-sm transition-all duration-150 hover:shadow-lg hover:-translate-y-0.5">
```

Card header:

- `px-5 py-4 border-b border-cpsu-border flex items-center gap-2`
- Heading: `font-bold text-sm`
- Icon: Lucide `w-4 h-4 text-cpsu-green`

KPI cards:

- Grid: `grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-4`
- Label: `text-xs font-medium text-gray-500 uppercase tracking-wide truncate`
- Value: `mt-2 text-3xl font-extrabold text-cpsu-black countup`
- Icon badge: `h-11 w-11 rounded-lg flex items-center justify-center shrink-0`
- Green accent: `bg-cpsu-green/10 text-cpsu-green`
- Gold accent: `bg-cpsu-gold/20 text-cpsu-gold-dark`
- Blue accent: `bg-blue-100 text-blue-700`
- Amber accent: `bg-amber-100 text-amber-700`
- Red accent: `bg-red-100 text-red-700`

Use CountUp.js for dashboard numbers and payroll totals such as gross pay, total
deductions, net pay, submitted payrolls, returned payrolls, and approved payrolls.

### Buttons, Inputs, Badges, and Icon Buttons

Primary button:

- `inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-all active:scale-95 disabled:opacity-60 bg-cpsu-green hover:bg-cpsu-green-dark text-white`

Secondary button:

- Same base classes, plus `bg-cpsu-gold hover:bg-cpsu-gold-dark text-cpsu-black`

Ghost button:

- Same base classes, plus `bg-white border border-cpsu-border text-cpsu-black hover:bg-cpsu-bg`

Danger button:

- Same base classes, plus `bg-cpsu-danger hover:bg-red-700 text-white`

Text inputs:

- Label: `block text-sm font-medium text-cpsu-black`
- Input: `w-full rounded-lg border border-cpsu-border px-3 py-2 text-sm focus:border-cpsu-green focus:ring-2 focus:ring-cpsu-green/20 outline-none`
- Hint: `text-xs text-gray-400`
- Error text: `text-xs text-cpsu-danger`
- Required marker: `text-cpsu-danger`

Icon buttons:

- Base: `inline-flex items-center justify-center h-8 w-8 rounded-lg transition-all active:scale-90`
- Default: `text-gray-500 hover:text-cpsu-green hover:bg-cpsu-green/10`
- View: `text-blue-600 hover:text-blue-700 hover:bg-blue-50`
- Edit: `text-cpsu-green hover:text-cpsu-green-dark hover:bg-cpsu-green/10`
- Danger/delete: `text-cpsu-danger hover:text-red-700 hover:bg-red-50`
- Icon size: `w-[17px] h-[17px]`

Badges:

- Base: `inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold`
- Green: `bg-cpsu-green/10 text-cpsu-green`
- Gold: `bg-cpsu-gold/20 text-cpsu-gold-dark`
- Red: `bg-red-100 text-red-700`
- Blue: `bg-blue-100 text-blue-700`
- Gray: `bg-gray-100 text-gray-600`
- Amber: `bg-amber-100 text-amber-700`

Recommended payroll badge mapping:

- Draft: gray
- Generated: blue
- Submitted: gold
- Under review: amber
- Returned: red
- Approved: green
- Paid/posted: green
- Error/blocker: red
- Missing logs: amber

### Searchable Selects and Filters

Use Tom Select for any select that may contain more than a few options:

- Campus
- Employee
- Payroll type
- Fund cluster
- Department/office
- Reviewer/approver
- Deduction type
- Account title

Tom Select visual rules:

```css
.ts-wrapper {
  margin: 0 !important;
  padding: 0 !important;
  border: 0 !important;
  background: transparent !important;
}
.ts-wrapper.single .ts-control {
  box-sizing: border-box !important;
  border-radius: .5rem !important;
  border-color: var(--cpsu-border) !important;
  height: 38px !important;
  min-height: 38px !important;
  padding: 0 12px !important;
  font-size: .875rem !important;
  display: flex !important;
  flex-wrap: nowrap !important;
  align-items: center !important;
  box-shadow: none !important;
}
.ts-wrapper.single .ts-control > * {
  margin: 0 !important;
  padding: 0 !important;
  line-height: 36px !important;
  height: 36px !important;
}
.ts-wrapper.single .ts-control > input { min-height: 0 !important; }
.ts-control.focus {
  border-color: var(--cpsu-green) !important;
  box-shadow: 0 0 0 3px rgba(11,110,46,.12) !important;
}
```

Filter bars:

- Wrap filters in a white card: `bg-white rounded-xl border border-cpsu-border shadow-sm p-4 mb-4 flex flex-wrap items-end gap-3`
- Use `ml-auto` for right-aligned summary text or export buttons
- Summary text: `text-xs text-gray-400`
- Date fields use Flatpickr and the same input classes

Flatpickr styling:

- Selected days use CPSU green background and border
- Range fill uses soft green `#eaf3ec`
- Month and weekday accents use CPSU green

### Tables and Search Lists

Tables must look and behave like the existing CSMS DataTables. This is important.
Use DataTables for searchable/index pages and server-side review queues.

Table shell:

- Put the table in a white card: `bg-white rounded-xl border border-cpsu-border shadow-sm overflow-hidden`
- Header area, when needed: `px-5 py-4 border-b border-cpsu-border flex items-center justify-between gap-3`
- Body padding for tables can be `p-4` or no padding if the table fills the card
- Never use heavy dark table headers
- Avoid horizontal scrolling; use DataTables Responsive child rows for smaller screens

DataTables controls:

- Search input and page length select:
  - Border: `1px solid #E3E6DE`
  - Radius: `0.6rem`
  - Padding: `0.5rem 0.8rem`
  - Background: white
  - Font size: `.875rem`
  - Focus border: `#0B6E2E`
  - Focus ring: `0 0 0 3px rgba(11,110,46,.12)`
- Search input minimum width: `220px`
- Search placeholder: `Search...`
- Controls margin bottom: `.75rem`
- Info text: `#8a978c`, `.8rem`, padding top `1rem`

DataTables table styling:

```css
table.dataTable {
  width: 100% !important;
  border-collapse: separate !important;
  border-spacing: 0;
}
table.dataTable thead th {
  background: transparent;
  color: #6b7a6f;
  font-weight: 600;
  text-transform: uppercase;
  font-size: .68rem;
  letter-spacing: .04em;
  padding: .55rem .85rem !important;
  border: 0 !important;
  border-bottom: 1.5px solid var(--cpsu-border) !important;
}
table.dataTable tbody td {
  padding: .7rem .85rem !important;
  vertical-align: middle;
  border: 0 !important;
  border-bottom: 1px solid #f0f2ee !important;
  font-size: .875rem;
  word-break: break-word;
}
table.dataTable tbody tr { transition: background-color .12s; }
table.dataTable tbody tr:hover { background: var(--cpsu-gray-bg); }
table.dataTable tbody tr:last-child td { border-bottom: 0 !important; }
table.dataTable.no-footer { border-bottom: 0 !important; }
```

Pagination:

- Buttons: rounded `.55rem`, margin `0 2px`, padding `.35rem .75rem`
- Current page: CPSU green background, white text, green border, subtle green shadow
- Hover: CPSU gray background, CPSU border, green text
- Disabled: opacity `.4`

Responsive child rows:

- Use DataTables responsive extension, not horizontal overflow
- First column gets expand control on collapsed screens
- Expand button: small rounded green square with white `+`
- Expanded parent state: red square with white `-`
- Child row background: `var(--cpsu-gray-bg)`
- Child labels: uppercase, `text-xs`, gray-green, minimum width `8rem`

Skeleton loader:

```css
.cpsu-skeleton {
  display: grid;
  gap: .5rem;
  padding: .5rem 0;
}
.cpsu-skeleton > div {
  height: 2.25rem;
  border-radius: .5rem;
  background: linear-gradient(90deg,#eef1ec 25%,#f6f8f4 37%,#eef1ec 63%);
  background-size: 400% 100%;
  animation: cpsu-shimmer 1.2s ease infinite;
}
@keyframes cpsu-shimmer {
  0% { background-position: 100% 0; }
  100% { background-position: 0 0; }
}
```

DataTable factory behavior:

- `processing: true`
- `serverSide: true` for large lists
- `responsive: { details: { type: 'column', target: 'tr' } }`
- `autoWidth: false`
- First column responsive priority `1`
- Last/actions column responsive priority `2`
- Page length default `10`
- Length menu `10, 25, 50, 100`
- Language:
  - Search label empty
  - Search placeholder `Search...`
  - Empty table `No records found.`
  - Zero records `No matching records.`
  - Processing HTML uses `.cpsu-skeleton`
- After every draw, call `lucide.createIcons()`

Payroll table action buttons:

- View payroll: icon `eye`, blue icon button
- Edit/correct: icon `pencil`, green icon button
- Submit: icon `send`, primary button
- Approve: icon `check-circle`, primary/green button
- Return: icon `rotate-ccw`, amber or danger depending severity
- Delete/void: icon `trash-2`, danger icon button with SweetAlert2 confirmation
- Export PDF: icon `file-text`
- Export Excel: icon `file-spreadsheet` or `download`
- Print: icon `printer`

### Modals and Dialogs

Use Alpine modals for payroll line details, deduction breakdowns, return comments,
manual override forms, and approval confirmations that need form input.

Modal structure:

- Fixed overlay: `fixed inset-0 z-[60] overflow-y-auto`
- Backdrop: `fixed inset-0 bg-black/40`
- Centering shell: `flex min-h-full items-center justify-center p-4`
- Panel: `relative w-full max-w-lg bg-white rounded-2xl shadow-xl border border-cpsu-border`
- Header: `flex items-center justify-between px-5 py-4 border-b border-cpsu-border`
- Title: `font-bold text-cpsu-black`
- Close button: `p-1.5 rounded-lg hover:bg-cpsu-bg text-gray-400 hover:text-cpsu-black transition`
- Body: `p-5`
- Enter animation: `opacity-0 translate-y-2 scale-95` to `opacity-100 translate-y-0 scale-100`
- Leave animation: reverse with `duration-150`

Use SweetAlert2 helper dialogs for confirms and toasts:

- Confirm button color: `#0B6E2E`
- Cancel button color: `#DC2626`
- Toast position: `top-end`
- Toast timer: `3000`
- Toast has a left border color matching type:
  - success `#16A34A`
  - error `#DC2626`
  - info `#FFD500`
  - warning `#E6BF00`

### Animation and Motion

Motion must be subtle, quick, and consistent:

- Page/card reveal: `data-aos="fade-up"`
- AOS defaults: duration `500`, once `true`, offset `40`
- Stagger cards using `data-aos-delay="{{ $i * 60 }}"`
- Hover card lift: `hover:shadow-lg hover:-translate-y-0.5`
- Button press: `active:scale-95`
- Icon button press: `active:scale-90`
- Sidebar drawer: `transition-transform duration-200`
- Dropdowns: `transition ease-out duration-150`, small fade/scale/translate
- Badge state changes: transition background/color/border `.35s ease`
- Use `x-cloak` for Alpine hidden states
- Call `lucide.createIcons()` on DOMContentLoaded, after Alpine init, and after DataTables redraws

### Charts and Reports UI

Use Chart.js for dashboard/report charts.

Chart panels:

- Card: `bg-white rounded-xl border border-cpsu-border shadow-sm p-5`
- Heading: `font-bold text-sm mb-4 flex items-center gap-2`
- Icon: `w-4 h-4 text-cpsu-green`
- Chart wrapper height: `relative h-64` or `relative h-72`
- Doughnut max width: `max-width:320px` or `340px` centered when appropriate

Chart defaults:

- Font family: Inter
- Font size: `11`
- Text color: `#6b7280`
- Tooltip background: `rgba(17,24,39,.92)`
- Tooltip padding: `10`
- Tooltip radius: `8`
- Tooltip box padding: `4`
- Use a mixed palette: `#0B6E2E`, `#2563EB`, `#E6BF00`, `#16A34A`, `#9333EA`, `#0EA5E9`, `#F97316`, `#DC2626`

### Required Payroll Pages

- Dashboard with payroll status counts
- Campus payroll dashboard
- Fund cluster management
- Employee salary and deduction settings
- Attendance import
- Missing log appeals
- Payroll period setup
- Payroll generation wizard
- Draft payroll review
- Payroll line detail modal
- Submit-to-main-campus screen
- University payroll review queue
- Returned payroll correction screen
- Approved payroll print screen
- Audit log viewer

### Page-Specific Payroll UI

Dashboard:

- Top filter card for date range, campus, payroll type, and fund cluster.
- KPI row with cards for draft, submitted, returned, approved, gross amount,
  deductions, and net amount.
- Use CountUp for all monetary and count values.
- Use chart cards for payroll status distribution, payroll totals by fund cluster,
  deductions by type, and monthly payroll trend.
- Use the same chart card style and mixed green/blue/gold/red palette described above.

Payroll generation wizard:

- Use a white `rounded-xl` panel with a compact step indicator at the top.
- Step indicator uses small circles or pills with green for completed/current,
  gray for pending, red for blocking errors.
- Steps should feel like an operational form, not a marketing wizard:
  1. Select campus, period, payroll type, and fund cluster.
  2. Import or confirm attendance.
  3. Preview employees and missing logs.
  4. Review computed gross pay, deductions, and net pay.
  5. Generate draft payroll.
- The main action remains a green primary button; secondary/back action is ghost.
- Critical blocking messages use red bordered alerts; warnings use amber bordered alerts.

Draft payroll review:

- Use a filter card above the table for campus, period, fund cluster, status,
  employee search, and reviewer.
- Main employee payroll table must use DataTables styling exactly as specified.
- Employee name column should show name, employee number, campus/office in muted text.
- Amount columns should align right and use tabular numbers.
- Status column uses badges.
- Action column uses compact icon buttons.
- Row detail modal shows salary, attendance deductions, tax, other deductions,
  adjustments, override reason, and final net pay.

Payroll line detail modal:

- Use the standard Alpine modal style.
- Header contains employee name, employee number, and payroll status badge.
- Body uses two-column responsive sections on desktop and one column on mobile.
- Computation breakdown cards use the same `bg-white rounded-xl border` style inside
  the modal only when they represent separate repeated groups.
- Highlight missing logs and unresolved appeals with amber badges or red alerts.
- Manual override controls must require reason and show reviewer visibility.

University payroll review queue:

- Use DataTables with server-side search.
- Queue rows should show payroll batch number, campus, period, fund cluster,
  submitted by, submitted date, total gross, total deductions, total net, status,
  current reviewer, and actions.
- Use a right-side toolbar for export, print draft, approve, return, and comments.
- Approve and return actions must use `CPSU.confirm(...)` or modal forms.

Returned payroll correction screen:

- Show a red/amber summary banner at the top with return reason and reviewer.
- Correction items use cards with status badges and direct action buttons.
- Keep the table/search behavior identical to the review table.

Approved payroll print screen:

- Screen view can use the normal app shell, but printable output should be clean white
  with the CPSU seal from `asset('images/cpsu-logo.png')`.
- Approved final print has no `DRAFT` watermark.
- Draft exports must show a large, light `DRAFT` watermark.
- Print buttons use Lucide `printer`; PDF export uses `file-text`; Excel export uses
  `download` or `file-spreadsheet`.

Audit log viewer:

- Use DataTables.
- Filters: date range, actor, campus, action type, payroll batch, severity.
- Severity badges: green for success, amber for warning, red for destructive/error,
  blue for informational events.

### UX Requirements

- Use clear status badges.
- Show computation breakdown per employee.
- Highlight missing logs and unresolved appeals.
- Disable submit while critical errors exist.
- Allow export of draft for checking with watermark `DRAFT`.
- Only final approved payroll can be printed without draft watermark.

## Validation Rules

Prevent final approval when:

- Any payroll line has no employee ID.
- Any payroll line has no fund cluster.
- Any payroll line has missing salary/rate.
- Any payroll line has unresolved missing logs.
- Any appeal used in computation is not approved.
- Any computed net amount is negative unless approved with override reason.
- Totals do not match line-level sums.

Allow manual override only with:

- Reason
- Amount
- Attachment, if required
- User ID
- Timestamp
- Reviewer visibility

## Application Route Requirements

Create API endpoints for:

- Validate the direct HRIS database connection using a protected `POST` action
- Sync employees from the direct HRIS database
- Read DTR/attendance logs from the direct HRIS database
- Read official work schedules from the direct HRIS database
- Import attendance logs
- Fetch attendance summary by employee and period
- Submit missing log appeal
- Approve/reject missing log appeal
- Generate payroll draft
- Recalculate payroll batch
- Submit payroll for review
- Return payroll for correction
- Approve payroll for printing
- Export payroll PDF
- Export payroll Excel

Use Laravel policies or gates for authorization.

All HRIS reads must use the dedicated `hris` database connection. Do not add outgoing HRIS HTTP requests or expose HRIS database credentials to Blade, JavaScript, URLs, logs, or exports.

All internal action routes used by the payroll frontend must use `POST` for actions that trigger sync or generation. Do not expose sensitive payroll data through query strings.

Required internal payroll API endpoints:

- `POST /api/hris/connection-check`
- `POST /api/hris/sync/employees`
- `POST /api/payroll/batches/{batch}/recalculate`
- `POST /api/payroll/batches/{batch}/dynamic-columns`
- `POST /api/payroll/templates/{template}/columns`
- `POST /api/payroll/templates/{template}/validate-formulas`

## Testing Requirements

Write tests for:

- Payroll computations
- Only the four final roles can be seeded or assigned.
- Employees cannot log in and are not stored as system users.
- Super Administrator and University Payroll Administrator users must have `campus_id = NULL`.
- Campus Payroll Administrator users must have exactly one non-null `campus_id`.
- Auditor / Accounting Viewer access works as university-wide when `campus_id = NULL` and campus-restricted when `campus_id` is set.
- Late/undertime/absent deduction
- Approved appeal marks missing log as present
- Rejected appeal does not affect payroll
- Campus administrator cannot access another campus payroll
- Campus administrator cannot approve their own payroll.
- University Payroll Administrator cannot directly edit submitted campus payroll and must return it for correction.
- Auditor / Accounting Viewer cannot mutate payroll, settings, employees, users, roles, or permissions.
- Submitted payroll cannot be edited except through correction workflow
- Returned payroll can be revised and resubmitted
- Approved payroll can be printed
- Draft payroll cannot be final printed
- Payroll snapshot remains unchanged after employee salary setting changes

## Deliverables

Produce:

- Laravel 10 project structure
- Migrations
- Seeders for roles, campuses, payroll templates, fund clusters, and common deduction types
- Eloquent models and relationships
- Controllers/services for payroll generation
- Blade UI pages
- PDF and Excel export classes
- Form requests and validation
- Policies/gates
- Feature and unit tests
- README with setup and payroll workflow

## Implementation Notes

Use service classes for payroll computation:

- `AttendanceSummaryService`
- `MissingLogAppealService`
- `PayrollComputationService`
- `PayrollBatchService`
- `PayrollReviewService`
- `PayrollExportService`

Do not put payroll formulas directly inside controllers.

Use database transactions when generating, recalculating, submitting, returning, or approving payroll.

Use queued jobs for large payroll generation and export if processing is slow.

Use immutable payroll snapshots for approved and printed payroll.

## Acceptance Criteria

The system is complete when:

- A campus payroll administrator can generate a draft payroll for a selected campus, period, payroll type, and fund cluster.
- The system automatically calculates salary, deductions, total deductions, and net amount.
- Missing logs are detected.
- Approved missing-log appeals can mark an employee present for payroll computation.
- Draft payroll shows a review page before submission.
- Payroll can be submitted to the University Payroll Administrator for review.
- University Payroll Administrator can approve, reject, or return payroll with required remarks.
- Returned payroll can be corrected and resubmitted.
- Final payroll cannot be printed until approved.
- Printed payroll matches the manual Excel payroll structure closely enough for official use.
- Multiple campus payroll administrators can work on separate campus payrolls without seeing or editing unauthorized campuses.
