from web_poet import Returns, WebPage, field, handle_urls

from easy_surfshop_com.items import LinkItem, NavigationItem


@handle_urls("easy-surfshop.com")
class NavigationPage(WebPage, Returns[NavigationItem]):
    """Page object for navigation-type pages: homepage, category/search
    listing pages. Extracts product item links, pagination, and the
    site category tree (only exposed as ``subcategories`` on non-listing
    pages, e.g. the homepage)."""

    @field
    def items(self) -> list[LinkItem] | None:
        result = []
        for el in self.css("div.product-item"):
            href = el.css("a.item-details::attr(href)").get()
            if not href:
                continue
            result.append(LinkItem(url=self.urljoin(href), text=""))
        return result or None

    @field
    def next_page(self) -> str | None:
        url = self.css("link[rel=next]::attr(href)").get()
        if not url:
            url = self.css("li.link-next a::attr(href)").get()
        if not url:
            return None
        return self.urljoin(url)

    @field
    def subcategories(self) -> list[LinkItem] | None:
        # Product listing pages (category/search result pages) render a
        # dedicated product grid with this id; the global category menu
        # present on every page is not a meaningful "subcategories" set
        # for them, so we only surface it on non-listing pages (e.g. the
        # homepage).
        if self.css("#productListSerp"):
            return None

        result = []
        seen = set()
        for el in self.css("#easy-full-menu-desk a[href]"):
            href = el.attrib.get("href")
            text = el.css("::text").get(default="").strip()
            if not href:
                continue
            url = self.urljoin(href)
            key = (url, text)
            if key in seen:
                continue
            seen.add(key)
            result.append(LinkItem(url=url, text=text))
        return result or None
