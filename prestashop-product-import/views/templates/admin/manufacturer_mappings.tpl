<div class="panel">
  <h3>Brand mappings: {$pi_source.name|escape:'html':'UTF-8'} ({$pi_rows|count} discovered)</h3>
  <p><a href="{$pi_back|escape:'html':'UTF-8'}">Back to source / test source</a></p>
  <p>Explicit overrides take priority over the source default. With no default, names are matched or auto-created. Discovery preserves existing overrides.</p>
  {if $pi_can_edit}
  <form method="post" action="{$pi_action|escape:'html':'UTF-8'}">
    <input type="hidden" name="id_source" value="{$pi_source.id_source|intval}">
    <label>Default for anything not mapped below</label>
    <select name="id_manufacturer" aria-label="Default manufacturer">
      <option value="" {if $pi_source.default_id_manufacturer === null}selected{/if}>Auto-create (no default)</option>
      {foreach from=$pi_options item=option}
        <option value="{$option.id_manufacturer|intval}" {if $pi_source.default_id_manufacturer == $option.id_manufacturer}selected{/if}>{$option.label|escape:'html':'UTF-8'}</option>
      {/foreach}
    </select>
    <button type="submit" name="saveDefaultMapping" value="1" class="btn btn-default">Save default</button>
  </form>
  {/if}
  <table class="table">
    <thead><tr><th>Source brand</th><th>Current mapping</th><th>Change override</th></tr></thead>
    <tbody>
    {foreach from=$pi_rows item=row}
      <tr>
        <td>{$row.display_name|escape:'html':'UTF-8'}</td>
        <td>→ {$row.current|escape:'html':'UTF-8'}</td>
        <td>{if $pi_can_edit}
          <form method="post" action="{$pi_action|escape:'html':'UTF-8'}">
            <input type="hidden" name="id_source" value="{$pi_source.id_source|intval}">
            <input type="hidden" name="source_name" value="{$row.source_name|escape:'html':'UTF-8'}">
            <select name="id_manufacturer" aria-label="Manufacturer override">
              <option value="" {if $row.id_manufacturer === null}selected{/if}>Use source default (auto-create if none)</option>
              {foreach from=$pi_options item=option}
                <option value="{$option.id_manufacturer|intval}" {if $row.id_manufacturer == $option.id_manufacturer}selected{/if}>{$option.label|escape:'html':'UTF-8'}</option>
              {/foreach}
            </select>
            <button type="submit" name="saveManufacturerMapping" value="1" class="btn btn-default">Save mapping</button>
          </form>
        {/if}</td>
      </tr>
    {foreachelse}<tr><td colspan="3">No brands discovered yet. Test the source to discover brand names.</td></tr>{/foreach}
    </tbody>
  </table>
</div>
