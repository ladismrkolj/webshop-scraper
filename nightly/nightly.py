#!/usr/bin/env python3
"""Nightly scrapyd runner.

Deploys every shop project to a local scrapyd, schedules a full-shop crawl of
each one, waits for them all to finish and leaves one CSV per shop behind:

    nightly/output/2026-08-23/recharge_si.csv

Usage:
    python nightly.py deploy          # build + upload an egg per project
    python nightly.py run             # schedule all shops, wait, write CSVs
    python nightly.py run --project recharge_si --project obsession_si
    python nightly.py status          # what scrapyd is doing right now
"""

from __future__ import annotations

import argparse
import ast
import csv
import datetime as dt
import json
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parent
DEFAULT_URL = "http://127.0.0.1:6800"

# The five shops that crawl over plain HTTP. gong_galaxy_com is deliberately
# left out: it refuses Scrapy's TLS handshake and needs a ZYTE_API_KEY, so a
# nightly run of it would only collect 429s. Pass `--project gong_galaxy_com`
# explicitly (with the key exported) if you do want it.
DEFAULT_PROJECTS = [
    "easy_surfshop_com",
    "infinitysport_si",
    "kitenatura_com",
    "obsession_si",
    "recharge_si",
]

POLL_SECONDS = 30
# A whole-shop crawl at 1 request/second takes a while; give up eventually so a
# wedged job cannot hold the night open forever.
DEFAULT_TIMEOUT_SECONDS = 6 * 60 * 60


# --------------------------------------------------------------------------
# scrapyd HTTP API


def _request(url: str, data: dict[str, object] | None = None) -> dict:
    if data is None:
        req = urllib.request.Request(url)
    else:
        pairs: list[tuple[str, str]] = []
        for key, value in data.items():
            if isinstance(value, (list, tuple)):
                pairs.extend((key, str(v)) for v in value)
            else:
                pairs.append((key, str(value)))
        req = urllib.request.Request(url, data=urllib.parse.urlencode(pairs).encode())
    with urllib.request.urlopen(req, timeout=60) as response:
        payload = json.loads(response.read().decode())
    if payload.get("status") == "error":
        raise RuntimeError(f"scrapyd error from {url}: {payload.get('message')}")
    return payload


def daemon_is_up(base_url: str) -> bool:
    try:
        _request(f"{base_url}/daemonstatus.json")
    except (urllib.error.URLError, OSError, RuntimeError):
        return False
    return True


def schedule(base_url: str, project: str, settings: list[str]) -> str:
    payload = _request(
        f"{base_url}/schedule.json",
        {"project": project, "spider": project, "setting": settings},
    )
    return payload["jobid"]


def list_jobs(base_url: str, project: str) -> dict:
    return _request(f"{base_url}/listjobs.json?project={urllib.parse.quote(project)}")


# --------------------------------------------------------------------------
# CSV columns


def product_fields(project: str) -> list[str]:
    """The ProductItem field names of a project, read straight from its
    ``items.py``.

    Every shop has its own schema, so each gets its own CSV with its own
    columns. Reading the dataclass rather than letting the CSV exporter infer
    headers from the first item keeps the columns stable and complete even when
    the first product happens to leave fields empty.
    """
    source = (REPO / project / project / "items.py").read_text()
    tree = ast.parse(source)
    for node in tree.body:
        if isinstance(node, ast.ClassDef) and node.name == "ProductItem":
            return [
                stmt.target.id
                for stmt in node.body
                if isinstance(stmt, ast.AnnAssign) and isinstance(stmt.target, ast.Name)
            ]
    raise LookupError(f"no ProductItem dataclass in {project}/items.py")


# --------------------------------------------------------------------------
# commands


def cmd_deploy(args: argparse.Namespace) -> int:
    failed = []
    for project in args.project:
        print(f"[deploy] {project}", flush=True)
        result = subprocess.run(
            ["uv", "run", "--with", "scrapyd-client", "scrapyd-deploy", "default", "-p", project],
            cwd=REPO / project,
        )
        if result.returncode != 0:
            failed.append(project)
    if failed:
        print(f"[deploy] failed: {', '.join(failed)}", file=sys.stderr)
        return 1
    return 0


def cmd_run(args: argparse.Namespace) -> int:
    if not daemon_is_up(args.url):
        print(
            f"scrapyd is not answering on {args.url} — start it with "
            "`cd nightly && uv run scrapyd` (run_nightly.sh does that for you)",
            file=sys.stderr,
        )
        return 2

    run_date = dt.date.today().isoformat()
    out_dir = (Path(args.output) if args.output else HERE / "output") / run_date
    out_dir.mkdir(parents=True, exist_ok=True)

    jobs: dict[str, tuple[str, Path]] = {}
    for project in args.project:
        csv_path = out_dir / f"{project}.csv"
        feeds = {
            str(csv_path): {
                "format": "csv",
                "overwrite": True,
                "fields": product_fields(project),
            }
        }
        settings = [f"FEEDS={json.dumps(feeds)}"]
        job_id = schedule(args.url, project, settings)
        jobs[project] = (job_id, csv_path)
        print(f"[run] scheduled {project} as {job_id} -> {csv_path}", flush=True)

    deadline = time.monotonic() + args.timeout
    pending = set(jobs)
    while pending:
        if time.monotonic() > deadline:
            print(f"[run] timed out waiting for: {', '.join(sorted(pending))}", file=sys.stderr)
            break
        time.sleep(POLL_SECONDS)
        for project in sorted(pending):
            job_id, _ = jobs[project]
            state = list_jobs(args.url, project)
            if any(job["id"] == job_id for job in state.get("finished", [])):
                pending.discard(project)
                print(f"[run] finished {project}", flush=True)

    return report(jobs, pending)


def report(jobs: dict[str, tuple[str, Path]], unfinished: set[str]) -> int:
    print("\n[summary]")
    problems = False
    for project, (_, csv_path) in sorted(jobs.items()):
        if project in unfinished:
            print(f"  {project:<20} DID NOT FINISH")
            problems = True
            continue
        if not csv_path.exists():
            print(f"  {project:<20} no CSV written — see nightly/var/logs/{project}/")
            problems = True
            continue
        with csv_path.open(newline="", encoding="utf-8") as handle:
            rows = max(sum(1 for _ in csv.reader(handle)) - 1, 0)
        print(f"  {project:<20} {rows:>6} products  {csv_path}")
        if rows == 0:
            problems = True
    return 1 if problems else 0


def cmd_status(args: argparse.Namespace) -> int:
    for project in args.project:
        try:
            state = list_jobs(args.url, project)
        except (urllib.error.URLError, OSError, RuntimeError) as error:
            print(f"{project}: {error}")
            continue
        print(
            f"{project:<20} pending={len(state.get('pending', []))} "
            f"running={len(state.get('running', []))} "
            f"finished={len(state.get('finished', []))}"
        )
    return 0


def main(argv: list[str] | None = None) -> int:
    # Shared options, accepted on either side of the subcommand.
    common = argparse.ArgumentParser(add_help=False)
    common.add_argument("--url", default=DEFAULT_URL, help="scrapyd base URL")
    common.add_argument(
        "--project",
        action="append",
        choices=DEFAULT_PROJECTS + ["gong_galaxy_com"],
        help="shop to act on (repeatable); defaults to the five plain-HTTP shops",
    )

    parser = argparse.ArgumentParser(
        description=__doc__,
        parents=[common],
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    sub = parser.add_subparsers(dest="command", required=True)

    sub.add_parser(
        "deploy", parents=[common], help="build and upload an egg for each project"
    ).set_defaults(func=cmd_deploy)

    run = sub.add_parser("run", parents=[common], help="crawl every shop and write one CSV each")
    run.add_argument("--output", help="output root (default nightly/output)")
    run.add_argument("--timeout", type=int, default=DEFAULT_TIMEOUT_SECONDS)
    run.set_defaults(func=cmd_run)

    sub.add_parser("status", parents=[common], help="show scrapyd job counts").set_defaults(func=cmd_status)

    args = parser.parse_args(argv)
    args.project = args.project or list(DEFAULT_PROJECTS)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
