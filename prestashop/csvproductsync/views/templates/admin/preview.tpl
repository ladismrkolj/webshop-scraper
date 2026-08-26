{*
 * Dry run: what the next import would do, without doing any of it.
 *}
<div class="panel">
	<div class="panel-heading">
		<i class="icon-eye-open"></i> {l s='Import preview' d='Modules.Csvproductsync.Admin'} — {$source->name|escape:'html':'UTF-8'}
	</div>

	<p class="help-block">
		{l s='Nothing on this page changes the catalogue. It reads the CSV, applies the mapping and reports what an import would do.' d='Modules.Csvproductsync.Admin'}
	</p>

	<div class="btn-group" style="margin-bottom:15px">
		<a href="{$mapping_link|escape:'html':'UTF-8'}" class="btn btn-default"><i class="icon-random"></i> {l s='Mapping' d='Modules.Csvproductsync.Admin'}</a>
		<a href="{$categories_link|escape:'html':'UTF-8'}" class="btn btn-default"><i class="icon-sitemap"></i> {l s='Category mapping' d='Modules.Csvproductsync.Admin'}</a>
		<a href="{$full_link|escape:'html':'UTF-8'}" class="btn btn-default"><i class="icon-calculator"></i> {l s='Dry run over the whole file' d='Modules.Csvproductsync.Admin'}</a>
		<a href="{$run_link|escape:'html':'UTF-8'}" class="btn btn-primary"
			onclick="return confirm('{l s='Run the import for real?' d='Modules.Csvproductsync.Admin' js=1}');">
			<i class="icon-play"></i> {l s='Import now' d='Modules.Csvproductsync.Admin'}
		</a>
	</div>

	{if $sample && $sample.problems}
		<div class="alert alert-warning">
			<strong>{l s='This mapping is not ready:' d='Modules.Csvproductsync.Admin'}</strong>
			<ul>{foreach from=$sample.problems item=problem}<li>{$problem|escape:'html':'UTF-8'}</li>{/foreach}</ul>
		</div>
	{/if}

	{if $full}
		<div class="panel">
			<div class="panel-heading"><i class="icon-calculator"></i> {l s='Whole-file dry run' d='Modules.Csvproductsync.Admin'}</div>
			<div class="row csvsync-counters">
				<div class="col-md-2"><span class="csvsync-count">{$full.counts.create}</span> {l s='to create' d='Modules.Csvproductsync.Admin'}</div>
				<div class="col-md-2"><span class="csvsync-count">{$full.counts.update}</span> {l s='to update' d='Modules.Csvproductsync.Admin'}</div>
				<div class="col-md-2"><span class="csvsync-count">{$full.counts.unchanged}</span> {l s='unchanged' d='Modules.Csvproductsync.Admin'}</div>
				<div class="col-md-2"><span class="csvsync-count">{$full.counts.skip}</span> {l s='skipped' d='Modules.Csvproductsync.Admin'}</div>
				<div class="col-md-4">
					<span class="csvsync-count">{$full.removals.count}</span>
					{l s='no longer in the feed' d='Modules.Csvproductsync.Admin'}
					<small>({$full.removals.percent}% {l s='of this source' d='Modules.Csvproductsync.Admin'})</small>
				</div>
			</div>

			{if $full.reasons}
				<p><strong>{l s='Why rows are skipped:' d='Modules.Csvproductsync.Admin'}</strong></p>
				<ul>{foreach from=$full.reasons key=reason item=count}<li>{$count} × {$reason|escape:'html':'UTF-8'}</li>{/foreach}</ul>
			{/if}

			{if $full.removals.count > 0}
				{if $full.removals.blocked_by_safety_limit}
					<div class="alert alert-danger">
						{l s='The safety limit would stop the removal step: too much of this source would disappear at once. Check the CSV before importing.' d='Modules.Csvproductsync.Admin'}
					</div>
				{else}
					<div class="alert alert-info">
						{l s='The import would apply "%action%" to these products:' d='Modules.Csvproductsync.Admin' sprintf=['%action%' => $full.removals.action]}
						<ul>
							{foreach from=$full.removals.sample item=row}
								<li>{l s='product' d='Modules.Csvproductsync.Admin'} #{$row.id_product} — <code>{$row.external_id|escape:'html':'UTF-8'}</code></li>
							{/foreach}
							{if $full.removals.count > $full.removals.sample|count}<li>…</li>{/if}
						</ul>
					</div>
				{/if}
			{/if}

			{if $full.unmapped_categories}
				<div class="alert alert-warning">
					<strong>{l s='Category values with no mapping yet:' d='Modules.Csvproductsync.Admin'}</strong>
					<ul>{foreach from=$full.unmapped_categories item=value}<li>{$value|escape:'html':'UTF-8'}</li>{/foreach}</ul>
					<a href="{$categories_link|escape:'html':'UTF-8'}" class="btn btn-default btn-sm">
						<i class="icon-sitemap"></i> {l s='Map them' d='Modules.Csvproductsync.Admin'}
					</a>
				</div>
			{/if}
		</div>
	{/if}

	{if $sample}
		<div class="panel">
			<div class="panel-heading">
				<i class="icon-list"></i> {l s='First rows, as the importer reads them' d='Modules.Csvproductsync.Admin'}
			</div>
			{foreach from=$sample.rows item=row}
				<div class="csvsync-preview-row">
					<h4>
						<span class="label label-{if $row.action == 'create'}success{elseif $row.action == 'update'}info{elseif $row.action == 'skip'}danger{else}default{/if}">
							{$row.action|escape:'html':'UTF-8'}
						</span>
						{l s='line' d='Modules.Csvproductsync.Admin'} {$row.line} — <code>{$row.key|escape:'html':'UTF-8'}</code>
						{if $row.id_product}<small>(→ {l s='product' d='Modules.Csvproductsync.Admin'} #{$row.id_product})</small>{/if}
						{if $row.reason}<small class="text-muted"> — {$row.reason|escape:'html':'UTF-8'}</small>{/if}
					</h4>
					<table class="table table-condensed">
						{foreach from=$row.fields item=field}
							<tr>
								<td style="width:25%"><strong>{$field.label|escape:'html':'UTF-8'}</strong></td>
								<td>{$field.value|escape:'html':'UTF-8'}</td>
							</tr>
						{/foreach}
						{foreach from=$row.categories item=category}
							<tr class="csvsync-category-row">
								<td><strong>{l s='Category' d='Modules.Csvproductsync.Admin'}</strong></td>
								<td>
									<code>{$category.value|escape:'html':'UTF-8'}</code> →
									{if $category.status == 'mapped'}
										<span class="label label-success">{$category.target|escape:'html':'UTF-8'}</span>
									{elseif $category.status == 'auto'}
										<span class="label label-info">{$category.target|escape:'html':'UTF-8'}</span>
									{elseif $category.status == 'ignored'}
										<span class="label label-default">{l s='ignored' d='Modules.Csvproductsync.Admin'}</span>
									{else}
										<span class="label label-warning">{$category.target|escape:'html':'UTF-8'}</span>
									{/if}
								</td>
							</tr>
						{/foreach}
					</table>
				</div>
			{foreachelse}
				<p class="alert alert-warning">{l s='No rows could be read.' d='Modules.Csvproductsync.Admin'}</p>
			{/foreach}
		</div>
	{/if}

	<div class="panel-footer">
		<a href="{$back_link|escape:'html':'UTF-8'}" class="btn btn-default"><i class="process-icon-cancel"></i> {l s='Back' d='Admin.Global'}</a>
	</div>
</div>
