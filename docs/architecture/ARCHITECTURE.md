# Architecture

## Style

FieldOps begins as a modular Laravel monolith with a Vue 3 and TypeScript user interface, PostgreSQL relational storage, S3-compatible object storage, and queued background work.

## Runtime components

- Web/proxy: terminates HTTP/TLS and serves/routes the application
- Laravel application: UI delivery, API, authentication, authorization, and domain logic
- Queue worker: QuickBooks sync, email, document processing, retries
- Scheduler: invokes Laravel scheduled tasks
- PostgreSQL: structured business data, audit data, queue metadata as applicable, and initial full-text search
- Cache and queue: database-backed defaults in development; add Redis only if measured needs justify it
- Object storage: attachment binaries. Legacy typed attachment rows continue to
  point to Laravel's private `local` disk (`storage/app/private`); new
  Customer/Project/Job uploads use the A4 default-off SharePoint bridge. The
  configured S3 disk is not used by either path; S3-compatible storage remains
  the planned portable object-storage direction.

## Domain modules

- Identity and access
- Customers
- Contacts
- Labeled Contact communication methods (Email, Phone, Teams)
- Sites
- Scheduling
- Jobs
- Projects
- Visits/revisits
- Timeline
- Notes
- Communications
- Documents
- Archive and recovery
- Items/job items
- Time entries, Job-status timer automation, Admin review/payroll/billing reports, and exception monitoring
- Search
- Integrations
- Audit

## Authentication boundary

Customers and contacts are operational records, not application identities.
Staff use local email/password accounts. On a clean installation, one atomic
first-account claim creates the initial Admin; it requires verified business
email, and public bootstrap closes permanently once claimed. After integration
onboarding is ready, all further accounts are provisioned through Admin
invitations. Email domain and Microsoft tenant do not restrict staff sign-in.
`spatie/laravel-permission` stores staff roles and permissions, with its teams
feature disabled. `admin` and `basic_user` retain the existing account/access
distinction and the same initial business permissions. `management` and
`billing` are additive notification-recipient roles only; user management
assigns one account role plus optional notification roles. Customer login,
multi-tenant provisioning, and fine-grained business-record permissions remain
deferred.

The initial permission grants are explicit: Admin has `admin.access`,
`users.manage`, `integrations.manage`, and the same `work_orders.view/manage`
permissions as Basic User. Management has `notifications.management`; Billing
has `notifications.billing`. Notification roles do not grant admin or business
record access on their own.

Laravel Fortify provides required TOTP two-factor authentication and recovery
codes behind the existing Vue/Inertia interface. Enrollment and changes require
recent password confirmation. A separate hashed, revocable trusted-device token
may suppress repeat challenges for 14 days; do not use Fortify's long-lived
remember-me cookie for this policy. See
[ADR-0038](decisions/ADR-0038-first-run-onboarding-and-integration-setup.md).

Ordinary authenticated sessions expire after 24 hours of inactivity and use a
browser-session cookie intended to expire when the browser closes. The protected
Admin integration-onboarding area has a separate one-hour idle grant; expiry
requires the current password and a fresh TOTP code again, but does not end the
underlying staff session.

## First-run integration onboarding

The single-instance setup flow and readiness gates are defined by
[ADR-0038](decisions/ADR-0038-first-run-onboarding-and-integration-setup.md).
Bitwarden Secrets Manager is the only secret manager. The BWS bootstrap token
is encrypted in PostgreSQL with Laravel authenticated encryption; the
`APP_KEY` remains deployment-managed outside the database. Environment-specific
BWS projects and read-only runtime machine accounts are required. Application
credentials are provisioned directly in BWS by an authorized BWS administrator;
FieldOps only validates and reads the selected fixed secret profile with its Can
Read runtime token and never writes secrets to BWS. Microsoft Graph credentials
are always required for first-run readiness. QuickBooks defaults to opt-out, so
the BWS check needs only Graph credentials; opting into QuickBooks makes the two
Intuit credentials, company OAuth, and Customer/Site/Item imports required.

QuickBooks client credentials resolve from BWS; company authorization remains
the existing OAuth flow and rotating per-company tokens stay encrypted in
`integration_connections`. Microsoft tenant/app credentials resolve from BWS.
The intended Graph resource owner is one designated, service-owned shared
mailbox for mail, Visit calendar events, and optional one-way Outlook Contacts;
it is not a staff member's mailbox or per-user integration. Microsoft 365
administrators manage human access separately through groups and folder
permissions. The current onboarding form stores manually entered targets but
requires type confirmations and a separately password-confirmed, audited runtime
activation after overall readiness and exact-target M365 evidence pass. In the
local development environment the readiness chain completed on 2026-10-12 and
the runtime target was activated; other environments remain unactivated. The
activation performed no Graph calls and enabled no write flags. SharePoint Site and
Drive targets remain separate onboarding settings, never request overrides.
Failed gateway checks pause readiness rather than falling back to local
credentials or storage. QuickBooks Customer/Site/Item
import precedes Outlook Contact projection; SharePoint Customer roots are made
on first attachment upload in the separately approved Business Attachments
target. Initial setup does not synchronize mailbox content or existing files.
CSV-only first-run mode is a local Customer/Site/Contact bootstrap alternative
when Intuit API credentials are unavailable. Imported records remain unlinked
from QBO; API reads, synchronization, and write-back are disabled for them. The
Graph readiness gate still applies before invitations unlock. If QuickBooks is
opted out, its OAuth and import gates are deferred; opting in later requires
those checks before synchronization can be enabled. If the separate local CSV
source is selected, its Customer/Site import is still required, but QuickBooks
Item readiness is not.

## Local document exports

Work-order PDF exports are rendered server-side from local Eloquent data using
the Laravel Dompdf adapter. Export controllers must authorize access to the
underlying record before rendering a document. Invoice PDF exports are deferred
until invoicing has an approved data model and workflow.

## Microsoft Graph notifications

The Graph client uses one configured tenant and the designated shared mailbox
for status/completion mail and Visit event projection; email intents use
a durable outbox, and Graph `202 Accepted` is recorded as accepted, not proof of
delivery. Exchange Application RBAC is scoped to that mailbox; M365
administrators separately manage human access, and unscoped equivalent Entra
grants must not defeat the scope. The Work Schedules Microsoft 365 Group is
retained for repository SharePoint content, not calendar writes. Microsoft 365
administrators manage resource-scoped Graph/Exchange permissions and human
sharing; FieldOps does not manage Microsoft permissions. PostgreSQL remains the
schedule source of truth. The current onboarding form still captures selectable
targets; ADR-0038 now gates runtime resolution on explicit saved-target
activation. Onboarding-required installations fail closed until that action is
completed; legacy installations whose onboarding is disabled retain their
deployment-configured targets. The local development target was activated on
2026-10-12 after the full readiness chain and exact-target M365 evidence; the
activation enabled no write flags and made no Graph calls.

## SMTP mail configuration

First-run staff email verification uses the SMTP transport configured and
test-sent to the proposed first Admin during the empty-install bootstrap, before
the installation claim is consumed. SMTP username/password are encrypted in the
singleton `mail_transport_configurations` row with the deployment-managed
`APP_KEY`; the settings are applied to web requests and queue workers without
writing plaintext credentials to environment files or logs. Plain SMTP is
allowed only in local development. This is separate from the Microsoft Graph
outbox used for approved operational mail.

An independent one-way Contact projection writes FieldOps Contact data to the
designated shared mailbox's default Outlook Contacts folder only after QBO
Customer import and Contact/tenant authority checks complete. It records the
remote Contact ID and owning mailbox, and does not import or merge Outlook data.
Shared Contacts visibility depends on the staff member's permissions and Outlook
client; universal visibility in new Outlook or Outlook on the web is not a
readiness requirement.
See
[ADR-0037](decisions/ADR-0037-one-way-outlook-contact-projection.md).

The SharePoint repository mirror remains on the Work Schedules Site/Drive. The
business-attachment provider uses a separately M365-approved Business
Attachments Site/Drive selected through onboarding after least-privilege access
and target/non-target canary verification. New Customer, Project, and Job
uploads use it only after readiness is complete and record separate DriveItem
metadata in PostgreSQL. Existing local attachment
rows and their local routes remain intact; list responses combine both sources
using one FieldOps attachment shape and a generic authenticated FieldOps URL.
That URL redirects remote records to their normal SharePoint `webUrl`. New
authenticated preview endpoints proxy Graph-generated small thumbnails without
downloading originals for list views, re-encode raster previews with GD, and
serve PDF/text/CSV inline with safe headers. Image processing requires PHP GD
with the enabled JPEG/PNG/GIF/WebP decoders. This is still not a migration of old files or a provider cutover for
reports, completion-email packages, archives, or other direct local-storage
consumers. A6 adds authenticated Office Online/SharePoint launch links without
rendering Office contents inside FieldOps. ADR-0036 proposes a separate future
business-document site/library; business writes remain blocked until it is
separately provisioned, approved, and configured.

SharePoint-backed attachment uploads and permanent deletes append owner-scoped
activity events. Active direct assignees and the related Project/Job/Visit
participants receive a persisted in-app notification; SMTP email is queued
after commit. Recoverable archive is not permanent deletion. No Teams delivery
or attachment-notification UI is part of this workflow.

## Boundaries

QuickBooks owns accounting and is the initial authority for synchronized customer and item information. FieldOps owns technician workflow, sites as operational records, jobs, projects, schedules, visits, timeline events, notes, documents, communication history, and job item usage.

## Azure portability

Application code depends on abstractions for object storage, queue/cache, mail, and secrets. Configuration is externalized. Containers are stateless except for declared persistent services. Azure migration should map services rather than require a rewrite.
