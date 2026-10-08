import os

from scrapy_zyte_api.utils import USER_AGENT as ZAPI_USER_AGENT

BOT_NAME = "gong_galaxy_com"

SPIDER_MODULES = ["gong_galaxy_com.spiders"]
NEWSPIDER_MODULE = "gong_galaxy_com.spiders"

ADDONS = {
    "scrapy_poet.Addon": 300,
}

SCRAPY_POET_DISCOVER = [
    "gong_galaxy_com.pages",
]

_ZYTE_API_USER_AGENT = f"scraping-agent-skills {ZAPI_USER_AGENT}"

ZYTE_API_TRANSPARENT_MODE = False

# gong-galaxy.com is the one shop here that cannot be crawled with plain Scrapy.
# It discriminates on the client's TLS fingerprint, not on anything we send: at
# the same moment, curl announcing itself as "python-httpx" gets 200 while httpx
# announcing itself as "curl" gets 429. Scrapy's own handshake is refused on
# every URL, robots.txt and the public products.json feed included.
#
# Its robots.txt does allow the paths this spider visits, so the block is
# infrastructure rather than policy — but the only ways through are a service
# that fetches on your behalf, or impersonating another client's TLS signature.
# The first is supported here; the second is not something this project does.
#
# Export ZYTE_API_KEY to crawl. Without it the spider will collect 429s.
# The page objects parse ordinary raw HTML, so the fixture tests run offline
# with no key at all.
if os.environ.get("ZYTE_API_KEY"):
    ADDONS["scrapy_zyte_api.Addon"] = 500
    ZYTE_API_TRANSPARENT_MODE = True

ROBOTSTXT_OBEY = True
CONCURRENT_REQUESTS_PER_DOMAIN = 1
DOWNLOAD_DELAY = 1

# Back off rather than hammer the shop, and treat 429 as retryable.
AUTOTHROTTLE_ENABLED = True
AUTOTHROTTLE_START_DELAY = 1
AUTOTHROTTLE_MAX_DELAY = 30
AUTOTHROTTLE_TARGET_CONCURRENCY = 1.0
RETRY_TIMES = 5
RETRY_HTTP_CODES = [429, 500, 502, 503, 504, 522, 524, 408]

FEED_EXPORT_ENCODING = "utf-8"
