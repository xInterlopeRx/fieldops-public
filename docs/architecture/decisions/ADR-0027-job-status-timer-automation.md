# ADR-0027: Configurable Job-Status Timer Automation

- Status: Accepted (updated 2026-10-11)
- Date: 2026-10-03

## Context

Technicians need the time-entry timer to follow common Job workflow transitions
without adding a separate time-tracking section. Job statuses already have
several consumers, so timer behavior must use a shared status registry instead
of introducing another status switch in application code. Status edits are
available through the Schedule Job workflow and the Admin Job workspace.

## Decisions

- `config/work_orders.php` is the canonical application configuration for Job
  status identifiers, labels, UI metadata, status-dependent validation, and
  timer automation rules. The status registry is shared through Inertia and is
  used by backend request validation and the Calendar/Admin status controls.
- Timer rules are keyed by a configured target status and expressed as data:
  activity types to stop, an activity type to start, and whether to stop the
  actor's timer for this Job. `WorkOrderTimerAutomationService` evaluates those
  rules generically; it does not contain status-name branches. Change
  configuration and rebuild/cache Laravel configuration as part of deployment;
  no runtime Admin settings UI is introduced.
- Jobs and Projects have Created and Scheduled statuses. Created Jobs do not
  require a Visit date; entering Scheduled requires a date and creates the
  initial Visit when one does not exist. Projects use these statuses without
  timer automation.
- On a transition to On The Way, automatically start a Travel segment. On a
  transition to In Progress, automatically start a Labor segment. Any active
  segment for this Job and actor is stopped first so each status transition
  starts a fresh segment; no browser confirmation is requested.
- On a transition to Created, Scheduled, Pending, Cancelled, or the configured
  completion status, stop the actor's active timer only when it belongs to this
  Job. Do not stop a timer for a different Job or another user. Pending requires
  a reason and may be selected only by a staff member assigned to the Job or one
  of its Visits; the transition, actor, timestamp, and reason are retained in
  append-only Job status history.
- Timer automation is performed only for an assigned actor. If that actor has
  an incompatible timer for another Job, an active-work transition is rejected
  rather than stopping the unrelated timer.
- Apply automation only when the status changes. The timer transition and Job
  update share a database transaction; validation/conflict failures roll back
  both. The authenticated actor's own timer is used, and automatic timer changes
  run only when that actor is assigned to the Job or one of its Visits.
- Apply the same rules through the technician Job edit/status routes and the
  Admin Job workspace. Timer ownership and the one-active-timer-per-user
  database constraint remain authoritative.

## Consequences

- Adding a Job status requires adding it to the shared status registry; its
  timer behavior is optional configuration rather than a service-code change.
- These transition rules do not add payroll-hour calculations, payroll
  execution, billing, or QuickBooks invoice/payment behavior. Admin review and
  approval/edit/delete are separately defined in ADR-0028; payroll-hour
  reporting is defined in ADR-0029. Manual timer controls remain available in
  Schedule Job details.
- A status transition that requires a timer start cannot proceed if the actor
  has an incompatible active timer. The UI reports the conflict without asking
  for confirmation or stopping another Job's timer.
