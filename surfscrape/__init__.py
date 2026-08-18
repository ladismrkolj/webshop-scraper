"""surfscrape - a thin, config-driven product-extraction layer on top of Scrapy."""

from .config import SiteConfig, Selectors
from .models import Product, Variant

__version__ = "0.2.0"
__all__ = ["SiteConfig", "Selectors", "Product", "Variant"]
