#!/usr/bin/env bash
# One night's work: make sure scrapyd is up, redeploy every shop project, crawl
# them all, write one CSV per shop under nightly/output/<date>/.
#
# This is what cron calls. Safe to run by hand at any time.
set -euo pipefail

cd "$(dirname "$0")"

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
