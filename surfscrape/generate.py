"""Write a site config by looking at the shop, instead of by hand.

`surfscrape init <shop-url>` does what you would do manually:

  1. fetch the homepage, guess the platform
  2. read robots.txt / sitemap.xml, collect some URLs
  3. infer the product-URL pattern from the URLs that actually parse as products
  4. fetch a sample product page and see what the automatic extractors get
  5. for each field they missed, try a library of known theme selectors and
     keep the ones that produce a plausible value
  6. print the YAML

The output is a starting point, not an oracle: check it, then `verify` it.
"""

from __future__ import annotations

import gzip
import json
import re
from collections import Counter
from urllib.parse import urljoin, urlparse

import httpx

from .config import Selectors, SiteConfig
from .extractors import css as css_extract
from .extractors import jsonld as jsonld_extract
from .extractors import platform
from .extractors.common import to_decimal

# Selectors seen across the common storefront themes. Cheap to try, and a
# candidate is only kept if it actually yields a plausible value on the page.
CANDIDATES: dict[str, list[str]] = {
    "title": ["h1.product_title", "h1.product-title", "h1.product__title", "h1[itemprop=name]", "h1"],
    "brand": ["*[itemprop=brand]", "a.brand", ".product-brand", ".woocommerce-product-attributes-item--attribute_pa_brand td"],
    "sku": ["span.sku", ".sku_wrapper .sku", "*[itemprop=sku]", ".product-sku"],
    # "price" is the price the customer pays now...
    "price": ["p.price ins .amount", ".price ins .woocommerce-Price-amount",
              ".price-item--sale", ".price__sale .price-item", ".special-price .price",
              "*[itemprop=price]::attr(content)", "p.price .amount",
              ".price .woocommerce-Price-amount", ".product-price"],
    # ...and these are the struck-through originals. Product.reconcile_prices()
    # puts the higher of the two into `price` and the lower into `sale_price`,
    # so pointing this at the old price is correct, not backwards.
    "sale_price": ["p.price del .amount", ".price del .woocommerce-Price-amount",
                   "s.price-item--regular", ".price__regular .price-item",
                   ".old-price", ".regular-price", "del .amount"],
    "description": ["div.woocommerce-product-details__short-description", ".product__description",
                    "#tab-description", "div[itemprop=description]", ".product-description"],
    "images": ["figure.woocommerce-product-gallery__wrapper img::attr(data-large_image)",
               ".woocommerce-product-gallery__image a::attr(href)",
               ".product__media img::attr(src)", ".product-gallery img::attr(src)",
               "*[itemprop=image]::attr(src)"],
    "availability": ["p.stock", ".stock", ".product-stock", "*[itemprop=availability]::attr(href)"],
    "stock_level": ["p.stock", ".stock-quantity", ".inventory-quantity"],
    "breadcrumbs": ["nav.woocommerce-breadcrumb a", ".breadcrumb a", ".breadcrumbs a",
                    "*[itemprop=itemListElement] *[itemprop=name]"],
}

PLAUSIBLE = {
    "price": lambda v: to_decimal(v) not in (None, 0),
    "sale_price": lambda v: to_decimal(v) not in (None, 0),
    "stock_level": lambda v: bool(re.search(r"\d", v)),
    "title": lambda v: 3 <= len(v) <= 250,
    "description": lambda v: len(v) >= 20,
}


class Probe:
    def __init__(self, timeout: float = 20.0):
        self.client = httpx.Client(
            timeout=timeout,
            follow_redirects=True,
            headers={"User-Agent": "surfscrape/0.2 (config generator)"},
        )

    def get(self, url: str) -> str:
        try:
            r = self.client.get(url)
            if r.status_code >= 400:
                return ""
            if url.endswith(".gz"):
                return gzip.decompress(r.content).decode("utf-8", "replace")
            return r.text
        except Exception:
            return ""

    def json(self, url: str):
        text = self.get(url)
        try:
            return json.loads(text) if text else None
        except ValueError:
            return None

    def close(self):
        self.client.close()


def find_sitemaps(probe: Probe, base_url: str) -> list[str]:
    robots = probe.get(urljoin(base_url, "/robots.txt"))
    found = re.findall(r"(?im)^\s*sitemap:\s*(\S+)", robots)
    return found or [urljoin(base_url, "/sitemap.xml")]


def collect_urls(probe: Probe, sitemaps: list[str], cap: int = 400) -> list[str]:
    """Walk sitemaps (following one level of sitemap index) and collect locs."""
    urls: list[str] = []
    for sm in sitemaps[:10]:
        text = probe.get(sm)
        if not text:
            continue
        locs = re.findall(r"<loc>\s*(.*?)\s*</loc>", text, re.S)
        if "<sitemapindex" in text:
            for child in locs[:10]:
                urls.extend(re.findall(r"<loc>\s*(.*?)\s*</loc>", probe.get(child), re.S))
        else:
            urls.extend(locs)
        if len(urls) >= cap:
            break
    return urls[:cap]


def guess_product_pattern(urls: list[str]) -> list[str]:
    """The path segment that most product-looking URLs share, e.g. '/izdelek/'.

    Prefers a known e-commerce slug when one is present, otherwise falls back
    to the most common second-to-last path segment.
    """
    known = ["/izdelek/", "/product/", "/products/", "/produkt/", "/shop/", "/p/", "/artikel/"]
    for slug in known:
        if sum(1 for u in urls if slug in u) >= max(2, len(urls) * 0.05):
            return [slug]
    segments = Counter()
    for u in urls:
        parts = [p for p in urlparse(u).path.split("/") if p]
        if len(parts) >= 2:
            segments[f"/{parts[0]}/"] += 1
    if segments:
        top, hits = segments.most_common(1)[0]
        if hits >= 2:
            return [re.escape(top)]
    return []


def probe_selectors(html: str, url: str, shop: str, missing: list[str]) -> dict[str, str]:
    """Try candidate selectors for each missing field; keep what works."""
    keep: dict[str, str] = {}
    for field in missing:
        for candidate in CANDIDATES.get(field, []):
            sel = Selectors(**{field: candidate})
            got = css_extract.extract(html, url, shop, sel)
            value = getattr(got, field, None)
            if field == "images":
                if got.images:
                    keep[field] = candidate
                    break
                continue
            if field == "breadcrumbs":
                if len(got.categories) >= 1:
                    keep[field] = candidate
                    break
                continue
            if value in (None, "", []):
                continue
            check = PLAUSIBLE.get(field)
            raw = css_extract.select_one(css_extract.HTMLParser(html), candidate)
            if check and raw is not None and not check(raw):
                continue
            keep[field] = candidate
            break
    return keep


# Discounts and stock levels are rarely in schema.org, so always probe for
# them even when the automatic extractors looked successful.
REQUIRED_FIELDS = ["title", "price", "sale_price", "sku", "brand", "description",
                   "images", "availability", "stock_level", "breadcrumbs"]


def generate(shop_url: str, name: str | None = None, sample_url: str | None = None) -> tuple[SiteConfig, dict]:
    """Returns (config, report). The report explains what was and was not found."""
    probe = Probe()
    report: dict = {"steps": []}
    try:
        base = shop_url.rstrip("/")
        name = name or urlparse(base).netloc.removeprefix("www.").split(".")[0]

        home = probe.get(base)
        detected = platform.detect_from_html(home) if home else "html"
        if probe.json(urljoin(base, "/products.json?limit=1")):
            detected = "shopify"
        elif isinstance(probe.json(urljoin(base, "/wp-json/wc/store/v1/products?per_page=1")), list):
            detected = "woocommerce"
        report["steps"].append(f"platform: {detected}")

        sitemaps = find_sitemaps(probe, base)
        report["steps"].append(f"sitemaps: {len(sitemaps)} advertised")

        cfg = SiteConfig(name=name, base_url=base, platform=detected)

        urls = collect_urls(probe, sitemaps)
        report["steps"].append(f"sitemap URLs sampled: {len(urls)}")
        if urls:
            cfg.product_url_include = guess_product_pattern(urls)
            report["steps"].append(f"product URL pattern: {cfg.product_url_include or 'NOT FOUND'}")

        if not sample_url:
            candidates = [u for u in urls if cfg.product_url_include and
                          re.search(cfg.product_url_include[0], u)]
            sample_url = candidates[0] if candidates else (urls[0] if urls else None)
        report["sample_url"] = sample_url

        if detected in ("shopify", "woocommerce"):
            report["steps"].append(
                f"{detected} JSON feed available - selectors are usually unnecessary")
            return cfg, report

        if not sample_url:
            report["steps"].append("no sample product page found - set product_url_include by hand")
            return cfg, report

        html = probe.get(sample_url)
        if not html:
            report["steps"].append(f"could not fetch sample page {sample_url}")
            return cfg, report

        auto = jsonld_extract.extract(html, sample_url, name)
        got = {f: bool(getattr(auto, f, None)) for f in REQUIRED_FIELDS if f != "breadcrumbs"} if auto else {}
        if auto:
            got["breadcrumbs"] = bool(auto.categories)
            report["steps"].append(f"automatic extraction ({auto.source}) covered: "
                                   + ", ".join(sorted(f for f, ok in got.items() if ok)))
        else:
            report["steps"].append("no schema.org or OpenGraph data - selectors will do the work")

        missing = [f for f in REQUIRED_FIELDS if not got.get(f)]
        found = probe_selectors(html, sample_url, name, missing)
        cfg.selectors = Selectors(**found)
        report["missing_after_auto"] = missing
        report["selectors_found"] = found
        report["still_missing"] = [f for f in missing if f not in found]
        currency = auto.currency if auto else None
        cfg.currency = currency or "EUR"
        return cfg, report
    finally:
        probe.close()
