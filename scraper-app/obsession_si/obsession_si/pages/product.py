import json
import re
from functools import cached_property

from web_poet import WebPage, Returns, field

from obsession_si.items import ProductItem, ProductVariant


class ProductPage(WebPage, Returns[ProductItem]):
    @cached_property
    def _product_id(self) -> str | None:
        return self.css("main div[data-productid]::attr(data-productid)").get()

    @cached_property
    def _description_lines(self) -> list[str]:
        """Lines of the full-description block, split on <br> tags."""
        html = self.css("div.full-description").get()
        if not html:
            return []
        # Strip the wrapping <div ...> ... </div> tag itself.
        inner = re.sub(r"^<div[^>]*>|</div>\s*$", "", html.strip())
        parts = re.split(r"<br\s*/?>", inner)
        lines = []
        for part in parts:
            text = re.sub(r"<[^>]+>", "", part)
            text = text.replace("&bull;", "•").strip()
            text = _unescape_html(text)
            if text:
                lines.append(text)
        return lines

    @cached_property
    def _variant_models(self) -> list[dict]:
        """Authoritative per-variant data parsed from the inline variantModels script."""
        script = None
        for text in self.css("script::text").getall():
            if "variantModels" in text and "VariantModels:'" in text:
                script = text
                break
        if not script:
            return []
        match = re.search(
            r"VariantModels\s*:\s*'((?:[^'\\]|\\.)*)'", script, re.DOTALL
        )
        if not match:
            return []
        raw = match.group(1)
        raw = (
            raw.replace('\\"', '"')
            .replace("\\/", "/")
            .replace("\\r", "")
            .replace("\\n", "")
        )
        try:
            return json.loads(raw)
        except (json.JSONDecodeError, ValueError):
            return []

    @cached_property
    def _variant_prices(self) -> dict[str, str]:
        """Map variant_id -> lowest_price_30d from the discounted-price DOM blocks."""
        prices = {}
        for el in self.css("div.discounted-price.historic-price[data-id]"):
            variant_id = el.attrib.get("data-id")
            price = el.css("span::text").get()
            if variant_id and price:
                prices[variant_id] = price.strip()
        return prices

    @field
    def product_name(self) -> str | None:
        return self.css("div.product-name h1::text").get()

    @field
    def brand(self) -> str | None:
        href = self.css("div.manufacturers a::attr(href)").get()
        if not href:
            return None
        slug = href.rstrip("/").rsplit("/", 1)[-1]
        return slug.upper() or None

    @field
    def price(self) -> str | None:
        product_id = self._product_id
        if product_id:
            price = self.css(
                f'div.discounted-price.historic-price[data-id="{product_id}"] span::text'
            ).get()
            if price:
                return price.strip()
        price = self.css("div.discounted-price.historic-price span::text").get()
        return price.strip() if price else None

    @field
    def currency(self) -> str | None:
        return "EUR"

    @field
    def category(self) -> str | None:
        text = self.css("div.mainCategory::text").get()
        return text.strip() if text else None

    @field
    def breadcrumb(self) -> str | None:
        text = self.css("div.breadcrumb ul li:nth-last-child(2) a span::text").get()
        return text.strip() if text else None

    @field
    def product_id(self) -> str | None:
        return self._product_id

    @field
    def url(self) -> str | None:
        return str(self.response.url)

    @field
    def description(self) -> str | None:
        lines = self._description_lines
        return "\n".join(lines) if lines else None

    def _labeled_value(self, label: str) -> str | None:
        """Find a "LABEL: value" line; if the label has no inline value,
        fall back to the next non-empty, non-bulleted-header line (the
        site sometimes puts the label and its value on separate lines)."""
        lines = self._description_lines
        for i, line in enumerate(lines):
            match = re.search(rf"{label}:\s*(.*)", line)
            if not match:
                continue
            value = re.sub(r"^•\s*", "", match.group(1).strip()).strip()
            if value:
                return value
            if i + 1 < len(lines):
                next_line = lines[i + 1].lstrip("•").strip()
                if next_line and ":" not in next_line.split()[0]:
                    return next_line
        return None

    @field
    def composition(self) -> str | None:
        return self._labeled_value("SESTAVA")

    @field
    def fit(self) -> str | None:
        return self._labeled_value("FIT")

    @field
    def available_sizes(self) -> list[str] | None:
        sizes = self.css(
            "div.sizeSelector div.sizevariantselector::attr(data-size)"
        ).getall()
        return sizes or None

    @field
    def variants(self) -> list[ProductVariant] | None:
        models = self._variant_models
        if not models:
            return None
        prices = self._variant_prices
        result = []
        for model in models:
            variant_id = str(model.get("VariantId")) if model.get("VariantId") is not None else None
            sku = model.get("Sku")
            result.append(
                ProductVariant(
                    variant_id=variant_id,
                    size=model.get("Size"),
                    color=model.get("Color"),
                    sku=sku.strip() if sku else None,
                    lowest_price_30d=prices.get(variant_id) if variant_id else None,
                )
            )
        return result or None

    @field
    def images(self) -> list[str] | None:
        urls = self.css("div.variantImgs div.colorvariantselector img::attr(src)").getall()
        return urls or None

    @field
    def main_image(self) -> str | None:
        url = self.css("div.variantImgs div.colorvariantselector img::attr(src)").get()
        return url


def _unescape_html(text: str) -> str:
    return (
        text.replace("&amp;", "&")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&quot;", '"')
        .replace("&#39;", "'")
    )
