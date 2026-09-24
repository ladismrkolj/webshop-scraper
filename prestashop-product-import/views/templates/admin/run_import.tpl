<div id="pi-run-list" class="panel" data-url="{$pi_run_url|escape:'html':'UTF-8'}">
  <h3>Run import</h3>
  <label for="pi-run-source">Sources</label>
  <select id="pi-run-source">
    <option value="all">All active sources</option>
    {foreach from=$pi_run_sources item=source}
    <option value="{$source.id_source|intval}" data-name="{$source.name|escape:'html':'UTF-8'}" data-deactivate="{$source.deactivate_missing|intval}">{$source.name|escape:'html':'UTF-8'}{if !$source.active} (inactive){/if}</option>
    {/foreach}
  </select>
  <button type="button" id="pi-run-list-button" class="btn btn-default">Run import now</button>
  <div id="pi-run-list-results" aria-live="polite"></div>
</div>
{include file='./run_import_script.tpl'}
