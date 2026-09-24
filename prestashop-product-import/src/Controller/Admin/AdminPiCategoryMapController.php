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
        if (!Tools::isSubmit('saveCategoryMapping') && !Tools::isSubmit('saveDefaultMapping')) {
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
            $value = Tools::getValue('id_category', '');
            if (!is_string($value) || ($value !== '' && (!ctype_digit($value) || (int) $value <= 0))) {
                throw new InvalidArgumentException('Invalid category.');
            }
            $idCategory = $value === '' ? null : (int) $value;
            if ($idCategory !== null && !in_array($idCategory, array_column($this->categoryOptions(), 'id_category'), true)) {
                throw new InvalidArgumentException('Category is not available in this shop.');
            }
            if (Tools::isSubmit('saveDefaultMapping')) {
                if (!(new SourceRepository())->update($idSource, ['default_id_category' => $idCategory])) {
                    throw new RuntimeException('Unable to save default category.');
                }
            } else {
                $hash = Tools::getValue('source_path_hash');
                $repository = new CategoryMappingRepository();
                if (!is_string($hash) || !$repository->findOverride($idSource, $hash)) {
                    throw new InvalidArgumentException('Unknown source path.');
                }
                $repository->setOverride($idSource, $hash, $idCategory);
            }
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
                    $row['current'] = $row['id_category'] === null ? ($source['default_id_category'] === null ? 'auto-create' : 'source default #' . $source['default_id_category']) : 'existing category #' . $row['id_category'] . ' (' . ($names[$row['id_category']] ?? 'missing or unavailable') . ')';
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
        return (new \ProductImport\Service\CatalogOptions())->categories((int) $this->context->shop->id, (int) $this->context->language->id);
    }
}
