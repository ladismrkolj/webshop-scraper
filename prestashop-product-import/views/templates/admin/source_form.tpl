{if isset($pi_help_section) && $pi_help_section == 'identifier'}
The JSON key that uniquely identifies a product (for example product_id or sku), so re-imports update it instead of creating duplicates. Leave blank until you use "Test source" above to see available JSON fields, then fill it in before running a real import.
{elseif isset($pi_help_section) && $pi_help_section == 'filter'}
Optional filter. Examples to adapt to your JSON (not defaults):<br>
Only selected categories: <code>path(fields, 'breadcrumbs.0.title') in ['Windsurf', 'Sails', 'Boards']</code><br>
Only below a price threshold: <code>num(fields['price']) &lt; 100</code><br>
Exclude one brand: <code>fields['brand'] != 'Nike'</code>
{else}
<div id="pi-fixed-mappings">
  <p class="help-block">Choose a source field to replace an expression, or select a field to insert at the cursor. Expressions are always editable; blank rows are not saved.</p>
  <p class="help-block">category_paths expects a list of paths, for example [fields['breadcrumbs']]. images expects a list of URLs; main_image defaults to its first entry. Container fields are included in the selectors.</p>
  {foreach from=$pi_fixed_rows item=row}
  <div class="panel pi-fixed-row" data-target="{$row.target|escape:'html':'UTF-8'}">
    <label for="pi-expression-{$row.target|escape:'html':'UTF-8'}">{$row.target|escape:'html':'UTF-8'}</label>
    <span class="badge">{$row.badge|escape:'html':'UTF-8'}</span>
    <input class="pi-fixed-target" type="hidden" name="field_mapping_target[]" value="{if $row.expression != ''}{$row.target|escape:'html':'UTF-8'}{/if}">
    <label>Source field (replace expression)
      <select class="pi-source-field" disabled><option value="">Run Test source above to populate</option></select>
    </label>
    <label>Insert field at cursor
      <select class="pi-insert-field" disabled><option value="">Run Test source above to populate</option></select>
    </label>
    <button type="button" class="btn btn-default pi-insert-button" disabled>+ Insert</button>
    <textarea id="pi-expression-{$row.target|escape:'html':'UTF-8'}" name="field_mapping_expression[]" rows="3">{$row.expression|escape:'html':'UTF-8'}</textarea>
  </div>
  {/foreach}
</div>
<h3>Custom fields</h3>
<p class="help-block">Use custom rows for targets outside the fixed list above.</p>
{include file='./mapping_rows.tpl' rows=$pi_mapping_rows prefix='field_mapping' heading='Custom target field' add_label='+ Add custom field'}
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
  function initRows() {
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
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initRows);
  else initRows();
}());
</script>
{/literal}
{include file='./source_editor_script.tpl'}
{/if}
