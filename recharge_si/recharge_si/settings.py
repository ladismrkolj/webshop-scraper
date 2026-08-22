import os

from scrapy_zyte_api.utils import USER_AGENT as ZAPI_USER_AGENT

BOT_NAME = "recharge_si"

SPIDER_MODULES = ["recharge_si.spiders"]
NEWSPIDER_MODULE = "recharge_si.spiders"

ADDONS = {
    "scrapy_poet.Addon": 300,
}

# recharge.si serves complete HTML over plain HTTP, so Zyte API is optional.
# Set ZYTE_API_KEY in the environment to route requests through it instead.
if os.environ.get("ZYTE_API_KEY"):
    ADDONS["scrapy_zyte_api.Addon"] = 500

SCRAPY_POET_DISCOVER = [
    "recharge_si.pages",
]

_ZYTE_API_USER_AGENT = f"scraping-agent-skills {ZAPI_USER_AGENT}"

# Zyte API is configured but transparent mode is off by default.
# Enable per-spider via custom_settings when needed:
#   custom_settings = {"ZYTE_API_TRANSPARENT_MODE": True}
ZYTE_API_TRANSPARENT_MODE = False

# recharge.si closes the connection on Scrapy's default User-Agent.
USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/131.0.0.0 Safari/537.36"
)

ROBOTSTXT_OBEY = True
CONCURRENT_REQUESTS_PER_DOMAIN = 1
DOWNLOAD_DELAY = 1
FEED_EXPORT_ENCODING = "utf-8"
