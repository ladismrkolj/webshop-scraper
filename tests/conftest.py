"""A throwaway webshop on localhost, so the tests exercise the real Scrapy stack."""

from __future__ import annotations

import threading
from functools import partial
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import pytest

ROBOTS = "User-agent: *\nDisallow: /cart\nSitemap: {base}/sitemap.xml\n"

SITEMAP = """<?xml version="1.0"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>{base}/izdelek/sail-45</loc><lastmod>2026-08-01</lastmod></url>
  <url><loc>{base}/izdelek/board-111</loc></url>
  <url><loc>{base}/cart</loc></url>
  <url><loc>{base}/o-nas</loc></url>
</urlset>"""

PAGE = """<html><head>
<script type="application/ld+json">
{{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[
 {{"@type":"ListItem","position":1,"name":"Domov"}},
 {{"@type":"ListItem","position":2,"name":"{cat}"}}]}}
</script>
<script type="application/ld+json">
{{"@context":"https://schema.org","@type":"Product","name":"{name}","sku":"{sku}",
  "brand":{{"@type":"Brand","name":"{brand}"}},"description":"a windsurf thing",
  "image":["/img/{sku}.jpg"],
  "offers":{{"@type":"Offer","price":"{price}","priceCurrency":"EUR",
             "availability":"https://schema.org/InStock"}}}}
</script></head><body>
<h1 class="product_title">{name}</h1>
<p class="price"><del><span class="amount">{old} €</span></del>
                 <ins><span class="amount">{price} €</span></ins></p>
<p class="stock">Na zalogi (4)</p>
</body></html>"""

CATEGORY = """<html><body>
<a href="/izdelek/sail-45">Sail</a>
<a href="/izdelek/board-111">Board</a>
<a href="/cart">Cart</a>
<a rel="next" href="/kategorija/jadra?page=2">next</a>
</body></html>"""

CATEGORY_P2 = """<html><body><a href="/izdelek/board-111">Board</a></body></html>"""

PRODUCTS = {
    "/izdelek/sail-45": dict(name="Sail 4.5", sku="S45", brand="Duotone",
                             price="799.00", old="899.00", cat="Jadra"),
    "/izdelek/board-111": dict(name="Board 111", sku="B111", brand="Fanatic",
                               price="1299.00", old="1299.00", cat="Deske"),
}


class Handler(BaseHTTPRequestHandler):
    def __init__(self, *a, base: str, **kw):
        self.base = base
        super().__init__(*a, **kw)

    def log_message(self, *a):
        pass

    def do_GET(self):
        path = self.path.split("?")[0]
        query = self.path[len(path):]
        if path == "/robots.txt":
            body, ctype = ROBOTS.format(base=self.base), "text/plain"
        elif path == "/sitemap.xml":
            body, ctype = SITEMAP.format(base=self.base), "application/xml"
        elif path in PRODUCTS:
            body, ctype = PAGE.format(**PRODUCTS[path]), "text/html"
        elif path == "/kategorija/jadra":
            body = CATEGORY_P2 if "page=2" in query else CATEGORY
            ctype = "text/html"
        elif path in ("/products.json", "/wp-json/wc/store/v1/products"):
            self.send_error(404)
            return
        else:
            body, ctype = "<html><body>nothing here</body></html>", "text/html"

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
        self.send_header("Cache-Control", "max-age=0")
        self.end_headers()
        self.wfile.write(raw)


@pytest.fixture(scope="session")
def shop():
    server = ThreadingHTTPServer(("127.0.0.1", 0), partial(Handler, base="x"))
    base = f"http://127.0.0.1:{server.server_address[1]}"
    server.RequestHandlerClass = partial(Handler, base=base)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    yield base
    server.shutdown()
