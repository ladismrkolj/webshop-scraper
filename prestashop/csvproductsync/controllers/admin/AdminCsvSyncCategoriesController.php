<?php
/**
 * Category mapping: every category value a feed uses, and the shop category it
 * lands in.
 *
 * The list is filled by scanning the CSV, which can be repeated whenever the
 * scraped shop invents new categories. A re-scan never disturbs a decision
 * already made — it only adds the values nobody has seen before.
 */
class AdminCsvSyncCategoriesController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'csvsync_category';
        $this->className = 'CsvSyncCategory';
        $this->identifier = 'id_csvsync_category';
        $this->lang = false;

        parent::__construct();
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addCSS(_MODULE_DIR_ . 'csvproductsync/views/css/admin.css');
    }

    public function initPageHeaderToolbar()
    {
        $this->page_header_toolbar_title = $this->trans('Category mapping', [], 'Modules.Csvproductsync.Admin');
        parent::initPageHeaderToolbar();
        unset($this->page_header_toolbar_btn['new']);
    }

    public function postProcess()
    {
        $source = $this->getSource();
        if (!$source) {
            return parent::postProcess();
        }

        if (Tools::isSubmit('submitCsvSyncScan')) {
            $this->processScan($source);
        } elseif (Tools::isSubmit('submitCsvSyncCategoryMap')) {
            $this->processSaveMap($source);
        }

        return true;
    }

    public function initContent()
    {
        $this->initTabModuleList();
        $this->initToolbar();
        $this->initPageHeaderToolbar();

        $source = $this->getSource();
        if (!$source) {
            $this->content = $this->renderSourcePicker();
        } else {
            $this->content = $this->renderMap($source);
        }

        $this->context->smarty->assign([
            'content' => $this->content,
            'show_page_header_toolbar' => $this->show_page_header_toolbar,
            'page_header_toolbar_title' => $this->page_header_toolbar_title,
            'page_header_toolbar_btn' => $this->page_header_toolbar_btn,
        ]);
    }

    /**
     * @return CsvSyncSource|null
     */
    private function getSource()
    {
        $id = (int) Tools::getValue('id_csvsync_source');
        if (!$id) {
            return null;
        }
        $source = new CsvSyncSource($id);

        return Validate::isLoadedObject($source) ? $source : null;
    }

    private function processScan(CsvSyncSource $source)
    {
        @set_time_limit(0);
        try {
            $result = (new CsvSyncCategoryScanner($source))->scan();
        } catch (Exception $exception) {
            $this->errors[] = $this->trans('Scan failed: %error%', ['%error%' => $exception->getMessage()], 'Modules.Csvproductsync.Admin');

            return;
        }

        if ($result['added'] > 0) {
            $this->confirmations[] = $this->trans(
                'Scanned %rows% rows: %distinct% category values in the feed, %added% of them new and waiting to be mapped.',
                ['%rows%' => (int) $result['rows'], '%distinct%' => (int) $result['distinct'], '%added%' => (int) $result['added']],
                'Modules.Csvproductsync.Admin'
            );
        } else {
            $this->confirmations[] = $this->trans(
                'Scanned %rows% rows: %distinct% category values, all of them already known.',
                ['%rows%' => (int) $result['rows'], '%distinct%' => (int) $result['distinct']],
                'Modules.Csvproductsync.Admin'
            );
        }
    }

    private function processSaveMap(CsvSyncSource $source)
    {
        $targets = (array) Tools::getValue('id_category');
        $ignored = (array) Tools::getValue('ignored');
        $changed = 0;

        foreach (CsvSyncCategory::getForSource((int) $source->id) as $row) {
            $id = (int) $row['id_csvsync_category'];
            if (!array_key_exists($id, $targets) && !array_key_exists($id, $ignored)) {
                continue;
            }

            $category = new CsvSyncCategory($id);
            if (!Validate::isLoadedObject($category)) {
                continue;
            }

            $id_category = isset($targets[$id]) ? (int) $targets[$id] : 0;
            $is_ignored = !empty($ignored[$id]);

            if ($is_ignored) {
                $status = CsvSyncCategory::STATUS_IGNORED;
                $id_category = 0;
            } elseif ($id_category > 0) {
                $status = CsvSyncCategory::STATUS_MAPPED;
            } else {
                $status = CsvSyncCategory::STATUS_NEW;
            }

            if ((int) $category->id_category === $id_category && $category->status === $status) {
                continue;
            }

            $category->id_category = $id_category;
            $category->status = $status;
            if ($category->update()) {
                ++$changed;
            }
        }

        $this->confirmations[] = $this->trans('%count% category mappings updated.', ['%count%' => $changed], 'Modules.Csvproductsync.Admin');
    }

    /**
     * Reached from the menu rather than from a source: ask which feed.
     */
    private function renderSourcePicker()
    {
        $sources = [];
        foreach (CsvSyncSource::getSources() as $source) {
            $counts = CsvSyncCategory::countByStatus((int) $source->id);
            $sources[] = [
                'id' => (int) $source->id,
                'name' => $source->name,
                'counts' => $counts,
                'link' => self::$currentIndex . '&id_csvsync_source=' . (int) $source->id . '&token=' . $this->token,
            ];
        }

        $this->context->smarty->assign(['sources' => $sources]);

        return $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'csvproductsync/views/templates/admin/categories_picker.tpl'
        );
    }

    private function renderMap(CsvSyncSource $source)
    {
        $filter = Tools::getValue('status_filter', 'all');
        $rows = CsvSyncCategory::getForSource((int) $source->id);
        $counts = CsvSyncCategory::countByStatus((int) $source->id);

        $shop_categories = [];
        foreach (Category::getCategories((int) $source->id_lang, false, false) as $category) {
            $shop_categories[] = [
                'id' => (int) $category['id_category'],
                // The breadcrumb makes two categories called "Kites" in
                // different branches tellable apart in a flat dropdown.
                'name' => $this->categoryPath((int) $category['id_category'], (int) $source->id_lang),
            ];
        }
        usort($shop_categories, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $visible = array_values(array_filter($rows, function ($row) use ($filter) {
            return $filter === 'all' || $row['status'] === $filter;
        }));

        $base = self::$currentIndex . '&id_csvsync_source=' . (int) $source->id . '&token=' . $this->token;
        $this->context->smarty->assign([
            'source' => $source,
            'rows' => $visible,
            'counts' => $counts,
            'total' => count($rows),
            'filter' => $filter,
            'shop_categories' => $shop_categories,
            'uses_mapping' => $source->category_mode === CsvSyncSource::CATEGORY_MAP,
            'form_action' => $base,
            'filter_link' => $base . '&status_filter=',
            'sources_link' => $this->context->link->getAdminLink('AdminCsvSyncSources'),
        ]);

        return $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'csvproductsync/views/templates/admin/categories.tpl'
        );
    }

    /** @var array|null category id => name, built once per request */
    private $category_names;

    /**
     * @return string "Home > Boards > Kites"
     */
    private function categoryPath($id_category, $id_lang)
    {
        if ($this->category_names === null) {
            $this->category_names = [];
            foreach (Category::getCategories($id_lang, false, false) as $category) {
                $this->category_names[(int) $category['id_category']] = $category['name'];
            }
        }
        $names = $this->category_names;

        $path = [];
        $current = new Category((int) $id_category, (int) $id_lang);
        foreach (array_reverse($current->getParentsCategories($id_lang)) as $parent) {
            $id = (int) $parent['id_category'];
            if (isset($names[$id])) {
                $path[] = $names[$id];
            }
        }

        return $path ? implode(' > ', $path) : (isset($names[(int) $id_category]) ? $names[(int) $id_category] : '#' . (int) $id_category);
    }
}
