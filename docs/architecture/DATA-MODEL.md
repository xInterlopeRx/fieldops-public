# Data Model Baseline

## Database style

Use a normalized relational PostgreSQL model. Use JSONB only for provider payload fragments, flexible metadata, or attributes without stable relational meaning. Do not use JSONB to avoid modeling known relationships.

## Initial entities

- organizations
- users
- Spatie roles and permissions on users; `admin` / `basic_user` are account roles and `management` / `billing` are notification roles (teams disabled)
- customers
- customer_addresses (billing/shipping address book; may reference a site)
- customer_email_addresses (unassociated customer email addresses)
- customer_attachments (private Customer attachment metadata)
- project_attachments (Project attachment metadata)
- work_order_attachments (Job attachment metadata; may also be linked to a Visit)
- sharepoint_attachments (standalone SharePoint DriveItem metadata and company/Site/Project/Job context; separate from local attachment tables)
- contacts
- contact_methods (multiple labeled email, phone, and Teams values per Contact)
- sites
- jobs
- work_order_status_history (append-only Job status transitions with optional
  Pending reason, actor, and timestamps)
- mail_transport_configurations (singleton SMTP host/port/security/from settings,
  optional encrypted username/password, verification timestamp, and configuring
  actor; SMTP credentials are encrypted with externally managed `APP_KEY` material)
- projects
- visits (Visit Graph event IDs are paired with nullable tenant, mailbox, and
  Calendar target snapshots. New events record the resolved target; legacy rows
  with an ID and no snapshot require M365-admin ownership confirmation or manual
  reconciliation before Calendar operations can use them.)
- schedule_entries
- timeline_events
- notes
- communications
- documents
- document_links
- `items` (read-only QuickBooks Item catalog scoped by realm: QBO ID, Name, safe Description, Type, Active, and sync time; no financial/account/inventory fields)
- `job_items` (Visit-level usage rows with Item FK/QBO ID/name/description snapshot, quantity, actor, and timestamps; type comes from the related local Item catalog row; no price/cost or stock fields; one row per Item per Visit)
- `quickbooks_item_catalog_syncs` (one environment/realm-bound resumable Item catalog read cursor with bounded progress, counts, actor, status, and sanitized failure code)
- `quickbooks_item_catalog_syncs` (one environment/realm-bound resumable Item catalog read cursor with bounded progress, counts, actor, status, and sanitized failure code)
- integration_connections
- bitwarden_secret_manager_connections (one row per environment, with organization/project IDs and API URLs, an encrypted BWS runtime read-only token, selected secret verification profile, configuring actor/time, and sanitized verification state/error)
- `bws_secret_write_attempts` and `bws_secret_write_attempt_items` are retained legacy audit tables from an earlier Can Write design. FieldOps no longer creates or reconciles write attempts; existing rows are preserved and no schema/data cleanup is performed.
- installation_claims (singleton initial-Admin claim timestamp and actor; existing installations are marked claimed during migration)
- application_setup_states (singleton fresh-install readiness gate, QuickBooks opt-in/paused state and required post-opt-in BWS recheck, API-vs-CSV Customer/Site source mode, and recorded completion receipt; existing installations are grandfathered)
- application_setup_steps (bounded per-step status, attempt time/count, sanitized failure code, progress counters, actor, and completion time for first Admin, BWS, QuickBooks OAuth/import, Graph validation, and failure reconciliation)
- microsoft_graph_onboarding_targets (one manually supplied tenant/client/mailbox/calendar and Work Schedules/Business Attachments target set per environment; explicit target shared-mailbox and optional non-target user-mailbox confirmations; boundary/preflight results; and nullable runtime activation time/actor. Type confirmations are external assertions, not Exchange recipient-type queries. Onboarding-required runtime consumers use the saved target only after an explicit readiness/evidence-gated activation; legacy installs continue to use deployment configuration. Activation blocks on mailbox-ID mismatches and unresolved/in-flight Graph work.)
- graph_onboarding_challenges (one-time, expiring Graph email-linkback attempts scoped to a saved target, environment, and first Admin; stores only a SHA-256 token hash and recipient-address hash, challenge status, timestamps, and sanitized failure code)
- microsoft_graph_onboarding_canaries (durable Calendar/Contacts canary attempts with target tenant/app/mailbox/calendar snapshot, unique operation ID, remote resource ID/ETag, create/cleanup/manual-reconciliation statuses, actor/timestamps, and sanitized failure code; tokens are not persisted; target deletion is restricted and actor deletion preserves the canary row)
- microsoft_graph_onboarding_permission_verifications (append-only M365-admin attestation snapshots for exact tenant/app/shared-mailbox target, exact non-target user-mailbox UPN, mailbox-type confirmations, and Business Attachments Site/Drive; records target/non-target mail and calendar scope results, Contacts/SharePoint boundary review, retention/unscoped-grant review, admin UPN, non-secret reference, actor, and timestamp; raw outputs and tokens are not stored)
- microsoft_graph_sharepoint_onboarding_canaries (durable Business Attachments empty-folder canary attempts with target Site/Drive snapshot, operation ID, remote DriveItem ID/eTag, create/conditional-cleanup/manual-reconciliation states, actor/timestamps, and sanitized failure code)
- trusted_devices (per-user hashed, revocable 14-day TOTP-satisfied device tokens; no plaintext bearer values)
- external_identities
- sync_runs
- `quickbooks_initial_customer_imports` (one environment/source-bound API or CSV Customer/Site phase cursor, allowing a later QBO API import after completed CSV onboarding; each cursor stores optional realm, bounded progress, counts, actor, status, optional encrypted normalized CSV payload, and sanitized failure code)
- `quickbooks_initial_customer_import_failures` (per-run QBO Customer ID or CSV source ID/row and sanitized failure code; no raw provider response or exception messages)
- audit_events
- notifications (Laravel database-channel notifications for staff)
- time_entries
- customer_user and project_user assignment pivots; work_order_user and contact_work_order preserve job assignments and additional job contacts

## Modeling rules

- Use internal primary keys and separate provider identifiers.
- Add organization_id even in single-tenant Release 1 where practical, but do not build tenant provisioning.
- Preserve historical events when external records become inactive.
- Keep customer billing and shipping addresses separate from operational sites. A customer address may be manually entered, imported from QuickBooks, or copied from a linked site; retain its source, optional source-site reference, and default purpose. Preserve a linked QBO child Job's `BillAddr` and `ShipAddr` as separate site-linked address rows rather than flattening or conflating the two.
- Contacts belong to a Customer and may optionally be scoped to one of that
  Customer's Sites (`site_id` is null for Customer-wide Contacts). The workspace
  validates that a selected Site belongs to the same Customer. `contact_type`
  carries the Contact role, including primary/additional and operational roles
  such as Gate Contact, Supervisor, Operations Contact, and Safety Contact.
- A Contact may have multiple related `contact_methods`. Each method has one
  constrained type (`email`, `phone`, or `teams`), a constrained label
  (`office`, `mobile`, `dispatch`, `after_hours`, `operations`, or `personal`),
  a value, and an `is_primary` flag. A partial unique index permits at most one
  primary method per Contact and type; repeated non-primary values are allowed.
  The Contact foreign key restricts physical deletion; archiving a Contact keeps
  its methods available for restoration with the parent.
  `is_primary` remains a FieldOps-owned preference; the Outlook projection uses
  Email primary ordering, while Job snapshots and client/Visit email selection
  still use the legacy `Contact.email` or Job snapshot fields.
  Existing Contact email/phone columns remain for current QuickBooks and
  workspace compatibility. Existing values are not backfilled into
  `contact_methods`; new methods are not synchronized with QuickBooks. The
  accepted one-way Outlook Contact projection uses the legacy fields plus
  normalized methods according to
  [ADR-0037](decisions/ADR-0037-one-way-outlook-contact-projection.md).
  `outlook_contact_id` is paired with its owning
  `outlook_contact_mailbox_id` (the designated shared-mailbox identity under
  ADR-0038); both are hidden from normal Contact
  serialization. The Contact also records `outlook_sync_status`,
  `outlook_sync_requested_at`, `outlook_last_synced_at`, and a sanitized
  `outlook_sync_error` code. These diagnostic attributes are hidden from normal
  serialization and exposed only through the authorized Admin sync-log view.
  Teams delivery is not included.
- Customer-level unassociated email addresses are relational rows, not person Contacts or JSON metadata.
- Attachments remain typed by owner: Customer, Project, or Job; a Job attachment may additionally reference a Visit. These relations preserve owner-specific authorization and archive behavior. Do not collapse them into a polymorphic owner without an explicit schema and authorization decision.
- Attachment rows hold metadata and a Laravel disk/path pointer; bytes are outside PostgreSQL. At the A1 audit, current upload paths explicitly select the private `local` disk. `documents` and `document_links` are not present in the connected schema or current model tree as generic attachment storage.
- `sharepoint_attachments` stores SharePoint DriveItem metadata (filename, MIME type, byte size, Drive/item IDs, normal SharePoint `webUrl`, optional uploader, nullable unique `client_upload_id` for idempotent browser-queued Job uploads, and owner context). The upload key is never serialized to attachment resources. `company_id` references `customers.id`; `site_id`, `project_id`, and `job_id` are optional. The unique Drive/item pair prevents duplicate metadata for one remote item. New upload flows populate these rows, but they remain separate from legacy attachment records; no automatic migration/copy association or binary is stored in PostgreSQL. The current provider is configured for one Site/Drive; future named storage targets and Site/Drive mapping are proposed in ADR-0036 and are not implemented.
- `audit_events` are append-only actor/subject records; ordinary model-change metadata stores changed field names and scoped relation identifiers, not prior/new PII values. Explicit Microsoft onboarding attestations/manual provider-ID reconciliations may retain the M365-admin UPN, short non-secret reference, and exact mailbox-scoped provider IDs as required evidence; never store tokens, raw command output, or credentials.
- Attachment lifecycle events use append-only `audit_events`; Laravel's
  `notifications` table stores in-app notices for active assigned staff, while
  notification email is queued after the owning database transaction commits.
  Notification payloads contain a safe event, owner label, and actor name, not
  file names, SharePoint URLs, or provider identifiers.
- Map a top-level QuickBooks Customer to a FieldOps Customer and a direct QBO child Customer marked `Job=true` to a Site under its parent. Retain provider IDs and SyncTokens separately from local keys. Do not map QBO `IsProject` to a FieldOps Project or turn QBO children into work orders.
- CSV-only imported Customers and Sites carry `import_source=csv_upload` and the CSV Customer ID in `source_customer_id`; they leave QuickBooks IDs/SyncTokens null. Never infer a verified QBO link from a CSV ID or display-name match. QuickBooks write services reject CSV-sourced records.
- Item catalog sync maps only provider ID, name, Description, type, and active state after filtering the full QBO response in memory. Category Items are not selectable; active Inventory, NonInventory, Service, and Group Items may be referenced by Visit usage rows. Usage snapshots retain the QBO ID, name, Description, and operational quantity, never transaction-level price/cost/account/stock data.
- QuickBooks Items are synchronized read-only into the current realm's local catalog. QBO Item queries return complete entities; the gateway allowlists only ID, Name, Description, Type, and Active before returning data to application code. Persist neither UnitPrice, PurchaseCost, accounting references, nor QtyOnHand. Item usage belongs to a Visit; preserve its QBO ID and name/description/type snapshot plus a positive decimal quantity, and do not decrement inventory.
- Retain QuickBooks customer-type references as provider ID/name pairs and supported custom-field values as provider-specific JSON. Do not model UI-only QuickBooks fields as synchronized data when the Accounting API does not expose them.
- Store the sync status, last request time, last successful refresh time, and a sanitized error code on the FieldOps Customer; never serialize provider tokens or sync internals to the customer-facing response.
- Use foreign keys and explicit delete behavior.
- Keep recoverable business archives in PostgreSQL. Archiveable Customers, Sites, Projects, WorkOrders, Visits, Contacts, CustomerAddresses, and attachment metadata carry archive state and reference an archive batch; active Eloquent queries omit archived rows by default.
- An Admin archive operation creates one batch for the selected root and active owned descendants. Restoring a batch restores only rows assigned to that batch; descendants archived earlier remain archived. A Site archive leaves a Project active while another active Site remains associated through the project-site relationship or an active Job.
- Archive attachment metadata without deleting object-storage bytes. Restrict physical parent deletes in the database and block Eloquent model deletes for archive-managed records; the guarded local QuickBooks dataset replacement is an explicit reset exception.
- Use UTC timestamps and render in the user's timezone.
- Make list endpoints paginated and deterministic.
- Store attachment bytes outside PostgreSQL.
- A Job has required creation-time Scope; each Visit stores an editable Scope snapshot inherited from the Job when created.
- A Visit may retain its Microsoft Graph event identifier, stable create transaction ID, synchronization/RSVP state, and Visit-linked completion-report attachment. A Job records the completion time, actor, and report-generation time for idempotent recovery. Keep Graph IDs separate from FieldOps, QBO, and Microsoft user identities.
- `time_entries` records the staff member (`user_id`), Customer, optional Project/Job/Visit context, activity type, start/end timestamps, duration in minutes, billable flag, notes, approval status, and creating/updating actors. `job_id` is nullable for Project-only entries; `project_id` is nullable for Jobs not associated with a Project. A running timer is Draft with null end/duration and a partial unique index permits only one such row per user. Store completed duration explicitly rather than deriving it, retain UTC timestamps, and validate related-record scope in the write service because FKs do not ensure cross-record consistency.
- Graph email outbox rows use a unique event key and record recipient, purpose, retry/acceptance state, and sanitized failure code. Do not store Graph credentials or access tokens in jobs/outbox rows.
- `bitwarden_secret_manager_connections` enforces one connection per environment through a unique `environment` key and records `organization_id`, `project_id`, `api_url`, `identity_url`, `configured_by_user_id`, `configured_at`, `verification_status`, `last_verified_at`, and a sanitized `last_error_code`. Its required `runtime_access_token` is reversible and therefore encrypted with Laravel's authenticated `encrypted` cast in a `TEXT` column, hidden from serialization, and not mass assignable; never hash it or derive the encryption key from a staff password. Keep `APP_KEY` in deployment-managed secret configuration with protected backups and a tested re-encryption rotation plan. FieldOps does not persist or accept BWS write tokens.
- Onboarding state records completed checks/import steps and sanitized status/error codes, not raw provider responses, credentials, or arbitrary JSON payloads. The Can Read helper validates a fixed application-secret name allowlist and non-empty values without storing or returning them. Integration targets are structured fields; Microsoft mailbox identity, calendar ID, selected SharePoint Site/Drive, and BWS environment project are stored separately from secret values.
- Trusted-device records store only a cryptographic hash of a random high-entropy token, user relationship, created/last-used/expiry/revocation timestamps, and bounded device metadata. A secure cookie carries the plaintext token for at most 14 days; revocation or expiry forces a fresh TOTP challenge.
- Add indexes based on actual query paths: customer/site/job relationships, schedule ranges, timeline ordering, external IDs, and global search fields.
