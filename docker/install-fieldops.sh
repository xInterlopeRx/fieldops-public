#!/usr/bin/env bash

set -Eeuo pipefail

usage() {
    cat <<'USAGE'
Install or upgrade a FieldOps Compose deployment using published images.

Usage:
  docker/install-fieldops.sh \
    --version vX.YY \
    --env-file /absolute/path/to/production.env \
    --pg-ca-file /absolute/path/to/postgresql-ca.pem \
    [--project-name fieldops] \
    [--public-url https://fieldops.example.com] \
    [--route-ip CLOUDFLARE_EDGE_IP ...] \
    --confirm-ready

Options:
  --version TAG       Exact immutable release tag; floating tags are rejected.
  --env-file PATH     Protected Laravel environment file (must not be
                      group/world-readable).
  --pg-ca-file PATH   PostgreSQL CA certificate mounted read-only by Compose.
  --project-name NAME Stable Compose project name; keep the same name on upgrades
                      so the existing private-attachment volume is reused.
  --public-url URL    Optional HTTPS origin to check at /up after local health.
  --route-ip IP       Repeatable IPv4 address for curl --resolve when local or
                      split DNS does not resolve the public hostname correctly.
                      This is only a temporary check; it does not change DNS.
  --confirm-ready     Confirm PostgreSQL and external services are provisioned,
                      and a verified backup exists for an upgrade (or the
                      database is intentionally new/empty). Startup migrations
                      run when the app container starts.
  --dry-run           Validate inputs and rendered Compose config without pulling
                      images, changing containers, or running migrations.
  --help              Show this help.

This script does not provision PostgreSQL, TLS, Cloudflare, object storage,
SMTP, or Microsoft/QuickBooks credentials. Run it from a FieldOps release source
archive on a host with Docker Compose already installed and configured.
USAGE
}

fail() {
    printf 'FieldOps install: %s\n' "$1" >&2
    exit 1
}

valid_ipv4() {
    [[ "$1" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || return 1

    local -a octets
    IFS='.' read -r -a octets <<< "$1"
    for octet in "${octets[@]}"; do
        ((10#$octet <= 255)) || return 1
    done
}

version=''
env_file=''
pg_ca_file=''
project_name='fieldops'
public_url=''
public_host=''
public_port=''
route_ips=()
confirm_ready=false
dry_run=false

while (($# > 0)); do
    case "$1" in
        --version|--env-file|--pg-ca-file|--project-name|--public-url|--route-ip)
            (($# >= 2)) || fail "Missing value for $1."
            option=$1
            value=$2
            shift 2
            case "$option" in
                --version) version=$value ;;
                --env-file) env_file=$value ;;
                --pg-ca-file) pg_ca_file=$value ;;
                --project-name) project_name=$value ;;
                --public-url) public_url=$value ;;
                --route-ip) route_ips+=("$value") ;;
            esac
            ;;
        --confirm-ready)
            confirm_ready=true
            shift
            ;;
        --dry-run)
            dry_run=true
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            fail "Unknown option: $1 (use --help for usage)."
            ;;
    esac
done

[[ "$version" =~ ^v[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || \
    fail 'Provide an exact version tag such as v1.02; latest and commit tags are not accepted.'
[[ "$env_file" = /* ]] || fail '--env-file must be an absolute path.'
[[ "$pg_ca_file" = /* ]] || fail '--pg-ca-file must be an absolute path.'
[[ "$project_name" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || \
    fail '--project-name must start with a lowercase letter or digit and contain only lowercase letters, digits, _ or -.'

if [[ -n "$public_url" ]]; then
    if [[ "$public_url" =~ ^https://([A-Za-z0-9.-]+)(:([0-9]{1,5}))?/?$ ]]; then
        public_host="${BASH_REMATCH[1]}"
        public_port="${BASH_REMATCH[3]:-443}"
        if ((10#$public_port < 1 || 10#$public_port > 65535)); then
            fail '--public-url port must be between 1 and 65535.'
        fi
    else
        fail '--public-url must be an HTTPS origin without a path, query, or credentials.'
    fi
elif ((${#route_ips[@]} > 0)); then
    fail '--route-ip can only be used together with --public-url.'
fi

for route_ip in "${route_ips[@]}"; do
    valid_ipv4 "$route_ip" || fail "Invalid IPv4 address for --route-ip: $route_ip."
done

if [[ "$dry_run" == false && "$confirm_ready" != true ]]; then
    fail 'Before starting containers, pass --confirm-ready after provisioning services and verifying backups (or confirming the database is new/empty).'
fi

[[ -f "$env_file" && -r "$env_file" ]] || fail 'The environment file must exist and be readable by this user.'
[[ -f "$pg_ca_file" && -r "$pg_ca_file" ]] || fail 'The PostgreSQL CA file must exist and be readable by this user.'

env_mode=$(stat -c '%a' -- "$env_file") || fail 'Could not inspect environment-file permissions.'
if (( (8#$env_mode & 077) != 0 )); then
    fail "Environment file permissions are too broad (mode $env_mode); remove group and other access (for example chmod 0600)."
fi

script_directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
repo_root=$(cd -- "$script_directory/.." && pwd -P)
compose_file="$repo_root/compose.prod.yml"
[[ -f "$compose_file" ]] || fail "Production Compose file not found at $compose_file."

command -v docker >/dev/null 2>&1 || fail 'Docker is not installed or is not on PATH.'
docker compose version >/dev/null 2>&1 || fail 'The Docker Compose plugin is unavailable.'
docker info >/dev/null 2>&1 || fail 'Docker is unavailable to this user; start/configure the daemon or rootless Docker first.'
if [[ -n "$public_url" ]]; then
    command -v curl >/dev/null 2>&1 || fail 'curl is required when --public-url is specified.'
fi

export FIELDOPS_VERSION="$version"
export FIELDOPS_ENV_FILE="$env_file"
export FIELDOPS_PG_CA_FILE="$pg_ca_file"

compose=(docker compose --project-name "$project_name" --file "$compose_file")
"${compose[@]}" config --quiet || fail 'Compose configuration validation failed; no containers were changed.'

printf 'Validated FieldOps %s deployment (Compose project: %s).\n' "$version" "$project_name"

if [[ "$dry_run" == true ]]; then
    printf 'Dry run passed; no images were pulled and no containers or database were changed.\n'
    exit 0
fi

printf 'Pulling the selected release images...\n'
"${compose[@]}" pull

app_image="ghcr.io/xinterloperx/fieldops-public-app:$version"
app_entrypoint=$(docker image inspect --format '{{json .Config.Entrypoint}}' "$app_image") || \
    fail 'Could not inspect the application image after pulling it.'
case "$app_entrypoint" in
    *fieldops-entrypoint*) ;;
    *)
        fail "Image $app_image does not contain the startup-migration entrypoint; no containers were changed. Keep the current deployment in place and select a compatible release."
        ;;
esac

printf 'Starting FieldOps; app startup will apply pending migrations.\n'
"${compose[@]}" up -d --remove-orphans

local_healthy=false
for ((attempt = 1; attempt <= 60; attempt++)); do
    if "${compose[@]}" exec --no-TTY web wget -q --spider http://127.0.0.1/up >/dev/null 2>&1; then
        local_healthy=true
        break
    fi
    sleep 2
done

if [[ "$local_healthy" != true ]]; then
    "${compose[@]}" ps || true
    fail 'The local web health endpoint did not become healthy within 120 seconds. Containers were left running for inspection; check `docker compose logs` before retrying.'
fi

printf 'Local application health check passed (/up).\n'

if [[ -n "$public_url" ]]; then
    public_status=''
    if ((${#route_ips[@]} > 0)); then
        for route_ip in "${route_ips[@]}"; do
            if candidate_status=$(curl --silent --location --max-time 20 \
                --resolve "$public_host:$public_port:$route_ip" \
                --output /dev/null --write-out '%{http_code}' \
                "${public_url%/}/up" 2>/dev/null); then
                public_status=$candidate_status
                [[ "$public_status" == 200 ]] && break
            fi
        done
    else
        public_status=$(curl --silent --show-error --location --max-time 20 \
            --output /dev/null --write-out '%{http_code}' "${public_url%/}/up") || \
            fail "Local services are healthy, but the public route could not be reached at ${public_url%/}/up. Containers remain running; check DNS, Cloudflare, and the reverse-proxy route."
    fi
    [[ "$public_status" == 200 ]] || \
        fail "Local services are healthy, but the public route returned HTTP ${public_status:-000} at ${public_url%/}/up. Containers remain running; check the supplied route IPs, DNS, Cloudflare, and the reverse-proxy route."
    printf 'Public route health check passed (HTTP 200).\n'
else
    printf 'Public route was not checked; pass --public-url https://your-hostname to verify it.\n'
fi

printf 'FieldOps %s is installed and healthy.\n' "$version"
