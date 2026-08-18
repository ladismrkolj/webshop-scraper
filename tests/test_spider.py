"""End-to-end through the real Scrapy stack, against the localhost shop.

Each run is a subprocess because Twisted's reactor cannot be restarted
in-process - which is also exactly how the CLI is used in production.
"""

from __future__ import annotations

import csv
import json
import os
import subprocess
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parent.parent


def run(*args: str, cwd: Path | None = None) -> subprocess.CompletedProcess:
    env = {**os.environ, "PYTHONPATH": str(ROOT)}
    return subprocess.run(
        [sys.executable, "-m", "surfscrape", *args],
        cwd=cwd or ROOT, capture_output=True, text=True, timeout=180, env=env,
    )


def write_config(tmp_path: Path, base: str, **extra) -> Path:
    import yaml

    sites = tmp_path / "sites"
    sites.mkdir(exist_ok=True)
    cfg = {
        "name": "testshop", "base_url": base, "platform": "html", "currency": "EUR",
        "product_url_include": ["/izdelek/"],
        "product_url_exclude": ["/cart"],
        "drop_breadcrumbs": ["Domov"],
        "selectors": {"sale_price": "p.price del .amount", "stock_level": "p.stock"},
        **extra,
    }
    path = sites / "testshop.yaml"
    path.write_text(yaml.safe_dump(cfg), encoding="utf-8")
    return path


def test_scrape_single_url(shop):
    """`surfscrape url <URL>` - no config anywhere, just point it at a page."""
    r = run("url", f"{shop}/izdelek/sail-45")
    assert r.returncode == 0, r.stderr[-2000:]
    rows = json.loads(r.stdout)
    assert len(rows) == 1
    row = rows[0]
    assert row["title"] == "Sail 4.5"
    assert row["brand"] == "Duotone"
    assert row["sku"] == "S45"
    assert row["category_path"] == "Jadra"          # "Domov" dropped by default
    assert row["effective_price"] == "799.00"
    assert row["availability"] == "in_stock"
    assert row["image_main"] == f"{shop}/img/S45.jpg"


def test_scrape_one_category_with_pagination(shop):
    r = run("category", f"{shop}/kategorija/jadra")
    assert r.returncode == 0, r.stderr[-2000:]
    rows = json.loads(r.stdout)
    titles = {row["title"] for row in rows}
    assert titles == {"Sail 4.5", "Board 111"}      # /cart link not followed
    assert len(rows) == 2                            # page 2 deduped by Scrapy


def test_scrape_whole_site_to_csv(shop, tmp_path):
    """Sitemap discovery + per-site selectors + Scrapy's own CSV exporter."""
    write_config(tmp_path, shop)
    out = tmp_path / "out"
    r = run("site", "testshop", "--format", "csv", "--out", str(out), cwd=tmp_path)
    assert r.returncode == 0, r.stderr[-3000:]

    rows = list(csv.DictReader((out / "testshop.csv").open(encoding="utf-8")))
    assert len(rows) == 2                            # /cart and /o-nas filtered out
    by_sku = {row["sku"]: row for row in rows}

    sail = by_sku["S45"]
    # The <del> element is the list price, so the JSON-LD price is the sale.
    assert sail["price"] == "899.00"
    assert sail["sale_price"] == "799.00"
    assert sail["discount_pct"] == "11.12"
    assert sail["stock_level"] == "4"                # from the CSS selector
    assert sail["category_path"] == "Jadra"

    # Equal old and new price is not a discount.
    assert by_sku["B111"]["sale_price"] == ""
    assert by_sku["B111"]["discount_pct"] == ""


def test_limit_and_robots_are_respected(shop, tmp_path):
    write_config(tmp_path, shop, product_url_include=["/izdelek/", "/cart"])
    out = tmp_path / "out2"
    r = run("site", "testshop", "--format", "csv", "--out", str(out),
            "--limit", "1", cwd=tmp_path)
    assert r.returncode == 0, r.stderr[-3000:]
    rows = list(csv.DictReader((out / "testshop.csv").open(encoding="utf-8")))
    assert len(rows) == 1
    assert "/cart" not in rows[0]["url"]             # robots.txt Disallow


def test_verify_reports_coverage(shop, tmp_path):
    write_config(tmp_path, shop)
    r = run("verify", "testshop", "--limit", "2", cwd=tmp_path)
    assert r.returncode == 0, r.stderr[-2000:]
    assert "ok    title" in r.stdout
    assert "ok    stock_level" in r.stdout
    assert "MISS" not in r.stdout.split("sample:")[0].replace("MISS  sale_price", "")


def test_init_generates_a_working_config(shop, tmp_path):
    """The generator should discover the shop well enough that `site` then works."""
    r = run("init", shop, "--name", "generated", "--write", cwd=tmp_path)
    assert r.returncode == 0, r.stderr[-3000:]

    import yaml
    cfg = yaml.safe_load((tmp_path / "sites" / "generated.yaml").read_text())
    assert cfg["base_url"] == shop
    assert cfg["product_url_include"] == ["/izdelek/"]   # inferred from the sitemap
    # JSON-LD had no discount or stock level, so the generator probed the HTML
    # and found the struck-through original price and the stock line.
    assert "del" in cfg["selectors"]["sale_price"]
    assert cfg["selectors"]["stock_level"] == "p.stock"

    out = tmp_path / "out3"
    r2 = run("site", "generated", "--format", "csv", "--out", str(out), cwd=tmp_path)
    assert r2.returncode == 0, r2.stderr[-3000:]
    rows = list(csv.DictReader((out / "generated.csv").open(encoding="utf-8")))
    assert {row["title"] for row in rows} == {"Sail 4.5", "Board 111"}
    # ...and the generated selectors actually produce the discount.
    sail = next(r for r in rows if r["sku"] == "S45")
    assert sail["price"] == "899.00" and sail["sale_price"] == "799.00"


def test_cache_dir_is_configurable_and_reused(shop, tmp_path):
    """The daily-run saving depends on this: the HTTP cache must land where we
    point it (a mounted volume, in Docker) and be reused by the next run.

    Scrapy does not read settings from the environment, so surfscrape/settings.py
    reads SURFSCRAPE_CACHE_DIR explicitly - this test is what catches that
    plumbing going missing.
    """
    write_config(tmp_path, shop)
    cache = tmp_path / "persistent-cache"
    env = {**os.environ, "PYTHONPATH": str(ROOT), "SURFSCRAPE_CACHE_DIR": str(cache)}

    def run_once() -> str:
        r = subprocess.run(
            [sys.executable, "-m", "surfscrape", "--log-level", "INFO",
             "site", "testshop", "--format", "csv", "--out", str(tmp_path / "o")],
            cwd=tmp_path, capture_output=True, text=True, timeout=180, env=env,
        )
        assert r.returncode == 0, r.stderr[-2000:]
        return r.stderr

    run_once()
    assert cache.is_dir(), "cache was not written to SURFSCRAPE_CACHE_DIR"
    assert any(cache.rglob("response_body")), "nothing stored in the cache"

    stats = run_once()
    # Second run: every page is revalidated against the stored copy (conditional
    # GET -> 304 -> body from cache) and nothing is downloaded fresh. A shop
    # sending real max-age headers would give httpcache/hit and no request at all.
    assert "httpcache/revalidate" in stats
    assert "httpcache/miss" not in stats, "second run refetched instead of revalidating"
