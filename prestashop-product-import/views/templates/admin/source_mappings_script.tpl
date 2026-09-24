{literal}
<script>
(function () {
  function init() {
    var source = document.getElementById('pi-preview');
    var form = source.closest('form');
    var id = Number(source.dataset.source);
    var states = {};
    function post(action, type, values) {
      var body = new URLSearchParams({ajax: '1', action: action, id_source: String(id), type: type});
      Object.keys(values || {}).forEach(function (key) { body.set(key, values[key]); });
      return fetch(source.dataset.url, {method: 'POST', credentials: 'same-origin', body: body})
        .then(function (response) { return response.json(); })
        .then(function (data) { if (data.error) throw new Error(data.error); return data; });
    }
    function select(options, value, empty) {
      var element = document.createElement('select');
      element.className = 'form-control';
      element.add(new Option(value == null ? empty : (options.find(function (option) { return Number(option.id) === Number(value); }) || {}).label || 'Unavailable #' + value, value == null ? '' : String(value)));
      function expand() {
        if (element.dataset.expanded) return;
        element.dataset.expanded = '1';
        var selected = element.value;
        element.replaceChildren(new Option(empty, ''));
        options.forEach(function (option) { element.add(new Option(option.label, String(option.id))); });
        if (selected && !options.some(function (option) { return String(option.id) === selected; })) element.add(new Option('Unavailable #' + selected, selected));
        element.value = selected;
      }
      element.addEventListener('focus', expand);
      element.addEventListener('mousedown', expand);
      element.addEventListener('keydown', function (event) { if (event.key === 'Enter') event.preventDefault(); else expand(); });
      return element;
    }
    function load(type) {
      var state = states[type];
      if (!id || state.loading) return;
      state.loading = true;
      state.pane.textContent = 'Loading…';
      post('mappings', type).then(function (data) {
        state.loaded = true;
        state.pane.replaceChildren();
        var help = document.createElement('p');
        help.textContent = data.rows.length + ' discovered. Changes here are saved immediately, independent of the main Save button.';
        state.pane.appendChild(help);
        var refresh = document.createElement('button');
        refresh.type = 'button'; refresh.className = 'btn btn-default'; refresh.textContent = 'Refresh from source';
        refresh.addEventListener('click', function () { source.querySelector('.pi-test-source').click(); });
        state.pane.appendChild(refresh);
        var label = document.createElement('label'); label.textContent = 'Default for anything not mapped below';
        state.pane.appendChild(label);
        var defaultSelect = select(data.options, data.default, 'Auto-create (no default)');
        label.appendChild(defaultSelect);
        function save(control, action, key, indicator, status) {
          control.addEventListener('change', function () {
            var value = control.value;
            control.disabled = true; indicator.textContent = 'Saving…';
            post(action, type, key == null ? {value: value} : {key: key, value: value})
              .then(function (result) { indicator.textContent = 'Saved'; if (status) status.textContent = result.status; if (action === 'saveDefault') { state.loading = false; load(type); } })
              .catch(function (error) { indicator.textContent = error.message; })
              .then(function () { control.disabled = false; });
          });
        }
        var defaultIndicator = document.createElement('span'); defaultIndicator.setAttribute('aria-live', 'polite'); label.appendChild(defaultIndicator);
        save(defaultSelect, 'saveDefault', null, defaultIndicator, null);
        if (!data.rows.length) {
          var empty = document.createElement('p');
          empty.textContent = 'Nothing discovered yet. Map ' + (type === 'category' ? 'category_paths' : 'manufacturer') + ' on the Product fields tab, save, then click Refresh from source.';
          state.pane.appendChild(empty); return;
        }
        var table = document.createElement('table'); table.className = 'table';
        var header = table.createTHead().insertRow();
        ['Source label', 'Current status', 'Override'].forEach(function (title) { var cell = document.createElement('th'); cell.textContent = title; header.appendChild(cell); });
        var body = table.createTBody();
        data.rows.forEach(function (row) {
          var tr = body.insertRow(); tr.insertCell().textContent = row.label;
          var status = tr.insertCell(); status.textContent = row.status;
          var cell = tr.insertCell();
          var control = select(data.options, row.id, 'Use default (auto-create if none)');
          control.setAttribute('aria-label', 'Override for ' + row.label);
          cell.appendChild(control);
          var indicator = document.createElement('span'); indicator.setAttribute('aria-live', 'polite'); cell.appendChild(indicator);
          save(control, 'saveMapping', row.key, indicator, status);
        });
        state.pane.appendChild(table);
      }).catch(function (error) { state.pane.textContent = error.message; }).then(function () { state.loading = false; });
    }
    ['category', 'brand'].forEach(function (type) {
      var pane = document.getElementById(type === 'category' ? 'pi-category-mappings' : 'pi-brands');
      states[type] = {pane: pane, loaded: false, loading: false};
      if (!id) pane.textContent = 'Save the source first.';
    });
    document.addEventListener('pi-source-tab', function (event) {
      var type = event.detail === 'pi-categories' ? 'category' : event.detail === 'pi-brands' ? 'brand' : null;
      if (type && !states[type].loaded) load(type);
    });
    document.addEventListener('pi-test-source-complete', function () {
      ['category', 'brand'].forEach(function (type) { load(type); });
    });
    var active = document.querySelector('#pi-source-tabs > .tab-content > .tab-pane.active');
    if (active) document.dispatchEvent(new CustomEvent('pi-source-tab', {detail: active.id}));
    form.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && event.target.closest('#pi-categories, #pi-brands')) event.preventDefault();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
</script>
{/literal}
