# ADR-0020: Fortify for Staff Two-Factor Authentication

- Status: Accepted
- Date: 2026-09-26
- Superseded in part: ADR-0038 makes staff TOTP mandatory and adds a 14-day trusted-device policy.

## Context

FieldOps needs stronger staff account protection while retaining its existing
Vue 3, TypeScript, and Inertia interface and invitation-based account creation.
The application currently uses Laravel Breeze-generated session authentication
routes. Implementing TOTP and recovery-code cryptography in FieldOps would
duplicate maintained Laravel ecosystem functionality.

## Decision

- Use Laravel Fortify as the backend for staff TOTP enrollment, login challenges,
  and recovery codes.
- Keep the existing Vue/Inertia interface and staff invitation workflow.
- Make TOTP enrollment optional initially; require password confirmation before
  enrollment, disabling, and recovery-code management.
- Disable Fortify public registration and passkey features. Do not add social
  sign-in.
- Permit authentication only for active staff accounts and rate-limit
  two-factor challenges.
- Keep Fortify secrets and recovery codes encrypted at rest and excluded from
  serialized user data.

## Consequences

- The Fortify login pipeline replaces the existing Breeze login POST handler;
  the application retains its Inertia login screen and supplies an Inertia
  challenge screen.
- A migration adds nullable Fortify two-factor columns to `users`.
- Staff may enroll from Profile settings and must save their one-time recovery
  codes securely.
- Fortify is an additional runtime dependency. Its registration routes remain
  disabled, so invitation-only provisioning is unchanged.
- The initial optional-enrollment phase was superseded by ADR-0038, which requires confirmed TOTP before normal business access and adds a 14-day trusted-device token.
