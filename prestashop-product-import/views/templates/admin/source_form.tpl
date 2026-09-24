{if isset($pi_help_section) && $pi_help_section == 'filter'}
Optional filter. Examples to adapt to your JSON (not defaults):<br>
Only selected categories: <code>path(fields, 'breadcrumbs.0.title') in ['Windsurf', 'Sails', 'Boards']</code><br>
Only below a price threshold: <code>num(fields['price']) &lt; 100</code><br>
Exclude one brand: <code>fields['brand'] != 'Nike'</code>
{else}
<div id="pi-source-tabs">
  <ul class="nav nav-tabs" role="tablist">
    <li class="active" role="presentation"><a id="pi-general-link" href="#pi-general" role="tab" aria-controls="pi-general" aria-selected="true" data-toggle="tab">General</a></li>
    <li role="presentation"><a id="pi-products-link" href="#pi-products" role="tab" aria-controls="pi-products" aria-selected="false" data-toggle="tab">Product fields</a></li>
    <li role="presentation"><a id="pi-categories-link" href="#pi-categories" role="tab" aria-controls="pi-categories" aria-selected="false">Categories</a></li>
    <li role="presentation"><a id="pi-brands-link" href="#pi-brands" role="tab" aria-controls="pi-brands" aria-selected="false">Brands</a></li>
  </ul>
  <div class="tab-content">
    <div id="pi-general" class="tab-pane active" role="tabpanel" aria-labelledby="pi-general-link"></div>
    <div id="pi-products" class="tab-pane" role="tabpanel" aria-labelledby="pi-products-link">
<div id="pi-fixed-mappings">
  <p class="help-block">Choose a source field, or choose Custom expression for formulas. Blank rows are not saved.</p>
  <p class="help-block">category_paths expects a list of paths, for example [fields['breadcrumbs']]. images expects a list of URLs; main_image defaults to its first entry. Container fields are included in the selectors.</p>
  {foreach from=$pi_fixed_rows item=row}
  <div class="panel pi-fixed-row" data-target="{$row.target|escape:'html':'UTF-8'}">
    <label for="pi-source-{$row.target|escape:'html':'UTF-8'}">{$row.target|escape:'html':'UTF-8'}</label>
    <span class="badge">{$row.badge|escape:'html':'UTF-8'}</span>
    <input class="pi-fixed-target" type="hidden" name="field_mapping_target[]" value="{if $row.expression != ''}{$row.target|escape:'html':'UTF-8'}{/if}">
    <select id="pi-source-{$row.target|escape:'html':'UTF-8'}" class="pi-source-field" aria-label="Source field for {$row.target|escape:'html':'UTF-8'}"></select>
    <div class="pi-custom-controls">
      <textarea name="field_mapping_expression[]" rows="3" aria-label="Custom expression for {$row.target|escape:'html':'UTF-8'}">{$row.expression|escape:'html':'UTF-8'}</textarea>
      <select class="pi-insert-field" aria-label="Field to insert"><option value="">Run Test source above to populate</option></select>
      <button type="button" class="btn btn-default pi-insert-button" disabled>+ Insert field at cursor</button>
    </div>
  </div>
  {/foreach}
</div>
<h3>Custom fields</h3>
<p class="help-block">Use custom rows for targets outside the fixed list above.</p>
{include file='./mapping_rows.tpl' rows=$pi_mapping_rows prefix='field_mapping' heading='Custom target field' add_label='+ Add custom field'}
<section id="pi-variants">
  <h3>Variants (optional)</h3>
  <p class="help-block">A variants list contains one entry per combination, such as <code>variants</code>. Attributes describe each entry, for example attribute Size mapped to <code>variant['size']</code>.</p>
  <label for="pi-variants-list">Variants list</label>
  <select id="pi-variants-list"></select>
  <input id="pi-variants-expression" type="text" name="variants_expression" value="{$pi_variants_expression|escape:'html':'UTF-8'}" aria-label="Custom variants expression">
  <div id="pi-variant-mappings">
    <p class="help-block">At least one attribute and a reference field are required. Variant price is absolute; active=false sets zero stock.</p>
    <h4>Variant fields</h4>
    {foreach from=['reference', 'ean13', 'price', 'quantity', 'active', 'image'] item=target}
    <div class="panel pi-variant-row" data-target="{$target|escape:'html':'UTF-8'}">
      <label>{$target|escape:'html':'UTF-8'}</label>{if $target == 'reference'} <span class="badge">Required for variants</span>{/if}
      <input class="pi-variant-target" type="hidden" name="variant_field_target[]" value="{$target|escape:'html':'UTF-8'}">
      <select class="pi-mapping-select" aria-label="Source for variant {$target|escape:'html':'UTF-8'}"></select>
      <textarea name="variant_field_expression[]" rows="3" aria-label="Custom variant {$target|escape:'html':'UTF-8'} expression">{foreach from=$pi_variant_field_rows item=row}{if $row.target == $target}{$row.expression|escape:'html':'UTF-8'}{/if}{/foreach}</textarea>
    </div>
    {/foreach}
    <h4>Attributes</h4>
    <div id="pi-attribute-rows">
    {foreach from=$pi_attribute_rows item=row}
      <div class="panel pi-attribute-row"><input type="text" name="variant_attribute_target[]" value="{$row.target|escape:'html':'UTF-8'}" placeholder="Attribute name, e.g. Size" aria-label="Attribute name"><select class="pi-mapping-select" aria-label="Attribute source"></select><textarea name="variant_attribute_expression[]" rows="3" aria-label="Custom attribute expression">{$row.expression|escape:'html':'UTF-8'}</textarea><button type="button" class="btn btn-default pi-remove-attribute">Remove</button></div>
    {foreachelse}
      <div class="panel pi-attribute-row"><input type="text" name="variant_attribute_target[]" placeholder="Attribute name, e.g. Size" aria-label="Attribute name"><select class="pi-mapping-select" aria-label="Attribute source"></select><textarea name="variant_attribute_expression[]" rows="3" aria-label="Custom attribute expression"></textarea><button type="button" class="btn btn-default pi-remove-attribute">Remove</button></div>
    {/foreach}
    </div>
    <button type="button" id="pi-add-attribute" class="btn btn-default">+ Add attribute</button>
  </div>
</section>
    </div>
    <div id="pi-categories" class="tab-pane" role="tabpanel" aria-labelledby="pi-categories-link"><div id="pi-category-root"></div><div id="pi-category-mappings"></div></div>
    <div id="pi-brands" class="tab-pane" role="tabpanel" aria-labelledby="pi-brands-link"></div>
  </div>
</div>
{include file='./source_tabs_script.tpl'}
{include file='./source_mappings_script.tpl'}
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
