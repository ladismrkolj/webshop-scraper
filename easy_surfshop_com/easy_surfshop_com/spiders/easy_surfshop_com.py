import scrapy
from scrapy_poet import DummyResponse

from easy_surfshop_com.pages.navigation import NavigationPage
from easy_surfshop_com.pages.product import ProductPage


class EasySurfshopComSpider(scrapy.Spider):
    name = "easy_surfshop_com"
    start_urls = ["https://easy-surfshop.com"]

    async def parse(self, response: DummyResponse, nav: NavigationPage):
        """Parse list/category pages — extract navigation links."""
        nav_item = await nav.to_item()

        for link in nav_item.items or []:
            yield scrapy.Request(link.url, callback=self.parse_item)

        if nav_item.next_page:
            yield scrapy.Request(nav_item.next_page, callback=self.parse)

        for link in nav_item.subcategories or []:
            yield scrapy.Request(link.url, callback=self.parse)

    async def parse_item(self, response: DummyResponse, page: ProductPage):
        """Extract item data."""
        yield await page.to_item()
