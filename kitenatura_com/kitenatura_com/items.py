from dataclasses import dataclass, field


@dataclass
class ProductVariant:
    variant_id: str | None = None
    color: str | None = None
    size: str | None = None
    price: float | None = None
    regular_price: float | None = None
    on_sale: bool | None = None
    available: bool | None = None
    sku: str | None = None


@dataclass
class ProductItem:
    url: str | None = None
    canonical_url: str | None = None
    page_title: str | None = None
    name: str | None = None
    brand: str | None = None
    brand_url: str | None = None
    sku: str | None = None
    mpn: str | None = None
    item_number: str | None = None
    product_id: str | None = None
    category: str | None = None
    category_url: str | None = None
    breadcrumbs: list[str] | None = None
    breadcrumbs_jsonld: list[str] | None = None
    price: float | None = None
    regular_price: float | None = None
    currency: str | None = None
    on_sale: bool | None = None
    discount_amount: float | None = None
    discount_percent: int | None = None
    price_min: float | None = None
    price_max: float | None = None
    regular_price_min: float | None = None
    regular_price_max: float | None = None
    availability: str | None = None
    in_stock: bool | None = None
    description: str | None = None
    description_html: str | None = None
    main_image: str | None = None
    images: list[str] | None = None
    image_count: int | None = None
    options: dict | None = None
    selected_color: str | None = None
    selected_size: str | None = None
    selected_variant_id: str | None = None
    variants: list[ProductVariant] | None = None
    variant_count: int | None = None


@dataclass
class NavLink:
    url: str | None = None
    text: str | None = None


@dataclass
class NavigationItem:
    items: list[NavLink] | None = None
    next_page: str | None = None
    subcategories: list[NavLink] | None = None
