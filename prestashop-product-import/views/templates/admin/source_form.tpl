<div class="pi-mapping-editor">
  <p class="help-block">Target fields: name, reference, price, short_description, description, ean13, weight, quantity, active, manufacturer, category_paths, images, main_image.</p>
  <p class="help-block">category_paths: a list of paths, each containing strings or title dictionaries; for scraper breadcrumbs use [path(fields, 'breadcrumbs')]. images: a list of URL strings. main_image: optional cover URL, defaults to the first image.</p>
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
  <button type="button" class="pi-add-row btn btn-default">Add row</button>
  <p class="help-block">Use fields as the JSON item, for example: num(path(fields, 'price')).</p>
</div>
<h3>Optional combinations</h3>
<label for="pi-variants-expression">Variants expression</label>
<input id="pi-variants-expression" type="text" name="variants_expression" value="{$pi_variants_expression|escape:'html':'UTF-8'}">
<p class="help-block">Leave empty for no combinations. Expression must return a list, for example fields['variants']. Rows below can use both fields and variant. At least one attribute and a reference mapping are required.</p>
{include file='./mapping_rows.tpl' rows=$pi_attribute_rows prefix='variant_attribute' heading='Attribute name'}
<p class="help-block">Variant fields: reference, ean13, price (absolute), quantity, active, image. active=false sets zero stock; it does not hide the combination.</p>
{include file='./mapping_rows.tpl' rows=$pi_variant_field_rows prefix='variant_field' heading='Variant target field'}
{literal}
<script>
(function () {
  Array.prototype.forEach.call(document.querySelectorAll('.pi-mapping-editor'), function (editor) {
  var body = editor.querySelector('tbody');
  var prototype = body.querySelector('tr').cloneNode(true);
  editor.querySelector('.pi-add-row').addEventListener('click', function () {
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
  });
}());
</script>
{/literal}

{include file='./preview.tpl'}
