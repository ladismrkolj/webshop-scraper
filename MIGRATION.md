# Repository split

The scrapers now live at the root instead of under `scraper-app/`. The existing
repository history remains intact. The importer and `local-dev/` moved to
https://github.com/ladismrkolj/ps-dynamic-product-import with their relevant
Git history preserved. Do not force-push the original repository.

On existing deployments, update crontab, service WorkingDirectory and any
external scripts from `webshop-scraper/scraper-app/nightly` to
`webshop-scraper/nightly`. Before updating the checkout, stop scheduled jobs
and preserve untracked `scraper-app/nightly/output/`, `var/`, and local
settings. Restore output and var under `nightly/` after updating. Rebuild
shop virtualenvs with `uv sync`, redeploy the eggs, then run one job manually.
Feed URLs and importer mappings can remain the same when the published feed
location is unchanged.

An installed PrestaShop module remains `modules/productimport/`; its technical
name is unchanged. Update future packaging/deployment to use the new checkout.
