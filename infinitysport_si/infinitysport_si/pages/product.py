"""Page object for infinitysport.si product detail pages."""

import json
import re
from functools import cached_property

from web_poet import WebPage, field


def _clean(text: str | None) -> str | None:
    if text is None:
        return None
    text = text.strip()
    return text or None


def _join_text(sel) -> str | None:
    """Join all descendant text nodes of a selector, collapsing whitespace."""
    parts = [t.strip() for t in sel.css("*::text").getall()]
    parts = [p for p in parts if p]
    if not parts:
        parts = [t.strip() for t in sel.xpath(".//text()").getall() if t.strip()]
    joined = " ".join(parts).strip()
    joined = re.sub(r"\s+", " ", joined)
    return joined or None


class ProductPage(WebPage[dict]):
    """Extracts product data from an infinitysport.si (PrestaShop) product page."""

    # -- shared helpers -----------------------------------------------

    @cached_property
    def _combinations(self) -> dict:
        """Parse the embedded `var combinations={...};` JS object into a dict
        keyed by combination_id."""
        text = self.response.text
        marker = "var combinations="
        start = text.find(marker)
        if start == -1:
            return {}
        brace_start = text.find("{", start)
        if brace_start == -1:
            return {}
        depth = 0
        i = brace_start
        in_string = False
        escape = False
        for i in range(brace_start, len(text)):
            ch = text[i]
            if in_string:
                if escape:
                    escape = False
                elif ch == "\\":
                    escape = True
                elif ch == '"':
                    in_string = False
            else:
                if ch == '"':
                    in_string = True
                elif ch == "{":
                    depth += 1
                elif ch == "}":
                    depth -= 1
                    if depth == 0:
                        break
        else:
            return {}
        raw = text[brace_start : i + 1]
        try:
            return json.loads(raw)
        except (json.JSONDecodeError, ValueError):
            return {}

    def _rte_list(self, heading_keyword: str) -> list[str] | None:
        """Find the <ul> following a heading (<p>) whose text contains the
        given keyword (case-insensitive), inside the description rte block,
        and return the cleaned text of each <li>."""
        keyword = heading_keyword.lower()
        for p in self.css("#idTab1 .rte p"):
            heading_text = " ".join(p.css("*::text").getall()).strip().lower()
            if keyword in heading_text:
                # The heading <p> and its <ul> aren't always direct siblings
                # (some pages wrap the heading in extra nested divs), so look
                # for the next <ul> anywhere after it in document order.
                ul = p.xpath("following::ul[1]")
                if not ul:
                    continue
                items = [_join_text(li) for li in ul.css("li")]
                items = [i for i in items if i]
                return items or None
        return None

    @cached_property
    def _feature_list(self) -> list[str] | None:
        return self._rte_list("lastnosti")

    @cached_property
    def _material_list(self) -> list[str] | None:
        return self._rte_list("material")

    @cached_property
    def _data_sheet(self) -> dict:
        result = {}
        for row in self.css("table.table-data-sheet tr"):
            cells = row.css("td")
            if len(cells) < 2:
                continue
            key = _clean(cells[0].css("*::text").get() or cells[0].xpath("string(.)").get())
            value = _clean(cells[1].css("*::text").get() or cells[1].xpath("string(.)").get())
            if key:
                result[key] = value
        return result

    # -- basic fields ---------------------------------------------------

    @field
    def name(self) -> str | None:
        return _clean(self.css("h1[itemprop='name']::text").get())

    @field
    def price(self) -> str | None:
        return _clean(self.css("#our_price_display::text").get())

    @field
    def price_currency(self) -> str | None:
        return _clean(self.css("meta[itemprop='priceCurrency']::attr(content)").get())

    @field
    def sku_product_id(self) -> str | None:
        return _clean(self.css("#product_page_product_id::attr(value)").get())

    @field
    def availability(self) -> str | None:
        href = self.css("link[itemprop='availability']::attr(href)").get()
        if not href:
            return None
        return href.rstrip("/").rsplit("/", 1)[-1] or None

    @field
    def main_image(self) -> str | None:
        src = self.css("#bigpic::attr(src)").get()
        return self.urljoin(src) if src else None

    @field
    def images(self) -> list[str] | None:
        result = []
        main = self.main_image
        if main:
            result.append(main)
        for src in self.css("#thumbs_list_frame img::attr(src)").getall():
            url = self.urljoin(src)
            if url:
                result.append(url)
        return result or None

    @field
    def description(self) -> str | None:
        features = self._feature_list
        materials = self._material_list
        if not features and not materials:
            return None
        feature_str = "; ".join(features) if features else ""
        material_str = "; ".join(materials) if materials else ""
        return f"Lastnosti: {feature_str}. Material: {material_str}.".strip()

    @field
    def old_price(self) -> str | None:
        old = _clean(self.css("#old_price_display::text").get())
        if old:
            return old
        return self.price

    @field
    def stock_status_text(self) -> str | None:
        return _clean(self.css("[itemprop='quantity']::text").get())

    @field
    def low_stock_warning(self) -> str | None:
        return _clean(self.css("#last_quantities::text").get())

    @field
    def combination_id(self) -> str | None:
        # The hidden #idCombination input is only populated client-side by
        # JS on the raw (non-rendered) response, so it's often empty there.
        # Fall back to the variant marked "selected" in size_variants.
        value = _clean(self.css("#idCombination::attr(value)").get())
        if value:
            return value
        for variant in self.size_variants or []:
            if variant.get("selected"):
                return variant.get("combination_id")
        return None

    @field
    def breadcrumbs(self) -> list[str] | None:
        # Walk the container's direct child nodes (elements *and* bare text
        # nodes) in document order: the current page's own name is often a
        # trailing text node rather than an <a>, not just a child element.
        result = []
        for node in self.xpath(".//div[contains(@class,'breadcrumb')][contains(@class,'clearfix')][1]/node()"):
            if isinstance(node.root, str):
                text = _clean(node.root)
            else:
                if "navigation-pipe" in (node.attrib.get("class") or ""):
                    continue
                text = _join_text(node)
            if text:
                result.append(text)
        return result or None

    @field
    def brand_logo_image(self) -> str | None:
        src = self.css("span.product-logo img::attr(src)").get()
        return self.urljoin(src) if src else None

    @field
    def size_variants(self) -> list[dict] | None:
        combinations = self._combinations

        radios = {}
        for li in self.css("fieldset.attribute_fieldset .attribute_list li, #attributes fieldset.attribute_fieldset ul li"):
            radio = li.css("input.attribute_radio")
            if not radio:
                continue
            value_id = radio.attrib.get("value")
            if not value_id:
                continue
            selected = radio.attrib.get("checked") is not None or bool(
                li.css("span.checked")
            )
            label = _clean(li.css("span.selected-size-wrapper::text").get())
            if label is None:
                label = _clean(_join_text(li.css("span.selected-size-wrapper")))
            radios[value_id] = {"label": label, "selected": selected}

        result = []
        if combinations:
            for combination_id, data in combinations.items():
                attrs = data.get("attributes") or []
                value_id = str(attrs[0]) if attrs else None
                radio_info = radios.get(value_id, {})
                quantity = data.get("quantity")
                in_stock = bool(quantity) if quantity is not None else bool(
                    data.get("inf_stock")
                )
                result.append(
                    {
                        "value_id": value_id,
                        "label": radio_info.get("label"),
                        "selected": radio_info.get("selected", False),
                        "combination_id": str(combination_id),
                        "quantity": quantity,
                        "in_stock": in_stock,
                    }
                )
        else:
            for value_id, info in radios.items():
                result.append(
                    {
                        "value_id": value_id,
                        "label": info.get("label"),
                        "selected": info.get("selected", False),
                        "combination_id": None,
                        "quantity": None,
                        "in_stock": None,
                    }
                )
        return result or None

    @field
    def style_attribute(self) -> str | None:
        return self._data_sheet.get("Slog")

    @field
    def data_sheet(self) -> dict | None:
        return self._data_sheet or None

    @field
    def feature_list(self) -> list[str] | None:
        return self._feature_list

    @field
    def material_list(self) -> list[str] | None:
        return self._material_list

    @field
    def accessories(self) -> list[dict] | None:
        result = []
        for row in self.css("table.accessorygroup tr"):
            name = _clean(row.css("a.ma_accessory_name::text").get())
            url = row.css("a.ma_accessory_name::attr(href)").get()
            if not name or not url:
                continue
            list_price = _clean(
                row.css("span[class*='line_though']::text").get()
            )
            discount_price = _clean(
                row.css("span[class^='discount_price']::text").get()
            )
            if list_price is None:
                # no strikethrough pair present: the single visible price
                # is the list price, no discount active
                list_price = _clean(row.css("span.accessory_price::text").get())
            result.append(
                {
                    "name": name,
                    "url": self.urljoin(url),
                    "list_price": list_price,
                    "discount_price": discount_price,
                }
            )
        return result or None

    @field
    def related_products(self) -> list[dict] | None:
        result = []
        for item in self.css("#product_category .item"):
            link = item.css("h5.product-name a")
            if not link:
                continue
            name = _clean(link.attrib.get("title")) or _clean(link.css("::text").get())
            url = link.attrib.get("href")
            if not name or not url:
                continue
            result.append({"name": name, "url": self.urljoin(url)})
        return result or None
