{literal}
<script>
(function () {
  'use strict';
  function confirmRun(label, deactivate) {
    return window.confirm('Run import now for ' + label + '?' + (deactivate ? '\nProducts missing from this feed will be deactivated.' : ''));
  }
  function init() {
    var panel = document.getElementById('pi-run-list') || document.getElementById('pi-run-editor');
    if (!panel || panel.dataset.ready) return;
    panel.dataset.ready = '1';
    var select = document.getElementById('pi-run-source');
    var button = panel.querySelector('button');
    var output = panel.querySelector('[aria-live]');
    button.addEventListener('click', function () {
      var selected = select && select.selectedOptions[0];
      var id = selected ? selected.value : panel.dataset.source;
      var label = selected ? (id === 'all' ? 'all active sources' : selected.dataset.name) : panel.dataset.name;
      var deactivate = id !== 'all' && (selected ? selected.dataset.deactivate === '1' : panel.dataset.deactivate === '1');
      if (!confirmRun(label, deactivate)) return;
      button.disabled = true;
      var original = button.textContent;
      button.textContent = 'Running... (this can take a while for image downloads)';
      output.textContent = '';
      var body = new URLSearchParams({ajax: '1', action: 'runImport', id_source: id});
      fetch(panel.dataset.url, {method: 'POST', credentials: 'same-origin', body: body})
        .then(function (response) { return response.json().then(function (data) { return {status: response.status, data: data}; }); })
        .then(function (response) {
          var data = response.data;
          if (response.status === 409) { output.textContent = 'Import already running or lock unavailable.'; return; }
          if (data.error) { output.textContent = data.error; return; }
          (data.runs || []).forEach(function (run) {
            var row = document.createElement('p');
            var counts = run.counts || {};
            row.textContent = run.name + ': ' + run.status + ' — created ' + counts.created + ', updated ' + counts.updated + ', skipped ' + counts.skipped + ', failed ' + counts.failed;
            output.appendChild(row);
            if (run.error_log) {
              var details = document.createElement('details');
              var summary = document.createElement('summary');
              summary.textContent = 'Error log';
              details.appendChild(summary);
              var log = document.createElement('pre');
              log.textContent = run.error_log;
              details.appendChild(log);
              output.appendChild(details);
            }
          });
          if (!data.runs || !data.runs.length) output.textContent = 'No active sources to run.';
          var link = document.createElement('a');
          link.href = data.runs_url;
          link.textContent = 'View runs log';
          output.appendChild(link);
        })
        .catch(function (error) { output.textContent = 'Import request failed: ' + error.message; })
        .then(function () { button.disabled = false; button.textContent = original; });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
</script>
{/literal}
