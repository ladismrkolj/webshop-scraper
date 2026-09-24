<?php

use ProductImport\Repository\ManufacturerMappingRepository;
use ProductImport\Repository\SourceRepository;

class AdminPiManufacturerMapController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess()
    {
        if (!Tools::isSubmit('saveManufacturerMapping') && !Tools::isSubmit('saveDefaultMapping')) {
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
            $value = Tools::getValue('id_manufacturer', '');
            if (!is_string($value) || ($value !== '' && (!ctype_digit($value) || (int) $value <= 0))) {
                throw new InvalidArgumentException('Invalid manufacturer.');
            }
            $idManufacturer = $value === '' ? null : (int) $value;
            if ($idManufacturer !== null && !in_array($idManufacturer, array_column($this->manufacturerOptions(), 'id_manufacturer'), true)) {
                throw new InvalidArgumentException('Manufacturer does not exist.');
            }
            if (Tools::isSubmit('saveDefaultMapping')) {
                if (!(new SourceRepository())->update($idSource, ['default_id_manufacturer' => $idManufacturer])) {
                    throw new RuntimeException('Unable to save default manufacturer.');
                }
            } else {
                $name = Tools::getValue('source_name');
                $repository = new ManufacturerMappingRepository();
                if (!is_string($name) || !$repository->findOverride($idSource, $name)) {
                    throw new InvalidArgumentException('Unknown source brand.');
                }
                $repository->setOverride($idSource, $name, $idManufacturer);
            }
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminPiManufacturerMap') . '&id_source=' . $idSource . '&conf=4');
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
                    throw new InvalidArgumentException('Open Brand mappings from a saved source.');
                }
                $rows = (new ManufacturerMappingRepository())->findAllForSource((int) $source['id_source']);
                $options = $this->manufacturerOptions();
                $names = array_column($options, 'label', 'id_manufacturer');
                foreach ($rows as &$row) {
                    $row['display_name'] = $row['source_name'];
                    $row['current'] = $row['id_manufacturer'] === null ? ($source['default_id_manufacturer'] === null ? 'auto-create' : 'source default #' . $source['default_id_manufacturer']) : 'existing manufacturer #' . $row['id_manufacturer'] . ' (' . ($names[$row['id_manufacturer']] ?? 'missing or unavailable') . ')';
                }
                unset($row);
                $this->context->smarty->assign([
                    'pi_source' => $source, 'pi_rows' => $rows, 'pi_options' => $options,
                    'pi_can_edit' => $this->access('edit'),
                    'pi_action' => $this->context->link->getAdminLink('AdminPiManufacturerMap'),
                    'pi_back' => $this->context->link->getAdminLink('AdminPiSource') . '&updatepi_source&id_source=' . (int) $source['id_source'],
                ]);
                $this->content = $this->context->smarty->fetch(dirname(__DIR__, 3) . '/views/templates/admin/manufacturer_mappings.tpl');
            } catch (Throwable $error) {
                $this->errors[] = Tools::safeOutput($error->getMessage());
            }
        }
        $this->setTemplate('content.tpl');
        $this->context->smarty->assign(['content' => $this->content, 'show_page_header_toolbar' => false, 'page_header_toolbar_title' => $this->trans('Brand mappings')]);
    }

    private function manufacturerOptions(): array
    {
        return (new \ProductImport\Service\CatalogOptions())->manufacturers();
    }
}
