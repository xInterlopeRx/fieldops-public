# ADR-0025: QuickBooks Contract Authority and Write Conflicts

- Status: Accepted
- Date: 2026-10-02
- Superseded in part by ADR-0038: the sandbox-only deployment limit is replaced by production Customer/Site writes only after onboarding verifies the production gateway and the supported contract. QuickBooks Item synchronization remains read-only; FieldOps never creates or updates Items. QuickBooks remains authoritative and SyncToken conflict handling remains in force for supported Customer/Site writes.

## Context

FieldOps users edit Customer and Site profiles while QuickBooks Online remains
the source of truth for synchronized contract data. A write based on a stale
local copy must not silently overwrite a newer QuickBooks edit. Failed writes
also need a visible, recoverable way to return to the authoritative provider
state.

## Decisions

- Keep QuickBooks authoritative for fields covered by the supported
  Customer/Site synchronization contract. FieldOps writes the supported
  Customer profile fields, default addresses, Site profile fields, and Site
  primary-contact fields through the existing sandbox-only queue. FieldOps-only
  Contacts, additional email addresses, nondefault address-book entries, Jobs,
  Visits, Projects, assignments, and notes outside the mapped QBO contract stay
  local.
- Before updating a linked QBO Customer or Site, fetch the current provider
  record and compare its `SyncToken` with the locally stored token from the last
  synchronization. If the tokens differ or the local token is absent, do not
  post the stale FieldOps payload. Mark the synchronization failed with the
  sanitized `quickbooks_conflict` code.
- Keep provider writes idempotent with the existing persisted request ID and
  payload. Serialize Customer/Site writes and Customer refreshes by the shared
  QuickBooks data lock. A stale queued request whose request ID was cleared by
  conflict resolution must not start another provider write.
- When an Admin resolves a failed synchronization, clear the failed Customer
  and Site write intent and queue a full Customer-plus-direct-Sites refresh. The
  full QBO response overwrites local synchronized values, including default
  Customer addresses and Site primary-contact fields. Nondefault/local-only
  address-book entries, FieldOps-only Contacts, and additional email addresses
  remain intact.
- Show the sync error in the Customer workspace and provide an explicit
  “Resolve using QuickBooks” action with a confirmation that pending local
  contract edits will be replaced. Poll status while a write/refresh is pending
  so a completed failure becomes actionable without navigating away. Do not
  expose SyncTokens, request IDs, payload snapshots, OAuth tokens, or provider
  error bodies to the browser.
- At this ADR's acceptance, keep all Customer/Site writes sandbox-only until
  production write-back is separately approved. ADR-0038 later authorizes
  supported production Customer/Site write operations only after onboarding
  readiness and safe retry/recovery gates pass. Item operations are read-only
  catalog synchronization and are not included in Customer/Site write-back.
  Conflict resolution itself performs a provider read, not a write. Continue to honor the separate
  Microsoft Graph write boundary as updated by ADR-0038.

## Consequences

- The user can edit supported linked Customer/Site contact fields in FieldOps;
  successful QBO responses are mapped back into the local model.
- A concurrent QBO edit blocks the stale FieldOps post. An Admin can explicitly
  refresh from QBO, which discards the failed local write intent and makes QBO
  values win.
- The UI must distinguish pending, failed, and synced states and tell the user
  when a failed local write will be discarded.
- The production Customer/Site write-back decision remains subject to ADR-0038
  onboarding, permission, idempotency, and recovery gates. Item catalog reads do
  not grant or imply Item write support. QuickBooks remains authoritative and
  the SyncToken conflict behavior in this ADR is retained for Customer/Site.
