# surfscrape

Turns windsurf webshops into one normalised product table (CSV / XML / JSONL).
Built to run once a day, cheaply, with a new shop costing you one YAML file.

Per product it captures: title, brand, SKU, price, sale price + discount %,
description, all image URLs, category tree, variations, stock status and stock
level where the shop exposes it.

## Quick start

```bash
pip install -r requirements.txt

python -m surfscrape probe https://www.recharge.si          # what platform is it?
python -m surfscrape verify sites/recharge.yaml --limit 5   # what do we extract?
python -m surfscrape scrape sites/ --format csv xml --timestamp
```

Output lands in `output/<shop>-<date>.csv` plus `output/run-stats.json`.

## How it gets the data

Three strategies, cheapest first — `strategy: auto` picks one per shop:

| Strategy | When | Cost for a 2000-product catalogue |
|---|---|---|
| `shopify` | `/products.json` responds | ~8 requests |
| `woocommerce` | Store API (`/wp-json/wc/store/v1/products`) responds | ~20 requests |
| `generic` | everything else | 1 request per product |

The generic path finds products via `robots.txt` → `sitemap.xml` (recursing
into sitemap indexes and `.gz`), falling back to crawling category pages with
pagination. Extraction is layered: **schema.org JSON-LD → OpenGraph → your CSS
selectors**, merged so the CSS layer only fills what the structured data
missed. Most shops emit a schema.org `Product` for Google, so a new shop often
needs zero selectors.

## Adding a shop

1. `python -m surfscrape probe https://newshop.com`
2. Copy `sites/recharge.yaml`, set `name`, `base_url`, and the URL patterns
   that identify a product page.
3. `python -m surfscrape verify sites/newshop.yaml --limit 5`

```
[newshop] strategy=generic  sample=5  sources={'jsonld': 5}
  ok    title          5/5
  MISS  stock_level    0/5
  part  brand          3/5
```

4. Add a selector for each `MISS`, re-run. Selector syntax is plain CSS, with
   `::attr(name)` for attributes and a list to try alternatives in order:

```yaml
selectors:
  stock_level: "p.stock"
  brand: ["*[itemprop=brand]", "a.brand-link"]
  images: "figure img::attr(data-large_image)"
```

That is the whole extension surface — no Python needed for a new shop.

## Daily runs are cheap

Every response is cached in SQLite with its `ETag` / `Last-Modified`, and
re-runs send conditional requests. A page that has not changed returns `304`
with no body: no bandwidth, no parsing, and much lighter load on the shop.
Keep `.cache/` (or the Docker `cache` volume) between runs — that is where the
saving comes from.

The scraper reads `robots.txt`, obeys `Disallow`, and honours `Crawl-delay`
when it is longer than your configured delay. Concurrency and delay are
per-shop settings; keep them gentle even though the shops said yes.

## One process per shop

Yes — that is how it is set up, and it is the right default. Each shop gets its
own container, cache, concurrency and schedule, so a shop that hangs, throttles
you or changes its markup cannot take the others down.

```bash
docker compose build
docker compose run --rm recharge          # one shop
docker compose up                         # all shops, in parallel
```

For scheduling, pick one:

- **cron on the host:** `0 3 * * * cd /srv/surfscrape && docker compose up`
- **GitHub Actions:** `.github/workflows/daily-scrape.yml` is included and runs
  one job per shop in a matrix with `fail-fast: false`.

`scrape sites/` in a single process also works and is fine for two shops; it
runs them sequentially and keeps going if one fails.

## Libraries used, and why

Everything here is off-the-shelf where a good library exists:

- **httpx** — async HTTP/1.1+2 client; the concurrency engine.
- **selectolax** — CSS selection over a C (Lexbor) parser. Typically 5–30×
  faster than BeautifulSoup+lxml on real pages, which matters at thousands of
  product pages a day.
- **pydantic v2** — schema and validation for both the product model and the
  site YAML, so a malformed config fails loudly at load.
- **price-parser** — handles `1.299,00 €` vs `€1,299.00` vs `129,00 EUR`.
  Do not hand-roll this.
- **tenacity** — retry with exponential backoff on 429/5xx.
- **PyYAML** — site configs.

Deliberately *not* used:

- **Scrapy** — the mature, batteries-included option, and a legitimate choice.
  It brings its own project layout, spider classes and twisted-style plumbing;
  for a config-driven engine where adding a shop should mean editing YAML, not
  writing a spider class, ~800 lines of httpx is simpler to own and extend.
  If you later need distributed crawling or a large middleware ecosystem,
  Scrapy is the migration target.
- **extruct** — would replace `extract/jsonld.py`, but its dependency chain
  fails to build on current setuptools and JSON-LD is one selector plus
  `json.loads`.
- **Playwright** — not needed unless a shop renders prices in JavaScript.
  Install with `pip install -e '.[browser]'` and add a rendering fetcher only
  for that shop if `verify` shows prices missing on a page where the browser
  shows them. Every product page you render costs ~50× a plain fetch, so treat
  it as a per-shop last resort.

## Status / what still needs you

The engine and its extractors are tested offline (`pytest`, 10 tests, including
a full discovery→CSV run against a local test shop and a 304-revalidation run).

**The two site configs in `sites/` are scaffolds, not verified.** The sandbox
this was written in cannot reach `recharge.si` or `easy-surfshop.com` — the
egress policy blocks both — so their URL patterns are the conventional defaults
for their likely platforms rather than observed facts. Run `probe` then
`verify` against each before trusting the output; expect to adjust
`product_url_include` and possibly add a few selectors. That is a few minutes
per shop, and `verify` tells you exactly what is missing.

## Output columns

One row per variant (or one per product if it has none):

`shop, product_id, variant_id, sku, title, variant_title, brand, category_path,
category_1..3, url, price, sale_price, effective_price, discount_pct, currency,
availability, stock_level, options, image_main, images, description, source,
scraped_at`

`availability` is normalised to `in_stock | out_of_stock | preorder | backorder`
across all shops and languages. The XML writer emits an RSS 2.0 feed in the
Google Merchant namespace, which most downstream tools already read.
