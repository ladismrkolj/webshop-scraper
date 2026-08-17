"""Per-shop configuration. Adding a shop = adding one YAML file in sites/."""

from __future__ import annotations

from pathlib import Path
from typing import Literal

import yaml
from pydantic import BaseModel, Field

Strategy = Literal["auto", "shopify", "woocommerce", "generic"]


class Selectors(BaseModel):
    """CSS selectors for a product page. All optional: only fill what JSON-LD misses.

    Each value may be a single selector or a list tried in order. Suffix a
    selector with '::attr(name)' to read an attribute instead of text.
    """

    title: str | list[str] | None = None
    brand: str | list[str] | None = None
    sku: str | list[str] | None = None
    price: str | list[str] | None = None
    sale_price: str | list[str] | None = None
    description: str | list[str] | None = None
    images: str | list[str] | None = None
    availability: str | list[str] | None = None
    stock_level: str | list[str] | None = None
    breadcrumbs: str | list[str] | None = None
    variants: str | list[str] | None = None
    variant_title: str | list[str] | None = None
    variant_price: str | list[str] | None = None
    variant_sku: str | list[str] | None = None


class Discovery(BaseModel):
    """How to find every product URL."""

    use_robots: bool = True
    sitemaps: list[str] = Field(default_factory=list)
    # Regexes: a URL is a product page if it matches any include and no exclude.
    product_url_include: list[str] = Field(default_factory=list)
    product_url_exclude: list[str] = Field(default_factory=list)
    # Fallback / supplement: crawl these listing pages and follow pagination.
    category_urls: list[str] = Field(default_factory=list)
    category_link_selector: str | None = None
    product_link_selector: str | None = None
    next_page_selector: str | None = None
    max_pages: int = 200
    max_products: int | None = None


class Fetch(BaseModel):
    concurrency: int = 4
    delay: float = 0.5  # seconds between requests to this host, per worker
    timeout: float = 30.0
    retries: int = 3
    user_agent: str = (
        "surfscrape/0.1 (+windsurf catalogue aggregator; contact: set_in_config)"
    )
    headers: dict[str, str] = Field(default_factory=dict)
    respect_robots: bool = True
    verify_tls: bool = True


class SiteConfig(BaseModel):
    name: str
    base_url: str
    enabled: bool = True
    strategy: Strategy = "auto"
    currency: str | None = None
    locale: str | None = None
    # Strip these leading crumbs from category paths ("Home", "Shop"...)
    drop_breadcrumbs: list[str] = Field(default_factory=lambda: ["Home", "Domov", "Shop"])
    discovery: Discovery = Field(default_factory=Discovery)
    selectors: Selectors = Field(default_factory=Selectors)
    fetch: Fetch = Field(default_factory=Fetch)

    @classmethod
    def load(cls, path: str | Path) -> SiteConfig:
        raw = yaml.safe_load(Path(path).read_text(encoding="utf-8"))
        return cls.model_validate(raw)

    @staticmethod
    def discover(directory: str | Path = "sites") -> list[Path]:
        return sorted(Path(directory).glob("*.y*ml"))
