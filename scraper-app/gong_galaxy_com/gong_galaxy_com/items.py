from dataclasses import dataclass
from typing import Any


@dataclass
class ProductVariant:
    variant_id: str | None = None
    sku: str | None = None
    size: str | None = None
    color: str | None = None
    type: str | None = None
    price: float | None = None
    regular_price: float | None = None
    currency: str | None = None
    availability: str | None = None
    available: bool | None = None
    url: str | None = None


@dataclass
class RelatedAddon:
    name: str | None = None
    variant: str | None = None
    price: float | None = None
    variant_id: str | None = None


@dataclass
class ProductItem:
    url: str | None = None
    canonical_url: str | None = None
    page_title: str | None = None
    name: str | None = None
    subtitle: str | None = None
    brand: str | None = None
    sku: str | None = None
    product_id: str | None = None
    selected_variant_id: str | None = None
    product_handle: str | None = None
    price: float | None = None
    regular_price: float | None = None
    on_sale: bool | None = None
    currency: str | None = None
    availability: str | None = None
    in_stock: bool | None = None
    description: str | None = None
    meta_description: str | None = None
    main_image: str | None = None
    images: list[str] | None = None
    breadcrumbs: list[str] | None = None
    category: str | None = None
    options: dict[str, Any] | None = None
    variants: list[ProductVariant] | None = None
    variant_count: int | None = None
    related_addons: list[RelatedAddon] | None = None


@dataclass
class NavigationItem:
    items: list[dict[str, Any]] | None = None
    next_page: str | None = None
    subcategories: list[dict[str, Any]] | None = None
