# ADR-0016: Laravel, Vue, and PostgreSQL

- Status: Accepted
- Date: 2026-09-18

## Context

The owner has prior Vue and Laravel experience, recently refactored the the organization website as a Node application, and already has a Laravel boilerplate with authentication and other foundations. Release speed and maintainability are more valuable than adding a new frontend framework.

## Decision

Use Laravel for the core application, Vue 3 with TypeScript for the interactive interface, and PostgreSQL for relational business data.

## Consequences

- Existing experience and boilerplate reduce startup work.
- PostgreSQL models strongly related operational data and supports initial global search.
- Python remains available for later specialized AI or automation services, but is not required for the first core application.
- The inspected application uses Laravel 13, PHP 8.5, Vue 3 with TypeScript and Inertia.js 2, Vite 8, Node 22, and PostgreSQL.
