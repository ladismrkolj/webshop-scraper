"""surfscrape - modular windsurf webshop catalogue scraper."""

from .config import SiteConfig
from .models import Product, Variant
from .pipeline import ShopScraper, run_site

__version__ = "0.1.0"
__all__ = ["SiteConfig", "Product", "Variant", "ShopScraper", "run_site"]
