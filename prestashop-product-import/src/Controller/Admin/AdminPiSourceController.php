<?php

use ProductImport\Repository\SourceRepository;

// Legacy controller classes must be global for PrestaShop's tab dispatcher.
class AdminPiSourceController extends AdminController
{
    private $sources;
    private $submittedSource;
    private $submittedMapping;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'pi_source';
        $this->identifier = 'id_source';
        $this->lang = false;
        parent::__construct();
        $this->sources = new SourceRepository();
    }

    public function postProcess()
    {
        $saving = Tools::isSubmit('submitAddpi_source');
        $deleting = Tools::isSubmit('deletepi_source');
        if (!$saving && !$deleting) {
            return;
        }
        $id = (int) Tools::getValue('id_source');
        $permission = $deleting ? 'delete' : ($id > 0 ? 'edit' : 'add');
        if (!$this->access($permission) || !$this->checkToken()) {
            $this->errors[] = $this->l('You do not have permission or your security token is invalid.');

            return;
        }
        try {
            if ($id > 0 && !$this->sources->find($id)) {
                throw new InvalidArgumentException('Source not found.');
            }
            if ($deleting) {
                if ($id <= 0 || !$this->sources->delete($id)) {
                    throw new RuntimeException('Unable to delete source.');
                }
            } else {
                $data = $this->readSubmission();
                if ($id > 0) {
                    if (!$this->sources->update($id, $data)) {
                        throw new RuntimeException('Unable to update source.');
                    }
                } else {
                    $this->sources->create($data);
                }
            }
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminPiSource') . '&conf=' . ($deleting ? 1 : 4));
        } catch (Throwable $error) {
            $this->errors[] = Tools::safeOutput($error->getMessage());
            if ($saving) {
                $this->display = $id > 0 ? 'edit' : 'add';
            }
        }
    }

    public function initContent()
    {
        // Render explicitly: there is no source ObjectModel for AdminController to load.
        $this->content = '';
        if (Tools::isSubmit('addpi_source') || Tools::isSubmit('updatepi_source') || Tools::isSubmit('submitAddpi_source')) {
            if ($this->access((int) Tools::getValue('id_source') > 0 ? 'edit' : 'add')) {
                $this->content = $this->renderForm();
            }
        } elseif ($this->access('view')) {
            $this->content = $this->renderList();
        }
        $this->setTemplate('content.tpl');
        $this->context->smarty->assign([
            'content' => $this->content,
            'show_page_header_toolbar' => false,
            'page_header_toolbar_title' => $this->l('Product Import sources'),
        ]);
    }

    public function renderList()
    {
        $helper = new HelperList();
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->title = $this->l('Product Import sources');
        $helper->token = $this->token;
        $helper->currentIndex = self::$currentIndex;
        $helper->simple_header = true;
        $helper->actions = [];
        foreach (['edit', 'delete'] as $action) {
            if ($this->access($action)) {
                $helper->actions[] = $action;
            }
        }
        if ($this->access('add')) {
            $helper->toolbar_btn['new'] = [
                'href' => $this->context->link->getAdminLink('AdminPiSource') . '&addpi_source',
                'desc' => $this->l('Add source'),
            ];
        }

        return $helper->generateList($this->sources->findAll(), [
            'name' => ['title' => $this->l('Name')],
            'technical_key' => ['title' => $this->l('Technical key')],
            'active' => ['title' => $this->l('Active'), 'type' => 'bool'],
        ]);
    }

    public function renderForm()
    {
        $id = (int) Tools::getValue('id_source');
        $source = $this->submittedSource;
        if ($source === null) {
            $source = $id > 0 ? $this->sources->find($id) : [];
        }
        if ($source === null) {
            $this->errors[] = $this->l('Source not found.');

            return '';
        }
        $source += [
            'name' => '', 'technical_key' => '', 'json_url' => '', 'json_file_path' => '',
            'identifier_field' => '', 'filter_expression' => '', 'field_mapping' => '{}', 'active' => 1,
        ];
        $mapping = json_decode($source['field_mapping'], true);
        $rows = $this->submittedMapping;
        if ($rows === null) {
            $rows = [];
            foreach (is_array($mapping) ? $mapping : [] as $target => $expression) {
                $rows[] = ['target' => $target, 'expression' => $expression];
            }
        }
        $this->context->smarty->assign('pi_mapping_rows', $rows ?: [['target' => '', 'expression' => '']]);
        $mappingHtml = $this->context->smarty->fetch(dirname(__DIR__, 3) . '/views/templates/admin/source_form.tpl');
        $inputs = [];
        foreach ([
            'name' => 'Name', 'technical_key' => 'Technical key', 'json_url' => 'JSON URL',
            'json_file_path' => 'JSON file path', 'identifier_field' => 'Identifier field',
        ] as $name => $label) {
            $inputs[] = [
                'type' => 'text', 'label' => $this->l($label), 'name' => $name,
                'required' => in_array($name, ['name', 'technical_key', 'identifier_field'], true),
            ];
        }
        $inputs[] = ['type' => 'textarea', 'label' => $this->l('Filter expression'), 'name' => 'filter_expression'];
        $inputs[] = ['type' => 'html', 'name' => 'mapping_rows', 'html_content' => $mappingHtml, 'label' => $this->l('Field mapping')];
        $inputs[] = [
            'type' => 'switch', 'name' => 'active', 'label' => $this->l('Active'), 'is_bool' => true,
            'values' => [
                ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')],
                ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')],
            ],
        ];
        $helper = new HelperForm();
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->id = $id;
        $helper->token = $this->token;
        $helper->currentIndex = self::$currentIndex;
        $helper->submit_action = 'submitAddpi_source';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->fields_value = $source;

        return $helper->generateForm([['form' => [
            'legend' => ['title' => $this->l('Import source')],
            'input' => $inputs,
            'submit' => ['title' => $this->l('Save')],
        ]]]);
    }

    private function readSubmission(): array
    {
        $data = [];
        foreach (['name', 'technical_key', 'json_url', 'json_file_path', 'identifier_field', 'filter_expression'] as $field) {
            $value = Tools::getValue($field, '');
            if (!is_string($value)) {
                throw new InvalidArgumentException('Invalid value for ' . $field . '.');
            }
            $data[$field] = trim($value);
        }
        $data['active'] = (int) (Tools::getValue('active') === '1');
        $this->submittedSource = $data;
        $targets = Tools::getValue('field_mapping_target', []);
        $expressions = Tools::getValue('field_mapping_expression', []);
        if (!is_array($targets) || !is_array($expressions) || array_keys($targets) !== array_keys($expressions)) {
            throw new InvalidArgumentException('Invalid mapping rows.');
        }
        $this->submittedMapping = [];
        foreach ($targets as $index => $target) {
            if (!is_string($target) || !is_string($expressions[$index])) {
                throw new InvalidArgumentException('Mapping rows must contain strings.');
            }
            $this->submittedMapping[] = ['target' => $target, 'expression' => $expressions[$index]];
        }
        $mapping = [];
        foreach ($this->submittedMapping as $row) {
            $target = trim($row['target']);
            $expression = trim($row['expression']);
            if ($target === '' && $expression === '') {
                continue;
            }
            if ($target === '' || $expression === '') {
                throw new InvalidArgumentException('Each mapping row needs a target and expression.');
            }
            if (array_key_exists($target, $mapping)) {
                throw new InvalidArgumentException('Duplicate mapping target: ' . $target);
            }
            $mapping[$target] = $expression;
        }
        foreach (['name' => 191, 'technical_key' => 64, 'identifier_field' => 191] as $field => $limit) {
            if ($data[$field] === '' || Tools::strlen($data[$field]) > $limit) {
                throw new InvalidArgumentException($field . ' is required and must not exceed ' . $limit . ' characters.');
            }
        }
        if (!preg_match('/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/D', $data['technical_key'])) {
            throw new InvalidArgumentException('Technical key must be a lowercase slug (letters, numbers, underscores or hyphens).');
        }
        if ($data['json_url'] === '' && $data['json_file_path'] === '') {
            throw new InvalidArgumentException('Provide a JSON URL or file path.');
        }
        if ($data['json_url'] !== '' && (!filter_var($data['json_url'], FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($data['json_url'], PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new InvalidArgumentException('JSON URL must be a valid HTTP or HTTPS URL.');
        }
        $existing = $this->sources->findByTechnicalKey($data['technical_key']);
        if ($existing && (int) $existing['id_source'] !== (int) Tools::getValue('id_source')) {
            throw new InvalidArgumentException('Technical key is already in use.');
        }
        $data['field_mapping'] = json_encode((object) $mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($data['field_mapping'] === false) {
            throw new InvalidArgumentException('Unable to encode field mapping: ' . json_last_error_msg());
        }
        foreach (['json_url', 'json_file_path', 'filter_expression'] as $field) {
            if ($data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
