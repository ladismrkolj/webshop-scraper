{*
 * Field mapping: one row per CSV column, with a sample value from the file so
 * you can see what you are mapping.
 *}
<div class="panel">
	<div class="panel-heading">
		<i class="icon-random"></i> {l s='Field mapping' d='Modules.Csvproductsync.Admin'} — {$source->name|escape:'html':'UTF-8'}
	</div>

	<p class="help-block">
		{l s='Every column of the CSV is listed below. Pick the PrestaShop field it should feed; leave a column unmapped to ignore it. The transform runs on the raw cell before it is written.' d='Modules.Csvproductsync.Admin'}
	</p>

	<form method="post" action="{$form_action|escape:'html':'UTF-8'}" id="csvsync-mapping-form">
		<table class="table csvsync-mapping">
			<thead>
				<tr>
					<th style="width:22%">{l s='CSV column' d='Modules.Csvproductsync.Admin'}</th>
					<th style="width:23%">{l s='Sample value' d='Modules.Csvproductsync.Admin'}</th>
					<th style="width:22%">{l s='PrestaShop field' d='Modules.Csvproductsync.Admin'}</th>
					<th style="width:18%">{l s='Transform' d='Modules.Csvproductsync.Admin'}</th>
					<th style="width:15%">{l s='Fallback if empty' d='Modules.Csvproductsync.Admin'}</th>
				</tr>
			</thead>
			<tbody>
			{foreach from=$rows item=row key=index}
				{assign var='suggested' value=($suggestions[$row.column])|default:null}
				<tr{if $row.missing} class="csvsync-missing-column"{/if}>
					<td>
						<input type="hidden" name="csv_column[{$index}]" value="{$row.column|escape:'html':'UTF-8'}">
						<code>{$row.column|escape:'html':'UTF-8'}</code>
						{if $row.missing}
							<span class="label label-warning" title="{l s='This column is mapped but is not in the file right now.' d='Modules.Csvproductsync.Admin'}">{l s='not in file' d='Modules.Csvproductsync.Admin'}</span>
						{/if}
					</td>
					<td class="csvsync-sample">{$row.sample|escape:'html':'UTF-8'}</td>
					<td>
						<select name="ps_field[{$index}]" class="form-control csvsync-field-select" data-index="{$index}">
							<option value="">— {l s='ignore' d='Modules.Csvproductsync.Admin'} —</option>
							{foreach from=$field_groups key=group item=fields}
								<optgroup label="{$group|escape:'html':'UTF-8'}">
									{foreach from=$fields key=field_key item=field_label}
										<option value="{$field_key|escape:'html':'UTF-8'}"
											{if $row.ps_field == $field_key} selected="selected"
											{elseif $row.ps_field == '' && $suggested && $suggested.ps_field == $field_key} selected="selected"{/if}>
											{$field_label|escape:'html':'UTF-8'}
										</option>
									{/foreach}
								</optgroup>
							{/foreach}
							<optgroup label="{l s='Features' d='Modules.Csvproductsync.Admin'}">
								<option value="__feature__" {if $row.ps_field == '__feature__'}selected="selected"{/if}>
									{l s='Product feature…' d='Modules.Csvproductsync.Admin'}
								</option>
							</optgroup>
						</select>
						<input type="text" class="form-control csvsync-feature-name"
							name="feature_name[{$index}]"
							value="{$row.feature_name|escape:'html':'UTF-8'}"
							placeholder="{l s='Feature name, e.g. Board volume' d='Modules.Csvproductsync.Admin'}"
							{if $row.ps_field != '__feature__'}style="display:none"{/if}>
						{if $row.ps_field == '' && $suggested}
							<span class="help-block csvsync-suggested">{l s='suggested' d='Modules.Csvproductsync.Admin'}</span>
						{/if}
					</td>
					<td>
						<select name="transform[{$index}]" class="form-control">
							{foreach from=$transforms key=transform_key item=transform_label}
								<option value="{$transform_key|escape:'html':'UTF-8'}"
									{if $row.transform == $transform_key} selected="selected"
									{elseif $row.transform == 'none' && $suggested && $suggested.transform == $transform_key} selected="selected"{/if}>
									{$transform_label|escape:'html':'UTF-8'}
								</option>
							{/foreach}
						</select>
					</td>
					<td>
						<input type="text" class="form-control" name="default_value[{$index}]"
							value="{$row.default_value|escape:'html':'UTF-8'}">
					</td>
				</tr>
			{foreachelse}
				<tr><td colspan="5">{l s='No columns could be read from this CSV.' d='Modules.Csvproductsync.Admin'}</td></tr>
			{/foreach}
			</tbody>
		</table>

		<div class="panel-footer">
			<a href="{$back_link|escape:'html':'UTF-8'}" class="btn btn-default"><i class="process-icon-cancel"></i> {l s='Back' d='Admin.Global'}</a>
			<button type="submit" name="submitCsvSyncMapping" class="btn btn-primary pull-right">
				<i class="process-icon-save"></i> {l s='Save mapping' d='Modules.Csvproductsync.Admin'}
			</button>
			<a href="{$preview_link|escape:'html':'UTF-8'}" class="btn btn-default pull-right" style="margin-right:8px">
				<i class="icon-eye-open"></i> {l s='Preview import' d='Modules.Csvproductsync.Admin'}
			</a>
		</div>
	</form>
</div>

<script type="text/javascript">
	// The feature-name box only makes sense next to the "Product feature" target.
	$(document).on('change', '.csvsync-field-select', function () {
		$(this).closest('td').find('.csvsync-feature-name').toggle($(this).val() === '__feature__');
	});
</script>
