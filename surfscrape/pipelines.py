"""The one pipeline we need: drop rows that are not usable products."""

import logging

from scrapy.exceptions import DropItem

log = logging.getLogger(__name__)


class DropIncompletePipeline:
    """A row needs at least a title and some price to be worth exporting."""

    def __init__(self):
        self.dropped = 0

    def process_item(self, item, spider):
        if not item.get("title"):
            self.dropped += 1
            raise DropItem(f"no title: {item.get('url')}")
        if item.get("effective_price") in (None, ""):
            self.dropped += 1
            raise DropItem(f"no price: {item.get('url')}")
        return item

    def close_spider(self, spider):
        if self.dropped:
            log.info("dropped %d incomplete rows", self.dropped)
