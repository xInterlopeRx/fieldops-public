# ADR-0018: Relational Core

- Status: Accepted
- Date: 2026-09-18

## Decision

Use PostgreSQL as the primary database. Store binary files in object storage. Do not add a document database or separate search cluster in Release 1.

## Reason

Customers, contacts, sites, jobs, visits, schedules, notes, communications, items, and permissions have explicit relationships and integrity requirements. PostgreSQL also provides JSONB and full-text capabilities for the limited cases that need flexibility.
