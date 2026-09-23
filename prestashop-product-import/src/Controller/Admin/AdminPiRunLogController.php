<?php

use ProductImport\Repository\ImportRunRepository;

class AdminPiRunLogController extends AdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'pi_import_run';
        $this->identifier = 'id_run';
        $this->lang = false;
        parent::__construct();
    }

    public function postProcess()
    {
        // Read-only: never invoke AdminController's generic mutation handlers.
    }

    public function initContent()
    {
        $this->content = $this->access('view') ? $this->renderList() : '';
        $this->setTemplate('content.tpl');
        $this->context->smarty->assign(['content' => $this->content, 'show_page_header_toolbar' => false, 'page_header_toolbar_title' => $this->l('Product Import runs')]);
    }

    public function renderList()
    {
        $repository = new ImportRunRepository();
        $idSource = (int) Tools::getValue('id_source');
        $rows = $idSource > 0 ? $repository->findRecent($idSource) : $repository->findAll();
        // HelperList text columns are not consistently autoescaped across legacy themes.
        foreach ($rows as &$row) {
            foreach (['source_name', 'status', 'error_log'] as $field) {
                $row[$field] = Tools::safeOutput((string) ($row[$field] ?? ''));
            }
        }
        unset($row);
        $helper = new HelperList();
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->title = $this->l('Recent import runs');
        $helper->token = $this->token;
        $helper->currentIndex = self::$currentIndex;
        $helper->simple_header = true;
        $helper->actions = [];
        $fields = [];
        foreach (['source_name' => 'Source', 'started_at' => 'Started', 'finished_at' => 'Finished', 'status' => 'Status', 'created_count' => 'Created', 'updated_count' => 'Updated', 'skipped_count' => 'Skipped', 'failed_count' => 'Failed', 'error_log' => 'Errors'] as $key => $label) {
            $fields[$key] = ['title' => $this->l($label)];
        }
        return $helper->generateList($rows, $fields);
    }
}
