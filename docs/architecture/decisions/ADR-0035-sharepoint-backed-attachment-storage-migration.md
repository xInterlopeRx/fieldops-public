# ADR-0035: SharePoint-backed Attachment Storage Migration Strategy

- Status: Accepted for the A4 new-upload bridge, A5 bounded previews, A6 Office/SharePoint launch links, and A7 queued upload UX; broader migration/cutover remains proposed
- Date: 2026-10-05
- Related: ADR-0021 (recoverable business archives), ADR-0023 (Graph notifications), ADR-0034 (repository-document-only SharePoint sync), ADR-0036 (proposed SharePoint storage structure)

## Context

FieldOps stores Customer, Project, and Job attachment metadata in three
owner-specific PostgreSQL tables and writes file bytes to the private Laravel
`local` disk. Job attachment rows may also be linked to a Visit. Preview,
download, report generation, and Graph notification code access those bytes
through Laravel `Storage` calls. A3 adds a separate `sharepoint_attachments`
metadata table with owner context and DriveItem identity, but it has no link to a
legacy local attachment row, checksum, or migration state. A4 connects new
Customer, Project, and Job upload paths to SharePoint while leaving legacy
attachment rows and direct local consumers in place.

The connected SharePoint operations in ADR-0034 are restricted to allowlisted
repository Markdown. They are not an approved general business-file store.
The proposed Business Attachments Site/library structure is documented in
ADR-0036. Its provisioning, audience, resource permissions, retention, and
source-of-truth policy remain undecided. See the A1 audit report at
[`docs/architecture/ATTACHMENT-AUDIT.md`](../ATTACHMENT-AUDIT.md).

## Decision

Adopt the narrow A4 compatibility bridge for **new uploads only**. This does not
authorize migrating existing local files or switching every attachment
consumer. A1 audited/documented the local architecture; A2 added bounded provider
primitives; A3 added metadata; A4 connects new upload paths, metadata records,
unified list responses, and a generic authenticated open URL. Continue to
preserve all existing local attachment tables, rows, bytes, and URLs.

### A4 new-upload compatibility bridge

- New Customer, Project, and Job uploads use the existing configured Graph
  Site/Drive and create a separate `SharePointAttachment` metadata row with
  uploader and available Customer/Site/Project/Job context. Remote paths use a
  generated UUID rather than customer-supplied filenames.
- `MICROSOFT_GRAPH_SHAREPOINT_ENABLED` remains default-off. If disabled or the
  remote write/metadata persistence fails, the request fails; it must never
  silently save the same upload to local storage instead.
- Existing attachment lists merge local and remote rows into the same FieldOps
  resource shape. Local URLs remain unchanged. Remote URLs use an authenticated
  FieldOps route, apply the relevant Admin/assigned-Job access check, validate
  the stored normal SharePoint `webUrl`, and redirect to that URL. No raw
  provider fields are sent to Vue.
- A4 did not add previews, thumbnails, custom Office actions, sharing links, or
  remote content streaming. A5 adds only the bounded preview types described
  below. Neither slice migrates legacy bytes or connects SharePoint rows to
  archive-state updates, completion-report reads, or Graph completion-email
  attachment reads; those consumers remain local-only.
- A4 uses the configured Work Schedules Site/Drive, which is also used by the
  repository-document mirror. ADR-0036 proposes a dedicated Business
  Attachments Site/library, but none has been provisioned; no M365 permission
  was changed and no live business-file upload was performed. Keep the feature
  flag off until the target and permission boundary are explicitly verified for
  the deployment.

### A5 bounded preview and thumbnail bridge

- Authenticated FieldOps preview and thumbnail routes reuse the same server-side
  owner-context and Admin/assigned-Job authorization as the generic open route.
  The frontend receives only generic optional `previewUrl` and `thumbnailUrl`
  capabilities; it does not receive Drive IDs, item IDs, credentials, or storage
  type flags.
- List thumbnails use Graph-generated medium/small raster thumbnails fetched
  server-side through a validated, short-lived URL with a 2 MiB response limit;
  the original DriveItem is not downloaded for list rendering. The returned
  thumbnail is MIME-checked, bounded, and re-encoded as JPEG by GD. Full image
  previews are on-demand only: source images are limited to 16 million pixels
  and re-encoded with GD to at most 2400x1800. EXIF metadata is not copied.
  Original uploads retain the provider's existing 20 MiB cap.
- PDF, UTF-8 plain text, and CSV are fetched through the bounded provider and
  served inline with `nosniff`, private/no-store caching, and generic failure
  responses. Text/CSV is served as `text/plain`, not rendered as HTML. Browser
  native PDF/text support is used; Word/Excel in-app previews, video previews,
  and external sharing remain out of scope.
- The A5 routes do not modify legacy local preview/download behavior. They make
  no client-side Graph requests and never return a Graph token or
  preauthenticated content URL. Thumbnail/preview responses are not persisted.
- Image processing requires PHP GD with JPEG/PNG/GIF/WebP support. It is present
  in the validated development environment; deployments must provide the same
  extension before enabling preview URLs.

### A6 Office Online and SharePoint launch links

- SharePoint-backed attachment resources expose a generic `onlineUrl` and an
  `officeApp` capability (`word`, `excel`, or `powerpoint`) derived from the
  attachment MIME type/validated filename extension. Legacy local attachment
  rows expose neither capability.
- Existing Customer, Project, Job, and Calendar attachment rows render one
  generic `Open` action for SharePoint-backed items. The user-facing control
  does not expose the provider name. The action targets the authenticated
  FieldOps open route, which enforces the existing owner/assigned-Job
  authorization and redirects to the saved normal `webUrl`; the browser
  receives no Graph IDs or credentials.
- PowerPoint `.ppt/.pptx` is added to the existing Customer/Project/Job upload
  allowlists so the requested PowerPoint action can be used for new attachments.
  No other existing upload type or size limit is changed.
- This uses the normal SharePoint file URL and its standard browser behavior; it
  adds no Office JavaScript/SDK, custom conversion, content rendering inside
  FieldOps, sharing link, or Graph permission. Tenant-side open behavior was not
  live-tested for A6.

### A7 queued Job uploads and stale-link reconciliation

- In the Job workspace, persist explicitly selected, not-yet-uploaded file
  Blobs in browser IndexedDB, scoped by staff user and Job. Retain each file
  until SharePoint confirms success or the user removes it. Resume queued work
  when that Job workspace is open and the browser reports/retries connectivity;
  this is a browser queue, not a server queue, service worker, or automatic
  background Graph sync. Keep the pending count bounded to 20 files per Job and
  apply the existing 20 MiB per-file server validation.
- Browser IndexedDB is device/profile-local and is not encrypted by FieldOps.
  Do not store credentials or provider tokens there. The UI states that queued
  bytes are not available to other staff until SharePoint accepts them.
- Give every queued file a client-generated UUID idempotency key. Use it as the
  generated SharePoint filename and persist it in a nullable unique
  `client_upload_id` column. A retried request with the same key and matching
  owner/file metadata returns the existing record, preventing a lost HTTP
  response from producing duplicate DriveItems. Never serialize this key to
  ordinary attachment resources.
- The generic Open action performs an on-demand metadata check before opening a
  SharePoint-backed file. A confirmed Graph `404` removes only the stale local
  metadata row and records an audit event; it does not issue a remote delete.
  Permission, network, and service errors leave the record intact and still
  allow the saved SharePoint link to be opened. There is no periodic or
  page-load-wide SharePoint reconciliation.
- Use MIME/extension-derived file-type icons beside filenames. Filenames still
  identify the file type; color and icons are not the only status indicators.

The broader migration sequence below remains proposed and must be completed
before migrating legacy rows, changing their content source, or retiring local
copies.

For a future implementation, prefer an additive migration that:

- Retains `customer_attachments`, `project_attachments`, and
  `work_order_attachments`, their typed owner relationships, archive state, and
  local `disk`/`path` values during the migration and rollback period.
- Adds provider-neutral content access behind an application service used by
  uploads, inline responses, downloads, report generation, and Graph email
  delivery. Keep the local adapter as the default during rollout.
- A3 adds a separate `sharepoint_attachments` table with `company_id` pointing
  to `customers.id`, optional `site_id`, `project_id`, and `job_id` context,
  `filename`, `mime_type`, `file_size`, Drive ID, DriveItem ID, and a normal
  `webUrl`. The Drive/item pair is unique. The table is not a backfill map for
  the existing local attachment tables; add that mapping and migration state
  only in a later, explicitly scoped migration step. Never store expiring
  `@microsoft.graph.downloadUrl` values or Graph credentials.
- Copies and verifies historical remote objects before switching an individual attachment
  to SharePoint. Keep the local object as a rollback copy; no archive action or
  initial migration step purges either copy.

## Proposed rollout

1. Obtain business approval for the dedicated Site/library and folder policy,
   data sensitivity, authorized readers, source-of-truth semantics, retention,
   backup, external-sharing boundary, and rollback/purge conditions. Have an
   M365 administrator verify the exact endpoint-supported app permission and
   site-level resource grant. Do not assume the repository-document sync's
   Work Schedules target is suitable for attachments.
2. Run a read-only, repeatable inventory over all three attachment tables,
   including archived rows. Record aggregate counts and bytes; validate each
   `disk`/`path`, metadata size/MIME, SHA-256, owner relationship, and optional
   Job/Visit consistency. Report missing/duplicate/orphaned objects and stop for
   remediation rather than silently skipping them.
3. Deploy additive nullable metadata with a default local backend. Preserve
   existing required `disk` and `path` values while old code and migration
   workers coexist. Backfill any selected state in bounded chunks; do not
   rewrite deployed historical migrations or change the existing Storage calls
   in this schema-only preparation.
4. Introduce a provider-neutral storage service and local adapter. Migrate all
   current direct consumers—including the authorized HTTP preview/download
   actions, completion report generation, and `SendGraphNotification`—before
   allowing any row to select SharePoint. Keep authorization in FieldOps and
   stream content through the application; do not create anonymous or
   preauthenticated sharing links in this plan.
5. Copy in retryable, idempotent background work using stable internal
   attachment IDs for destination identity. Keep file bytes and tokens out of
   queue payloads. Capture remote IDs/version only after checking remote size
   and SHA-256 against the source. Resolve eTag conflicts explicitly; do not
   overwrite changed remote content blindly.
6. Cut over one verified record at a time. Preserve the local pointer and bytes
   through an approved rollback window. Keep one primary write target per
   attachment; do not introduce un-reconciled dual writes. Test missing remote
   objects and Graph/storage outages without losing the local fallback.
7. Reconcile attachment counts, bytes, hashes, archived status, and each
   consumer before enabling SharePoint as the primary store. Any eventual local
   deletion or remote purge requires a separately approved auditable retention
   and recovery operation.

## Risks and constraints

- A PostgreSQL transaction cannot make a filesystem or Graph write atomic. The
  current blob-before-row order can leave orphaned local files; a future transfer
  worker must represent partial progress and safely resume.
- Existing callers use Storage directly, so switching a provider field without
  updating all consumers would break preview, download, email, and report
  generation.
- Archived metadata and binary retention are intentional under ADR-0021.
  SharePoint synchronization must include archived objects or explicitly
  preserve a working local copy; archive/restore must not trigger remote deletion.
- Current per-file validation is 20 MiB, while a Job request can contain up to
  20 files. Confirm web server/PHP request limits and choose bounded/resumable
  transfer behavior before increasing limits.
- Work-order attachments have independent Job and Visit foreign keys; current
  PostgreSQL constraints do not guarantee that both parents share the same Job.
  Inventory and validate this relationship before migration.
- The Job inline response and Admin forced-download responses have different
  headers/disposition. Preserve documented application routes and review MIME
  safety in a separate implementation/test slice.
- Site-level Graph permissions, allowed/denied resource behavior, data ownership,
  and retention have not been approved or independently tenant-verified for
  business attachments. The A4 code does not establish those tenant-side
  controls.

## Consequences

- A1 made no runtime changes. A2 adds a bounded, default-off provider service
  against the configured Site/Drive, using an eTag precondition on deletion.
  A3 adds an independent metadata table and Eloquent relationships. A4 routes
  new uploads into that provider and table and merges their metadata into the
  existing lists. A5 adds authenticated, bounded raster/PDF/text/CSV preview
  routes for SharePoint-backed rows; legacy local rows, routes, reports, email
  consumers, archive behavior, and the legacy attachment schema remain intact.
- A read-only live metadata/download smoke test of the existing SharePoint root
  README matched the local README bytes. No live upload or delete was performed;
  mocked HTTP tests do not prove write/delete permissions or non-target denial.
- The existing three typed attachment relationships remain unchanged. The
  separate SharePoint metadata table has explicit Customer, Site, Project, and
  Job foreign keys rather than a polymorphic owner, but has no reference to a
  legacy attachment row yet.
- Local files remain the current source and a future rollback copy until
  explicit cutover and retention approvals.
- Provisioning/approval of the ADR-0036 target, the SharePoint permission
  boundary, archive/retention
  behavior, legacy-file migration/cutover, report/email integration, and any
  external sharing remain open. ADR-0034 continues to cover repository Markdown
  only; it does not itself authorize operational documents or customer sharing.
