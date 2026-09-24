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
    var identifier = form.querySelector('[name="identifier_field"]');
    var identifierPicker = document.getElementById('pi-identifier-picker');
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
    function expression(parts) {
      if (parts.length === 1) return 'fields[' + quote(parts[0]) + ']';
      // path() splits on dots and has no escape syntax for a literal dot in a key.
      if (parts.every(function (part) { return String(part).indexOf('.') === -1; })) {
        return 'path(fields, ' + quote(parts.join('.')) + ')';
      }
      return 'fields' + parts.map(function (part) { return '[' + quote(part) + ']'; }).join('');
    }
    function label(parts) {
      return parts.map(function (part, index) {
        if (typeof part === 'number') return '[' + part + ']';
        return /^[A-Za-z_][A-Za-z0-9_]*$/.test(part) ? (index ? '.' : '') + part : '[' + JSON.stringify(part) + ']';
      }).join('');
    }
    function fill(select, entries) {
      select.replaceChildren(new Option(entries.length ? 'Choose a source field' : 'Run Test source above to populate', ''));
      entries.forEach(function (entry) { select.add(new Option(entry.label, entry.value)); });
      select.disabled = !entries.length;
    }
    function populate(fields) {
      window.piSourceFields = fields;
      var seen = new Map();
      fields.forEach(function (field) {
        var parts = segments(field.key);
        // Include parent containers so images/breadcrumbs can be mapped as entire lists.
        for (var length = 1; length <= parts.length; length++) {
          var prefix = parts.slice(0, length);
          seen.set(JSON.stringify(prefix), {parts: prefix, label: label(prefix), value: expression(prefix)});
        }
      });
      choices = Array.from(seen.values());
      fixed.forEach(function (row) {
        fill(row.querySelector('.pi-source-field'), choices);
        fill(row.querySelector('.pi-insert-field'), choices);
        row.querySelector('.pi-insert-button').disabled = true;
      });
      fill(identifierPicker, choices.filter(function (choice) { return choice.parts.length === 1; }).map(function (choice) {
        return {label: choice.label, value: String(choice.parts[0])};
      }));
    }
    identifierPicker.addEventListener('change', function () {
      if (this.value !== '') {
        identifier.value = this.value;
        identifier.dispatchEvent(new Event('input', {bubbles: true}));
      }
    });
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
      row.querySelector('.pi-source-field').addEventListener('change', function () {
        if (!this.value) return;
        textarea.value = this.value;
        textarea.focus();
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);
        remember();
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
      });
      insert.addEventListener('change', function () { button.disabled = !this.value; });
      button.addEventListener('click', function () {
        if (!insert.value) return;
        textarea.focus();
        textarea.setSelectionRange(start, end);
        textarea.setRangeText(insert.value, start, end, 'end');
        remember();
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
      });
      sync();
      form.addEventListener('submit', sync);
    });

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
    function discovery(container, title, data, linkTitle) {
      section(container, title, data);
      if (data.mapping_url) {
        var link = document.createElement('a');
        link.href = data.mapping_url;
        link.textContent = linkTitle;
        container.appendChild(link);
      }
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
              result(categories, 'unique_paths', 'category path', 'category paths'),
              result(brands, 'unique_brands', 'brand', 'brands')
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
            discovery(details, 'Category discovery', categories, 'Open category mappings');
            discovery(details, 'Brand discovery', brands, 'Open brand mappings');
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
        .then(function () { clicked.disabled = false; });
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
