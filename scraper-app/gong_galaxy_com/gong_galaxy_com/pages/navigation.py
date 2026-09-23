"""Page object for GONG Galaxy navigation: the homepage and collection pages."""

from __future__ import annotations

import re
from typing import Any

from web_poet import WebPage, field, handle_urls

from gong_galaxy_com.items import NavigationItem


# EU-formatted prices ("964,81€"), to tell a hotspot's title from its price.
_PRICE_RE = re.compile(r"^[\d\s.,]+\s*€$")


def _defrag(url: str) -> str:
    return url.split("#")[0]


@handle_urls("www.gong-galaxy.com")
class NavigationPage(WebPage[NavigationItem]):
    """Product links, pagination and subcategory links.

    Serves two page shapes. Collection pages carry a product grid plus a
    ``hero-nav`` strip of sibling collections; the homepage carries several
    product widgets and the full mega-menu. The fields below prefer the
    narrower, page-specific source and fall back to the site-wide one, so a
    collection page yields its own subcategories rather than the whole menu.
    """

    @field
    def items(self) -> list[dict[str, Any]] | None:
        """Links to product detail pages.

        A whole-page sweep is deliberate: the homepage puts products in card
        sliders *and* in shoppable-image hotspots, so iterating product cards
        alone misses some. Editorial "push" tiles point at /blogs/ and are
        excluded for free. Fragments are stripped — one homepage anchor carries
        ``#ProductInfoWrapper`` and would otherwise duplicate its product.
        """
        seen: dict[str, dict[str, Any]] = {}
        for anchor in self.css('a[href*="/products/"]'):
            href = anchor.attrib.get("href")
            if not href:
                continue
            url = _defrag(self.urljoin(href.strip()))
            if url in seen:
                continue
            seen[url] = {"url": url, "text": self._product_text(anchor)}
        return list(seen.values()) or None

    @staticmethod
    def _product_text(anchor) -> str | None:
        """Title for a product anchor.

        Never a generic ``::text`` on the anchor: each card's image wrapper
        contains an inline ``<style>`` block, so that would return CSS.
        """
        card = anchor.xpath("ancestor::w3-product-card[1]")
        for source in (card, anchor):
            if not source:
                continue
            text = source.css(".product-card__title::text").get()
            if text and text.strip():
                return text.strip()

        # Shoppable-image hotspots have no card: the title is a bare text node
        # in the tooltip, alongside the price and a brand logo.
        tooltip = anchor.xpath(
            "ancestor::*[contains(@class, 'hotspot-product__tooltip')][1]"
        )
        for candidate in tooltip.css("::text").getall():
            candidate = candidate.strip()
            if candidate and not _PRICE_RE.match(candidate):
                return candidate

        title = anchor.attrib.get("title") or anchor.css("img::attr(alt)").get()
        return title.strip() if title and title.strip() else None

    @field
    def next_page(self) -> str | None:
        href = self.css("link[rel=next]::attr(href)").get()
        if not href:
            href = self.css("a[rel=next]::attr(href)").get()
        return self.urljoin(href.strip()) if href and href.strip() else None

    @field
    def subcategories(self) -> list[dict[str, Any]] | None:
        """Links to subcategory listing pages.

        On a collection page the ``hero-nav`` strip holds exactly that
        collection's children; the site-wide drawer and mega-menu repeat the
        same ~750 links on every page, so they are only used as a fallback
        (the homepage, which has no hero-nav).
        """
        links = self._links(
            "nav.hero-nav ul.hero-nav__quicklinks a.hero-nav__item",
            "span.hero-nav__label::text",
        )
        if links:
            return links
        # A leaf collection simply has no children — don't fall back to the
        # mega-menu there, or every such page would re-emit ~750 site-wide
        # links. The fallback exists for entry points like the homepage.
        if self._is_collection_page:
            return None
        return self._links(
            "nav.navigation-main-linklist a.navigation__link",
            "span.navigation__label::text",
        ) or None

    @property
    def _is_collection_page(self) -> bool:
        canonical = self.css("link[rel=canonical]::attr(href)").get() or self.url
        return "/collections/" in canonical

    def _links(self, anchor_css: str, text_css: str) -> list[dict[str, Any]]:
        """Collection links from ``anchor_css``, deduplicated, order preserved.

        Anchor text comes from an explicit label span: depth-1 nav entries
        embed a visually-hidden icon name that a plain ``::text`` would prefix
        onto every label.
        """
        seen: dict[str, dict[str, Any]] = {}
        for anchor in self.css(anchor_css):
            href = anchor.attrib.get("href")
            if not href or "/collections/" not in href:
                continue
            url = _defrag(self.urljoin(href.strip()))
            if url in seen:
                continue
            text = anchor.css(text_css).get()
            seen[url] = {
                "url": url,
                "text": text.strip() if text and text.strip() else None,
            }
        return list(seen.values())
