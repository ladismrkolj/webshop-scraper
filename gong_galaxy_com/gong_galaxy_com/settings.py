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

# Unlike the other shops in this repo, gong-galaxy.com sits behind an anti-bot
# layer: a plain Scrapy request gets a "Verifying your connection..." interstitial
# and then 429, whatever User-Agent it sends. Crawling this shop therefore NEEDS
# Zyte API — export ZYTE_API_KEY and the addon takes over every request. The page
# objects parse ordinary raw HTML, so they are unaffected either way, and the
# fixture tests run offline without a key.
if os.environ.get("ZYTE_API_KEY"):
    ADDONS["scrapy_zyte_api.Addon"] = 500
    ZYTE_API_TRANSPARENT_MODE = True

# Kept for the no-Zyte case; on its own it does not get past the anti-bot layer.
USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/131.0.0.0 Safari/537.36"
)

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
