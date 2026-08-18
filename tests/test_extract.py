"""Offline tests: fixture HTML/JSON in, normalised Product out."""

from decimal import Decimal

from surfscrape.config import Selectors
from surfscrape.extractors import css as css_extract
from surfscrape.extractors import jsonld as jsonld_extract
from surfscrape.extractors.common import norm_availability, to_decimal
from surfscrape.extractors.platform import shopify_product, woo_product

JSONLD_HTML = """
<html><head>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[
 {"@type":"ListItem","position":1,"name":"Home","item":"/"},
 {"@type":"ListItem","position":2,"name":"Sails","item":"/sails"},
 {"@type":"ListItem","position":3,"name":"Wave","item":"/sails/wave"}]}
</script>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Product","name":"Duotone Super Star 4.5",
 "sku":"DT-SS-45","brand":{"@type":"Brand","name":"Duotone"},
 "description":"Wave sail.","image":["/img/a.jpg","https://cdn.x/b.jpg"],
 "offers":{"@type":"Offer","price":"1.299,00","priceCurrency":"EUR",
           "availability":"https://schema.org/InStock","inventoryLevel":3}}
</script></head><body>
<p class="stock">Na zalogi (3)</p>
<span class="old-price">1.499,00 €</span>
</body></html>
"""


def test_jsonld_product():
    p = jsonld_extract.extract(JSONLD_HTML, "https://shop.test/p/1", "t", ["Home"])
    assert p.title == "Duotone Super Star 4.5"
    assert p.brand == "Duotone"
    assert p.sku == "DT-SS-45"
    assert p.price == Decimal("1299.00")
    assert p.currency == "EUR"
    assert p.availability == "in_stock"
    assert p.stock_level == 3
    assert p.categories == ["Sails", "Wave"]
    assert p.images == ["https://shop.test/img/a.jpg", "https://cdn.x/b.jpg"]
    assert p.is_complete()


def test_css_layer_fills_gaps_and_orders_sale_price():
    sel = Selectors(sale_price="span.old-price", availability="p.stock", stock_level="p.stock")
    base = jsonld_extract.extract(JSONLD_HTML, "https://shop.test/p/1", "t", ["Home"])
    over = css_extract.extract(JSONLD_HTML, "https://shop.test/p/1", "t", sel, currency="EUR")
    assert over.sale_price == Decimal("1499.00")
    assert over.availability == "in_stock"
    assert over.stock_level == 3

    merged = base.merge(over).reconcile_prices()
    # The "old price" element holds 1499, so that is the list price and the
    # 1299 from JSON-LD is what the customer actually pays.
    assert merged.price == Decimal("1499.00")
    assert merged.sale_price == Decimal("1299.00")
    assert merged.discount_pct == 13.34
    assert merged.title == "Duotone Super Star 4.5"   # JSON-LD fields survive


def test_opengraph_fallback():
    html = """<html><head>
    <meta property="og:title" content="Fanatic Gecko 111">
    <meta property="og:image" content="https://cdn.x/g.jpg">
    <meta property="product:price:amount" content="899.00">
    <meta property="product:price:currency" content="EUR">
    <meta property="product:availability" content="instock"></head></html>"""
    p = jsonld_extract.extract(html, "https://shop.test/p/2", "t")
    assert p.source == "opengraph"
    assert p.price == Decimal("899.00")
    assert p.availability == "in_stock"


def test_shopify_variants_and_discount():
    node = {
        "id": 1, "handle": "north-sail", "title": "North Sail", "vendor": "North",
        "body_html": "<p>Nice <b>sail</b></p>", "product_type": "Sails",
        "images": [{"src": "https://cdn/1.jpg"}],
        "options": [{"name": "Size"}],
        "variants": [
            {"sku": "N-45", "title": "4.5", "option1": "4.5", "price": "500.00",
             "compare_at_price": "600.00", "available": True, "inventory_quantity": 2},
            {"sku": "N-50", "title": "5.0", "option1": "5.0", "price": "650.00",
             "compare_at_price": None, "available": False, "inventory_quantity": 0},
        ],
    }
    p = shopify_product(node, "t", "https://shop.test")
    assert p.url == "https://shop.test/products/north-sail"
    assert p.description == "Nice sail"
    assert len(p.variants) == 2
    v = p.variants[0]
    assert v.price == Decimal("600.00") and v.sale_price == Decimal("500.00")
    assert v.discount_pct == 16.67
    assert v.options == {"Size": "4.5"}
    assert p.variants[1].availability == "out_of_stock"
    assert p.availability == "in_stock"       # at least one variant buyable
    assert p.stock_level == 2


def test_woocommerce_minor_units():
    node = {
        "id": 7, "name": "Ion Harness", "sku": "ION-1",
        "permalink": "https://shop.test/izdelek/ion-harness",
        "description": "<p>Harness</p>", "is_in_stock": True,
        "categories": [{"name": "Harnesses"}], "images": [{"src": "https://cdn/h.jpg"}],
        "attributes": [{"name": "Brand", "terms": [{"name": "ION"}]}],
        "prices": {"price": "12000", "regular_price": "15000", "sale_price": "12000",
                   "currency_code": "EUR", "currency_minor_unit": 2},
        "variations": [],
    }
    p = woo_product(node, "t")
    assert p.price == Decimal("150.00")
    assert p.sale_price == Decimal("120.00")
    assert p.discount_pct == 20.0
    assert p.brand == "ION"
    assert p.categories == ["Harnesses"]


def test_availability_and_price_parsing():
    assert norm_availability("https://schema.org/OutOfStock") == "out_of_stock"
    assert norm_availability("Ni na zalogi") == "out_of_stock"
    assert norm_availability("Na zalogi") == "in_stock"
    assert norm_availability(False) == "out_of_stock"
    assert to_decimal("1.299,00 €") == Decimal("1299.00")
    assert to_decimal("€1,299.00") == Decimal("1299.00")
    assert to_decimal("") is None


def test_row_flattening():
    p = jsonld_extract.extract(JSONLD_HTML, "https://shop.test/p/1", "t", ["Home"])
    rows = p.rows()
    assert len(rows) == 1 and rows[0]["category_path"] == "Sails > Wave"

    node = {"id": 1, "handle": "h", "title": "T", "vendor": "V", "options": [{"name": "Size"}],
            "variants": [{"sku": "a", "option1": "S", "price": "10", "available": True},
                         {"sku": "b", "option1": "M", "price": "10", "available": True}],
            "images": [], "body_html": ""}
    sp = shopify_product(node, "t", "https://shop.test")
    assert len(sp.rows()) == 2          # one row per variant
