<div id="pi-mapping-editor">
  <table class="table">
    <thead><tr><th>Target field</th><th>Expression</th><th></th></tr></thead>
    <tbody>
      {foreach from=$pi_mapping_rows item=row}
        <tr>
          <td><input type="text" name="field_mapping_target[]" aria-label="Target field" value="{$row.target|escape:'html':'UTF-8'}"></td>
          <td><textarea name="field_mapping_expression[]" aria-label="Expression" rows="3">{$row.expression|escape:'html':'UTF-8'}</textarea></td>
          <td><button type="button" class="btn btn-default pi-remove-row">Remove</button></td>
        </tr>
      {/foreach}
    </tbody>
  </table>
  <button type="button" class="btn btn-default" id="pi-add-row">Add row</button>
  <p class="help-block">Use fields as the JSON item, for example: num(path(fields, 'price')).</p>
</div>
{literal}
<script>
(function () {
  var editor = document.getElementById('pi-mapping-editor');
  var body = editor.querySelector('tbody');
  var prototype = body.querySelector('tr').cloneNode(true);
  document.getElementById('pi-add-row').addEventListener('click', function () {
    var row = prototype.cloneNode(true);
    Array.prototype.forEach.call(row.querySelectorAll('input, textarea'), function (input) {
      input.value = '';
    });
    body.appendChild(row);
  });
  editor.addEventListener('click', function (event) {
    if (event.target.classList.contains('pi-remove-row')) {
      var row = event.target.closest('tr');
      row.parentNode.removeChild(row);
    }
  });
}());
</script>
{/literal}
