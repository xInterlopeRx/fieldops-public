# ADR-0038: First-Run Onboarding and Integration Setup

- Status: Accepted; implementation is staged and integration enablement remains gated. Local development commissioning completed 2026-10-12 (full readiness chain passed, runtime target activated, no write flags enabled); other environments remain gated.
- Date: 2026-10-08
- Related: ADR-0020 (staff two-factor authentication), ADR-0023 (Graph mail and Visit calendar), ADR-0025 (QuickBooks authority), ADR-0036 (SharePoint storage structure), ADR-0037 (Outlook Contact projection)

## Context

FieldOps is currently invitation-only and assumes deployment-provided QuickBooks
and Microsoft Graph configuration. A production installation with a clean
database has no supported path to create its first Admin, verify that account's
email, configure integration credentials and targets, validate provider access,
or safely import its initial QuickBooks data. Generic registration or merely
collecting credentials would not establish that the integration permissions and
resource targets work.

The first-use workflow must remain single-instance and internally administered.
It does not introduce tenants, customer logins, broad mailbox synchronization,
or FieldOps-managed Microsoft permissions. Microsoft Graph mail, calendar, and
optional Contacts operations need a stable, service-owned Exchange resource rather
than an individual staff member's mailbox; human access to that resource remains
an independent Microsoft 365 administration concern. Setup may be paused and
resumed after a failed check, but required integrations remain unavailable until
repaired.

## Decisions

### Installation claim and staff identity

- When the users table is empty, expose a first-account bootstrap form. A
  database-backed singleton claim guarded by a transaction/row lock permits only
  one initial account. Existing installations with any staff account are
  considered claimed; they do not reopen public registration.
- The first account receives the existing `admin` role. Do not add a separate
  Super Admin role. Once claimed, staff provisioning returns to Admin-managed
  invitations and public registration remains closed.
- The first account and every invited account must verify its own sign-in email.
  Do not constrain staff addresses to a domain or Microsoft tenant, and do not
  require Entra sign-in. Notification recipient addresses may be any valid,
  user-authorized address accepted by the integration policy.
- Before allowing the one-time first-Admin claim, collect SMTP host, port,
  STARTTLS/implicit-TLS mode, optional paired username/password, and sender
  identity. Plain SMTP without TLS is available only in the local environment.
  Test-send only to the submitted first-Admin email address. A failed
  SMTP test must not create the Admin or consume the installation claim. After a
  successful test, create the Admin and singleton SMTP settings in one database
  transaction, then send the normal signed email-verification link. The Admin
  must click that link before entering the authenticated onboarding flow; a
  post-login-only SMTP setup would create a bootstrap deadlock.
- Encrypt recoverable SMTP username/password attributes in
  `mail_transport_configurations` with Laravel authenticated encryption. The
  deployment-managed `APP_KEY` remains outside PostgreSQL. Never serialize,
  flash, log, or place SMTP credentials in queue payloads. Apply the persisted
  transport to Laravel's default mailer for web requests and queue workers;
  a failed SMTP test returns a sanitized message and leaves the installation
  claim available for retry. A later verification-send failure leaves the new
  Admin authenticated only to the verification prompt, where the resend action
  can retry. The configured SMTP test email and subsequent verification email are
  separate messages.
- Require confirmed TOTP two-factor authentication for every staff account
  before normal application access. Enrollment requires a confirmed email and
  password confirmation. Recovery codes remain available. A user may explicitly
  trust a device for 14 days; implement this as a revocable, hashed device token
  with secure/HttpOnly/SameSite cookie attributes, not Laravel's multi-year
  remember-me cookie. Password changes, deactivation, MFA reset, and explicit
  sign-out-all revoke trusted-device tokens.
- Use a 24-hour idle timeout for ordinary authenticated staff sessions and a
  browser-session cookie that expires when the browser closes. The protected
  Admin integration-onboarding area has a separate one-hour idle grant; activity
  in that area renews the grant, and expiry requires the current password and a
  fresh TOTP code again without logging the Admin out of FieldOps. The ordinary
  session timeout is sliding inactivity, not a hard 24-hour maximum session age.

### BWS credentials and read verification

- Bitwarden Secrets Manager (BWS) is the only application secret manager. Use a
  dedicated BWS project per deployment environment. Runtime access uses a
  project-scoped, read-only machine-account token.
- FieldOps stores the BWS read token encrypted at rest in a dedicated integration
  record. Use Laravel authenticated encryption; the `APP_KEY` is deployment
  secret material and must be backed up and rotated independently of PostgreSQL.
  Never derive it from the first Admin's password. Secret values and tokens are
  hidden from serialization and never appear in logs, browser responses, session
  state, or queued job payloads.
- FieldOps does not write application credentials to BWS. Provision the two
  fixed Microsoft Graph secret names through the BWS administration workflow
  before connecting the runtime reader. Intuit Client ID/Secret are also
  required only after the Admin opts into QuickBooks synchronization. The Can
  Read verification checks project access and reads only the selected profile's
  values to confirm presence, non-empty content, and supported size. It returns
  a sanitized result and never displays, stores, or logs application values.
- If the project is readable but any required credential is absent or invalid,
  do not save the candidate runtime token. The Admin may provision the required
  names in BWS and retry, or defer BWS setup. Deferral pauses onboarding; it does
  not unlock invitations or BWS-dependent QuickBooks/Graph capabilities. An
  Admin can explicitly resume the step later.
- The expected secret names are application-owned constants for Intuit and
  Microsoft Graph credentials. BWS API and identity URLs come from trusted
  deployment configuration and cannot be selected in a browser request; this
  prevents redirecting the runtime token to an attacker-controlled host. The
  Admin enters the BWS organization/project IDs, but a request cannot choose an
  arbitrary secret name or target. Do not automatically create projects or
  machine accounts. Fail closed if BWS cannot be reached; no `.env`, stale
  cache, or local-storage fallback is allowed.
- Read only the configured environment project. Hermes's current BWS reader
  fetches all secret values available in that project with `bws secret list`;
  therefore each environment project must contain only FieldOps secrets. Keep
  the returned map in process memory only as long as needed, select the
  application allowlist immediately, and never export the full map into Laravel
  global environment/config state.
- Per-company QuickBooks OAuth access/refresh tokens have a rotating lifecycle
  and remain encrypted in FieldOps connection state, not in BWS. BWS may protect
  the separate Laravel encryption key. Test this against Intuit's refresh-token
  rotation/expiration contract before production enablement.
- **SDK implementation:** use the official Bitwarden Rust SDK helper, pinned to
  upstream commit `03a4220327bfdb00b8aec3179c81f9e62f1fcde1`, compiled as a
  static binary. It accepts a bounded JSON request on stdin and returns only
  sanitized status results. The helper and Laravel stdin adapters are
  implemented under `docker/bws-helper/`, `app/Services/BwsSecretHelper.php`,
  and `app/Services/BwsSecretReader.php`. The reader returns only explicitly
  requested allowlisted credentials to its PHP caller; callers must use them
  directly and not log, serialize, cache, or queue them. PHP-FPM does not
  install/enable FFI, values are not sent in argv, and the helper accepts only
  fixed application secret names. The user confirmed that the organization's paid
  Bitwarden server-product license covers internal SDK use. The encrypted
  per-environment connection schema/model and
  `ConfigureBitwardenSecretManagerConnection` are implemented. The service
  verifies access to the selected project and availability of the selected
  import profile's fixed app-secret values before persisting the token. API mode
  QuickBooks opt-out requires only the two Graph names. Opting into QuickBooks
  requires API mode and all four Intuit/Graph names; CSV-only mode continues to
  keep QBO API access/write-back disabled. Values exist only in helper
  and PHP process memory during verification and are not returned to the browser.
  The service leaves an existing connection unchanged when verification fails.
  Connections carrying the former project-only `read_verified` status are not
  runtime-ready until reverified with required-secret checks; no existing token
  is deleted by this policy change.
  The Admin runtime-token page and POST setup endpoint are
  permission-guarded. Saving the runtime reader requires the current password;
  the protected Onboarding-area grant already requires a fresh TOTP and expires
  after one hour of inactivity. Validation failures remove the token and
  password before session input is flashed. The page never returns the token and
  explains that BWS permission scope is provisioned outside FieldOps. The
  verification confirms project read plus availability of required static app
  credentials, but does not grant or change BWS permissions.
  QuickBooks OAuth and Microsoft Graph token acquisition now resolve app
  credentials through the saved BWS runtime connection with no environment
  fallback; per-company QuickBooks OAuth tokens remain in encrypted
  `integration_connections`. Persisted singleton setup state and per-step
   progress now support pause/resume and a readiness receipt with its Admin
   actor. QuickBooks sync defaults to opted out; its BWS Intuit-credential check,
   company OAuth, and Customer/Site/Item import gates become required only after
   an explicit Admin opt-in. BWS must be verified again after opt-in, even if a
   full secret profile was checked previously. The runtime sync remains paused
   until separately enabled after BWS and company-connection checks pass. Opting in after a
   readiness receipt adds QBO requirements for enabling sync without relocking
   invitations already unlocked by that receipt. Existing installations are grandfathered so their established
   invitation workflow is preserved.
   Fresh onboarding states with an existing QuickBooks connection/import or
   QuickBooks-linked records are migration-marked opted in, preserving the prior
   required-import intent; their BWS Intuit profile must be reverified after the
   migration before sync can be enabled. Empty/unlinked onboarding states default
   to opted out. Legacy installations with onboarding disabled are unchanged.
  The singleton `installation_claims` row, empty-database bootstrap form, and
  one-time Admin claim are implemented. Existing installations are migration-
  marked claimed, and the bootstrap transaction locks the singleton row. Both
  the first Admin and invited staff now receive email verification; the business
  route group requires verified email. Claimed installations now require a
  confirmed Fortify TOTP secret before business-route access; Profile security
  settings remain reachable for enrollment. After successful TOTP, the user may
  issue a 14-day trusted-device cookie with Secure, HttpOnly, and SameSite=Strict
  attributes; PostgreSQL stores only its SHA-256 hash. Session proof is tied to
  the current TOTP secret, legacy remember-me is disabled, and users can forget
  trusted devices or sign out all sessions. Password, status, and TOTP credential
  changes revoke trusted-device rows. Global sign-out clears database sessions
  and requires the database session driver. Fresh-install invitations and
   invitation-link acceptance stay locked until a readiness receipt is recorded.
   A BWS setup deferral pauses the checklist only; it remains a blocker and does
   not count as a readiness pass or unlock invitations.
   M365 permission attestation and SharePoint write canary are readiness gates.
   When opted into QuickBooks, the production-safe resumable Customer/Site import
   and read-only Item catalog sync are additional required gates. External
   Microsoft checks were first run live during the local commissioning on
   2026-10-12 (readiness finalized, linkback verified, Calendar/Contacts
   canaries cleaned, SharePoint canary manually reconciled, M365 attestation
   recorded). Customer/Site completion alone does
   not pass opted-in QuickBooks readiness; a fresh installation cannot unlock
   invitations by deferring BWS or Graph setup. QuickBooks does not block the
   readiness receipt while opted out. A
  protected Admin form stores manually entered Microsoft tenant/app/mailbox/
  calendar and SharePoint target identifiers, along with an M365-admin-approved
  non-target mailbox sign-in email/UPN and Site/Drive control IDs, in an
  environment-specific record;
  saving them performs no Graph request and does not switch existing consumers
  from deployment configuration.
  A separately password-confirmed action performs read-only metadata GETs to
  those exact saved targets through the BWS-backed Graph token provider. The
  protected Onboarding-area grant supplies the fresh-TOTP check; task forms do
  not ask for a second TOTP code while that grant remains active.
  Notification-mailbox validation uses the selected or default Calendar resource
  path rather than a standalone `/users/{id}` directory-profile lookup, avoiding
  an additional broad directory-read permission for the preflight. The saved
  Work Schedules Group ID is treated as an admin-supplied identifier and is not
  queried; this avoids requiring broad `Group.Read.All` for an ID not used by the
  Graph runtime path.
  It does not read mailbox or file content, send mail, write canaries, or change
  permissions. It records only partial/read-verified status. A separate
  password-confirmed action now sends one plain-text
  setup message from the saved notification mailbox to the first Admin's verified
  address. Its 30-minute signed URL carries the one-time bearer token in the URL
  fragment, so it is not sent in the HTTP request or server access logs. A GET
  only renders a confirmation page; an explicit recipient POST records delivery
  confirmation. Only token and recipient-address hashes are stored, and target
  changes invalidate outstanding challenges. This does not prove target/non-target
  permission boundaries, create canaries, activate Graph operations, or unlock
  invitations. Network timeouts and ambiguous server failures are recorded as
  delivery unknown and are not retried automatically. Full Graph readiness remains
  incomplete until the remaining permission-boundary checks, canary cleanup, and M365 review
  pass. Tests fake all Graph/BWS requests; the only live Graph/BWS requests so
  far were the approved 2026-10-12 local commissioning runs (linkback,
  canaries, probes).
  A further password-confirmed check probes the explicitly entered
  non-target default Calendar, Site, and Drive with metadata-only GETs selecting
  only `id`; it also requests at most one row from the Contacts-folder collection
  for both the selected and non-target mailboxes using `$top=1` and `$select=id`.
  It never reads Contact items. The v1.0 collection can be empty when no child
  folders exist under the default Contacts folder; any successful selected
  collection response establishes endpoint access but does not identify the
  default folder. The Graph client may retry once after a 401. Only HTTP 403 counts
  as non-target denied; 200 is accessible and fails the boundary check, while 404
  is inconclusive because it may indicate a nonexistent control. Contacts
  boundary status additionally requires a successful selected Contacts-folder
  collection request and non-target denial. The bounded probe does not identify
  the default folder or read Contact items. Results are stored per resource and audited; stale results are
  discarded if targets change during the requests. These probes check read access
  only, not Exchange `Mail.Send`, `Calendars.ReadWrite`, Contacts write, or
  SharePoint write permission. Exchange mail/calendar scope remains an M365-admin
  PowerShell verification. An attestation requires a separate current-password
  confirmation inside the protected Onboarding area and records
  the M365 administrator UPN, a short non-secret ticket/run reference, the exact
  saved tenant/app/mailbox/Business Attachments Site/Drive snapshot, target and
  non-target Mail.Send/Calendars.ReadWrite outcomes, Contacts and SharePoint
  boundary review, SharePoint retention review, unscoped Entra grant review, and
  first-Admin actor/time. This is an auditable first-Admin attestation of the
  M365-admin's external checks, not an independent FieldOps query; raw output,
  credentials, and tokens are not stored. Target changes make the evidence
  inapplicable. The read-only check does not activate Graph operations
  or unlock invitations. Automated tests fake these requests; the local
  commissioning ran the bounded live probes on 2026-10-12.
  A Calendar write canary requires a separate current-password confirmation and
  is gated on the selected-target read preflight plus denied non-target
  Calendar/Site/Drive probes.
  It creates one free/private event with no attendees or business data, persists
  the Graph transaction ID, remote ID, and ETag, then conditionally deletes with
  `If-Match`. Ambiguous creates are recorded as unknown and never retried
  automatically. Cleanup failures and ETag conflicts remain visible and block
  the Graph readiness step; cleanup can be retried conditionally or manually
  reconciled by the Admin after inspecting the saved Calendar. Manual
  reconciliation records the Admin/time without
  claiming that FieldOps deleted the event. These requests are fake-tested in
  automated tests; the local commissioning created and cleaned the live Calendar
  canary on 2026-10-12. A Contacts canary also requires a separate
  current-password confirmation and a successful selected Contacts-folder
  collection request plus denied non-target collection access. That bounded
  folder-collection
  probe can be empty, does not identify the default folder, and does not read
  Contact items. The canary
  write itself targets the selected mailbox's default Contacts folder and creates
  one marker-only Contact in the
  selected mailbox, without email, phone, address, or business data, persists
  the remote ID/ETag, and immediately attempts conditional cleanup. Unknown
  creates are not retried; cleanup can be conditionally retried or manually
  reconciled with the same durable actor/time record. This canary tests selected
  Contacts write access only; M365-admin verification of the Contacts permission
  boundary remains required before enabling projection. Automated tests fake
  these requests; the local commissioning created and cleaned the live Contacts
  canary on 2026-10-12. Canary history is durable: target deletion is
  constrained and actor deletion only nulls its reference. A SharePoint canary
  requires a separate current-password confirmation and creates one uniquely
  marked empty folder in the approved Business Attachments Drive, stores its
  DriveItem ID/eTag and exact target snapshot, then conditionally deletes using
  `If-Match`. Ambiguous creation is not retried; cleanup conflicts/failures
  remain durable and block readiness, with conditional retry or explicit manual
  reconciliation. The canary proves a write to the selected Drive only; the
  recorded M365-admin boundary/retention attestation remains a separate
  prerequisite. Graph and BWS operations are fake-tested in automated tests;
  no live Graph write or M365 verification happened during implementation.
  The local commissioning ran the approved live canaries and recorded the
  M365-admin attestation on 2026-10-12. The first Admin has a separate
  current-password-confirmed Graph readiness finalizer in the protected
  Onboarding area. It rechecks target-bound metadata, read boundaries, linkback,
  M365 attestation, successful Calendar/Contacts/SharePoint canaries, and the
  absence of unresolved cleanup before persisting the Graph step as passed.
  This does not itself unlock invitations: the complete readiness receipt and
  all other setup steps remain required.
  FieldOps has no Can Write token flow or BWS write endpoint. Application
  credentials are provisioned directly in the dedicated BWS project by an
  authorized BWS administrator. The Can Read test reads one of two fixed
  profiles: all four Intuit/Graph names after QuickBooks opt-in, or only the two
  Graph names while opted out. Every name in the selected profile must be present,
  non-empty, and within the helper limit before it saves the runtime token.
  Values are discarded and no secret contents are recorded. Missing or
  unreadable values fail closed without replacing an existing connection.
  Fresh-install Admins may defer BWS setup, but the deferred step remains a
  readiness blocker. Invitations and Graph-dependent capabilities stay
  locked; setup can be explicitly resumed. Previously deployed
  `bws_secret_write_*` tables and models remain historical audit data and are
  no longer written by application code. No schema drop or data deletion is
  required.

### QuickBooks connection and import

- QuickBooks sync opt-in requires Intuit Client ID and Client Secret as named BWS secrets. The
  user still authorizes the company through the existing QuickBooks OAuth
  authorization-code flow; an app key alone is not a company connection. Keep
  callback validation, state, redirect URI, and server-side encrypted token
  behavior. CSV-only mode is a separate source choice and does not use Intuit
  credentials, OAuth, API reads, synchronization, or write-back. After a
  completed CSV import, an Admin may explicitly switch to API mode and opt into
  QuickBooks later. Preserve CSV rows as local-only; the QBO API import creates
  separate provider-ID records and never guesses matches by display name. Show
  the possible-duplicate warning and require explicit confirmation. Block the
  transition while a CSV run is incomplete, when non-CSV Customer/Site records
  exist, or after QBO setup data already exists.
  - Opted-in API setup verifies supported QuickBooks Customer and linked-Site
    reads/writes plus read-only Item catalog reads. The first phase previews
    counts and lets the Admin explicitly start or resume a bounded Customer/Site
    import. It binds progress to the authorized company, upserts by stable QBO
    IDs, records per-Customer sanitized failures, and never clears existing
    FieldOps business data. Each job imports at most 50 direct child Sites per
    Customer; oversized sets and nested/orphaned hierarchies fail explicitly.
    Customer/Site phase completion does not pass the opted-in import gate; a
    separate bounded, resumable read-only Item catalog sync must also complete.
    While opted out, the OAuth and Customer/Site/Item import steps are not
    required in API mode and may be resumed later by opting in.
    FieldOps does not create or update QBO Items. Do not use the existing
    destructive local-only `quickbooks:import-customers --replace-business-data`
    reset command in production onboarding.
- Do not import invoices, payments, taxes, or accounting workflows. The
  first-run Customer export CSV fallback imports Customers, primary/secondary
  Contacts, one Primary Site from a service address, and a separate structured
  billing address. CSV IDs remain source IDs rather than verified `quickbooks_id`
  values; QBO OAuth and all API read/write paths are disabled in CSV-only mode.
  Paid/unpaid values are ignored and assignees are not matched by display name.
  Account type/status, creation date, tags, and lead source remain unmapped and
  are not stored pending an explicit mapping decision.
  Uploads are limited to 5 MB/1,000 data rows and must carry a stable, unique
  Customer ID. The normalized source payload is encrypted for resumability and
  cleared after successful Customer/Site import. With QuickBooks synchronization
  opted out, a completed CSV Customer/Site import does not require an Item catalog
  for invitation readiness; BWS/Graph readiness, failure reconciliation, and the
  readiness receipt still apply. QuickBooks-dependent Item features remain
  unavailable until the installation switches to API mode and imports the
  read-only Item catalog. No CSV-to-QBO write-back is implemented.
  - Release 1 reads the existing QuickBooks Item catalog as a read-only source
     for Visit-level Item usage. Only QuickBooks Item ID, Name, Description, Type,
     and Active are retained. Active Inventory, NonInventory, Service, and Group
     Items are selectable; Category records remain catalog-only. Visit usage
     stores the QBO Item ID/name/description snapshot, quantity, Visit, actor,
     and timestamp; type is read from the related local Item catalog row. The
     accountant-only Billing Job Report includes Item identity,
    description, and usage quantities; the client completion PDF remains
    Item-free. FieldOps does not create/update Items in QuickBooks or
    store/render/report UnitPrice, PurchaseCost, accounting references, or
    QtyOnHand. It does not adjust QBO quantities or perform inventory tracking.
    The QBO Item query response cannot be projected to selected columns; the user
    approved transient in-memory receipt of full Item responses. The gateway
     immediately allowlists the five safe fields and never
    persists, logs, serializes, queues, or renders the raw response. CSV-only
    onboarding cannot import Items without Intuit API access, but an Item source
    is not an invitation-readiness requirement while QuickBooks sync is opted out.
- The seed/demo Customer must be a clearly identified Customer already returned
  by the connected QuickBooks import. Do not silently create test accounting
  records. The user may keep it as a training record or archive it using normal
  FieldOps recovery behavior.

### Microsoft targets and checks

- Use one dedicated Microsoft 365 shared mailbox as the FieldOps-owned resource
  for app-only transactional mail, Visit calendar events, and the optional
  one-way Outlook Contact projection. Do not target a staff member's personal
  mailbox or create per-user Microsoft integrations. An M365 administrator
  provisions and maintains the shared mailbox; FieldOps authenticates as its
  application and does not use interactive mailbox sign-in.
- Identify the shared mailbox with its approved primary SMTP/resolvable UPN and
  its Exchange `ExternalDirectoryObjectId` / Graph `user.id`; verify those object
  IDs match. Do not substitute Exchange `ExchangeGuid`, the App Registration
  Object ID, or the Enterprise Application Object ID. Use the default Calendar
  and Contacts folder unless an administrator deliberately approves a dedicated
  calendar or custom Contacts folder. A folder display name or localized path is
  not a stable Graph identifier.
- The Admin selects one exact, already provisioned Microsoft mailbox identity
  using its known Graph user/object ID (and the corresponding approved address
  where needed). Do not enumerate tenant users, offer a directory-wide people
  picker/list, or require the operator to search FieldOps staff. Microsoft 365
  administrators must have established the target's Exchange Application RBAC
  roles and human permissions through their normal process before FieldOps
  commissioning; FieldOps records/tests the supplied target and does not assign
  those roles. In this context, “user object” is Microsoft's resource identity
  shape, not a FieldOps staff account. The target must be a dedicated FieldOps
  shared mailbox. The real same-tenant non-target control should be a user
  mailbox; verify the shared-mailbox target is allowed and the user-mailbox
  control is denied.
- Microsoft credentials remain named BWS secrets. The Admin records the tenant
  ID, app/client ID, designated shared-mailbox address/object ID, optional
  calendar ID, and approved SharePoint Site/Drive IDs during onboarding. Do not
  enumerate tenant mailboxes or Sites and do not accept a per-request target
  override. Work Schedules and Business Attachments remain separate SharePoint
  targets; Exchange RBAC does not authorize SharePoint.
- Current implementation status: onboarding accepts a manually supplied object
  ID/address (no directory enumeration or user picker) and now requires an Admin
  confirmation that the exact target is the dedicated shared mailbox and, when
  configured, that the non-target control is a user mailbox. Read preflight and
  boundary checks fail closed unless those confirmations are recorded. FieldOps
  cannot independently determine Exchange recipient type through the current
  Graph preflight; the M365-admin verification remains an external attestation.
  Append-only permission evidence snapshots both mailbox types and the exact
  non-target mailbox UPN; changing that UPN makes prior evidence inapplicable.
  Saving these pending values does not switch active consumers. A separate,
  current-password-confirmed first-Admin action now records an audited runtime
  activation only after the overall readiness receipt, Graph readiness, and
  exact-target M365 evidence pass. Runtime services resolve the saved target only
  after that activation for onboarding-required installations; legacy
  installations retain deployment-config targets. Activation enables no write
  flag and makes no Graph request. Each Visit event ID stores the tenant,
  mailbox, and Calendar target snapshot used to create it. Activation blocks on
  event IDs with missing/mismatched snapshots, ambiguous failed creates, Contact
  IDs from another mailbox, or pending/in-flight mail, Visit, or Contact work;
  deployment configuration matching the selected target is not evidence of an
  older event's owner. Calendar sync and RSVP polling also fail closed when an
  existing event lacks a snapshot matching the resolved runtime target. The local
  development target was activated on 2026-10-12; other environments require
  their own commissioning.
- Before or after activation, the first Admin may record one of two per-Visit
  M365-admin attestations for an event whose target snapshot is absent or differs:
  confirm the exact event ID is present in the selected mailbox/Calendar, which
  stores that target snapshot and retains the pointer; or confirm an old event
  was removed or otherwise reconciled, which clears only the local pointer and
  returns the Visit to `not_requested`. For an ambiguous create with no returned
  provider ID, the administrator must inspect the relevant mailbox Calendars and
  reconcile any possible duplicate before clearing the transaction marker. Both
  actions require the first Admin's password/TOTP-protected session, the M365
  administrator UPN, and a short reference; neither makes a Graph request or
  remote deletion. Each action appends an audit event. Contacts are locally
  paused after reconciliation until explicit one-way sync is enabled. Do not
  imply that manual reconciliation means the application deleted remote data.
- Keep app-only Exchange Application RBAC scoped to exactly the designated
  shared mailbox. Verify the intended target is in scope and a real same-tenant
  non-target mailbox is denied, and inspect Entra grants because unscoped Graph
  application roles are additive and can defeat Exchange scoping. Do not broaden
  the Exchange scope to make a test pass. Microsoft 365 administrators own these
  application permissions and all human mailbox/folder permissions.
- Human access is separate from FieldOps app authorization. Prefer centrally
  managed mail-enabled security groups for Full Access, Send As, Calendar Editor,
  and, if required, Contacts Editor access; grant only the approved groups and
  follow the organization's joiner/mover/leaver process. Group-based Full Access
  does not guarantee Outlook automapping; staff may need to add the mailbox
  manually. Do not make FieldOps readiness depend on shared Contacts appearing
  universally in new Outlook or Outlook on the web; supported-client behavior
  has documented limitations.
- Provide an in-product walkthrough with Microsoft portal links and read-only
  PowerShell commands for finding/validating identifiers and diagnosing missing
  roles. FieldOps must not grant Microsoft permissions. A permissions failure
  pauses that capability and reports the exact target, operation, sanitized
  result, and administrator remediation without revealing secret values.
- A separate default-off `MICROSOFT_GRAPH_ONBOARDING_WRITES_ENABLED` deployment
  gate blocks onboarding-target POST/DELETE calls, including linkback email and
  temporary canary creation/cleanup. Read-only preflight remains available while
  this gate is off. Enable it only for an explicitly approved commissioning
  window after exact-target policy and M365 permissions are reviewed; it also
  permits conditional canary cleanup.
- Initial Graph validation is access/authority checking only, plus one
  user-addressed `sendMail` verification message with a signed, expiring
  linkback. No mailbox content is read or synchronized. A mail `202 Accepted`
  alone is not proof; onboarding is complete only after the recipient follows
  the link.
- The Admin explicitly authorizes test canaries for write-capability checks: one
  clearly named temporary Calendar event, Contact, and SharePoint folder/file in
  the manually selected targets. Record canary identity and cleanup result.
  Attempt conditional cleanup after success; if cleanup fails, leave a visible
  reconciliation item and never pretend the target is clean. Do not include real
  business data in a canary. This verifies endpoint authorization and does not
  backfill or synchronize existing mailbox/Contacts/SharePoint content.
- Visit event projection and transactional mail remain app-only Graph operations
  against the designated shared mailbox. Saving mailbox details is not proof of
  authorization: M365 administrators must verify the exact Exchange Application
  RBAC scope, inspect additive Entra grants, and prove allowed target access plus
  denied access to a real non-target mailbox before enabling operations.
- Contacts remain a one-way FieldOps-to-Outlook projection per ADR-0037. Keep
  projection disabled until the initial QuickBooks Customer import completes
  and Contacts access/authority has been verified. Project to the designated
  shared mailbox's default Contacts folder unless a custom folder is deliberately
  approved. Then queue a slow, resumable projection with per-Contact status and
  safe recovery; do not import or merge Outlook Contacts. This FieldOps projection
  is independent of whether every staff member's Outlook client can display the
  shared Contacts folder.
- Teams is not part of Release 1 and does not inherit Exchange mailbox
  authorization. Plan it as a separate v1.1 integration decision: first choose
  the team conversation/workflow and whether FieldOps acts through a Teams app
  with resource-specific consent (RSC), a deliberate delegated identity, or a
  bot. Evaluate the user's proposed supported Copilot chatbot/channel-message
  workflow as a team communication option, but do not assume its permissions,
  message APIs, installation, or subscription lifecycle from the mailbox
  integration. Do not add Teams permissions or messaging behavior under this ADR.
- SharePoint attachments and documentation use separate targets: repository
  Markdown remains on the current Work Schedules target, while business files are
  intended for the `Documents` library on the `the organization Industrial Networks`
  site. The new site's exact Site/Drive IDs, selected-site permission boundary,
  and retention settings remain unverified; do not enable writes until an M365
  administrator supplies/approves the target and the checks pass. Do not sync or
  move existing files at startup. For new uploads, lazily create the top-level
  `FieldOps` folder and then `customer-{customer_id}/` in the selected drive;
  store generated UUID filenames beneath it. Use the internal Customer ID, not
  its name or other PII. Existing files remain in their current locations;
  folders are organizational, not access controls. FieldOps authorizes every
  preview/open/download.

### Progress, pause, and readiness gates

- Persist per-step onboarding status, last attempt, sanitized failure code, and
  bounded progress. A failed integration step does not erase prior successful
  setup; an Admin may leave and resume onboarding.
- Group QuickBooks, Bitwarden Secrets Manager, Microsoft Graph, and first-run
  setup in a protected Admin Onboarding navigation area. Opening it requires the
  current password and a fresh TOTP code. The server enforces a user/session- and
  credential-bound access grant that expires after one hour without a request
  to the protected area; requests made within the area renew that idle window.
  Direct routes and actions remain behind the same gate. Sensitive actions retain
  their per-action authorization, explicit confirmations, and current-password
  confirmation, but do not request another TOTP code while the area grant is
  active. An expired grant requires the Admin to unlock the area again. The
  QuickBooks OAuth callback is exempt from the area gate so a valid, session-bound
  provider redirect can complete; its OAuth state validation remains required.
- No gateway falls back to local credentials/storage. Failed or unverified
  capabilities stay disabled. Keep Users/Invitations, business writes, QBO
  import/write-back, Contacts projection, and SharePoint writes gated until the
  required readiness conditions for that step pass.
- Before invitations unlock, require: first Admin email verified, TOTP enrolled,
  BWS Can Read token with Graph credentials, Graph target/canary/mail-linkback
  checks passed, and Graph/BWS failure queues reconciled. If the Admin opted into
  QuickBooks before recording readiness, also require the Intuit BWS credential
  profile, QuickBooks OAuth connection, complete Customer/Site and Item imports,
  and QBO failure reconciliation. If opted out, mark the QuickBooks credential,
  OAuth, and API import steps `not_required`; they may be completed later. If the
  Admin separately selects the local CSV source, its Customer/Site import remains
  required, but it does not require Intuit credentials or a QuickBooks Item
  catalog. Record an explicit readiness receipt and actor. A later opt-in requires
  the BWS Intuit profile and company OAuth checks before sync can be enabled;
  Customer/Site/Item imports then finish the QBO readiness step without revoking
  an existing invitation receipt.
- Provide an Admin-controlled QuickBooks synchronization pause independent of
  the saved OAuth connection and import-mode selection. Changing the switch
  requires the current password, is permission-guarded and audited, and preserves
  integration credentials and local records. The protected Onboarding area
  supplies the fresh-TOTP check. QuickBooks sync is disabled by default for new
  installations and is paused on upgrade by the default-change migration. While
  paused, block new QuickBooks reads, writes, and API imports; retain queued
  QuickBooks work in non-worker queues and resume it when re-enabled. A provider
  request already in progress may finish. CSV imports remain local-only and are
  not paused by this QuickBooks API control. The held-queue mechanism requires
  the shared database queue; do not expose the control as available for an
  unsupported queue driver.

## Consequences

- First-run setup is public only while no staff user exists and atomically claims
  the single-instance installation. This is not multi-tenant provisioning.
- This ADR changes current invitation-only bootstrap, optional TOTP,
  environment-configured credential assumptions, selectable Graph mailbox targets,
  default-off Contact projection timing, and the unresolved production QBO
  write-back decision. The Microsoft target is now one designated shared
  mailbox, not an arbitrary or staff-owned mailbox. Migrations must preserve
  existing invited staff and connections while new installations start unclaimed.
- A service-owned shared mailbox gives FieldOps one stable app-resource boundary
  for installation, authorization review, and maintenance rather than coupling
  Graph setup to individual staff accounts. Human access and staff lifecycle are
  handled through centrally managed Microsoft 365 groups, independently of the
  app-only integration.
- The accepted mailbox target is an architecture decision, not a live tenant
  change. Existing deployments and mailbox-scoped Graph event/Contact identifiers
  remain tied to their current resources until the first Admin completes the
  implemented activation action. That action fails closed when saved target
  evidence is stale, mailbox-scoped IDs point elsewhere, or Graph work remains
  unresolved/in-flight. It does not make Graph requests or enable writes. Never-
  attempted `pending_configuration` intents remain held by their feature flags;
  after an approved flag change they may dispatch from the activated target.
  Existing consumers are not redirected merely by saving target values; the
  local development target was activated on 2026-10-12 after the full chain
  passed, and other environments remain unactivated. Do not silently redirect consumers or
  delete the old mailbox or its data during routine rollback.
- FieldOps performs no BWS writes. Graph/QBO writes remain disabled until the
  corresponding credential, target, permission, canary-cleanup,
  import-idempotency, and recovery gates are implemented and verified.
  Documentation of a workflow does not itself authorize live external changes.
