# FieldOps

FieldOps is a technician-first application for scheduling work and organizing customer, site, job, visit, and service history.

This repository contains the public source snapshot. Development work is maintained separately; release snapshots are exported to this repository as new commits rather than copying private development history.

## Requirements

- PHP 8.5 or later with the extensions listed in the CI workflow
- Composer 2
- Node.js 22 and npm
- PostgreSQL for deployments (SQLite is configured for the automated test suite)

## Local development

```sh
cp .env.example .env
composer install
php artisan key:generate
npm ci
php artisan migrate
npm run build
```

Configure a local PostgreSQL database in `.env` before migrating. The application does not seed staff or business records by default; use the first-admin setup flow for a new installation.

Run the automated checks with:

```sh
composer test
npm run build
```

## Deployment

See [the deployment guide](docs/operations/DEPLOYMENT.md) for a minimal production outline. Review the security guidance in that document before exposing an instance to a network.

## Product and architecture

- [Product overview](docs/product/PRODUCT.md)
- [Release 1 scope](docs/product/RELEASE-1.md)
- [Architecture](docs/architecture/ARCHITECTURE.md)
- [Data model](docs/architecture/DATA-MODEL.md)
- [Architecture decisions](docs/architecture/decisions/)

Development notes, integration research, operational command histories, and private reference material are maintained separately and are not part of this public snapshot.

The public update-workflow manual is planned for `docs/release/UPDATE-WORKFLOW.md`; it is not included yet.

## Releases

The release workflow runs CI for pull requests to `main`, pushes to `main`, and version tags. Container publication is limited to version tags that point to a commit in `main` history. Published GHCR packages use the `fieldops-public-app` and `fieldops-public-web` names; package visibility is not changed by the workflow.
