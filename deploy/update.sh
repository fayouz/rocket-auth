#!/bin/sh
# Updates a Rocket Auth installed without Docker (git clone of the repository), in place:
#   new version checked out, dependencies installed, interface built, migrations applied, services restarted.
#
# Run by the scheduled task `php bin/console app:update:run` when an administrator clicks "Mettre à jour",
# as the user owning the files. It can also be run by hand: TARGET_VERSION=v0.7.0 deploy/update.sh
#
# Environment:
#   TARGET_VERSION           tag to install (default: the latest vX.Y.Z tag of the remote)
#   UPDATE_RESTART_COMMAND   command restarting the services, e.g. "sudo systemctl restart rocket-auth-front"
#   UPDATE_GIT_REMOTE        git remote to fetch (default: origin)
#   UPDATE_BUILD_FRONTEND    0 when the interface is deployed elsewhere (default: 1)
#   PHP_BIN, COMPOSER_BIN, NPM_BIN   binaries (default: php, composer, npm)
set -eu

cd "$(dirname "$0")/.."
ROOT=$(pwd)
PHP_BIN=${PHP_BIN:-php}
COMPOSER_BIN=${COMPOSER_BIN:-composer}
NPM_BIN=${NPM_BIN:-npm}
REMOTE=${UPDATE_GIT_REMOTE:-origin}
BUILD_FRONTEND=${UPDATE_BUILD_FRONTEND:-1}
export APP_ENV="${APP_ENV:-prod}"
export COMPOSER_NO_INTERACTION=1

step() { printf '\n==> %s\n' "$*"; }

install_backend() {
    (cd "$ROOT/backend" && "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-progress)
}

build_frontend() {
    [ "$BUILD_FRONTEND" = "1" ] || return 0
    (cd "$ROOT/frontend" && "$NPM_BIN" ci --no-audit --no-fund && NUXT_PUBLIC_APP_VERSION="$1" "$NPM_BIN" run build)
}

step "Rocket Auth: $(git describe --tags --always 2>/dev/null || echo unknown) in $ROOT"
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Local changes in the working tree: commit or discard them before updating." >&2
    git status --short >&2
    exit 1
fi

step "Fetching the versions from $REMOTE"
git fetch --tags --force --prune "$REMOTE"

TARGET=${TARGET_VERSION:-}
if [ -z "$TARGET" ]; then
    TARGET=$(git tag --list 'v*' --sort=-v:refname | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | head -n 1 || true)
fi
if ! printf '%s' "$TARGET" | grep -Eq '^v?[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$'; then
    echo "No version to install (TARGET_VERSION='${TARGET_VERSION:-}', no vX.Y.Z tag on $REMOTE)." >&2
    exit 1
fi
if ! git rev-parse -q --verify "refs/tags/$TARGET" >/dev/null; then
    echo "Tag $TARGET not found on $REMOTE." >&2
    exit 1
fi

PREVIOUS=$(git rev-parse HEAD)
PREVIOUS_VERSION=$(git describe --tags --always)
if [ "$(git rev-parse "refs/tags/$TARGET^{commit}")" = "$PREVIOUS" ]; then
    echo "$TARGET is already installed."
fi

# Until the migrations run, a failure puts the previous version back.
rollback() {
    status=$?
    [ "$status" -eq 0 ] && return
    step "Failure: going back to $PREVIOUS_VERSION"
    git -c advice.detachedHead=false checkout --force "$PREVIOUS" && install_backend && build_frontend "$PREVIOUS_VERSION" \
        && echo "$PREVIOUS_VERSION restored." || echo "Could not restore $PREVIOUS_VERSION: fix the installation by hand." >&2
    exit "$status"
}
trap rollback EXIT

step "Installing $TARGET"
git -c advice.detachedHead=false checkout --force "refs/tags/$TARGET"
VERSION=$(git describe --tags --always)

step "API dependencies (composer)"
install_backend

if [ "$BUILD_FRONTEND" = "1" ]; then
    step "Interface (npm ci, build)"
    build_frontend "$VERSION"
fi

trap - EXIT

step "Database migrations"
(cd backend && "$PHP_BIN" bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration)

step "Cache"
(cd backend && "$PHP_BIN" bin/console cache:clear && printf '%s\n' "$VERSION" > VERSION)

step "Restarting the services"
# Sending workers stop after their current message; their supervisor (systemd, supervisord) restarts them.
(cd backend && "$PHP_BIN" bin/console messenger:stop-workers)
if [ -n "${UPDATE_RESTART_COMMAND:-}" ]; then
    sh -c "$UPDATE_RESTART_COMMAND"
else
    echo "UPDATE_RESTART_COMMAND is empty: restart the API (PHP) and the interface (node) to load $VERSION."
fi

step "Rocket Auth $VERSION installed"
