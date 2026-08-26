<?php
/**
 * Sources: the list of feeds, the form behind each one, and the three things
 * you do to a feed — map its columns, preview what it would change, import it.
 */
class AdminCsvSyncSourcesController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'csvsync_source';
        $this->className = 'CsvSyncSource';
        $this->identifier = 'id_csvsync_source';
        $this->lang = false;
        $this->_defaultOrderBy = 'name';

        parent::__construct();

        $this->fields_list = [
            'id_csvsync_source' => ['title' => $this->trans('ID', [], 'Admin.Global'), 'align' => 'text-center', 'class' => 'fixed-width-xs'],
            'name' => ['title' => $this->trans('Name', [], 'Admin.Global')],
            'location' => ['title' => $this->trans('CSV location', [], 'Modules.Csvproductsync.Admin')],
            'match_by' => ['title' => $this->trans('Matched by', [], 'Modules.Csvproductsync.Admin')],
            'mappings' => [
                'title' => $this->trans('Mapped fields', [], 'Modules.Csvproductsync.Admin'),
                'align' => 'text-center',
                'search' => false,
                'orderby' => false,
                'havingFilter' => false,
            ],
            'products' => [
                'title' => $this->trans('Products', [], 'Modules.Csvproductsync.Admin'),
                'align' => 'text-center',
                'search' => false,
                'orderby' => false,
            ],
            'last_run' => [
                'title' => $this->trans('Last import', [], 'Modules.Csvproductsync.Admin'),
                'search' => false,
                'orderby' => false,
            ],
            'active' => [
                'title' => $this->trans('Enabled', [], 'Admin.Global'),
                'active' => 'status',
                'type' => 'bool',
                'align' => 'text-center',
                'class' => 'fixed-width-sm',
            ],
        ];

        $this->_select = '
            (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'csvsync_mapping` m WHERE m.id_csvsync_source = a.id_csvsync_source) AS mappings,
            (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'csvsync_link` l WHERE l.id_csvsync_source = a.id_csvsync_source) AS products';

        $this->addRowAction('mapping');
        $this->addRowAction('categories');
        $this->addRowAction('preview');
        $this->addRowAction('run');
        $this->addRowAction('edit');
        $this->addRowAction('delete');

        $this->bulk_actions = [
            'delete' => [
                'text' => $this->trans('Delete selected', [], 'Admin.Actions'),
                'confirm' => $this->trans('Delete selected sources? Imported products stay in the catalogue.', [], 'Modules.Csvproductsync.Admin'),
                'icon' => 'icon-trash',
            ],
        ];
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addCSS(_MODULE_DIR_ . 'csvproductsync/views/css/admin.css');
    }

    public function displayMappingLink($token, $id)
    {
        return $this->linkButton('mapping', $id, 'icon-random', $this->trans('Mapping', [], 'Modules.Csvproductsync.Admin'));
    }

    public function displayCategoriesLink($token, $id)
    {
        $counts = CsvSyncCategory::countByStatus((int) $id);
        $label = $this->trans('Categories', [], 'Modules.Csvproductsync.Admin');
        if ($counts[CsvSyncCategory::STATUS_NEW] > 0) {
            $label .= ' (' . (int) $counts[CsvSyncCategory::STATUS_NEW] . ')';
        }

        return $this->linkButton('categories', $id, 'icon-sitemap', $label);
    }

    public function displayPreviewLink($token, $id)
    {
        return $this->linkButton('preview', $id, 'icon-eye-open', $this->trans('Preview', [], 'Modules.Csvproductsync.Admin'));
    }

    public function displayRunLink($token, $id)
    {
        return $this->linkButton('run', $id, 'icon-play', $this->trans('Import now', [], 'Modules.Csvproductsync.Admin'));
    }

    private function linkButton($action, $id, $icon, $label)
    {
        $url = self::$currentIndex . '&' . $this->identifier . '=' . (int) $id
            . '&csvsync_action=' . $action . '&token=' . $this->token;

        return '<a class="btn btn-default" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="' . $icon . '"></i> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    // ------------------------------------------------------------------
    // source form

    public function renderForm()
    {
        $categories = [['id' => 0, 'name' => $this->trans('— none —', [], 'Modules.Csvproductsync.Admin')]];
        foreach (Category::getCategories((int) $this->context->language->id, true, false) as $category) {
            $categories[] = ['id' => (int) $category['id_category'], 'name' => $category['name']];
        }

        $tax_groups = [['id' => 0, 'name' => $this->trans('No tax', [], 'Modules.Csvproductsync.Admin')]];
        foreach (TaxRulesGroup::getTaxRulesGroups(true) as $group) {
            $tax_groups[] = ['id' => (int) $group['id_tax_rules_group'], 'name' => $group['name']];
        }

        $match_options = [];
        foreach (CsvSyncFields::matchableFields() as $field) {
            $match_options[] = ['id' => $field, 'name' => CsvSyncFields::label($field)];
        }

        $languages = [];
        foreach (Language::getLanguages(false) as $language) {
            $languages[] = ['id' => (int) $language['id_lang'], 'name' => $language['name']];
        }

        $shops = [];
        foreach (Shop::getShops(true) as $shop) {
            $shops[] = ['id' => (int) $shop['id_shop'], 'name' => $shop['name']];
        }

        $this->fields_form = [
            'legend' => [
                'title' => $this->trans('CSV source', [], 'Modules.Csvproductsync.Admin'),
                'icon' => 'icon-cogs',
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->trans('Name', [], 'Admin.Global'),
                    'name' => 'name',
                    'required' => true,
                    'hint' => $this->trans('How you will recognise this feed, e.g. "recharge.si nightly".', [], 'Modules.Csvproductsync.Admin'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->trans('CSV location', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'location',
                    'required' => true,
                    'hint' => $this->trans('An https:// URL, or a path on this server. A relative path is taken from the shop root, e.g. var/csv/recharge_si.csv.', [], 'Modules.Csvproductsync.Admin'),
                ],
                [
                    'type' => 'switch',
                    'label' => $this->trans('Enabled', [], 'Admin.Global'),
                    'name' => 'active',
                    'hint' => $this->trans('Only enabled sources are imported by the cron job.', [], 'Modules.Csvproductsync.Admin'),
                    'values' => $this->switchValues('active'),
                ],

                ['type' => 'html', 'name' => 'sep_format', 'html_content' => $this->sectionTitle($this->trans('File format', [], 'Modules.Csvproductsync.Admin'))],
                ['type' => 'text', 'label' => $this->trans('Delimiter', [], 'Modules.Csvproductsync.Admin'), 'name' => 'delimiter', 'class' => 'fixed-width-xs'],
                ['type' => 'text', 'label' => $this->trans('Enclosure', [], 'Modules.Csvproductsync.Admin'), 'name' => 'enclosure', 'class' => 'fixed-width-xs'],
                ['type' => 'text', 'label' => $this->trans('Encoding', [], 'Modules.Csvproductsync.Admin'), 'name' => 'encoding', 'class' => 'fixed-width-sm'],
                [
                    'type' => 'switch',
                    'label' => $this->trans('First row holds column names', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'has_header',
                    'values' => $this->switchValues('has_header'),
                ],

                ['type' => 'html', 'name' => 'sep_match', 'html_content' => $this->sectionTitle($this->trans('Matching products', [], 'Modules.Csvproductsync.Admin'))],
                [
                    'type' => 'select',
                    'label' => $this->trans('Match products by', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'match_by',
                    'options' => ['query' => $match_options, 'id' => 'id', 'name' => 'name'],
                    'hint' => $this->trans('Which mapped field identifies a product across runs. The feed\'s own product id is the most stable choice.', [], 'Modules.Csvproductsync.Admin'),
                ],
                [
                    'type' => 'select',
                    'label' => $this->trans('Language', [], 'Admin.Global'),
                    'name' => 'id_lang',
                    'options' => ['query' => $languages, 'id' => 'id', 'name' => 'name'],
                ],
                [
                    'type' => 'select',
                    'label' => $this->trans('Shop', [], 'Admin.Global'),
                    'name' => 'id_shop',
                    'options' => ['query' => $shops, 'id' => 'id', 'name' => 'name'],
                ],

                ['type' => 'html', 'name' => 'sep_writes', 'html_content' => $this->sectionTitle($this->trans('What an import may change', [], 'Modules.Csvproductsync.Admin'))],
                [
                    'type' => 'switch',
                    'label' => $this->trans('Create products missing from the shop', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'create_missing_products',
                    'values' => $this->switchValues('create_missing_products'),
                ],
                [
                    'type' => 'switch',
                    'label' => $this->trans('New products start enabled', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'activate_new_products',
                    'hint' => $this->trans('Off is the safe default: new products land disabled and wait for you to look at them.', [], 'Modules.Csvproductsync.Admin'),
                    'values' => $this->switchValues('activate_new_products'),
                ],
                ['type' => 'switch', 'label' => $this->trans('Update prices', [], 'Modules.Csvproductsync.Admin'), 'name' => 'update_price', 'values' => $this->switchValues('update_price')],
                ['type' => 'switch', 'label' => $this->trans('Update stock', [], 'Modules.Csvproductsync.Admin'), 'name' => 'update_stock', 'values' => $this->switchValues('update_stock')],
                [
                    'type' => 'switch',
                    'label' => $this->trans('Update names, descriptions and identifiers', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'update_text',
                    'hint' => $this->trans('Leave off if you edit product texts by hand — an import would otherwise overwrite them every night.', [], 'Modules.Csvproductsync.Admin'),
                    'values' => $this->switchValues('update_text'),
                ],
                [
                    'type' => 'switch',
                    'label' => $this->trans('Refresh images when the feed changes them', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'update_images',
                    'hint' => $this->trans('Slow. Images are re-downloaded only when the feed\'s URL list actually changed.', [], 'Modules.Csvproductsync.Admin'),
                    'values' => $this->switchValues('update_images'),
                ],
                ['type' => 'switch', 'label' => $this->trans('Update categories', [], 'Modules.Csvproductsync.Admin'), 'name' => 'update_categories', 'values' => $this->switchValues('update_categories')],

                ['type' => 'html', 'name' => 'sep_categories', 'html_content' => $this->sectionTitle($this->trans('Categories', [], 'Modules.Csvproductsync.Admin'))],
                [
                    'type' => 'radio',
                    'label' => $this->trans('Category handling', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'category_mode',
                    'values' => [
                        ['id' => 'category_mode_map', 'value' => CsvSyncSource::CATEGORY_MAP, 'label' => $this->trans('Use the category mapping table (recommended)', [], 'Modules.Csvproductsync.Admin')],
                        ['id' => 'category_mode_auto', 'value' => CsvSyncSource::CATEGORY_AUTO, 'label' => $this->trans('Recreate the feed\'s own category path in the shop', [], 'Modules.Csvproductsync.Admin')],
                    ],
                    'hint' => $this->trans('Mapping keeps your category tree yours; the automatic mode lets the feed grow it.', [], 'Modules.Csvproductsync.Admin'),
                ],
                [
                    'type' => 'radio',
                    'label' => $this->trans('Categories nobody has mapped yet', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'unmapped_category_action',
                    'values' => [
                        ['id' => 'unmapped_default', 'value' => CsvSyncSource::UNMAPPED_DEFAULT, 'label' => $this->trans('Put the product in the default category below', [], 'Modules.Csvproductsync.Admin')],
                        ['id' => 'unmapped_skip_category', 'value' => CsvSyncSource::UNMAPPED_SKIP_CATEGORY, 'label' => $this->trans('Import the product, leave its categories alone', [], 'Modules.Csvproductsync.Admin')],
                        ['id' => 'unmapped_skip_product', 'value' => CsvSyncSource::UNMAPPED_SKIP_PRODUCT, 'label' => $this->trans('Skip the product until its category is mapped', [], 'Modules.Csvproductsync.Admin')],
                    ],
                ],
                [
                    'type' => 'select',
                    'label' => $this->trans('Default category', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'id_category_default',
                    'options' => ['query' => $categories, 'id' => 'id', 'name' => 'name'],
                ],

                ['type' => 'html', 'name' => 'sep_price', 'html_content' => $this->sectionTitle($this->trans('Price rules', [], 'Modules.Csvproductsync.Admin'))],
                [
                    'type' => 'text',
                    'label' => $this->trans('Price multiplier', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'price_multiplier',
                    'class' => 'fixed-width-sm',
                    'hint' => $this->trans('1 imports the feed price as-is; 1.25 adds a 25% markup.', [], 'Modules.Csvproductsync.Admin'),
                ],
                [
                    'type' => 'switch',
                    'label' => $this->trans('Feed prices include tax', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'price_tax_included',
                    'hint' => $this->trans('Scraped shop prices usually do. PrestaShop stores prices without tax, so they are converted using the tax group below.', [], 'Modules.Csvproductsync.Admin'),
                    'values' => $this->switchValues('price_tax_included'),
                ],
                [
                    'type' => 'select',
                    'label' => $this->trans('Tax rule group', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'id_tax_rules_group',
                    'options' => ['query' => $tax_groups, 'id' => 'id', 'name' => 'name'],
                ],
                ['type' => 'text', 'label' => $this->trans('Round prices to', [], 'Modules.Csvproductsync.Admin'), 'name' => 'price_round', 'class' => 'fixed-width-xs', 'suffix' => $this->trans('decimals', [], 'Modules.Csvproductsync.Admin')],

                ['type' => 'html', 'name' => 'sep_missing', 'html_content' => $this->sectionTitle($this->trans('Products that disappear from the feed', [], 'Modules.Csvproductsync.Admin'))],
                [
                    'type' => 'radio',
                    'label' => $this->trans('When a product is no longer in the CSV', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'missing_action',
                    'values' => [
                        ['id' => 'missing_disable', 'value' => CsvSyncSource::MISSING_DISABLE, 'label' => $this->trans('Disable it (recommended)', [], 'Modules.Csvproductsync.Admin')],
                        ['id' => 'missing_stock', 'value' => CsvSyncSource::MISSING_OUT_OF_STOCK, 'label' => $this->trans('Set its stock to zero', [], 'Modules.Csvproductsync.Admin')],
                        ['id' => 'missing_delete', 'value' => CsvSyncSource::MISSING_DELETE, 'label' => $this->trans('Delete it', [], 'Modules.Csvproductsync.Admin')],
                        ['id' => 'missing_nothing', 'value' => CsvSyncSource::MISSING_NOTHING, 'label' => $this->trans('Leave it alone', [], 'Modules.Csvproductsync.Admin')],
                    ],
                    'hint' => $this->trans('Only products this source imported are ever touched.', [], 'Modules.Csvproductsync.Admin'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->trans('Safety limit', [], 'Modules.Csvproductsync.Admin'),
                    'name' => 'missing_max_percent',
                    'class' => 'fixed-width-xs',
                    'suffix' => '%',
                    'hint' => $this->trans('If more than this share of the source\'s products go missing at once, nothing is removed and the run reports it. A half-failed scrape looks exactly like a closing-down sale; this is what tells them apart. 0 disables the check.', [], 'Modules.Csvproductsync.Admin'),
                ],
            ],
            'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
        ];

        return parent::renderForm();
    }

    /**
     * Defaults for a brand new source, so the form is not a wall of blanks.
     */
    public function getFieldsValue($obj)
    {
        $values = parent::getFieldsValue($obj);
        if (Validate::isLoadedObject($obj)) {
            return $values;
        }

        return array_merge([
            'delimiter' => ',',
            'enclosure' => '"',
            'encoding' => 'UTF-8',
            'has_header' => 1,
            'id_lang' => (int) $this->context->language->id,
            'id_shop' => (int) $this->context->shop->id,
            'match_by' => 'external_id',
            'create_missing_products' => 1,
            'activate_new_products' => 0,
            'update_price' => 1,
            'update_stock' => 1,
            'category_mode' => CsvSyncSource::CATEGORY_MAP,
            'unmapped_category_action' => CsvSyncSource::UNMAPPED_DEFAULT,
            'missing_action' => CsvSyncSource::MISSING_DISABLE,
            'missing_max_percent' => 30,
            'price_multiplier' => 1,
            'price_round' => 2,
            'active' => 1,
        ], array_filter($values, function ($value) {
            // Keep whatever the failed submission put back in the form.
            return $value !== null && $value !== '';
        }));
    }

    private function switchValues($name)
    {
        return [
            ['id' => $name . '_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
            ['id' => $name . '_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
        ];
    }

    private function sectionTitle($title)
    {
        return '<hr><h4 class="csvsync-section">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h4>';
    }

    // ------------------------------------------------------------------
    // routing

    public function initContent()
    {
        $action = Tools::getValue('csvsync_action');
        $id_source = (int) Tools::getValue($this->identifier);

        if (!$action || !$id_source) {
            parent::initContent();

            return;
        }

        $source = new CsvSyncSource($id_source);
        if (!Validate::isLoadedObject($source)) {
            $this->errors[] = $this->trans('Unknown source.', [], 'Modules.Csvproductsync.Admin');
            parent::initContent();

            return;
        }

        $this->initTabModuleList();
        $this->initToolbar();
        $this->initPageHeaderToolbar();

        switch ($action) {
            case 'mapping':
                $this->content = $this->renderMapping($source);
                break;
            case 'preview':
                $this->content = $this->renderPreview($source);
                break;
            case 'categories':
                Tools::redirectAdmin($this->context->link->getAdminLink('AdminCsvSyncCategories')
                    . '&id_csvsync_source=' . (int) $source->id);
                break;
            default:
                parent::initContent();

                return;
        }

        $this->context->smarty->assign([
            'content' => $this->content,
            'show_page_header_toolbar' => $this->show_page_header_toolbar,
            'page_header_toolbar_title' => $this->page_header_toolbar_title,
            'page_header_toolbar_btn' => $this->page_header_toolbar_btn,
        ]);
    }

    public function postProcess()
    {
        $action = Tools::getValue('csvsync_action');
        $id_source = (int) Tools::getValue($this->identifier);

        if ($action === 'run' && $id_source) {
            $this->processRunImport(new CsvSyncSource($id_source));

            return true;
        }
        if (Tools::isSubmit('submitCsvSyncMapping') && $id_source) {
            $this->processSaveMapping(new CsvSyncSource($id_source));

            return true;
        }

        return parent::postProcess();
    }

    /**
     * A manual import runs inside the request, so a huge feed is better left
     * to cron; the screen says so rather than silently timing out.
     */
    private function processRunImport(CsvSyncSource $source)
    {
        if (!Validate::isLoadedObject($source)) {
            $this->errors[] = $this->trans('Unknown source.', [], 'Modules.Csvproductsync.Admin');

            return;
        }
        if (CsvSyncRun::hasRunningRun((int) $source->id)) {
            $this->errors[] = $this->trans('An import of this source is already running.', [], 'Modules.Csvproductsync.Admin');

            return;
        }

        @set_time_limit(0);
        $importer = new CsvSyncImporter($source);
        $run = $importer->run('manual');

        $summary = $this->trans(
            '%rows% rows: %created% created, %updated% updated, %unchanged% unchanged, %removed% removed, %skipped% skipped.',
            [
                '%rows%' => (int) $run->rows_read,
                '%created%' => (int) $run->products_created,
                '%updated%' => (int) $run->products_updated,
                '%unchanged%' => (int) $run->products_unchanged,
                '%removed%' => (int) $run->products_removed,
                '%skipped%' => (int) $run->rows_skipped,
            ],
            'Modules.Csvproductsync.Admin'
        );

        if ($run->status === CsvSyncRun::STATUS_ERROR) {
            $this->errors[] = $summary . ' ' . $run->message;
        } elseif ($run->message) {
            $this->warnings[] = $summary . ' ' . nl2br(htmlspecialchars($run->message, ENT_QUOTES, 'UTF-8'));
        } else {
            $this->confirmations[] = $summary;
        }
    }

    // ------------------------------------------------------------------
    // mapping screen

    private function renderMapping(CsvSyncSource $source)
    {
        $columns = [];
        $samples = [];
        try {
            $peek = CsvSyncReader::peek($source, 2);
            $columns = $peek['header'];
            foreach ($peek['rows'] as $row) {
                foreach ($row as $column => $value) {
                    if (!isset($samples[$column]) || $samples[$column] === '') {
                        $samples[$column] = Tools::substr((string) $value, 0, 120);
                    }
                }
            }
        } catch (Exception $exception) {
            $this->errors[] = $this->trans('Could not read the CSV: %error%', ['%error%' => $exception->getMessage()], 'Modules.Csvproductsync.Admin');
        }

        // Whatever is already mapped wins, even for a column the feed dropped:
        // losing a mapping because a scrape hiccuped would be infuriating.
        $existing = [];
        foreach ($source->getMappings() as $mapping) {
            $existing[$mapping->csv_column] = $mapping;
            if (!in_array($mapping->csv_column, $columns, true)) {
                $columns[] = $mapping->csv_column;
            }
        }

        $rows = [];
        foreach ($columns as $column) {
            $mapping = isset($existing[$column]) ? $existing[$column] : null;
            $field = $mapping ? $mapping->ps_field : '';
            $rows[] = [
                'column' => $column,
                'sample' => isset($samples[$column]) ? $samples[$column] : '',
                'ps_field' => CsvSyncFields::isFeature($field) ? '__feature__' : $field,
                'feature_name' => CsvSyncFields::isFeature($field) ? CsvSyncFields::featureName($field) : '',
                'transform' => $mapping ? $mapping->transform : 'none',
                'default_value' => $mapping ? $mapping->default_value : '',
                'missing' => !isset($samples[$column]) && $mapping !== null,
            ];
        }

        $this->context->smarty->assign([
            'source' => $source,
            'rows' => $rows,
            'field_groups' => CsvSyncFields::grouped(),
            'field_hints' => CsvSyncFields::all(),
            'transforms' => CsvSyncTransformer::all(),
            'suggestions' => $this->suggestMapping($columns, $existing),
            'form_action' => self::$currentIndex . '&' . $this->identifier . '=' . (int) $source->id
                . '&csvsync_action=mapping&token=' . $this->token,
            'back_link' => self::$currentIndex . '&token=' . $this->token,
            'preview_link' => self::$currentIndex . '&' . $this->identifier . '=' . (int) $source->id
                . '&csvsync_action=preview&token=' . $this->token,
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'csvproductsync/views/templates/admin/mapping.tpl');
    }

    /**
     * First-time help: the scrapers in this project name their columns close
     * enough to PrestaShop's that a guess saves most of the clicking. Only
     * columns nobody has mapped yet are guessed at.
     *
     * @return array column => ['ps_field' => string, 'transform' => string]
     */
    private function suggestMapping(array $columns, array $existing)
    {
        $by_name = [
            'product_id' => ['external_id', 'trim'],
            'sku_product_id' => ['external_id', 'trim'],
            'sku' => ['reference', 'trim'],
            'item_number' => ['reference', 'trim'],
            'article_no' => ['reference', 'trim'],
            'mpn' => ['mpn', 'trim'],
            'ean' => ['ean13', 'trim'],
            'name' => ['name', 'trim'],
            'product_name' => ['name', 'trim'],
            'page_title' => ['meta_title', 'trim'],
            'brand' => ['manufacturer', 'trim'],
            'description' => ['description', 'none'],
            'description_html' => ['description', 'none'],
            'short_description' => ['description_short', 'none'],
            'price' => ['price', 'number'],
            'regular_price' => ['wholesale_price', 'number'],
            'old_price' => ['wholesale_price', 'number'],
            'in_stock' => ['in_stock', 'boolean'],
            'availability' => ['in_stock', 'schema_availability'],
            'stock_status_text' => ['available_now', 'trim'],
            'category' => ['categories', 'trim'],
            'breadcrumbs' => ['categories', 'breadcrumb_to_path'],
            'breadcrumb' => ['categories', 'breadcrumb_to_path'],
            'images' => ['images', 'python_list'],
            'main_image' => ['images', 'absolute_url'],
            'canonical_url' => ['', 'none'],
            'weight' => ['weight', 'number'],
        ];

        $suggestions = [];
        foreach ($columns as $column) {
            $key = Tools::strtolower(trim((string) $column));
            if (isset($existing[$column]) || !isset($by_name[$key]) || $by_name[$key][0] === '') {
                continue;
            }
            $suggestions[$column] = ['ps_field' => $by_name[$key][0], 'transform' => $by_name[$key][1]];
        }

        return $suggestions;
    }

    /**
     * The whole mapping is replaced on every save, so it is validated in full
     * first: a half-applied mapping is worse than a rejected one.
     */
    private function processSaveMapping(CsvSyncSource $source)
    {
        if (!Validate::isLoadedObject($source)) {
            $this->errors[] = $this->trans('Unknown source.', [], 'Modules.Csvproductsync.Admin');

            return;
        }

        $columns = (array) Tools::getValue('csv_column');
        $fields = (array) Tools::getValue('ps_field');
        $features = (array) Tools::getValue('feature_name');
        $transforms = (array) Tools::getValue('transform');
        $defaults = (array) Tools::getValue('default_value');
        $known_transforms = CsvSyncTransformer::all();

        $planned = [];
        $claimed = [];
        foreach ($columns as $index => $column) {
            $column = trim((string) $column);
            $field = isset($fields[$index]) ? trim((string) $fields[$index]) : '';
            if ($column === '' || $field === '') {
                continue;
            }

            if ($field === '__feature__') {
                $feature_name = isset($features[$index]) ? trim((string) $features[$index]) : '';
                if ($feature_name === '') {
                    $this->errors[] = $this->trans(
                        'Column "%column%" is mapped to a feature but no feature name was given.',
                        ['%column%' => $column],
                        'Modules.Csvproductsync.Admin'
                    );
                    continue;
                }
                $field = 'feature:' . $feature_name;
            } elseif (!CsvSyncFields::exists($field)) {
                $this->errors[] = $this->trans('Unknown target field "%field%".', ['%field%' => $field], 'Modules.Csvproductsync.Admin');
                continue;
            }

            // Two columns feeding one field would overwrite each other in an
            // order nobody can see, so it is refused rather than resolved.
            if (isset($claimed[$field])) {
                $this->errors[] = $this->trans(
                    'Both "%a%" and "%b%" are mapped to %field% — pick one.',
                    ['%a%' => $claimed[$field], '%b%' => $column, '%field%' => CsvSyncFields::label($field)],
                    'Modules.Csvproductsync.Admin'
                );
                continue;
            }
            $claimed[$field] = $column;

            $transform = isset($transforms[$index]) ? (string) $transforms[$index] : 'none';
            $planned[] = [
                'column' => $column,
                'field' => $field,
                'transform' => isset($known_transforms[$transform]) ? $transform : 'none',
                'default' => isset($defaults[$index]) ? (string) $defaults[$index] : '',
            ];
        }

        if (!$planned) {
            $this->errors[] = $this->trans('Map at least one column before saving.', [], 'Modules.Csvproductsync.Admin');
        }
        if (!isset($claimed[$source->match_by])) {
            $this->errors[] = $this->trans(
                'The matching field (%field%) has to be mapped, or the importer cannot recognise a product between runs.',
                ['%field%' => CsvSyncFields::label($source->match_by)],
                'Modules.Csvproductsync.Admin'
            );
        }
        if ($source->create_missing_products && !isset($claimed['name'])) {
            $this->errors[] = $this->trans(
                'This source creates products, so the Name field has to be mapped.',
                [],
                'Modules.Csvproductsync.Admin'
            );
        }

        if ($this->errors) {
            return;
        }

        CsvSyncMapping::deleteForSource((int) $source->id);
        foreach ($planned as $position => $row) {
            $mapping = new CsvSyncMapping();
            $mapping->id_csvsync_source = (int) $source->id;
            $mapping->csv_column = $row['column'];
            $mapping->ps_field = $row['field'];
            $mapping->transform = $row['transform'];
            $mapping->default_value = $row['default'];
            $mapping->position = (int) $position;
            $mapping->add();
        }

        $this->confirmations[] = $this->trans(
            'Mapping saved: %count% columns.',
            ['%count%' => count($planned)],
            'Modules.Csvproductsync.Admin'
        );
    }

    // ------------------------------------------------------------------
    // preview screen

    private function renderPreview(CsvSyncSource $source)
    {
        $sample = null;
        $full = null;
        $limit = (int) Tools::getValue('rows', 10);

        try {
            $preview = new CsvSyncPreview($source);
            $sample = $preview->sample($limit > 0 ? min($limit, 200) : 10);
            if (Tools::getValue('full')) {
                @set_time_limit(0);
                $full = $preview->fullRun();
            }
        } catch (Exception $exception) {
            $this->errors[] = $this->trans('Could not read the CSV: %error%', ['%error%' => $exception->getMessage()], 'Modules.Csvproductsync.Admin');
        }

        $base = self::$currentIndex . '&' . $this->identifier . '=' . (int) $source->id . '&token=' . $this->token;
        $this->context->smarty->assign([
            'source' => $source,
            'sample' => $sample,
            'full' => $full,
            'rows_shown' => $limit,
            'preview_link' => $base . '&csvsync_action=preview',
            'full_link' => $base . '&csvsync_action=preview&full=1',
            'mapping_link' => $base . '&csvsync_action=mapping',
            'categories_link' => $this->context->link->getAdminLink('AdminCsvSyncCategories') . '&id_csvsync_source=' . (int) $source->id,
            'run_link' => $base . '&csvsync_action=run',
            'back_link' => self::$currentIndex . '&token=' . $this->token,
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'csvproductsync/views/templates/admin/preview.tpl');
    }
}
