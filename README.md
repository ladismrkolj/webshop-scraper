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

## Nightly run

`nightly/` crawls every shop once a night through a local
[scrapyd](https://scrapyd.readthedocs.io/) and writes one CSV per shop,
stamped with the run's date and time so nothing is ever overwritten:

```
nightly/output/recharge_si_2026-08-23_023005.csv
```

All shops in one run share a single timestamp, so a night's files sort
together.

Scrapyd runs the projects from eggs in one environment, so `nightly/` pins the
union of the shop projects' dependencies alongside the daemon. The shop
projects' own `uv sync`'d virtualenvs are not used at run time — only for
building the eggs.

### Setting up a fresh machine

From a clean Debian/Ubuntu LXC container or VM, as a normal (non-root) user
with sudo. Substitute your package manager on other distributions; nothing here
is Debian-specific beyond the `apt` lines.

```bash
# 1. System packages. git and curl to fetch things, cron for the schedule,
#    build-essential + libxml2/libxslt/libffi headers because lxml and cffi
#    compile from source if no wheel matches.
sudo apt update
sudo apt install -y git curl cron build-essential \
    libxml2-dev libxslt1-dev libffi-dev libssl-dev zlib1g-dev
sudo systemctl enable --now cron

# 2. uv, which manages Python itself — no system python3.13 needed.
curl -LsSf https://astral.sh/uv/install.sh | sh
source "$HOME/.local/bin/env"          # or open a new shell

# 3. The repo.
git clone https://github.com/ladismrkolj/webshop-scraper.git ~/webshop-scraper
cd ~/webshop-scraper

# 4. The runner environment (uv fetches CPython 3.13 on first sync).
cd nightly && uv sync

# 5. Each shop project, so scrapyd-deploy can build its egg.
cd ~/webshop-scraper
for shop in easy_surfshop_com infinitysport_si kitenatura_com obsession_si recharge_si; do
    (cd "$shop" && uv sync)
done

# 6. One crawl by hand, to confirm the whole path works before trusting cron.
cd ~/webshop-scraper/nightly && ./run_nightly.sh
```

A container needs **outbound HTTPS to the shops and to PyPI**, and enough disk
for the environments (~1 GB) plus the CSVs. 1 vCPU and 1 GB RAM are enough —
the crawls are rate-limited to one request per second per domain, so they are
waiting on the network, not on the CPU.

Then install the schedule:

```bash
crontab -e
# paste the line from nightly/crontab.example, with your real path:
30 2 * * * cd /home/youruser/webshop-scraper/nightly && ./run_nightly.sh >> var/nightly-cron.log 2>&1
```

`run_nightly.sh` starts scrapyd itself if it is not already listening, so cron
is the only thing that needs to be running for the nightly crawl to happen.
On a box that stays up, you may prefer scrapyd as a service instead — see
`nightly/scrapyd.service.example`.

### Running

```bash
cd nightly
./run_nightly.sh                            # what cron runs: deploy + crawl all shops
./run_nightly.sh --project recharge_si      # just one shop
```

The pieces are usable on their own:

```bash
cd nightly
uv run scrapyd                              # the daemon, on 127.0.0.1:6800
uv run python nightly.py deploy             # build + upload an egg per project
uv run python nightly.py run                # schedule all shops, wait, report
uv run python nightly.py run --timeout 3600 # give up on a wedged crawl sooner
uv run python nightly.py status             # job counts per shop
```

`deploy` re-uploads the current code, so run it (or `run_nightly.sh`, which
does it for you) after any change to a page object — scrapyd otherwise keeps
crawling the egg it already has.

A run ends with a summary and exits non-zero if any shop timed out or produced
an empty CSV, which is what makes a cron failure mail worth reading:

```
[summary]
  easy_surfshop_com       412 products  /home/you/webshop-scraper/nightly/output/easy_surfshop_com_2026-08-23_023005.csv
  infinitysport_si        377 products  ...
  recharge_si             DID NOT FINISH
```

### Monitoring

```bash
cd nightly
uv run python nightly.py status             # pending/running/finished per shop
tail -f var/nightly-cron.log                # last night's summary, as cron saw it
tail -f var/logs/recharge_si/recharge_si/*.log   # one crawl's Scrapy log
tail -f var/scrapyd.log                     # the daemon itself
ls -lt output/ | head                       # newest CSVs first
```

Scrapyd also has a small web UI with the job list and links to every log, at
<http://127.0.0.1:6800/>. It binds to loopback only, so reach it from your
laptop over SSH rather than exposing it:

```bash
ssh -L 6800:127.0.0.1:6800 youruser@yourbox    # then open http://127.0.0.1:6800/
```

The end of a crawl's log holds Scrapy's stats — `item_scraped_count`,
`downloader/response_status_count/*`, `finish_reason`. A shop that suddenly
returns far fewer products than the night before usually means its markup
changed; run that project's fixture tests (see below) to find out which page
object broke.

### Configuring

| What | Where |
| --- | --- |
| Which shops run by default | `DEFAULT_PROJECTS` in `nightly/nightly.py` |
| When the crawl happens | your crontab line (`nightly/crontab.example`) |
| How long before a wedged crawl is abandoned | `DEFAULT_TIMEOUT_SECONDS` in `nightly/nightly.py`, or `--timeout` |
| How many shops crawl at once, log retention, port | `nightly/scrapyd.conf` (`max_proc`, `jobs_to_keep`, `http_port`) |
| Politeness, User-Agent, robots.txt for one shop | that project's `<shop>/<shop>/settings.py` |
| CSV columns | that project's `items.py` — the `ProductItem` fields *are* the columns |
| Zyte API | `export ZYTE_API_KEY=…` before the run (see below) |

Changing `http_port` means passing `--url http://127.0.0.1:<port>` to
`nightly.py`, and editing the `curl` check in `run_nightly.sh`.

The default is the **five plain-HTTP shops** — `gong_galaxy_com` is left out,
since without `ZYTE_API_KEY` it only collects 429s (see below). Add it with
`--project gong_galaxy_com` once you have a key exported, or move it into
`DEFAULT_PROJECTS` to make it part of every night.

### Output

Each shop has its own item schema, so each gets its own CSV. The columns come
from that project's `ProductItem` dataclass rather than from whatever the first
scraped product happened to fill in, so the header is stable and complete even
across nights where different fields are empty. To add or drop a column, edit
the dataclass (and the page object that fills it) — the CSV follows.

```bash
cd nightly/output
column -s, -t < recharge_si_2026-08-23_023005.csv | less -S   # eyeball a run
ls recharge_si_*.csv                                          # every night, oldest first
```

Nothing prunes `output/`, so it grows by one CSV per shop per night. Drop
something like this in cron if that matters:

```bash
find ~/webshop-scraper/nightly/output -name '*.csv' -mtime +90 -delete
```

`--output DIR` writes elsewhere — a mounted share, say — without touching the
repo. Both `nightly/output/` and `nightly/var/` are untracked.

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
