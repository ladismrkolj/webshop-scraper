from dataclasses import dataclass, field
from typing import Any


@dataclass
class ProductItem:
    name: str | None = None
    product_id: str | None = None
    brand: str | None = None
    canonical_url: str | None = None
    short_description: str | None = None
    description: str | None = None
    price: float | None = None
    regular_price: float | None = None
    discount: str | None = None
    currency: str | None = None
    loyalty_price: float | None = None
    loyalty_points_required: float | None = None
    availability: str | None = None
    in_stock: bool | None = None
    availability_text: str | None = None
    free_delivery: bool | None = None
    breadcrumbs: list[dict[str, Any]] | None = None
    category: str | None = None
    board_shape: str | None = None
    board_volume: str | None = None
    additional_properties: list[dict[str, Any]] | None = None
    main_image: str | None = None
    images: list[str] | None = None


@dataclass
class NavigationItem:
    items: list[dict[str, Any]] | None = None
    next_page: str | None = None
    subcategories: list[dict[str, Any]] | None = None
