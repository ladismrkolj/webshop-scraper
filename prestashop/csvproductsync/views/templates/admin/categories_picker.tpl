<div class="panel">
	<div class="panel-heading"><i class="icon-sitemap"></i> {l s='Category mapping' d='Modules.Csvproductsync.Admin'}</div>
	<p class="help-block">{l s='Pick the source whose categories you want to map.' d='Modules.Csvproductsync.Admin'}</p>
	<table class="table">
		<thead>
			<tr>
				<th>{l s='Source' d='Modules.Csvproductsync.Admin'}</th>
				<th class="text-center">{l s='Needs mapping' d='Modules.Csvproductsync.Admin'}</th>
				<th class="text-center">{l s='Mapped' d='Modules.Csvproductsync.Admin'}</th>
				<th class="text-center">{l s='Ignored' d='Modules.Csvproductsync.Admin'}</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
		{foreach from=$sources item=source}
			<tr>
				<td>{$source.name|escape:'html':'UTF-8'}</td>
				<td class="text-center">
					{if $source.counts.new > 0}<span class="badge badge-warning">{$source.counts.new}</span>{else}0{/if}
				</td>
				<td class="text-center">{$source.counts.mapped}</td>
				<td class="text-center">{$source.counts.ignored}</td>
				<td class="text-right">
					<a href="{$source.link|escape:'html':'UTF-8'}" class="btn btn-default">
						<i class="icon-sitemap"></i> {l s='Open' d='Admin.Actions'}
					</a>
				</td>
			</tr>
		{foreachelse}
			<tr><td colspan="5">{l s='No sources yet.' d='Modules.Csvproductsync.Admin'}</td></tr>
		{/foreach}
		</tbody>
	</table>
</div>
