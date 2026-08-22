import json
import re
from functools import cached_property
from urllib.parse import parse_qs, urlparse

from web_poet import Returns, WebPage, field, handle_urls

from kitenatura_com.items import ProductItem, ProductVariant

# --- module-level helpers ------------------------------------------------

_ARTICLENUMBER_RE = re.compile(r"^articlenumber_(.+)$")


def _money(cents) -> float | None:
    """Shopify money fields are integer cents; 0/None means "absent"."""
    if not cents:
        return None
    return round(cents / 100.0, 2)


def _strip_html(html: str) -> str | None:
    """Plain-text rendering of an HTML description fragment."""
    if not html or not html.strip():
        return None
    from parsel import Selector

    paras = Selector(text=html).css("p")
    if paras:
        text = "\n\n".join(
            " ".join("".join(p.css("::text").getall()).split()) for p in paras
        )
    else:
        text = " ".join(Selector(text=html).css("::text").getall()).strip()
    return text.strip() or None


@handle_urls("kitenatura.com")
class ProductPage(WebPage, Returns[ProductItem]):
    """Product detail page for the kitenatura.com Shopify store.

    Nearly every field is read from the inline theme-settings JSON island
    (``script[type="application/json"][data-section-type="static-product"]``),
    which carries the full Shopify ``product`` object under its ``product``
    key. Money values there are integer cents; image URLs are
    protocol-relative. A handful of fields (mpn, currency, availability,
    breadcrumbs_jsonld) are only reliably available via the page's JSON-LD
    ``Product``/``BreadcrumbList`` blocks, and a few (canonical_url,
    page_title, on-page breadcrumbs, brand_url, selected_variant_id) are
    simplest to read straight from the DOM.
    """

    # -- shared parsed sources -------------------------------------------

    @cached_property
    def _theme(self) -> dict:
        raw = self.css(
            'script[type="application/json"][data-section-type="static-product"]::text'
        ).get()
        if not raw:
            return {}
        try:
            return json.loads(raw)
        except ValueError:
            return {}

    @cached_property
    def _product(self) -> dict:
        return self._theme.get("product") or {}

    @cached_property
    def _jsonld_nodes(self) -> list:
        nodes = []
        for raw in self.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except ValueError:
                continue
            if isinstance(data, list):
                nodes.extend(data)
            else:
                nodes.append(data)
        return nodes

    @cached_property
    def _jsonld_product(self) -> dict:
        for node in self._jsonld_nodes:
            if isinstance(node, dict) and node.get("@type") == "Product":
                return node
        return {}

    @cached_property
    def _jsonld_breadcrumbs(self) -> dict:
        for node in self._jsonld_nodes:
            if isinstance(node, dict) and node.get("@type") == "BreadcrumbList":
                return node
        return {}

    @cached_property
    def _option_names(self) -> list[str]:
        return [n for n in self._product.get("options") or [] if n]

    @cached_property
    def _variants_raw(self) -> list[dict]:
        return self._product.get("variants") or []

    @cached_property
    def _selected_variant(self) -> dict:
        variants = self._variants_raw
        if not variants:
            return {}

        # 1. The <select data-variants> option marked `selected`.
        selected_id = self.css(
            "select[data-variants] option[selected]::attr(value)"
        ).get()
        if selected_id and selected_id != "not-selected":
            for v in variants:
                if str(v.get("id")) == selected_id:
                    return v

        # 2. A `?variant=<id>` query param on the JSON-LD offer URL.
        offer_url = (self._jsonld_product.get("offers") or {}).get("url")
        if offer_url:
            qs = parse_qs(urlparse(offer_url).query)
            variant_ids = qs.get("variant")
            if variant_ids:
                for v in variants:
                    if str(v.get("id")) == variant_ids[0]:
                        return v

        # 3. First available variant, else the first variant.
        for v in variants:
            if v.get("available"):
                return v
        return variants[0]

    def _option_value(self, variant: dict, name: str) -> str | None:
        try:
            idx = self._option_names.index(name)
        except ValueError:
            return None
        options = variant.get("options") or []
        if idx < len(options):
            return options[idx]
        return None

    # -- identity / URLs --------------------------------------------------

    @field
    def url(self) -> str | None:
        return str(self.response.url)

    @field
    def canonical_url(self) -> str | None:
        href = self.css('link[rel="canonical"]::attr(href)').get()
        return self.urljoin(href) if href else None

    @field
    def page_title(self) -> str | None:
        title = self.css("title::text").get()
        return title.strip() if title else None

    @field
    def name(self) -> str | None:
        return self._product.get("title")

    @field
    def brand(self) -> str | None:
        return self._product.get("vendor")

    @field
    def brand_url(self) -> str | None:
        href = self.css('a[href*="/collections/vendors?q="]::attr(href)').get()
        if href:
            return self.urljoin(href)
        vendor = self._product.get("vendor")
        if vendor:
            from urllib.parse import quote

            return self.urljoin("/collections/vendors?q=" + quote(vendor))
        return None

    @field
    def sku(self) -> str | None:
        sku = self._jsonld_product.get("sku")
        if sku:
            return sku
        variant = self._selected_variant
        return variant.get("sku") if variant else None

    @field
    def mpn(self) -> str | None:
        return self._jsonld_product.get("mpn")

    @field
    def item_number(self) -> str | None:
        for tag in self._product.get("tags") or []:
            match = _ARTICLENUMBER_RE.match(tag)
            if match:
                return match.group(1)
        return None

    @field
    def product_id(self) -> str | None:
        product_id = self._product.get("id")
        return str(product_id) if product_id is not None else None

    # -- category / breadcrumbs -------------------------------------------

    @cached_property
    def _breadcrumb_links(self):
        return self.css("nav.breadcrumbs-container a")

    @field
    def category(self) -> str | None:
        texts = [t.strip() for t in self._breadcrumb_links.css("::text").getall()]
        texts = [t for t in texts if t]
        return texts[-1] if texts else None

    @field
    def category_url(self) -> str | None:
        hrefs = self._breadcrumb_links.css("::attr(href)").getall()
        return self.urljoin(hrefs[-1]) if hrefs else None

    @field
    def breadcrumbs(self) -> list[str] | None:
        texts = self.css(
            "nav.breadcrumbs-container a::text, "
            "nav.breadcrumbs-container > span:not(.breadcrumbs-delimiter)::text"
        ).getall()
        crumbs = [t.strip() for t in texts if t.strip()]
        return crumbs or None

    @field
    def breadcrumbs_jsonld(self) -> list[str] | None:
        items = self._jsonld_breadcrumbs.get("itemListElement") or []
        items = sorted(items, key=lambda el: el.get("position", 0))
        names = [
            (el.get("item") or {}).get("name")
            for el in items
            if (el.get("item") or {}).get("name")
        ]
        return names or None

    # -- pricing ------------------------------------------------------------

    @field
    def price(self) -> float | None:
        variant = self._selected_variant
        return _money(variant.get("price")) if variant else None

    @field
    def regular_price(self) -> float | None:
        variant = self._selected_variant
        return _money(variant.get("compare_at_price")) if variant else None

    @field
    def currency(self) -> str | None:
        currency = (self._jsonld_product.get("offers") or {}).get("priceCurrency")
        return currency or "EUR"

    @property
    def _price_info(self) -> tuple[float | None, float | None]:
        variant = self._selected_variant
        if not variant:
            return None, None
        price = _money(variant.get("price"))
        regular_price = _money(variant.get("compare_at_price"))
        return price, regular_price

    @field
    def on_sale(self) -> bool | None:
        price, regular_price = self._price_info
        return regular_price is not None and price is not None and regular_price > price

    @field
    def discount_amount(self) -> float | None:
        price, regular_price = self._price_info
        if regular_price is None or price is None or regular_price <= price:
            return None
        return round(regular_price - price, 2)

    @field
    def discount_percent(self) -> int | None:
        price, regular_price = self._price_info
        if regular_price is None or price is None or regular_price <= price:
            return None
        return round((regular_price - price) / regular_price * 100)

    @field
    def price_min(self) -> float | None:
        return _money(self._product.get("price_min"))

    @field
    def price_max(self) -> float | None:
        return _money(self._product.get("price_max"))

    @field
    def regular_price_min(self) -> float | None:
        return _money(self._product.get("compare_at_price_min"))

    @field
    def regular_price_max(self) -> float | None:
        return _money(self._product.get("compare_at_price_max"))

    # -- availability ---------------------------------------------------

    @field
    def availability(self) -> str | None:
        value = (self._jsonld_product.get("offers") or {}).get("availability")
        if value:
            return value.rstrip("/").rsplit("/", 1)[-1]
        return "InStock" if self._product.get("available") else "OutOfStock"

    @field
    def in_stock(self) -> bool | None:
        available = self._product.get("available")
        if available is not None:
            return bool(available)
        return any(v.get("available") for v in self._variants_raw)

    # -- description ------------------------------------------------------

    @field
    def description(self) -> str | None:
        raw = self._product.get("content") or ""
        return _strip_html(raw)

    @field
    def description_html(self) -> str | None:
        raw = (self._product.get("content") or "").strip()
        return raw or None

    # -- images -------------------------------------------------------------

    @field
    def main_image(self) -> str | None:
        featured = self._product.get("featured_image")
        return self.urljoin(featured) if featured else None

    @field
    def images(self) -> list[str] | None:
        images = [self.urljoin(src) for src in self._product.get("images") or []]
        return images or None

    @field
    def image_count(self) -> int | None:
        images = [self.urljoin(src) for src in self._product.get("images") or []]
        return len(images) if images else None

    # -- options / selected variant -----------------------------------------

    @field
    def options(self) -> dict | None:
        names = self._option_names
        if not names:
            return None
        result = {}
        for i, name in enumerate(names):
            seen: list[str] = []
            for v in self._variants_raw:
                opts = v.get("options") or []
                if i < len(opts) and opts[i] is not None and opts[i] not in seen:
                    seen.append(opts[i])
            result[name] = seen
        return result or None

    @field
    def selected_color(self) -> str | None:
        return self._option_value(self._selected_variant, "Color")

    @field
    def selected_size(self) -> str | None:
        return self._option_value(self._selected_variant, "Size")

    @field
    def selected_variant_id(self) -> str | None:
        variant = self._selected_variant
        variant_id = variant.get("id") if variant else None
        return str(variant_id) if variant_id is not None else None

    @field
    def variants(self) -> list[ProductVariant] | None:
        result = []
        for v in self._variants_raw:
            price = _money(v.get("price"))
            regular_price = _money(v.get("compare_at_price"))
            result.append(
                ProductVariant(
                    variant_id=str(v["id"]) if v.get("id") is not None else None,
                    color=self._option_value(v, "Color") or v.get("option1"),
                    size=self._option_value(v, "Size") or v.get("option2"),
                    price=price,
                    regular_price=regular_price,
                    on_sale=bool(
                        regular_price is not None
                        and price is not None
                        and regular_price > price
                    ),
                    available=v.get("available"),
                    sku=v.get("sku"),
                )
            )
        return result or None

    @field
    def variant_count(self) -> int | None:
        variants = self._variants_raw
        return len(variants) if variants else None
