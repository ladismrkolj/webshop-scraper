BOT_NAME = "easy_surfshop_com"

SPIDER_MODULES = ["easy_surfshop_com.spiders"]
NEWSPIDER_MODULE = "easy_surfshop_com.spiders"

# This site (easy-surfshop.com) is not blocked and responds fine to plain
# HTTP requests, so scrapy-zyte-api is disabled and only scrapy-poet is
# enabled. To re-enable Zyte API (e.g. for scale or Scrapy Cloud deploys),
# set ZYTE_API_KEY and add back:
#   "scrapy_zyte_api.Addon": 500,
ADDONS = {
    "scrapy_poet.Addon": 300,
}

SCRAPY_POET_DISCOVER = [
    "easy_surfshop_com.pages",
]

ROBOTSTXT_OBEY = True
CONCURRENT_REQUESTS_PER_DOMAIN = 1
DOWNLOAD_DELAY = 1
FEED_EXPORT_ENCODING = "utf-8"

# Native Scrapy JSON export: one JSON array per run, overwritten each time.
# Override the path/format per-run with -o/-O or -s FEEDS=... (e.g. nightly.py
# already does this to get timestamped, per-shop filenames).
FEEDS = {
    "output/%(name)s_%(time)s.json": {
        "format": "json",
        "encoding": "utf8",
        "indent": 2,
        "overwrite": False,
    },
}
