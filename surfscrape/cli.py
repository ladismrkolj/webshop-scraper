"""Thin CLI over the Scrapy spider. Four ways to scrape, plus two helpers.

    surfscrape url      https://shop.si/izdelek/jadro-45      # one product
    surfscrape category https://shop.si/kategorija/jadra      # one category
    surfscrape site     recharge                              # whole shop
    surfscrape site     all --format csv --out output/

    surfscrape init     https://shop.si                       # write a config
    surfscrape verify   recharge                              # coverage report

Anything the CLI does, `scrapy crawl shop -a ...` does too - the CLI just
saves you the settings boilerplate.
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

from scrapy.crawler import CrawlerProcess
from scrapy.settings import Settings
from scrapy.utils.log import configure_logging

from . import settings as default_settings
from .config import SiteConfig
from .models import ROW_FIELDS

FORMATS = {"csv": "csv", "json": "json", "jsonl": "jsonlines", "xml": "xml"}


def _settings(overrides: dict | None = None) -> Settings:
    s = Settings()
    s.setmodule(default_settings, priority="project")
    if overrides:
        s.update(overrides, priority="cmdline")
    return s


def _feeds(out: Path, stem: str, formats: list[str], stamp: bool) -> dict:
    suffix = f"-{datetime.now(timezone.utc):%Y%m%d}" if stamp else ""
    feeds = {}
    for fmt in formats:
        path = out / f"{stem}{suffix}.{fmt}"
        feeds[str(path)] = {"format": FORMATS[fmt], "fields": ROW_FIELDS, "overwrite": True}
    return feeds


def _run(spider_args: list[dict], overrides: dict, collect: bool = False) -> list[dict]:
    """Run the spider once per arg-set, in one Scrapy process.

    With collect=True the scraped rows are returned instead of exported.
    """
    from .collect import drain
    from .spiders.shop import ShopSpider

    if collect:
        overrides = dict(overrides)
        pipelines = dict(_settings().getdict("ITEM_PIPELINES"))
        pipelines["surfscrape.collect.CollectPipeline"] = 900
        overrides["ITEM_PIPELINES"] = pipelines

    process = CrawlerProcess(_settings(overrides), install_root_handler=False)
    for args in spider_args:
        process.crawl(ShopSpider, **args)
    process.start()
    return drain() if collect else []


# ------------------------------------------------------------------ verbs
def cmd_url(args) -> int:
    items = _run([{"url": args.url}],
                 {"LOG_LEVEL": args.log_level, "HTTPCACHE_ENABLED": False}, collect=True)
    if not items:
        print("no product extracted - try `surfscrape init <shop-url>` to build a config",
              file=sys.stderr)
        return 1
    print(json.dumps(items, indent=2, default=str, ensure_ascii=False))
    return 0


def cmd_category(args) -> int:
    out = Path(args.out)
    overrides = {"LOG_LEVEL": args.log_level}
    if args.format:
        overrides["FEEDS"] = _feeds(out, "category", args.format, args.timestamp)
    spider_args = {"category": args.category}
    if args.site:
        spider_args["site"] = args.site      # reuse that shop's selectors
    if args.limit:
        spider_args["limit"] = args.limit
    items = _run([spider_args], overrides, collect=not args.format)
    if not args.format:
        print(json.dumps(items, indent=2, default=str, ensure_ascii=False))
    return 0


def cmd_site(args) -> int:
    configs = SiteConfig.load_all() if args.name == "all" else [SiteConfig.load(args.name)]
    configs = [c for c in configs if c.enabled or args.force]
    if not configs:
        print("no enabled site configs", file=sys.stderr)
        return 1

    out = Path(args.out)
    runs, feeds = [], {}
    for cfg in configs:
        spider_args = {"site": cfg.name}
        if args.limit:
            spider_args["limit"] = args.limit
        runs.append(spider_args)
        feeds.update(_feeds(out, cfg.name, args.format, args.timestamp))

    overrides = {"LOG_LEVEL": args.log_level, "FEEDS": feeds}
    # Per-shop Scrapy overrides from YAML apply when running a single shop.
    if len(configs) == 1 and configs[0].settings:
        overrides.update(configs[0].settings)
    _run(runs, overrides)
    return 0


def cmd_verify(args) -> int:
    """Scrape a few products and report which fields actually got filled."""
    items = _run([{"site": args.name, "limit": args.limit}],
                 {"LOG_LEVEL": "ERROR", "HTTPCACHE_ENABLED": False}, collect=True)
    if not items:
        print(f"[{args.name}] nothing scraped. Check base_url and product_url_include, "
              f"or run: surfscrape init <shop-url>")
        return 1

    fields = ["title", "brand", "sku", "price", "sale_price", "currency", "availability",
              "stock_level", "category_path", "image_main", "description"]
    n = len(items)
    print(f"\n[{args.name}] {n} rows  sources={ {i['source'] for i in items} }")
    print("-" * 46)
    for f in fields:
        filled = sum(1 for i in items if i.get(f) not in (None, "", []))
        flag = "ok  " if filled == n else ("MISS" if filled == 0 else "part")
        print(f"  {flag}  {f:<15} {filled}/{n}")
    print("-" * 46)
    s = items[0]
    print(f"sample: {s['title']!r} {s['effective_price']} {s['currency']} "
          f"[{s['category_path']}]\n        {s['url']}")
    print("\nAdd a selector to sites/%s.yaml for each MISS, then re-run." % args.name)
    return 0


def cmd_init(args) -> int:
    from .generate import generate

    cfg, report = generate(args.url, name=args.name, sample_url=args.sample)
    print("# " + "\n# ".join(report["steps"]), file=sys.stderr)
    if report.get("selectors_found"):
        print(f"# selectors found: {report['selectors_found']}", file=sys.stderr)
    if report.get("still_missing"):
        print(f"# STILL MISSING (add by hand): {report['still_missing']}", file=sys.stderr)
    if report.get("sample_url"):
        print(f"# sample page: {report['sample_url']}", file=sys.stderr)

    yaml_text = cfg.dump_yaml()
    if args.write:
        path = Path(args.write) if args.write != "-" else Path(f"sites/{cfg.name}.yaml")
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(yaml_text, encoding="utf-8")
        print(f"\nwrote {path}\nnext: surfscrape verify {cfg.name}", file=sys.stderr)
    else:
        print(yaml_text)
    return 0


# ------------------------------------------------------------------ parser
def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="surfscrape", description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--log-level", default="INFO",
                   choices=["DEBUG", "INFO", "WARNING", "ERROR"])
    sub = p.add_subparsers(dest="cmd", required=True)

    u = sub.add_parser("url", help="scrape a single product URL, print JSON")
    u.add_argument("url")
    u.set_defaults(func=cmd_url)

    c = sub.add_parser("category", help="scrape one category page and its pagination")
    c.add_argument("category", metavar="url")
    c.add_argument("--site", help="use this shop's config for selectors/patterns")
    c.add_argument("--limit", type=int)
    c.add_argument("--format", nargs="*", choices=list(FORMATS), default=[],
                   help="omit to print JSON to stdout")
    c.add_argument("--out", default="output")
    c.add_argument("--timestamp", action="store_true")
    c.set_defaults(func=cmd_category)

    s = sub.add_parser("site", help="scrape a whole shop (or 'all')")
    s.add_argument("name")
    s.add_argument("--format", nargs="+", choices=list(FORMATS), default=["csv"])
    s.add_argument("--out", default="output")
    s.add_argument("--limit", type=int, help="stop after N products (smoke test)")
    s.add_argument("--timestamp", action="store_true")
    s.add_argument("--force", action="store_true", help="run even if enabled: false")
    s.set_defaults(func=cmd_site)

    v = sub.add_parser("verify", help="field-coverage report for a shop config")
    v.add_argument("name")
    v.add_argument("--limit", type=int, default=5)
    v.set_defaults(func=cmd_verify)

    i = sub.add_parser("init", help="generate a site config by inspecting a shop")
    i.add_argument("url")
    i.add_argument("--name")
    i.add_argument("--sample", help="a known product URL, if the guess is poor")
    i.add_argument("--write", nargs="?", const="-",
                   help="write to sites/<name>.yaml instead of stdout")
    i.set_defaults(func=cmd_init)
    return p


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    configure_logging({"LOG_LEVEL": getattr(args, "log_level", "INFO")})
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
