<?php
/**
 * CSV Product Sync — imports scraped product feeds into the catalogue.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/autoload.php';

class Csvproductsync extends Module
{
    public function __construct()
    {
        $this->name = 'csvproductsync';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'webshop-scraper';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('CSV Product Sync', [], 'Modules.Csvproductsync.Admin');
        $this->description = $this->trans(
            'Import and keep products in sync from CSV feeds: one source per feed, its own field mapping, its own category mapping, run by cron.',
            [],
            'Modules.Csvproductsync.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'This removes every source, mapping and import history. Products already imported stay in the catalogue.',
            [],
            'Modules.Csvproductsync.Admin'
        );
    }

    /**
     * The back-office menu: a parent entry with the three screens under it.
     */
    public function getTabs()
    {
        return [
            [
                'class_name' => 'AdminCsvSync',
                'visible' => true,
                'name' => 'CSV Product Sync',
                'parent_class_name' => 'AdminCatalog',
            ],
            [
                'class_name' => 'AdminCsvSyncSources',
                'visible' => true,
                'name' => 'Sources',
                'parent_class_name' => 'AdminCsvSync',
            ],
            [
                'class_name' => 'AdminCsvSyncCategories',
                'visible' => true,
                'name' => 'Category mapping',
                'parent_class_name' => 'AdminCsvSync',
            ],
            [
                'class_name' => 'AdminCsvSyncRuns',
                'visible' => true,
                'name' => 'Import history',
                'parent_class_name' => 'AdminCsvSync',
            ],
        ];
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!include __DIR__ . '/sql/install.php') {
            return false;
        }

        // The cron URL carries this token instead of a login, so it has to be
        // unguessable and it has to be generated per installation.
        Configuration::updateValue('CSVSYNC_CRON_TOKEN', Tools::passwdGen(32, 'NO_NUMERIC'));
        Configuration::updateValue('CSVSYNC_DEFAULT_QUANTITY', 10);
        Configuration::updateValue('CSVSYNC_MAX_IMAGES', 6);

        return true;
    }

    public function uninstall()
    {
        Configuration::deleteByName('CSVSYNC_CRON_TOKEN');
        Configuration::deleteByName('CSVSYNC_DEFAULT_QUANTITY');
        Configuration::deleteByName('CSVSYNC_MAX_IMAGES');

        include __DIR__ . '/sql/uninstall.php';

        return parent::uninstall();
    }

    /**
     * The module's configuration page is really the sources list, plus the
     * cron settings that are shared by every source.
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitCsvSyncSettings')) {
            Configuration::updateValue('CSVSYNC_DEFAULT_QUANTITY', (int) Tools::getValue('CSVSYNC_DEFAULT_QUANTITY'));
            Configuration::updateValue('CSVSYNC_MAX_IMAGES', (int) Tools::getValue('CSVSYNC_MAX_IMAGES'));
            $output .= $this->displayConfirmation($this->trans('Settings updated.', [], 'Admin.Notifications.Success'));
        }

        if (Tools::isSubmit('submitCsvSyncNewToken')) {
            Configuration::updateValue('CSVSYNC_CRON_TOKEN', Tools::passwdGen(32, 'NO_NUMERIC'));
            $output .= $this->displayConfirmation($this->trans('A new cron token was generated. Update your cron job.', [], 'Modules.Csvproductsync.Admin'));
        }

        $this->context->smarty->assign([
            'csvsync_cron_url' => $this->getCronUrl(),
            'csvsync_cron_cli' => $this->getCronCliCommand(),
            'csvsync_sources_link' => $this->context->link->getAdminLink('AdminCsvSyncSources'),
            'csvsync_default_quantity' => (int) Configuration::get('CSVSYNC_DEFAULT_QUANTITY'),
            'csvsync_max_images' => (int) Configuration::get('CSVSYNC_MAX_IMAGES'),
            'csvsync_sources' => CsvSyncSource::getSources(),
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    /**
     * @param int|null $id_source a URL for one source, or for every active one
     *
     * @return string
     */
    public function getCronUrl($id_source = null)
    {
        $url = Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/cron.php'
            . '?token=' . Configuration::get('CSVSYNC_CRON_TOKEN');
        if ($id_source) {
            $url .= '&id_source=' . (int) $id_source;
        }

        return $url;
    }

    /**
     * Hostinger runs cron as PHP CLI, which skips the web server entirely and
     * so is not subject to its request timeout — the right way to run a feed
     * of any size.
     *
     * @return string
     */
    public function getCronCliCommand($id_source = null)
    {
        $command = '/usr/bin/php ' . _PS_MODULE_DIR_ . $this->name . '/cron.php --token=' . Configuration::get('CSVSYNC_CRON_TOKEN');
        if ($id_source) {
            $command .= ' --id_source=' . (int) $id_source;
        }

        return $command;
    }
}
