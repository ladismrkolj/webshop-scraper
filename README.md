# surfscrape

A product-extraction layer on top of [Scrapy](https://scrapy.org), for windsurf
webshops. Scrapy does the crawling; this repo does the part Scrapy has no
opinion about — turning any shop's product page into the same row.

Per product: title, brand, SKU, price, sale price + discount %, description,
all image URLs, category tree, variations, stock status and stock level.

```bash
pip install -r requirements.txt

surfscrape url      https://shop.si/izdelek/jadro-45     # one product, prints JSON
surfscrape category https://shop.si/kategorija/jadra     # one category + pagination
surfscrape site     recharge                             # whole shop -> CSV
surfscrape site     all --format csv xml --timestamp     # every shop in sites/

surfscrape init     https://shop.si --write              # generate a config
surfscrape verify   recharge                             # what did we actually get?
```

Everything the CLI does, plain Scrapy does too — the CLI only saves you the
settings boilerplate:

```bash
scrapy crawl shop -a site=recharge -O out.csv
scrapy crawl shop -a url=https://shop.si/izdelek/jadro-45
```

## Why Scrapy

Because it deletes most of the code. These are all stock settings in
`surfscrape/settings.py`, not things we maintain:

| Need | Handled by |
|---|---|
| robots.txt, `Disallow`, `Crawl-delay` | `ROBOTSTXT_OBEY` |
| conditional GET, 304s on daily re-runs | `HTTPCACHE_POLICY = RFC2616Policy` |
| retries with backoff, timeouts | `RETRY_*`, `DOWNLOAD_TIMEOUT` |
| adaptive politeness | `AUTOTHROTTLE_ENABLED` |
| sitemap discovery, indexes, `.gz` | `SitemapSpider` |
| duplicate URL filtering | built-in dupefilter |
| CSV / XML / JSON / JSONL output | `FEEDS` |

What is left — and all this project really is — is `surfscrape/extractors/`:
the stack that reads a product out of a page.

## The extraction layer

For each product page, in order, each layer filling only what the last one left
blank:

1. **schema.org JSON-LD** — most shops emit a `Product` node for Google, so
   this alone usually covers a new shop.
2. **OpenGraph meta tags** — fallback for the rest.
3. **your CSS selectors** — `sites/<shop>.yaml`, for whatever is still missing.

Shops on Shopify or WooCommerce skip HTML entirely: `/products.json` and the
WooCommerce Store API return fully-structured products with real variants and
inventory, so a 2000-product catalogue is ~8 requests instead of 2000. The
spider detects this from the homepage and switches by itself
(`platform: auto`).

## Adding a shop

```bash
surfscrape init https://newshop.com --write
```

It fetches the homepage, detects the platform, reads robots.txt and the
sitemap, infers the product-URL pattern from the URLs it finds, then fetches a
sample product page and checks what the automatic extractors got. For each
field they missed it tries a library of known theme selectors and keeps the
ones that produce a plausible value:

```
# platform: html
# sitemaps: 1 advertised
# sitemap URLs sampled: 400
# product URL pattern: ['/izdelek/']
# automatic extraction (jsonld) covered: availability, brand, breadcrumbs,
#   description, images, price, sku, title
# selectors found: {'sale_price': 'p.price del .amount', 'stock_level': 'p.stock'}
wrote sites/newshop.yaml
```

Then check it:

```
$ surfscrape verify newshop --limit 5
[newshop] 5 rows  sources={'jsonld'}
  ok    title           5/5
  part  sale_price      1/5      <- only one product is discounted, fine
  MISS  brand           0/5      <- add a selector
```

Add a selector per `MISS` and re-run. A whole shop config is often this small:

```yaml
name: newshop
base_url: https://newshop.com
platform: auto
currency: EUR
product_url_include: ["/izdelek/"]
selectors:
  brand: ".product-brand"           # plain CSS
  images: ".gallery img::attr(src)" # ::attr() for attributes
  sku: ["span.sku", ".product-sku"] # a list tries each in order
```

Note on `sale_price`: point it at the shop's *struck-through original* price.
`price` is the higher number, `sale_price` the lower, and the engine swaps them
if a selector finds them the other way round.

## Running locally (no Docker)

Docker is only for the scheduled daily runs. For testing, work directly:

```bash
git clone <repo> && cd webshop-scraper
python -m venv .venv && source .venv/bin/activate
pip install -e .            # or: pip install -r requirements.txt
```

Then, from anywhere:

```bash
surfscrape url https://shop.si/izdelek/jadro-45      # fastest feedback loop
surfscrape site recharge --limit 5 --format csv      # 5 products, then stop
surfscrape verify recharge                           # what got filled in?
```

`--limit N` is the flag to reach for while iterating: it stops after N products
so you are not hammering a shop to test a selector. `surfscrape url` needs no
config at all, so it is the quickest way to see whether a page is even
extractable.

Without `pip install`, `python -m surfscrape ...` works from the repo root just
as well. Configs are read from `./sites/` if that exists, otherwise from the
ones in the repo — so you can keep a scratch `sites/` in a test directory
without touching the real ones.

Two Scrapy tools worth knowing when a selector fights you:

```bash
scrapy shell "https://shop.si/izdelek/jadro-45"   # REPL: response.css("p.price").get()
scrapy parse --spider=shop -a site=recharge "https://shop.si/izdelek/jadro-45"
```

And the test suite needs no network at all — it runs a shop on localhost:

```bash
pip install -e '.[dev]' && pytest -q
```

## One process per shop

For the daily production runs, each shop gets its own container, cache and
schedule, so a shop that hangs, throttles you or changes its markup cannot
affect the others.

```bash
docker compose build
docker compose run --rm recharge     # one shop
docker compose up                    # all shops, in parallel
```

Scheduling — pick one:

- **cron:** `0 3 * * * cd /srv/surfscrape && docker compose up`
- **GitHub Actions:** `.github/workflows/daily-scrape.yml`, one job per shop in
  a matrix with `fail-fast: false`.

Keep the cache volume between runs. That is what makes the daily re-run cheap:
unchanged pages come back `304` with no body and no parsing.

## Layout

```
surfscrape/
  extractors/       the actual work: jsonld.py, css.py, platform.py, common.py
  spiders/shop.py   one spider for every shop (~180 lines)
  generate.py       `init`: writes a config by inspecting a shop
  config.py         the YAML schema
  models.py         Product/Variant, and the flattening into export rows
  settings.py       Scrapy settings - the crawl behaviour lives here
  cli.py            argparse over the above
sites/*.yaml        one file per shop
```

## Prior art

The layered "default extractor, overridden per site by URL pattern" shape is
the same idea as Zyte's [scrapy-poet / web-poet page
objects](https://scrapy-poet.readthedocs.io/en/stable/rules-from-web-poet.html),
where a `RulesRegistry` swaps in a per-site page object via `@handle_urls`.
That is the right thing to grow into if a shop ever needs real per-site *code*
rather than selectors — the extractor stack is already the seam for it. It is
deliberately not used yet: it brings dependency injection and providers, and so
far a YAML file per shop does the job.

[`zyte-spider-templates`](https://zyte-spider-templates.readthedocs.io/en/latest/templates/e-commerce.html)
solves exactly this problem (`scrapy crawl ecommerce -a url=...`) but its
extraction runs through the paid Zyte API. The CLI here borrows its shape.

## Status

13 tests, all offline. Extractor unit tests plus end-to-end runs of the real
Scrapy stack against a local test shop: single URL, category with pagination,
sitemap-driven full run to CSV, `--limit`, robots.txt `Disallow`, and
`init`-generates-a-config-that-then-works.

```bash
pytest -q
```

**The two configs in `sites/` are unverified scaffolds.** The sandbox this was
written in cannot reach `recharge.si` or `easy-surfshop.com` (egress policy), so
their patterns are conventional defaults, not observed facts. Replace each with
a generated one — `surfscrape init https://www.recharge.si --write` — and check
it with `verify`. That is the ten-second version of what would otherwise be an
afternoon.

For a shop that renders prices in JavaScript, add
[scrapy-playwright](https://github.com/scrapy-plugins/scrapy-playwright)
(`pip install -e '.[browser]'`) for that shop only; it costs roughly 50× a
plain fetch per page.
