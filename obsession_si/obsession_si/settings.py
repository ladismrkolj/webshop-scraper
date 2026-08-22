from scrapy_zyte_api.utils import USER_AGENT as ZAPI_USER_AGENT

BOT_NAME = "obsession_si"

SPIDER_MODULES = ["obsession_si.spiders"]
NEWSPIDER_MODULE = "obsession_si.spiders"

import os

ADDONS = {
    "scrapy_poet.Addon": 300,
}

SCRAPY_POET_DISCOVER = [
    "obsession_si.pages",
]

_ZYTE_API_USER_AGENT = f"scraping-agent-skills {ZAPI_USER_AGENT}"

# Zyte API is only enabled when ZYTE_API_KEY is set (e.g. via env var or
# scrapy-zyte-api's usual discovery). Without a key, requests go through
# Scrapy's normal HTTP downloader. To use Zyte API, add the addon back and
# set ZYTE_API_KEY, then enable transparent mode per-spider via
# custom_settings = {"ZYTE_API_TRANSPARENT_MODE": True}.
if os.environ.get("ZYTE_API_KEY"):
    ADDONS["scrapy_zyte_api.Addon"] = 500
    ZYTE_API_TRANSPARENT_MODE = False

ROBOTSTXT_OBEY = True
CONCURRENT_REQUESTS_PER_DOMAIN = 1
DOWNLOAD_DELAY = 1
FEED_EXPORT_ENCODING = "utf-8"
USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
)
