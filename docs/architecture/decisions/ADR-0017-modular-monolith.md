# ADR-0017: Modular Monolith First

- Status: Accepted
- Date: 2026-09-18

## Decision

Deploy FieldOps as one modular Laravel application plus supporting worker/scheduler processes, PostgreSQL, and object storage. Do not begin with microservices.

## Reason

Release 1 has one owner, one organization, and coupled operational workflows. A modular monolith minimizes deployment and debugging overhead while preserving domain boundaries.
