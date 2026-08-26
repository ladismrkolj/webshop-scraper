<?php
/**
 * Turns one CSV feed into catalogue changes.
 *
 * The run is deliberately conservative: it only ever touches products it has
 * imported itself (tracked in csvsync_link), it only writes the parts of a
 * product the source is configured to own, and it refuses to remove anything
 * when the feed looks suspiciously short.
 */
class CsvSyncImporter
{
    /** @var CsvSyncSource */
    private $source;

    /** @var CsvSyncRun */
    private $run;

    /** @var CsvSyncRowMapper */
    private $mapper;

    /** @var string stamped on every link the run touches, so stale rows are simply the older ones */
    private $run_stamp;

    /** @var string[] */
    private $problems = [];

    /** @var int */
    private $problem_count = 0;

    /** @var array|null category mapping table, loaded once per run */
    private $category_map;

    /** @var array values the run met that nobody has mapped yet, as a set */
    private $unmapped_categories = [];

    /** @var bool */
    private $dry_run = false;

    /** @var callable|null progress reporter, for the CLI */
    private $logger;

    const MAX_REPORTED_PROBLEMS = 50;

    public function __construct(CsvSyncSource $source)
    {
        $this->source = $source;
    }

    public function setDryRun($dry_run)
    {
        $this->dry_run = (bool) $dry_run;

        return $this;
    }

    public function setLogger(callable $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @param string $trigger manual|cron
     *
     * @return CsvSyncRun
     */
    public function run($trigger = 'manual')
    {
        $this->run_stamp = date('Y-m-d H:i:s');
        $this->run = new CsvSyncRun();
        $this->run->id_csvsync_source = (int) $this->source->id;
        $this->run->trigger_type = in_array($trigger, ['manual', 'cron'], true) ? $trigger : 'manual';
        $this->run->status = CsvSyncRun::STATUS_RUNNING;
        $this->run->add();

        $reader = new CsvSyncReader($this->source);
        try {
            $this->mapper = new CsvSyncRowMapper($this->source);
            $problems = $this->mapper->validate();
            if ($problems) {
                throw new Exception(implode(' ', $problems));
            }

            $reader->open();
            $this->importRows($reader);
            $this->removeMissing();
            $this->reportUnmappedCategories();

            $this->run->status = $this->problem_count > 0 ? CsvSyncRun::STATUS_PARTIAL : CsvSyncRun::STATUS_SUCCESS;
        } catch (Exception $exception) {
            $this->run->status = CsvSyncRun::STATUS_ERROR;
            $this->addProblem($exception->getMessage());
        }

        $reader->close();
        $this->run->message = $this->buildMessage();
        $this->run->update();

        return $this->run;
    }

    // ------------------------------------------------------------------
    // rows

    private function importRows(CsvSyncReader $reader)
    {
        $seen = [];
        while (($row = $reader->next()) !== null) {
            ++$this->run->rows_read;

            try {
                $mapped = $this->mapper->map($row);
                $key = $this->mapper->key($mapped);
                if ($key === '') {
                    throw new Exception('row has no value for the matching field');
                }
                // A feed that lists one row per variant would otherwise make
                // the same product fight with itself inside a single run.
                if (isset($seen[$key])) {
                    ++$this->run->rows_skipped;
                    continue;
                }
                $seen[$key] = true;

                $this->importRow($key, $mapped);
            } catch (Exception $exception) {
                ++$this->run->rows_skipped;
                $this->addProblem(sprintf('line %d: %s', $reader->getLineNumber(), $exception->getMessage()));
            }

            if ($this->run->rows_read % 200 === 0) {
                $this->run->update();
                $this->log(sprintf(
                    '%d rows: %d created, %d updated, %d unchanged, %d skipped',
                    $this->run->rows_read,
                    $this->run->products_created,
                    $this->run->products_updated,
                    $this->run->products_unchanged,
                    $this->run->rows_skipped
                ));
            }
        }
    }

    /**
     * @param string $key
     * @param array $mapped
     */
    private function importRow($key, array $mapped)
    {
        $link = CsvSyncLink::findByExternalId((int) $this->source->id, $key);
        $product = null;

        if ($link && Validate::isLoadedObject($product = new Product((int) $link->id_product, false, (int) $this->source->id_lang))) {
            // The known case: this feed created it and still owns it.
        } else {
            if ($link) {
                // The product was deleted outside the module. The stale link
                // would collide with the unique (source, external id) key when
                // this row is imported again, so it goes now.
                $link->delete();
                $link = null;
            }
            $product = $this->findExistingProduct($mapped);
        }

        $this->assertCategoriesDecided($mapped);

        $hash = $this->mapper->hash($mapped);
        $is_new = !Validate::isLoadedObject($product);

        if ($is_new && !$this->source->create_missing_products) {
            ++$this->run->rows_skipped;

            return;
        }

        // Nothing in the feed row changed and the product is already linked:
        // this is the common case on a nightly run, so it must cost nothing.
        if (!$is_new && $link && $link->row_hash === $hash) {
            ++$this->run->products_unchanged;
            $this->touchLink($link);

            return;
        }

        if ($this->dry_run) {
            $is_new ? ++$this->run->products_created : ++$this->run->products_updated;

            return;
        }

        if ($is_new) {
            $product = $this->createProduct($mapped);
            ++$this->run->products_created;
        } else {
            $this->updateProduct($product, $mapped);
            ++$this->run->products_updated;
        }

        $this->applyStock($product, $mapped, $is_new);
        $this->applyAssociations($product, $mapped, $is_new);

        $this->saveLink($link, $key, $product, $hash, $mapped);
    }

    // ------------------------------------------------------------------
    // products

    /**
     * @return Product|null
     */
    private function findExistingProduct(array $mapped)
    {
        $field = $this->source->match_by;
        $value = $this->mapper->key($mapped);
        if ($value === '') {
            return null;
        }

        // An external id is meaningless to PrestaShop, so a product that is
        // not linked yet can only be found by a real catalogue field.
        $column = $field === 'external_id' ? 'reference' : $field;
        if ($column === 'name') {
            $sql = new DbQuery();
            $sql->select('p.id_product')
                ->from('product', 'p')
                ->innerJoin('product_lang', 'pl', 'pl.id_product = p.id_product')
                ->where('pl.id_lang = ' . (int) $this->source->id_lang)
                ->where('pl.name = "' . pSQL($value) . '"');
            $id = (int) Db::getInstance()->getValue($sql);
        } else {
            if ($field === 'external_id' && !isset($mapped['product']['reference'])) {
                return null;
            }
            $lookup = $field === 'external_id' ? (string) $mapped['product']['reference'] : $value;
            $sql = new DbQuery();
            $sql->select('id_product')
                ->from('product')
                ->where('`' . bqSQL($column) . '` = "' . pSQL($lookup) . '"');
            $id = (int) Db::getInstance()->getValue($sql);
        }

        if (!$id) {
            return null;
        }
        $product = new Product($id, false, (int) $this->source->id_lang);

        return Validate::isLoadedObject($product) ? $product : null;
    }

    /**
     * @return Product
     *
     * @throws Exception
     */
    private function createProduct(array $mapped)
    {
        $product = new Product();
        $product->id_shop_default = (int) $this->source->id_shop;
        $product->active = (bool) $this->source->activate_new_products;
        $product->id_tax_rules_group = (int) $this->source->id_tax_rules_group;
        $product->id_category_default = (int) $this->source->id_category_default;
        $product->price = 0;
        $product->minimal_quantity = 1;
        $product->redirect_type = '404';

        $this->assignFields($product, $mapped, true);

        if (!$product->add()) {
            throw new Exception('PrestaShop refused to create the product');
        }
        if ((int) $this->source->id_category_default) {
            $product->addToCategories([(int) $this->source->id_category_default]);
        }

        return $product;
    }

    private function updateProduct(Product $product, array $mapped)
    {
        $this->assignFields($product, $mapped, false);
        if (!$product->update()) {
            throw new Exception('PrestaShop refused to update the product');
        }
    }

    /**
     * Copies mapped values onto the product, obeying what the source is
     * allowed to own on an existing product.
     */
    private function assignFields(Product $product, array $mapped, $is_new)
    {
        $write_text = $is_new || $this->source->update_text;
        $write_price = $is_new || $this->source->update_price;

        foreach ($mapped['product'] as $field => $value) {
            $is_price_field = in_array($field, ['price', 'wholesale_price', 'id_tax_rules_group', 'on_sale'], true);
            if ($is_price_field && !$write_price) {
                continue;
            }
            if (!$is_price_field && !$write_text && !$is_new) {
                // Identity fields (reference, EAN...) travel with the text
                // update, because they are just as much "the feed's copy".
                continue;
            }
            if (!property_exists($product, $field)) {
                continue;
            }
            $product->{$field} = $this->castProductField($field, $value);
        }

        if ($write_text) {
            $this->assignLangFields($product, $mapped);
        }

        if ($is_new && !$product->id_tax_rules_group) {
            $product->id_tax_rules_group = (int) $this->source->id_tax_rules_group;
        }
    }

    private function castProductField($field, $value)
    {
        $floats = ['price', 'wholesale_price', 'weight', 'width', 'height', 'depth'];
        $bools = ['active', 'available_for_order', 'show_price', 'on_sale'];

        if (in_array($field, $floats, true)) {
            return (float) CsvSyncTransformer::toNumber($value);
        }
        if (in_array($field, $bools, true)) {
            return CsvSyncTransformer::toBool($value) ? 1 : 0;
        }
        if ($field === 'id_tax_rules_group') {
            return (int) $value;
        }
        if ($field === 'condition') {
            $condition = Tools::strtolower(trim((string) $value));

            return in_array($condition, ['new', 'used', 'refurbished'], true) ? $condition : 'new';
        }
        if (in_array($field, ['reference', 'ean13', 'upc', 'isbn', 'mpn', 'supplier_reference'], true)) {
            return Tools::substr(trim((string) $value), 0, 64);
        }

        return $value;
    }

    /**
     * The feed speaks one language; every other installed language gets the
     * same text so nothing is left blank in the back office.
     */
    private function assignLangFields(Product $product, array $mapped)
    {
        $languages = Language::getLanguages(false);
        foreach ($mapped['lang'] as $field => $value) {
            if (!property_exists($product, $field)) {
                continue;
            }
            $value = (string) $value;
            if ($field === 'name') {
                $value = Tools::substr($value, 0, 128);
            }
            if (in_array($field, ['meta_title', 'meta_description'], true)) {
                $value = Tools::substr(strip_tags($value), 0, 512);
            }

            $per_lang = [];
            foreach ($languages as $language) {
                $per_lang[(int) $language['id_lang']] = $value;
            }
            $product->{$field} = $per_lang;
        }

        // PrestaShop will not save a product whose friendly URL is empty.
        if (empty($mapped['lang']['link_rewrite']) && !empty($mapped['lang']['name'])) {
            $per_lang = [];
            foreach ($languages as $language) {
                $per_lang[(int) $language['id_lang']] = Tools::str2url((string) $mapped['lang']['name']);
            }
            $product->link_rewrite = $per_lang;
        }
    }

    // ------------------------------------------------------------------
    // stock, categories, images, features

    private function applyStock(Product $product, array $mapped, $is_new)
    {
        if (!$is_new && !$this->source->update_stock) {
            return;
        }

        $quantity = null;
        if (isset($mapped['special']['quantity'])) {
            $quantity = (int) round((float) CsvSyncTransformer::toNumber($mapped['special']['quantity']));
        } elseif (isset($mapped['special']['in_stock'])) {
            $quantity = CsvSyncTransformer::toBool($mapped['special']['in_stock'])
                ? (int) Configuration::get('CSVSYNC_DEFAULT_QUANTITY')
                : 0;
        }

        if ($quantity === null) {
            return;
        }

        StockAvailable::setQuantity((int) $product->id, 0, max(0, $quantity), (int) $this->source->id_shop);
    }

    private function applyAssociations(Product $product, array $mapped, $is_new)
    {
        $special = $mapped['special'];

        if (isset($special['manufacturer'])) {
            $id = $this->findOrCreateNamed('Manufacturer', (string) $special['manufacturer']);
            if ($id && (int) $product->id_manufacturer !== $id) {
                $product->id_manufacturer = $id;
                $product->update();
            }
        }

        if (isset($special['supplier'])) {
            $id = $this->findOrCreateNamed('Supplier', (string) $special['supplier']);
            if ($id) {
                $product->id_supplier = $id;
                $product->update();
                if (!ProductSupplier::getIdByProductAndSupplier((int) $product->id, 0, $id)) {
                    $product->addSupplierReference($id, 0, (string) $product->supplier_reference, (float) $product->wholesale_price);
                }
            }
        }

        if (isset($special['categories']) && ($is_new || $this->source->update_categories)) {
            $this->applyCategories($product, (string) $special['categories']);
        }

        if (isset($special['tags']) && ($is_new || $this->source->update_text)) {
            $tags = CsvSyncTransformer::parseAnyList((string) $special['tags']);
            if ($tags) {
                Tag::addTags((int) $this->source->id_lang, (int) $product->id, $tags);
            }
        }

        if ($mapped['features'] && ($is_new || $this->source->update_text)) {
            $this->applyFeatures($product, $mapped['features']);
        }

        if (isset($special['images'])) {
            $this->applyImages($product, (string) $special['images'], $is_new);
        }
    }

    /**
     * Turns a row's category values into shop categories.
     *
     * In "auto" mode the feed's own path is created as needed. In "map" mode
     * only categories someone has explicitly mapped are used, so a feed cannot
     * grow the shop's category tree on its own.
     *
     * @return int[] shop category ids, empty when the row places nowhere
     */
    private function resolveCategories($value)
    {
        $paths = array_filter(array_map('trim', explode('|', (string) $value)), 'strlen');
        if (!$paths) {
            return [];
        }

        if ($this->source->category_mode === CsvSyncSource::CATEGORY_MAP) {
            return $this->resolveMappedCategories($paths);
        }

        $root = (int) Configuration::get('PS_HOME_CATEGORY');
        $ids = [];
        foreach ($paths as $path) {
            $parent = $root;
            foreach (array_filter(array_map('trim', explode('>', $path)), 'strlen') as $name) {
                $parent = $this->findOrCreateCategory($name, $parent);
                $ids[] = $parent;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param string[] $paths
     *
     * @return int[]
     */
    private function resolveMappedCategories(array $paths)
    {
        if ($this->category_map === null) {
            $this->category_map = CsvSyncCategory::getMap((int) $this->source->id);
        }

        $ids = [];
        foreach ($paths as $path) {
            if (!isset($this->category_map[$path])) {
                // A value the last scan never saw: record it so it shows up
                // as new on the mapping screen instead of vanishing silently.
                CsvSyncCategory::record((int) $this->source->id, [$path => 1]);
                $this->category_map[$path] = ['id_category' => 0, 'status' => CsvSyncCategory::STATUS_NEW];
                $this->unmapped_categories[$path] = true;
            }
            $entry = $this->category_map[$path];
            if ($entry['status'] === CsvSyncCategory::STATUS_MAPPED && $entry['id_category']) {
                $ids[] = (int) $entry['id_category'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether a row may be imported at all given its categories.
     *
     * @throws Exception when the source would rather wait for a decision
     */
    private function assertCategoriesDecided(array $mapped)
    {
        if ($this->source->category_mode !== CsvSyncSource::CATEGORY_MAP
            || $this->source->unmapped_category_action !== CsvSyncSource::UNMAPPED_SKIP_PRODUCT
        ) {
            return;
        }
        if (!isset($mapped['special']['categories'])) {
            return;
        }
        if (!$this->resolveCategories($mapped['special']['categories'])) {
            throw new Exception('category is not mapped yet');
        }
    }

    private function applyCategories(Product $product, $value)
    {
        $ids = $this->resolveCategories($value);
        if (!$ids) {
            // Nothing resolved. Leaving the product where it is beats moving
            // it somewhere arbitrary, so only the default-category setting acts.
            if ($this->source->category_mode === CsvSyncSource::CATEGORY_MAP
                && $this->source->unmapped_category_action === CsvSyncSource::UNMAPPED_DEFAULT
                && (int) $this->source->id_category_default
            ) {
                $ids = [(int) $this->source->id_category_default];
            } else {
                return;
            }
        }

        $product->deleteCategories();
        $product->addToCategories($ids);
        $product->id_category_default = (int) end($ids);
        $product->update();
    }

    /**
     * @return int
     */
    private function findOrCreateCategory($name, $id_parent)
    {
        $sql = new DbQuery();
        $sql->select('c.id_category')
            ->from('category', 'c')
            ->innerJoin('category_lang', 'cl', 'cl.id_category = c.id_category')
            ->where('cl.id_lang = ' . (int) $this->source->id_lang)
            ->where('cl.name = "' . pSQL($name) . '"')
            ->where('c.id_parent = ' . (int) $id_parent);
        $id = (int) Db::getInstance()->getValue($sql);
        if ($id) {
            return $id;
        }

        $category = new Category();
        $category->id_parent = (int) $id_parent;
        $category->active = true;
        foreach (Language::getLanguages(false) as $language) {
            $category->name[(int) $language['id_lang']] = Tools::substr($name, 0, 128);
            $category->link_rewrite[(int) $language['id_lang']] = Tools::str2url($name);
        }
        if (!$category->add()) {
            $this->addProblem(sprintf('could not create category "%s"', $name));

            return 0;
        }

        return (int) $category->id;
    }

    /**
     * @param string $class Manufacturer or Supplier
     *
     * @return int
     */
    private function findOrCreateNamed($class, $name)
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $id = (int) call_user_func([$class, 'getIdByName'], $name);
        if ($id) {
            return $id;
        }

        /** @var ObjectModel $object */
        $object = new $class();
        $object->name = Tools::substr($name, 0, 64);
        $object->active = true;
        if (property_exists($object, 'link_rewrite')) {
            foreach (Language::getLanguages(false) as $language) {
                $object->link_rewrite[(int) $language['id_lang']] = Tools::str2url($name);
            }
        }
        if (!$object->add()) {
            $this->addProblem(sprintf('could not create %s "%s"', $class, $name));

            return 0;
        }

        return (int) $object->id;
    }

    private function applyFeatures(Product $product, array $features)
    {
        foreach ($features as $feature_name => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $id_feature = (int) Feature::addFeatureImport($feature_name);
            if (!$id_feature) {
                continue;
            }
            $id_value = (int) FeatureValue::addFeatureValueImport($id_feature, $value, (int) $product->id, (int) $this->source->id_lang);
            if ($id_value) {
                Product::addFeatureProductImport((int) $product->id, $id_feature, $id_value);
            }
        }
    }

    /**
     * Images are the slowest part of an import by a wide margin, so they are
     * fetched only for new products, or when the feed's URL list actually
     * changed and the source is allowed to refresh them.
     */
    private function applyImages(Product $product, $value, $is_new)
    {
        if (!$is_new && !$this->source->update_images) {
            return;
        }

        $urls = array_map(
            [CsvSyncTransformer::class, 'absolutise'],
            CsvSyncTransformer::parseAnyList($value)
        );
        $urls = array_values(array_filter($urls, function ($url) {
            return (bool) preg_match('#^https?://#i', $url);
        }));
        if (!$urls) {
            return;
        }

        $hash = sha1(implode('|', $urls));
        $link = CsvSyncLink::findByProduct((int) $this->source->id, (int) $product->id);
        if (!$is_new && $link && $link->image_hash === $hash) {
            return;
        }
        $this->pending_image_hash = $hash;

        if (!$is_new) {
            foreach ($product->getImages((int) $this->source->id_lang) as $existing) {
                $image = new Image((int) $existing['id_image']);
                $image->delete();
            }
        }

        $limit = (int) Configuration::get('CSVSYNC_MAX_IMAGES');
        $position = 1;
        foreach (array_slice($urls, 0, $limit > 0 ? $limit : 10) as $url) {
            if (!$this->downloadImage($product, $url, $position === 1, $position)) {
                $this->addProblem(sprintf('image failed for product %d: %s', (int) $product->id, $url));
                continue;
            }
            ++$position;
        }
    }

    /** @var string|null image hash to store with the link once the row succeeds */
    private $pending_image_hash;

    /**
     * @return bool
     */
    private function downloadImage(Product $product, $url, $is_cover, $position)
    {
        $image = new Image();
        $image->id_product = (int) $product->id;
        $image->position = (int) $position;
        $image->cover = (bool) $is_cover;
        foreach (Language::getLanguages(false) as $language) {
            $image->legend[(int) $language['id_lang']] = Tools::substr((string) $product->name, 0, 128);
        }
        if (!$image->add()) {
            return false;
        }

        $temp = tempnam(_PS_TMP_IMG_DIR_, 'csvsync');
        $ok = false;
        if ($temp && Tools::copy($url, $temp) && filesize($temp) > 0) {
            $ok = ImageManager::resize($temp, $image->getPathForCreation() . '.jpg');
            if ($ok) {
                foreach (ImageType::getImagesTypes('products') as $type) {
                    ImageManager::resize(
                        $temp,
                        $image->getPathForCreation() . '-' . $type['name'] . '.jpg',
                        (int) $type['width'],
                        (int) $type['height']
                    );
                }
            }
        }
        if ($temp && file_exists($temp)) {
            @unlink($temp);
        }

        if (!$ok) {
            $image->delete();

            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // links and removals

    private function saveLink($link, $key, Product $product, $hash, array $mapped)
    {
        if (!$link) {
            $link = CsvSyncLink::findByProduct((int) $this->source->id, (int) $product->id) ?: new CsvSyncLink();
        }
        $link->id_csvsync_source = (int) $this->source->id;
        $link->external_id = Tools::substr($key, 0, 255);
        $link->id_product = (int) $product->id;
        $link->row_hash = $hash;
        if ($this->pending_image_hash !== null) {
            $link->image_hash = $this->pending_image_hash;
            $this->pending_image_hash = null;
        }
        $link->date_seen = $this->run_stamp;

        $link->id ? $link->update() : $link->add();
    }

    private function touchLink(CsvSyncLink $link)
    {
        $link->date_seen = $this->run_stamp;
        Db::getInstance()->update(
            'csvsync_link',
            ['date_seen' => pSQL($this->run_stamp)],
            'id_csvsync_link = ' . (int) $link->id
        );
    }

    /**
     * Products this feed brought in that the feed no longer lists.
     */
    private function removeMissing()
    {
        if ($this->source->missing_action === CsvSyncSource::MISSING_NOTHING || $this->dry_run) {
            return;
        }
        if ($this->run->status === CsvSyncRun::STATUS_ERROR) {
            return;
        }

        $stale = CsvSyncLink::getStale((int) $this->source->id, $this->run_stamp);
        if (!$stale) {
            return;
        }

        // A scrape that half-failed looks exactly like a shop that discontinued
        // half its catalogue, and only one of those should empty the store.
        $total = CsvSyncLink::countForSource((int) $this->source->id);
        $percent = $total > 0 ? (count($stale) / $total) * 100 : 0;
        if ($this->source->missing_max_percent > 0 && $percent > (int) $this->source->missing_max_percent) {
            $this->addProblem(sprintf(
                '%d of %d products (%.1f%%) are missing from the feed, over the %d%% safety limit — nothing was removed. '
                . 'Check the feed, then raise the limit if the drop is real.',
                count($stale),
                $total,
                $percent,
                (int) $this->source->missing_max_percent
            ));

            return;
        }

        foreach ($stale as $row) {
            $product = new Product((int) $row['id_product']);
            if (!Validate::isLoadedObject($product)) {
                Db::getInstance()->delete('csvsync_link', 'id_csvsync_link = ' . (int) $row['id_csvsync_link']);
                continue;
            }

            switch ($this->source->missing_action) {
                case CsvSyncSource::MISSING_DISABLE:
                    $product->active = false;
                    $product->update();
                    break;
                case CsvSyncSource::MISSING_OUT_OF_STOCK:
                    StockAvailable::setQuantity((int) $product->id, 0, 0, (int) $this->source->id_shop);
                    break;
                case CsvSyncSource::MISSING_DELETE:
                    $product->delete();
                    Db::getInstance()->delete('csvsync_link', 'id_csvsync_link = ' . (int) $row['id_csvsync_link']);
                    break;
            }
            ++$this->run->products_removed;
        }
    }

    // ------------------------------------------------------------------
    // reporting

    /**
     * A feed that grew new categories must not do so quietly: the run stays
     * green-ish but says what is waiting for a decision.
     */
    private function reportUnmappedCategories()
    {
        if (!$this->unmapped_categories) {
            return;
        }
        $values = array_keys($this->unmapped_categories);
        sort($values);
        $this->addProblem(sprintf(
            '%d new category value(s) are not mapped yet: %s. Map them on the source\'s Categories tab.',
            count($values),
            implode(', ', array_slice($values, 0, 10)) . (count($values) > 10 ? ', ...' : '')
        ));
    }

    private function addProblem($message)
    {
        ++$this->problem_count;
        if (count($this->problems) < self::MAX_REPORTED_PROBLEMS) {
            $this->problems[] = $message;
        }
        $this->log($message);
    }

    private function buildMessage()
    {
        if (!$this->problem_count) {
            return '';
        }
        $message = implode("\n", $this->problems);
        if ($this->problem_count > count($this->problems)) {
            $message .= sprintf("\n... and %d more", $this->problem_count - count($this->problems));
        }

        return $message;
    }

    private function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
