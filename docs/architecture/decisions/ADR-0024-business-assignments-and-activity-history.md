# ADR-0024: Business Assignments and Activity History

- Status: Accepted
- Date: 2026-10-02

## Context

Administrators need to assign staff to Customers, Projects, and Jobs, and users
need an attributable history of changes to operational records. Assignment
changes must reach staff and administrators without sending internal alerts to
clients. The existing Microsoft Graph notification path is restricted to Visit
and client/Management/Billing workflows and remains disabled pending Microsoft
365 access verification.

## Decisions

- Store Customer and Project assignments in `customer_user` and `project_user`;
  retain Job assignments in `work_order_user`. Record the assigning staff user
  on each newly created assignment. Only active FieldOps users may be assigned.
- Store additional Job contacts in `contact_work_order`. Job role contacts remain
  explicit contact foreign keys on the Job and must belong to the Job's customer.
- Send assignment addition/removal notices through Laravel's queued `mail`
  notification channel after the database transaction commits. Notify the
  affected staff member and active users with `admin.access`; do not address
  customer contacts. Notifications carry record context and a FieldOps link, not
  credentials or provider tokens.
- Store append-only `audit_events` with actor, polymorphic business subject,
  timestamp, event name, human-readable summary, and bounded metadata. Metadata
  records changed field names and relation identifiers, not old/new field values
  or customer contact contents.
- Reject audit-event updates, deletes, and truncation at the database boundary
  as well as through Eloquent; retain actor foreign-key integrity so deleting a
  user cannot silently rewrite or orphan attribution.
- Log authenticated Eloquent creates/edits for Customers, Sites, Projects, Jobs,
  Visits, Contacts, addresses, and attachments through an observer. Record
  assignment and archive/restore actions explicitly because they modify pivot or
  archive state without normal model events. Ignore system synchronization with
  no authenticated app actor.
- Keep assignment management and workspace endpoints behind `admin.access`.
  FieldOps Admin archival remains recoverable under ADR-0021.

## Consequences

- Assignment diffs, audit rows, and queued notices are committed atomically; a
  rollback does not leave a notification for a change that did not persist.
- Activity feeds can show a bounded, deterministic timeline without storing
  unnecessary before/after PII.
- The configured Laravel mail transport and queue worker must be available for
  assignment notices to be delivered. Delivery failures use normal queue
  observability; assignment persistence does not depend on synchronous email
  delivery.
- Microsoft Graph notification/calendar permissions and the client-notification
  outbox are unchanged.
