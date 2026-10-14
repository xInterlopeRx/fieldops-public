# Production deployment outline

This guide is a starting point for a self-managed deployment. Adapt it to the host, network, PostgreSQL service, and object-storage provider you operate. Do not use production data or credentials in development or test environments.

## Before deployment

- Use a supported Linux host and a maintained Docker Engine / Compose installation.
- Provision PostgreSQL separately with TLS, backups, restricted network access, and a dedicated application role.
- Provide durable private attachment storage and a tested backup/restore procedure.
- Configure SMTP and any optional external integrations with dedicated, least-privilege credentials.
- Store secrets outside the repository with access limited to the application operators.
- Terminate HTTPS at a trusted reverse proxy or load balancer; do not expose the application over plain HTTP to untrusted networks.

## Container deployment

Use `compose.prod.yml` with images published for a tested version tag. Set `FIELDOPS_APP_IMAGE` and `FIELDOPS_WEB_IMAGE` to the matching `fieldops-public-app` and `fieldops-public-web` GHCR image references. Also set `FIELDOPS_ENV_FILE` and `FIELDOPS_PG_CA_FILE` in the deployment environment.

Create the environment file and PostgreSQL CA bundle outside the repository with restrictive permissions. Set `APP_ENV=production`, `APP_DEBUG=false`, a generated `APP_KEY`, the PostgreSQL connection details, and appropriate mail, queue, session, cache, and storage settings. Never add the environment file or certificates to Git or a container build context.

Pull the selected images, run database migrations as a controlled deployment step, and start the app, web, queue worker, and scheduler services. Keep the web service behind HTTPS and restrict administrative access. Monitor application logs, queue health, database capacity, and backup status.

## First startup and operations

For an empty database, complete the first-admin bootstrap and required security setup before inviting staff. Do not seed default staff credentials. Keep application, database, storage, and integration backups protected and periodically test restoring them.

The release workflow does not change GHCR package visibility. Review package visibility and access controls separately before distributing images.
