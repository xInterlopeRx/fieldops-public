# Production deployment outline

This guide describes a self-managed Docker Compose deployment. Adapt host,
network, PostgreSQL, and storage choices to the environment. Do not use
production data or credentials in development or test environments.

## Before deployment

- Use a supported Linux host with maintained Docker Engine and Compose.
- Provision PostgreSQL separately, with TLS, restricted network access, a
  dedicated application role, and verified backups.
- Keep the application environment file and PostgreSQL CA bundle outside the
  source checkout with restrictive permissions. Never add secrets, certificates,
  customer data, or private attachments to Git or a container build context.
- Configure HTTPS at a trusted reverse proxy or load balancer. Compose binds
  Nginx to `127.0.0.1:8080` by default; PHP-FPM is not exposed on the host.
- Provision SMTP, storage, and any required external integrations separately.
  Complete their environment-specific setup and readiness checks before enabling
  integration writes.

## Install or upgrade

Use a release source archive and the exact matching version tag for both images.
The images are `ghcr.io/xinterloperx/fieldops-public-app:<version>` and
`ghcr.io/xinterloperx/fieldops-public-web:<version>`; Compose selects them from
`FIELDOPS_VERSION`.

Before an upgrade, verify a PostgreSQL backup and a backup of the named
`fieldops-private` volume. Keep the same Compose project name to reuse that
volume. The installer requires Docker Compose, an existing PostgreSQL database
and CA bundle, and a protected Laravel environment file; it does not provision
those services or credentials.

```bash
bash docker/install-fieldops.sh \
  --version vX.YY \
  --env-file /absolute/path/to/production.env \
  --pg-ca-file /absolute/path/to/postgresql-ca.pem \
  --project-name fieldops \
  --public-url https://fieldops.example.com \
  --confirm-ready
```

Replace `vX.YY` and the example HTTPS origin with the selected release and the
deployment's public origin. `--confirm-ready` confirms external prerequisites
and a verified backup, or that the database is intentionally new and empty. The
installer validates Compose, pulls the matching images, and refuses an
application image without the startup-migration entrypoint before changing
containers. It then starts the stack and checks local `/up`; `--public-url`
enables an additional HTTPS health check. A public-route check can be pinned to
current Cloudflare edge IPv4 addresses with repeatable `--route-ip` options when
local DNS is split; this is temporary and does not change DNS.

The first startup of the app's default PHP-FPM command applies pending Laravel
migrations before PHP-FPM starts. Worker, scheduler, and explicit Artisan
commands do not run migrations. Keep one app service instance so migrations
cannot race. This is a brief-interruption deployment, not a zero-downtime
upgrade. If startup or health checks fail, the installer leaves containers for
inspection and does not roll back images or database changes. Rollback requires
a previously tested, schema-compatible image; it does not reverse migrations or
restore the database.

The v1.01 images predate the startup-migration entrypoint and are intentionally
rejected by the installer. Do not use the install/upgrade command with v1.01;
retain that version's migration procedure until selecting a compatible release.

For an empty database, complete the first-admin bootstrap and required security
setup before inviting staff. Do not seed default staff credentials.

## Manual Compose operation

The same Compose file can be operated directly after provisioning and backing up
the required state:

```bash
export FIELDOPS_VERSION=vX.YY
export FIELDOPS_ENV_FILE=/absolute/path/to/production.env
export FIELDOPS_PG_CA_FILE=/absolute/path/to/postgresql-ca.pem

docker compose -f compose.prod.yml pull
docker compose -f compose.prod.yml up -d --remove-orphans
docker compose -f compose.prod.yml ps
docker compose -f compose.prod.yml logs --tail=100 web app worker scheduler
```

Confirm Nginx and PHP-FPM health, monitor worker queue/heartbeat and scheduler
logs, and maintain protected application, PostgreSQL, and storage backups. The
release workflow does not change GHCR package visibility; verify package access
for the deployment host before pulling images.
