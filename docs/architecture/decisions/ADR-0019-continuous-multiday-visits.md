# ADR-0019: Continuous Visits for Multi-day Work

- Status: Accepted
- Date: 2026-09-26

## Context

Some scheduled work begins on one calendar day and ends on a later day, potentially spanning several days. FieldOps already models scheduled work as visits associated with jobs. Splitting one uninterrupted multi-day assignment into artificial daily revisits would fragment the schedule and make future time tracking harder to reconcile.

## Decision

Represent uninterrupted multi-day work as one continuous visit with an explicit start and end. Do not create separate visits solely to cross a midnight or calendar-day boundary. The time-entry model and backend timer engine in ADR-0026 can associate work with a Visit through nullable `visit_id`; unscheduled Job time and Project-only time remain possible.

## Consequences

- Calendar month cells derive overnight continuation segments from `duration_minutes`; drag/drop preserves that duration and previews the resulting end time.
- Conflict detection must compare overlapping intervals, including across dates.
- Full multi-day support remains incomplete: the current revisit form caps duration at 24 hours, there is no explicit end field, and calendar queries do not yet fetch visits that start before the displayed date range. A migration plus form/API/query updates are required.
- Time-entry capture, Job-status automation, Admin review/approval, and
  payroll-hour reporting are defined by ADR-0026 through ADR-0029. Payroll
  execution, invoicing, and payment workflows remain deferred and do not change
  the continuous-Visit scheduling constraints above.
