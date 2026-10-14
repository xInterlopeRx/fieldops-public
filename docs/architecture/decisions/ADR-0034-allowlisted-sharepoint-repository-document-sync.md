# ADR-0034: Repository Documentation Inventory and SharePoint Sync

- Status: Accepted
- Date: 2026-10-05
- Related: ADR-0032 (broader SharePoint gap), ADR-0033 (metadata discovery)

## Context

FieldOps needs to inventory the documentation actually present in the repository
and synchronize authoritative Markdown to the configured Work Schedules Documents
drive. This is not a business attachment workflow or general SharePoint file
integration. Existing Graph client-credentials authentication and configured
Work Schedules Site/Drive identifiers must be reused.

## Decisions

- `DocumentationInventoryService` discovers repository Markdown, including
  root project guidance, ADRs, docs/architecture, docs/product, docs/integrations,
  docs/operations, docs/development, and tracked agent/tooling knowledge. It
  records repository-relative path, classification, file type, filesystem
  modification timestamp, suggested SharePoint path, and eligibility. Excluded
  roots include `.git`, `vendor`, `node_modules`, `storage`, build output, and
  coverage output. Non-Markdown crawl artefacts are reported but not eligible.
- Per the follow-up scope reduction, do not synchronize `.agents/**`,
  `.claude/**`, root `AGENTS.md`, or root `CLAUDE.md`. Keep `.opencode/**`
  eligible. The inventory still lists the excluded Claude/agent Markdown and
  their suggested destinations, but marks them ineligible.
- `graph:sharepoint:inventory-docs` prints the generated Markdown inventory
  report without creating a local report file. `graph:sharepoint:sync-docs`
  synchronizes only eligible Markdown files. The Git working tree is
  authoritative; SharePoint is a copy.
- Repository documentation synchronization is manual only. Git push, deployment,
  and the production asset build must not invoke SharePoint writes. Before running
  `php artisan graph:sharepoint:sync-docs`, review the eligible Markdown inventory
  and ensure only intended, committed documentation is in the local working tree.
  QuickXorHash makes safe retries idempotent.
- Preserve repository-relative paths in SharePoint. Map hidden `.opencode`
  paths beneath `agent-tooling/opencode/` to avoid hidden SharePoint folder
  names. Create only missing parent folders required for eligible document
  paths; do not enumerate, reconcile, rename, or delete folders.
- Use the existing `MICROSOFT_GRAPH_SHAREPOINT_ENABLED` gate and Graph
  credentials; synchronization is invoked only by the explicit Artisan command,
  never by Git hooks, production builds, application requests, or the queue. Verify
  that the configured Drive is present in the configured Site before processing.
- For each file, compute Microsoft QuickXorHash over the exact local bytes and
  compare it with the existing DriveItem `file.hashes.quickXorHash`. Skip the
  upload when hashes match. Create missing files and replace files whose hashes
  differ. If Graph does not return a comparable hash for an existing item, fail
  closed and do not upload that item.
- QuickXorHash is used only for change detection, not for security or integrity
  verification. Keep uploads bounded and log only file identifiers/status, not
  document contents, tokens, secrets, or authorization headers.
- `graph:sharepoint:prune-agent-docs` removes only the previously synchronized
  `.agents/**`, `.claude/**`, `AGENTS.md`, and `CLAUDE.md` items. It verifies each
  remote QuickXorHash still matches the local source before deletion, preflights
  all targets before deleting any, and leaves SharePoint folders untouched.
- Retain the narrower `graph:sharepoint:upload-readme` command as a one-file
  create-or-replace operation. Both operations reuse the same app-only Graph
  gateway and configured Site/Drive; no new app registration or permission is
  introduced.

## Consequences

- The sync command reports each eligible file as `unchanged`, `created`, or
  `updated`; reruns do not upload unchanged files and use the same DriveItem
  path, preventing duplicate names. If an existing remote file has no comparable
  QuickXorHash, sync fails closed instead of uploading speculatively.
- Agent/Claude source files remain in the inventory as excluded records after
  their remote copies are removed. The explicit OpenCode command document remains
  eligible.
- No remote metadata database, sync cursor, queue, folder reconciliation,
  download, attachment storage, or customer sharing is introduced.
- The explicit Artisan command is the only repository-document sync trigger.
  It remains separate from Git operations and production builds.
- Broader SharePoint business-file workflows remain deferred under ADR-0032,
  except for the separately scoped A4 new-upload compatibility bridge in
  ADR-0035. This repository-document mirror does not implement that bridge or
  change its target, authorization, migration, or retention behavior.
