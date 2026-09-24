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
    var timer = null;
    var alive = true;
    window.addEventListener('pagehide', function () { alive = false; if (timer) clearTimeout(timer); });
    function request(action, id, baseline) {
      var body = new URLSearchParams({ajax: '1', action: action, id_source: id});
      if (baseline !== undefined) body.set('baseline_run_id', String(baseline));
      return fetch(panel.dataset.url, {method: 'POST', credentials: 'same-origin', body: body})
        .then(function (response) { return response.json().then(function (data) { return {status: response.status, data: data}; }); });
    }
    function render(data, message) {
      output.textContent = '';
      if (message) {
        var notice = document.createElement('p');
        notice.textContent = message;
        output.appendChild(notice);
      }
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
      if (!message && (!data.runs || !data.runs.length)) output.textContent = 'No active sources to run.';
      if (data.runs_url) {
        var link = document.createElement('a');
        link.href = data.runs_url;
        link.textContent = 'View runs log';
        output.appendChild(link);
      }
    }
    function poll(id, baseline, runsUrl, initial) {
      request('importStatus', id, baseline).then(function (response) {
        if (!alive) return;
        if (response.data.error) throw new Error(response.data.error);
        var data = response.data;
        data.runs_url = runsUrl;
        render(data, data.running ? (initial ? 'An import is currently running.' : 'Import started in the background — you can leave this page.') : '');
        button.disabled = data.running;
        if (data.running) timer = setTimeout(function () { poll(id, baseline, runsUrl, false); }, 3000);
      }).catch(function (error) {
        if (!alive) return;
        output.textContent = 'Import status failed: ' + error.message;
        button.disabled = false;
      });
    }
    var initialId = select ? select.value : panel.dataset.source;
    if (initialId && initialId !== '0' && !button.disabled) poll(initialId, Number(panel.dataset.baseline), null, true);
    button.addEventListener('click', function () {
      var selected = select && select.selectedOptions[0];
      var id = selected ? selected.value : panel.dataset.source;
      var label = selected ? (id === 'all' ? 'all active sources' : selected.dataset.name) : panel.dataset.name;
      var deactivate = id !== 'all' && (selected ? selected.dataset.deactivate === '1' : panel.dataset.deactivate === '1');
      if (!confirmRun(label, deactivate)) return;
      button.disabled = true;
      output.textContent = '';
      request('runImport', id).then(function (response) {
        var data = response.data;
        if (response.status === 409) throw new Error('Import already running or lock unavailable.');
        if (data.error) throw new Error(data.error);
        if (data.mode === 'background') {
          render(data, 'Import started in the background — you can leave this page.');
          timer = setTimeout(function () { poll(id, data.baseline_run_id, data.runs_url, false); }, 3000);
        } else {
          render(data, '');
          button.disabled = false;
        }
      }).catch(function (error) {
        output.textContent = 'Import request failed: ' + error.message;
        button.disabled = false;
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
</script>
{/literal}
