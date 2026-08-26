# CSV Product Sync — PrestaShop 9 module

Imports the CSVs produced by the scrapers in this repository into a PrestaShop
9 catalogue, and keeps them in sync: prices and stock follow the feed, products
that vanish from it are disabled (or removed), and new ones are created.

Every shop scraped here has its own `ProductItem` schema, so every CSV has
different columns. The module therefore has no fixed idea of what a CSV looks
like: you add one **source** per feed and map its columns onto PrestaShop
fields yourself.

## Installing

Copy the module folder into the shop and install it from the back office:

```
scp -r prestashop/csvproductsync/ user@host:~/domains/shop.tld/public_html/modules/
```

Then **Modules → Module Manager → search "CSV Product Sync" → Install**.

The back office gains a **Catalog → CSV Product Sync** menu with three screens:
Sources, Category mapping and Import history.

## Setting up a feed

1. **Sources → Add new source.** Give it a name and the CSV location — an
   `https://` URL, or a path on the server (a relative path is taken from the
   shop root, e.g. `var/csv/recharge_si.csv`).

   The rest of the form is about what an import is allowed to do:

   | Setting | What it decides |
   | --- | --- |
   | Match products by | Which field identifies a product across runs. Prefer the feed's own product id (`external_id`) — names and prices change, ids do not. |
   | Create products missing from the shop | Whether the feed may add products, and whether they arrive enabled. |
   | Update price / stock / text / images / categories | Which parts of an existing product the feed owns. Leave *text* off if you edit descriptions by hand, or a nightly import will overwrite them. |
   | Price rules | A multiplier for your markup, and un-taxing feed prices (scraped shop prices normally include VAT; PrestaShop stores prices without it). |
   | When a product is no longer in the CSV | Disable it, zero its stock, delete it, or leave it alone. |
   | Safety limit | If more than this share of the source's products disappear at once, nothing is removed and the run says so. A half-failed scrape looks exactly like a closing-down sale; this is what tells them apart. |

2. **Mapping.** Lists every column in the CSV next to a sample value from the
   file, and lets you pick the PrestaShop field it feeds. Columns whose names
   match the scrapers' conventions (`product_id`, `main_image`, `breadcrumbs`…)
   come pre-suggested.

   Each column also gets a **transform**, which is where the scraped shapes are
   handled: `1.299,00 €` → a number, `True` → a boolean,
   `http://schema.org/InStock` → in stock, `['https://a.jpg', 'https://b.jpg']`
   (Scrapy's Python list repr) → an image list, a breadcrumb list → a
   `Home > Boards > Kites` category path.

   A column can also feed a **product feature**, for things like board volume
   that have no PrestaShop field of their own.

3. **Category mapping.** Press **Scan the CSV for categories** to collect every
   distinct category value the feed uses, then map each one to a category in
   your shop (or mark it ignored).

   The scan is re-runnable and additive: values you have already decided on keep
   their mapping, and a re-scan only surfaces the ones nobody has seen before —
   which is what makes it safe to re-run whenever the scraped shop invents a new
   category. An import that meets a brand-new value records it too, so it shows
   up here rather than disappearing quietly.

   A source can instead be set to recreate the feed's own category paths
   automatically, if you would rather let the feed grow your category tree.

4. **Preview.** Shows what an import *would* do, changing nothing:

   - the first rows as the importer reads them, field by field, with the verdict
     for each (create / update / unchanged / skip) and where its categories land;
   - **Dry run over the whole file**: counts for the whole feed, why rows would
     be skipped, which products would be removed and whether the safety limit
     would stop that, and every category value still unmapped.

   Worth running after every mapping change, and after a scraper change.

5. **Import now**, once the preview looks right.

## Running it from cron

Hostinger: **hPanel → Advanced → Cron Jobs**. The module's configuration page
shows the exact line, including this installation's token:

```
/usr/bin/php /home/uXXXXXX/domains/shop.tld/public_html/modules/csvproductsync/cron.php --token=TOKEN
```

Every enabled source is imported. Useful flags:

| Flag | Effect |
| --- | --- |
| `--id_source=3` | Import just that source. |
| `--dry-run` | Report what would change, change nothing. |
| `--quiet` | Only the per-source summary lines. |

Schedule it shortly after the nightly scrape in `nightly/` finishes. Two runs of
the same source never overlap: a second one exits rather than fighting the first
over the same products.

The same entry point works over HTTP
(`https://shop.tld/modules/csvproductsync/cron.php?token=TOKEN`) for hosts that
only offer URL cron, but PHP CLI is the better choice — a large feed will
outlive the web server's request timeout.

## How it decides what to change

The module records which products it imported, per source, in
`csvsync_link`. Everything follows from that:

- **A product is only ever touched by the source that created it.** Products you
  added by hand are invisible to the importer, and so is another feed's stock.
- **Unchanged rows cost nothing.** Each row's mapped values are hashed; on the
  next run, a row that hashes the same is skipped without a single write. On a
  nightly import of a stable catalogue that is almost every row.
- **Images are hashed separately**, so a price change does not trigger a
  re-download of a dozen images.
- **Removals are bounded** by the safety limit described above.

## What it does not do

- **Combinations.** The scraped feeds carry variants (sizes, colours), but the
  importer writes one PrestaShop product per row and ignores variant columns. A
  feed with one row per variant will import the first row and skip the rest as
  duplicates.
- **Specific prices / discounts.** A feed's sale price can be mapped to the
  wholesale price for reference, but no specific-price rule is created.
- **Deleting categories, brands or suppliers** it created. It only ever adds.

## Tests

The parsing layer — the value transforms and the CSV reader — runs without a
shop:

```
php prestashop/csvproductsync/tests/run_tests.php
```

These cover the cases the scraped feeds actually produce: decimal commas and
currency suffixes, `True`/`False`, schema.org availability URLs, Scrapy's Python
list repr, BOMs, embedded commas and quotes, and blank lines. The rest of the
module needs a real PrestaShop installation to exercise.
