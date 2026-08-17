"""schema.org extraction: JSON-LD first, then microdata/OpenGraph leftovers.

Most modern shops emit a Product node for SEO and Google Merchant, so this one
extractor usually carries a new shop 80% of the way with zero configuration.
"""

from __future__ import annotations

import json
import logging
from typing import Any, Iterator
from urllib.parse import urljoin

from selectolax.parser import HTMLParser

from ..models import Product, Variant
from .common import clean_text, first, norm_availability, to_decimal

log = logging.getLogger(__name__)


def _iter_json_ld(tree: HTMLParser) -> Iterator[dict]:
    for node in tree.css('script[type="application/ld+json"]'):
        raw = node.text()
        if not raw or not raw.strip():
            continue
        try:
            data = json.loads(raw)
        except ValueError:
            # Some themes emit trailing commas or several concatenated objects.
            try:
                data = json.loads(raw.replace(",]", "]").replace(",}", "}"))
            except ValueError:
                continue
        yield from _walk(data)


def _walk(data: Any) -> Iterator[dict]:
    if isinstance(data, list):
        for item in data:
            yield from _walk(item)
    elif isinstance(data, dict):
        yield data
        for key in ("@graph", "mainEntity", "itemListElement", "hasVariant"):
            if key in data:
                yield from _walk(data[key])


def _types(node: dict) -> set[str]:
    t = node.get("@type")
    if isinstance(t, str):
        return {t.lower()}
    if isinstance(t, list):
        return {str(x).lower() for x in t}
    return set()


def _offers(node: dict) -> list[dict]:
    offers = node.get("offers")
    out: list[dict] = []
    for o in _walk(offers) if offers is not None else []:
        if isinstance(o, dict) and ("price" in o or "priceSpecification" in o or "lowPrice" in o):
            out.append(o)
    return out


def _offer_price(offer: dict) -> tuple[Any, str | None]:
    price = offer.get("price")
    if price is None:
        spec = offer.get("priceSpecification")
        if isinstance(spec, dict):
            price = spec.get("price")
        elif isinstance(spec, list) and spec:
            price = spec[0].get("price")
    if price is None:
        price = offer.get("lowPrice")
    return price, offer.get("priceCurrency")


def _images(node: dict, base: str) -> list[str]:
    raw = node.get("image")
    out: list[str] = []
    for item in raw if isinstance(raw, list) else [raw]:
        if isinstance(item, str):
            out.append(urljoin(base, item))
        elif isinstance(item, dict):
            url = item.get("url") or item.get("contentUrl")
            if url:
                out.append(urljoin(base, url))
    return out


def _breadcrumbs(tree: HTMLParser, drop: list[str]) -> list[str]:
    for node in _iter_json_ld(tree):
        if "breadcrumblist" not in _types(node):
            continue
        items = node.get("itemListElement") or []
        crumbs: list[tuple[int, str]] = []
        for it in items:
            if not isinstance(it, dict):
                continue
            name = it.get("name")
            item = it.get("item")
            if not name and isinstance(item, dict):
                name = item.get("name")
            if name:
                crumbs.append((int(it.get("position") or len(crumbs) + 1), str(name).strip()))
        if crumbs:
            crumbs.sort(key=lambda c: c[0])
            names = [n for _, n in crumbs]
            lowered = {d.lower() for d in drop}
            return [n for n in names if n.lower() not in lowered]
    return []


def extract(html: str, url: str, shop: str, drop_breadcrumbs: list[str] | None = None) -> Product | None:
    tree = HTMLParser(html)
    product_node = None
    for node in _iter_json_ld(tree):
        if "product" in _types(node) or "productgroup" in _types(node):
            product_node = node
            break
    if product_node is None:
        return _from_opengraph(tree, url, shop)

    n = product_node
    brand = n.get("brand")
    if isinstance(brand, dict):
        brand = brand.get("name")
    elif isinstance(brand, list) and brand:
        brand = brand[0].get("name") if isinstance(brand[0], dict) else brand[0]

    offers = _offers(n)
    price = currency = availability = None
    stock_level = None
    if offers:
        raw_price, currency = _offer_price(offers[0])
        price = to_decimal(raw_price, currency_hint=currency)
        availability = norm_availability(offers[0].get("availability"))
        lvl = offers[0].get("inventoryLevel")
        if isinstance(lvl, dict):
            lvl = lvl.get("value")
        if lvl is not None:
            try:
                stock_level = int(float(lvl))
            except (TypeError, ValueError):
                pass

    variants: list[Variant] = []
    seen_offers = offers[1:] if len(offers) > 1 else []
    for o in seen_offers:
        p, cur = _offer_price(o)
        variants.append(
            Variant(
                sku=o.get("sku") or o.get("mpn"),
                title=clean_text(o.get("name")),
                price=to_decimal(p, currency_hint=cur),
                currency=cur or currency,
                availability=norm_availability(o.get("availability")),
                barcode=o.get("gtin13") or o.get("gtin"),
            )
        )
    for v in n.get("hasVariant") or []:
        if not isinstance(v, dict):
            continue
        v_offers = _offers(v)
        p, cur = _offer_price(v_offers[0]) if v_offers else (None, None)
        variants.append(
            Variant(
                sku=v.get("sku"),
                title=clean_text(v.get("name")),
                options={
                    k: str(v[k])
                    for k in ("color", "size", "material", "pattern")
                    if v.get(k)
                },
                price=to_decimal(p, currency_hint=cur),
                currency=cur or currency,
                availability=norm_availability(v_offers[0].get("availability")) if v_offers else None,
            )
        )

    return Product(
        shop=shop,
        url=url,
        product_id=str(first(n.get("productID"), n.get("@id"), "") or "") or None,
        sku=n.get("sku") or n.get("mpn"),
        title=clean_text(n.get("name")),
        brand=clean_text(brand) if isinstance(brand, str) else None,
        description=clean_text(n.get("description")),
        categories=_breadcrumbs(tree, drop_breadcrumbs or []) or _category_field(n),
        images=_images(n, url),
        price=price,
        currency=currency,
        availability=availability,
        stock_level=stock_level,
        variants=variants,
        attributes={"gtin": n.get("gtin13") or n.get("gtin"), "color": n.get("color")},
        source="jsonld",
    )


def _category_field(n: dict) -> list[str]:
    cat = n.get("category")
    if isinstance(cat, str):
        return [c.strip() for c in cat.replace("/", ">").split(">") if c.strip()]
    if isinstance(cat, dict):
        return [str(cat.get("name"))] if cat.get("name") else []
    if isinstance(cat, list):
        return [str(c) for c in cat if c]
    return []


def _from_opengraph(tree: HTMLParser, url: str, shop: str) -> Product | None:
    def meta(prop: str) -> str | None:
        node = tree.css_first(f'meta[property="{prop}"]') or tree.css_first(f'meta[name="{prop}"]')
        return node.attributes.get("content") if node else None

    title = meta("og:title")
    price = meta("product:price:amount") or meta("og:price:amount")
    if not title and price is None:
        return None
    images = [urljoin(url, i.attributes["content"])
              for i in tree.css('meta[property="og:image"]')
              if i.attributes.get("content")]
    currency = meta("product:price:currency") or meta("og:price:currency")
    return Product(
        shop=shop,
        url=url,
        title=clean_text(title),
        description=clean_text(meta("og:description")),
        price=to_decimal(price, currency_hint=currency),
        currency=currency,
        availability=norm_availability(meta("product:availability") or meta("og:availability")),
        images=images,
        source="opengraph",
    )
