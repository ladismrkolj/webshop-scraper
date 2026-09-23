#!/usr/bin/env bash
# One night's work: make sure scrapyd is up, redeploy every shop project, crawl
# them all, write one timestamped CSV per shop under nightly/output/.
#
# This is what cron calls. Safe to run by hand at any time.
set -euo pipefail

cd "$(dirname "$0")"

# cron runs with a bare PATH (/usr/bin:/bin), so uv — which installs into
# ~/.local/bin — is not on it. Find it and put its directory on PATH, so both
# this script and the `uv` that nightly.py shells out to for deploys resolve.
if ! command -v uv >/dev/null 2>&1; then
    for candidate in "${UV_BIN_DIR:-/nonexistent}/uv" "$HOME/.local/bin/uv" /usr/local/bin/uv /opt/uv/bin/uv "$HOME/.cargo/bin/uv"; do
        if [ -x "$candidate" ]; then
            PATH="$(dirname "$candidate"):$PATH"
            export PATH
            break
        fi
    done
fi
if ! command -v uv >/dev/null 2>&1; then
    echo "[nightly] uv not found on PATH ($PATH)." >&2
    echo "[nightly] Install it (https://astral.sh/uv) or set UV_BIN_DIR to the directory holding it." >&2
    exit 127
fi

mkdir -p var/logs output

if ! curl -sf http://127.0.0.1:6800/daemonstatus.json >/dev/null; then
    echo "[nightly] starting scrapyd"
    uv run scrapyd >>var/scrapyd.log 2>&1 &
    for _ in $(seq 1 30); do
        curl -sf http://127.0.0.1:6800/daemonstatus.json >/dev/null && break
        sleep 1
    done
fi

# Redeploy every night so the daemon runs the current code.
uv run python nightly.py deploy "$@"
uv run python nightly.py run "$@"
