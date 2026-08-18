"""Native JSON APIs for the two platforms most shops run on.

Where these work they beat HTML scraping outright: one request returns 250
fully-structured products with real variants and inventory, so a whole
catalogue is a handful of requests instead of thousands.

  Shopify:     /products.json?limit=250&page=N       (open by default)
  WooCommerce: /wp-json/wc/store/v1/products          (Store API, public, no key)

These are pure functions: JSON payload in, Products out. The spider owns the
requesting and the paging.
"""

from __future__ import annotations

from decimal import Decimal
from typing import Any
from urllib.parse import urljoin

from ..models import Product, Variant
from .common import cents_to_decimal, clean_text, norm_availability, to_decimal



# ---------------------------------------------------------------- Shopify
def shopify_product(node: dict[str, Any], shop: str, base_url: str) -> Product:
    handle = node.get("handle", "")
    images = [i.get("src") for i in node.get("images", []) if i.get("src")]
    variants: list[Variant] = []
    option_names = [o.get("name") for o in node.get("options", [])]
    for v in node.get("variants", []):
        opts = {}
        for idx, name in enumerate(option_names, start=1):
            val = v.get(f"option{idx}")
            if name and val:
                opts[str(name)] = str(val)
        price = to_decimal(v.get("price"))
        compare = to_decimal(v.get("compare_at_price"))
        # Shopify: compare_at_price is the struck-through original.
        if compare and price and compare > price:
            list_price, sale_price = compare, price
        else:
            list_price, sale_price = price, None
        variants.append(
            Variant(
                sku=v.get("sku"),
                title=v.get("title"),
                options=opts,
                price=list_price,
                sale_price=sale_price,
                availability=norm_availability(v.get("available")),
                stock_level=v.get("inventory_quantity"),
                barcode=v.get("barcode"),
                image=(v.get("featured_image") or {}).get("src") if isinstance(v.get("featured_image"), dict) else None,
            )
        )

    prices = [v.effective_price for v in variants if v.effective_price is not None]
    lists = [v.price for v in variants if v.price is not None]
    return Product(
        shop=shop,
        url=urljoin(base_url, f"/products/{handle}"),
        product_id=str(node.get("id")),
        sku=variants[0].sku if variants else None,
        title=node.get("title"),
        brand=node.get("vendor"),
        description=clean_text(node.get("body_html")),
        categories=[node["product_type"]] if node.get("product_type") else [],
        images=images,
        price=min(lists) if lists else None,
        sale_price=min(prices) if prices and lists and min(prices) < min(lists) else None,
        availability="in_stock" if any(v.availability == "in_stock" for v in variants) else "out_of_stock",
        stock_level=sum(v.stock_level for v in variants if v.stock_level is not None) or None,
        variants=variants,
        attributes={"tags": node.get("tags"), "handle": handle},
        source="shopify",
    )



# ------------------------------------------------------------ WooCommerce
def woo_product(node: dict[str, Any], shop: str) -> Product:
    prices = node.get("prices") or {}
    minor = int(prices.get("currency_minor_unit", 2) or 2)

    def money(val: Any) -> Decimal | None:
        if val in (None, ""):
            return None
        return cents_to_decimal(val) if minor == 2 else to_decimal(val)

    regular = money(prices.get("regular_price"))
    sale = money(prices.get("sale_price"))
    if regular and sale and sale >= regular:
        sale = None

    cats = [c.get("name") for c in node.get("categories", []) if c.get("name")]
    images = [i.get("src") for i in node.get("images", []) if i.get("src")]
    brand = None
    for attr in node.get("attributes", []):
        if str(attr.get("name", "")).lower() in ("brand", "blagovna znamka", "znamka"):
            terms = attr.get("terms") or []
            if terms:
                brand = terms[0].get("name")

    variants = [
        Variant(
            sku=None,
            title=" / ".join(str(a.get("value")) for a in (v.get("attributes") or [])) or None,
            options={str(a.get("name")): str(a.get("value")) for a in (v.get("attributes") or [])},
            price=money((v.get("prices") or {}).get("price")),
            currency=prices.get("currency_code"),
        )
        for v in node.get("variations", [])
    ]

    return Product(
        shop=shop,
        url=node.get("permalink"),
        product_id=str(node.get("id")),
        sku=node.get("sku") or None,
        title=clean_text(node.get("name")),
        brand=brand,
        description=clean_text(node.get("description") or node.get("short_description")),
        categories=cats,
        images=images,
        price=regular,
        sale_price=sale,
        currency=prices.get("currency_code"),
        availability=norm_availability(node.get("is_in_stock")),
        stock_level=node.get("low_stock_remaining"),
        variants=variants,
        attributes={"on_sale": node.get("on_sale"), "type": node.get("type")},
        source="woocommerce",
    )


SHOPIFY_FEED = "/products.json?limit=250&page={page}"
WOO_FEED = "/wp-json/wc/store/v1/products?per_page=100&page={page}"


def detect_from_html(body: str) -> str:
    """Best guess at a shop's platform from its homepage HTML."""
    text = body.lower()
    if "cdn.shopify.com" in text or "shopify.theme" in text or "/cdn/shop/" in text:
        return "shopify"
    if "wp-content/plugins/woocommerce" in text or "woocommerce-page" in text:
        return "woocommerce"
    if "prestashop" in text:
        return "prestashop"
    return "html"


def parse_shopify_feed(payload, shop: str, base_url: str) -> list[Product]:
    return [shopify_product(p, shop, base_url) for p in (payload or {}).get("products", [])]


def parse_woo_feed(payload, shop: str) -> list[Product]:
    return [woo_product(p, shop) for p in (payload or []) if isinstance(p, dict)]
