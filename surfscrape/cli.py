"""Command line entry point.

    surfscrape scrape sites/easy-surfshop.yaml --format csv xml
    surfscrape scrape sites/            # every enabled shop, one after another
    surfscrape verify sites/recharge.yaml --limit 5   # coverage report
    surfscrape probe https://someshop.com            # what platform is it?
"""

from __future__ import annotations

import argparse
import asyncio
import logging
import sys
from datetime import datetime, timezone
from pathlib import Path

from .config import SiteConfig
from .export import WRITERS, write_stats
from .extract import platform
from .http import Fetcher
from .pipeline import ShopScraper


def _configs(target: str) -> list[SiteConfig]:
    path = Path(target)
    paths = SiteConfig.discover(path) if path.is_dir() else [path]
    if not paths:
        sys.exit(f"no site configs found in {target}")
    return [SiteConfig.load(p) for p in paths]


def _outfile(outdir: Path, shop: str, fmt: str, stamp: bool) -> Path:
    suffix = f"-{datetime.now(timezone.utc):%Y%m%d}" if stamp else ""
    return outdir / f"{shop}{suffix}.{fmt}"


async def cmd_scrape(args: argparse.Namespace) -> int:
    outdir = Path(args.out)
    all_stats = []
    failures = 0
    for cfg in _configs(args.target):
        if not cfg.enabled and not args.force:
            logging.info("[%s] disabled, skipping", cfg.name)
            continue
        if args.limit:
            cfg.discovery.max_products = args.limit
        cache = None if args.no_cache else str(Path(args.cache_dir) / f"{cfg.name}.sqlite")
        products, stats = await ShopScraper(cfg, cache).run()
        all_stats.append(stats.as_dict())
        if not products:
            failures += 1
            logging.error("[%s] produced no products", cfg.name)
            continue
        for fmt in args.format:
            path = WRITERS[fmt](products, _outfile(outdir, cfg.name, fmt, args.timestamp))
            print(f"[{cfg.name}] {len(products)} products -> {path}")
    write_stats(all_stats, outdir / "run-stats.json")
    return 1 if failures else 0


async def cmd_verify(args: argparse.Namespace) -> int:
    """Scrape a handful of pages and report which fields we actually filled.

    This is the loop for teaching the engine a new shop: run it, look at the
    zeroes, add a selector to the YAML for each one, run it again.
    """
    cfg = SiteConfig.load(args.target)
    cfg.discovery.max_products = args.limit
    products, stats = await ShopScraper(cfg, None).run()
    if not products:
        print(f"[{cfg.name}] nothing scraped - check base_url, "
              f"discovery.product_url_include and robots.txt")
        return 1

    fields = ["title", "brand", "sku", "description", "price", "sale_price",
              "currency", "availability", "stock_level"]
    n = len(products)
    print(f"\n[{cfg.name}] strategy={stats.strategy}  sample={n}  sources={stats.sources}")
    print("-" * 52)
    for f in fields:
        filled = sum(1 for p in products if getattr(p, f) not in (None, "", []))
        flag = "ok " if filled == n else ("MISS" if filled == 0 else "part")
        print(f"  {flag}  {f:<14} {filled}/{n}")
    for f, label in (("images", "images"), ("categories", "categories"), ("variants", "variants")):
        filled = sum(1 for p in products if getattr(p, f))
        flag = "ok " if filled == n else ("MISS" if filled == 0 else "part")
        print(f"  {flag}  {label:<14} {filled}/{n}")
    print("-" * 52)
    sample = products[0]
    print(f"sample: {sample.title!r} {sample.price} {sample.currency} "
          f"[{sample.category_path}] {len(sample.images)} images, "
          f"{len(sample.variants)} variants\n{sample.url}")
    return 0


async def cmd_probe(args: argparse.Namespace) -> int:
    from .config import Fetch

    async with Fetcher(args.url, Fetch()) as f:
        kind = await platform.detect(f, args.url)
        sitemaps = f.sitemaps_from_robots()
    print(f"platform: {kind}")
    print(f"crawl-delay honoured: {args.url}")
    print("sitemaps in robots.txt:")
    for s in sitemaps or ["  (none advertised - try /sitemap.xml)"]:
        print(f"  {s}")
    return 0


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="surfscrape", description="Windsurf webshop catalogue scraper")
    p.add_argument("-v", "--verbose", action="store_true")
    sub = p.add_subparsers(dest="cmd", required=True)

    s = sub.add_parser("scrape", help="scrape one config or a directory of them")
    s.add_argument("target", help="path to a site YAML or a directory of them")
    s.add_argument("--out", default="output")
    s.add_argument("--format", nargs="+", choices=list(WRITERS), default=["csv"])
    s.add_argument("--cache-dir", default=".cache")
    s.add_argument("--no-cache", action="store_true")
    s.add_argument("--limit", type=int, help="cap products (smoke tests)")
    s.add_argument("--timestamp", action="store_true", help="date-stamp output filenames")
    s.add_argument("--force", action="store_true", help="run even if enabled: false")
    s.set_defaults(func=cmd_scrape)

    v = sub.add_parser("verify", help="field-coverage report for a site config")
    v.add_argument("target")
    v.add_argument("--limit", type=int, default=10)
    v.set_defaults(func=cmd_verify)

    pr = sub.add_parser("probe", help="detect platform + sitemaps for a shop URL")
    pr.add_argument("url")
    pr.set_defaults(func=cmd_probe)
    return p


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)-7s %(message)s",
        datefmt="%H:%M:%S",
    )
    return asyncio.run(args.func(args))


if __name__ == "__main__":
    raise SystemExit(main())
