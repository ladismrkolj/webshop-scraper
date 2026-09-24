{literal}
<script>
(function () {
  'use strict';
  function init() {
    var panel = document.getElementById('pi-preview');
    if (!panel || panel.dataset.editorReady) return;
    panel.dataset.editorReady = '1';
    var form = panel.closest('form');
    var fixed = Array.from(form.querySelectorAll('.pi-fixed-row'));
    var variantRows = Array.from(form.querySelectorAll('.pi-variant-row'));
    var attributes = document.getElementById('pi-attribute-rows');
    var variantsList = document.getElementById('pi-variants-list');
    var variantsExpression = document.getElementById('pi-variants-expression');
    var variantMappings = document.getElementById('pi-variant-mappings');
    var variantChoices = [], listChoices = [];
    var identifier = form.querySelector('[name="identifier_field"]');
    var identifierPicker = document.getElementById('pi-identifier-picker');
    var identifierTouched = false;
    var identifierChoice = '';
    var choices = [];
    // Retained for all selectors: testing a configuration does not refetch/replace this list.
    window.piSourceFields = [];

    function segments(key) {
      var parts = [], position = 0, match;
      while (position < key.length) {
        var tail = key.slice(position);
        if ((match = /^(?:\.)?([A-Za-z_][A-Za-z0-9_]*)/.exec(tail))) parts.push(match[1]);
        else if ((match = /^\[(\d+)\]/.exec(tail))) parts.push(Number(match[1]));
        else if ((match = /^\[("(?:\\.|[^"\\])*")\]/.exec(tail))) parts.push(JSON.parse(match[1]));
        else throw new Error('Unsupported field key: ' + key);
        position += match[0].length;
      }
      return parts;
    }
    function quote(value) {
      return "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
    }
    function expression(parts, root) {
      root = root || 'fields';
      if (parts.length === 1) return root + '[' + quote(parts[0]) + ']';
      // path() splits on dots and has no escape syntax for a literal dot in a key.
      if (parts.every(function (part) { return String(part).indexOf('.') === -1; })) {
        return 'path(' + root + ', ' + quote(parts.join('.')) + ')';
      }
      return root + parts.map(function (part) { return '[' + quote(part) + ']'; }).join('');
    }
    function label(parts) {
      return parts.map(function (part, index) {
        if (typeof part === 'number') return '[' + part + ']';
        return /^[A-Za-z_][A-Za-z0-9_]*$/.test(part) ? (index ? '.' : '') + part : '[' + JSON.stringify(part) + ']';
      }).join('');
    }
    function fill(select, entries, empty, custom) {
      select.replaceChildren(new Option(empty, ''));
      entries.forEach(function (entry) { select.add(new Option(entry.label, entry.value)); });
      if (custom) select.add(new Option('Custom expression…', '__custom__'));
    }
    function setSelection(row, entries) {
      var select = row.querySelector('.pi-mapping-select, .pi-source-field');
      var input = row.querySelector('textarea');
      var wasCustom = row.dataset.custom === '1';
      var match = entries.find(function (entry) { return entry.value === input.value; });
      select.value = wasCustom ? '__custom__' : (input.value ? (match ? match.value : '__custom__') : '');
      if (input.value && !select.value) select.value = '__custom__';
      var controls = row.querySelector('.pi-custom-controls');
      (controls || input).style.display = select.value === '__custom__' ? '' : 'none';
      if (!controls) input.style.display = select.value === '__custom__' ? '' : 'none';
      var target = row.querySelector('.pi-fixed-target, .pi-variant-target');
      if (target) target.value = input.value.trim() ? row.dataset.target : '';
    }
    function configureRow(row, entries) {
      var select = row.querySelector('.pi-mapping-select, .pi-source-field');
      var input = row.querySelector('textarea');
      row.piEntries = entries;
      fill(select, entries, '— not mapped —', true);
      setSelection(row, entries);
      if (row.dataset.ready) return;
      row.dataset.ready = '1';
      select.addEventListener('change', function () {
        row.dataset.touched = '1';
        row.dataset.custom = select.value === '__custom__' ? '1' : '0';
        if (select.value !== '__custom__') input.value = select.value;
        if (select.value === '__custom__') {
          var controls = row.querySelector('.pi-custom-controls');
          (controls || input).style.display = '';
          input.focus();
        } else setSelection(row, row.piEntries);
        input.dispatchEvent(new Event('input', {bubbles: true}));
      });
      input.addEventListener('input', function () {
        row.dataset.touched = '1';
        var target = row.querySelector('.pi-fixed-target, .pi-variant-target');
        if (target) target.value = input.value.trim() ? row.dataset.target : '';
      });
    }
    function updateVariantMappings() {
      variantMappings.style.display = variantsExpression.value.trim() ? '' : 'none';
    }
    function updateLists() {
      var currentList = variantsList.value;
      fill(variantsList, listChoices, '— no variants —', true);
      if (variantsList.dataset.touched) variantsList.value = currentList;
      if (!variantsList.dataset.touched) {
        var match = listChoices.find(function (entry) { return entry.value === variantsExpression.value; });
        variantsList.value = variantsExpression.value ? (match ? match.value : '__custom__') : '';
      }
      variantsExpression.style.display = variantsList.value === '__custom__' ? '' : 'none';
      updateVariantMappings();
    }
    function populate(fields) {
      window.piSourceFields = fields;
      var seen = new Map();
      var lists = new Map();
      fields.forEach(function (field) {
        var parts = segments(field.key);
        for (var length = 1; length <= parts.length; length++) {
          var prefix = parts.slice(0, length);
          seen.set(JSON.stringify(prefix), {parts: prefix, label: label(prefix), value: expression(prefix, 'fields')});
        }
        var index = parts.findIndex(function (part) { return part === 0; });
        if (index > 0 && index < parts.length - 1) lists.set(JSON.stringify(parts.slice(0, index)), parts.slice(0, index));
      });
      choices = Array.from(seen.values());
      listChoices = Array.from(lists.values()).map(function (parts) { return {parts: parts, label: label(parts), value: expression(parts, 'fields')}; });
      var selected = listChoices.find(function (entry) { return entry.value === variantsExpression.value; });
      var listParts = selected && selected.parts;
      var itemSeen = new Map();
      if (listParts) fields.forEach(function (field) {
        var parts = segments(field.key);
        if (JSON.stringify(parts.slice(0, listParts.length)) !== JSON.stringify(listParts) || parts[listParts.length] !== 0) return;
        var item = parts.slice(listParts.length + 1);
        for (var length = 1; length <= item.length; length++) {
          var prefix = item.slice(0, length);
          itemSeen.set(JSON.stringify(prefix), {label: label(prefix), value: expression(prefix, 'variant')});
        }
      });
      variantChoices = Array.from(itemSeen.values());
      var grouped = variantChoices.concat(choices.map(function (entry) { return {label: 'Product: ' + entry.label, value: entry.value}; }));
      fixed.forEach(function (row) {
        configureRow(row, choices);
        fill(row.querySelector('.pi-insert-field'), choices, 'Insert field at cursor', false);
        row.querySelector('.pi-insert-button').disabled = true;
      });
      variantRows.forEach(function (row) { configureRow(row, grouped); });
      Array.from(attributes.children).forEach(function (row) { configureRow(row, grouped); });
      updateLists();
      fill(identifierPicker, choices.filter(function (choice) { return choice.parts.length === 1; }).map(function (choice) {
        return {label: choice.label, value: String(choice.parts[0])};
      }), '— choose —', false);
      identifierPicker.add(new Option('Other key…', '__other__'));
      var selectedIdentifier = identifierTouched ? identifierChoice : identifier.value;
      identifierPicker.value = selectedIdentifier === '' ? '' :
        Array.from(identifierPicker.options).some(function (option) { return option.value === selectedIdentifier; }) ? selectedIdentifier : '__other__';
      identifier.style.display = identifierPicker.value === '__other__' ? '' : 'none';
    }
    identifierPicker.addEventListener('change', function () {
      identifierTouched = true;
      identifierChoice = this.value;
      if (this.value !== '__other__') identifier.value = this.value;
      identifier.style.display = this.value === '__other__' ? '' : 'none';
      if (this.value === '__other__') identifier.focus();
    });
    identifier.addEventListener('input', function () { identifierTouched = true; identifierChoice = '__other__'; });
    fixed.forEach(function (row) {
      var textarea = row.querySelector('textarea');
      var target = row.querySelector('.pi-fixed-target');
      var insert = row.querySelector('.pi-insert-field');
      var button = row.querySelector('.pi-insert-button');
      var start = textarea.value.length, end = start;
      function remember() { start = textarea.selectionStart; end = textarea.selectionEnd; }
      function sync() { target.value = textarea.value.trim() ? row.dataset.target : ''; }
      ['select', 'keyup', 'click', 'input', 'blur'].forEach(function (event) { textarea.addEventListener(event, remember); });
      textarea.addEventListener('input', sync);
      insert.addEventListener('change', function () { button.disabled = !this.value; });
      button.addEventListener('click', function () {
        if (!insert.value) return;
        textarea.focus();
        textarea.setSelectionRange(start, end);
        textarea.setRangeText(insert.value, start, end, 'end');
        remember();
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
      });
      configureRow(row, choices);
      form.addEventListener('submit', sync);
    });

    variantsList.addEventListener('change', function () {
      variantsList.dataset.touched = '1';
      if (variantsList.value !== '__custom__') variantsExpression.value = variantsList.value;
      variantsExpression.style.display = variantsList.value === '__custom__' ? '' : 'none';
      if (variantsList.value === '__custom__') variantsExpression.focus();
      updateVariantMappings();
      populate(window.piSourceFields);
    });
    variantsExpression.addEventListener('input', updateVariantMappings);
    document.getElementById('pi-add-attribute').addEventListener('click', function () {
      var row = attributes.firstElementChild.cloneNode(true);
      row.querySelector('input').value = '';
      row.querySelector('textarea').value = '';
      delete row.dataset.ready;
      delete row.dataset.touched;
      delete row.dataset.custom;
      attributes.appendChild(row);
      populate(window.piSourceFields);
    });
    attributes.addEventListener('click', function (event) {
      if (event.target.classList.contains('pi-remove-attribute')) {
        if (attributes.children.length > 1) event.target.closest('.pi-attribute-row').remove();
        else {
          attributes.firstElementChild.querySelector('input').value = '';
          attributes.firstElementChild.querySelector('textarea').value = '';
          delete attributes.firstElementChild.dataset.touched;
          delete attributes.firstElementChild.dataset.custom;
          configureRow(attributes.firstElementChild, variantChoices.concat(choices));
        }
      }
    });
    populate([]);

    function section(container, title, values) {
      var heading = document.createElement('h4');
      heading.textContent = title;
      container.appendChild(heading);
      var table = document.createElement('table');
      table.className = 'table';
      Object.keys(values || {}).forEach(function (key) {
        var row = table.insertRow();
        row.insertCell().textContent = key;
        var pre = document.createElement('pre');
        pre.textContent = JSON.stringify(values[key], null, 2);
        row.insertCell().appendChild(pre);
      });
      container.appendChild(table);
    }
    function mapping(container, title, data) {
      var rows = Object.create(null);
      Object.keys(data.values).forEach(function (key) { rows[key] = {value: data.values[key], error: data.errors[key] || null}; });
      Object.keys(data.errors).forEach(function (key) { if (!rows[key]) rows[key] = {error: data.errors[key]}; });
      section(container, title, rows);
    }
    function discovery(container, title, data) {
      section(container, title, data);
    }
    var test = panel.querySelector('.pi-test-source');
    var configuration = form.querySelector('.pi-preview-button');
    function request(action, clicked) {
      var sourceTest = action === 'testSource';
      var output = form.querySelector(sourceTest ? '.pi-source-results' : '.pi-config-results');
      clicked.disabled = true;
      output.textContent = 'Testing…';
      if (sourceTest) populate([]);
      var body = new URLSearchParams();
      body.set('ajax', '1');
      body.set('action', action);
      if (Number(panel.dataset.source) > 0) body.set('id_source', panel.dataset.source);
      if (sourceTest) {
        body.set('json_url', form.querySelector('[name="json_url"]').value);
        body.set('json_file_path', form.querySelector('[name="json_file_path"]').value);
      }
      body.set('item_index', '0');
      fetch(panel.dataset.url, {method: 'POST', credentials: 'same-origin', body: body})
        .then(function (response) { return response.json(); })
        .then(function (data) {
          output.textContent = '';
          if (data.error) {
            if (sourceTest) output.textContent = data.error;
            else section(output, 'Error', {error: data.error});
            return;
          }
          if (sourceTest) {
            var inspection = data.inspection || {};
            var categories = data.categories || {};
            var brands = data.brands || {};
            var status = document.createElement('p');
            var count = function (value, singular, plural) { return value + ' ' + (value === 1 ? singular : plural); };
            var result = function (sectionData, key, singular, plural) {
              if (sectionData.error) return sectionData.error;
              if (sectionData.skipped) return sectionData.skipped;
              return count(sectionData[key], singular, plural);
            };
            status.textContent = 'OK - ' + [
              count(data.item_count, 'item', 'items'),
              inspection.error || count((inspection.fields || []).length, 'field', 'fields'),
              (categories.skipped || categories.error ? 'categories: ' : '') + result(categories, 'unique_paths', 'category path', 'category paths'),
              (brands.skipped || brands.error ? 'brands: ' : '') + result(brands, 'unique_brands', 'brand', 'brands')
            ].join(', ');
            output.appendChild(status);
            var details = document.createElement('details');
            var summary = document.createElement('summary');
            summary.textContent = 'Details';
            details.appendChild(summary);
            section(details, 'Source status', {connectivity: data.connectivity, item_count: data.item_count});
            if (inspection.error) section(details, 'Inspection error', inspection);
            else {
              populate(inspection.fields);
              var fields = Object.create(null);
              inspection.fields.forEach(function (field) { fields[field.key] = field.value; });
              section(details, 'Available fields', fields);
              section(details, 'Full sample JSON', {json: inspection.json});
              if (inspection.truncated) section(details, 'Display limits', {notice: 'Field list capped at depth 4, 3 entries per array, 100 rows. Expressions may also use other keys.'});
            }
            discovery(details, 'Category discovery', categories);
            discovery(details, 'Brand discovery', brands);
            output.appendChild(details);
            return;
          }
          section(output, 'Filter', data.filter);
          mapping(output, 'Base fields', data.base);
          section(output, 'Category paths', data.categories);
          section(output, 'Manufacturer', data.manufacturer);
          section(output, 'Errors', data.errors);
          section(output, 'Variants', {total: data.variants.total, truncated: data.variants.truncated});
          data.variants.items.forEach(function (variant) {
            mapping(output, 'Variant ' + variant.index + ' fields', variant.fields);
            mapping(output, 'Variant ' + variant.index + ' attributes', variant.attributes);
            section(output, 'Attribute resolution', variant.resolutions);
          });
        })
        .catch(function (error) { output.textContent = 'Test failed: ' + error.message; })
        .then(function () { clicked.disabled = false; if (sourceTest) document.dispatchEvent(new Event('pi-test-source-complete')); });
    }
    test.addEventListener('click', function () { request('testSource', test); });
    configuration.addEventListener('click', function () { request('preview', configuration); });
    if (Number(panel.dataset.source) > 0 && (form.querySelector('[name="json_url"]').value.trim() || form.querySelector('[name="json_file_path"]').value.trim())) {
      request('testSource', test);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
</script>
{/literal}
