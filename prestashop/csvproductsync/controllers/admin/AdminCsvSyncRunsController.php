<?php
/**
 * Import history: one row per run, with whatever the run had to complain about.
 */
class AdminCsvSyncRunsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'csvsync_run';
        $this->className = 'CsvSyncRun';
        $this->identifier = 'id_csvsync_run';
        $this->lang = false;
        $this->list_no_link = true;
        $this->_defaultOrderBy = 'id_csvsync_run';
        $this->_defaultOrderWay = 'DESC';

        parent::__construct();

        $this->_select = 's.name AS source_name';
        $this->_join = 'LEFT JOIN `' . _DB_PREFIX_ . 'csvsync_source` s ON s.id_csvsync_source = a.id_csvsync_source';

        $this->fields_list = [
            'id_csvsync_run' => ['title' => $this->trans('ID', [], 'Admin.Global'), 'align' => 'text-center', 'class' => 'fixed-width-xs'],
            'source_name' => ['title' => $this->trans('Source', [], 'Modules.Csvproductsync.Admin'), 'havingFilter' => true],
            'date_add' => ['title' => $this->trans('Started', [], 'Modules.Csvproductsync.Admin'), 'type' => 'datetime'],
            'date_upd' => ['title' => $this->trans('Finished', [], 'Modules.Csvproductsync.Admin'), 'type' => 'datetime'],
            'trigger_type' => ['title' => $this->trans('Trigger', [], 'Modules.Csvproductsync.Admin'), 'type' => 'select', 'list' => ['manual' => 'manual', 'cron' => 'cron'], 'filter_key' => 'a!trigger_type'],
            'status' => [
                'title' => $this->trans('Status', [], 'Admin.Global'),
                'type' => 'select',
                'list' => [
                    CsvSyncRun::STATUS_RUNNING => 'running',
                    CsvSyncRun::STATUS_SUCCESS => 'success',
                    CsvSyncRun::STATUS_PARTIAL => 'partial',
                    CsvSyncRun::STATUS_ERROR => 'error',
                ],
                'filter_key' => 'a!status',
                'callback' => 'renderStatus',
            ],
            'rows_read' => ['title' => $this->trans('Rows', [], 'Modules.Csvproductsync.Admin'), 'align' => 'text-right'],
            'products_created' => ['title' => $this->trans('Created', [], 'Modules.Csvproductsync.Admin'), 'align' => 'text-right'],
            'products_updated' => ['title' => $this->trans('Updated', [], 'Modules.Csvproductsync.Admin'), 'align' => 'text-right'],
            'products_unchanged' => ['title' => $this->trans('Unchanged', [], 'Modules.Csvproductsync.Admin'), 'align' => 'text-right'],
            'products_removed' => ['title' => $this->trans('Removed', [], 'Modules.Csvproductsync.Admin'), 'align' => 'text-right'],
            'rows_skipped' => ['title' => $this->trans('Skipped', [], 'Modules.Csvproductsync.Admin'), 'align' => 'text-right'],
            'message' => ['title' => $this->trans('Notes', [], 'Modules.Csvproductsync.Admin'), 'callback' => 'renderMessage', 'search' => false, 'orderby' => false],
        ];

        $this->actions = ['details'];
        $this->bulk_actions = [
            'delete' => ['text' => $this->trans('Delete selected', [], 'Admin.Actions'), 'confirm' => $this->trans('Delete selected items?', [], 'Admin.Notifications.Warning'), 'icon' => 'icon-trash'],
        ];
    }

    public static function renderStatus($status)
    {
        $classes = [
            CsvSyncRun::STATUS_SUCCESS => 'success',
            CsvSyncRun::STATUS_PARTIAL => 'warning',
            CsvSyncRun::STATUS_ERROR => 'danger',
            CsvSyncRun::STATUS_RUNNING => 'info',
        ];
        $class = isset($classes[$status]) ? $classes[$status] : 'default';

        return '<span class="badge badge-' . $class . ' label-' . $class . '">' . htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /**
     * The full note can run to fifty lines; the list shows the shape of it and
     * the details view has the rest.
     */
    public static function renderMessage($message)
    {
        $message = trim((string) $message);
        if ($message === '') {
            return '—';
        }
        $lines = explode("\n", $message);
        $first = htmlspecialchars($lines[0], ENT_QUOTES, 'UTF-8');
        if (count($lines) === 1) {
            return '<span title="' . $first . '">' . Tools::substr($first, 0, 90) . '</span>';
        }

        return '<span title="' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '">'
            . Tools::substr($first, 0, 90) . ' <em>(+' . (count($lines) - 1) . ')</em></span>';
    }

    public function renderView()
    {
        $run = new CsvSyncRun((int) Tools::getValue('id_csvsync_run'));
        if (!Validate::isLoadedObject($run)) {
            $this->errors[] = $this->trans('Unknown run.', [], 'Modules.Csvproductsync.Admin');

            return '';
        }
        $source = new CsvSyncSource((int) $run->id_csvsync_source);

        $this->context->smarty->assign([
            'run' => $run,
            'source_name' => Validate::isLoadedObject($source) ? $source->name : '—',
            'back_link' => $this->context->link->getAdminLink('AdminCsvSyncRuns'),
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'csvproductsync/views/templates/admin/run.tpl');
    }
}
