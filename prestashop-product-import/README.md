# Product Import: sources and base-product imports

Steps 2–6: source configuration, JSON fetching, mapping expressions, filters, category/manufacturer resolution, base-product upserts, and real combinations. Read-only preview and a secret-token cron endpoint are available.

## Getting started: configuring your first source

1. **Catalog → Product Import → "+ Add source"**.
2. Fill in the required fields:
   - **Name** — any label.
   - **Technical key** — a lowercase slug (letters/numbers/`_`/`-`), unique across sources.
   - **JSON URL** or **JSON file path** — exactly one. A URL is fetched over HTTP(S) at import time; a file path is read directly from disk on the PrestaShop server (e.g. useful if the scraper and PrestaShop share a filesystem/volume).
   - Leave **Identifier field** blank for now — inspect the JSON before choosing it.
3. **Save the source once with just those fields filled in** — saving keeps you on the edit page. Saved expressions enable category and brand discovery.
4. **Test source** runs automatically after loading a saved source and uses the first item in the feed. It shows available fields with example values, e.g. `breadcrumbs[0].title → "Windsurf"`, to help choose expressions.
5. Fill in **Identifier field** with the JSON key that uniquely identifies a product within this source (e.g. `product_id`, `sku`, `url`), so re-imports update instead of duplicate. Then fill in **Field mapping** rows: each row is `target field → expression`. The canonical target fields are `name`, `reference`, `price`, `short_description`, `description`, `ean13`, `weight`, `quantity`, `active`, `manufacturer`, `category_paths`, `images`, `main_image` — see "Base-product targets and source options" below for exactly what each does. Every expression sees the whole item as `fields` (e.g. `fields['name']`, `num(fields['price'])`) — see "Expressions" below for the full helper-function list.
6. If your source has product variants (size/color/etc.), open **Product fields → Variants (optional)** — see "Combinations" below.
7. **Save again.**
8. Click **"Test configuration"** to run one real item through the whole pipeline (filter → mapping → category/manufacturer resolution) without writing anything to the database, and confirm the resolved values look right.
9. If you mapped `category_paths`, click **"Discover categories from full JSON"** — this scans *every* item in the source (not just the preview one) and records every unique category path it finds, without creating anything. Open the Categories tab to see the full list and, for any path, override "auto-create" with an existing store category via the dropdown. The Brands tab works the same way. Defaults and overrides save immediately, independently of the main Save button. Paths left as "auto-create" get a fresh category chain created for them automatically the first time a real import needs them.
10. Once satisfied, click **Run import now** on the saved source editor to import that source, or choose **All active sources** or one source in the source list panel. Set up the daily cron URL shown on the source list for automatic runs (see "Daily cron and run logs" below).

## Development

Run from this directory:

```sh
composer install
./vendor/bin/phpunit
./vendor/bin/php-cs-fixer fix --dry-run --diff
```

Dependencies and tools stay in local `vendor/`. Target platform is **PrestaShop 9.x**, which requires **PHP 8.1+** (`composer.json`'s floor and `ps_versions_compliancy` in `productimport.php`/`config.xml` are set accordingly). Some code still uses PHP-7.2-era patterns (e.g. the evaluator's PHPDoc `mixed` return instead of a native union type) left over from before the PS9 target was confirmed — harmless under 8.1+, just not yet modernized; safe to clean up later, not required for correctness. PrestaShop 9 continues to support legacy `AdminController`/`HelperForm`/`HelperList`-based module admin pages for backward compatibility (confirmed against PrestaShop's own developer docs), so this module's admin controller approach remains valid, though it has not yet been exercised against a real PS9 install. PHPUnit 13 requires PHP 8.4+ for development; Composer selects dependencies for the PHP version doing the installation, so re-resolve on the actual deployment PHP version before packaging if it differs from the machine used here.

## Installation and source editor

Package this directory as **`productimport/`** under PrestaShop's `modules/` directory, with production `vendor/` dependencies included. The checkout directory name `prestashop-product-import` is not the module's technical name. Install the module, then use Catalog > Product Import (or its Configure link).

The legacy loader uses `controllers/admin/AdminPiSourceController.php`; this loads the global controller implementation in `src/Controller/Admin/`. Source records belong to the installation, not individual multistore shops. Uninstall removes all five module tables, both tabs and the cron token; imported catalog data remains.

The editor accepts parallel target/expression rows, preserves failed submissions, rejects duplicate targets and requires a unique lowercase source slug and a URL or file path. Empty mapping rows are ignored. Expression validity is checked at evaluation time so an admin can save work in progress.

Expressions receive `fields` as the complete JSON item. Helpers: `path(fields, 'breadcrumbs.0.title')`, `num(value)`, `str(value)`, `regex('/pattern/', value)`, and `first(list)`. Missing paths return null; `num` accepts PHP numeric values/strings, not localized currency strings. `str` accepts scalars/stringable objects and null; unsupported values and malformed regex patterns become `ExpressionEvaluationException`. Mapping returns `['values' => [...], 'errors' => [target => message]]`, retaining successful fields and assigning null to failed fields. Lists pass through unchanged. Broken filters propagate the same exception; empty/whitespace filters import everything.

Optional **Filter expression** examples to adapt to your JSON (not defaults):

- Only selected categories: `path(fields, 'breadcrumbs.0.title') in ['Windsurf', 'Sails', 'Boards']`
- Only below a price threshold: `num(fields['price']) < 100`
- Exclude one brand: `fields['brand'] != 'Nike'`

JSON must have a top-level array; objects and scalar JSON are rejected. A nonempty URL takes precedence over a file path; HTTP failures do not silently fall back to stale files. Only HTTP(S) URLs are fetched, with no redirect following. Local file paths must identify readable regular files.

## Base-product targets and source options

Canonical mapping targets: `name`, `reference`, `price`, `short_description`, `description`, `ean13`, `weight`, `quantity`, `active`, `manufacturer`, `category_paths`, `images`, `main_image`. These are documented in the source editor, not enforced as an allowlist. New products require a usable name. Existing products retain unmapped name/descriptions/reference/EAN/price/quantity/manufacturer; null or missing weight defaults to 0 and active to true as specified.

`category_paths` accepts a single category name string (for example `fields['category']`), a single path of strings or `{title: ...}` dictionaries, or a list of paths. Example expression for scraper breadcrumbs: `[path(fields, 'breadcrumbs')]`. Hierarchy matters only when categories are auto-created; set overrides on the Categories tab to map source categories to existing shop categories. A nonempty integer-keyed child array identifies multi-path input; otherwise a list is one path. Empty arrays do not determine shape. In multi-path mode, top-level scalars/dictionaries are ignored. Sparse integer keys are accepted; only string segments/titles survive; deeper nesting is not recursively flattened. Order and duplicate paths/segments are retained. Hashes use `sha1(json_encode(normalizedPath))`, without JSON flags. Invalid UTF-8 causes an exception instead of producing a misleading hash.

Sources now store optional `root_category_id`, `id_lang_default` (default 1), and `deactivate_missing`, which controls stale product/combination cleanup after a successful fetch. Category chains default to `PS_ROOT_CATEGORY`; seen paths are recorded without overwriting category overrides. `setOverride` updates a previously discovered row. Category names are matched exactly under their parent using `category_lang.id_shop = category.id_shop_default` across languages; created names/slugs are filled in every installed language. Manufacturer names are matched exactly in `manufacturer.name`.

The importer receives already-mapped values, resolved category IDs and a manufacturer ID; ImportRunner supplies these inputs in commit mode. It preserves other language values when updating the source language. For a new product it also seeds the shop-default language's required name/slug. It explicitly sets `id_category_default` to the first resolved category before saving, then calls `updateCategories($categoryIds)`. A new product without categories is associated with `PS_HOME_CATEGORY`. A stale external-product link raises an error rather than silently creating a replacement.

Mapped prices are stored as given in the tax-exclusive `Product::price` field.

Images are downloaded with HTTP(S) cURL into temporary local files. A supplied main image is tried first (otherwise the first image), and can stand alone. Image creation uses `new Image()`, `add()`, `associateTo()`, `getPathForCreation()`, `ImageManager::resize()` and `ImageType::getImagesTypes('products')`. Original JPEG and configured thumbnail sizes are written; failed image records/files are deleted, temporary downloads are removed, and individual errors are logged without aborting the product. The first successful image becomes cover only when the product has no cover. Existing images/covers are preserved. Every image URL is downloaded on each import, then its content fingerprint is compared with the product's actual image files. Known images create no new Image rows or thumbnails; deleting an image in the admin allows the next import to restore it. Redirects are not followed.

Catalog writes and external links are not a single transaction; a failure after saving a new product but before linking can leave an unlinked product. Resolve calls are intended to run serially: concurrent auto-creation is not protected by unique name constraints. Current shop context controls new catalog objects/media; full multistore orchestration is outside this step.

## Verification boundaries

Unit tests cover the four initial services plus CategoryPathNormalizer with temporary files and representative scraper fixtures. They deliberately do not exercise SourceRepository, AdminPiSourceController, CategoryResolver, ManufacturerResolver, ProductImporter, CategoryMappingRepository or ExternalProductRepository: these require real PrestaShop classes and a database. No fake PrestaShop mocks are used. Module install/uninstall, tab loading, permissions, form rendering, SQL behavior, category/manufacturer creation, product/stock updates, image APIs, and HTTP fetching need integration verification in a deployment environment. No PrestaShop core is included or downloaded.

## Combinations (step 4)

Optional source `variant_mapping` stores `variants_expression`, `attributes` (name/expression rows), and `fields` (target/expression map). An empty variants expression saves NULL and ignores the section. Configured variants require an attribute row and a reference field row; blank rows are ignored, incomplete/duplicate rows rejected. Expressions are not syntax-validated at save time. `VariantFieldMapper::variants($item, $expression)` evaluates the list once and rejects non-list or non-array entries. `mapAttributes($item, $variant, $definitions)` and `mapFields($item, $variant, $mapping)` both expose `fields` and `variant`, with per-entry failures recorded as null plus an error. Attribute values are string-coerced via the expression `str()` helper; null remains null. Callers must reject failed/empty required identity or attribute values before resolving/importing. ImportRunner records these errors in the run log.

`CombinationImporter::import($idSource, $parentExternalId, $variantExternalId, $idProduct, $baseProductPrice, $mappedFields, $attributeIds)` upserts a real `Combination` (`product_attribute`, with shop fields in `product_attribute_shop`). The external key is exactly `parentExternalId . ':' . variantExternalId`, scoped by source, and must fit 191 characters. Callers must choose identifiers without ambiguous colon boundaries (e.g. `a:b` + `c` collides with `a` + `b:c`); the requested composition is not escaped or hashed. Existing links must refer to a valid combination of the supplied parent.

Price is an impact: absolute variant price minus the supplied base product price, including negative impacts. Both prices must use the same tax basis; step 3's unimplemented tax conversion caveat still applies. Missing price preserves an existing impact (new ObjectModel defaults apply). Attribute links use `Combination::setAttributes($attributeIds)`; stock uses `StockAvailable::setQuantity($idProduct, $idCombination, $quantity)`. Combinations have no native `active` property: explicit false forces zero stock. This does not hide the combination or override the shop's allow-out-of-stock-ordering policy. Missing quantity preserves stock. The first imported combination becomes default when none exists; later imports preserve the existing default, even if unavailable. Non-default `default_on` is NULL. `Product::updateDefaultAttribute()` refreshes the parent's cached default.

Attribute groups/values are global and reused by exact BINARY name matches across installed languages (case-sensitive, consistent with step 3). Group creation fills `name` and required `public_name`, uses `group_type = 'select'` and `is_color_group = false`. Values use `ProductAttribute` on PS 8, or legacy `Attribute` on PS 1.7; an ObjectModel check prevents accidentally instantiating PHP 8's built-in Attribute class. Names are filled in every installed language, matching the category resolver.

`ProductImageAttacher` now owns the existing download/Image/thumbnail/cleanup logic and returns the existing or new image ID. Both importers reuse it. Combination images use `Combination::setImages([$idImage])`, associating the image specifically via the combination API rather than only adding a product gallery image. Failures are logged and do not abort the combination; failed attachments are removed by the attacher. Setting a supplied image replaces that combination's image associations. Repeated imports reuse matching gallery images. A changed photo at the same URL is added; the old photo is not deleted. Two near-identical photos may be treated as one. Catalog changes and external linking remain nontransactional; imports should run serially.

Unit tests cover VariantFieldMapper and retain all earlier tests. AttributeResolver, CombinationImporter and ExternalCombinationRepository are deliberately not tested against fake PrestaShop classes: their ObjectModel/SQL/stock/image/default behavior, along with the admin form, requires real PrestaShop integration verification. Combination/setAttributes/setImages and price-impact storage are implemented with high confidence in the legacy 1.7/8 API, but have not been executed here. Version-specific ProductAttribute naming and default-cache/multistore behavior deserve deployment review.

## Read-only preview (step 5)

The saved-source form previews the first item fetched from the source. Save configuration edits first. Unsaved sources cannot be previewed. Requests use the tokenized AdminPiSource URL with `ajax=1&action=preview`, routed explicitly from this controller's custom `postProcess()` to `ajaxProcessPreview()`. The endpoint checks view permission and the admin token, validates the numeric item index, catches failures and terminates with JSON. No importer, stock or image attachment code runs. Results are inserted using DOM textContent, not interpreted as HTML.

PreviewBuilder receives decoded `field_mapping` and `variant_mapping` arrays (or null for no variant mapping); the HTTP caller decodes database JSON. Dependencies are constructor-injected. Results contain `filter`, `base` (values/errors), `categories`, `manufacturer`, `variants` (total/truncated/items), and stage-level `errors`. Mapping/filter failures remain visible; even excluded products are mapped to help diagnose configuration. Five variants are mapped at most; truncated variants are explicitly counted and are not individually evaluated. Null/empty attributes are reported as errors and not sent to the resolver.

All three resolvers accept `bool $commit = true`. Existing commit-mode return values remain unchanged: category IDs, attribute IDs, manufacturer ID/null. With false:

- CategoryResolver returns `{path, id_category, auto_create}` per path, reads overrides without upsertSeen, and walks existing children until the first missing segment. It never creates categories. Missing chains have null leaf ID and auto_create=true.
- AttributeResolver returns nullable `id_attribute_group`/`id_attribute` plus `create_group`/`create_value`. Missing groups or values are reported without creation.
- ManufacturerResolver returns `{name, id_manufacturer, auto_create}`, including a null/no-create result for an absent name. Its native return type is removed to support both shapes on PHP 7.2; PHPDoc documents the contract.

There were no production resolver call sites before PreviewBuilder. ImportRunner uses the original signatures with explicit true; ProductImporter and CombinationImporter still receive resolved IDs and are unchanged. Preview uses false explicitly in every resolver call. Module/catalog writes and seen-path tracking are bypassed; ordinary PrestaShop request/session infrastructure remains outside this module's control.

PreviewBuilder tests use handwritten resolver doubles (asserting commit=false), real expression evaluation/mappers, and no fake PrestaShop bootstrap. Live resolver SQL, controller dispatch/permission handling and browser/Smarty rendering still need integration verification; the AJAX controller is intentionally not unit-tested.

## Daily cron and run logs (step 6)

Each run records how it started. The runs log and live admin status show `cron` for the synchronous cron URL, `cron-background` for that URL with `&background=1`, `admin-background` for an admin button background run, `admin-manual` for its inline fallback, and `cli` for a direct `php bin/import.php all|<id_source>` invocation. Historical runs show `unknown`. Background spawning passes its original trigger as the optional second CLI argument: `php bin/import.php all|<id_source> [cli|cron-background|admin-background]`.

Install the module in PrestaShop 9, configure and preview each source, then copy the full HTTPS cron URL from the source list. It is built using `getModuleLink('productimport', 'cron', ['token' => ...], true)`. The legacy endpoint is `index.php?fc=module&module=productimport&controller=cron&token=SECRET`, implemented by `controllers/front/cron.php` / `ProductImportCronModuleFrontController::postProcess()`. The endpoint outputs JSON and exits before theme rendering. Install generates a 40-hex-character secret using `random_bytes(20)`; uninstall removes it. Token comparison rejects absent/empty tokens and uses `hash_equals`.

Configure your server's scheduler (the module does not edit the server crontab), for example daily at 03:00:

```cron
0 3 * * * /usr/bin/curl --fail --silent --show-error 'PASTE_FULL_CRON_URL_HERE' >> /path/to/productimport-cron.log 2>&1
```

The source list Run import panel runs all active sources by default or one selected source, including an inactive one. The saved source editor also runs that source from its saved configuration; unsaved edits are ignored. A confirmation warns when a single source deactivates missing products. Results show per-source counts and errors, with a link to the runs log. Manual and cron imports share the same lock and execution path.

The endpoint disables PHP's execution time limit, but web-server/proxy timeouts still require deployment configuration for large catalogs. It takes a nonblocking local `flock` in the PrestaShop cache directory to prevent overlapping requests on this server. Concurrent imports across separate hosts without a shared lock filesystem are not serialized. Treat the copied URL as a secret. Inspect the JSON `runs[].status` even when the HTTP request succeeds; per-source failures do not produce a transport error.

Catalog > Product Import runs shows the most recent 100 runs, source names, times, status, counts and error logs. An optional `id_source` URL parameter shows that source's latest 20. This page is read-only.

ImportRunner receives decoded mapping columns, like PreviewBuilder. The cron caller decodes each active source independently; malformed JSON is reported/logged without blocking other sources. A factory wires real dependencies, including a small ImportCatalog boundary for saved base prices and stale catalog changes. No preview code runs during cron; category/attribute/manufacturer resolution uses commit=true.

Existing importers retain their signatures and internal link calls. The runner calls link again after success with the run ID. Repository link methods accept an optional run ID; null preserves the previous marker on an existing row. New unmarked links use NULL. Combination identities remain `parentExternalId:reference`, where reference is the mapped variant reference. The combination price calculation receives the saved product price, not an assumed mapped/default value.

Counts: created/updated count successfully saved and marked base products; skipped counts filtered items; failed counts item failures, variant failures and stale-cleanup failures. A base product can count as updated while one of its variants counts as failed. Mapping field errors are logged but do not themselves count as failed when import otherwise succeeds. Attribute errors or missing variant references reject that variant. Status is completed, completed_with_errors (nonzero failed count), or failed (fetch/start/configuration failure). Run logs are capped at 65,536 bytes in memory and at persistence, preserve UTF-8 boundaries and end with a truncation marker when capped.

After a successful fetch, deactivate_missing follows the requested touched-link semantics: all unmarked products are set inactive and unmarked combinations receive zero stock. This includes previously linked items now filtered out or failing before their link is marked, and every stale link after a valid empty feed. Fresh successful links are left alone. A fetch failure performs no catalog work or stale cleanup. Stale combinations are not hidden and may remain orderable if the shop permits out-of-stock orders. Cleanup does not delete catalog records. Imports remain nontransactional; an unexpected process termination or failure persisting finish() can leave a running log or partially applied catalog changes. runAll isolates source exceptions and continues.

Existing limitations still apply: tax-inclusive prices are not converted, identifier colon boundaries must be unambiguous, and full multistore orchestration is not implemented.

ImportRunner tests use injected handwritten catalog/repository/importer doubles plus real pure mapping/filter components. They cover mixed outcomes, fetch failure, variant isolation, saved-price usage, run markers, stale-only cleanup, logging bounds and source failure isolation. ImportRunRepository, front-controller dispatch, the run-log controller and ImportCatalog's actual PrestaShop writes require live integration verification; no fake PrestaShop core bootstrap was added.

## Verified live against real PrestaShop 9.1.5 (`local-dev/`)

Everything above was built and gated without a live PrestaShop instance.
It has since been installed and exercised end-to-end against a real
PrestaShop 9.1.5 + PHP 8.3 + MySQL 8 stack (`local-dev/`, a throwaway
Docker environment — see that directory's README) — module install/
uninstall, both admin pages (list, add, edit, the dynamic mapping-row and
variant-mapping JS, the category tree widget), save/persist round-trips,
the AJAX preview endpoint against real fixture data, and a full cron run
that created real products with real category/manufacturer resolution.

That pass found and fixed real PS9-specific bugs that unit tests alone
couldn't catch (no PrestaShop core to run them against):

- **`composer.json`'s `symfony/expression-language` constraint left
  `symfony/cache` and `symfony/var-exporter` unconstrained**, so Composer
  resolved them to `8.x`, which requires PHP 8.4.1+ — above this module's
  own declared `>=8.2` floor and above PS9's own `8.1`-`8.4` supported
  range on an 8.1/8.2/8.3 install. Fixed by explicitly constraining both
  to `^6.4 || ^7.0` in `require`. This is exactly the kind of bug that
  only surfaces when `vendor/` actually gets loaded by a real PHP runtime
  different from whichever machine ran `composer install` — worth
  re-checking after any future dependency bump.
- **`AdminController::l()` does not exist in PrestaShop 9** (confirmed via
  `ReflectionClass` against the real core, not assumed) — only
  `ModuleAdminController` extends far enough to matter, and even that
  doesn't have `l()` either; the actual universal translation method,
  present all the way up at `ControllerCore`, is `trans($id, $parameters
  = [], $domain = null, $locale = null)`. `AdminPiSourceController` and
  `AdminPiRunLogController` now extend `ModuleAdminController` (the
  conventionally-correct base for a module's own admin pages) and every
  `$this->l(...)` call was replaced with `$this->trans(...)`.
- **`Tools::link_rewrite()` does not exist**; the real method is
  `Tools::str2url()`. Fixed in `CategoryResolver` and `ProductImporter`.

Not yet exercised live: combinations/attributes end-to-end (tested via
`PreviewBuilder` and unit tests, not yet a real committed import),
`deactivate_missing`, and the tax-inclusive-price TODO path (still
unimplemented as documented above). The install auto-installer also
required the standard post-install `/install*` folder removal and a
`var/cache` ownership fix (`chown -R www-data:www-data`) — both
environment/deployment steps, not module bugs, and already handled by
`local-dev/`'s normal flow once you know to do them.

One more environment note, not a bug: PrestaShop rewrites `config.xml` on
disk after install (reformats it and drops the `ps_versions_compliancy`
element entirely) — harmless at runtime (that element is only read by the
module validator/marketplace before install, not afterward), but don't be
surprised if a live install leaves your working tree's `config.xml`
looking modified; just don't commit that regenerated copy.

**Fixed as of 0.7.0, verified live**: the missing "Add source" button.
`HelperList`'s own toolbar rendering doesn't produce anything on this
controller in PS9's admin theme (confirmed via `document.querySelectorAll`
against the real rendered DOM — no `.panel-heading`/`.toolbar` element at
all, not CSS-hidden), so the list page now renders its own plain "+ Add
source" link instead of relying on it — see "Source configuration tools"
below.

## Source configuration tools (0.7.0)

Upgrade the installed module to 0.7.0 through the module manager before opening Category mappings. `upgrade/upgrade-0.7.0.php` registers the new source-specific hidden admin tab without resetting existing configuration or imported data. Fresh installations register it directly; uninstall removes it.

The source list renders its own Add source button, with an explicit add-permission check and a tokenized `getAdminLink('AdminPiSource') . '&addpi_source'` URL. It does not rely on HelperList toolbar rendering. Each source has a Category mappings link; the edit page has the same link and a Discover categories button.

Discovery requires a saved source and saved category_paths expression. It fetches every item, evaluates only that expression (no import filter or other mappings), normalizes paths, deduplicates by normalized hash in memory, and records each unique path once after the scan. There are no per-item database queries. It creates no categories and preserves existing overrides. The response shows unique paths found in this scan, scanned/failed item counts, up to 20 error examples (500 characters each), and a link to the mapping page. Previously discovered paths remain listed; discovery is additive. Preview remains strictly read-only.

The mapping page shows every discovered path and its current override. Each row has an indented category select and Save mapping button; an empty value restores auto-create. Categories come from one read joining category, category_shop and category_lang for the current shop/admin language, ordered by nleft. Both active and inactive categories are offered. Missing translations use an unnamed label. The page avoids Category::getNestedCategories API/version differences and per-row tree widgets. Edits check the admin token, edit permission, source/path ownership, and selected category availability in the current shop.

The inspect endpoint can inspect an item by its zero-based index. It requires a saved source but no mapping expressions and runs no filter/resolver/importer. FieldInspector returns complete pretty JSON plus a flattened list: depth 4 from the root, first 3 entries per list at every level, maximum 100 rows. Containers at the depth limit become count summaries; a truncated flag indicates any omitted rows. Identifier-style object keys use dots, array indices use brackets, unusual keys use JSON-quoted brackets. Empty arrays are accepted as empty objects at the root because associative JSON decoding loses that distinction. Scalar/nonempty list roots are rejected. Inspection output uses textContent, never HTML interpretation.

New unit tests cover FieldInspector only. Discovery, repository queries, mapping-page rendering/saving, tab upgrades and controller dispatch are intentionally not tested with fake PrestaShop objects.

**Verified live against real PrestaShop 9.1.5, all working**: `php bin/console prestashop:module upgrade productimport` registered the new hidden `AdminPiCategoryMap` tab correctly. The "+ Add source" button renders and links to a working add form. "Inspect sample item" returns correct pretty JSON and a correctly-flattened field list (e.g. `breadcrumbs[0].title` → `"Windsurf"`) for a real saved source. "Discover categories from full JSON" scanned a real 3-item fixture, found 1 unique path, 0 errors, and the "Open category mappings" link took me straight to the new mapping page. Setting an override there (path → an existing "Home" category via the indented `<select>`) saved correctly and the page reflected "→ existing category #2 (— Home #2)" afterward.

## Source editor v2 backend: Test source and manufacturer overrides (0.8.0)

Upgrade the installed module to 0.8.0 before testing saved sources or importing: the upgrade adds pi_manufacturer_mapping without reinstalling or resetting data. No brand-mapping page or new button is included in this step. Existing Inspect and Discover categories buttons remain compatible; Test source is available directly over AJAX.

POST to the tokenized AdminPiSource URL with `ajax=1`, `action=testSource`, raw `json_url` and/or `json_file_path` (URL wins), and `item_index=0`. Omit `id_source` entirely for a never-saved source. An optional positive `id_source` enables discovery using that saved source's category_paths/manufacturer expressions against the **submitted** feed. The saved URL/path is never substituted. Connectivity and discovery fetch/scan the full submitted feed. A nonempty JSON array is required. Unsaved requests require add permission, saved requests require edit permission, and both require the admin token.

Successful response:

```json
{
  "connectivity": {"ok": true, "format": "non-empty JSON array"},
  "item_count": 10,
  "inspection": {"json": "pretty JSON string", "fields": [{"key": "brand", "value": "Acme"}], "truncated": false},
  "categories": {"unique_paths": 2, "items_scanned": 10, "failed_items": 0, "errors": [], "errors_omitted": 0, "mapping_url": "tokenized AdminPiCategoryMap URL"},
  "brands": {"unique_brands": 1, "items_scanned": 10, "failed_items": 0, "errors": [], "errors_omitted": 0, "mapping_url": "tokenized AdminPiManufacturerMap URL"}
}
```

Each discovery section instead returns `{"skipped":"source not saved yet"}` or `{"skipped":"no saved category_paths expression"}` / `{"skipped":"no saved manufacturer expression"}` when inapplicable. Inspection/discovery stage failures return `{"error":"message"}` in that section and do not block the other sections. Fetch, permissions, empty-feed or source-lookup failures return top-level `{"error":"message"}`. Per-item discovery failures increment failed_items and expose up to 20 examples of 500 characters each. No item cap is applied; category and brand scans deduplicate in memory before recording unique entries. Null/blank brands are ignored; nonstring or over-191-character brands count as failures. Discovery does not evaluate the product filter and never creates catalog categories/manufacturers.

ManufacturerResolver now exposes `resolve(?string $name, int $idSource, bool $commit = true)`. It trims names and applies source overrides before exact-name catalog lookup/creation, retaining commit-ID versus preview-object return shapes. `discover(string $name, int $idSource)` only records the trimmed nonblank name. Upserting seen names preserves admin overrides. PreviewBuilder and ImportRunner pass source IDs; ProductImporter still receives an already-resolved manufacturer ID. The brand mapping URL intentionally targets the not-yet-implemented AdminPiManufacturerMap controller (next step).

Repository/real resolver/controller behavior is bootstrap-bound and has not been unit-tested with fake PrestaShop classes. Existing PreviewBuilder/ImportRunner tests only update the resolver-double signatures and assert source IDs. Live AJAX request, upgrade and database behavior remain to be verified on PS9.1.5.

## Brand mapping page and source defaults (0.9.0)

Upgrade the installed module to 0.9.0. The upgrade registers the hidden AdminPiManufacturerMap tab and adds nullable `pi_source.default_id_category` and `default_id_manufacturer`; fresh installs include both. The standalone Brand mappings and Category mappings pages remain available; the source editor provides Categories and Brands tabs. Brand options use `SELECT id_manufacturer, name FROM <prefix>manufacturer ORDER BY name`, without language/shop joins. Posted IDs must be blank or positive integers present in that list; category choices remain limited to the existing current-shop category list. Both pages check permissions, token and source/row ownership, and escape displayed values.

Each mapping page offers a separate 'Default for anything not mapped below' form that updates only its source default via SourceRepository. Empty means NULL. Resolution precedence is explicit row override, then source default, then existing exact-match/auto-create behavior. A null row override inherits the source default; to restore auto-create for all unoverridden entries, clear the source default. Defaults do not create categories/manufacturers, do not affect discovery, and do not assign a manufacturer for blank names or a category when there is no normalized path. Source-edit saves leave these separately managed defaults untouched.

CategoryResolver and ManufacturerResolver now accept an optional trailing `?int $defaultId = null`, after commit. PreviewBuilder and ImportRunner explicitly pass their matching source default; existing return shapes and earlier call signatures remain compatible. Builder/runner doubles assert the supplied IDs, but the DB-bound fallback implementation and admin page are not unit-tested with fake PrestaShop classes.

MySQL 8.0 does **not** support `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` (see https://dev.mysql.com/doc/refman/8.0/en/alter-table.html). The upgrade checks information_schema.COLUMNS in DATABASE() for each exact table/column, then runs `ALTER TABLE <prefix>pi_source ADD COLUMN default_id_category INT UNSIGNED NULL` or the corresponding manufacturer statement only if missing. This makes sequential retries safe without unsupported syntax. Verify the upgrade, hidden-tab permissions, select/save round-trips and preview/cron fallback precedence on the live PS9 instance; they were not executed against it here.

## Source editor v3: product and variant mapping

Test source discovers JSON fields and list candidates. The Product fields tab shows thirteen canonical rows with one source selector each. Choose a discovered field, leave a row unmapped, or choose Custom expression for formulas and the cursor insert control. Saved formulas remain editable and are matched to discovered fields when inspection completes. Custom target fields remain available below the fixed rows.

Each Product fields row also has **Sample values**. Choose a sample size and run it to see the distinct values and counts produced by the row's current expression from the current feed, even before saving. This is a general aid for writing expressions for any field.

The same tab contains optional variants. Select a discovered list of objects or enter a custom list expression, then map variant fields and named attributes from variant item keys, product fields, or custom expressions. At least one attribute and a reference field are required when variants are enabled. The existing `field_mapping_*`, `variant_attribute_*`, `variant_field_*`, and `variants_expression` submission names remain in use. Test configuration evaluates the saved source.


PHP gates do not exercise DOM/Smarty interaction. Live-check unsaved Test source, saved-source discovery, dropdown population, mid-expression insertion/selection replacement, clearing fixed rows and saving/reopening, custom and combination add/remove controls, and the renamed Test configuration response on PS9.1.5. Category and brand mappings are managed in their source editor tabs.

## Background imports

Run the daily import as the web user with a CLI cron entry:

```cron
0 3 * * * /usr/bin/php /path/to/modules/productimport/bin/import.php all
```

The CLI also accepts a source ID, including an inactive source. The admin Run import buttons start a background CLI process and show live run status; if process spawning is unavailable, they run inline. The tokenized cron URL remains synchronous by default. Append `&background=1` to start a background import and receive HTTP 202 with `{"started":true}`; unsupported spawning falls back to the synchronous response. Image downloads and thumbnails are generated inside the background process. Background logs are written to the PrestaShop cache directory as `productimport-run-*.log`.

## Remove existing duplicate images

Run `php bin/dedupe-images.php all` or `php bin/dedupe-images.php <id_source>` from the module directory to preview duplicates on products linked by this module. The default is a dry run showing each product and duplicate IDs. Add `--apply` to delete duplicates, keeping the lowest image ID in each fingerprint group. PrestaShop handles image files, thumbnails and cover changes. Ordinary combination image associations on removed duplicates are re-established on the next import because attachment returns the kept image ID.

## Supplier and price fields (0.11.0)

Upgrade to 0.11.0 before saving sources. The General tab's **Supplier** dropdown tags every imported product from that source with one supplier. Create suppliers under Catalog > Brands & Suppliers. Clearing the dropdown leaves existing product suppliers untouched.

The Product fields tab adds optional `wholesale_price` (your cost price, separate from the retail price customers pay and not shown to customers) and `regular_price`. When both `regular_price` and `price` are mapped and the regular price is higher, the product's base price becomes `regular_price` and an always-on, all-customer specific price overrides it with `price`. A regular price alone becomes the base price. Otherwise the module removes that discount slot. This applies to base products only; combination pricing is unchanged. If you manually create your own always-on discount on an imported product outside this module, the next import may overwrite it.

### XML source feeds

Upgrade to 0.12.0 and choose **Format: XML** on the General tab. Enter the XML feed URL or local file in the existing URL/file fields, then set **XML item path** to the slash-separated path from the document root to each repeating product element. For example:

```xml
<catalog><products>
  <product id="A1"><name>Board</name><categories><category>Water</category><category>Boards</category></categories></product>
  <product id="A2"><name>Sail</name></product>
</products></catalog>
```

Use `products/product` as the item path. The first item exposes flattened field keys `["@attributes"].id`, `name`, `categories.category[0]`, and `categories.category[1]` for mapping. XML sources support Test source, Sample values, filtering, category and brand discovery, and variants just like JSON sources after conversion. Existing sources default to JSON.

`@attributes` and `@value` are synthetic metadata keys invented by this module during XML conversion, not fields in the supplier's XML. For example, `<dobava id="1">Na zalogi</dobava>` becomes `{"@attributes": {"id": "1"}, "@value": "Na zalogi"}`.
