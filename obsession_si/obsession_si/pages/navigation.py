from web_poet import Returns, WebPage, field, handle_urls

from obsession_si.items import NavigationItem, NavLink


@handle_urls("obsession.si")
class NavigationPage(WebPage, Returns[NavigationItem]):
    """Category/brand listing pages, paginated grids, and product-detail pages
    (whose only navigational content is a "related products" carousel)."""

    @field
    def items(self) -> list[NavLink] | None:
        result = []
        for el in self.css("article.product-item"):
            href = el.css(".picture a::attr(href)").get()
            if not href:
                href = el.css("h2.product-title a::attr(href)").get()
            if not href:
                continue
            # Prefer the anchor's own visible text; the picture anchor
            # usually wraps only an <img> (no text node), so fall back to
            # its title attribute, which carries the full product name.
            text = (el.css(".picture a::text").get() or "").strip()
            if not text:
                text = (el.css(".picture a::attr(title)").get() or "").strip()
            result.append(NavLink(url=self.urljoin(href), text=text))
        return result or None

    @field
    def next_page(self) -> str | None:
        return self.css("li.next-page a::attr(href)").get()

    @field
    def subcategories(self) -> list[NavLink] | None:
        result = []
        for el in self.css("a.lastLevelCategory"):
            href = el.attrib.get("href")
            if not href:
                continue
            text = (el.attrib.get("title") or el.css("::text").get() or "").strip()
            result.append(NavLink(url=self.urljoin(href), text=text))
        return result or None
