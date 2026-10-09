"""Page object for product detail pages on www.recharge.si."""

from __future__ import annotations

import json
import re
from functools import cached_property
from typing import Any

from parsel import Selector
from web_poet import Returns, WebPage, field, handle_urls

from recharge_si.items import ProductItem

# Slovenian number formatting: "." thousands separator, "," decimal separator.
_NUM_RE = re.compile(r"[\d.,]+")
_BR_RE = re.compile(r"(?i)<br\s*/?>")
_TRAILING_ELLIPSIS_RE = re.compile(r"(?:\s*(?:\.\.\.|…))+\s*$")


def _parse_number(text: str | None) -> float | None:
    """Parse a Slovenian-formatted number such as "2.699,00 €" into a float."""
    if not text:
        return None
    match = _NUM_RE.search(text.replace("\xa0", " "))
    if not match:
        return None
    raw = match.group(0).replace(".", "").replace(",", ".")
    try:
        return float(raw)
    except ValueError:
        return None


def _html_to_text(html: str) -> str:
    """Convert an HTML fragment to text, turning <br> into newlines."""
    text = _BR_RE.sub("\n", html)
    parts = Selector(text=text).xpath("//body//text()").getall()
    return "".join(parts)


@handle_urls("www.recharge.si")
class ProductPage(WebPage, Returns[ProductItem]):
    """Extracts product data from recharge.si detail pages."""

    # --- shared helpers -------------------------------------------------

    @cached_property
    def _jsonld_blocks(self) -> list[dict[str, Any]]:
        blocks: list[dict[str, Any]] = []
        for raw in self.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except ValueError:
                continue
            if isinstance(data, dict):
                candidates = data.get("@graph", [data])
            elif isinstance(data, list):
                candidates = data
            else:
                continue
            blocks.extend(c for c in candidates if isinstance(c, dict))
        return blocks

    def _jsonld_of_type(self, type_name: str) -> dict[str, Any]:
        for block in self._jsonld_blocks:
            types = block.get("@type")
            types = types if isinstance(types, list) else [types]
            if any(str(t).split("/")[-1] == type_name for t in types):
                return block
        return {}

    @cached_property
    def _product_ld(self) -> dict[str, Any]:
        return self._jsonld_of_type("Product")

    @cached_property
    def _offers_ld(self) -> dict[str, Any]:
        offers = self._product_ld.get("offers")
        if isinstance(offers, list):
            offers = offers[0] if offers else None
        return offers if isinstance(offers, dict) else {}

    @cached_property
    def _breadcrumb_ld(self) -> dict[str, Any]:
        return self._jsonld_of_type("BreadcrumbList")

    @cached_property
    def _attributes(self) -> list[dict[str, str]]:
        result: list[dict[str, str]] = []
        for row in self.css("div.feature-table ul li"):
            spans = row.css("span")
            if len(spans) < 2:
                continue
            name = "".join(spans[0].css("::text").getall()).strip().rstrip(":").strip()
            value = "".join(spans[1].css("::text").getall()).strip()
            if name and value:
                result.append({"name": name, "value": value})
        return result

    def _attribute(self, name: str) -> str | None:
        for entry in self._attributes:
            if entry["name"].casefold() == name.casefold():
                return entry["value"]
        return None

    # --- fields ---------------------------------------------------------

    @field
    def name(self) -> str | None:
        value = self._product_ld.get("name")
        if not value:
            value = self.css("div.product-name h1::text").get()
        return value.strip() if value else None

    @field
    def product_id(self) -> str | None:
        value = self.css("input#pID::attr(value)").get()
        if value and value.strip():
            return value.strip()
        main_image = self.main_image
        if main_image:
            match = re.search(r"/productpics/(\d+)_", main_image)
            if match:
                return match.group(1)
        return None

    @field
    def brand(self) -> str | None:
        brand = self._product_ld.get("brand")
        if isinstance(brand, dict):
            brand = brand.get("name")
        if not brand:
            brand = self.css("div.item-title figure img::attr(alt)").get()
        return brand.strip() if isinstance(brand, str) and brand.strip() else None

    @field
    def canonical_url(self) -> str | None:
        items = self._breadcrumb_items
        if items:
            url = items[-1].get("url")
            if url:
                return url
        href = self.css("ol.breadcrumb li:last-child a::attr(href)").get()
        if href:
            return self.urljoin(href)
        return str(self.response.url)

    @field
    def short_description(self) -> str | None:
        block = self.css("div.desc-short")
        if not block:
            return None
        parts = block[0].xpath(".//text()[not(ancestor::a)]").getall()
        text = " ".join(p.strip() for p in parts if p.strip()).strip()
        text = _TRAILING_ELLIPSIS_RE.sub("", text)
        return text or None

    @field
    def description(self) -> str | None:
        divs = self.css("section.item-desc > div")
        for div in divs:
            if div.attrib.get("class"):
                continue
            text = _html_to_text(div.get() or "")
            text = re.sub(r"[ \t]+\n", "\n", text)
            text = text.strip()
            if text:
                return text
        return None

    @field
    def price(self) -> float | None:
        raw = self._offers_ld.get("price")
        if raw is not None:
            try:
                return float(raw)
            except (TypeError, ValueError):
                pass
        return self.regular_price

    @field
    def regular_price(self) -> float | None:
        # On a discounted product the pre-discount price is struck through and
        # the current price sits in the sibling <strong>.
        value = _parse_number(self.css("div.price.action s::text").get())
        if value is not None:
            return value
        value = _parse_number(self.css("div.price div.regular-price strong::text").get())
        if value is not None:
            return value
        raw = self._offers_ld.get("price")
        try:
            return float(raw) if raw is not None else None
        except (TypeError, ValueError):
            return None

    @field
    def discount(self) -> str | None:
        # Detail pages spell the saving out as "prihranite 311,70 € (30 %)";
        # listing cards use a compact "-371 €" badge.
        percent = self.css("div.price.action").re_first(r"\(\s*(\d+(?:[.,]\d+)?)\s*%\s*\)")
        if percent:
            number = percent.replace(",", ".")
            if "." in number:
                number = number.rstrip("0").rstrip(".")
            return f"-{number} %"
        text = self.css("span.discount-box::text").get()
        text = text.strip() if text else ""
        return text or None

    @field
    def currency(self) -> str | None:
        currency = self._offers_ld.get("priceCurrency")
        if currency:
            return str(currency).strip()
        return "EUR" if self.css("div.price").re(r"€") else None

    @field
    def loyalty_price(self) -> float | None:
        return _parse_number(self.css("div.price.program strong::text").get())

    @field
    def loyalty_points_required(self) -> float | None:
        return _parse_number(self.css("div.price.program em::text").get())

    @field
    def availability(self) -> str | None:
        value = self._offers_ld.get("availability")
        if value:
            return str(value).rstrip("/").split("/")[-1]
        legend = self.css("div.stock-display span.stock-legend::text").get()
        if legend:
            return "InStock" if "ni na zalogi" not in legend.casefold() else "OutOfStock"
        return None

    @field
    def in_stock(self) -> bool | None:
        availability = self.availability
        if availability is None:
            return None
        return availability.casefold() == "instock"

    @field
    def availability_text(self) -> str | None:
        parts = self.css("div.stock-display span.stock ::text").getall()
        parts = [re.sub(r"\s+", " ", p).strip() for p in parts]
        text = " - ".join(p for p in parts if p)
        return text or None

    @field
    def free_delivery(self) -> bool:
        for block in self.css("div.price div.free-delivery"):
            text = " ".join(block.css("::text").getall()).casefold()
            if "brezplačno" in text:
                return True
        return bool(self.css("div.price div.free-delivery i.fa-truck"))

    @cached_property
    def _breadcrumb_items(self) -> list[dict[str, Any]]:
        entries: list[tuple[int, dict[str, Any]]] = []
        elements = self._breadcrumb_ld.get("itemListElement") or []
        for index, element in enumerate(elements):
            if not isinstance(element, dict):
                continue
            item = element.get("item")
            if isinstance(item, str):
                name, url = element.get("name"), item
            elif isinstance(item, dict):
                name, url = item.get("name"), item.get("@id") or item.get("url")
            else:
                name, url = element.get("name"), None
            if not name:
                continue
            try:
                position = int(element.get("position", index + 1))
            except (TypeError, ValueError):
                position = index + 1
            entries.append((position, {"name": str(name).strip(), "url": url}))

        if entries:
            result = [entry for _, entry in sorted(entries, key=lambda e: e[0])]
        else:
            result = []
            for li in self.css("ol.breadcrumb li"):
                name = " ".join(t.strip() for t in li.css("::text").getall()).strip()
                if not name:
                    continue
                href = li.css("a::attr(href)").get()
                result.append(
                    {"name": name, "url": self.urljoin(href) if href else None}
                )

        if result:
            result[-1]["url"] = None
        return result

    @field
    def breadcrumbs(self) -> list[dict[str, Any]] | None:
        return self._breadcrumb_items or None

    @field
    def category(self) -> str | None:
        items = self._breadcrumb_items
        if len(items) >= 2:
            return items[-2]["name"]
        return None

    @field
    def board_shape(self) -> str | None:
        return self._attribute("Oblika deske")

    @field
    def board_volume(self) -> str | None:
        return self._attribute("Volumen deske")

    @field
    def additional_properties(self) -> list[dict[str, str]] | None:
        return self._attributes or None

    @field
    def main_image(self) -> str | None:
        images = self.images
        if images:
            return images[0]
        href = self.css("div.product-image a::attr(href)").get()
        return self.urljoin(href) if href else None

    @field
    def images(self) -> list[str] | None:
        raw = self._product_ld.get("image")
        urls: list[str] = []
        if isinstance(raw, str):
            urls = [raw]
        elif isinstance(raw, list):
            for entry in raw:
                if isinstance(entry, str):
                    urls.append(entry)
                elif isinstance(entry, dict) and entry.get("url"):
                    urls.append(entry["url"])
        if not urls:
            urls = [
                self.urljoin(href)
                for href in self.css(
                    "div#slider1 ul.thumbnails li a::attr(href)"
                ).getall()
            ]
        urls = [self.urljoin(u) for u in urls if u]
        return urls or None
