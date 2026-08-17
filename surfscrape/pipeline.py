"""Orchestration: discover -> fetch -> extract -> normalise, for one shop."""

from __future__ import annotations

import asyncio
import logging
import time
from dataclasses import dataclass, field

from .config import SiteConfig
from .discovery import Discoverer, Found
from .extract import css as css_extract
from .extract import jsonld as jsonld_extract
from .extract import platform
from .http import Fetcher
from .models import Product

log = logging.getLogger(__name__)


@dataclass
class RunStats:
    shop: str
    discovered: int = 0
    scraped: int = 0
    incomplete: int = 0
    failed: int = 0
    unchanged: int = 0
    seconds: float = 0.0
    strategy: str = ""
    sources: dict[str, int] = field(default_factory=dict)

    def as_dict(self) -> dict:
        return {**self.__dict__}


class ShopScraper:
    def __init__(self, cfg: SiteConfig, cache_path: str | None = None):
        self.cfg = cfg
        self.cache_path = cache_path

    async def run(self) -> tuple[list[Product], RunStats]:
        started = time.monotonic()
        stats = RunStats(shop=self.cfg.name)
        async with Fetcher(self.cfg.base_url, self.cfg.fetch, self.cache_path) as fetcher:
            strategy = self.cfg.strategy
            if strategy == "auto":
                strategy = await platform.detect(fetcher, self.cfg.base_url)
                log.info("[%s] detected platform: %s", self.cfg.name, strategy)
            stats.strategy = strategy
            limit = self.cfg.discovery.max_products

            products: list[Product] = []
            if strategy == "shopify":
                products = await platform.shopify_catalogue(
                    fetcher, self.cfg.base_url, self.cfg.name, limit
                )
            elif strategy == "woocommerce":
                products = await platform.woocommerce_catalogue(
                    fetcher, self.cfg.base_url, self.cfg.name, limit
                )

            if products:
                stats.discovered = len(products)
                # The API gives us everything except, sometimes, the category
                # tree - which the sitemap/crawl and page breadcrumbs do have.
                if self.cfg.selectors.model_dump(exclude_none=True):
                    products = await self._enrich(fetcher, products)
            else:
                if strategy != "generic":
                    log.warning("[%s] %s API returned nothing, falling back to HTML",
                                self.cfg.name, strategy)
                    stats.strategy = f"{strategy}->generic"
                found = await Discoverer(self.cfg, fetcher).discover()
                stats.discovered = len(found)
                products = await self._scrape_pages(fetcher, found, stats)

            stats.unchanged = fetcher.stats["not_modified"]

        good = [p for p in products if p.is_complete()]
        stats.incomplete = len(products) - len(good)
        stats.scraped = len(good)
        for p in good:
            stats.sources[p.source or "?"] = stats.sources.get(p.source or "?", 0) + 1
        stats.seconds = round(time.monotonic() - started, 1)
        log.info("[%s] done: %s", self.cfg.name, stats.as_dict())
        return good, stats

    async def _scrape_pages(
        self, fetcher: Fetcher, found: list[Found], stats: RunStats
    ) -> list[Product]:
        results: list[Product] = []
        lock = asyncio.Lock()

        async def work(item: Found) -> None:
            product = await self.scrape_one(fetcher, item.url)
            if product is None:
                stats.failed += 1
                return
            if item.categories and not product.categories:
                product.categories = item.categories
            async with lock:
                results.append(product)

        await asyncio.gather(*(work(f) for f in found))
        return results

    async def scrape_one(self, fetcher: Fetcher, url: str) -> Product | None:
        """Layered extraction: schema.org for structure, CSS config for the gaps."""
        resp = await fetcher.get(url)
        if not resp.ok or not resp.text:
            return None
        base = jsonld_extract.extract(
            resp.text, resp.url, self.cfg.name, self.cfg.drop_breadcrumbs
        )
        if self.cfg.selectors.model_dump(exclude_none=True):
            from_css = css_extract.extract(
                resp.text,
                resp.url,
                self.cfg.name,
                self.cfg.selectors,
                currency=self.cfg.currency,
                drop_breadcrumbs=self.cfg.drop_breadcrumbs,
            )
            base = base.merge(from_css) if base else from_css
        if base is None:
            return None
        if not base.currency:
            base.currency = self.cfg.currency
        for v in base.variants:
            v.currency = v.currency or base.currency
        return base.reconcile_prices()

    async def _enrich(self, fetcher: Fetcher, products: list[Product]) -> list[Product]:
        """Visit product pages to add what the platform API omitted (breadcrumbs...)."""
        lock = asyncio.Lock()
        out: list[Product] = []

        async def work(p: Product) -> None:
            extra = await self.scrape_one(fetcher, p.url) if p.url else None
            async with lock:
                out.append(p.merge(extra) if extra else p)

        await asyncio.gather(*(work(p) for p in products))
        return out


async def run_site(cfg: SiteConfig, cache_path: str | None = None):
    return await ShopScraper(cfg, cache_path).run()
