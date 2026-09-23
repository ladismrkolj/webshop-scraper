from dataclasses import dataclass


@dataclass
class NavLink:
    url: str | None = None
    text: str | None = None


@dataclass
class NavigationItem:
    items: list[NavLink] | None = None
    next_page: str | None = None
    subcategories: list[NavLink] | None = None


@dataclass
class ProductVariant:
    variant_id: str | None = None
    size: str | None = None
    color: str | None = None
    sku: str | None = None
    lowest_price_30d: str | None = None


@dataclass
class ProductItem:
    product_name: str | None = None
    brand: str | None = None
    price: str | None = None
    currency: str | None = None
    category: str | None = None
    breadcrumb: str | None = None
    product_id: str | None = None
    url: str | None = None
    description: str | None = None
    composition: str | None = None
    fit: str | None = None
    available_sizes: list[str] | None = None
    variants: list[ProductVariant] | None = None
    images: list[str] | None = None
    main_image: str | None = None
