import scrapy
from scrapy_poet import DummyResponse

from kitenatura_com.pages.navigation import NavigationPage
from kitenatura_com.pages.product import ProductPage


class KitenaturaComSpider(scrapy.Spider):
    name = "kitenatura_com"
    start_urls = ["https://kitenatura.com"]

    # This site serves everything over plain HTTP with no bot-protection that
    # needs Zyte API, so skip it and use Scrapy's default downloader — this
    # also means the spider works without a ZYTE_API_KEY configured.
    custom_settings = {
        "DOWNLOAD_HANDLERS": {
            "http": "scrapy.core.downloader.handlers.http11.HTTP11DownloadHandler",
            "https": "scrapy.core.downloader.handlers.http11.HTTP11DownloadHandler",
        },
    }

    async def parse(self, response: DummyResponse, nav: NavigationPage):
        """Parse the homepage / collection pages — discover product and category links."""
        nav_item = await nav.to_item()

        for link in nav_item.items or []:
            if link.url:
                yield scrapy.Request(link.url, callback=self.parse_item)

        if nav_item.next_page:
            yield scrapy.Request(nav_item.next_page, callback=self.parse)

        for sub in nav_item.subcategories or []:
            if sub.url:
                yield scrapy.Request(sub.url, callback=self.parse)

    async def parse_item(self, response: DummyResponse, page: ProductPage):
        """Extract product data from a detail page."""
        yield await page.to_item()
