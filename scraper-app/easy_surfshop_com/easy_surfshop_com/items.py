from dataclasses import dataclass


@dataclass
class ProductItem:
    url: str | None = None
    page_title: str | None = None
    name: str | None = None
    product_id: str | None = None
    article_no: str | None = None
    brand: str | None = None
    brand_url: str | None = None
    brand_logo: str | None = None
    price: float | None = None
    price_text: str | None = None
    currency: str | None = None
    regular_price: float | None = None
    discount_percent: str | None = None
    you_save: str | None = None
    price_from: str | None = None
    availability: str | None = None
    availability_text: str | None = None
    stock_info: str | None = None
    stock_availability_time_days: int | None = None
    shipping_cost: str | None = None
    main_image: str | None = None
    images: list[str] | None = None
    image_popup: str | None = None
    short_description: str | None = None
    description: str | None = None
    product_highlights: list[dict] | None = None
    features: list[str] | None = None
    specification_table: list[dict] | None = None
    size_options: list[str] | None = None
    variants: list[dict] | None = None
    variant_count: int | None = None
    breadcrumbs: list[dict] | None = None
    category: str | None = None
    site_name: str | None = None
    previous_item: dict | None = None
    next_item: dict | None = None


@dataclass
class LinkItem:
    url: str | None = None
    text: str | None = None


@dataclass
class NavigationItem:
    items: list[LinkItem] | None = None
    next_page: str | None = None
    subcategories: list[LinkItem] | None = None
