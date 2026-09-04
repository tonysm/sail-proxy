#!/usr/bin/env bash
# Usage: scripts/release.sh VERSION [--dry-run]
#   VERSION: semver (0.3.0, 0.3.0-rc.1); a v prefix on the argument is ignored,
#   the tag is always bare (0.3.0)
#
# Validates, runs the checks, rebuilds the PHAR with the release version baked
# in, commits it, tags, and pushes to trigger the release workflow. Set
# DRY_RUN=1 (or pass --dry-run) to run the checks only, changing nothing.

set -euo pipefail

# --- Colors ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
RESET='\033[0m'

info() { echo -e "${GREEN}==>${RESET} ${BOLD}$*${RESET}"; }
warn() { echo -e "${YELLOW}WARNING:${RESET} $*"; }
error() { echo -e "${RED}ERROR:${RESET} $*" >&2; }
die() {
    error "$@"
    exit 1
}

cd "$(git rev-parse --show-toplevel)"

# --- Args ---
VERSION="${1:-${VERSION:-}}"
DRY_RUN="${DRY_RUN:-0}"
if [[ "$*" == *"--dry-run"* ]]; then
    DRY_RUN=1
fi
case "$DRY_RUN" in
1 | true) DRY_RUN=1 ;;
*) DRY_RUN=0 ;;
esac

if [[ -z "$VERSION" || "$VERSION" == "dev" ]]; then
    echo "Usage: scripts/release.sh VERSION [--dry-run]"
    exit 1
fi

# --- Normalise and validate the version ---
VERSION="${VERSION#v}"
# Leading zeros are refused (semver forbids them, and bash arithmetic would
# read 08 as broken octal in the version comparison below).
if [[ ! "$VERSION" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[a-zA-Z0-9.]+)?$ ]]; then
    die "Invalid version '${VERSION}' (expected X.Y.Z or X.Y.Z-suffix without leading zeros, optionally v-prefixed)"
fi

TAG="${VERSION}"
PRERELEASE=0
if [[ "$VERSION" == *-* ]]; then
    PRERELEASE=1
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
    info "Dry run — no builds, commits, tags or pushes"
    echo ""
fi

# --- Verify branch ---
DEFAULT_BRANCH=$(git remote show origin 2>/dev/null | sed -n 's/.*HEAD branch: //p')
DEFAULT_BRANCH="${DEFAULT_BRANCH:-main}"
BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [[ "$BRANCH" != "$DEFAULT_BRANCH" ]]; then
    die "Not on $DEFAULT_BRANCH (currently on $BRANCH)"
fi

# --- Verify clean tree ---
if [[ -n "$(git status --porcelain)" ]]; then
    die "Working tree is not clean. Commit or stash changes first."
fi

# From here on the tree is known clean, so on failure the built archive can be
# checked out to undo a half-made release. Checkout from HEAD, not the index:
# once the build commit lands, HEAD contains the archive and the checkout is a
# no-op.
restore_build() {
    if [[ "${1:-1}" -ne 0 ]]; then
        git checkout --quiet HEAD -- builds/sail-proxy || true
    fi
}
trap 'restore_build "$?"' EXIT

# --- Verify synced with remote ---
git fetch origin "$DEFAULT_BRANCH" --quiet
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse "origin/$DEFAULT_BRANCH")
if [[ "$LOCAL" != "$REMOTE" ]]; then
    die "Local $DEFAULT_BRANCH (${LOCAL:0:7}) is not synced with origin (${REMOTE:0:7}). Pull or push first."
fi

# --- Verify dependencies are installed ---
if [[ ! -x vendor/bin/pest ]]; then
    die "vendor/bin/pest not found. Run 'composer install' first."
fi

# --- Validate the tag before anything mutates ---
git fetch origin --tags --quiet
if git rev-parse -q --verify "refs/tags/${TAG}^{commit}" >/dev/null; then
    die "Tag $TAG already exists. If its release failed, re-run the failed workflow job on that tag; otherwise choose a new version."
fi

# A stable release that is not newer than the latest stable tag would roll the
# committed archive on main backwards. Compare numerically, never lexically.
# Force base 10: the requested version is validated against leading zeros
# above, but an already-pushed tag like v1.09.0 is not, and bash would
# otherwise read its fields as octal.
version_lt() {
    local -a a b
    local i
    IFS=. read -r -a a <<<"${1#v}"
    IFS=. read -r -a b <<<"${2#v}"
    for i in 0 1 2; do
        if ((10#${a[i]:-0} < 10#${b[i]:-0})); then return 0; fi
        if ((10#${a[i]:-0} > 10#${b[i]:-0})); then return 1; fi
    done
    return 1
}

if [[ "$PRERELEASE" -eq 0 ]]; then
    # Legacy tags are unprefixed (0.2.0), current ones are v-prefixed — match both.
    LATEST_STABLE=$(git tag --list --sort=-version:refname | grep -E '^v?[0-9]+\.[0-9]+\.[0-9]+$' | head -1 || true)
    if [[ -n "$LATEST_STABLE" && "$LATEST_STABLE" != "$TAG" ]] && ! version_lt "$LATEST_STABLE" "$TAG"; then
        die "Version $VERSION is not newer than the latest stable release ${LATEST_STABLE#v}. Stable releases cannot go backwards."
    fi
fi

# --- Run pre-flight checks ---
info "Running release checks"
info "  Branch: $BRANCH"
info "  Commit: ${LOCAL:0:7}"
info "  Tag:    $TAG"
echo ""

vendor/bin/pint --test
vendor/bin/pest

if [[ "$DRY_RUN" -eq 1 ]]; then
    echo ""
    info "Dry run complete. Nothing was built, committed or tagged."
    exit 0
fi

# --- Rebuild the archive with the release version baked in ---
# The archive is what Composer serves from the tag and what the release
# workflow attaches for mise/curl installs, so it has to be rebuilt and
# committed before the tag is created.
info "Building $TAG"
php sail-proxy app:build sail-proxy --build-version="$TAG"

chmod +x builds/sail-proxy
BUILT="$(./builds/sail-proxy --version)"
if ! grep -qF "$TAG" <<<"$BUILT"; then
    die "The fresh build reports '$BUILT', not '$TAG'. Refusing to release a mislabeled archive."
fi

# --- Commit, tag and push ---
# The tag was validated against origin before anything mutated, but that check
# has a window: another push can take the tag or move main while the build
# runs. Pushing main on its own and discovering the collision afterwards would
# leave the release commit dangling, so the two refs go up in one --atomic
# push — an existing remote tag or a non-fast-forward main rejects both.
PREP_BASE="$LOCAL"
git add builds/sail-proxy
if ! git diff --cached --quiet; then
    git commit -m "Build $TAG"
    LOCAL=$(git rev-parse HEAD)
    info "Committed the build (${LOCAL:0:7})"
else
    warn "The rebuilt archive is identical to the committed one — no build commit needed."
fi

info "Creating tag $TAG"
git tag -a "$TAG" -m "Release $TAG"

# On rejection, put the clone back where a re-run can start: drop the local
# build commit and tag so the next attempt (under a new version, if the tag
# was taken) is not blocked by an unpushed main or a stale local tag.
PUSH_REFS=("$TAG")
if [[ "$LOCAL" != "$PREP_BASE" ]]; then
    PUSH_REFS=("$DEFAULT_BRANCH" "$TAG")
fi
info "Pushing ${PUSH_REFS[*]} to origin"
if ! git push --atomic origin "${PUSH_REFS[@]}"; then
    git tag -d "$TAG" >/dev/null
    git reset --quiet --hard "$PREP_BASE"
    git fetch origin --tags --quiet
    die "Push rejected: origin changed while the release was being prepared (another push of $TAG or $DEFAULT_BRANCH). Nothing was pushed; $DEFAULT_BRANCH and the tag were reset locally. Re-run after pulling — or choose a new version if $TAG is now taken."
fi

REPO=$(git remote get-url origin | sed -E 's#(git@github\.com:|https://github\.com/)##; s#\.git$##')

echo ""
info "Release $TAG triggered"
echo ""
echo "  Actions: https://github.com/$REPO/actions"
echo "  Release: https://github.com/$REPO/releases/tag/$TAG"
