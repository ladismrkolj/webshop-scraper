"""Page object for GONG Galaxy (Shopify) product detail pages."""

from __future__ import annotations

import html as html_lib
import json
import re
from functools import cached_property
from typing import Any

import extruct
import parsel
from web_poet import WebPage, field, handle_urls

from gong_galaxy_com.items import ProductItem, ProductVariant, RelatedAddon

# Inline Shopify product objects appear under different variable names
# depending on which theme apps are installed on the page.
_PRODUCT_JSON_ANCHORS = (
    r"var\s+productInfo\s*=\s*",
    r"window\.SERVICIFY_PRODUCT\s*=\s*(?:[^;{]*\|\|\s*)?",
    r"ALMA_PRODUCT_DATA\s*=\s*",
)

# Titles are prefixed with the brand or the product family ("GONG | ...",
# "Pack | ..."). Only strip a short, separator-free leading segment.
_BRAND_PREFIX_RE = re.compile(r"^[^|]{1,20}\|\s*(?=\S)")
_VARIANT_QS_RE = re.compile(r"[?&]variant=(\d+)")

# option name -> item field, for the localized (French) Shopify option axes
_OPTION_FIELD_MAP = {
    "taille": "size",
    "size": "size",
    "couleur": "color",
    "color": "color",
    "colour": "color",
    "type": "type",
}


def _extract_braced(text: str, start: int) -> str | None:
    """Return the balanced ``{...}`` block that starts at/after ``start``."""
    begin = text.find("{", start)
    if begin == -1:
        return None
    depth = 0
    in_str: str | None = None
    escaped = False
    for i in range(begin, len(text)):
        ch = text[i]
        if in_str:
            if escaped:
                escaped = False
            elif ch == "\\":
                escaped = True
            elif ch == in_str:
                in_str = None
            continue
        if ch in "\"'":
            in_str = ch
        elif ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                return text[begin : i + 1]
    return None


def _loads_relaxed(blob: str) -> dict[str, Any] | None:
    """Parse JSON, tolerating the JS-object-literal dialect (unquoted keys,
    trailing commas) used by some of the inline product objects."""
    try:
        return json.loads(blob)
    except ValueError:
        pass
    relaxed = re.sub(r"([{,])\s*([A-Za-z_$][\w$]*)\s*:", r'\1"\2":', blob)
    relaxed = re.sub(r",\s*([}\]])", r"\1", relaxed)
    try:
        return json.loads(relaxed)
    except ValueError:
        return None


def _parse_eu_money(raw: str | None) -> float | None:
    """Parse a French/EU formatted amount such as ``"1.234,56€"``."""
    if not raw:
        return None
    cleaned = re.sub(r"[^\d,.]", "", raw.replace("\xa0", ""))
    if not cleaned:
        return None
    if "," in cleaned:
        cleaned = cleaned.replace(".", "").replace(",", ".")
    try:
        return float(cleaned)
    except ValueError:
        return None


def _cents(value: Any) -> float | None:
    """Convert an integer-cents money value from the inline product object."""
    if value is None or isinstance(value, bool):
        return None
    try:
        return round(int(value) / 100, 2)
    except (TypeError, ValueError):
        return None


def _availability_token(value: Any) -> str | None:
    """``http://schema.org/InStock`` -> ``InStock`` (note: http, not https)."""
    if not isinstance(value, str) or not value:
        return None
    return value.rstrip("/").rsplit("/", 1)[-1]


def _collapse(text: str | None) -> str | None:
    if text is None:
        return None
    text = html_lib.unescape(text).replace("\xa0", " ")
    text = re.sub(r"\s+", " ", text).strip()
    return text or None


# Tags that end a line of prose. Inline tags (<strong>, <em>, <a>) are
# deliberately absent: text split across them must be rejoined with no
# separator, or words like "the<strong> easy</strong>" come out doubled-spaced.
_BLOCK_TAG_RE = re.compile(
    r"</?(?:p|div|br|h[1-6]|li|ul|ol|tr|table|section|article|header|footer)\b[^>]*>",
    re.I,
)


def _block_text(fragment: str | None) -> str | None:
    """Text of an HTML fragment with block structure kept as newlines.

    Text nodes inside a block are joined with no separator; each block becomes
    its own line. Blank lines are dropped so the result is stable regardless of
    how the theme nests its wrappers.
    """
    if not fragment:
        return None
    marked = _BLOCK_TAG_RE.sub("\n", fragment)
    parts = parsel.Selector(text=marked).xpath("//text()").getall()
    text = html_lib.unescape("".join(parts)).replace("\xa0", " ")
    lines = [re.sub(r"[^\S\n]+", " ", line).strip() for line in text.split("\n")]
    return "\n".join(line for line in lines if line) or None


@handle_urls("www.gong-galaxy.com")
class ProductPage(WebPage[ProductItem]):
    """Product detail page.

    Data comes from three complementary sources:

    * the inline Shopify product object (``var productInfo`` /
      ``window.SERVICIFY_PRODUCT`` / ``ALMA_PRODUCT_DATA``) — the only source
      with ``compare_at_price`` and per-variant option values; money is in
      **integer cents** there;
    * JSON-LD (``Product`` for brand/currency/availability, ``BreadcrumbList``
      for the trail) — money is in decimal units there;
    * the DOM, for the gallery, the buybox tagline and the cross-sell strip.
    """

    # ------------------------------------------------------------------
    # shared helpers
    # ------------------------------------------------------------------

    @cached_property
    def _product_json(self) -> dict[str, Any]:
        """First inline Shopify product object that parses, in priority order."""
        text = self.response.text
        for anchor in _PRODUCT_JSON_ANCHORS:
            for match in re.finditer(anchor, text):
                blob = _extract_braced(text, match.end())
                if not blob:
                    continue
                data = _loads_relaxed(blob)
                if isinstance(data, dict) and ("variants" in data or "id" in data):
                    return data
        return {}

    @cached_property
    def _metadata(self) -> dict[str, Any]:
        return extruct.extract(
            self.response.text,
            base_url=self.url,
            syntaxes=["json-ld"],
        )

    @cached_property
    def _jsonld_product(self) -> dict[str, Any]:
        for entry in self._metadata.get("json-ld", []):
            if entry.get("@type") == "Product":
                return entry
        return {}

    @cached_property
    def _jsonld_breadcrumbs(self) -> dict[str, Any]:
        for entry in self._metadata.get("json-ld", []):
            if entry.get("@type") == "BreadcrumbList":
                return entry
        return {}

    @cached_property
    def _offers(self) -> list[dict[str, Any]]:
        offers = self._jsonld_product.get("offers")
        if isinstance(offers, dict):
            return [offers]
        if isinstance(offers, list):
            return [o for o in offers if isinstance(o, dict)]
        return []

    @cached_property
    def _offers_by_variant_id(self) -> dict[str, dict[str, Any]]:
        result: dict[str, dict[str, Any]] = {}
        for offer in self._offers:
            match = _VARIANT_QS_RE.search(offer.get("url") or "")
            if match:
                result[match.group(1)] = offer
        return result

    @cached_property
    def _offers_by_sku(self) -> dict[str, dict[str, Any]]:
        return {o["sku"]: o for o in self._offers if o.get("sku")}

    @cached_property
    def _raw_variants(self) -> list[dict[str, Any]]:
        variants = self._product_json.get("variants")
        if isinstance(variants, list):
            return [v for v in variants if isinstance(v, dict)]
        return []

    @cached_property
    def _option_names(self) -> list[str]:
        """Positional option names, e.g. ``["Type", "Taille", "Couleur"]``."""
        names = self._product_json.get("options")
        if isinstance(names, list):
            out = []
            for name in names:
                if isinstance(name, dict):  # some Shopify payloads nest them
                    name = name.get("name")
                if isinstance(name, str):
                    out.append(name)
            return out
        return []

    @cached_property
    def _selected_variant_id(self) -> str | None:
        # the add-to-cart form's hidden input reflects the actual selection,
        # including when the URL carries ?variant=. Exact-match the name so the
        # cross-sell inputs (items[1][id], ...) are not picked up.
        value = self.css('form input[name="id"]::attr(value)').get()
        if value and value.strip().isdigit():
            return value.strip()
        match = _VARIANT_QS_RE.search(self.url)
        if match:
            return match.group(1)
        for offer in self._offers:
            match = _VARIANT_QS_RE.search(offer.get("url") or "")
            if match:
                return match.group(1)
        if self._raw_variants:
            vid = self._raw_variants[0].get("id")
            if vid is not None:
                return str(vid)
        return None

    @cached_property
    def _selected_raw_variant(self) -> dict[str, Any]:
        selected = self._selected_variant_id
        if selected:
            for variant in self._raw_variants:
                if str(variant.get("id")) == selected:
                    return variant
        return self._raw_variants[0] if self._raw_variants else {}

    @cached_property
    def _selected_offer(self) -> dict[str, Any]:
        selected = self._selected_variant_id
        if selected and selected in self._offers_by_variant_id:
            return self._offers_by_variant_id[selected]
        sku = self._selected_raw_variant.get("sku")
        if sku and sku in self._offers_by_sku:
            return self._offers_by_sku[sku]
        return self._offers[0] if self._offers else {}

    @cached_property
    def _currency(self) -> str | None:
        for offer in ([self._selected_offer] if self._selected_offer else []) + self._offers:
            currency = offer.get("priceCurrency")
            if currency:
                return currency
        return self.css('meta[property="og:price:currency"]::attr(content)').get()

    @cached_property
    def _title(self) -> str | None:
        """Canonical product title, brand prefix included.

        ``<title>`` and ``<h1>`` are unreliable here (some pages drop the
        ``GONG | `` prefix and append the selected colour), so the inline
        product object and og:title come first.
        """
        title = self._product_json.get("title")
        if isinstance(title, str) and title.strip():
            return title.strip()
        og_title = self.css('meta[property="og:title"]::attr(content)').get()
        if og_title and og_title.strip():
            return og_title.strip()
        text = self.css("title::text").get()
        return text.strip() if text else None

    def _variant_price(self, variant: dict[str, Any]) -> float | None:
        price = _cents(variant.get("price"))
        if price is not None:
            return price
        offer = self._offers_by_variant_id.get(str(variant.get("id")))
        if offer is None and variant.get("sku"):
            offer = self._offers_by_sku.get(variant["sku"])
        if offer and offer.get("price") is not None:
            try:
                return round(float(offer["price"]), 2)
            except (TypeError, ValueError):
                return None
        return None

    # ------------------------------------------------------------------
    # fields
    # ------------------------------------------------------------------

    @field
    def url(self) -> str | None:
        return str(self.response.url)

    @field
    def canonical_url(self) -> str | None:
        href = self.css("link[rel=canonical]::attr(href)").get()
        if not href:
            href = self.css('meta[property="og:url"]::attr(content)').get()
        return self.urljoin(href.strip()) if href else None

    @field
    def page_title(self) -> str | None:
        return self._title

    @field
    def name(self) -> str | None:
        title = self._title
        if not title:
            return None
        return _BRAND_PREFIX_RE.sub("", title).strip() or None

    @field
    def subtitle(self) -> str | None:
        # scope to the direct child div: the wrapper also holds the
        # "See Description" button, and on some pages the text is inside <p>.
        parts = self.css(".product-buybox__description-short > div ::text").getall()
        if not parts:
            return None
        return _collapse("".join(parts))

    @field
    def brand(self) -> str | None:
        brand = self._jsonld_product.get("brand")
        if isinstance(brand, dict):
            brand = brand.get("name")
        if isinstance(brand, str) and brand.strip():
            return brand.strip()
        vendor = self._product_json.get("vendor")
        return vendor.strip() if isinstance(vendor, str) and vendor.strip() else None

    @field
    def sku(self) -> str | None:
        sku = self._selected_raw_variant.get("sku") or self._selected_offer.get("sku")
        if not sku:
            sku = self._jsonld_product.get("sku")
        return str(sku) if sku else None

    @field
    def product_id(self) -> str | None:
        pid = self._product_json.get("id")
        if pid is not None:
            return str(pid)
        nosto = self.css("div.nosto_product > span.product_id::text").get()
        if nosto and nosto.strip():
            return nosto.strip()
        st = self.css("script#__st::text").get()
        if st:
            match = re.search(r'"rid"\s*:\s*(\d+)', st)
            if match:
                return match.group(1)
        return None

    @field
    def selected_variant_id(self) -> str | None:
        return self._selected_variant_id

    @field
    def product_handle(self) -> str | None:
        handle = self._product_json.get("handle")
        if isinstance(handle, str) and handle.strip():
            return handle.strip()
        for candidate in (self.url, self.css("link[rel=canonical]::attr(href)").get() or ""):
            match = re.search(r"/products/([^/?#]+)", candidate)
            if match:
                return match.group(1)
        return self.css("[data-product-handle]::attr(data-product-handle)").get()

    @field
    def price(self) -> float | None:
        price = self._variant_price(self._selected_raw_variant)
        if price is not None:
            return price
        # last resort: the visible buybox price (French formatting)
        raw = self.css(".product-buybox__price .price-item--sale::text").get()
        if not raw or not raw.strip():
            raw = self.css(
                ".product-buybox__price .price-container__regular"
                " .price-item--regular::text"
            ).get()
        return _parse_eu_money(raw)

    @field
    def regular_price(self) -> float | None:
        """Compare-at price; falls back to ``price`` when not discounted.

        JSON-LD carries no compare-at price at all, so this is the inline
        product object first, then two narrowly scoped DOM sources.
        """
        price = self.price
        candidates = [
            _cents(self._selected_raw_variant.get("compare_at_price")),
            _cents(self._product_json.get("compare_at_price")),
            # direct child only: a bare .list_price matches Nosto tagging for
            # every recommended product on the page.
            _parse_eu_money(self.css("div.nosto_product > span.list_price::text").get()),
            _parse_eu_money(
                self.css(".product-buybox__price s.price-item--regular::text").get()
            ),
        ]
        for candidate in candidates:
            if candidate is not None and (price is None or candidate > price):
                return candidate
        return price

    @field
    def on_sale(self) -> bool | None:
        price, regular = self.price, self.regular_price
        if price is None or regular is None:
            return None
        return regular > price

    @field
    def currency(self) -> str | None:
        return self._currency

    @field
    def availability(self) -> str | None:
        available = self._selected_raw_variant.get("available")
        if isinstance(available, bool):
            return "InStock" if available else "OutOfStock"
        token = _availability_token(self._selected_offer.get("availability"))
        if token:
            return token
        available = self._product_json.get("available")
        if isinstance(available, bool):
            return "InStock" if available else "OutOfStock"
        return None

    @field
    def in_stock(self) -> bool | None:
        availability = self.availability
        return None if availability is None else availability == "InStock"

    @field
    def description(self) -> str | None:
        raw = self._product_json.get("description")
        if not isinstance(raw, str) or not raw.strip():
            raw = self._jsonld_product.get("description")
        if isinstance(raw, str) and raw.strip():
            return _block_text(raw)
        block = self.css(".js-product-description").get()
        return _block_text(block)

    @field
    def meta_description(self) -> str | None:
        content = self.css('meta[name="description"]::attr(content)').get()
        if not content:
            content = self.css('meta[property="og:description"]::attr(content)').get()
        return content.strip() if content and content.strip() else None

    @field
    def main_image(self) -> str | None:
        images = self.images
        return images[0] if images else None

    @field
    def images(self) -> list[str] | None:
        """Gallery images, order-preserving deduplicated.

        The swiper clones the first slide and the featured image repeats, so
        the raw selector yields more entries than there are distinct images.
        The inline product object's ``images`` array is NOT usable: wrong
        count and no ``&width=`` parameter.
        """
        srcs = self.css(".js-product-gallery-item img.product-media::attr(src)").getall()
        if not srcs:
            srcs = self.css(".product-gallery-item img.product-media::attr(src)").getall()
        urls = [self.urljoin(src.strip()) for src in srcs if src and src.strip()]
        return list(dict.fromkeys(urls)) or None

    @field
    def breadcrumbs(self) -> list[str] | None:
        items = self._jsonld_breadcrumbs.get("itemListElement")
        if not isinstance(items, list):
            return None
        ordered = sorted(
            (i for i in items if isinstance(i, dict)),
            key=lambda i: i.get("position") or 0,
        )
        names = [i["name"] for i in ordered if isinstance(i.get("name"), str)]
        return names or None

    @field
    def category(self) -> str | None:
        crumbs = self.breadcrumbs
        if not crumbs or len(crumbs) < 3:
            return None
        return " > ".join(crumbs[1:-1]) or None

    @field
    def options(self) -> dict[str, Any] | None:
        """Option names mapped to their values, in first-appearance order.

        Names are French (``Taille``, ``Couleur``) even on the /en storefront —
        emit them verbatim. Never sort the values.
        """
        options: dict[str, list[str]] = {}
        names = self._option_names
        # A single "Title"/"Default Title" axis means the product has no real
        # Shopify options (pack products) — fall through to the DOM pickers.
        meaningful = [n for n in names if n.strip().lower() != "title"]
        if meaningful and self._raw_variants:
            for index, name in enumerate(names, start=1):
                if name.strip().lower() == "title":
                    continue
                values: list[str] = []
                for variant in self._raw_variants:
                    value = variant.get(f"option{index}")
                    if isinstance(value, str) and value and value not in values:
                        values.append(value)
                if values:
                    options[name] = values
            if options:
                return options

        return self._dom_options() or None

    def _dom_options(self) -> dict[str, list[str]]:
        """Options read from the ``<variant-radios>`` pickers.

        Used for pack products, whose selectable options belong to component
        products rather than to the pack's own (single) Shopify variant.
        """
        blocks = self.css("variant-radios")
        # data-default-variant holds JSON with UNESCAPED double quotes, so lxml
        # mangles the attribute — read the open tags off the raw HTML instead.
        raw_tags = re.findall(r"<variant-radios\b[^>]*>", self.response.text)
        options: dict[str, list[str]] = {}
        multi = len(blocks) > 1
        for index, block in enumerate(blocks):
            component = None
            if multi and index < len(raw_tags):
                component = self._component_name(raw_tags[index])
            for fieldset in block.css("fieldset.product-options"):
                key = fieldset.css("legend::attr(data-option-name)").get()
                values = fieldset.css(
                    "input.product-variant-option::attr(value)"
                ).getall()
                values = [v.strip() for v in values if v and v.strip()]
                if not key:
                    key = fieldset.css("input.product-variant-option::attr(name)").get()
                # The markup lowercases these ("taille"), while the Shopify
                # options array capitalizes them ("Taille"). Normalize so both
                # code paths produce the same keys.
                if key:
                    key = key.strip()
                    key = key[:1].upper() + key[1:]
                if not key or not values:
                    continue
                if component:
                    key = f"{key} ({component})"
                options.setdefault(key, [])
                for value in values:
                    if value not in options[key]:
                        options[key].append(value)
        return options

    @staticmethod
    def _component_name(raw_tag: str) -> str | None:
        """Component product title from a pack's raw ``<variant-radios>`` tag."""
        match = re.search(r'"name":"(.*?)","public_title":"(.*?)"', raw_tag)
        if not match:
            return None
        name, public_title = match.group(1), match.group(2)
        if public_title and name.endswith(f" - {public_title}"):
            name = name[: -len(f" - {public_title}")]
        return name or None

    @field
    def variants(self) -> list[ProductVariant] | None:
        """All variants, including out-of-stock ones (never filter them out)."""
        canonical = self.canonical_url or self.url
        currency = self._currency
        # positional option index -> item field name, via the option labels
        axes: dict[int, str] = {}
        for index, name in enumerate(self._option_names, start=1):
            mapped = _OPTION_FIELD_MAP.get(name.strip().lower())
            if mapped:
                axes[index] = mapped

        result: list[ProductVariant] = []
        for raw in self._raw_variants:
            variant_id = raw.get("id")
            variant_id = str(variant_id) if variant_id is not None else None
            price = self._variant_price(raw)
            regular = _cents(raw.get("compare_at_price"))
            if regular is None or (price is not None and regular <= price):
                regular = price
            available = raw.get("available")
            if not isinstance(available, bool):
                offer = self._offers_by_variant_id.get(variant_id or "")
                token = _availability_token((offer or {}).get("availability"))
                available = token == "InStock" if token else None
            values = {"size": None, "color": None, "type": None}
            for index, target in axes.items():
                value = raw.get(f"option{index}")
                if isinstance(value, str) and value and value != "Default Title":
                    values[target] = value
            result.append(
                ProductVariant(
                    variant_id=variant_id,
                    sku=raw.get("sku") or None,
                    size=values["size"],
                    color=values["color"],
                    type=values["type"],
                    price=price,
                    regular_price=regular,
                    currency=currency,
                    availability=(
                        None
                        if available is None
                        else ("InStock" if available else "OutOfStock")
                    ),
                    available=available,
                    url=(
                        f"{canonical}?variant={variant_id}"
                        if canonical and variant_id
                        else None
                    ),
                )
            )

        if result:
            return result

        # no inline product object: fall back to the JSON-LD offers alone
        for offer in self._offers:
            match = _VARIANT_QS_RE.search(offer.get("url") or "")
            price = offer.get("price")
            try:
                price = round(float(price), 2) if price is not None else None
            except (TypeError, ValueError):
                price = None
            availability = _availability_token(offer.get("availability"))
            result.append(
                ProductVariant(
                    variant_id=match.group(1) if match else None,
                    sku=offer.get("sku") or None,
                    price=price,
                    regular_price=price,
                    currency=offer.get("priceCurrency") or currency,
                    availability=availability,
                    available=None if availability is None else availability == "InStock",
                    url=offer.get("url") or None,
                )
            )
        return result or None

    @field
    def variant_count(self) -> int | None:
        variants = self.variants
        return len(variants) if variants else None

    @field
    def related_addons(self) -> list[RelatedAddon] | None:
        """Cross-sell add-ons — only the rendered cards count.

        The form also carries hidden ``items[n][id]`` cross-sell inputs and the
        page embeds a Nosto recommendations blob; neither belongs here.
        """
        addons: list[RelatedAddon] = []
        for card in self.css("div.product-bundle-card"):
            name = card.css(".product-bundle-card-title::text").get()
            variant = card.css(".product-bundle-card-option::text").get()
            if not (variant and variant.strip()):
                variant = card.css(".product-bundle-card-option-pack::text").get()
            if not (variant and variant.strip()):
                # populated on pack cards where the option span is empty
                variant = card.css("img.product-bundle-variant-image::attr(alt)").get()
            price = card.css(
                ".product-bundle-card-button__text::attr(data-price)"
            ).get()
            if price is None:
                price = card.css(".product-bundle-card-button__text::text").get()
            addons.append(
                RelatedAddon(
                    name=name.strip() if name and name.strip() else None,
                    variant=variant.strip() if variant and variant.strip() else None,
                    price=_parse_eu_money(price),
                    variant_id=card.css(
                        "w3-product-bundle-item::attr(data-variant-id)"
                    ).get(),
                )
            )
        return addons or None
