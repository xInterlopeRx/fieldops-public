# ADR-0037: One-Way Outlook Contact Projection

- Status: Accepted; production enablement is blocked on Microsoft 365 permission and mailbox-scope verification
- Date: 2026-10-07
- Related: ADR-0023 (Microsoft Graph Visit events and operational notifications)
- Superseded in part by ADR-0038: project to the designated FieldOps shared mailbox, not an arbitrary or staff-owned mailbox, and do not begin Contact projection until the QBO initial import completes. Direction, mapping, provider identity, archive, and no-reverse-sync rules remain unchanged.

## Context

FieldOps stores Customer-wide and Site-scoped Contacts, legacy single-value
QuickBooks-backed contact fields, and local multi-value `contact_methods`.
QuickBooks owns its existing contract fields; contact-method rows are local to
FieldOps. Outlook is not a source of FieldOps Contact data, and the application
must not import, merge, or reverse-synchronize Outlook Contacts.

The approved model is a one-way FieldOps-to-Outlook Contact projection into the
default Contacts folder of the designated FieldOps shared mailbox (or a
deliberately approved custom folder). Outlook item IDs are mailbox-scoped.
Existing mail and calendar permissions do not authorize
Contacts operations, and the required Contacts application permission and its
mailbox-scoped authorization mechanism have not been verified in the tenant.

## Decision

- Treat FieldOps as the source of truth for all Contact values sent to Outlook.
  Do not list Outlook Contacts, import their data, merge by display name/email,
  consume Outlook change notifications, or write Outlook edits back to FieldOps
  or QuickBooks.
- Use the designated shared mailbox's default Contacts folder unless a custom
  folder is deliberately approved. Store the Outlook item ID in
  `contacts.outlook_contact_id` and the owning mailbox identity in
  `contacts.outlook_contact_mailbox_id`. Keep both provider fields out of normal
  Contact serialization. Refuse active sync when the stored mailbox differs
  from the configured target. Do not change the target without explicitly
  reconciling the old mailbox's copies and pointers.
- Map names from FieldOps Contact name fields. Project Email methods with the
  primary method first; if none exists, use legacy `Contact.email` first, then
  method IDs in ascending order. Retain a distinct legacy address, deduplicate
  case-insensitively, and fail rather than truncate when the supported Outlook
  email slots are exceeded. FieldOps email labels are not represented remotely.
- Map legacy `mobile_phone` and Mobile-labeled Phone methods to Outlook's single
  `mobilePhone` field. Fail rather than choose among multiple distinct mobile
  values. Map legacy `phone`/`alternate_phone` and Office, Dispatch, After Hours,
  and Operations Phone methods to `businessPhones`, with deterministic ordering
  and deduplication. Fail rather than truncate beyond Outlook's two supported
  `businessPhones` values. Personal Phone and all Teams methods remain
  FieldOps-only. Outlook does not preserve the per-method FieldOps phone labels.
- Queue create/update work after relevant Contact and method changes commit. A
  bounded manual command queues active Contacts and previously synchronized
  archived Contacts. Use the stored provider ID for updates/deletes; do not
  retry an ambiguous create outcome because the Graph create operation has no
  application idempotency key. Retry idempotent updates/deletes and rate-limited
  work using the existing queue and Graph client behavior.
- FieldOps archive deletes the linked Outlook Contact and then clears both
  stored provider fields. A missing remote item is treated as already deleted.
  Restoring the FieldOps archive creates a new Outlook Contact from current
  FieldOps data. No Microsoft-side deletion occurs while synchronization is
  disabled.
- Persist per-Contact sync status, request time, last successful sync time, and a
  sanitized failure code. Display the Contact, mailbox-scoped Outlook ID, status,
  and last successful sync in the existing Admin Sync Logs page, paginated and
  searchable, behind `integrations.manage`.
- **Resync** queues the normal FieldOps upsert (or archived remote cleanup) and
  preserves a valid Outlook link. An `unknown` create with no stored ID cannot be
  retried automatically. The Admin Repair action may queue a new create only
  after an explicit confirmation that the configured Outlook folder was manually
  checked and any possible duplicate was reconciled.
- **Repair** is an explicit Admin-confirmed replacement: only for an active
  Contact whose stored Outlook ID is owned by the currently configured mailbox,
  delete that item (treat 404 as already absent), clear its item ID while
  retaining mailbox ownership during the retryable replacement, and create a
  fresh Outlook Contact from FieldOps. Do not use linked-item replacement for
  archived Contacts or untracked/mismatched mailbox IDs. Unknown creates with no
  ID use only the explicit manual-reconciliation confirmation above.
- Keep `MICROSOFT_GRAPH_CONTACTS_SYNC_ENABLED=false` by default. Microsoft 365
  administrators must confirm the least-privilege Contacts write permission and
  a supported authorization boundary scoped to the designated shared mailbox,
  then verify an allowed request to that mailbox and denied access to a non-target
  mailbox. A fixed mailbox path in application configuration is not a security
  boundary. Do not add an unscoped tenant-wide Graph grant to enable this feature.

## Consequences

- FieldOps changes flow to Outlook only after the feature flag is deliberately
  enabled. No live Graph Contact writes or permission changes are part of this
  decision's implementation.
- QuickBooks mappings, Job Contact snapshots, client/Visit email selection,
  Customer/Site ownership, Contact roles, and Teams delivery remain unchanged.
- Changing the designated shared mailbox requires an explicit
  migration/reconciliation;
  IDs must not be reused against a different mailbox.
- An ambiguous create is visible as `unknown`, distinct from a known `failed`
  write. Admins cannot use Resync/Repair to blindly create another item while its
  remote outcome is unknown.
- Email and phone method labels are not losslessly represented in Outlook. The
  local Contact remains authoritative for those labels and for unsupported
  values.
- Outlook clients do not universally expose shared-mailbox Contacts. FieldOps
  projection/readiness guarantees only the app's configured one-way projection;
  they do not guarantee that every staff member can browse those Contacts in new
  Outlook or Outlook on the web. Human mailbox and folder access is separately
  administered through Microsoft 365 groups and client-supported permissions.
