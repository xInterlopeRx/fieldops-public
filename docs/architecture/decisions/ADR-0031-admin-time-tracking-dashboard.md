# ADR-0031: Admin Time-Tracking Dashboard and Exceptions

- Status: Accepted
- Date: 2026-10-03

## Context

Office staff need one operational view of time-entry workload and timer issues,
with direct paths to the existing Review, Payroll, and Billing workspaces. The
dashboard is visibility over FieldOps records, not a payroll or accounting
system.

## Decisions

- Add an Admin-only Time Tracking Dashboard with all-time metrics for Unapproved
  Hours, Approved Hours, Billable Hours, Unbilled Hours, Active Timers, and
  Exceptions. Hours use stored `duration_minutes` from completed entries; active
  timers are counted separately and are excluded from hour totals.
- Unapproved Hours sums completed Draft entries. Approved Hours sums completed
  Approved, Invoiced, and Paid entries because those statuses have passed the
  approval stage. Billable Hours sums Billable entries among those same
  completed statuses. Unbilled Hours is the Billable Approved subset.
- An Exception is either (a) an active timer older than the configured
  `time_tracking.dashboard.stale_timer_hours` threshold or (b) a completed Draft
  entry whose `ended_at` is older than
  `time_tracking.dashboard.unapproved_entry_age_days`. Defaults are 12 hours
  and 7 days. The exception list is Admin-only and paginated.
- Provide quick links to Review, Payroll, Billing, and Exceptions. The Dashboard
  and Exceptions pages share an Admin authorization boundary; no new business
  permission is introduced.
- Compute aggregate hour metrics in a single database aggregate query. Exception
  records are loaded with displayed technician/Customer/Project/Job labels and
  bounded to 50 rows per page.

## Consequences

- Dashboard values are all-time operational totals and have no date-range
  control. Payroll and Billing retain their separate period-scoped reports.
- Exceptions are detection-only. This feature does not automatically stop
  timers, approve entries, send notifications, or make external writes.
- Threshold changes require editing configuration and deploying the updated
  config; there is no runtime settings editor.
