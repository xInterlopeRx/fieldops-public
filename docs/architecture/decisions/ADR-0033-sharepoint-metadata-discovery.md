# ADR-0033: SharePoint Metadata Discovery Validation Commands

- Status: Accepted
- Date: 2026-10-05
- Related: ADR-0032 (broader SharePoint gap and file-support plan), ADR-0034 (allowlisted repository-document sync)

## Context

The existing Laravel Graph gateway has tenant-specific Work Schedules Site and
Drive identifiers but no SharePoint operations. The user requested read-only
Site, Drive, and document-library discovery plus CLI validation commands, while
prohibiting new app registrations/secrets and changes to existing Mail/Calendar
behavior. The broader use of SharePoint files and client sharing remains open
under ADR-0032.

## Decisions

- Add `MicrosoftGraphSharePointService` methods to read metadata for one
  explicit/configured Site, enumerate its Drives, and enumerate its document
  libraries from Site Lists. Match document libraries to Drives by the
  SharePoint List ID when Graph returns that relationship.
- Add read-only artisan commands:
  `graph:sharepoint:discover-site`, `graph:sharepoint:discover-drives`, and
  `graph:sharepoint:discover-libraries`. Each accepts an optional Site ID and
  defaults to `MICROSOFT_GRAPH_WORK_SCHEDULES_SITE_ID`. These commands inspect
  one known Site; they do not search or enumerate tenant-wide Sites.
- Add `MICROSOFT_GRAPH_SHAREPOINT_ENABLED`, default `false`, independently of
  the existing Mail and Calendar toggles. The existing single-tenant app-only
  client-credentials registration, tenant, client ID, and secret are reused;
  no new registration, secret, credential persistence, or Graph permission grant
  is created by this change.
- SharePoint requests use the existing Graph v1.0 client and `/.default` token
  flow only when the SharePoint toggle is enabled. Follow OData continuation
  links only when they are HTTPS URLs on `graph.microsoft.com` under `/v1.0/`;
  reject other hosts, fragments, credentials, traversal paths, and oversized
  links. Cap discovery at 100 pages of 100 records each.
- The code requires a selected application permission and Site-level resource
  grant before a live call can succeed, but the M365 administrator must choose,
  consent, and provision those outside FieldOps. The exact permission must be
  verified against each operation. Do not add broad `Sites.Read.All`,
  `Sites.ReadWrite.All`, `Files.Read.All`, or `Files.ReadWrite.All`, and do not
  grant runtime `Sites.FullControl.All` to administer its own Site access.
- Discovery returns metadata only: Site IDs/name/URL, Drive IDs/name/type/URL,
  and document-library List IDs/name/URL plus a matching Drive ID/name. It does
  not download files, read file contents, upload, change permissions, create
  sharing links, or persist remote metadata.
- Keep all existing Mail/Calendar code paths and feature switches unchanged.
  There are no HTTP routes or user-facing permissions for these validation
  commands; deployment-console access and the feature toggle are the execution
  boundary.

## Consequences

- The existing Site ID must be available in deployment configuration or be
  explicitly supplied to a command. Tenant-wide site discovery is intentionally
  unsupported because the selected-site design must not depend on broad
  enumeration permissions.
- Commands can validate application behavior with mocked Graph responses; they
  do not prove tenant consent or resource scoping. Before enabling the toggle,
  M365 administrators must test access to the selected Site and denial to a
  non-selected Site.
- At ADR-0033 acceptance, general SharePoint file content, download, delta
  synchronization, retention, external/client sharing, and local
  business-document identity mapping remained unplanned. A2 later added bounded
  standalone provider-level upload/download/metadata/delete operations against
  the configured Drive; it is not wired to FieldOps attachments or a business
  document workflow. Delta sync, migration identity, retention, and external
  sharing remain unplanned. The repository-document mirror remains the separate
  narrow workflow recorded in ADR-0034.
