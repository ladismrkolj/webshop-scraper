"""Per-shop configuration: one YAML file per shop, all fields optional but two.

Everything here is about *this shop's HTML*. Crawl politeness, concurrency,
retries and caching are Scrapy settings, not ours - see surfscrape/settings.py.
"""

from __future__ import annotations

from pathlib import Path

import yaml
from pydantic import BaseModel, Field

PACKAGE_SITES_DIR = Path(__file__).resolve().parent.parent / "sites"


def sites_dirs() -> list[Path]:
    """Where to look for shop configs: the working directory first, so a
    checkout, a container mount and an ad-hoc directory all just work."""
    cwd = Path.cwd() / "sites"
    return [cwd] if cwd.resolve() == PACKAGE_SITES_DIR.resolve() else [cwd, PACKAGE_SITES_DIR]


class Selectors(BaseModel):
    """CSS selectors for a product page. Only fill in what the automatic
    extractors miss - anything left out keeps its schema.org value.

    Syntax: plain CSS, '::attr(name)' for an attribute, a list to try in order.
    """

    title: str | list[str] | None = None
    brand: str | list[str] | None = None
    sku: str | list[str] | None = None
    price: str | list[str] | None = None
    sale_price: str | list[str] | None = None
    description: str | list[str] | None = None
    images: str | list[str] | None = None
    availability: str | list[str] | None = None
    stock_level: str | list[str] | None = None
    breadcrumbs: str | list[str] | None = None
    variants: str | list[str] | None = None
    variant_title: str | list[str] | None = None
    variant_price: str | list[str] | None = None
    variant_sku: str | list[str] | None = None

    def any_set(self) -> bool:
        return bool(self.model_dump(exclude_none=True))


class SiteConfig(BaseModel):
    name: str
    base_url: str
    enabled: bool = True
    platform: str = "auto"          # auto | shopify | woocommerce | html
    currency: str | None = None
    drop_breadcrumbs: list[str] = Field(default_factory=lambda: ["Home", "Domov", "Shop"])

    # Which URLs are product pages / category pages. Regex, matched against
    # the full URL. Empty product_url_include means "anything on this host".
    product_url_include: list[str] = Field(default_factory=list)
    product_url_exclude: list[str] = Field(
        default_factory=lambda: ["/cart", "/checkout", "/kosarica", "/blagajna",
                                 r"\?add-to-cart=", "/wp-admin", "/my-account"]
    )
    sitemaps: list[str] = Field(default_factory=list)   # empty -> robots.txt
    category_urls: list[str] = Field(default_factory=list)
    next_page_selector: str | None = None   # only needed if pagination is JS-ish

    # Politeness override for this shop only (Scrapy settings names).
    settings: dict = Field(default_factory=dict)

    selectors: Selectors = Field(default_factory=Selectors)

    @classmethod
    def load(cls, target: str | Path) -> SiteConfig:
        """Accepts a path, or a bare shop name resolved against sites/."""
        path = Path(target)
        if not path.exists():
            candidates = [d / f"{target}.{ext}" for d in sites_dirs() for ext in ("yaml", "yml")]
            for cand in candidates:
                if cand.exists():
                    path = cand
                    break
            else:
                raise FileNotFoundError(
                    f"no site config for {target!r}; looked in "
                    + ", ".join(str(d) for d in sites_dirs())
                )
        return cls.model_validate(yaml.safe_load(path.read_text(encoding="utf-8")))

    @classmethod
    def load_all(cls, directory: str | Path | None = None) -> list[SiteConfig]:
        dirs = [Path(directory)] if directory else sites_dirs()
        for d in dirs:
            paths = sorted(d.glob("*.y*ml"))
            if paths:
                return [cls.load(p) for p in paths]
        return []

    def dump_yaml(self) -> str:
        data = self.model_dump(exclude_defaults=True, exclude_none=True)
        return yaml.safe_dump(data, sort_keys=False, allow_unicode=True)

    @staticmethod
    def for_url(url: str, name: str = "adhoc") -> SiteConfig:
        """A throwaway config, for `surfscrape url ...` against an unknown shop."""
        from urllib.parse import urlparse

        p = urlparse(url)
        return SiteConfig(name=name, base_url=f"{p.scheme}://{p.netloc}")
