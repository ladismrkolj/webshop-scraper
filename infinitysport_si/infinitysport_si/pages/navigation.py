from web_poet import Returns, WebPage, field, handle_urls

from infinitysport_si.items import NavigationItem, NavigationLink

# Matches the "Naprej" (next) pagination link, or a disabled/absent one.
_NEXT_LINK_SELECTOR = "#pagination_next_bottom a::attr(href)"
_NEXT_DISABLED_SELECTOR = "#pagination_next_bottom.disabled, li.pagination_next.disabled"


@handle_urls("infinitysport.si")
class NavigationPage(WebPage, Returns[NavigationItem]):
    """Navigation page object for infinitysport.si (PrestaShop).

    Covers two page shapes seen on this domain:
    - category/listing pages: product links live in `li.ajax_block_product`
      containers, pagination in `#pagination_bottom`, and the current
      category's siblings/children are exposed via the active mega-menu
      entry `li.sfHoverForce`.
    - product detail pages: same-category product links are exposed in the
      "other products in this category" carousel (`#product_category`).
    """

    def _product_link_containers(self):
        containers = self.css("li.ajax_block_product")
        if containers:
            return containers
        return self.css("#product_category .item")

    @field
    def items(self) -> list[NavigationLink] | None:
        result = []
        for container in self._product_link_containers():
            link = container.css("h5.product-name a")
            url = link.attrib.get("href")
            if not url:
                continue
            text = link.attrib.get("title") or "".join(
                t.strip() for t in link.css("::text").getall()
            ).strip()
            result.append(NavigationLink(url=self.urljoin(url), text=text or None))
        return result or None

    @field
    def next_page(self) -> str | None:
        if self.css(_NEXT_DISABLED_SELECTOR):
            return None
        href = self.css(_NEXT_LINK_SELECTOR).get()
        if not href:
            return None
        return self.urljoin(href)

    @field
    def subcategories(self) -> list[NavigationLink] | None:
        result = []
        for link in self.css("li.sfHoverForce > ul > li > a"):
            href = link.attrib.get("href")
            if not href:
                continue
            text = "".join(link.css("::text").getall()).strip()
            if not text:
                text = (link.attrib.get("title") or "").strip()
            result.append(NavigationLink(url=self.urljoin(href), text=text or None))
        return result or None
