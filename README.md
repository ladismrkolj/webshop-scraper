# webshop-scraper

Per-site [Scrapy](https://scrapy.org/) projects that scrape windsurf/kite/SUP
webshop catalogues into structured product data.

Each shop is its own **standalone, self-contained Scrapy project**. There is no
shared framework and no cross-project imports — a change to one shop cannot
break another, and each project pins its own dependencies.

## Shops

| Project | Shop | Platform |
| --- | --- | --- |
| `recharge_si/` | [recharge.si](https://www.recharge.si) | custom HTML |
| `easy_surfshop_com/` | [easy-surfshop.com](https://www.easy-surfshop.com) | — |
| `kitenatura_com/` | [kitenatura.com](https://www.kitenatura.com) | Shopify |
| `obsession_si/` | [obsession.si](https://www.obsession.si) | — |
| `infinitysport_si/` | [infinitysport.si](https://www.infinitysport.si) | — |
| `gong_galaxy_com/` | [gong-galaxy.com](https://www.gong-galaxy.com/en) | Shopify |

## Layout

Every project follows the same shape:

```
<shop>_<tld>/
  scrapy.cfg
  pyproject.toml            # dependencies, pinned via uv.lock
  <shop>_<tld>/
    settings.py             # scrapy-poet + optional Zyte API
    items.py                # ProductItem / NavigationItem dataclasses
    pages/
      navigation.py         # NavigationPage  — product links, pagination, subcategories
      product.py            # ProductPage     — the product fields
    spiders/
      <shop>_<tld>.py       # wires the page objects together
  fixtures/                 # saved pages + expected values, run as tests
```

Extraction lives in the **web-poet page objects** under `pages/`, not in the
spider. The spider only decides what to request; the page objects decide what a
response means. That split is what makes the fixtures possible — each fixture is
a frozen response plus the item it should produce, so a site redesign shows up
as a failing test rather than as silently empty fields.

## Running a scraper

Each project is driven with [uv](https://docs.astral.sh/uv/). From the repo root:

```bash
cd recharge_si && uv sync
```

Crawl the whole shop:

```bash
cd recharge_si && uv run scrapy crawl recharge_si -O products.json
```

Crawl a single category, or a single product:

```bash
cd recharge_si && uv run scrapy crawl recharge_si -a url=https://www.recharge.si/en/windsurf/boards
```

```bash
cd recharge_si && uv run scrapy crawl recharge_si -a product_url=https://www.recharge.si/en/windsurf/boards/patrik-f-cross-113-2024
```

Substitute the project/spider name for the other shops — the directory name and
the spider name are always the same.

## Tests

The fixtures under `fixtures/` are the regression suite. They replay saved
responses through the page objects and assert the extracted item, so they need
no network:

```bash
cd recharge_si && uv run pytest fixtures/
```

Run these after any page-object change, and re-capture the fixtures when a shop
legitimately changes its markup.

## Zyte API

`settings.py` in each project wires up [Zyte
API](https://docs.zyte.com/zyte-api/get-started.html) but leaves it off by
default. Export a key to route requests through it when a site blocks or needs
browser rendering:

```bash
export ZYTE_API_KEY=your-key-here
```

Five of the six shops serve complete HTML over plain HTTP and need no key.

**`gong_galaxy_com` is the exception and cannot be crawled with plain Scrapy.**
gong-galaxy.com discriminates on the client's TLS fingerprint: at the same
moment, curl announcing itself as `python-httpx` gets a 200 while httpx
announcing itself as `curl` gets a 429. The User-Agent is irrelevant, and
Scrapy's own handshake is refused on every URL — product pages, `robots.txt`,
and the public `products.json` feed alike. Its `robots.txt` does allow the paths
the spider visits, so this is infrastructure rather than stated policy, but the
only remaining ways through are a service that fetches on your behalf or
impersonating another client's TLS signature. This project does the former:

```bash
export ZYTE_API_KEY=your-key-here
cd gong_galaxy_com && uv run scrapy crawl gong_galaxy_com -O products.json
```

Without a key the spider runs but collects 429s. Its page objects parse ordinary
raw HTML, so its 104 fixture tests still pass offline with no key.

## Adding a shop

New projects are generated with the `zyte-web-data:scrape` skill workflow, which
explores the site, agrees a field schema, generates the page objects from real
saved pages, and writes the fixtures. Keep the generated project at the repo
root alongside the others.
