"""The extraction layer.

This is the only interesting code in the project: given a product page's HTML,
produce a normalised Product. Scrapy handles getting the HTML.

The stack, in order - each layer only fills what the previous one left blank:

    1. schema.org JSON-LD   (most shops emit it for Google; usually enough)
    2. OpenGraph meta tags  (fallback for the rest)
    3. your CSS selectors   (sites/<shop>.yaml, for whatever is still missing)

Platform JSON (Shopify / WooCommerce Store API) is handled separately in
platform.py, because there the data arrives as JSON, not HTML.
"""

from __future__ import annotations

from ..config import SiteConfig
from ..models import Product
from . import common, css, jsonld, platform

__all__ = ["extract_product", "common", "css", "jsonld", "platform"]


def extract_product(html: str, url: str, cfg: SiteConfig) -> Product | None:
    """Run the full stack. Returns None if the page holds no product at all."""
    product = jsonld.extract(html, url, cfg.name, cfg.drop_breadcrumbs)

    if cfg.selectors.any_set():
        from_css = css.extract(
            html, url, cfg.name, cfg.selectors,
            currency=cfg.currency, drop_breadcrumbs=cfg.drop_breadcrumbs,
        )
        product = product.merge(from_css) if product else from_css

    if product is None:
        return None
    if not product.currency:
        product.currency = cfg.currency
    for v in product.variants:
        v.currency = v.currency or product.currency
    return product.reconcile_prices()
