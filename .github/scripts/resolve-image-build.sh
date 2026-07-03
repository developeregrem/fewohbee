#!/usr/bin/env bash
set -euo pipefail

release_window_days=7
checkout_ref="${GITHUB_REF}"
release_version=""
run_build="true"
runtime_refresh="false"

latest_stable_release_tag() {
    # Runtime refreshes rebuild the newest stable release, not whatever branch
    # happens to be current on the scheduler's default branch.
    git tag --list 'v*' |
        grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' |
        sort -V |
        tail -n 1 || true
}

latest_package_tag_timestamp() {
    local package_name="$1"
    local version="$2"

    export GH_TOKEN="${GH_TOKEN:-${GITHUB_TOKEN:-}}"
    if [[ -z "${GH_TOKEN}" ]]; then
        echo "GH_TOKEN/GITHUB_TOKEN is required for package lookup." >&2
        exit 1
    fi

    # GHCR stores package versions as manifests with a list of tags. A release
    # may appear as either "4.9.0" (Docker tag) or "v4.9.0" (older/manual tag
    # shape), so accept both and use the newest matching manifest timestamp.
    gh api --paginate "/users/${IMAGE_OWNER}/packages/container/${package_name}/versions?per_page=100" \
        --jq ".[] | (.metadata.container.tags // []) as \$tags | select((\$tags | index(\"${version}\")) or (\$tags | index(\"v${version}\"))) | .updated_at" |
        sort |
        tail -n 1
}

if [[ "${GITHUB_EVENT_NAME}" == "schedule" || "${GITHUB_EVENT_NAME}" == "workflow_dispatch" ]]; then
    # Scheduled/manual runs are runtime refreshes: rebuild the latest release
    # with current base images while keeping the application code at that tag.
    runtime_refresh="true"
    latest_tag="$(latest_stable_release_tag)"

    if [[ -z "${latest_tag}" ]]; then
        echo "No stable vX.Y.Z release tag found." >&2
        exit 1
    fi

    checkout_ref="${latest_tag}"
    release_version="${latest_tag#v}"
    echo "Runtime refresh source: ${latest_tag} (image tag: ${release_version})"

    if [[ "${GITHUB_EVENT_NAME}" == "schedule" ]]; then
        # Manual dispatch always rebuilds. Scheduled runs skip when the current
        # release image was already published inside the configured window.
        latest_tag_timestamp="$(latest_package_tag_timestamp "fewohbee-phpfpm" "${release_version}")"

        if [[ -n "${latest_tag_timestamp}" ]]; then
            latest_tag_epoch="$(date -u -d "${latest_tag_timestamp}" +%s)"
            cutoff_epoch="$(date -u -d "${release_window_days} days ago" +%s)"

            if (( latest_tag_epoch > cutoff_epoch )); then
                echo "Image tag ${release_version} was updated at ${latest_tag_timestamp} (< ${release_window_days} days). Skipping scheduled rebuild."
                run_build="false"
            else
                echo "Image tag ${release_version} was last updated at ${latest_tag_timestamp}. Rebuilding."
            fi
        else
            echo "Image tag ${release_version} was not found in GHCR. Rebuilding."
        fi
    fi
fi

{
    echo "checkout_ref=${checkout_ref}"
    echo "release_version=${release_version}"
    echo "run_build=${run_build}"
    echo "runtime_refresh=${runtime_refresh}"
} >> "${GITHUB_OUTPUT}"
