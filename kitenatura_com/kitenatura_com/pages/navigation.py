from web_poet import Returns, WebPage, field, handle_urls

from kitenatura_com.items import NavigationItem, NavLink


@handle_urls("kitenatura.com")
class NavigationPage(WebPage, Returns[NavigationItem]):
    """Start/collection (listing) page for the kitenatura.com Shopify store.

    Extracts links to product detail pages, the next pagination page, and
    subcategory links, from the on-page navigation and product grid markup.
    """

    @field
    def items(self) -> list[NavLink] | None:
        links = []
        seen = set()
        for a in self.css("h2.productitem--title a[data-product-page-link]"):
            href = a.attrib.get("href")
            if not href:
                continue
            url = self.urljoin(href.split("?")[0])
            if url in seen:
                continue
            seen.add(url)
            text = " ".join(a.css("::text").getall()).split()
            links.append(NavLink(url=url, text=" ".join(text) or None))
        return links or None

    @field
    def next_page(self) -> str | None:
        href = self.css('head link[rel="next"]::attr(href)').get()
        if not href:
            href = self.css(".pagination--next a::attr(href)").get()
        return self.urljoin(href) if href else None

    @field
    def subcategories(self) -> list[NavLink] | None:
        links = []
        seen = set()
        for a in self.css("a.collection-filters__filter-link"):
            href = a.attrib.get("href")
            if not href:
                continue
            url = self.urljoin(href)
            if url in seen:
                continue
            seen.add(url)
            text = " ".join(a.css("::text").getall()).split()
            links.append(NavLink(url=url, text=" ".join(text) or None))
        return links or None
