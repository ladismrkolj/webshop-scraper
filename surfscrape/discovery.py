"""Finding every product URL, without a hand-written list.

Order of preference:
  1. sitemap.xml (from robots.txt or config) - cheapest and most complete,
     and gives us <lastmod> for incremental daily runs.
  2. platform APIs (Shopify /products.json, WooCommerce Store API) - see platform.py
  3. crawling category pages + pagination - the fallback for everything else.
"""

from __future__ import annotations

import gzip
import logging
import re
from dataclasses import dataclass, field
from datetime import datetime
from urllib.parse import urljoin

from selectolax.parser import HTMLParser

from .config import SiteConfig
from .http import Fetcher, same_host

log = logging.getLogger(__name__)

_URL_RE = re.compile(r"<url>(.*?)</url>", re.S | re.I)
_LOC_RE = re.compile(r"<loc>\s*(.*?)\s*</loc>", re.S | re.I)
_LASTMOD_RE = re.compile(r"<lastmod>\s*(.*?)\s*</lastmod>", re.S | re.I)
_SITEMAP_RE = re.compile(r"<sitemap>(.*?)</sitemap>", re.S | re.I)


@dataclass(slots=True)
class Found:
    url: str
    lastmod: datetime | None = None
    categories: list[str] = field(default_factory=list)


def _parse_lastmod(text: str) -> datetime | None:
    text = text.strip()
    for fmt in ("%Y-%m-%dT%H:%M:%S%z", "%Y-%m-%dT%H:%M:%SZ", "%Y-%m-%d"):
        try:
            return datetime.strptime(text.replace("+00:00", "Z"), fmt)
        except ValueError:
            continue
    return None


class Discoverer:
    def __init__(self, cfg: SiteConfig, fetcher: Fetcher):
        self.cfg = cfg
        self.fetcher = fetcher
        self._inc = [re.compile(p) for p in cfg.discovery.product_url_include]
        self._exc = [re.compile(p) for p in cfg.discovery.product_url_exclude]

    def is_product_url(self, url: str) -> bool:
        if not same_host(url, self.cfg.base_url):
            return False
        if any(p.search(url) for p in self._exc):
            return False
        if not self._inc:
            return True
        return any(p.search(url) for p in self._inc)

    # ---- sitemaps -----------------------------------------------------
    async def _fetch_sitemap_text(self, url: str) -> str:
        if url.endswith(".gz"):
            try:
                r = await self.fetcher.client.get(url)
                if r.status_code >= 400:
                    return ""
                return gzip.decompress(r.content).decode("utf-8", "replace")
            except Exception as exc:
                log.warning("gzipped sitemap %s failed: %s", url, exc)
                return ""
        resp = await self.fetcher.get(url)
        return resp.text if resp.ok else ""

    async def from_sitemaps(self) -> list[Found]:
        seeds = list(self.cfg.discovery.sitemaps)
        if self.cfg.discovery.use_robots:
            seeds += self.fetcher.sitemaps_from_robots()
        if not seeds:
            seeds = [urljoin(self.cfg.base_url, "/sitemap.xml")]

        seen_maps: set[str] = set()
        out: dict[str, Found] = {}
        queue = list(dict.fromkeys(seeds))
        while queue:
            sm = queue.pop(0)
            if sm in seen_maps:
                continue
            seen_maps.add(sm)
            text = await self._fetch_sitemap_text(sm)
            if not text:
                continue
            # sitemap index -> more sitemaps
            children = [
                m.group(1)
                for block in _SITEMAP_RE.findall(text)
                if (m := _LOC_RE.search(block))
            ]
            if children:
                queue.extend(c for c in children if c not in seen_maps)
                continue
            for block in _URL_RE.findall(text):
                loc = _LOC_RE.search(block)
                if not loc:
                    continue
                url = loc.group(1).strip()
                if not self.is_product_url(url):
                    continue
                lm = _LASTMOD_RE.search(block)
                out[url] = Found(url=url, lastmod=_parse_lastmod(lm.group(1)) if lm else None)
        log.info("[%s] sitemaps: %d product URLs from %d sitemap(s)",
                 self.cfg.name, len(out), len(seen_maps))
        return list(out.values())

    # ---- crawling -----------------------------------------------------
    async def from_crawl(self) -> list[Found]:
        d = self.cfg.discovery
        if not d.category_urls:
            return []
        found: dict[str, Found] = {}
        visited: set[str] = set()
        queue: list[tuple[str, list[str]]] = [(u, []) for u in d.category_urls]
        pages = 0

        while queue and pages < d.max_pages:
            url, trail = queue.pop(0)
            if url in visited:
                continue
            visited.add(url)
            resp = await self.fetcher.get(url)
            pages += 1
            if not resp.ok or not resp.text:
                continue
            tree = HTMLParser(resp.text)

            for node in tree.css(d.product_link_selector or "a[href]"):
                href = node.attributes.get("href")
                if not href:
                    continue
                full = urljoin(url, href).split("#")[0]
                if self.is_product_url(full) and full not in found:
                    found[full] = Found(url=full, categories=list(trail))

            if d.category_link_selector:
                for node in tree.css(d.category_link_selector):
                    href = node.attributes.get("href")
                    if not href:
                        continue
                    full = urljoin(url, href).split("#")[0]
                    if full not in visited and same_host(full, self.cfg.base_url):
                        label = node.text(strip=True)
                        queue.append((full, trail + ([label] if label else [])))

            if d.next_page_selector:
                nxt = tree.css_first(d.next_page_selector)
                href = nxt.attributes.get("href") if nxt else None
                if href:
                    full = urljoin(url, href).split("#")[0]
                    if full not in visited:
                        queue.append((full, trail))

            if d.max_products and len(found) >= d.max_products:
                break

        log.info("[%s] crawl: %d product URLs from %d pages", self.cfg.name, len(found), pages)
        return list(found.values())

    async def discover(self) -> list[Found]:
        results: dict[str, Found] = {}
        for f in await self.from_sitemaps():
            results[f.url] = f
        if not results or self.cfg.discovery.category_urls:
            for f in await self.from_crawl():
                if f.url in results:
                    if f.categories and not results[f.url].categories:
                        results[f.url].categories = f.categories
                else:
                    results[f.url] = f
        limit = self.cfg.discovery.max_products
        out = list(results.values())
        return out[:limit] if limit else out
