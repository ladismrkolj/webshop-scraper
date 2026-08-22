import scrapy
from scrapy_poet import DummyResponse

from infinitysport_si.pages.navigation import NavigationPage
from infinitysport_si.pages.product import ProductPage


class InfinitysportSiSpider(scrapy.Spider):
    name = "infinitysport_si"
    start_urls = [
        "https://infinitysport.si/si/ostali-sporti/windsurf/windsurf-jadra/14155-north-sails-ws-jadro-x-over-wave-2025"
    ]

    async def parse(self, response: DummyResponse, nav: NavigationPage):
        """Parse list/category/detail pages — extract navigation links."""
        nav_item = await nav.to_item()

        # Follow item links -> item extraction PO
        for link in nav_item.items or []:
            if link.url:
                yield scrapy.Request(link.url, callback=self.parse_item)

        # Follow pagination
        if nav_item.next_page:
            yield scrapy.Request(nav_item.next_page, callback=self.parse)

        # Follow subcategories
        for link in nav_item.subcategories or []:
            if link.url:
                yield scrapy.Request(link.url, callback=self.parse)

    async def parse_item(self, response: DummyResponse, page: ProductPage):
        """Extract product data."""
        yield await page.to_item()
