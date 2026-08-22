from dataclasses import dataclass


@dataclass
class SizeVariant:
    value_id: str | None = None
    label: str | None = None
    selected: bool | None = None
    combination_id: str | None = None
    quantity: int | None = None
    in_stock: bool | None = None


@dataclass
class Accessory:
    name: str | None = None
    url: str | None = None
    list_price: str | None = None
    discount_price: str | None = None


@dataclass
class RelatedProduct:
    name: str | None = None
    url: str | None = None


@dataclass
class NavigationLink:
    url: str | None = None
    text: str | None = None


@dataclass
class NavigationItem:
    items: list[NavigationLink] | None = None
    next_page: str | None = None
    subcategories: list[NavigationLink] | None = None


@dataclass
class ProductItem:
    name: str | None = None
    price: str | None = None
    price_currency: str | None = None
    sku_product_id: str | None = None
    availability: str | None = None
    main_image: str | None = None
    description: str | None = None
    old_price: str | None = None
    stock_status_text: str | None = None
    low_stock_warning: str | None = None
    combination_id: str | None = None
    breadcrumbs: list[str] | None = None
    images: list[str] | None = None
    brand_logo_image: str | None = None
    size_variants: list[SizeVariant] | None = None
    style_attribute: str | None = None
    data_sheet: dict | None = None
    feature_list: list[str] | None = None
    material_list: list[str] | None = None
    accessories: list[Accessory] | None = None
    related_products: list[RelatedProduct] | None = None
