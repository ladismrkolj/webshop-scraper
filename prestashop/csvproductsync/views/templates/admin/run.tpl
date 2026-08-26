<div class="panel">
	<div class="panel-heading">
		<i class="icon-time"></i> {l s='Import run' d='Modules.Csvproductsync.Admin'} #{$run->id} — {$source_name|escape:'html':'UTF-8'}
	</div>
	<table class="table">
		<tr><td style="width:25%"><strong>{l s='Status' d='Admin.Global'}</strong></td><td>{$run->status|escape:'html':'UTF-8'}</td></tr>
		<tr><td><strong>{l s='Trigger' d='Modules.Csvproductsync.Admin'}</strong></td><td>{$run->trigger_type|escape:'html':'UTF-8'}</td></tr>
		<tr><td><strong>{l s='Started' d='Modules.Csvproductsync.Admin'}</strong></td><td>{$run->date_add|escape:'html':'UTF-8'}</td></tr>
		<tr><td><strong>{l s='Finished' d='Modules.Csvproductsync.Admin'}</strong></td><td>{$run->date_upd|escape:'html':'UTF-8'}</td></tr>
		<tr><td><strong>{l s='Rows read' d='Modules.Csvproductsync.Admin'}</strong></td><td>{$run->rows_read}</td></tr>
		<tr><td><strong>{l s='Created' d='Modules.Csvproductsync.Admin'}</strong></td><td>{$run->products_created}</td></tr>
		<tr><td><strong>{l s='Updated' d='Modules.Csvproductsync.Admin'}</strong></td><td>{$run->products_updated}</td></tr>
		<tr><td><strong>{l s='Unchanged' d='Modules.Csvproductsync.Admin'}</strong></td><td>{$run->products_unchanged}</td></tr>
		<tr><td><strong>{l s='Removed' d='Modules.Csvproductsync.Admin'}</strong></td><td>{$run->products_removed}</td></tr>
		<tr><td><strong>{l s='Skipped' d='Modules.Csvproductsync.Admin'}</strong></td><td>{$run->rows_skipped}</td></tr>
	</table>

	{if $run->message}
		<div class="panel-heading"><i class="icon-warning"></i> {l s='Notes' d='Modules.Csvproductsync.Admin'}</div>
		<pre class="csvsync-log">{$run->message|escape:'html':'UTF-8'}</pre>
	{/if}

	<div class="panel-footer">
		<a href="{$back_link|escape:'html':'UTF-8'}" class="btn btn-default"><i class="process-icon-cancel"></i> {l s='Back' d='Admin.Global'}</a>
	</div>
</div>
