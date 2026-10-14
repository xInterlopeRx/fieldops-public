# ADR-0022: Initial Microsoft Graph Read-Only Access

- Status: Retired (2026-10-02)
- Date: 2026-10-01

> This ADR records the initial gateway slice. ADR-0023 supersedes its no-write
> decision only for Visit calendar events and the explicitly described FieldOps
> notification/report emails, and supersedes its permission-grant procedure for
> the selected Exchange Application RBAC authorization model. Other Graph writes
> and data synchronization remain deferred.
>
> The Admin-only read routes and their mailbox configuration were later removed
> after the user confirmed they were not needed. This ADR is retained as a
> record of that initial, now-retired implementation.

## Context

FieldOps needs a bounded first integration slice using Microsoft Graph for the configured Microsoft 365 environment. FieldOps already owns its technician schedule, operational Customer/Site Contacts, outbound SMTP, and communication history. QuickBooks remains authoritative for synchronized Customer and Site profiles. Graph email, Teams, SharePoint, and change notifications were previously deferred or not yet modeled.

The Hermes Graph gateway demonstrates a working app-only client-credentials flow and explicit Graph operations. Its Python secret provider, tool wrappers, unbounded result aggregation, and caller-supplied mailbox identity do not directly fit FieldOps's Laravel authorization, secret, query, and queue boundaries.

## Decision

- Implement a Laravel-native, backend-only, app-only Graph gateway for one configured Entra tenant and one server-configured mailbox identity. Do not accept tenant or mailbox identities from request parameters.
- Use the Graph v1.0 endpoint and the client-credentials `/.default` scope. Keep the tenant ID, client ID, and client secret in externalized server configuration; do not copy Hermes credentials or store access tokens in application records, logs, responses, or queue payloads.
- Enable only read operations for user calendar-view pages, Outlook personal contacts, message list metadata, and an explicitly requested message detail/body. Admin middleware protects the JSON endpoints. Pages are bounded, and continuation cursors are encrypted and restricted to the same Graph resource path.
- Request only the tenant application permissions required by this read-only slice: `Calendars.Read`, `Contacts.Read`, and `Mail.Read` for message-body access. Microsoft Entra admin consent is required. Before live use, configure and verify Exchange Application RBAC or an equivalent supported restriction so app-only access is scoped to the configured mailbox; a mailbox ID allow-list in FieldOps code is not the tenant security boundary.
- Do not persist or automatically merge Graph results into QBO or FieldOps Customers, Sites, Contacts, schedules, or communications in this slice. Preserve QBO IDs, FieldOps local IDs, and Graph IDs as separate identity domains. Graph `Contacts` are not automatically FieldOps operational Contacts.
- Do not implement Graph email send/draft/update/delete, calendar writes, Teams APIs, SharePoint/OneDrive reads or writes, webhook/change-notification subscriptions, or background synchronization yet. These need explicit use cases, authorization/data-retention rules, and follow-up decisions.
- Do not add an end-user UI in this slice. Provide Admin-only backend read endpoints for controlled validation and future application use.

## Consequences

- The integration uses app-only permissions, not a technician's delegated Microsoft identity. Every request acts within the configured mailbox scope; FieldOps server-side Admin authorization remains mandatory.
- Message detail may contain personal or confidential body text. Only Admin-only requests can retrieve it, lists return metadata without bodies, and no message content is persisted by these endpoints.
- Microsoft Graph `202 Accepted` send semantics are not applicable because no write/send operation is provided.
- No Graph connection table or provider identity mapping is added yet; credentials remain configuration-only, and read results are transient responses.
- Live use is blocked until the app registration has only the intended Graph application permissions, tenant consent is approved, and mailbox scoping is verified. A local mocked test suite does not prove tenant consent or Exchange RBAC configuration.
- The broader Graph research remains relevant for future calendar synchronization, Teams, SharePoint, and notification work. Each addition requires a separately reviewed permission/data-flow decision; do not expand the gateway into an arbitrary Graph proxy.
