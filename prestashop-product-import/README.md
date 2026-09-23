# Product Import: sources and base-product imports

Steps 2–4: source configuration, JSON fetching, mapping expressions, filters, category/manufacturer resolution base-product upserts, and real combinations. No preview endpoint or cron orchestration is implemented.

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

The legacy loader uses `controllers/admin/AdminPiSourceController.php`; this loads the global controller implementation in `src/Controller/Admin/`. Source records belong to the installation, not individual multistore shops. Uninstall removes all four module tables and the tab; imported catalog data remains.

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

## Combinations (step 4)

Optional source `variant_mapping` stores `variants_expression`, `attributes` (name/expression rows), and `fields` (target/expression map). An empty variants expression saves NULL and ignores the section. Configured variants require an attribute row and a reference field row; blank rows are ignored, incomplete/duplicate rows rejected. Expressions are not syntax-validated at save time. `VariantFieldMapper::variants($item, $expression)` evaluates the list once and rejects non-list or non-array entries. `mapAttributes($item, $variant, $definitions)` and `mapFields($item, $variant, $mapping)` both expose `fields` and `variant`, with per-entry failures recorded as null plus an error. Attribute values are string-coerced via the expression `str()` helper; null remains null. Callers must reject failed/empty required identity or attribute values before resolving/importing. The future orchestrator decides how to report these errors; no orchestration is added here.

`CombinationImporter::import($idSource, $parentExternalId, $variantExternalId, $idProduct, $baseProductPrice, $mappedFields, $attributeIds)` upserts a real `Combination` (`product_attribute`, with shop fields in `product_attribute_shop`). The external key is exactly `parentExternalId . ':' . variantExternalId`, scoped by source, and must fit 191 characters. Callers must choose identifiers without ambiguous colon boundaries (e.g. `a:b` + `c` collides with `a` + `b:c`); the requested composition is not escaped or hashed. Existing links must refer to a valid combination of the supplied parent.

Price is an impact: absolute variant price minus the supplied base product price, including negative impacts. Both prices must use the same tax basis; step 3's unimplemented tax conversion caveat still applies. Missing price preserves an existing impact (new ObjectModel defaults apply). Attribute links use `Combination::setAttributes($attributeIds)`; stock uses `StockAvailable::setQuantity($idProduct, $idCombination, $quantity)`. Combinations have no native `active` property: explicit false forces zero stock. This does not hide the combination or override the shop's allow-out-of-stock-ordering policy. Missing quantity preserves stock. The first imported combination becomes default when none exists; later imports preserve the existing default, even if unavailable. Non-default `default_on` is NULL. `Product::updateDefaultAttribute()` refreshes the parent's cached default.

Attribute groups/values are global and reused by exact BINARY name matches across installed languages (case-sensitive, consistent with step 3). Group creation fills `name` and required `public_name`, uses `group_type = 'select'` and `is_color_group = false`. Values use `ProductAttribute` on PS 8, or legacy `Attribute` on PS 1.7; an ObjectModel check prevents accidentally instantiating PHP 8's built-in Attribute class. Names are filled in every installed language, matching the category resolver.

`ProductImageAttacher` now owns the existing download/Image/thumbnail/cleanup logic and returns the new image ID. Both importers reuse it. Combination images use `Combination::setImages([$idImage])`, associating the image specifically via the combination API rather than only adding a product gallery image. Failures are logged and do not abort the combination; failed new images are removed. Setting a supplied image replaces that combination's image associations. As in step 3, repeated imports append gallery images; previous gallery images are not reconciled or deleted. Catalog changes and external linking remain nontransactional; imports should run serially.

Unit tests cover VariantFieldMapper and retain all earlier tests. AttributeResolver, CombinationImporter and ExternalCombinationRepository are deliberately not tested against fake PrestaShop classes: their ObjectModel/SQL/stock/image/default behavior, along with the admin form, requires real PrestaShop integration verification. Combination/setAttributes/setImages and price-impact storage are implemented with high confidence in the legacy 1.7/8 API, but have not been executed here. Version-specific ProductAttribute naming and default-cache/multistore behavior deserve deployment review.
