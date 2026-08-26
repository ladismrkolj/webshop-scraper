<?php
/**
 * The parent menu entry. It has no screen of its own, so it hands over to the
 * sources list.
 */
class AdminCsvSyncController extends ModuleAdminController
{
    public function initContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminCsvSyncSources'));
    }
}
