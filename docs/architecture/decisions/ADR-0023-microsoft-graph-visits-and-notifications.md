# ADR-0023: Microsoft Graph Visit Events and Operational Notifications

- Status: Accepted
- Date: 2026-10-01
- Supersedes: the no-calendar-write and no-email-send boundary in ADR-0022, only for the use cases below
- Superseded in part by ADR-0038: use one designated, service-owned shared mailbox for FieldOps mail and Visit calendar operations instead of a fixed deployment mailbox or an arbitrary staff-selected mailbox. Exchange Application RBAC remains scoped to that exact mailbox and requires target/non-target verification.

> Later tenant discovery identified Work Schedules as a Microsoft 365 Group.
> The current deployment's separate notification mailbox was selected as a
> workaround for Visit event projection. ADR-0038 now sets the intended stable
> target as a dedicated FieldOps shared mailbox for mail, calendar, and optional
> Contacts. Human group/folder access and app-only Exchange Application RBAC are
> administered separately by Microsoft 365 administrators; FieldOps does not
> manage those permissions. The Group Calendar is not the schedule-event target;
> its SharePoint site remains the repository/Copilot resource. The legacy
> Admin-only Graph read gateway from ADR-0022 was retired.

## Context

FieldOps needs one Microsoft Graph calendar event for each Visit, RSVP visibility,
client and management status messages, a completion report with supporting files,
and a Billing ready-to-bill notice. ADR-0022 already established a Laravel-native
app-only gateway for one configured tenant/mailbox and read-only calendar,
Outlook-contact, and message access. FieldOps has no Graph write permission,
calendar mapping, external-mail outbox, or Visit-level attachment association.

## Decisions

- Keep app-only client credentials for notification mail and the shared-mailbox
  calendar in one configured tenant/mailbox. No Admin-only Graph mailbox
  read gateway remains. Request operations never accept a tenant or mailbox
  identity.
- Do not automatically backfill calendar events for Visits that existed before
  this feature. A staff edit/reschedule can explicitly mark an existing Visit
  for event synchronization; new Visits enter the pending queue.
- Create one calendar event per Visit, including the initial Visit and revisits.
  Invite active users assigned to the Visit or Job and the selected Job client
  contact when an email address is available. Do not include internal technical
  notes in a calendar event shared with the client.
- Persist the Graph event ID, a stable `transactionId` before event creation,
  synchronization status, and RSVP response state on the Visit. Reschedule or
  scope edits update that event. Use scheduled RSVP polling every 15 minutes;
  a decline alerts Management immediately and any required attendee who has not
  accepted after 24 hours also alerts Management. Neither condition automatically
  cancels the event or blocks the Job.
- Require a human-entered, nontechnical client completion summary when a Job is
  marked complete. Immediately queue that summary to the client and Management;
  include client-facing Field Notes in status/completion email, but never include
  internal Job Notes. Technicians do not receive status-update email. Queue the
  full PDF and all FieldOps supporting attachments for the client within the
  24-hour target. The completion PDF includes every Visit's notes under a date
  heading in chronological order, plus Job Field Notes; it excludes internal Job
  Notes and assigned-staff details. Store the generated PDF as a Visit-linked
  attachment on the initial Visit. Billing receives a separate Billing Job
  Report with QuickBooks Item identities, descriptions, and Visit quantities; the client-facing
  completion PDF does not include Item usage. No prices, costs, account references,
  or stock quantities are included. No internal self-copy, CC, or BCC is sent.
- Use `spatie/laravel-permission` for roles and permissions. Keep the package's
  teams feature disabled. Admin and Basic User preserve the same initial business
  permissions; Management and Billing are additive notification-recipient roles
  and do not independently grant application access. Every invited account gets
  exactly one of Admin or Basic User, with optional Management/Billing roles.
  Admin has `admin.access`, `users.manage`, `integrations.manage`, and
  `work_orders.view/manage`; Basic User has `work_orders.view/manage`;
  Management has `notifications.management`; Billing has
  `notifications.billing`.
- Queue Graph operations and record notification intents in a database outbox
  with unique event keys. A Graph `202 Accepted` means accepted by Graph, not
  delivered. Treat an ambiguous send timeout as `unknown` and do not blindly
  retry it. Send to each recipient separately with no self-copy or carbon-copy
  fields.
- Use direct `sendMail` file attachments only, with a conservative default
  estimated request budget of 2.5 MiB. If the complete client attachment set
  exceeds the budget or a source file is unavailable, send none of the package,
  hold delivery, and alert Management. Do not silently omit files. A future
  SharePoint specific-people link/copy flow needs verified site grants,
  external-sharing policy, and client authentication before it can replace this
  hold behavior.
- Do not include QBO Estimates or Invoices in the report until a stable,
  explicit mapping to a FieldOps Job is approved. QuickBooks, FieldOps, and
  Microsoft identities remain separate.
- Keep Graph writes disabled by default. Live use requires Exchange Application
  RBAC role assignments scoped to the configured mailbox and a tested Graph
  denial for non-target mailboxes. Do not also grant equivalent unscoped Entra
  Graph Application permissions; a fixed mailbox ID in app configuration is
  not the security boundary.

## Current scoped Exchange Application RBAC roles

- `Application Calendars.ReadWrite` for the notification mailbox's user calendar
  event create/update and attendee RSVP reads.
- `Application Mail.Send` for transactional FieldOps notifications and direct
  small-file attachments. The implementation does not create drafts or use
  attachment upload sessions, so it does not request `Application Mail.ReadWrite`.

Use Exchange Application RBAC role assignments with a resource scope restricted
to the notification mailbox for calendar/mail write roles. These Exchange role
assignments correspond to the
same Microsoft Graph application permissions, but are resource-scoped. Do **not**
also grant the equivalent unscoped Microsoft Graph Application permissions in
Entra ID: Exchange RBAC and Entra grants are independent and additive, so an
unscoped Entra permission defeats the mailbox restriction. Test both allowed
access to the configured mailbox and denied access to another mailbox through
Graph. SharePoint permissions are not part of this decision.

The intended notification mailbox calendar is the dedicated FieldOps shared
mailbox calendar; the Work Schedules Group Calendar is not the event target.
Microsoft 365 administrators separately grant human access through approved
groups/folder permissions. FieldOps does not manage those Microsoft permissions.

## Consequences

- The queue worker and Laravel scheduler are required for event synchronization,
  RSVP polling, completion report generation, and delivery recovery. These are
  asynchronous operations and do not promise delivery when Graph writes are
  disabled or tenant policy rejects a recipient.
- The current attachment budget can hold large Job bundles; no partial email is
  sent. Management must verify a later secure SharePoint delivery path for those
  cases.
- RSVP warnings do not change FieldOps Job or Visit status automatically.
- The application continues to retain FieldOps originals. No QBO transaction
  files or SharePoint copies are created by this slice.
- Legacy Visits are marked `not_requested` for Graph synchronization, preventing
  enabling the integration from sending invitations for an existing schedule
  without an explicit edit/backfill decision.
