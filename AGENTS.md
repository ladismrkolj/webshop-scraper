# Scraper repository

Each shop is a standalone Scrapy project at the repository root. Keep shop
dependencies in its local `.venv` with `uv sync`; never install globally.
Extraction belongs in web-poet page objects, not spiders. Read the applicable
Scrapy/zyte skill before changing scraper code. New selectors require fixtures.

After extraction changes, run `uv run pytest fixtures/` in each changed shop,
and the configured formatting/lint and Scrapy contracts where present. Do not
claim live crawls were tested from offline fixtures. Avoid live crawls unless
needed; Gong requires a Zyte key. Never commit keys or generated output.

`nightly/` discovers shop projects as siblings. Keep that layout intact.
The PrestaShop importer is maintained separately at
https://github.com/ladismrkolj/prestashop-product-import. The feed files are the
interface; do not introduce imports or paths into the importer checkout.
