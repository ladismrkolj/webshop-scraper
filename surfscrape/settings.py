"""Scrapy settings - the crawling behaviour lives here, not in our code.

Everything in this file is stock Scrapy. It replaces the hand-written fetcher,
cache, retry, throttle and robots handling a bespoke engine would need.
"""

import os

BOT_NAME = "surfscrape"
SPIDER_MODULES = ["surfscrape.spiders"]
NEWSPIDER_MODULE = "surfscrape.spiders"

USER_AGENT = "surfscrape/0.2 (+windsurf catalogue aggregator)"
ROBOTSTXT_OBEY = True

# Polite by default. AutoThrottle adapts to the shop's actual latency, so a
# slow shop is hit less hard without us guessing a delay.
CONCURRENT_REQUESTS = 8
CONCURRENT_REQUESTS_PER_DOMAIN = 4
DOWNLOAD_DELAY = 0.5
AUTOTHROTTLE_ENABLED = True
AUTOTHROTTLE_START_DELAY = 0.5
AUTOTHROTTLE_MAX_DELAY = 20.0
AUTOTHROTTLE_TARGET_CONCURRENCY = 2.0

RETRY_ENABLED = True
RETRY_TIMES = 3
DOWNLOAD_TIMEOUT = 30

# This is what makes a daily re-run cheap: RFC2616 caching sends
# If-None-Match / If-Modified-Since and a 304 costs headers only.
HTTPCACHE_ENABLED = True
# Scrapy does not read settings from the environment, so a container that wants
# its cache on a mounted volume has to be told here. Relative paths land in
# .scrapy/, absolute ones are used as-is.
HTTPCACHE_DIR = os.environ.get("SURFSCRAPE_CACHE_DIR", "httpcache")
HTTPCACHE_POLICY = "scrapy.extensions.httpcache.RFC2616Policy"
HTTPCACHE_STORAGE = "scrapy.extensions.httpcache.FilesystemCacheStorage"
HTTPCACHE_ALWAYS_STORE = True

ITEM_PIPELINES = {"surfscrape.pipelines.DropIncompletePipeline": 300}

LOG_LEVEL = "INFO"
REQUEST_FINGERPRINTER_IMPLEMENTATION = "2.7"
TWISTED_REACTOR = "twisted.internet.asyncioreactor.AsyncioSelectorReactor"
FEED_EXPORT_ENCODING = "utf-8"
