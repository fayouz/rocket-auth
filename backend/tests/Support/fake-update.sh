#!/bin/sh
# Stands for deploy/update.sh in the tests (UPDATE_SCRIPT of .env.test).
echo "==> Installing ${TARGET_VERSION:-latest}"
echo "restart: ${UPDATE_RESTART_COMMAND:-none}"
if [ "${TARGET_VERSION:-}" = "v0.9.9" ]; then
    echo "composer install failed" >&2
    exit 3
fi
echo "==> Done"
