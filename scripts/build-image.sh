#!/usr/bin/env bash
#
# Sanctioned release build for buddy images.
#
# Encodes the procedure from docs/plans/2026-07-22-performance-levers.md so it
# does not have to be remembered: build from a CLEAN WORKTREE at a reviewed SHA,
# from the production Dockerfiles only, tagged with that SHA.
#
#   buddy:<sha>-octane   API      docker/production/Dockerfile.octane  (FrankenPHP, :8080)
#   buddy:<sha>          fpm      docker/production/Dockerfile         (worker, migrate job, rollback)
#
# docker/Dockerfile is the local-dev image and is refused here. Building it for a
# deployable tag is what caused the 2026-07-27 outage: it serves on :8000 while
# ingress targets :8080, so the deploy hangs rather than failing.
#
# Usage:
#   scripts/build-image.sh <git-ref> [api|fpm|both]
#
# Example:
#   scripts/build-image.sh b963dc7 api
#
set -euo pipefail

# The old default, acrbuddydevzywxldjeqo3sa, lived in the decommissioned
# subscription and no longer exists, so the unset case failed at the push
# rather than at the argument check. Buddy now runs in the credited
# subscription and this is the registry its container app pulls from.
REGISTRY="${BUDDY_ACR:-acrbuddycreditoerh7kdnhtzo6}"
REF="${1:-}"
TARGET="${2:-both}"

die() { echo "error: $*" >&2; exit 1; }

[ -n "$REF" ] || die "usage: scripts/build-image.sh <git-ref> [api|fpm|both]"

case "$TARGET" in
    api|fpm|both) ;;
    *) die "target must be api, fpm or both (got '$TARGET')" ;;
esac

command -v az >/dev/null 2>&1 || die "az CLI not found"
git rev-parse --git-dir >/dev/null 2>&1 || die "not inside a git repository"

SHA="$(git rev-parse --short=7 "$REF^{commit}" 2>/dev/null)" || die "unknown git ref: $REF"

# A release artifact must be reproducible from the reviewed commit alone, so the
# build context is a detached worktree rather than the working tree, which may
# carry uncommitted edits, local .env files or test-run artifacts.
WORKTREE="$(mktemp -d "${TMPDIR:-/tmp}/buddy-build-${SHA}.XXXXXX")"
cleanup() { git worktree remove "$WORKTREE" --force >/dev/null 2>&1 || true; }
trap cleanup EXIT

git worktree add --detach -q "$WORKTREE" "$SHA" || die "could not create a clean worktree at $SHA"
echo "built from clean worktree at $SHA"

build() {
    dockerfile="$1"
    tag="$2"

    # The guardrail: a deployable tag may only ever be produced from a
    # production Dockerfile. Anything else is the mistake this script exists
    # to prevent, so fail before spending a build.
    case "$dockerfile" in
        docker/production/*) ;;
        *) die "refusing to build deployable tag '$tag' from '$dockerfile'. Release images come from docker/production/ only." ;;
    esac

    [ -f "$WORKTREE/$dockerfile" ] || die "$dockerfile does not exist at $SHA"

    echo "building $tag from $dockerfile"
    ( cd "$WORKTREE" && az acr build --registry "$REGISTRY" --image "$tag" -f "$dockerfile" . )
}

if [ "$TARGET" = "api" ] || [ "$TARGET" = "both" ]; then
    build "docker/production/Dockerfile.octane" "buddy:${SHA}-octane"
fi

if [ "$TARGET" = "fpm" ] || [ "$TARGET" = "both" ]; then
    build "docker/production/Dockerfile" "buddy:${SHA}"
fi

cat <<EOF

built. next steps, in this order.

These named the rg-buddy-dev estate until 2026-09-05. That resource group was
decommissioned with the old subscription; buddy now runs in rg-buddy-credit in
the credited subscription, and both subscriptions are called "Azure subscription
1", so the GUID is the only thing that distinguishes them. Pass it explicitly.

  SUB=e7e7a0f4-2689-47ed-b7e0-ce68d8394cc4

  1. migrations do NOT run at container start. Repoint and run the job:
       az containerapp job update -n caj-buddy-migrate-credit -g rg-buddy-credit --subscription \$SUB \\
         --image ${REGISTRY}.azurecr.io/buddy:${SHA}
       az containerapp job start  -n caj-buddy-migrate-credit -g rg-buddy-credit --subscription \$SUB

  2. deploy the API at ZERO traffic and probe the per-revision FQDN first:
       az containerapp update -n ca-buddy-api-credit -g rg-buddy-credit --subscription \$SUB \\
         --image ${REGISTRY}.azurecr.io/buddy:${SHA}-octane --revision-suffix oct-${SHA}
       curl https://ca-buddy-api-credit--oct-${SHA}.wonderfuldune-56e52a9b.northeurope.azurecontainerapps.io/api/health

  3. only then shift traffic, keeping the previous revision for rollback:
       az containerapp ingress traffic set -n ca-buddy-api-credit -g rg-buddy-credit --subscription \$SUB \\
         --revision-weight ca-buddy-api-credit--oct-${SHA}=100 <previous>=0

  4. the worker and the remaining jobs run the fpm tag, so they need repointing too:
       az containerapp update -n ca-buddy-worker-credit -g rg-buddy-credit --subscription \$SUB \\
         --image ${REGISTRY}.azurecr.io/buddy:${SHA}
       for j in caj-buddy-outbox-credit caj-buddy-feedback-health-credit; do
         az containerapp job update -n \$j -g rg-buddy-credit --subscription \$SUB \\
           --image ${REGISTRY}.azurecr.io/buddy:${SHA}
       done

  Never scale an app to zero or delete a revision set while the Microsoft for
  Startups milestone window is open (to roughly 2026-10-10): a counted workload
  dropping below the spend floor for a single day resets the tranche.
EOF
