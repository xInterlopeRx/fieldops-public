#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
output="$repo_root/storage/app/private/fieldops-bws-helper"

if [[ -x "$output" && "${1:-}" != "--force" ]]; then
    rebuild=false
    for source in \
        "$repo_root/docker/php/Dockerfile" \
        "$repo_root/docker/bws-helper/Cargo.toml" \
        "$repo_root/docker/bws-helper/Cargo.lock" \
        "$repo_root/docker/bws-helper/src/main.rs"; do
        if [[ "$source" -nt "$output" ]]; then
            rebuild=true
            break
        fi
    done

    if [[ "$rebuild" == false ]]; then
        printf 'Host BWS helper is up to date at %s\n' "$output"
        exit 0
    fi
fi

temporary_root="${TMPDIR:-/tmp/opencode}"
build_output=""
for candidate_root in "$temporary_root" /var/tmp; do
    mkdir -p "$candidate_root" 2>/dev/null || continue
    if build_output="$(mktemp -d "$candidate_root/fieldops-bws-helper.XXXXXX" 2>/dev/null)"; then
        break
    fi
done

if [[ -z "$build_output" ]]; then
    echo "No writable temporary directory is available for the BWS helper build." >&2
    exit 1
fi

trap 'rm -rf -- "$build_output"' EXIT

docker buildx build \
    --file "$repo_root/docker/php/Dockerfile" \
    --target bws-helper-export \
    --output "type=local,dest=$build_output" \
    "$repo_root"

helper_binary="$build_output/fieldops-bws-helper"
if [[ ! -x "$helper_binary" ]]; then
    echo "The Docker build did not produce the expected BWS helper." >&2
    exit 1
fi

install -D -m 0755 "$helper_binary" "$output"

printf 'Built host BWS helper at %s\n' "$output"
