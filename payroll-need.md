# HRIS → Payroll Data Contract

Full logic of what the CPSU Payroll Management System reads from HRIS, when it reads
it, how it turns that data into pay, and what HRIS still needs to provide before a
payroll run is fully automated.

**Connection:** payroll reads the HRIS database **directly over a read-only SQL
connection**. There is no HTTP API and no import file, so "what to give payroll" means
*tables and columns to expose*, not a payload to build.

| | |
|---|---|
| Database | `dbcpsuhris` |
| Tables read | `employees`, `dtrs`, `official_times` |
| Join key | `emp_ID` |
| Configured by | `HRIS_DB_*` in `.env` |
| Access needed | `SELECT` only — payroll never writes to HRIS |

**Field states used below**

- **SUPPLIED** — HRIS already provides this and payroll consumes it automatically.
- **NEEDED** — payroll wants this and HRIS is the right owner. Not available today.
- **BLOCKER** — missing data that currently produces wrong or hand-corrected pay.
- **PAYROLL-SIDE** — encoded by payroll staff, deliberately out of HRIS scope.

---

## 1. When payroll reads HRIS

There are exactly two read events. Everything else in payroll runs on data already
copied in.

| # | Event | Trigger | Tables read |
|---|---|---|---|
| A | **Employee sync** | Manual — a user with HRIS rights clicks "Sync from HRIS" | `employees` |
| B | **Attendance read** | Automatic — on every payroll generation, and on "Refresh attendance" | `employees`, `dtrs`, `official_times` |

Every read, successful or failed, is written to payroll's `hris_sync_logs` with the
request type, status, duration in milliseconds, record count and any error message.
That table is the audit trail if a run looks wrong.

**Payroll periods are semi-monthly** and generated automatically: day 1–15 and day 16
to end of month, for the current and previous month. All attendance reads are bounded
by the selected period's `date_from` and `date_to`.

---

## 2. Read A — Employee sync

### The query

```sql
SELECT emp_ID, fname, mname, lname, prefix, suffix,
       position, emp_dept, emp_status, camp_id
FROM   employees
WHERE  stat_1 = 1
  AND  emp_ID IS NOT NULL
  AND  emp_ID <> ''
  [AND camp_id = ?]      -- forced to their own campus for campus administrators
  [AND emp_status = ?]   -- only when a status filter is chosen
ORDER  BY lname, fname
```

### Field mapping

| HRIS column | Becomes | Rule | State |
|---|---|---|---|
| `emp_ID` | `employee_no` | Identity key across both systems and the join key for all attendance. **Must never be reused or renumbered.** | SUPPLIED |
| `lname`, `prefix`, `fname`, `mname`, `suffix` | `full_name` | Joined in that order. A single `name` column is used instead when present. | SUPPLIED |
| `position` | `designation` | Printed on the payroll sheet as-is. | SUPPLIED |
| `emp_dept` | `office_id` | Treated as an **office ID**, not a name. A numeric value is resolved against the offices reference table — see gap 4.2. | SUPPLIED |
| `emp_status` | `employment_type` | Selects the pay formula (section 5). **A wrong code pays the wrong rate.** | SUPPLIED |
| `camp_id` | `campus_id` | Used verbatim — HRIS campus IDs 1-12 must stay identical to payroll's. Also scopes who may view the record. | SUPPLIED |
| `stat_1` | *(filter only)* | Only `1` is read. Separated employees simply stop appearing; payroll is never told they left. | NEEDED |

### Upsert behaviour

- Records are matched on `emp_ID` and inserted or updated — never duplicated.
- HRIS-owned fields above are **overwritten** on every sync.
- Payroll-owned fields (salary, fund cluster, tax profile, deductions, part-time rate)
  are **preserved** and never touched by a sync.
- Rows with a blank `emp_ID`, or a `camp_id` that does not exist in payroll, are
  skipped and counted as skipped.
- Payroll's seeder never creates employees. **HRIS is the only source of employees.**

---

## 3. Read B — Attendance

Runs automatically when a payroll draft is generated, scoped to the employees in that
run.

### Parameters payroll sends

```
date_from   = period.date_from
date_to     = period.date_to
campus_id   = the campus being generated
emp_id      = list of employee numbers in the run (or "all")
emp_status  = "all"
```

### The queries

```sql
-- 1. eligible employees
SELECT emp_ID FROM employees
WHERE  stat_1 = 1 AND emp_ID <> ''
  [AND camp_id = ?] [AND emp_ID IN (...)]

-- 2. punches
SELECT emp_ID, date, time_in, time_out, time_over
FROM   dtrs
WHERE  emp_ID IN (...)
  AND  date BETWEEN :date_from AND :date_to
ORDER  BY emp_ID, date

-- 3. schedules
SELECT * FROM official_times WHERE empid IN (...)
```

### Table shapes

**`dtrs`** — one row per punch batch per day.

| Column | Used for | Notes |
|---|---|---|
| `emp_ID` | Join to the employee | Must match `employees.emp_ID` exactly, trimmed. |
| `date` | Grouping into days | Filtered to the payroll period range. |
| `time_in` | AM in, PM in | May hold several punches comma-separated in one cell. |
| `time_out` | AM out, PM out | Same format. |
| `time_over` | Overtime in / out | Captured and displayed, but **not yet paid** — see gap 4.6. |

**`official_times`** — one row per employee, keyed by `empid`. Ten columns,
`morn_mon`…`morn_fri` and `aft_mon`…`aft_fri`, each a range string like `08:00-12:00`.

- There are **no Saturday or Sunday columns**, so weekend duty cannot be scheduled or paid.
- A missing row, missing column or unparseable range falls back to the default
  schedule: **08:00–12:00** and **13:00–17:00**.

### How payroll derives late and undertime

Per employee, per date, in this order:

1. **Resolve the schedule.** Pick the `morn_*` / `aft_*` pair for that weekday from
   `official_times`. Fall back to the default schedule for anything missing.
2. **Parse the punches.** Split `time_in`, `time_out` and `time_over` on commas, parse
   each to `HH:MM`, drop unparseable values, de-duplicate, sort ascending.
3. **Filter to plausible punches.**
   - time-ins kept only if at or before **afternoon start + 30 minutes**
   - time-outs kept only if at or after **morning end − 60 minutes**
4. **Require two of each.** A day needs **≥ 2 time-ins and ≥ 2 time-outs** to be
   complete. AM in = first time-in, PM in = last; AM out = first time-out, PM out = last.
5. **Compute, per half-day:**
   ```
   late      = max(0, am_in  - morning_start) + max(0, pm_in  - afternoon_start)
   undertime = max(0, morning_end - am_out)   + max(0, afternoon_end - pm_out)
   ```
6. **Flag incomplete days.** If either pair is short, that day contributes **zero**
   late and zero undertime and is flagged for HR review instead — a missing log never
   silently under-deducts.

Totals per employee per period: `total_late_minutes`, `total_undertime_minutes`,
`review_days`.

### The integration hook that already exists

Payroll's attendance sync reads these keys from the per-employee summary, and uses
its own default only when a key is absent:

| Key it looks for | Aliases accepted | Default when missing |
|---|---|---|
| `present_days` | — | **every weekday in the period** |
| `absent_days` | — | **0** |
| `total_late_minutes` | `late_minutes` | 0 |
| `total_undertime_minutes` | `undertime_minutes` | 0 |

**`present_days` and `absent_days` are already consumed if supplied — HRIS simply
never supplies them.** That is gap 4.1, and it is the smallest possible change on the
payroll side: expose the data and it is picked up.

---

## 4. What HRIS still needs to provide

Ordered by how much manual work each removes. The first two are the difference
between an automated run and a hand-checked one.

### 4.1 Absences and approved leave — BLOCKER

The single biggest gap. Payroll has no source for absences, so it assumes **every
weekday in the period was worked** and sets absent days to zero. Unpaid absences are
caught only if a human notices. HRIS already holds `leave_applications` and
`leave_credits`; payroll needs a readable per-employee, per-date view of them.

```
Needed per employee, per payroll period:
  present_days      days actually rendered
  absent_days       unpaid absences
  paid_leave_days   approved leave that still pays

Or a readable table:
  emp_ID, date, leave_type, is_paid, status   (approved leave only)
```

Note that payroll's own review workflow already classifies resolutions as CTO,
Vacation Leave, Sick Leave and Emergency Leave — the same vocabulary HRIS uses. Those
are being entered by hand today because the data is not readable.

### 4.2 The offices reference table — BLOCKER

`employees.emp_dept` stores an office ID — 28, 73, 14 — but **no offices table exists
in `dbcpsuhris`** (verified across all 64 tables). Payroll currently carries a frozen
70-row copy taken from the previous payroll system. Any office HRIS adds or renames is
invisible until that copy is manually refreshed, and a new ID resolves to nothing.

```
Needed in dbcpsuhris:
  offices(id, office_name, office_abbr, stat)

Critical: ids must match the values already in employees.emp_dept.
          Do not renumber.
```

### 4.3 Salary and salary grade — NEEDED

`monthly_salary` and `salary_grade` are typed into payroll by hand for every employee,
and every promotion or step increment has to be re-typed. If HRIS holds appointment or
plantilla records, exposing the current rate with its effectivity date removes that
entirely.

```
Needed: emp_ID, monthly_rate, salary_grade, step, effective_from, effective_to
```

### 4.4 Separations and status changes — NEEDED

When someone resigns, `stat_1` flips and they vanish from the sync. Payroll never
learns they separated, so their record stays active until someone deactivates it. A
last-day date lets payroll stop paying them on the correct period automatically.

```
Needed: emp_ID, separation_date, reason   (or stat_1 history with dates)
```

### 4.5 Holiday calendar — NEEDED

No holiday table exists in either system. Working days are counted as Monday-Friday
only, so a regular or special holiday is treated as an ordinary working day. This
affects the daily-rate divisor and blocks holiday premium pay.

```
Needed: date, name, type (regular | special), campus_id (null = university-wide)
```

### 4.6 Overtime approval — NEEDED

`dtrs.time_over` is read and shown, but payroll cannot pay it — there is no record of
which overtime was *approved*. Unapproved punches must never become pay, so the flag
has to come from HRIS.

```
Needed: emp_ID, date, approved_hours, status
```

---

## 5. What the data feeds

So HRIS can see why each field matters. `emp_status` selects the formula; everything
else feeds it.

### Employment status mapping

| `emp_status` | Employment type | Daily rate |
|---|---|---|
| `1` | Regular | monthly / working days in the month |
| `2` | Full-time / Part-time | monthly / working days in the month |
| `3` | Part-time / Part-time | hourly rate x hours rendered |
| `4` | Job Order | monthly / **22**, fixed |

- **Job Order is always 22 days**, even in a month with 23 working days.
- Working days means Monday-Friday across the **whole month**, so a semi-monthly
  period still divides by the full month's count.
- Part-time/Part-time uses an hourly rate encoded in payroll, not a monthly salary.
- An unrecognized `emp_status` falls back to Regular.

### Period computation

```
rate/day     = monthly / divisor above
rate/hour    = rate/day / 8
rate/minute  = rate/hour / 60

Gross             = rate/day     x days rendered
Late deduction    = rate/minute  x late minutes
Undertime         = rate/minute  x undertime minutes
Absent deduction  = rate/day     x absent days
Earned            = gross - late - undertime - absent
Net               = earned - total deductions
```

Statutory deductions apply on the **first-half period only** (day 1 to 15):

- SSS — 5% of salary credit, capped 5,000-35,000
- PhilHealth — 5% of a 10,000-100,000 base
- Pag-IBIG — 2% capped at 200 (1% at or below 1,500)
- Withholding tax — earned x the employee's tax rate

The second-half period pays earnings only, with no scheduled deductions.

---

## 6. What happens when HRIS is unavailable

Payroll degrades deliberately rather than guessing:

- Generation still produces a draft, but **late and undertime are forced to zero** for
  every line rather than being estimated.
- The batch records the failure in its snapshot and the attempt is logged to
  `hris_sync_logs` with status `unavailable`.
- **Submission is blocked** — a draft generated while the HRIS attendance database was
  unreachable cannot be submitted until a successful "Refresh attendance" run.
- Separately, any line still carrying an unresolved attendance review also blocks
  submission until HR resolves it.

The practical implication for HRIS: **downtime does not corrupt payroll, it stalls
it.** Availability during payroll preparation matters more than raw speed.

---

## 7. Deliberately not from HRIS

Encoded and owned by payroll staff. Listed so nobody builds them twice.

| Field | Why payroll owns it |
|---|---|
| `fund_cluster` | Which fund pays the person — a budget decision, not an HR one. |
| `tax_rate`, `bir_sworn_status` | BIR profile maintained with the sworn declaration on file. |
| `nsca_mpc_amount` | Cooperative deduction set by the coop, not HR. |
| `other_deductions` | Loans and one-off adjustments per period. |
| `philhealth_contribution_type` | Direct or indirect, decided per employee. |
| `part_time_rate_per_hour` | Negotiated hourly rate for a part-time post. |
| Payroll signatories | Chosen per batch by the campus payroll administrator. |

If HRIS actually owns any of these, say so and they move into section 4.

---

## 8. Invariants

Break any of these and payroll produces wrong numbers rather than an error.

- `emp_ID` is permanent. Changing it orphans an employee's entire attendance and
  payroll history.
- `camp_id` values must stay aligned with payroll's campus IDs 1-12. A mismatch
  silently routes an employee to the wrong campus payroll, or skips them entirely.
- `emp_dept` must stay a valid office ID. Renumbering offices breaks every employee's
  office assignment at once.
- `emp_status` must be one of 1, 2, 3 or 4. Anything unrecognized falls back to
  Regular — and pays as Regular.
- `dtrs.emp_ID` must match `employees.emp_ID` exactly. Payroll trims whitespace but
  does not fuzzy-match; an unmatched punch row is invisible.
- Times are parsed as clock strings. **Blank beats malformed** — an unreadable value is
  treated as no punch and flagged for review, but a wrong one is trusted and paid.
- Payroll only ever reads. Any change to HRIS schema names or types is a breaking
  change for payroll and should be coordinated before deployment.

---

*Generated from the CPSU Payroll codebase — the HRIS read layer, attendance derivation
and computation service. Reflects the system as built; the gaps in section 4 are open
asks, not existing behavior.*
