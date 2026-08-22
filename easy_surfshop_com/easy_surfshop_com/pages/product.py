import json
import re
from functools import cached_property
from urllib.parse import urljoin

from price_parser import Price
from web_poet import Returns, WebPage, field, handle_urls

from easy_surfshop_com.items import ProductItem

# ``easy-surfshop.com`` serves different product pages through (at least) two
# different underlying shop-platform templates (a "Hummerce"-style template and
# an "eSklep/EC"-style template). Both share the same overall page structure,
# but differ in small details (see comments below). The selectors here try to
# be robust to both variants.

CARD_HEADING_TAGS = {"h3", "h4", "h5"}


def _norm(text: str | None) -> str | None:
    """Collapse whitespace; return None for empty results."""
    if text is None:
        return None
    result = " ".join(text.split())
    return result or None


def _text(el) -> str:
    """Whitespace-collapsed text of an lxml element and its descendants."""
    return " ".join("".join(el.itertext()).split())


def _money_float(text: str | None) -> float | None:
    if not text:
        return None
    price = Price.fromstring(text)
    return price.amount_float


@handle_urls("easy-surfshop.com")
class ProductPage(WebPage, Returns[ProductItem]):
    # ------------------------------------------------------------------
    # Shared helpers
    # ------------------------------------------------------------------

    @cached_property
    def _base_href(self) -> str:
        # The site declares <base href="https://.../"> in <head> and most
        # in-body links are root-relative WITHOUT a leading slash (e.g.
        # "do/cat/..."). They must be resolved against that base href, NOT
        # against the response/page URL, or the joined URL is wrong
        # (e.g. ".../do/item/XXX/do/cat/...").
        base = self.css("base::attr(href)").get()
        return base or self.urljoin("/")

    def _abs(self, href: str | None) -> str | None:
        if not href:
            return None
        return urljoin(self._base_href, href)

    @cached_property
    def _product_json(self) -> dict:
        # A compact product JSON is embedded in a <script data-event="..."
        # type="application/json"> block: "viewItem" (base product) on some
        # pages, "productsList" (per variant) on others. Both carry the same
        # keys (name, id, price, currencyCode, brand, category...). Text is
        # tab-indented but valid JSON.
        for event in ("viewItem", "productsList"):
            text = self.css(f'script[data-event="{event}"]::text').get()
            if text:
                try:
                    return json.loads(text)
                except (ValueError, TypeError):
                    continue
        return {}

    @cached_property
    def _save_price_wrappers(self) -> dict:
        # Discount info (regular price / discount % / "you save") lives in
        # sibling `div.savePriceWrapper` blocks, one per variant SKU, keyed
        # by `data-subitem`. The `data-regularprice`/`data-yousave`
        # attributes on the `tr.subitem` rows themselves are often empty.
        out = {}
        for wrapper in self.css("div.savePriceWrapper"):
            key = wrapper.attrib.get("data-subitem")
            if key:
                out[key] = wrapper
        return out

    @cached_property
    def _variant_rows(self):
        # `tr.subitem` also matches one extra "parent" row representing the
        # base product (data-isparent="true", empty size/price) - skip it.
        return [
            tr
            for tr in self.css("tr.subitem")
            if tr.attrib.get("data-isparent") != "true"
        ]

    @cached_property
    def _description_sections(self) -> dict:
        """Walk the description tab and bucket content into
        intro / highlights / features, tolerating the two known markup
        variants (h2 vs h2>strong section titles, h3/h4>strong card
        titles)."""
        sections: dict = {"intro": [], "highlights": [], "features": []}
        containers = self.css('#OPIS_PRODUKTU, div[itemprop="description"]')
        if not containers:
            return sections
        root = containers[0].root

        current = "intro"
        for el in root.iter():
            tag = getattr(el, "tag", None)
            if not isinstance(tag, str):
                continue
            if tag in ("script", "style"):
                continue
            if tag == "h2":
                heading = (_text(el) or "").lower()
                if "highlight" in heading:
                    current = "highlights"
                elif "feature" in heading:
                    current = "features"
                else:
                    current = "other"
                continue
            if tag == "p" and current == "intro":
                text = _text(el)
                if text:
                    sections["intro"].append(text)
                continue
            if tag == "div" and current in ("highlights", "features"):
                classes = el.get("class", "")
                if "col-" not in classes or "row" in classes.split():
                    continue
                heading_el = None
                for child in el:
                    child_tag = getattr(child, "tag", None)
                    if child_tag in CARD_HEADING_TAGS:
                        heading_el = child
                        break
                if heading_el is None:
                    continue
                title = _text(heading_el)
                if not title:
                    continue
                paragraphs = [
                    _text(p) for p in el if getattr(p, "tag", None) == "p"
                ]
                paragraphs = [p for p in paragraphs if p]
                if not paragraphs:
                    continue
                text = " ".join(paragraphs)
                sections[current].append({"title": title, "text": text})
        return sections

    # ------------------------------------------------------------------
    # Identity / navigation
    # ------------------------------------------------------------------

    @field
    def url(self) -> str | None:
        # NOTE: must use `self.response.css`, not `self.css` - the latter
        # resolves `self.url`, which is this very field (infinite recursion).
        return self.response.css("link[rel=canonical]::attr(href)").get() or str(
            self.response.url
        )

    @field
    def page_title(self) -> str | None:
        return _norm(self.css("title::text").get())

    @field
    def name(self) -> str | None:
        h1 = self.css('h1[itemprop="name"]')
        if not h1:
            return None
        return _norm(_text(h1[0].root))

    @field
    def product_id(self) -> str | None:
        return self.css("div.product-page::attr(data-id)").get()

    @field
    def article_no(self) -> str | None:
        value = self.css('form#AddCart input[name="item"]::attr(value)').get()
        if value:
            return value
        value = self.css('script[data-event="viewItem"]::attr(data-symbol)').get()
        if value:
            return value
        symb = self.css("p.symb").get()
        if symb:
            match = re.search(r"Article no\.\s*([^\s|<]+)", symb)
            if match:
                return match.group(1)
        return None

    @field
    def brand(self) -> str | None:
        value = _norm(self.css("p.symb a::text").get())
        return value or self._product_json.get("brand")

    @field
    def brand_url(self) -> str | None:
        return self._abs(self.css("p.symb a::attr(href)").get())

    @field
    def brand_logo(self) -> str | None:
        url = self.css("div.logo-producer img::attr(data-original)").get()
        if not url or "empty.gif" in url:
            url = self.css("div.logo-producer noscript img::attr(src)").get()
        return url

    # ------------------------------------------------------------------
    # Pricing
    # ------------------------------------------------------------------

    @field
    def price(self) -> float | None:
        return _money_float(self.css("strong#price::text").get())

    @field
    def price_text(self) -> str | None:
        amount = _norm(self.css("strong#price::text").get())
        symbol = self.css("strong#price::attr(data-currency)").get() or _norm(
            self.css("strong#price span::text").get()
        )
        if not amount:
            return None
        return f"{amount} {symbol}".strip() if symbol else amount

    @field
    def currency(self) -> str | None:
        code = self._product_json.get("currencyCode")
        if code:
            return code
        return self.css(
            "li.moneyLayer select option[selected]::attr(value)"
        ).get()

    @field
    def regular_price(self) -> float | None:
        text = self.css("div.savePriceWrapper span.oldPrice::text").get()
        return _money_float(text)

    @field
    def discount_percent(self) -> str | None:
        return _norm(self.css("div.savePriceWrapper span.savePrice-perc::text").get())

    @field
    def you_save(self) -> str | None:
        return _norm(self.css("div.savePriceWrapper span.savePrice::text").get())

    @field
    def price_from(self) -> str | None:
        prices = []
        for tr in self._variant_rows:
            raw = tr.attrib.get("data-price")
            value = _money_float(raw)
            if value is not None:
                prices.append((value, raw))
        if not prices:
            return self.price_text
        _, raw = min(prices, key=lambda pair: pair[0])
        symbol = self.css("strong#price::attr(data-currency)").get()
        return f"{raw} {symbol}".strip() if symbol else raw

    # ------------------------------------------------------------------
    # Availability
    # ------------------------------------------------------------------

    @field
    def availability(self) -> str | None:
        return self.css(".availability-description::attr(href)").get()

    @field
    def availability_text(self) -> str | None:
        return _norm(self.css(".availability-description::text").get())

    @field
    def stock_info(self) -> str | None:
        return _norm(" ".join(self.css("div.stock-info ::text").getall()))

    @field
    def stock_availability_time_days(self) -> int | None:
        for tr in self._variant_rows:
            raw = tr.attrib.get("data-stockavailabilitytime")
            if raw:
                try:
                    return int(float(raw))
                except ValueError:
                    continue
        return None

    @field
    def shipping_cost(self) -> str | None:
        for tr in self._variant_rows:
            raw = tr.attrib.get("data-shippingcost")
            if raw:
                return raw
        return None

    # ------------------------------------------------------------------
    # Images
    # ------------------------------------------------------------------

    @cached_property
    def _gallery_images(self) -> list[str]:
        urls = self.css(
            '#product_slider img[itemprop="image"]::attr(data-original)'
        ).getall()
        seen: dict[str, None] = {}
        for url in urls:
            if url and "empty.gif" not in url:
                seen.setdefault(url, None)
        return list(seen.keys())

    @field
    def main_image(self) -> str | None:
        images = self._gallery_images
        return images[0] if images else None

    @field
    def images(self) -> list[str] | None:
        return self._gallery_images or None

    @field
    def image_popup(self) -> str | None:
        url = self.css('meta[property="og:image"]::attr(content)').get()
        if url:
            return url
        return self.css("#product_slider li a::attr(href)").get()

    # ------------------------------------------------------------------
    # Text content
    # ------------------------------------------------------------------

    @field
    def short_description(self) -> str | None:
        return _norm(self.css('meta[name="description"]::attr(content)').get())

    @field
    def description(self) -> str | None:
        paragraphs = self._description_sections["intro"]
        return "\n\n".join(paragraphs) if paragraphs else None

    @field
    def product_highlights(self) -> list[dict] | None:
        return self._description_sections["highlights"] or None

    @field
    def features(self) -> list[str] | None:
        return [
            f"{item['title']} - {item['text']}"
            for item in self._description_sections["features"]
        ] or None

    @field
    def specification_table(self) -> list[dict] | None:
        rows = self.css("table.flip-content tr")
        if not rows:
            return None
        headers = [_norm(h) for h in rows[0].css("th::text").getall()]
        if not any(headers):
            return None
        table = []
        for row in rows[1:]:
            values = [_norm(v) for v in row.css("td::text").getall()]
            if not values:
                continue
            table.append(dict(zip(headers, values)))
        return table or None

    # ------------------------------------------------------------------
    # Variants
    # ------------------------------------------------------------------

    @cached_property
    def _variants_list(self) -> list[dict]:
        currency = self.currency
        out = []
        for tr in self._variant_rows:
            attrib = tr.attrib
            sku = attrib.get("data-symbol")
            if not sku:
                continue
            size = _norm("".join(tr.css("td:nth-child(4)::text").getall()))
            name = _norm(tr.css("td.name::text").get())
            wrapper = self._save_price_wrappers.get(sku)

            regular_price = None
            discount_percent = None
            you_save = None
            if wrapper is not None:
                regular_price = _norm(wrapper.css("span.oldPrice::text").get())
                discount_percent = _norm(
                    wrapper.css("span.savePrice-perc::text").get()
                )
                you_save_raw = _norm(wrapper.css("span.savePrice::text").get())
                if you_save_raw:
                    you_save = re.sub(
                        r"^you save\s*", "", you_save_raw, flags=re.IGNORECASE
                    ).strip()

            variant = {
                "variant_id": attrib.get("id"),
                "sku": sku,
                "name": name,
                "size": size,
                "price": attrib.get("data-price"),
                "currency": currency,
                "regular_price": regular_price,
                "discount_percent": discount_percent,
                "you_save": you_save,
                "available": attrib.get("data-available") == "true",
                "buyable": attrib.get("data-buyable") == "true",
                "quantity_external": attrib.get("data-quantityexternal"),
                "quantity_internal": attrib.get("data-quantityinternal"),
                "shipping_cost": attrib.get("data-shippingcost"),
                "image": attrib.get("data-_big") or attrib.get("data-article_big"),
            }

            stock_time = attrib.get("data-stockavailabilitytime")
            if stock_time:
                variant["stock_availability_time_days"] = stock_time

            out.append(variant)
        return out

    @field
    def size_options(self) -> list[str] | None:
        seen: dict[str, None] = {}
        for variant in self._variants_list:
            size = variant.get("size")
            if size:
                seen.setdefault(size, None)
        return list(seen.keys()) or None

    @field
    def variants(self) -> list[dict] | None:
        return self._variants_list or None

    @field
    def variant_count(self) -> int | None:
        return len(self._variants_list)

    # ------------------------------------------------------------------
    # Navigation / taxonomy
    # ------------------------------------------------------------------

    @field
    def breadcrumbs(self) -> list[dict] | None:
        out = []
        for li in self.css("ul.breadcrumb li"):
            title = _norm(li.css('span[itemprop="title"]::text').get())
            if title is None:
                continue
            href = li.css("a::attr(href)").get()
            out.append({"title": title, "url": self._abs(href) if href else None})
        return out or None

    @field
    def category(self) -> str | None:
        breadcrumbs = self.breadcrumbs
        if not breadcrumbs:
            return self._product_json.get("categoryMain")
        for crumb in reversed(breadcrumbs):
            if crumb.get("url"):
                return crumb["title"]
        return None

    @field
    def site_name(self) -> str | None:
        return self.css('meta[property="og:site_name"]::attr(content)').get()

    def _nav_item(self, side: str) -> dict | None:
        anchor = self.css(f"div.products-nav a.popoverItem.pull-{side}")
        if not anchor:
            return None
        href = anchor.attrib.get("href")
        name = _norm(
            anchor.xpath("following-sibling::div[1]//strong[last()]/text()").get()
        )
        if not name and not href:
            return None
        return {"name": name, "url": self._abs(href)}

    @field
    def previous_item(self) -> dict | None:
        return self._nav_item("left")

    @field
    def next_item(self) -> dict | None:
        return self._nav_item("right")
