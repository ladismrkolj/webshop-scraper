"""In-process item sink, for the CLI verbs that print instead of exporting.

A pipeline rather than a signal handler on purpose: Scrapy rebuilds a
crawler's SignalManager when it applies settings, which silently discards
handlers connected before crawl() - a pipeline is registered through the
settings themselves, so it always fires.
"""

SINK: list[dict] = []


class CollectPipeline:
    """Runs after DropIncompletePipeline, so only exportable rows land here."""

    def process_item(self, item, spider):
        SINK.append(dict(item))
        return item


def drain() -> list[dict]:
    items = list(SINK)
    SINK.clear()
    return items
