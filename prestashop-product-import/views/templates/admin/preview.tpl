<div id="pi-preview" data-url="{$pi_preview_url|escape:'html':'UTF-8'}" data-source="{$pi_preview_id|intval}">
  <h3>Test source</h3>
  <p>Test the URL or file path entered above, even before saving. For saved sources, discovery uses the saved category and manufacturer expressions. Both tests inspect the first item in the feed.</p>
  <button type="button" class="btn btn-default pi-test-source">Test source</button>
  <div class="pi-source-results" aria-live="polite"></div>
</div>
<div id="pi-configuration-panel">
  <h3>Test configuration</h3>
  <p>Save changes first. Tests the saved filter, mappings and resolutions without writing catalog data. Uses the first item in the feed; at most five variants are shown.</p>
  <button type="button" class="btn btn-default pi-preview-button" {if !$pi_preview_id}disabled{/if}>Test configuration</button>
  {if !$pi_preview_id}<p>Save the source to enable Test configuration.</p>{/if}
  {if $pi_preview_id}<p><a href="{$pi_category_url|escape:'html':'UTF-8'}">Category mappings →</a> | <a href="{$pi_manufacturer_url|escape:'html':'UTF-8'}">Brand mappings →</a></p>{/if}
  <div class="pi-config-results" aria-live="polite"></div>
</div>
