# Release 1 Scope

## Included

- Responsive internal web application
- Monthly schedule home page
- Customer-wide and Site-scoped Contacts with operational roles such as Gate Contact, Supervisor, Operations Contact, and Safety Contact
- Customers with multiple contacts and sites; the Customer workspace supports multiple labeled Email, Phone, and Teams methods per Contact, with add/remove for Email and Phone and primary selection per type. After the initial QuickBooks Customer/Site import and onboarding permission checks, Contacts project one-way from FieldOps to the designated FieldOps shared mailbox's Outlook Contacts folder; Outlook never synchronizes back. Shared Contacts visibility is client-dependent and is not guaranteed for every user's Outlook client. Teams contact methods are data only in Release 1; Teams messaging is a separate v1.1 design/build phase.
- Admin customer, project, and job workspaces with compact directories, scoped contacts/sites, private attachments, and recoverable record archives
- Jobs, projects, visits/revisits, and scheduling
- Created and Scheduled statuses for Jobs and Projects. A Job may be created
  without a scheduled time; scheduling creates its initial Visit. Job creation
  emails the client contact, and scheduling emails the client contact and active
  assigned staff. Moving a Job to Pending requires an assigned staff member and
  a recorded reason. Job status colors are consistent across the Schedule,
  directories, and nested workspaces: Created gray, Scheduled blue, On The Way
  purple, Pending orange, In Progress magenta, Completed green, and Cancelled
  red.
- Customer and site timelines
- Notes and operational history
- Images, videos, PDFs, and common document attachments
- Global cross-entity search from every page
- Client, technical, project-manager, and contractor check-in contact-of-record fields on jobs
- Initial visits and revisits with separate associations
- Conflict-aware drag-and-drop scheduling with business-hours suggestions
- Server-side work-order PDF export
- One-time first-Admin bootstrap on an empty installation, pre-claim SMTP test/configuration, verified staff email, mandatory TOTP with a 14-day trusted-device option, and Admin-managed invitations unlocked after integration onboarding; Admin and Basic User account roles, optional Management and Billing notification roles use Spatie Permission with teams disabled
- Required staff TOTP two-factor authentication with one-time recovery codes and 14-day trusted-device tokens
- SMTP outbound email and communication history; first-run bootstrap requires a
  successful SMTP test to the first Admin's submitted address before claiming the
  installation, then sends the normal email-verification link
- Optional QuickBooks Online Customer/Site and Item synchronization through FieldOps-owned BWS Intuit app credentials and per-company OAuth authorization. Clients do not supply app Client IDs/Secrets; an authorized user in each QuickBooks company consents to the FieldOps app, producing company-specific tokens and Realm ID. QuickBooks starts opted out and paused; opting in makes Intuit credentials, company OAuth, and Customer/Site/Item onboarding checks required. Sync must then be explicitly enabled with the current password after BWS and company authorization are verified; required imports must finish before recording the readiness receipt. With QuickBooks sync opted out, a completed local-only CSV Customer/Site import does not require an Item catalog to unlock invitations; standard BWS/Graph readiness, failure reconciliation, and the readiness receipt remain required. Item-dependent features remain unavailable until API mode and Item sync are enabled. Opting out leaves the QBO setup steps available to return to later and does not relax Microsoft Graph readiness. Supported production Customer/Site writes use SyncToken conflict detection and QuickBooks-authoritative resolution after onboarding verifies the gateway. A first-run CSV export fallback may load Customers/Sites/Contacts locally when Intuit API credentials are unavailable; those records are not QuickBooks-linked and are never matched by name. After the completed CSV source transitions to API mode, those CSV records remain local-only while separate QBO-ID records are imported; potential duplicates require manual review.
- Production FieldOps write-back for supported Customer, Site, address, and Site primary-contact fields after gateway readiness; QuickBooks contract values overwrite local copies on later sync or conflict
- App-only Microsoft Graph access to one designated, service-owned shared mailbox: transactional notification/completion mail and Visit event projection. The mailbox is not tied to an individual staff account; human access is administered separately through Microsoft 365 groups and folder permissions. The legacy Admin-only mailbox-read gateway remains retired. Graph data is not merged with QBO/FieldOps records.
- Record existing QuickBooks Items and quantities used per Visit and include Item identity, description, and quantity in the Billing job report; do not store or reveal Item cost, price, accounting references, or on-hand stock, and do not implement inventory tracking
- Admin and Basic User roles with the same effective business permissions initially; the first account uses the existing Admin role (no separate Super Admin role)
- Assign active staff to Customers, Projects, and Jobs; assignment additions/removals queue internal email notices to the affected staff member and active Admins, never clients
- Record SharePoint-backed attachment upload, permanent-delete, and failure activity; persist in-app notifications and queue SMTP email for active assigned staff and related Project/Job/Visit participants. Recoverable archive is not deletion; Teams delivery and a delete UI are excluded.
- Resumable first-run integration setup for BWS-backed QuickBooks/Graph application credentials, one explicitly supplied dedicated shared-mailbox object ID (no directory picker/list), separate SharePoint targets, mailbox-type confirmations, authority checks, an email linkback, and temporary Calendar/Contact/SharePoint permission canaries; no startup mailbox/content sync. A separate audited runtime activation is available after the full readiness receipt and exact-target M365 evidence; it does not enable Graph write flags. Where the saved mailbox differs, manually reconcile mailbox-scoped Visit/Contact IDs with M365-admin evidence before activation; FieldOps clears only its local pointers and never deletes old remote records in this step. After QuickBooks import, queue bounded one-way Outlook Contact projection; create a Customer documentation root on first attachment upload in the approved Business Attachments target.
- Timestamped business activity history for customer, site, project, job, visit, contact, attachment, and assignment changes
- Authorized Admin sync-health indicator and bounded QuickBooks/Microsoft Graph issue review with safe retry or QuickBooks-authoritative resolution actions, including paginated Outlook Contact ID/status/last-sync diagnostics and guarded Resync/Repair controls
- Reusable technician timer in Schedule Job details for starting/stopping time and viewing active status, Customer/Project/Job/Visit context, and live elapsed duration; assigned-user Job status transitions automatically start Travel on On The Way, start Labor on In Progress, and stop the current Job timer on Created/Scheduled/Pending/Cancelled/Completed without confirmation prompts; no separate time-tracking section
- Admin > Time Tracking > Review for filtering all staff time entries and processing approval, unapproval, edits, deletion, and bulk approval
- Admin > Time Tracking > Payroll for date-range regular/overtime/total hours by technician, daily and time-entry drill-down, and CSV/Excel export using the configured weekly threshold; this is reporting only, not payroll execution or integration
- Admin > Time Tracking > Billing for Customer/Project/Job-grouped time-entry preparation, billable/unbilled summaries, and local Approved-to-Invoiced status updates; no QuickBooks invoice is created
- Admin > Time Tracking Dashboard with operational hour/status metrics, Active Timer and exception counts, and quick links to Review, Payroll, Billing, and Exceptions
- Required Job Scope, editable Visit Scope snapshots, and a client-facing completion summary
- One notification-mailbox calendar event per Visit, invitations to assigned technicians and the linked client contact, and scheduled RSVP polling
- Client/Management status updates, a client completion summary followed by a client completion PDF and supporting-file delivery, and a Billing ready-to-bill email with a separate Billing Job Report. Client updates and reports include Field Notes and every Visit's notes in visit-date order, while excluding internal Job Notes, assigned-staff details, and Item usage.
- Admin-only recoverable archive and batch restore for Customers, Sites, Projects, Jobs, Visits, Contacts, CustomerAddresses, and attachment metadata; the archive UI supports bounded batch browsing, root-type filtering, and whole-batch restore
- Internal API structure and agent extension points for later testing
- Ubuntu deployment and Azure-portable architecture

## Explicitly deferred

- Customer portal or customer login
- Multi-tenant provisioning
- Customer guest users (the one-time empty-install staff bootstrap is an exception, not guest access)
- Native desktop or mobile apps
- Progress widgets and performance metrics
- User-facing agentic actions
- Public partner API
- External sharing, migration of legacy local attachments, and a full SharePoint storage cutover remain deferred. After onboarding verifies the M365-selected Business Attachments site/Drive, new Customer, Project, and Job uploads record metadata in FieldOps and appear in unified attachment lists through generic authenticated URLs. Customer documentation roots are created on first attachment upload. Repository Markdown remains on Work Schedules. A5 provides bounded raster image previews/thumbnails and browser-native PDF, text, and CSV previews. A6 adds authenticated Office Online/SharePoint launch links; `.ppt`/`.pptx` join the upload allowlist. Office content is not rendered or converted inside FieldOps. Archive integration, completion-report reads, and Graph email attachment reads remain out of scope. Legacy files keep their local routes. QBO Estimate/Invoice documents remain deferred until a stable Job mapping is approved.
- Graph webhooks and continuous mailbox synchronization
- Release 1 Microsoft Teams data/actions and Graph change-notification subscriptions. Teams integration is planned as a separate v1.1 phase, with a supported Copilot/team-channel communication workflow among the options to evaluate; it requires its own authorization, message, and subscription decisions. Broader SharePoint/OneDrive integration beyond the A4–A6 attachment bridge also remains deferred.
- QuickBooks Customer Contact List `.xlsx` report conversion to the first-run CSV importer is V2. Release 1 keeps the current local-only an existing field-service platform-shaped CSV workflow; QuickBooks-linked Customer/Site imports use the QuickBooks API. Do not infer QBO IDs or customer/site hierarchy from display names.
- Full accounting, invoicing, payments, and financial reporting. The Customer CSV fallback imports only the approved identity/contact/address fields; account type/status, tags, lead source, and creation date remain unmapped. The sample's client rows are not used as fixtures or importer inputs.
- Inventory management
- Fine-grained business-record RBAC (Spatie account/admin and notification permissions are in use)
- Payroll execution/integration and invoicing/payment integration. ADR-0026 defines the timer foundation, ADR-0027 defines Job-status timer automation, ADR-0028 defines the Admin review and approval boundary, and ADR-0029 defines payroll-hour reporting; `Invoiced` and `Paid` are local statuses and do not connect to QuickBooks.

## SMS

Client texting is a required capability. Provider selection and exact release placement remain an open decision. Release 1 data structures must support normalized phone numbers, communication preferences/consent, and provider-neutral communication records.

## Release 1 success

A technician can open the monthly schedule, find scheduled work, view complete customer/site history without knowing a ticket number, record work and purchased items, attach files, send an SMTP message, and search the full operational record quickly.

## Future Milestones
- **QuickBooks Production Integration:** Complete the Intuit App Assessment and Compliance Questionnaire, secure production client credentials, and implement robust rate-limit handling and idempotent write logic. (See `docs/integrations/QUICKBOOKS-PRODUCTION-RESEARCH.md` for details).
