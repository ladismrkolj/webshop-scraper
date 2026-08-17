"""Writers: CSV (flat, one row per variant), JSONL (lossless), XML (RSS 2.0
product feed in the Google Merchant namespace, which most tools already read)."""

from __future__ import annotations

import csv
import json
from datetime import datetime, timezone
from pathlib import Path
from xml.etree import ElementTree as ET

from .models import Product

CSV_FIELDS = [
    "shop", "product_id", "variant_id", "sku", "title", "variant_title", "brand",
    "category_path", "category_1", "category_2", "category_3", "url",
    "price", "sale_price", "effective_price", "discount_pct", "currency",
    "availability", "stock_level", "options", "image_main", "images",
    "description", "source", "scraped_at",
]


def _rows(p: Product) -> list[dict]:
    base = {
        "shop": p.shop,
        "product_id": p.product_id or "",
        "sku": p.sku or "",
        "title": p.title or "",
        "brand": p.brand or "",
        "category_path": p.category_path,
        "category_1": p.categories[0] if len(p.categories) > 0 else "",
        "category_2": p.categories[1] if len(p.categories) > 1 else "",
        "category_3": p.categories[2] if len(p.categories) > 2 else "",
        "url": p.url,
        "currency": p.currency or "",
        "image_main": p.images[0] if p.images else "",
        "images": " | ".join(p.images),
        "description": p.description or "",
        "source": p.source or "",
        "scraped_at": p.scraped_at.isoformat(),
    }
    if not p.variants:
        eff = p.sale_price if p.sale_price is not None else p.price
        return [{
            **base, "variant_id": "", "variant_title": "", "options": "",
            "price": p.price if p.price is not None else "",
            "sale_price": p.sale_price if p.sale_price is not None else "",
            "effective_price": eff if eff is not None else "",
            "discount_pct": p.discount_pct if p.discount_pct is not None else "",
            "availability": p.availability or "",
            "stock_level": p.stock_level if p.stock_level is not None else "",
        }]

    rows = []
    for idx, v in enumerate(p.variants):
        eff = v.effective_price if v.effective_price is not None else (
            p.sale_price if p.sale_price is not None else p.price
        )
        rows.append({
            **base,
            "variant_id": v.sku or f"{p.product_id or 'p'}-{idx}",
            "sku": v.sku or p.sku or "",
            "variant_title": v.title or "",
            "options": "; ".join(f"{k}={val}" for k, val in v.options.items()),
            "price": v.price if v.price is not None else (p.price if p.price is not None else ""),
            "sale_price": v.sale_price if v.sale_price is not None else (
                p.sale_price if p.sale_price is not None else ""),
            "effective_price": eff if eff is not None else "",
            "discount_pct": v.discount_pct if v.discount_pct is not None else (
                p.discount_pct if p.discount_pct is not None else ""),
            "availability": v.availability or p.availability or "",
            "stock_level": v.stock_level if v.stock_level is not None else (
                p.stock_level if p.stock_level is not None else ""),
            "image_main": v.image or base["image_main"],
        })
    return rows


def to_csv(products: list[Product], path: str | Path) -> Path:
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=CSV_FIELDS, extrasaction="ignore")
        writer.writeheader()
        for p in products:
            writer.writerows(_rows(p))
    return path


def to_jsonl(products: list[Product], path: str | Path) -> Path:
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as fh:
        for p in products:
            fh.write(p.model_dump_json() + "\n")
    return path


G = "http://base.google.com/ns/1.0"


def to_xml(products: list[Product], path: str | Path, title: str = "surfscrape feed") -> Path:
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    ET.register_namespace("g", G)
    rss = ET.Element("rss", {"version": "2.0", "xmlns:g": G})
    channel = ET.SubElement(rss, "channel")
    ET.SubElement(channel, "title").text = title
    ET.SubElement(channel, "description").text = (
        f"Generated {datetime.now(timezone.utc).isoformat()} - {len(products)} products"
    )

    for p in products:
        for row in _rows(p):
            item = ET.SubElement(channel, "item")

            def g(tag: str, value) -> None:
                if value not in (None, ""):
                    ET.SubElement(item, f"{{{G}}}{tag}").text = str(value)

            g("id", row["variant_id"] or row["product_id"] or row["url"])
            g("item_group_id", row["product_id"])
            ET.SubElement(item, "title").text = row["title"]
            ET.SubElement(item, "link").text = row["url"]
            ET.SubElement(item, "description").text = row["description"]
            g("brand", row["brand"])
            g("mpn", row["sku"])
            g("product_type", row["category_path"])
            g("availability", row["availability"])
            g("quantity", row["stock_level"])
            if row["effective_price"] != "":
                g("price", f"{row['effective_price']} {row['currency']}".strip())
            if row["sale_price"] != "":
                g("sale_price", f"{row['sale_price']} {row['currency']}".strip())
            g("image_link", row["image_main"])
            for extra in row["images"].split(" | ")[1:11]:
                g("additional_image_link", extra)
            if row["options"]:
                g("custom_label_0", row["options"])
            g("shop", row["shop"])

    ET.ElementTree(rss).write(path, encoding="utf-8", xml_declaration=True)
    return path


def write_stats(stats: list[dict], path: str | Path) -> Path:
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(stats, indent=2, default=str), encoding="utf-8")
    return path


WRITERS = {"csv": to_csv, "jsonl": to_jsonl, "xml": to_xml}
