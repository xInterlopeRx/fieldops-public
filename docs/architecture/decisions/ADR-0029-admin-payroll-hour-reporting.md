# ADR-0029: Admin Payroll-Hour Reporting

- Status: Accepted
- Date: 2026-10-03

## Context

Administrators need a payroll-ready view of approved time without adding payroll
execution, third-party payroll connections, or accounting writes. Payroll
Regular/Overtime totals depend on an explicit threshold, workweek, inclusion
status, and date-range rule.

## Decisions

- The Admin > Time Tracking > Payroll report summarizes Regular Hours, Overtime
  Hours, and Total Hours by technician. Expanding a technician shows daily
  totals and the time-entry segments that contribute to them.
- Include completed `Approved` entries only. Draft, Invoiced, Paid, and active
  entries are excluded; the screen reports the number of completed non-Approved
  entries overlapping the selected dates. This report does not alter approval
  status.
- Use a weekly threshold of 2,400 minutes (40 hours) per technician and
  configured workweek. Classify approved hours in chronological order: the first
  2,400 minutes in the workweek are Regular, and later minutes are Overtime.
  There is no daily overtime stacking. The threshold and ISO workweek-start day
  are configured in `config/time_tracking.php`; defaults are 40 hours and
  Monday–Sunday.
- If the selected dates cover only part of a workweek, include all Approved
  entries overlapping each touched full workweek when calculating the threshold
  position. Display and export only hours allocated to dates inside the selected
  range.
- Allocate an entry's stored `duration_minutes` across the local calendar days
  it spans in proportion to elapsed `started_at`/`ended_at` seconds. This keeps
  daily allocations summing to the recorded duration while allowing
  cross-midnight entries to appear on each worked date. Calculation and date
  boundaries use the configured application timezone.
- Default the report to the current configured workweek and bound one request to
  at most 366 inclusive dates. Hour values retain six decimal places in report
  data and exports; the screen presents four decimal places.
- CSV export is a payroll summary with one row per technician and a grand total.
  Excel export is an Excel-compatible SpreadsheetML `.xls` workbook with
  Summary, Daily Totals, and Time Entries sheets. Text cells are emitted as
  strings and CSV text is protected against formula injection.
- Only active Admins with `admin.access` can view or export the report. All
  queries are server-side, bounded by the selected date range plus complete
  workweeks needed for overtime classification, and eagerly load the displayed
  related labels.
- The feature reports hours only. It does not calculate wages/rates, run
  payroll, make payments, or connect to payroll or accounting providers.

## Consequences

- Hours may be fractional when an entry is proportionally split across dates;
  report rounding is representational only and does not change stored minutes.
- Existing overlapping Approved entries are summed as recorded. Detecting or
  resolving overlapping completed intervals remains a separate data-quality
  decision.
- Changing the overtime threshold or workweek start requires updating the
  configuration and deploying the new rule. Historical exports use the rule
  active when the export is generated; no policy-version history is stored.
