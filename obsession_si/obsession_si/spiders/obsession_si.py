import scrapy
from scrapy_poet import DummyResponse

from obsession_si.pages.navigation import NavigationPage
from obsession_si.pages.product import ProductPage


class ObsessionSiSpider(scrapy.Spider):
    name = "obsession_si"
    start_urls = ["https://www.obsession.si/si/naish-est-1979-t-shirt"]

    def start_requests(self):
        for url in self.start_urls:
            # The start URL is itself a product detail page, so extract it directly...
            yield scrapy.Request(url, callback=self.parse_item)
            # ...and also use it as a navigation page to discover more products/categories.
            yield scrapy.Request(url, callback=self.parse, dont_filter=True)

    async def parse(self, response: DummyResponse, nav: NavigationPage):
        """Parse list/category pages — extract navigation links."""
        nav_item = await nav.to_item()

        # Follow item links → item extraction PO
        for link in nav_item.items or []:
            yield scrapy.Request(link.url, callback=self.parse_item)

        # Follow pagination
        if nav_item.next_page:
            yield scrapy.Request(nav_item.next_page, callback=self.parse)

        # Follow subcategories
        for link in nav_item.subcategories or []:
            yield scrapy.Request(link.url, callback=self.parse)

    async def parse_item(self, response: DummyResponse, page: ProductPage):
        """Extract item data."""
        yield await page.to_item()
