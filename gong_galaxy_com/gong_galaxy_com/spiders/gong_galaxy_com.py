import scrapy
from scrapy_poet import DummyResponse

from gong_galaxy_com.pages.navigation import NavigationPage
from gong_galaxy_com.pages.product import ProductPage


class GongGalaxyComSpider(scrapy.Spider):
    name = "gong_galaxy_com"
    allowed_domains = ["www.gong-galaxy.com"]
    start_urls = ["https://www.gong-galaxy.com/en"]

    def __init__(self, url=None, product_url=None, *args, **kwargs):
        """Crawl the whole shop, or a single category (``-a url=``) or product
        (``-a product_url=``)."""
        super().__init__(*args, **kwargs)
        self.product_url = product_url
        if url:
            self.start_urls = [url]

    async def start(self):
        if self.product_url:
            yield scrapy.Request(self.product_url, callback=self.parse_item)
            return
        async for request in super().start():
            yield request

    async def parse(self, response: DummyResponse, nav: NavigationPage):
        """Parse start/category pages — follow products, pagination, subcategories."""
        nav_item = await nav.to_item()

        for link in nav_item.items or []:
            yield scrapy.Request(link["url"], callback=self.parse_item)

        if nav_item.next_page:
            yield scrapy.Request(nav_item.next_page, callback=self.parse)

        for link in nav_item.subcategories or []:
            yield scrapy.Request(link["url"], callback=self.parse)

    async def parse_item(self, response: DummyResponse, page: ProductPage):
        yield await page.to_item()
