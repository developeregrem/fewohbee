#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${TAGS:-}" ]]; then
    echo "TAGS is required." >&2
    exit 1
fi

# nginx has no debug-specific build. These aliases make a single
# FEWOHBEE_VERSION=...-debug value work for all docker-compose services while
# still pointing nginx at the standard image contents.
{
    echo "tags<<EOF"
    printf '%s\n' "${TAGS}"
    printf '%s\n' "${TAGS}" | sed 's/$/-debug/'
    echo "EOF"
} >> "${GITHUB_OUTPUT}"
