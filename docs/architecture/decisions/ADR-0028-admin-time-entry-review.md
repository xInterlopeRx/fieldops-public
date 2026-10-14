# ADR-0028: Admin Time-Entry Review and Approval

- Status: Accepted
- Date: 2026-10-03

## Context

FieldOps now has timer capture and status-driven automation, but administrators
need one bounded place to review and process staff time entries. The user
explicitly expanded the previously deferred approval, edit, and delete scope.
This workflow is internal administration only; it does not make FieldOps a
payroll or accounting system.

## Decisions

- Add an Admin > Time Tracking > Review screen backed by a paginated query with
  filters for the entry start-date range, technician, Customer, Project,
  activity type, billable state, and approval status. Results are ordered by
  start time and ID, with related labels eager-loaded.
- Require the active `admin.access` ability for review, edit, approve,
  unapprove, bulk approve, and delete. Timer users retain their existing
  owner-scoped start/stop access; no new Spatie permission or cross-user timer
  endpoint is introduced.
- Approval is a reversible Draft ↔ Approved transition for completed entries
  only. A running entry cannot be approved. Bulk approval accepts at most 100
  selected IDs and is atomic: any missing, running, or non-Draft selection
  rejects the entire batch.
- Admin edits may change start/end timestamps, duration, activity type,
  billable state, and notes. Context/performer foreign keys remain unchanged.
  Edits are allowed for completed Draft or Approved entries; editing an
  Approved entry returns it to Draft and records the Admin actor in both
  `updated_by` and the append-only audit log. Running entries and entries marked
  Invoiced or Paid cannot be edited or approved/unapproved.
- Admin deletion permanently removes the selected time entry after recording
  an append-only audit event. Deleting a running timer is permitted only through
  this Admin action and serializes with timer creation by locking the performer
  first. The UI confirms the irreversible action.
- `Invoiced` and `Paid` remain local labels. This review screen does not create,
  update, or delete QuickBooks records, and it does not execute payroll.

## Consequences

- The four approval enum values remain stored. Only Draft/Approved transitions
  are managed by this screen; Invoiced/Paid do not imply external accounting
  state.
- Admin review is paginated and uses server-side filters; it does not load all
  time entries or expose unrestricted records to Basic Users.
- Deletion is auditable but not recoverable. An active timer deleted by an
  Admin will no longer appear in the technician's next active-timer refresh.

Payroll-hour reporting is defined separately in ADR-0029.

Customer billing preparation and the local Approved-to-Invoiced transition are
defined separately in ADR-0030.
