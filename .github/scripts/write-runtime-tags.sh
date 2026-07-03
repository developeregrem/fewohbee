#!/usr/bin/env bash
set -euo pipefail

image="$1"
version="$2"
suffix="${3:-}"

major="${version%%.*}"
minor="${version%.*}"

# Runtime refreshes bypass docker/metadata-action because they checkout a tag
# while running from a schedule/manual event. Recreate the same release aliases
# metadata-action would publish for a real vX.Y.Z tag push.
{
    echo "tags<<EOF"
    echo "${image}:${version}${suffix}"
    echo "${image}:${minor}${suffix}"
    echo "${image}:${major}${suffix}"
    echo "${image}:latest${suffix}"
    echo "EOF"
} >> "${GITHUB_OUTPUT}"
