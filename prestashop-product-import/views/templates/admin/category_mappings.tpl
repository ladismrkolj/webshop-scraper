<div class="panel">
  <h3>Category mappings: {$pi_source.name|escape:'html':'UTF-8'} ({$pi_rows|count} discovered)</h3>
  <p><a href="{$pi_back|escape:'html':'UTF-8'}">Back to source / discover categories</a></p>
  <p>Auto-create uses the source's configured root. Discovery never creates catalog categories and preserves existing overrides.</p>
  <table class="table">
    <thead><tr><th>Source path</th><th>Current mapping</th><th>Change override</th></tr></thead>
    <tbody>
    {foreach from=$pi_rows item=row}
      <tr>
        <td>{$row.display_path|escape:'html':'UTF-8'}</td>
        <td>→ {$row.current|escape:'html':'UTF-8'}</td>
        <td>{if $pi_can_edit}
          <form method="post" action="{$pi_action|escape:'html':'UTF-8'}">
            <input type="hidden" name="id_source" value="{$pi_source.id_source|intval}">
            <input type="hidden" name="source_path_hash" value="{$row.source_path_hash|escape:'html':'UTF-8'}">
            <select name="id_category" aria-label="Category override">
              <option value="" {if $row.id_category === null}selected{/if}>Auto-create</option>
              {foreach from=$pi_options item=option}
                <option value="{$option.id_category|intval}" {if $row.id_category == $option.id_category}selected{/if}>{$option.label|escape:'html':'UTF-8'}</option>
              {/foreach}
            </select>
            <button type="submit" name="saveCategoryMapping" value="1" class="btn btn-default">Save mapping</button>
          </form>
        {/if}</td>
      </tr>
    {foreachelse}<tr><td colspan="3">No paths discovered yet. Open the source and click Discover categories.</td></tr>{/foreach}
    </tbody>
  </table>
</div>
