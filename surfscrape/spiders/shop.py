"""One spider for every shop. What differs per shop is a YAML file, not code.

Four ways to start it, matching the four CLI verbs:

    scrapy crawl shop -a site=recharge                  # whole catalogue
    scrapy crawl shop -a url=https://shop/product/x     # one product
    scrapy crawl shop -a category=https://shop/jadra    # one category
    scrapy crawl shop -a site=recharge -a limit=20      # smoke test
"""

from __future__ import annotations

import json
import re
from urllib.parse import urljoin, urlparse

import scrapy
from scrapy.spiders import SitemapSpider

from ..config import SiteConfig
from ..extractors import extract_product, platform


class ShopSpider(SitemapSpider):
    """SitemapSpider gives us robots.txt sitemap discovery, sitemap indexes and
    .gz handling for free; we only add the product-URL filter and the parsing."""

    name = "shop"

    # SitemapSpider machinery. sitemap_rules is set per-run in __init__.
    sitemap_rules = [("", "parse_product")]

    def __init__(self, site: str | None = None, url: str | None = None,
                 category: str | None = None, limit: int | None = None,
                 platform_override: str | None = None, *args, **kwargs):
        if not any((site, url, category)):
            raise ValueError("give one of: -a site=<name> | -a url=<url> | -a category=<url>")

        self.cfg = SiteConfig.load(site) if site else SiteConfig.for_url(url or category)
        if platform_override:
            self.cfg.platform = platform_override
        self.limit = int(limit) if limit else None
        self.count = 0
        self.single_url = url
        self.category_url = category

        # hostname, never netloc: Scrapy's offsite filter chokes on a :port
        host = urlparse(self.cfg.base_url).hostname or ""
        self.allowed_domains = [host.removeprefix("www.")]
        self._inc = [re.compile(p) for p in self.cfg.product_url_include]
        self._exc = [re.compile(p) for p in self.cfg.product_url_exclude]
        # Route every sitemap URL through our own filter.
        self.sitemap_rules = [("", "parse_product")]
        self.sitemap_urls = self.cfg.sitemaps or [urljoin(self.cfg.base_url, "/robots.txt")]
        super().__init__(*args, **kwargs)

    # ---- URL classification -------------------------------------------
    def is_product_url(self, url: str) -> bool:
        if any(p.search(url) for p in self._exc):
            return False
        if not self._inc:
            return True
        return any(p.search(url) for p in self._inc)

    def sitemap_filter(self, entries):
        """SitemapSpider hook: keep only product URLs, and honour --limit."""
        for entry in entries:
            if self.is_product_url(entry["loc"]):
                yield entry

    # ---- entry points --------------------------------------------------
    async def start(self):
        """Scrapy >= 2.13 entry point (SitemapSpider overrides it, so must we)."""
        for request in self.initial_requests():
            yield request

    def start_requests(self):
        """Kept for Scrapy < 2.13, where `start` does not exist."""
        return self.initial_requests()

    def initial_requests(self):
        if self.single_url:
            yield scrapy.Request(self.single_url, callback=self.parse_product, dont_filter=True)
            return
        if self.category_url:
            yield scrapy.Request(self.category_url, callback=self.parse_category, dont_filter=True)
            return
        if self.cfg.platform in ("shopify", "woocommerce"):
            yield from self._feed_requests(self.cfg.platform, page=1)
            return
        if self.cfg.platform == "auto":
            yield scrapy.Request(self.cfg.base_url, callback=self.detect_platform,
                                 dont_filter=True)
            return
        yield from self._html_requests()

    def _html_requests(self):
        """Sitemaps (parsed by SitemapSpider) plus any explicit category pages."""
        for url in self.cfg.category_urls:
            yield scrapy.Request(url, callback=self.parse_category)
        for url in self.sitemap_urls:
            yield scrapy.Request(url, callback=self._parse_sitemap)

    def detect_platform(self, response):
        """One request to the homepage decides which strategy to use."""
        detected = platform.detect_from_html(response.text)
        self.logger.info("platform detected: %s", detected)
        self.cfg.platform = detected
        if detected in ("shopify", "woocommerce"):
            yield from self._feed_requests(detected, page=1)
        else:
            yield from self._html_requests()

    # ---- platform JSON feeds -------------------------------------------
    def _feed_requests(self, kind: str, page: int):
        tmpl = platform.SHOPIFY_FEED if kind == "shopify" else platform.WOO_FEED
        yield scrapy.Request(
            urljoin(self.cfg.base_url, tmpl.format(page=page)),
            callback=self.parse_feed,
            cb_kwargs={"kind": kind, "page": page},
            dont_filter=True,
        )

    def parse_feed(self, response, kind: str, page: int):
        try:
            payload = json.loads(response.text)
        except ValueError:
            self.logger.warning("%s feed page %d is not JSON, falling back to HTML", kind, page)
            yield from self._html_requests()
            return

        if kind == "shopify":
            products = platform.parse_shopify_feed(payload, self.cfg.name, self.cfg.base_url)
        else:
            products = platform.parse_woo_feed(payload, self.cfg.name)

        if not products:
            if page == 1:
                self.logger.warning("%s feed empty, falling back to HTML", kind)
                yield from self._html_requests()
            return

        for product in products:
            yield from self._emit(product)
        if not self._at_limit():
            yield from self._feed_requests(kind, page + 1)

    # ---- HTML pages -----------------------------------------------------
    def parse_category(self, response):
        """Follow product links, then pagination. Used by `surfscrape category`."""
        for href in response.css("a::attr(href)").getall():
            url = response.urljoin(href).split("#")[0]
            if self.is_product_url(url) and self._same_site(url):
                yield response.follow(url, callback=self.parse_product)

        selector = self.cfg.next_page_selector or "a[rel=next]::attr(href), a.next::attr(href)"
        for href in response.css(selector).getall():
            yield response.follow(href, callback=self.parse_category)

    def parse_product(self, response):
        if self._at_limit():
            return
        product = extract_product(response.text, response.url, self.cfg)
        if product is None:
            self.logger.debug("no product found on %s", response.url)
            return
        yield from self._emit(product)

    # ---- helpers ---------------------------------------------------------
    def _same_site(self, url: str) -> bool:
        host = (urlparse(url).hostname or "").removeprefix("www.")
        return host in self.allowed_domains

    def _at_limit(self) -> bool:
        return bool(self.limit and self.count >= self.limit)

    def _emit(self, product):
        if self._at_limit():
            return
        self.count += 1
        for row in product.rows():
            yield row
