# ADR-0036: SharePoint Knowledge and Business Attachment Storage Structure

- Status: Proposed logical design; provisioning and implementation are not authorized by this ADR
- Date: 2026-10-06
- Related: ADR-0032 (SharePoint capability boundary), ADR-0034 (repository document sync), ADR-0035 (attachment bridge and migration)

## Context

The configured Graph target is the SharePoint site associated with the Work
Schedules Microsoft 365 Group. On 2026-10-06, the read-only
`graph:sharepoint:discover-libraries` command returned one document library named
`Documents` for that configured site. This discovery read library metadata only;
it did not enumerate folders or files or change SharePoint content.

Repository synchronization uses that configured Drive and preserves eligible
repository-relative Markdown paths. The `.opencode/**` subtree maps beneath
`agent-tooling/opencode/`; `.agents/**`, `.claude/**`, root `AGENTS.md`, and root
`CLAUDE.md` remain excluded. Repository files are copies; Git is authoritative.

A4 initially targeted the configured Work Schedules Drive using
`attachments/<generated-uuid>.<extension>` at the Drive root. New uploads were
then grouped beneath `attachments/customer-{customer_id}/`. The agreed root for
new business uploads is now `FieldOps/`, producing paths beneath
`FieldOps/customer-{customer_id}/`; existing files remain at their original
paths. This naming change does not select or validate a new SharePoint site or
Drive, migrate existing files, or implement this ADR's proposed per-owner-type
Business Attachments layout.

The current storage service is configured for one Site/Drive. `sharepoint_attachments`
records the DriveItem ID and Drive ID, plus FieldOps Customer/Site/Project/Job
owner context; its `site_id` column is the FieldOps Site foreign key, not the
Microsoft SharePoint Site ID. The preview/download provider currently resolves
items only through the one configured Drive. Future multi-target storage must
preserve this distinction and continue resolving existing Drive IDs.

## Decision

Keep **Knowledge** and **FieldOps Business Attachments** as separate SharePoint
targets. The intended Business Attachments target is the `the organization Industrial
Networks` site and its `Documents` library; its exact Site/Drive IDs and
permissions have not yet been provided or verified. Under that library, lazily
create a top-level `FieldOps` folder for new business uploads. Do not use folders
as security boundaries.

### Knowledge documents

- Keep the existing Work Schedules Group site and its `Documents` library for
  the repository-document mirror and other explicitly approved internal
  knowledge material.
- Preserve the current repository-relative path mapping and exclusions; do not
  move the synced root documents beneath a new folder as part of this design.
- Reserve `knowledge/{topic}/{document-slug}.{extension}` for future curated
  knowledge files that are not synchronized from Git. Use lowercase kebab-case
  topic and document slugs. This path is a convention only; no new knowledge
  upload workflow is implemented here.
- Treat Git as the source of truth for repository content. SharePoint remains a
  recoverable/readable copy produced by the manual sync. Do not store customer,
  site, project, or job attachments in this library.

### Customer, Site, Project, and Job documents

- Use the `Documents` library on the intended `the organization Industrial Networks`
  site, separate from the Work Schedules repository-document target. M365
  administrators own site membership, sharing policy, permissions, and retention.
  Confirm the exact Site/Drive IDs and selected-site boundary before enabling
  writes.
- Create `FieldOps` at the library root on first business attachment upload.
  Customer-, Site-, Project-, and Job-owned files share the FieldOps application
  authorization boundary and have no approved distinct retention classes.
  Separate libraries are not justified solely by owner table/type.
- The proposed future owner-specific layout uses one canonical owner folder per
  file; do not copy an attachment into every parent folder. The path includes
  the Customer ID for context and then the most-specific FieldOps owner:

  ```text
  FieldOps/customers/{customer_id}/attachments/{attachment_uuid}.{extension}
  FieldOps/customers/{customer_id}/sites/{site_id}/attachments/{attachment_uuid}.{extension}
  FieldOps/customers/{customer_id}/projects/{project_id}/attachments/{attachment_uuid}.{extension}
  FieldOps/customers/{customer_id}/jobs/{job_id}/attachments/{attachment_uuid}.{extension}
  ```

  Customer-wide, Site, Project, and Job folders are separate. A Job file is
  stored under the Job path even when it also records its Site and Project IDs in
  PostgreSQL. A future Visit association, if approved, must not duplicate the
  binary.

### Naming conventions

- Use the fixed top-level folder `FieldOps`, followed by lowercase fixed path
  segments such as `customers`, `sites`, `projects`, `jobs`, and `attachments`.
- Use stable internal FieldOps IDs in owner folders and a generated UUID for the
  attachment filename. Use only an extension derived from validated content
  type. Never put customer names, site addresses, job titles, contact details,
  original filenames, or other PII in the SharePoint path.
- Keep the user-supplied display filename in the authorized FieldOps metadata
  response/database record, not in the storage path. Preserve DriveItem ID,
  Drive ID, normal `webUrl`, MIME type, byte size, and owner IDs in FieldOps.
- The path is organization, not authorization. FieldOps must authorize every
  preview/open/download; do not add per-folder sharing links or rely on folder
  obscurity.

## Why sites, libraries, and folders

| Choice | Use | Rationale |
|---|---|---|
| Separate sites | Knowledge vs operational business files | Creates an independent M365 administration, membership, sharing, and retention boundary. The Work Schedules site is Group-backed and already hosts repository copies; customer/business files have a different sensitivity and lifecycle. |
| Separate libraries | Not initially within the Business Attachments site | All four owner categories use the same initial internal audience and FieldOps authorization. One library avoids duplicating configuration and creating unnecessary target mappings. Add a library only if M365 retention, records management, or access requirements materially differ. |
| Owner folders | Customer/Site/Project/Job organization inside the Business Attachments library | Mirrors typed FieldOps ownership without copying a file into multiple parent folders. Folder names use IDs, not PII. Folders do not grant distinct security or retention by themselves. |
| Separate site per owner type | Rejected | Would multiply site provisioning, app grants, target configuration, and operational maintenance without a distinct audience or policy for each owner type. |

The Work Schedules site's actual membership and retention policies were not
inspected as part of this design. The separate-site recommendation is a boundary
design, not a claim about its current permissions.

## Retention and recovery

- M365 administrators must select the Business Attachments site's retention,
  versioning, recycle-bin, legal-hold, backup, and disposition policies before
  the new target is enabled. No expiry or purge is inferred from folder names.
- FieldOps archive remains a recoverable application state. Archiving a Customer,
  Site, Project, or Job must not delete a SharePoint object. Keep the metadata
  pointer and bytes available for restore, subject to the approved M365 retention
  policy.
- Do not purge either SharePoint or legacy local copies during a future
  migration/cutover until reconciliation, retention, backup, and rollback
  windows have been approved and tested.
- Repository knowledge documents follow the existing Git-source-of-truth and
  sync/prune rules; that behavior does not apply to customer/business files.

## Permission and configuration considerations

- Do not add permissions in this design step. Before enabling a future Business
  Attachments target, an M365 administrator must verify the exact endpoint's
  app-only permission and selected-site resource grant, then test allowed access
  to the Business site and denied access to an unselected site. Prefer an
  endpoint-supported selected-site grant on the existing app registration; do
  not add broad tenant-wide Graph file/site roles.
- M365 administrators manage human site membership and any Editor access.
  FieldOps remains the application authorization boundary and does not create
  anonymous or client-sharing links. External customer access is not part of
  this design.
- Future application configuration should expose named, server-side targets
  (for example `knowledge` and `business_attachments`) mapped to configured
  Site/Drive IDs. Requests must never select a target. The current single-target
  storage provider must be extended before uploads move to the Business site.
- Before multiple targets are active, preserve the current Drive as a valid
  read target and add a durable SharePoint Site ID or target key to attachment
  metadata. The existing `sharepoint_drive_id` remains the item-read address;
  the added mapping provides an auditable Site/target identity and ensures old
  A4 records do not become unreadable when the default target changes.

## Scope and consequences

- This ADR records the agreed Business Attachments target and root-folder
  convention. It does not by itself change SharePoint content, permissions,
  retention policy, or database schema, and it performs no content migration.
- New uploads use `FieldOps/customer-{customer_id}/` with UUID filenames in the
  currently configured Drive; older uploads remain in their existing paths,
  including the original `attachments/` root. No existing attachment is moved.
- The configured Work Schedules Drive is a temporary compatibility target, not
  the intended long-term business-file destination. The feature flag remains
  default-off until the intended site's Site/Drive IDs, permission boundary, and
  retention controls are confirmed.
- A5's bounded raster/PDF/text/CSV preview behavior is independent of this
  target-design proposal; it uses the existing configured Drive and does not
  introduce a new site or library.

## Validation

- Read the existing sync command/service and A4 upload path and their ADRs.
- Read-only `graph:sharepoint:discover-libraries` confirmed the configured
  Work Schedules site currently exposes one library named `Documents`. No file
  or folder enumeration, content read, migration, write, permission change, or
  external sharing operation was performed for this design.
