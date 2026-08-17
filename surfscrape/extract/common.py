"""Helpers shared by every extractor."""

from __future__ import annotations

import re
from decimal import Decimal, InvalidOperation

from price_parser import Price

_AVAIL_MAP = {
    "instock": "in_stock",
    "in_stock": "in_stock",
    "onlineonly": "in_stock",
    "limitedavailability": "in_stock",
    "outofstock": "out_of_stock",
    "out_of_stock": "out_of_stock",
    "soldout": "out_of_stock",
    "discontinued": "out_of_stock",
    "preorder": "preorder",
    "presale": "preorder",
    "backorder": "backorder",
}


def norm_availability(value: object) -> str | None:
    """schema.org URLs, Shopify booleans and free text -> our four states."""
    if value is None:
        return None
    if isinstance(value, bool):
        return "in_stock" if value else "out_of_stock"
    text = str(value).strip().lower()
    if not text:
        return None
    key = text.rsplit("/", 1)[-1].replace(" ", "").replace("-", "")
    if key in _AVAIL_MAP:
        return _AVAIL_MAP[key]
    # Negatives first: "ni na zalogi" contains "na zalogi".
    for neg in ("ni na zalog", "razprodano", "sold out", "niedostępny", "brak", "nicht"):
        if neg in text:
            return "out_of_stock"
    for pos in ("na zalog", "available", "dostępny", "in stock"):
        if pos in text:
            return "in_stock"
    return None


def to_decimal(value: object, *, currency_hint: str | None = None) -> Decimal | None:
    """Robust price parsing: handles '1.299,00 €', '€1,299.00', 129900 (cents is NOT assumed)."""
    if value is None or isinstance(value, bool):
        return None
    if isinstance(value, (int, float, Decimal)):
        try:
            return Decimal(str(value))
        except InvalidOperation:
            return None
    text = str(value).strip()
    if not text:
        return None
    p = Price.fromstring(text, currency_hint=currency_hint)
    if p.amount is not None:
        return p.amount
    try:
        return Decimal(re.sub(r"[^\d.\-]", "", text) or "0")
    except InvalidOperation:
        return None


def cents_to_decimal(value: object) -> Decimal | None:
    """Shopify and the Woo Store API quote integer minor units."""
    if value is None:
        return None
    try:
        return (Decimal(str(value)) / Decimal(100)).quantize(Decimal("0.01"))
    except (InvalidOperation, ValueError):
        return None


def clean_text(text: str | None, limit: int = 20000) -> str | None:
    if not text:
        return None
    out = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", text)).strip()
    return out[:limit] or None


def first(*values):
    for v in values:
        if v not in (None, "", [], {}):
            return v
    return None
