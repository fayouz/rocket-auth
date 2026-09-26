#!/usr/bin/env bash
# Starts the demo in a GitHub Codespace (also works locally, with localhost URLs).
set -euo pipefail
cd "$(dirname "$0")/../.."

APP_NAME="Rocket Auth"
FRONT_PORT=3100
DOCS_PORT=3101
REPOSITORY=fayouz/rocket-auth

if [ -n "${CODESPACE_NAME:-}" ]; then
  public_url() { echo "https://${CODESPACE_NAME}-$1.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"; }
  FRONT_URL="$(public_url $FRONT_PORT)"
  # Use links that are valid even when Codespaces has not forwarded Docker port $DOCS_PORT.
  export DEMO_DOCS_URL="https://github.com/${REPOSITORY}/tree/develop/docs/content"
  export DEMO_CHANGELOG_URL="https://github.com/${REPOSITORY}/blob/develop/CHANGELOG.md"
else
  FRONT_URL=http://localhost:$FRONT_PORT
  export DEMO_DOCS_URL=http://localhost:$DOCS_PORT
  export DEMO_CHANGELOG_URL=http://localhost:$DOCS_PORT/changelog
fi

docker compose -f compose.yaml -f compose.demo.yaml up -d --build

if [ -n "${CODESPACE_NAME:-}" ]; then
  # Codespaces can discover Docker-published ports a few seconds after Compose starts.
  for i in $(seq 1 30); do
    if curl --fail --silent --output /dev/null "http://localhost:$DOCS_PORT/" \
      && gh codespace ports --json sourcePort -c "$CODESPACE_NAME" \
        --jq '.[].sourcePort' | grep -qx "$DOCS_PORT"; then
      break
    fi
    sleep 2
  done

  if gh codespace ports visibility "$DOCS_PORT:public" -c "$CODESPACE_NAME"; then
    export DEMO_DOCS_URL="$(public_url $DOCS_PORT)"
    export DEMO_CHANGELOG_URL="$(public_url $DOCS_PORT)/changelog"
    docker compose -f compose.yaml -f compose.demo.yaml up -d --no-deps front
  else
    echo "⚠️  Le port $DOCS_PORT n'est pas public : les raccourcis utilisent GitHub pour éviter un 404."
  fi
fi

cat <<INFO

✅ Démo ${APP_NAME} démarrée
   ${APP_NAME}   : ${FRONT_URL}   (admin@example.org / demo-admin-password)
   Documentation : ${DEMO_DOCS_URL}   (changelog : ${DEMO_CHANGELOG_URL})
   Guide         : demo/README.md
INFO
