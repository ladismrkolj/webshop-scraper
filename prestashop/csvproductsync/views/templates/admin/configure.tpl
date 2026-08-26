{*
 * Module configuration: the shared settings, and the cron line to paste into
 * Hostinger's cron manager.
 *}
<div class="panel">
	<div class="panel-heading"><i class="icon-cogs"></i> {l s='CSV Product Sync' d='Modules.Csvproductsync.Admin'}</div>

	<p>
		{l s='Each CSV feed is a source with its own field mapping and its own category mapping.' d='Modules.Csvproductsync.Admin'}
	</p>
	<a href="{$csvsync_sources_link|escape:'html':'UTF-8'}" class="btn btn-primary">
		<i class="icon-list"></i> {l s='Manage sources' d='Modules.Csvproductsync.Admin'} ({$csvsync_sources|count})
	</a>
</div>

<div class="panel">
	<div class="panel-heading"><i class="icon-time"></i> {l s='Cron' d='Modules.Csvproductsync.Admin'}</div>

	<p class="help-block">
		{l s='Add this to Hostinger\'s cron manager (hPanel → Advanced → Cron Jobs). It imports every enabled source. Running it via PHP CLI rather than a URL avoids the web server\'s request timeout, which a large feed will otherwise hit.' d='Modules.Csvproductsync.Admin'}
	</p>

	<label>{l s='Command (recommended)' d='Modules.Csvproductsync.Admin'}</label>
	<pre class="csvsync-cron">{$csvsync_cron_cli|escape:'html':'UTF-8'}</pre>

	<label>{l s='Or over HTTP' d='Modules.Csvproductsync.Admin'}</label>
	<pre class="csvsync-cron">{$csvsync_cron_url|escape:'html':'UTF-8'}</pre>

	<p class="help-block">
		{l s='Add --id_source=N to import a single source, or --dry-run to report without changing anything. Suggested schedule: shortly after the nightly scrape finishes.' d='Modules.Csvproductsync.Admin'}
	</p>

	<form method="post" onsubmit="return confirm('{l s='Generate a new token? The old cron command will stop working.' d='Modules.Csvproductsync.Admin' js=1}');">
		<button type="submit" name="submitCsvSyncNewToken" class="btn btn-default">
			<i class="icon-refresh"></i> {l s='Generate a new token' d='Modules.Csvproductsync.Admin'}
		</button>
	</form>
</div>

<div class="panel">
	<div class="panel-heading"><i class="icon-wrench"></i> {l s='Import settings' d='Modules.Csvproductsync.Admin'}</div>
	<form method="post" class="form-horizontal">
		<div class="form-group">
			<label class="control-label col-lg-4">{l s='Stock for "in stock" without a quantity' d='Modules.Csvproductsync.Admin'}</label>
			<div class="col-lg-3">
				<input type="number" min="0" class="form-control" name="CSVSYNC_DEFAULT_QUANTITY" value="{$csvsync_default_quantity}">
			</div>
			<div class="col-lg-5">
				<p class="help-block">{l s='Used when a feed says only whether a product is available.' d='Modules.Csvproductsync.Admin'}</p>
			</div>
		</div>
		<div class="form-group">
			<label class="control-label col-lg-4">{l s='Maximum images per product' d='Modules.Csvproductsync.Admin'}</label>
			<div class="col-lg-3">
				<input type="number" min="1" class="form-control" name="CSVSYNC_MAX_IMAGES" value="{$csvsync_max_images}">
			</div>
		</div>
		<div class="panel-footer">
			<button type="submit" name="submitCsvSyncSettings" class="btn btn-primary pull-right">
				<i class="process-icon-save"></i> {l s='Save' d='Admin.Actions'}
			</button>
		</div>
	</form>
</div>
