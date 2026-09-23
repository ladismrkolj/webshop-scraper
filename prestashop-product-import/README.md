# Product Import: foundational layer

Step 2 only: source configuration, JSON fetching, mapping expressions and filters. No product/category writes or cron execution are implemented.

## Development

Run from this directory:

```sh
composer install
./vendor/bin/phpunit
./vendor/bin/php-cs-fixer fix --dry-run --diff
```

Dependencies and tools stay in local `vendor/`. Runtime source uses PHP 7.2.5-compatible syntax (the evaluator's `mixed` return is documented, not a PHP 8 type declaration). PHPUnit 13 requires PHP 8.4+ for development. Composer selects dependencies for the PHP version doing the installation; a lock resolved on PHP 8.5 is not a guarantee of compatibility with PHP 7.2. Resolve and validate production dependencies for the actual deployment PHP version before packaging.

## Installation and source editor

Package this directory as **`productimport/`** under PrestaShop's `modules/` directory, with production `vendor/` dependencies included. The checkout directory name `prestashop-product-import` is not the module's technical name. Install the module, then use Catalog > Product Import (or its Configure link).

The legacy loader uses `controllers/admin/AdminPiSourceController.php`; this loads the global controller implementation in `src/Controller/Admin/`. Source records belong to the installation, not individual multistore shops. Uninstall removes the source table and tab.

The editor accepts parallel target/expression rows, preserves failed submissions, rejects duplicate targets and requires a unique lowercase source slug and a URL or file path. Empty mapping rows are ignored. Expression validity is checked at evaluation time so an admin can save work in progress.

Expressions receive `fields` as the complete JSON item. Helpers: `path(fields, 'breadcrumbs.0.title')`, `num(value)`, `str(value)`, `regex('/pattern/', value)`, and `first(list)`. Missing paths return null; `num` accepts PHP numeric values/strings, not localized currency strings. `str` accepts scalars/stringable objects and null; unsupported values and malformed regex patterns become `ExpressionEvaluationException`. Mapping returns `['values' => [...], 'errors' => [target => message]]`, retaining successful fields and assigning null to failed fields. Lists pass through unchanged. Broken filters propagate the same exception; empty/whitespace filters import everything.

JSON must have a top-level array; objects and scalar JSON are rejected. A nonempty URL takes precedence over a file path; HTTP failures do not silently fall back to stale files. Only HTTP(S) URLs are fetched, with no redirect following. Local file paths must identify readable regular files.

## Verification boundaries

Unit tests cover the four services with temporary files and representative scraper fixtures. They deliberately do not exercise SourceRepository or AdminPiSourceController: both require a real PrestaShop bootstrap/database. Module install/uninstall, tab loading, permissions, form rendering, SQL behavior, and HTTP fetching need integration verification in a deployment environment. No PrestaShop core is included or downloaded.
