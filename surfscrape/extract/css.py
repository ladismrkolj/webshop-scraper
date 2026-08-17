"""Config-driven CSS extraction.

This is the layer you edit when you teach the engine a new shop. It only has
to cover what schema.org missed - anything you leave out of the YAML is simply
not applied, and the JSON-LD value survives.

Selector syntax:
    "h1.product-title"                -> text of first match
    "meta[itemprop=sku]::attr(content)" -> attribute of first match
    ["a", "b"]                        -> try in order, first non-empty wins
"""

from __future__ import annotations

import re
from urllib.parse import urljoin

from selectolax.parser import HTMLParser, Node

from ..config import Selectors
from ..models import Product, Variant
from .common import clean_text, norm_availability, to_decimal

_ATTR_RE = re.compile(r"^(?P<sel>.*?)::attr\((?P<attr>[^)]+)\)$")


def _split(selector: str) -> tuple[str, str | None]:
    m = _ATTR_RE.match(selector.strip())
    return (m.group("sel").strip(), m.group("attr")) if m else (selector.strip(), None)


def _value(node: Node, attr: str | None) -> str | None:
    if attr:
        return node.attributes.get(attr)
    return node.text(strip=True)


def select_one(tree: HTMLParser | Node, selector: str | list[str] | None) -> str | None:
    for sel in _as_list(selector):
        css, attr = _split(sel)
        for node in tree.css(css):
            val = _value(node, attr)
            if val and val.strip():
                return val.strip()
    return None


def select_all(tree: HTMLParser | Node, selector: str | list[str] | None) -> list[str]:
    out: list[str] = []
    for sel in _as_list(selector):
        css, attr = _split(sel)
        for node in tree.css(css):
            val = _value(node, attr)
            if val and val.strip():
                out.append(val.strip())
        if out:
            break
    return out


def _as_list(selector: str | list[str] | None) -> list[str]:
    if not selector:
        return []
    return [selector] if isinstance(selector, str) else list(selector)


def _int(text: str | None) -> int | None:
    if not text:
        return None
    m = re.search(r"-?\d+", text.replace(".", "").replace(",", ""))
    return int(m.group()) if m else None


def extract(
    html: str,
    url: str,
    shop: str,
    sel: Selectors,
    *,
    currency: str | None = None,
    drop_breadcrumbs: list[str] | None = None,
) -> Product:
    tree = HTMLParser(html)
    drop = {d.lower() for d in (drop_breadcrumbs or [])}

    images = [urljoin(url, i) for i in select_all(tree, sel.images)]
    crumbs = [c for c in select_all(tree, sel.breadcrumbs) if c.lower() not in drop]

    variants: list[Variant] = []
    for vnode in (tree.css(_as_list(sel.variants)[0]) if sel.variants else []):
        title = select_one(vnode, sel.variant_title) or vnode.text(strip=True)
        variants.append(
            Variant(
                sku=select_one(vnode, sel.variant_sku),
                title=clean_text(title),
                price=to_decimal(select_one(vnode, sel.variant_price), currency_hint=currency),
                currency=currency,
                availability=norm_availability(
                    vnode.attributes.get("data-availability")
                    or ("out_of_stock" if vnode.attributes.get("disabled") is not None else None)
                ),
                stock_level=_int(vnode.attributes.get("data-stock")),
            )
        )

    price = to_decimal(select_one(tree, sel.price), currency_hint=currency)
    sale = to_decimal(select_one(tree, sel.sale_price), currency_hint=currency)
    # A "sale price" selector that finds the higher number is really the list price.
    if price is not None and sale is not None and sale > price:
        price, sale = sale, price

    return Product(
        shop=shop,
        url=url,
        title=clean_text(select_one(tree, sel.title)),
        brand=clean_text(select_one(tree, sel.brand)),
        sku=select_one(tree, sel.sku),
        description=clean_text(select_one(tree, sel.description)),
        categories=crumbs,
        images=images,
        price=price,
        sale_price=sale,
        currency=currency,
        availability=norm_availability(select_one(tree, sel.availability)),
        stock_level=_int(select_one(tree, sel.stock_level)),
        variants=variants,
        source="css",
    )
