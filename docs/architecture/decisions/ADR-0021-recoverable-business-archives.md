# ADR-0021: Recoverable Business Archives

- Status: Accepted
- Date: 2026-10-01

## Context

Administrative removal of operational records must be recoverable. Existing foreign keys include physical cascade behavior, and project or work-order attachments are stored as external files whose metadata relationships must remain intact. Archive operations also need to preserve descendants archived in earlier, independent actions.

## Decision

- Keep archived rows and archive history in PostgreSQL; do not introduce a separate archive database or copy business rows elsewhere.
- Add archive state to Customers, Sites, Projects, WorkOrders, Visits, Contacts, CustomerAddresses, CustomerEmailAddresses, CustomerAttachments, ProjectAttachments, and WorkOrderAttachments. A UUID archive batch records the selected root, actor, time, optional reason, and restore actor/time.
- Exclude archived rows from normal Eloquent queries. Provide Admin-only archive listing, archive, and whole-batch restore endpoints. The archive UI lists active batches with bounded pagination and root-type filtering, and restores whole batches; archive creation remains available through the protected archive endpoint and is not part of this UI.
- Archive the active owned descendants into the same batch. Restore only rows carrying that batch ID, leaving descendants archived earlier untouched.
- Archiving a Site must not archive a Project while another active Site remains linked through the project-site relationship or an active Job. Archiving an owned Project still cascades to its active Jobs, Visits, and attachments.
- Retain attachment metadata and object-storage bytes while archived. Prevent ordinary Eloquent and database parent deletes from cascading through archive-managed relationships. The guarded local QuickBooks dataset replacement remains an explicit destructive reset path.
- QuickBooks inbound synchronization must not unarchive archived Customers, Sites, Contacts, or addresses, create duplicates in place of archived provider-linked rows, or issue remote delete operations.

## Consequences

- Archive and restore operations are transactional and batch-scoped. Restore is all-or-nothing and reports a conflict if active contact or default-address uniqueness would be violated.
- Contact-type and default-address uniqueness applies to active rows, allowing replacement active records while older rows remain archived.
- Archived provider-linked rows can reserve globally unique QuickBooks identifiers; synchronization skips those rows rather than silently restoring them.
- There is no retention or purge policy yet. Removing archive history requires an explicit future decision and a safe purge workflow.
- The QuickBooks replacement command may physically clear local business rows only after validating its local sandbox guard and attachment-metadata safety check; it also clears corresponding archive batches.
