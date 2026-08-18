"""Normalised product model. Every site adapter produces these, nothing else."""

from __future__ import annotations

from datetime import datetime, timezone
from decimal import Decimal
from typing import Any

from pydantic import BaseModel, Field, HttpUrl, field_validator


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


class Variant(BaseModel):
    """A single buyable permutation (size / colour / volume ...)."""

    sku: str | None = None
    title: str | None = None
    options: dict[str, str] = Field(default_factory=dict)
    price: Decimal | None = None
    sale_price: Decimal | None = None
    currency: str | None = None
    availability: str | None = None  # in_stock | out_of_stock | preorder | backorder
    stock_level: int | None = None
    barcode: str | None = None
    image: str | None = None

    @property
    def effective_price(self) -> Decimal | None:
        return self.sale_price if self.sale_price is not None else self.price

    @property
    def discount_pct(self) -> float | None:
        if self.price and self.sale_price and self.price > 0 and self.sale_price < self.price:
            return round(float((self.price - self.sale_price) / self.price * 100), 2)
        return None


class Product(BaseModel):
    shop: str
    url: str
    product_id: str | None = None
    sku: str | None = None
    title: str | None = None
    brand: str | None = None
    description: str | None = None
    categories: list[str] = Field(default_factory=list)  # root -> leaf
    images: list[str] = Field(default_factory=list)
    price: Decimal | None = None
    sale_price: Decimal | None = None
    currency: str | None = None
    availability: str | None = None
    stock_level: int | None = None
    variants: list[Variant] = Field(default_factory=list)
    attributes: dict[str, Any] = Field(default_factory=dict)
    scraped_at: datetime = Field(default_factory=_utcnow)
    source: str | None = None  # which extractor won: jsonld / shopify / woocommerce / css

    @field_validator("images", mode="after")
    @classmethod
    def _dedupe_images(cls, v: list[str]) -> list[str]:
        seen: set[str] = set()
        out = []
        for img in v:
            if img and img not in seen:
                seen.add(img)
                out.append(img)
        return out

    @property
    def category_path(self) -> str:
        return " > ".join(self.categories)

    @property
    def discount_pct(self) -> float | None:
        if self.price and self.sale_price and self.price > 0 and self.sale_price < self.price:
            return round(float((self.price - self.sale_price) / self.price * 100), 2)
        return None

    def merge(self, other: Product) -> Product:
        """Fill blanks in self from other. Used to layer CSS results over JSON-LD."""
        data = self.model_dump()
        for key, val in other.model_dump().items():
            if key in ("shop", "url", "scraped_at", "source"):
                continue
            cur = data.get(key)
            empty = cur is None or cur == [] or cur == {} or cur == ""
            if empty and val not in (None, [], {}, ""):
                data[key] = val
        return Product(**data)

    def reconcile_prices(self) -> Product:
        """A shop's 'old price' element is the list price, whichever selector found it.

        Run after merging extractors: whenever sale_price ends up above price,
        the two are the wrong way round.
        """
        if self.price is not None and self.sale_price is not None:
            if self.sale_price > self.price:
                self.price, self.sale_price = self.sale_price, self.price
            elif self.sale_price == self.price:
                self.sale_price = None
        elif self.price is None and self.sale_price is not None:
            self.price, self.sale_price = self.sale_price, None
        for v in self.variants:
            if v.price is not None and v.sale_price is not None and v.sale_price > v.price:
                v.price, v.sale_price = v.sale_price, v.price
        return self

    def is_complete(self) -> bool:
        """Minimum bar for a usable row."""
        return bool(self.title and (self.price is not None or self.variants))

    def rows(self) -> list[dict]:
        """Flatten to one dict per variant (or one for the product if it has none).

        This is what the spider yields, so Scrapy's own CSV / XML / JSON feed
        exporters can write it with no exporter code of ours.
        """
        base = {
            "shop": self.shop,
            "product_id": self.product_id or "",
            "sku": self.sku or "",
            "title": self.title or "",
            "brand": self.brand or "",
            "category_path": self.category_path,
            "url": self.url,
            "currency": self.currency or "",
            "image_main": self.images[0] if self.images else "",
            "images": " | ".join(self.images),
            "description": self.description or "",
            "source": self.source or "",
            "scraped_at": self.scraped_at.isoformat(),
        }
        if not self.variants:
            eff = self.sale_price if self.sale_price is not None else self.price
            return [{
                **base, "variant_id": "", "variant_title": "", "options": "",
                "price": self.price, "sale_price": self.sale_price,
                "effective_price": eff, "discount_pct": self.discount_pct,
                "availability": self.availability or "",
                "stock_level": self.stock_level,
            }]
        out = []
        for idx, v in enumerate(self.variants):
            eff = v.effective_price if v.effective_price is not None else (
                self.sale_price if self.sale_price is not None else self.price)
            out.append({
                **base,
                "variant_id": v.sku or f"{self.product_id or 'p'}-{idx}",
                "sku": v.sku or self.sku or "",
                "variant_title": v.title or "",
                "options": "; ".join(f"{k}={val}" for k, val in v.options.items()),
                "price": v.price if v.price is not None else self.price,
                "sale_price": v.sale_price if v.sale_price is not None else self.sale_price,
                "effective_price": eff,
                "discount_pct": v.discount_pct if v.discount_pct is not None else self.discount_pct,
                "availability": v.availability or self.availability or "",
                "stock_level": v.stock_level if v.stock_level is not None else self.stock_level,
                "image_main": v.image or base["image_main"],
            })
        return out


ROW_FIELDS = [
    "shop", "product_id", "variant_id", "sku", "title", "variant_title", "brand",
    "category_path", "url", "price", "sale_price", "effective_price", "discount_pct",
    "currency", "availability", "stock_level", "options", "image_main", "images",
    "description", "source", "scraped_at",
]
