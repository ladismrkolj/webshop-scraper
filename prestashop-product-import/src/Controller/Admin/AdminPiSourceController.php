<?php

use ProductImport\Repository\SourceRepository;

// Legacy controller classes must be global for PrestaShop's tab dispatcher.
// PS9 removed the legacy l()/translation fallback from AdminController; it
// only exists on ModuleAdminController now.
class AdminPiSourceController extends ModuleAdminController
{
    private $sources;
    private $submittedSource;
    private $submittedMapping;
    private $submittedVariant;

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
        $actions = ['testSource' => 'ajaxProcessTestSource', 'preview' => 'ajaxProcessPreview', 'inspect' => 'ajaxProcessInspect', 'discoverCategories' => 'ajaxProcessDiscoverCategories'];
        $action = Tools::getValue('action');
        if ($this->ajax && is_string($action) && isset($actions[$action])) {
            $this->{$actions[$action]}();
            return;
        }
        $saving = Tools::isSubmit('submitAddpi_source');
        $deleting = Tools::isSubmit('deletepi_source');
        if (!$saving && !$deleting) {
            return;
        }
        $id = (int) Tools::getValue('id_source');
        $permission = $deleting ? 'delete' : ($id > 0 ? 'edit' : 'add');
        if (!$this->access($permission) || !$this->checkToken()) {
            $this->errors[] = $this->trans('You do not have permission or your security token is invalid.');

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

    public function ajaxProcessPreview()
    {
        try {
            if (!$this->access('view') || !$this->checkToken()) {
                throw new RuntimeException('Permission denied or invalid security token.');
            }
            $source = $this->sources->find((int) Tools::getValue('id_source'));
            if (!$source) {
                throw new InvalidArgumentException('Save a source before previewing it.');
            }
            $item = $this->sampleItem($source);
            foreach (['field_mapping', 'variant_mapping'] as $column) {
                if ($column === 'variant_mapping' && empty($source[$column])) {
                    $source[$column] = null;
                    continue;
                }
                $source[$column] = json_decode($source[$column], true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($source[$column])) {
                    throw new InvalidArgumentException('Invalid saved ' . $column . ' JSON.');
                }
            }
            $evaluator = new \ProductImport\Service\ExpressionEvaluator();
            $normalizer = new \ProductImport\Service\CategoryPathNormalizer();
            $builder = new \ProductImport\Service\PreviewBuilder(
                new \ProductImport\Service\ProductFilter($evaluator),
                new \ProductImport\Service\ProductFieldMapper($evaluator),
                $normalizer,
                new \ProductImport\Service\CategoryResolver(new \ProductImport\Repository\CategoryMappingRepository(), $normalizer),
                new \ProductImport\Service\ManufacturerResolver(),
                new \ProductImport\Service\VariantFieldMapper($evaluator),
                new \ProductImport\Service\AttributeResolver()
            );
            $result = $builder->build($source, $item);
        } catch (Throwable $error) {
            $result = ['error' => $error->getMessage()];
        }
        header('Content-Type: application/json; charset=utf-8');
        $json = json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
        die($json === false ? '{"error":"Unable to encode preview response."}' : $json);
    }

    private function sampleItem(array $source, ?array $fetchedItems = null): array
    {
        $hasIndex = Tools::getIsset('item_index');
        $hasRaw = Tools::getIsset('raw_item');
        if ($hasIndex === $hasRaw) {
            throw new InvalidArgumentException('Provide exactly one of item_index or raw_item.');
        }
        if ($hasRaw) {
            $raw = Tools::getValue('raw_item');
            if (!is_string($raw) || substr(ltrim($raw), 0, 1) !== '{') {
                throw new InvalidArgumentException('raw_item must be a JSON object.');
            }
            $item = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($item)) {
                throw new InvalidArgumentException('Invalid JSON object: ' . json_last_error_msg());
            }
        } else {
            $index = Tools::getValue('item_index');
            if (!is_string($index) || !ctype_digit($index)) {
                throw new InvalidArgumentException('item_index must be a non-negative integer.');
            }
            $items = $fetchedItems ?? (new \ProductImport\Service\JsonFetcher())->fetch($source['json_url'], $source['json_file_path']);
            if (!array_key_exists((int) $index, $items) || !is_array($items[(int) $index])) {
                throw new InvalidArgumentException('No product object at that index.');
            }
            $item = $items[(int) $index];
        }
        return $item;
    }

    public function ajaxProcessInspect()
    {
        try {
            $source = $this->requireSource('view');
            $result = (new \ProductImport\Service\FieldInspector())->inspect($this->sampleItem($source));
        } catch (Throwable $error) {
            $result = ['error' => $error->getMessage()];
        }
        $this->sendJson($result);
    }

    public function ajaxProcessDiscoverCategories()
    {
        try {
            $source = $this->requireSource('edit');
            $mapping = json_decode($source['field_mapping'], true, 512, JSON_THROW_ON_ERROR);
            $expression = $mapping['category_paths'] ?? null;
            if (!is_string($expression) || trim($expression) === '') {
                throw new InvalidArgumentException('Save a category_paths expression before discovery.');
            }
            $items = (new \ProductImport\Service\JsonFetcher())->fetch($source['json_url'], $source['json_file_path']);
            $result = $this->discoverValues($items, (int) $source['id_source'], $expression, false);
        } catch (Throwable $error) {
            $result = ['error' => $error->getMessage()];
        }
        $this->sendJson($result);
    }

    public function ajaxProcessTestSource()
    {
        try {
            $rawId = $_POST['id_source'] ?? '';
            if (!is_string($rawId) || ($rawId !== '' && (!ctype_digit($rawId) || (int) $rawId <= 0))) {
                throw new InvalidArgumentException('id_source must be a positive integer when provided.');
            }
            $idSource = $rawId === '' ? null : (int) $rawId;
            if (!$this->checkToken() || !$this->access($idSource === null ? 'add' : 'edit')) {
                throw new RuntimeException('Permission denied or invalid security token.');
            }
            $source = $idSource === null ? null : $this->sources->find($idSource);
            if ($idSource !== null && !$source) {
                throw new InvalidArgumentException('Source not found.');
            }
            $input = [];
            foreach (['json_url', 'json_file_path'] as $key) {
                $value = $_POST[$key] ?? null;
                if ($value !== null && !is_string($value)) {
                    throw new InvalidArgumentException($key . ' must be a string.');
                }
                $input[$key] = $value;
            }
            // Always test the submitted location, never silently substitute the saved URL/path.
            $items = (new \ProductImport\Service\JsonFetcher())->fetch($input['json_url'], $input['json_file_path']);
            if ($items === []) {
                throw new InvalidArgumentException('Source JSON must be a non-empty array.');
            }
            $result = ['connectivity' => ['ok' => true, 'format' => 'non-empty JSON array'], 'item_count' => count($items)];
            try {
                $result['inspection'] = (new \ProductImport\Service\FieldInspector())->inspect($this->sampleItem($input, $items));
            } catch (Throwable $error) {
                $result['inspection'] = ['error' => $error->getMessage()];
            }
            $mapping = [];
            $mappingError = null;
            if ($source !== null) {
                try {
                    $mapping = json_decode($source['field_mapping'], true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($mapping)) {
                        throw new InvalidArgumentException('Saved field_mapping must be a JSON object.');
                    }
                } catch (Throwable $error) {
                    $mappingError = $error->getMessage();
                }
            }
            foreach (['categories' => 'category_paths', 'brands' => 'manufacturer'] as $section => $target) {
                try {
                    if ($source === null) {
                        $result[$section] = ['skipped' => 'source not saved yet'];
                    } elseif ($mappingError !== null) {
                        $result[$section] = ['error' => $mappingError];
                    } elseif (!isset($mapping[$target]) || $mapping[$target] === '') {
                        $result[$section] = ['skipped' => 'no saved ' . $target . ' expression'];
                    } elseif (!is_string($mapping[$target])) {
                        throw new InvalidArgumentException('Saved ' . $target . ' expression must be a string.');
                    } elseif (trim($mapping[$target]) === '') {
                        $result[$section] = ['skipped' => 'no saved ' . $target . ' expression'];
                    } else {
                        $result[$section] = $this->discoverValues($items, $idSource, $mapping[$target], $section === 'brands');
                    }
                } catch (Throwable $error) {
                    $result[$section] = ['error' => $error->getMessage()];
                }
            }
        } catch (Throwable $error) {
            $result = ['error' => $error->getMessage()];
        }
        $this->sendJson($result);
    }

    /** Full-feed scan shared by the existing discovery button and Test source; writes only after deduplication. */
    private function discoverValues(array $items, int $idSource, string $expression, bool $brands): array
    {
        $evaluator = new \ProductImport\Service\ExpressionEvaluator();
        $normalizer = new \ProductImport\Service\CategoryPathNormalizer();
        $values = [];
        $failed = 0;
        $errors = [];
        foreach ($items as $index => $item) {
            try {
                if (!is_array($item)) {
                    throw new InvalidArgumentException('Item is not an object.');
                }
                $value = $evaluator->evaluate($expression, ['fields' => $item]);
                if ($brands) {
                    if ($value !== null && !is_string($value)) {
                        throw new InvalidArgumentException('Manufacturer must be a string or null.');
                    }
                    $name = trim($value ?? '');
                    if (mb_strlen($name) > 191) {
                        throw new InvalidArgumentException('Manufacturer name exceeds 191 characters.');
                    }
                    if ($name !== '') {
                        $values['name:' . $name] = $name;
                    }
                } else {
                    foreach ($normalizer->normalize($value) as $path) {
                        $values[$normalizer->hash($path)] = $path;
                    }
                }
            } catch (Throwable $error) {
                ++$failed;
                if (count($errors) < 20) {
                    $errors[] = 'Item ' . $index . ': ' . mb_substr($error->getMessage(), 0, 500);
                }
            }
        }
        if ($brands) {
            $resolver = new \ProductImport\Service\ManufacturerResolver();
            foreach ($values as $name) {
                $resolver->discover($name, $idSource);
            }
        } else {
            (new \ProductImport\Service\CategoryResolver(new \ProductImport\Repository\CategoryMappingRepository(), $normalizer))->discover(array_values($values), $idSource);
        }
        return [$brands ? 'unique_brands' : 'unique_paths' => count($values), 'items_scanned' => count($items), 'failed_items' => $failed, 'errors' => $errors,
            'errors_omitted' => max(0, $failed - count($errors)), 'mapping_url' => $this->context->link->getAdminLink($brands ? 'AdminPiManufacturerMap' : 'AdminPiCategoryMap') . '&id_source=' . $idSource];
    }

    private function requireSource(string $permission): array
    {
        if (!$this->access($permission) || !$this->checkToken()) {
            throw new RuntimeException('Permission denied or invalid security token.');
        }
        $source = $this->sources->find((int) Tools::getValue('id_source'));
        if (!$source) {
            throw new InvalidArgumentException('Save a source first.');
        }
        return $source;
    }

    private function sendJson(array $result): void
    {
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"error":"Unable to encode response"}');
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
            if ($this->access('add')) {
                $url = $this->context->link->getAdminLink('AdminPiSource') . '&addpi_source';
                $this->content .= '<p><a class="btn btn-default" href="' . Tools::safeOutput($url) . '">+ ' . Tools::safeOutput($this->trans('Add source')) . '</a></p>';
            }
            $this->content .= $this->renderList();
        }
        $this->setTemplate('content.tpl');
        $this->context->smarty->assign([
            'content' => $this->content,
            'show_page_header_toolbar' => false,
            'page_header_toolbar_title' => $this->trans('Product Import sources'),
        ]);
    }

    public function renderList()
    {
        $helper = new HelperList();
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->title = $this->trans('Product Import sources');
        $helper->token = $this->token;
        $helper->currentIndex = self::$currentIndex;
        $helper->simple_header = true;
        $helper->actions = [];
        foreach (['edit', 'delete'] as $action) {
            if ($this->access($action)) {
                $helper->actions[] = $action;
            }
        }
        $cronUrl = $this->context->link->getModuleLink('productimport', 'cron', ['token' => Configuration::get('PIIMPORT_CRON_TOKEN')], true);
        $banner = '<div class="alert alert-info">Daily cron URL: <code>' . Tools::safeOutput($cronUrl) . '</code></div>';
        $sources = $this->sources->findAll();
        foreach ($sources as $source) {
            $url = $this->context->link->getAdminLink('AdminPiCategoryMap') . '&id_source=' . (int) $source['id_source'];
            $brandUrl = $this->context->link->getAdminLink('AdminPiManufacturerMap') . '&id_source=' . (int) $source['id_source'];
            $banner .= '<p>' . Tools::safeOutput($source['name']) . ': <a href="' . Tools::safeOutput($url) . '">Category mappings →</a> | <a href="' . Tools::safeOutput($brandUrl) . '">Brand mappings →</a></p>';
        }
        return $banner . $helper->generateList($sources, [
            'name' => ['title' => $this->trans('Name')],
            'technical_key' => ['title' => $this->trans('Technical key')],
            'active' => ['title' => $this->trans('Active'), 'type' => 'bool'],
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
            $this->errors[] = $this->trans('Source not found.');

            return '';
        }
        $source += [
            'name' => '', 'technical_key' => '', 'json_url' => '', 'json_file_path' => '',
            'identifier_field' => '', 'filter_expression' => '', 'field_mapping' => '{}', 'active' => 1,
            'variant_mapping' => null, 'root_category_id' => null, 'id_lang_default' => 1, 'price_tax_included' => 0, 'deactivate_missing' => 0,
        ];
        $mapping = json_decode($source['field_mapping'], true);
        $rows = $this->submittedMapping;
        if ($rows === null) {
            $rows = [];
            foreach (is_array($mapping) ? $mapping : [] as $target => $expression) {
                $rows[] = ['target' => $target, 'expression' => $expression];
            }
        }
        $canonical = ['name', 'reference', 'price', 'short_description', 'description', 'ean13', 'weight', 'quantity', 'active', 'manufacturer', 'category_paths', 'images', 'main_image'];
        $fixed = [];
        foreach ($canonical as $target) {
            $fixed[$target] = ['target' => $target, 'expression' => '', 'badge' => $target === 'name' ? 'Required for new products' : (in_array($target, ['reference', 'price', 'category_paths', 'images'], true) ? 'Recommended' : 'Optional')];
        }
        $custom = [];
        $assigned = [];
        foreach ($rows as $row) {
            if (isset($fixed[$row['target']]) && !isset($assigned[$row['target']])) {
                $fixed[$row['target']]['expression'] = $row['expression'];
                $assigned[$row['target']] = true;
            } else {
                // Preserve custom rows and invalid duplicate submissions for correction.
                $custom[] = $row;
            }
        }
        $this->context->smarty->assign(['pi_fixed_rows' => $fixed, 'pi_mapping_rows' => $custom]);
        $variant = $this->submittedVariant ?? json_decode($source['variant_mapping'] ?: '{}', true);
        $variant = is_array($variant) ? $variant : [];
        $variantFields = [];
        foreach ($variant['fields'] ?? [] as $target => $expression) {
            $variantFields[] = ['target' => $target, 'expression' => $expression];
        }
        $this->context->smarty->assign([
            'pi_variants_expression' => $variant['variants_expression'] ?? '',
            'pi_attribute_rows' => $variant['attribute_rows'] ?? array_map(static function ($row) {
                return ['target' => $row['name'], 'expression' => $row['expression']];
            }, $variant['attributes'] ?? []),
            'pi_variant_field_rows' => $variant['field_rows'] ?? $variantFields,
        ]);
        $this->context->smarty->assign(['pi_manufacturer_url' => $this->context->link->getAdminLink('AdminPiManufacturerMap') . '&id_source=' . $id, 'pi_category_url' => $this->context->link->getAdminLink('AdminPiCategoryMap') . '&id_source=' . $id, 'pi_can_discover' => $this->access('edit'), 'pi_preview_id' => $id, 'pi_preview_url' => $this->context->link->getAdminLink('AdminPiSource')]);
        $testHtml = $this->context->smarty->fetch(dirname(__DIR__, 3) . '/views/templates/admin/preview.tpl');
        $mappingHtml = $this->context->smarty->fetch(dirname(__DIR__, 3) . '/views/templates/admin/source_form.tpl');
        $helpTemplate = $this->context->smarty->createTemplate(dirname(__DIR__, 3) . '/views/templates/admin/source_form.tpl');
        $helpTemplate->assign('pi_help_section', 'identifier');
        $identifierHelp = $helpTemplate->fetch();
        $helpTemplate->assign('pi_help_section', 'filter');
        $filterHelp = $helpTemplate->fetch();
        $inputs = [];
        foreach ([
            'name' => 'Name', 'technical_key' => 'Technical key', 'json_url' => 'JSON URL',
            'json_file_path' => 'JSON file path', 'identifier_field' => 'Identifier field',
        ] as $name => $label) {
            if ($name === 'identifier_field') {
                $inputs[] = ['type' => 'html', 'name' => 'source_test', 'html_content' => $testHtml];
                $inputs[] = ['type' => 'html', 'name' => 'identifier_picker', 'label' => $this->trans('Identifier source field'), 'html_content' => '<select id="pi-identifier-picker" disabled aria-label="Identifier source field"><option value="">Run Test source above to populate</option></select><p class="help-block">Choose a top-level JSON key. The raw identifier below stays editable.</p>'];
            }
            $inputs[] = [
                'type' => 'text', 'label' => $this->trans($label), 'name' => $name, 'id' => $name,
                'required' => in_array($name, ['name', 'technical_key'], true),
                'desc' => $name === 'identifier_field' ? $identifierHelp : '',
            ];
        }
        $inputs[] = ['type' => 'textarea', 'label' => $this->trans('Filter expression'), 'name' => 'filter_expression', 'desc' => $filterHelp];
        $inputs[] = ['type' => 'html', 'name' => 'mapping_rows', 'html_content' => $mappingHtml, 'label' => $this->trans('Field mapping')];
        $tree = new HelperTreeCategories('pi-root-category-tree', $this->trans('Root category'));
        $tree->setRootCategory((int) Configuration::get('PS_ROOT_CATEGORY'));
        $tree->setInputName('root_category_id');
        $tree->setUseCheckBox(false);
        $tree->setUseSearch(true);
        $tree->setSelectedCategories($source['root_category_id'] ? [(int) $source['root_category_id']] : []);
        $inputs[] = [
            'type' => 'html', 'name' => 'root_category_tree', 'label' => $this->trans('Root category'),
            'html_content' => $tree->render() . '<button type="button" class="btn btn-default" onclick="document.querySelectorAll(&quot;[name=root_category_id]&quot;).forEach(function (input) { input.checked = false; });">Use shop root</button>',
            'desc' => $this->trans('Leave unselected to create category chains under the shop root category.'),
        ];
        $inputs[] = [
            'type' => 'select', 'name' => 'id_lang_default', 'label' => $this->trans('Source language'),
            'options' => ['query' => Language::getLanguages(false), 'id' => 'id_lang', 'name' => 'name'],
        ];
        foreach (['active' => 'Active', 'price_tax_included' => 'Prices include tax', 'deactivate_missing' => 'Deactivate missing products'] as $field => $label) {
            $inputs[] = [
                'type' => 'switch', 'name' => $field, 'label' => $this->trans($label), 'is_bool' => true,
                'values' => [
                    ['id' => $field . '_on', 'value' => 1, 'label' => $this->trans('Yes')],
                    ['id' => $field . '_off', 'value' => 0, 'label' => $this->trans('No')],
                ],
                'desc' => $field === 'price_tax_included'
                    ? $this->trans('Tax conversion is not implemented yet: prices are currently stored unchanged.')
                    : ($field === 'deactivate_missing' ? $this->trans('After a successful fetch, untouched products are deactivated and untouched combinations receive zero stock.') : ''),
            ];
        }
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
            'legend' => ['title' => $this->trans('Import source')],
            'input' => $inputs,
            'submit' => ['title' => $this->trans('Save')],
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
        foreach (['active', 'price_tax_included', 'deactivate_missing'] as $field) {
            $data[$field] = (int) (Tools::getValue($field) === '1');
        }
        $root = Tools::getValue('root_category_id', '');
        $language = Tools::getValue('id_lang_default', '1');
        if (!is_string($root) || ($root !== '' && (!ctype_digit($root) || (int) $root <= 0))) {
            throw new InvalidArgumentException('Invalid root category ID.');
        }
        if (!is_string($language) || !ctype_digit($language) || (int) $language <= 0) {
            throw new InvalidArgumentException('Invalid source language ID.');
        }
        $data['root_category_id'] = $root === '' ? null : (int) $root;
        $data['id_lang_default'] = (int) $language;
        $this->submittedSource = $data;
        $variantExpression = Tools::getValue('variants_expression', '');
        if (!is_string($variantExpression)) {
            throw new InvalidArgumentException('Invalid variants expression.');
        }
        $this->submittedVariant = ['variants_expression' => trim($variantExpression), 'attribute_rows' => [], 'field_rows' => []];
        $data['variant_mapping'] = null;
        if (trim($variantExpression) !== '') {
            $this->submittedVariant['attribute_rows'] = $this->readRows('variant_attribute');
            $this->submittedVariant['field_rows'] = $this->readRows('variant_field');
        }
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
        if ($this->submittedVariant['variants_expression'] !== '') {
            $attributes = $this->rowsToMapping($this->submittedVariant['attribute_rows']);
            $fields = $this->rowsToMapping($this->submittedVariant['field_rows']);
            if ($attributes === [] || !isset($fields['reference'])) {
                throw new InvalidArgumentException('Variants require at least one attribute and a reference field mapping.');
            }
            $definitions = [];
            foreach ($attributes as $name => $expression) {
                $definitions[] = ['name' => (string) $name, 'expression' => $expression];
            }
            $data['variant_mapping'] = json_encode([
                'variants_expression' => $this->submittedVariant['variants_expression'],
                'attributes' => $definitions, 'fields' => (object) $fields,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($data['variant_mapping'] === false) {
                throw new InvalidArgumentException('Unable to encode variant mapping: ' . json_last_error_msg());
            }
        }
        if ($data['root_category_id'] !== null && !Validate::isLoadedObject(new Category($data['root_category_id']))) {
            throw new InvalidArgumentException('Selected root category does not exist.');
        }
        $languageIds = array_map('intval', array_column(Language::getLanguages(false), 'id_lang'));
        if (!in_array($data['id_lang_default'], $languageIds, true)) {
            throw new InvalidArgumentException('Selected source language does not exist.');
        }
        foreach (['name' => 191, 'technical_key' => 64] as $field => $limit) {
            if ($data[$field] === '' || Tools::strlen($data[$field]) > $limit) {
                throw new InvalidArgumentException($field . ' is required and must not exceed ' . $limit . ' characters.');
            }
        }
        if (Tools::strlen($data['identifier_field']) > 191) {
            throw new InvalidArgumentException('identifier_field must not exceed 191 characters.');
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

    private function readRows(string $prefix): array
    {
        $targets = Tools::getValue($prefix . '_target', []);
        $expressions = Tools::getValue($prefix . '_expression', []);
        if (!is_array($targets) || !is_array($expressions) || array_keys($targets) !== array_keys($expressions)) {
            throw new InvalidArgumentException('Invalid variant mapping rows.');
        }
        $rows = [];
        foreach ($targets as $index => $target) {
            if (!is_string($target) || !is_string($expressions[$index])) {
                throw new InvalidArgumentException('Variant mapping rows must contain strings.');
            }
            $rows[] = ['target' => $target, 'expression' => $expressions[$index]];
        }

        return $rows;
    }

    private function rowsToMapping(array $rows): array
    {
        $mapping = [];
        foreach ($rows as $row) {
            $target = trim($row['target']);
            $expression = trim($row['expression']);
            if ($target === '' && $expression === '') {
                continue;
            }
            if ($target === '' || $expression === '' || array_key_exists($target, $mapping)) {
                throw new InvalidArgumentException('Variant rows require a unique name/target and an expression.');
            }
            $mapping[$target] = $expression;
        }

        return $mapping;
    }
}
