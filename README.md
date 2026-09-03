# CPSU Payroll Management System

Laravel 10 payroll system for Central Philippines State University, modeled from `Payroll sample.xlsx`.

## Setup

1. Configure `.env` database values.
2. Create the database if needed.
3. Run:

```bash
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Demo accounts all use `password`:

- `super@cpsu.edu.ph`
- `university.payroll@cpsu.edu.ph`
- `main.payroll@cpsu.edu.ph`
- `san-carlos.payroll@cpsu.edu.ph`
- `auditor@cpsu.edu.ph`

## HRIS integration

Employee synchronization and payroll attendance/DTR calculations read directly from the HRIS database through a dedicated, read-only connection. Configure it separately from the payroll database:

```env
HRIS_DB_CONNECTION=mysql
HRIS_DB_HOST=127.0.0.1
HRIS_DB_PORT=3306
HRIS_DB_DATABASE=dbcpsuhris
HRIS_DB_USERNAME=root
HRIS_DB_PASSWORD=
```

Payroll reads employee records from `employees`, DTR punches from `dtrs`, and official schedules from `official_times`. It does not call an HRIS HTTP API. Direct database connection checks, employee syncs, and attendance reads are logged in `hris_sync_logs`.
The database seeder does not create employee records; employees must always be imported from HRIS.

## Workflow

Campus payroll administrators generate a draft by selecting campus, payroll period, workbook template, and fund cluster. The system computes rendered days, late/undertime/absence deductions, gross, earned pay, tax, statutory deductions, total deductions, and net pay.

Drafts can be submitted to University Payroll. University Payroll can approve or return with remarks. Final printable payroll and CSV export are blocked until the batch is approved.

## Tests

```bash
php artisan test
```

The test suite verifies automatic payroll computation with an approved missing-log appeal and campus-level payroll isolation.
