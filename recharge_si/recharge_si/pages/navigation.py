"""Page object for navigation (listing / start) pages on www.recharge.si."""

from __future__ import annotations

import re
from typing import Any
from urllib.parse import urljoin

from parsel import Selector
from web_poet import Returns, WebPage, field, handle_urls

from recharge_si.items import NavigationItem

# Pagination on this site is JS-driven; the next page URL only appears in an
# inline script: paginator = new Paginator("", "/windsurf/jadra?page=2", ...)
_PAGINATOR_RE = re.compile(r"""new\s+Paginator\(\s*["'][^"']*["']\s*,\s*["']([^"']*)["']""")


def _clean(text: str | None) -> str | None:
    """Collapse whitespace; return None for empty results."""
    if not text:
        return None
    cleaned = " ".join(text.split())
    return cleaned or None


@handle_urls("www.recharge.si")
class NavigationPage(WebPage, Returns[NavigationItem]):
    """Extracts item links, subcategory links and the next page URL."""

    def _link(self, anchor: Selector) -> dict[str, Any] | None:
        """Build a {url, text} dict from an anchor, using anchor text only."""
        href = anchor.attrib.get("href")
        if not href:
            return None
        text = _clean(" ".join(anchor.css("::text").getall()))
        return {"url": urljoin(self.url, href), "text": text}

    def _links(self, css: str, *, dedupe: bool = False) -> list[dict[str, Any]]:
        result: list[dict[str, Any]] = []
        seen: set[str] = set()
        for anchor in self.css(css):
            link = self._link(anchor)
            if not link:
                continue
            if dedupe:
                if link["url"] in seen:
                    continue
                seen.add(link["url"])
            result.append(link)
        return result

    @field
    def items(self) -> list[dict[str, Any]] | None:
        """Links to product detail pages (listing grids and start page carousels)."""
        result: list[dict[str, Any]] = []
        for article in self.css("ul.item-list li article"):
            anchor = article.css("h4 a")
            if not anchor:
                continue
            link = self._link(anchor[0])
            if link:
                result.append(link)
        return result or None

    @field
    def next_page(self) -> str | None:
        """Next page URL, taken from the inline Paginator(...) script."""
        for script in self.css("script::text").getall():
            match = _PAGINATOR_RE.search(script)
            if match:
                href = match.group(1).strip()
                if href:
                    return urljoin(self.url, href)
        # Fall back to a standard rel=next link if the site ever renders one.
        href = self.css("a[rel=next]::attr(href)").get()
        return urljoin(self.url, href) if href else None

    @field
    def subcategories(self) -> list[dict[str, Any]] | None:
        """Child category links.

        Prefer the page-scoped sidebar tree (``ul.categories``), where children
        of the current category are the ``h6`` entries. Item counts live in a
        sibling ``<small>`` outside the anchor, so only anchor text is used.
        The anchor for the current category carries ``class="active"`` and is
        excluded. When the current category is a leaf (or there is no sidebar
        at all), fall back to the site-wide mega-menu.
        """
        sidebar = self._links("ul.categories h6 a:not(.active)")
        if sidebar:
            return sidebar
        return (
            self._links(
                "ul.nav.navbar-nav > li > a, li.dropdown .dropdown-menu a", dedupe=True
            )
            or None
        )
