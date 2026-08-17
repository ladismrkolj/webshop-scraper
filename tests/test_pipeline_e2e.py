"""End-to-end run against a throwaway local shop: robots -> sitemap -> pages
-> extraction -> CSV. No external network involved."""

from __future__ import annotations

import asyncio
import threading
from functools import partial
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import pytest

from surfscrape.config import SiteConfig
from surfscrape.export import to_csv
from surfscrape.pipeline import ShopScraper

ROBOTS = "User-agent: *\nDisallow: /cart\nSitemap: {base}/sitemap.xml\n"

SITEMAP = """<?xml version="1.0"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>{base}/product/sail-45</loc><lastmod>2026-08-01</lastmod></url>
  <url><loc>{base}/product/board-111</loc></url>
  <url><loc>{base}/cart</loc></url>
  <url><loc>{base}/about</loc></url>
</urlset>"""

PAGE = """<html><head>
<script type="application/ld+json">
{{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[
 {{"@type":"ListItem","position":1,"name":"Home"}},
 {{"@type":"ListItem","position":2,"name":"{cat}"}}]}}
</script>
<script type="application/ld+json">
{{"@context":"https://schema.org","@type":"Product","name":"{name}","sku":"{sku}",
  "brand":{{"@type":"Brand","name":"{brand}"}},"description":"desc","image":["/img/{sku}.jpg"],
  "offers":{{"@type":"Offer","price":"{price}","priceCurrency":"EUR",
             "availability":"https://schema.org/InStock"}}}}
</script></head><body><span class="old">{old} €</span></body></html>"""

PRODUCTS = {
    "/product/sail-45": dict(name="Sail 4.5", sku="S45", brand="Duotone",
                             price="799.00", old="899.00", cat="Sails"),
    "/product/board-111": dict(name="Board 111", sku="B111", brand="Fanatic",
                               price="1299.00", old="1299.00", cat="Boards"),
}


class Handler(BaseHTTPRequestHandler):
    def __init__(self, *a, base: str, **kw):
        self.base = base
        super().__init__(*a, **kw)

    def log_message(self, *a):  # keep test output clean
        pass

    def do_GET(self):
        path = self.path.split("?")[0]
        if path == "/robots.txt":
            body, ctype = ROBOTS.format(base=self.base), "text/plain"
        elif path == "/sitemap.xml":
            body, ctype = SITEMAP.format(base=self.base), "application/xml"
        elif path in PRODUCTS:
            body, ctype = PAGE.format(**PRODUCTS[path]), "text/html"
        elif path in ("/products.json", "/wp-json/wc/store/v1/products"):
            self.send_error(404)
            return
        else:
            body, ctype = "<html><body>nothing</body></html>", "text/html"
        raw = body.encode()
        etag = f'"{hash(body) & 0xffff}"'
        if self.headers.get("If-None-Match") == etag:
            self.send_response(304)
            self.send_header("ETag", etag)
            self.end_headers()
            return
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("ETag", etag)
        self.end_headers()
        self.wfile.write(raw)


@pytest.fixture()
def shop():
    server = ThreadingHTTPServer(("127.0.0.1", 0), partial(Handler, base="PLACEHOLDER"))
    base = f"http://127.0.0.1:{server.server_address[1]}"
    server.RequestHandlerClass = partial(Handler, base=base)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    yield base
    server.shutdown()


def _config(base: str, **over) -> SiteConfig:
    data = {
        "name": "testshop",
        "base_url": base,
        "strategy": "generic",
        "currency": "EUR",
        "discovery": {"product_url_include": ["/product/"], "product_url_exclude": ["/cart"]},
        "selectors": {"sale_price": "span.old"},
        "fetch": {"delay": 0, "concurrency": 4},
    }
    data.update(over)
    return SiteConfig.model_validate(data)


def test_full_run(shop, tmp_path):
    products, stats = asyncio.run(ShopScraper(_config(shop), str(tmp_path / "c.sqlite")).run())
    assert stats.discovered == 2       # /cart and /about filtered out
    assert stats.scraped == 2 and stats.failed == 0

    by_sku = {p.sku: p for p in products}
    sail = by_sku["S45"]
    assert sail.brand == "Duotone"
    assert sail.categories == ["Sails"]          # "Home" dropped
    assert str(sail.price) == "899.00"           # old-price element wins as list price
    assert str(sail.sale_price) == "799.00"
    assert sail.discount_pct == 11.12
    assert sail.availability == "in_stock"
    assert sail.images == [f"{shop}/img/S45.jpg"]

    # Equal old/new price is not a discount.
    assert by_sku["B111"].sale_price is None

    csv_text = to_csv(products, tmp_path / "out.csv").read_text(encoding="utf-8")
    assert "Fanatic" in csv_text and len(csv_text.strip().splitlines()) == 3


def test_second_run_uses_conditional_requests(shop, tmp_path):
    cache = str(tmp_path / "c.sqlite")
    cfg = _config(shop)
    asyncio.run(ShopScraper(cfg, cache).run())
    products, stats = asyncio.run(ShopScraper(cfg, cache).run())
    # Everything was served from cache via 304, and we still got full products.
    assert stats.unchanged >= 2
    assert stats.scraped == 2


def test_robots_disallow_is_respected(shop, tmp_path):
    cfg = _config(shop, discovery={"product_url_include": ["/product/", "/cart"]})
    products, _ = asyncio.run(ShopScraper(cfg, None).run())
    assert all("/cart" not in p.url for p in products)
