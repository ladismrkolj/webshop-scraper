{*
 * Category mapping for one source: scan the feed, then decide where each of
 * its category values belongs.
 *}
<div class="panel">
	<div class="panel-heading">
		<i class="icon-sitemap"></i> {l s='Category mapping' d='Modules.Csvproductsync.Admin'} — {$source->name|escape:'html':'UTF-8'}
	</div>

	<p class="help-block">
		{l s='Scanning reads the whole CSV and collects every category value in it. Values you have already decided on are left exactly as they are, so you can re-scan whenever the shop being scraped adds new categories — only the new values will need attention.' d='Modules.Csvproductsync.Admin'}
	</p>

	{if !$uses_mapping}
		<div class="alert alert-warning">
			{l s='This source is set to recreate the feed\'s own category paths, so this table is not used during import. Switch its "Category handling" setting to the mapping table to use it.' d='Modules.Csvproductsync.Admin'}
		</div>
	{/if}

	<form method="post" action="{$form_action|escape:'html':'UTF-8'}">
		<button type="submit" name="submitCsvSyncScan" class="btn btn-primary">
			<i class="icon-refresh"></i> {l s='Scan the CSV for categories' d='Modules.Csvproductsync.Admin'}
		</button>
		<span class="help-block" style="display:inline-block;margin-left:10px">
			{l s='%new% new · %mapped% mapped · %ignored% ignored · %total% in total' d='Modules.Csvproductsync.Admin' sprintf=['%new%' => $counts.new, '%mapped%' => $counts.mapped, '%ignored%' => $counts.ignored, '%total%' => $total]}
		</span>
	</form>

	<hr>

	<div class="btn-group" style="margin-bottom:12px">
		<a href="{$filter_link|escape:'html':'UTF-8'}all" class="btn btn-default {if $filter == 'all'}active{/if}">{l s='All' d='Admin.Global'} ({$total})</a>
		<a href="{$filter_link|escape:'html':'UTF-8'}new" class="btn btn-default {if $filter == 'new'}active{/if}">{l s='Needs mapping' d='Modules.Csvproductsync.Admin'} ({$counts.new})</a>
		<a href="{$filter_link|escape:'html':'UTF-8'}mapped" class="btn btn-default {if $filter == 'mapped'}active{/if}">{l s='Mapped' d='Modules.Csvproductsync.Admin'} ({$counts.mapped})</a>
		<a href="{$filter_link|escape:'html':'UTF-8'}ignored" class="btn btn-default {if $filter == 'ignored'}active{/if}">{l s='Ignored' d='Modules.Csvproductsync.Admin'} ({$counts.ignored})</a>
	</div>

	<form method="post" action="{$form_action|escape:'html':'UTF-8'}">
		<table class="table csvsync-categories">
			<thead>
				<tr>
					<th style="width:40%">{l s='Value in the CSV' d='Modules.Csvproductsync.Admin'}</th>
					<th style="width:10%" class="text-center">{l s='Rows' d='Modules.Csvproductsync.Admin'}</th>
					<th style="width:35%">{l s='Shop category' d='Modules.Csvproductsync.Admin'}</th>
					<th style="width:15%" class="text-center">{l s='Ignore' d='Modules.Csvproductsync.Admin'}</th>
				</tr>
			</thead>
			<tbody>
			{foreach from=$rows item=row}
				<tr class="csvsync-status-{$row.status|escape:'html':'UTF-8'}">
					<td>
						<code>{$row.csv_value|escape:'html':'UTF-8'}</code>
						{if $row.status == 'new'}<span class="label label-warning">{l s='new' d='Modules.Csvproductsync.Admin'}</span>{/if}
					</td>
					<td class="text-center">{$row.occurrences}</td>
					<td>
						<select name="id_category[{$row.id_csvsync_category}]" class="form-control fixed-width-xxl">
							<option value="0">— {l s='not mapped' d='Modules.Csvproductsync.Admin'} —</option>
							{foreach from=$shop_categories item=category}
								<option value="{$category.id}" {if $row.id_category == $category.id}selected="selected"{/if}>
									{$category.name|escape:'html':'UTF-8'}
								</option>
							{/foreach}
						</select>
					</td>
					<td class="text-center">
						<input type="checkbox" name="ignored[{$row.id_csvsync_category}]" value="1"
							{if $row.status == 'ignored'}checked="checked"{/if}>
					</td>
				</tr>
			{foreachelse}
				<tr>
					<td colspan="4">
						{if $total == 0}
							{l s='Nothing scanned yet — run the scan above.' d='Modules.Csvproductsync.Admin'}
						{else}
							{l s='No category values match this filter.' d='Modules.Csvproductsync.Admin'}
						{/if}
					</td>
				</tr>
			{/foreach}
			</tbody>
		</table>

		<div class="panel-footer">
			<a href="{$sources_link|escape:'html':'UTF-8'}" class="btn btn-default"><i class="process-icon-cancel"></i> {l s='Back to sources' d='Modules.Csvproductsync.Admin'}</a>
			{if $rows}
				<button type="submit" name="submitCsvSyncCategoryMap" class="btn btn-primary pull-right">
					<i class="process-icon-save"></i> {l s='Save category mapping' d='Modules.Csvproductsync.Admin'}
				</button>
			{/if}
		</div>
	</form>
</div>
