<?php

use ProductImport\Repository\CategoryMappingRepository;
use ProductImport\Repository\SourceRepository;

class AdminPiCategoryMapController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess()
    {
        if (!Tools::isSubmit('saveCategoryMapping')) {
            return;
        }
        try {
            if (!$this->access('edit') || !$this->checkToken()) {
                throw new RuntimeException('Permission denied or invalid security token.');
            }
            $idSource = (int) Tools::getValue('id_source');
            if (!(new SourceRepository())->find($idSource)) {
                throw new InvalidArgumentException('Source not found.');
            }
            $hash = Tools::getValue('source_path_hash');
            $repository = new CategoryMappingRepository();
            if (!is_string($hash) || !$repository->findOverride($idSource, $hash)) {
                throw new InvalidArgumentException('Unknown source path.');
            }
            $value = Tools::getValue('id_category', '');
            if (!is_string($value) || ($value !== '' && (!ctype_digit($value) || (int) $value <= 0))) {
                throw new InvalidArgumentException('Invalid category.');
            }
            $idCategory = $value === '' ? null : (int) $value;
            if ($idCategory !== null && !in_array($idCategory, array_column($this->categoryOptions(), 'id_category'), true)) {
                throw new InvalidArgumentException('Category is not available in this shop.');
            }
            $repository->setOverride($idSource, $hash, $idCategory);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminPiCategoryMap') . '&id_source=' . $idSource . '&conf=4');
        } catch (Throwable $error) {
            $this->errors[] = Tools::safeOutput($error->getMessage());
        }
    }

    public function initContent()
    {
        $this->content = '';
        if ($this->access('view')) {
            try {
                $source = (new SourceRepository())->find((int) Tools::getValue('id_source'));
                if (!$source) {
                    throw new InvalidArgumentException('Open Category mappings from a saved source.');
                }
                $rows = (new CategoryMappingRepository())->findAllForSource((int) $source['id_source']);
                $options = $this->categoryOptions();
                $names = array_column($options, 'label', 'id_category');
                foreach ($rows as &$row) {
                    $path = json_decode($row['source_path'], true, 512, JSON_THROW_ON_ERROR);
                    $row['display_path'] = implode(' > ', $path);
                    $row['current'] = $row['id_category'] === null ? 'auto-create' : 'existing category #' . $row['id_category'] . ' (' . ($names[$row['id_category']] ?? 'missing or unavailable') . ')';
                }
                unset($row);
                $this->context->smarty->assign([
                    'pi_source' => $source, 'pi_rows' => $rows, 'pi_options' => $options,
                    'pi_can_edit' => $this->access('edit'),
                    'pi_action' => $this->context->link->getAdminLink('AdminPiCategoryMap'),
                    'pi_back' => $this->context->link->getAdminLink('AdminPiSource') . '&updatepi_source&id_source=' . (int) $source['id_source'],
                ]);
                $this->content = $this->context->smarty->fetch(dirname(__DIR__, 3) . '/views/templates/admin/category_mappings.tpl');
            } catch (Throwable $error) {
                $this->errors[] = Tools::safeOutput($error->getMessage());
            }
        }
        $this->setTemplate('content.tpl');
        $this->context->smarty->assign(['content' => $this->content, 'show_page_header_toolbar' => false, 'page_header_toolbar_title' => $this->trans('Category mappings')]);
    }

    private function categoryOptions(): array
    {
        // One query, ordered by the nested-set position. No per-category ObjectModel loads.
        $rows = Db::getInstance()->executeS('SELECT c.`id_category`, c.`level_depth`, cl.`name` FROM `' . _DB_PREFIX_ . 'category` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . (int) $this->context->shop->id
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category` AND cl.`id_shop` = cs.`id_shop` AND cl.`id_lang` = ' . (int) $this->context->language->id
            . ' ORDER BY c.`nleft`, c.`id_category`');
        if ($rows === false) {
            throw new RuntimeException('Unable to list categories.');
        }
        return array_map(static function (array $row): array {
            return ['id_category' => (int) $row['id_category'], 'label' => str_repeat('— ', min(30, (int) $row['level_depth'])) . ($row['name'] ?? '(unnamed)') . ' #' . (int) $row['id_category']];
        }, $rows);
    }
}
