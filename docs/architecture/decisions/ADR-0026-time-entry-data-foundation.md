# ADR-0026: Foundational Time Entry Data Model

- Status: Accepted
- Date: 2026-10-02

## Context

FieldOps needs a normalized data foundation and a backend timer engine for
recording staff time against Customers, Projects, Jobs, and, where applicable,
the scheduled Visit. The approved slice includes start, stop, and current-user
active-timer endpoints, with a reusable technician control embedded in Schedule
Job details rather than a separate time-tracking section. At the time of this
decision, approval, editing, and deletion were out of scope; the Admin review
workflow later approved by ADR-0028 supersedes that boundary. Timesheet
and payroll-hour reporting were later approved by ADR-0029. Payroll execution
and accounting workflows remain out of scope. ADR-0019 says work attributable
to a scheduled Visit should retain that association.

## Decisions

- Store entries in `time_entries`. Each row identifies the performing staff
  member (`user_id`), required Customer, optional Project, optional Job
  (`work_orders`), optional Visit, activity type, start/end timestamps, explicit
  duration in minutes, billable flag, notes, approval status, creator, and
  optional updater.
- A Project-only entry has a Project and no Job or Visit. A Job entry may omit
  Project when the Job is not part of a Project. `customer_id` remains required.
  A timer start can include `visit_id` when work is being performed for a
  scheduled Visit; the start request verifies it belongs to the selected Job.
  Choosing a Visit automatically remains a UI concern.
- Represent activity types and approval states with PHP backed enums and
  database-enforced allowed values. Persist activity values as stable snake-case
  identifiers (`remote_support`, `project_work`) and approval values as
  lowercase identifiers (`draft`, `approved`, `invoiced`, `paid`).
- Use `Draft` as the default status and `false` as the default for `billable` to
  avoid making a new entry billable without an explicit choice. A running timer
  is a Draft with null `ended_at` and `duration_minutes`; the database allows at
  most one running timer per user. Stopping stamps `ended_at` and stores elapsed
  whole minutes, truncated toward zero. Start/end timestamps are retained at
  second precision; no billing-rounding policy is implied.
- Keep performer and audit actors separate. `created_by` is required;
  `updated_by` is nullable until an edit occurs. Restrict physical deletion of
  referenced users and business records so entries retain attribution and
  context; normal record archival remains separate from time-entry deletion.
- Register a `TimeEntryPolicy`. Active staff with the existing
  `work_orders.manage` permission may start and stop their own timers; the active
  timer query uses `work_orders.view` and only returns the caller's entry. Other
  policy abilities remain fail-closed. No new Spatie permission is introduced.
- Keep the timer control in existing Schedule Job details. It displays timer
  status, activity, Customer/Project/Job/Visit context, live elapsed time, and
  Start/Stop actions so technicians do not navigate to a separate timer section.
- `Invoiced` and `Paid` are local status values only. This model does not create
  invoices, payments, billing rates, invoice identifiers, or QuickBooks writes.

## Consequences

- The schema adds relational FKs and indexes for staff/customer/context/status
  date queries. The timer start service verifies current Customer/Project/Job/
  Visit relationships; future time-entry write paths must preserve those
  cross-record checks because foreign keys alone only guarantee referenced rows
  exist.
- The four approval values are stored; Admin approval/edit/delete behavior and
  eligible transitions are defined by ADR-0028. `Invoiced` and `Paid` remain
  local status labels without accounting records.
- Overlapping completed intervals, alternative timer rounding, billable policy,
  payroll execution, invoicing, and payment integration remain out of scope.
  Payroll-hour reporting is separately defined in ADR-0029.

Job-status timer automation is specified separately in ADR-0027.
