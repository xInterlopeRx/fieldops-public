# ADR-0030: Admin Time-Entry Billing Preparation

- Status: Accepted
- Date: 2026-10-03

## Context

Office staff need to organize billable time by Customer, Project, and Job and
locally mark approved hours as invoiced while preparing customer billing. This
is a FieldOps reporting/status workflow only; QuickBooks invoice creation and
all other accounting integrations remain out of scope.

## Decisions

- Add an Admin > Time Tracking > Billing screen with a selectable date range,
  defaulting to the current calendar month in the application timezone and
  bounded to 366 days. Filter entries by `started_at` date. Group hierarchically
  by Customer, Project, and Job; entries without a Project or Job appear in
  explicit no-project/project-level groups.
- Display completed entries with approval status Approved, Invoiced, or Paid.
  Draft and running entries are excluded from this billing report.
- Total Billable Hours is the sum of stored duration for entries marked
  `billable` among the displayed approval statuses. Total Unbilled Hours is the
  subset that is Billable and Approved. Non-billable entries remain visible for
  context but contribute to neither summary.
- An Admin may mark an entry Invoiced only when it is completed, Billable, and
  Approved. Bulk Mark Invoiced accepts up to 100 IDs and is atomic; if any
  selected entry is no longer eligible, the entire operation is rejected.
  Each successful status change records the acting Admin in `updated_by` and
  the append-only audit log.
- `Invoiced` is a local `TimeEntryApprovalStatus` value. This action does not
  create, update, or look up a QuickBooks invoice and does not call an external
  billing service. `Paid` remains a local label; this screen does not set it.
- The displayed Billable Status distinguishes non-billable entries from
  Billable Unbilled, Invoiced, and Paid entries. Users without `admin.access`
  cannot view the report or invoke its mutations.

## Consequences

- Total Billable Hours may include already Invoiced or Paid records, while Total
  Unbilled Hours deliberately counts only the Billable Approved subset.
- Billing reports use the explicit stored `duration_minutes` value and do not
  calculate rates, taxes, invoice amounts, payments, or payroll wages.
- Re-marking Invoiced or marking a non-billable, Draft, or running entry is
  denied; bulk operations preserve all-or-nothing behavior.
