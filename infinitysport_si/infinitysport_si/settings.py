BOT_NAME = "infinitysport_si"

SPIDER_MODULES = ["infinitysport_si.spiders"]
NEWSPIDER_MODULE = "infinitysport_si.spiders"

ADDONS = {
    "scrapy_poet.Addon": 300,
}

SCRAPY_POET_DISCOVER = [
    "infinitysport_si.pages",
]

ROBOTSTXT_OBEY = True
CONCURRENT_REQUESTS_PER_DOMAIN = 1
DOWNLOAD_DELAY = 1
FEED_EXPORT_ENCODING = "utf-8"
