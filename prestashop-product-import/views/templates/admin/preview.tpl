<div id="pi-preview" data-url="{$pi_preview_url|escape:'html':'UTF-8'}" data-source="{$pi_preview_id|intval}">
  <h3>Preview saved source</h3>
  <p>Save configuration changes first. Preview reads catalog data but does not create or update records. Only the first five variants are shown.</p>
  <label>Input mode <select class="pi-mode"><option value="index">Source item index</option><option value="raw">Pasted JSON object</option></select></label>
  <label>Item index <input class="pi-index" type="number" min="0" value="0"></label>
  <label>JSON object <textarea class="pi-raw" rows="5"></textarea></label>
  <button type="button" class="btn btn-default pi-preview-button" {if !$pi_preview_id}disabled{/if}>Preview</button>
  <div class="pi-results" aria-live="polite"></div>
</div>
{literal}
<script>
(function () {
  var panel = document.getElementById('pi-preview');
  var button = panel.querySelector('.pi-preview-button');
  var results = panel.querySelector('.pi-results');
  function section(title, value) {
    var heading = document.createElement('h4');
    heading.textContent = title;
    results.appendChild(heading);
    var table = document.createElement('table');
    table.className = 'table';
    Object.keys(value || {}).forEach(function (key) {
      var row = table.insertRow();
      row.insertCell().textContent = key;
      var pre = document.createElement('pre');
      pre.textContent = JSON.stringify(value[key], null, 2);
      row.insertCell().appendChild(pre);
    });
    results.appendChild(table);
  }
  function mapping(title, data) {
    var rows = {};
    Object.keys(data.values).forEach(function (key) {
      rows[key] = {value: data.values[key], error: data.errors[key] || null};
    });
    Object.keys(data.errors).forEach(function (key) {
      if (!Object.prototype.hasOwnProperty.call(rows, key)) rows[key] = {error: data.errors[key]};
    });
    section(title, rows);
  }
  button.addEventListener('click', function () {
    button.disabled = true;
    results.textContent = 'Loading preview…';
    var body = new URLSearchParams();
    body.set('ajax', '1');
    body.set('action', 'preview');
    body.set('id_source', panel.dataset.source);
    if (panel.querySelector('.pi-mode').value === 'raw') body.set('raw_item', panel.querySelector('.pi-raw').value);
    else body.set('item_index', panel.querySelector('.pi-index').value);
    fetch(panel.dataset.url, {method: 'POST', credentials: 'same-origin', body: body})
      .then(function (response) { return response.json(); })
      .then(function (data) {
        results.textContent = '';
        if (data.error) { section('Error', {error: data.error}); return; }
        section('Filter', data.filter);
        mapping('Base fields', data.base);
        section('Category paths (auto_create means would create)', data.categories);
        section('Manufacturer (auto_create means would create)', data.manufacturer);
        section('Errors', data.errors);
        section('Variants', {total: data.variants.total, truncated: data.variants.truncated});
        data.variants.items.forEach(function (variant) {
          mapping('Variant ' + variant.index + ' fields', variant.fields);
          mapping('Variant ' + variant.index + ' attributes', variant.attributes);
          section('Attribute resolution (create_group/create_value means would create)', variant.resolutions);
        });
      })
      .catch(function (error) { results.textContent = 'Preview failed: ' + error.message; })
      .then(function () { button.disabled = false; });
  });
}());
</script>
{/literal}
