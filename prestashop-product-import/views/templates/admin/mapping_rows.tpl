<div class="pi-mapping-editor">
  <table class="table">
    <thead><tr><th>{$heading|escape:'html':'UTF-8'}</th><th>Expression</th><th></th></tr></thead>
    <tbody>
      {foreach from=$rows item=row}
        <tr>
          <td><input type="text" name="{$prefix|escape:'html':'UTF-8'}_target[]" value="{$row.target|escape:'html':'UTF-8'}"></td>
          <td><textarea name="{$prefix|escape:'html':'UTF-8'}_expression[]" rows="3">{$row.expression|escape:'html':'UTF-8'}</textarea></td>
          <td><button type="button" class="btn btn-default pi-remove-row">Remove</button></td>
        </tr>
      {foreachelse}
        <tr>
          <td><input type="text" name="{$prefix|escape:'html':'UTF-8'}_target[]"></td>
          <td><textarea name="{$prefix|escape:'html':'UTF-8'}_expression[]" rows="3"></textarea></td>
          <td><button type="button" class="btn btn-default pi-remove-row">Remove</button></td>
        </tr>
      {/foreach}
    </tbody>
  </table>
  <button type="button" class="btn btn-default pi-add-row">Add row</button>
</div>
