# Product Import: sources and base-product imports

Steps 2–3: source configuration, JSON fetching, mapping expressions, filters, category/manufacturer resolution and base-product upserts. No combinations, preview endpoint or cron orchestration are implemented.

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

The legacy loader uses `controllers/admin/AdminPiSourceController.php`; this loads the global controller implementation in `src/Controller/Admin/`. Source records belong to the installation, not individual multistore shops. Uninstall removes all three module tables and the tab; imported catalog data remains.

The editor accepts parallel target/expression rows, preserves failed submissions, rejects duplicate targets and requires a unique lowercase source slug and a URL or file path. Empty mapping rows are ignored. Expression validity is checked at evaluation time so an admin can save work in progress.

Expressions receive `fields` as the complete JSON item. Helpers: `path(fields, 'breadcrumbs.0.title')`, `num(value)`, `str(value)`, `regex('/pattern/', value)`, and `first(list)`. Missing paths return null; `num` accepts PHP numeric values/strings, not localized currency strings. `str` accepts scalars/stringable objects and null; unsupported values and malformed regex patterns become `ExpressionEvaluationException`. Mapping returns `['values' => [...], 'errors' => [target => message]]`, retaining successful fields and assigning null to failed fields. Lists pass through unchanged. Broken filters propagate the same exception; empty/whitespace filters import everything.

JSON must have a top-level array; objects and scalar JSON are rejected. A nonempty URL takes precedence over a file path; HTTP failures do not silently fall back to stale files. Only HTTP(S) URLs are fetched, with no redirect following. Local file paths must identify readable regular files.

## Base-product targets and source options

Canonical mapping targets: `name`, `reference`, `price`, `short_description`, `description`, `ean13`, `weight`, `quantity`, `active`, `manufacturer`, `category_paths`, `images`, `main_image`. These are documented in the source editor, not enforced as an allowlist. New products require a usable name. Existing products retain unmapped name/descriptions/reference/EAN/price/quantity/manufacturer; null or missing weight defaults to 0 and active to true as specified.

`category_paths` is a list of paths, each a list of strings or `{title: ...}` dictionaries. Example expression for scraper breadcrumbs: `[path(fields, 'breadcrumbs')]`. For convenience, the normalizer also accepts a single unwrapped path. A nonempty integer-keyed child array identifies multi-path input; otherwise the input is one path. Empty arrays do not determine shape. In multi-path mode, top-level scalars/dictionaries are ignored. Sparse integer keys are accepted; only string segments/titles survive; deeper nesting is not recursively flattened. Order and duplicate paths/segments are retained. Hashes use `sha1(json_encode(normalizedPath))`, without JSON flags. Invalid UTF-8 causes an exception instead of producing a misleading hash.

Sources now store optional `root_category_id`, `id_lang_default` (default 1), `price_tax_included`, and reserved `deactivate_missing`. The latter has no operational effect yet. Category chains default to `PS_ROOT_CATEGORY`; seen paths are recorded without overwriting category overrides. `setOverride` updates a previously discovered row. Category names are matched exactly under their parent using `category_lang.id_shop = category.id_shop_default` across languages; created names/slugs are filled in every installed language. Manufacturer names are matched exactly in `manufacturer.name`.

The importer receives already-mapped values, resolved category IDs and a manufacturer ID; orchestration remains for later steps. It preserves other language values when updating the source language. For a new product it also seeds the shop-default language's required name/slug. It explicitly sets `id_category_default` to the first resolved category before saving, then calls `updateCategories($categoryIds)`. A new product without categories is associated with `PS_HOME_CATEGORY`. A stale external-product link raises an error rather than silently creating a replacement.

**Tax limitation:** when `price_tax_included` is true, the mapped price is still stored unchanged in the tax-exclusive `Product::price` field. The code contains a TODO and the form displays this caveat. Proper conversion needs the shop's configured tax rules group; no conversion is guessed.

Images are downloaded with HTTP(S) cURL into temporary local files. A supplied main image is tried first (otherwise the first image), and can stand alone. Image creation uses `new Image()`, `add()`, `associateTo()`, `getPathForCreation()`, `ImageManager::resize()` and `ImageType::getImagesTypes('products')`. Original JPEG and configured thumbnail sizes are written; failed image records/files are deleted, temporary downloads are removed, and individual errors are logged without aborting the product. The first successful image becomes cover only when the product has no cover. Existing images/covers are preserved; repeated imports currently append images again (only duplicate URLs within a single call are removed). Cross-run image reconciliation is not implemented. Redirects are not followed.

Catalog writes and external links are not a single transaction; a failure after saving a new product but before linking can leave an unlinked product. Resolve calls are intended to run serially: concurrent auto-creation is not protected by unique name constraints. Current shop context controls new catalog objects/media; full multistore orchestration is outside this step.

## Verification boundaries

Unit tests cover the four initial services plus CategoryPathNormalizer with temporary files and representative scraper fixtures. They deliberately do not exercise SourceRepository, AdminPiSourceController, CategoryResolver, ManufacturerResolver, ProductImporter, CategoryMappingRepository or ExternalProductRepository: these require real PrestaShop classes and a database. No fake PrestaShop mocks are used. Module install/uninstall, tab loading, permissions, form rendering, SQL behavior, category/manufacturer creation, product/stock updates, image APIs, and HTTP fetching need integration verification in a deployment environment. No PrestaShop core is included or downloaded.
