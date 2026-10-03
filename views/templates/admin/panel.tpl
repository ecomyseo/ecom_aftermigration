{*
 * Ecom Aftermigration - Panel de diagnostico
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *}
<div class="ecomam">

	{foreach from=$ecomam_avisos item=aviso}
		<div class="alert alert-{$aviso.tipo|escape:'html':'UTF-8'}">{$aviso.texto|escape:'html':'UTF-8'}</div>
	{/foreach}

	{if $ecomam_overrides.desactivados}
		<div class="alert alert-danger">
			{l s='Overrides are disabled in Advanced parameters > Performance: turn them on or nothing here will protect the shop.' d='Modules.Ecomaftermigration.Admin'}
		</div>
	{/if}

	{if $ecomam_fallos > 0}
		<div class="alert alert-danger">
			{l s='%count% check(s) failed. Press Repair on each one.' sprintf=['%count%' => $ecomam_fallos] d='Modules.Ecomaftermigration.Admin'}
		</div>
	{elseif $ecomam_avisos_check > 0}
		<div class="alert alert-warning">
			{l s='No failures. There are %count% warning(s) to look at.' sprintf=['%count%' => $ecomam_avisos_check] d='Modules.Ecomaftermigration.Admin'}
		</div>
	{else}
		<div class="alert alert-success">
			{l s='Every check passed.' d='Modules.Ecomaftermigration.Admin'}
		</div>
	{/if}

	<div class="panel">
		<div class="panel-heading">
			<i class="icon-stethoscope"></i> {l s='Diagnosis' d='Modules.Ecomaftermigration.Admin'}
			<span class="badge">{$ecomam_total_checks|intval}</span>
		</div>

		<p class="ecomam-linea">
			{l s='PrestaShop %ps% with PHP %php%.' sprintf=['%ps%' => $ecomam_ps_version, '%php%' => $ecomam_php_version] d='Modules.Ecomaftermigration.Admin'}
			{if $ecomam_multitienda}{l s='Multistore is on: everything is checked shop by shop.' d='Modules.Ecomaftermigration.Admin'}{/if}
		</p>

		<table class="table ecomam-tabla">
			<thead>
				<tr>
					<th style="width:110px;">{l s='Status' d='Modules.Ecomaftermigration.Admin'}</th>
					<th>{l s='Check' d='Modules.Ecomaftermigration.Admin'}</th>
					<th style="width:140px;"></th>
				</tr>
			</thead>
			<tbody>
			{foreach from=$ecomam_resultados item=resultado}
				<tr>
					<td>
						{if $resultado.estado == 'ok'}
							<span class="badge ecomam-ok">{l s='OK' d='Modules.Ecomaftermigration.Admin'}</span>
						{elseif $resultado.estado == 'aviso'}
							<span class="badge ecomam-aviso">{l s='Warning' d='Modules.Ecomaftermigration.Admin'}</span>
						{else}
							<span class="badge ecomam-fallo">{l s='Failure' d='Modules.Ecomaftermigration.Admin'}</span>
						{/if}
					</td>
					<td>
						<strong>{$resultado.titulo|escape:'html':'UTF-8'}</strong><br>
						<span class="ecomam-mensaje">{$resultado.mensaje|escape:'html':'UTF-8'}</span>
						<br><span class="ecomam-desc">{$resultado.descripcion|escape:'html':'UTF-8'}</span>
						{if $resultado.detalle|@count > 0}
							<div class="ecomam-plegable">
								<a href="#" class="ecomam-toggle" data-ecomam-destino="detalle-{$resultado.codigo|escape:'html':'UTF-8'}">
									{l s='Show the detail' d='Modules.Ecomaftermigration.Admin'}
								</a>
								<pre id="detalle-{$resultado.codigo|escape:'html':'UTF-8'}" class="ecomam-detalle ecomam-cerrado">{foreach from=$resultado.detalle item=linea}{$linea|escape:'html':'UTF-8'}
{/foreach}</pre>
							</div>
						{/if}
					</td>
					<td>
						{if $resultado.puede_arreglar}
							<form method="post" action="{$ecomam_form_action|escape:'html':'UTF-8'}">
								<input type="hidden" name="ecomamCheck" value="{$resultado.codigo|escape:'html':'UTF-8'}">
								<button type="submit" name="ecomamArreglar" value="1" class="btn btn-primary btn-sm">
									<i class="icon-wrench"></i> {l s='Repair' d='Modules.Ecomaftermigration.Admin'}
								</button>
							</form>
						{/if}
					</td>
				</tr>
			{/foreach}
			</tbody>
		</table>

		<div class="ecomam-botones">
			<form method="post" action="{$ecomam_form_action|escape:'html':'UTF-8'}" class="ecomam-inline">
				<button type="submit" name="ecomamDiagnosticar" value="1" class="btn btn-default">
					<i class="icon-refresh"></i> {l s='Check again' d='Modules.Ecomaftermigration.Admin'}
				</button>
			</form>
			<form method="post" action="{$ecomam_form_action|escape:'html':'UTF-8'}" class="ecomam-inline">
				<button type="submit" name="ecomamOverridesInstalar" value="1" class="btn btn-default">
					<i class="icon-download"></i> {l s='Install the overrides' d='Modules.Ecomaftermigration.Admin'}
				</button>
			</form>
			<form method="post" action="{$ecomam_form_action|escape:'html':'UTF-8'}" class="ecomam-inline">
				<button type="submit" name="ecomamOverridesQuitar" value="1" class="btn btn-default">
					<i class="icon-trash"></i> {l s='Remove the overrides' d='Modules.Ecomaftermigration.Admin'}
				</button>
			</form>
			<form method="post" action="{$ecomam_form_action|escape:'html':'UTF-8'}" class="ecomam-inline">
				<button type="submit" name="ecomamVaciarLog" value="1" class="btn btn-default">
					<i class="icon-eraser"></i> {l s='Empty the logs' d='Modules.Ecomaftermigration.Admin'}
				</button>
			</form>
		</div>
		<p class="ecomam-linea">
			{l s='The overrides go to override/classes/. Nothing that is not ours is overwritten.' d='Modules.Ecomaftermigration.Admin'}
		</p>
	</div>

	<div class="panel">
		<div class="panel-heading">
			<i class="icon-file-text"></i> {l s='Log' d='Modules.Ecomaftermigration.Admin'}
		</div>

		<p class="ecomam-linea">{l s='Newest first. The files are in modules/ecom_aftermigration/logs/.' d='Modules.Ecomaftermigration.Admin'}</p>

		<h4>{l s='Arrays reaching pSQL()' d='Modules.Ecomaftermigration.Admin'}</h4>
		{if $ecomam_escape_lineas|@count > 0}
			<pre class="ecomam-detalle">{foreach from=$ecomam_escape_lineas item=linea}{$linea|escape:'html':'UTF-8'}
{/foreach}</pre>
		{else}
			<p class="ecomam-linea">{l s='Nothing recorded.' d='Modules.Ecomaftermigration.Admin'}</p>
		{/if}

		<h4>{l s='Module activity' d='Modules.Ecomaftermigration.Admin'}</h4>
		{if $ecomam_log_lineas|@count > 0}
			<pre class="ecomam-detalle">{foreach from=$ecomam_log_lineas item=linea}{$linea|escape:'html':'UTF-8'}
{/foreach}</pre>
		{else}
			<p class="ecomam-linea">{l s='Nothing recorded. Turn on debug mode below.' d='Modules.Ecomaftermigration.Admin'}</p>
		{/if}
	</div>

	<div class="panel">
		<div class="panel-heading">
			<a href="#" class="ecomam-toggle" data-ecomam-destino="ecomam-ayuda">
				<i class="icon-question"></i> {l s='Help / how it works' d='Modules.Ecomaftermigration.Admin'}
			</a>
		</div>

		<div id="ecomam-ayuda" class="ecomam-cerrado">
			<h4>{l s='Address rules of the invoice PDF' d='Modules.Ecomaftermigration.Admin'}</h4>
			<p>{l s='HTMLTemplateInvoice reads PS_INVCE_INVOICE_ADDR_RULES and PS_INVCE_DELIVERY_ADDR_RULES and hands the result straight to AddressFormat::generateAddress(). If the key is gone, json_decode returns null and PHP 8 throws array_key_exists(): Argument #2 must be of type array, null given. The invoice is generated inside validateOrder(), so the customer cannot finish the purchase. The repair writes the factory value back into both keys.' d='Modules.Ecomaftermigration.Admin'}</p>

			<h4>{l s='Multilanguage configuration keys' d='Modules.Ecomaftermigration.Admin'}</h4>
			<p>{l s='Configuration::loadConfiguration() decides whether a key is multilanguage by looking at configuration_lang. A key with no rows there stops being multilanguage, the adapter returns a string, and the translatable field of the form gets a string instead of an array: Orders > Invoices dies with Expected argument of type "object, array or empty". The repair inserts the missing rows.' d='Modules.Ecomaftermigration.Admin'}</p>

			<h4>{l s='Rows missing in the language tables' d='Modules.Ecomaftermigration.Admin'}</h4>
			<p>{l s='Only tables whose name ends in _lang and that have id_lang inside the primary key are touched. Rows are copied with INSERT IGNORE from the language that has the most, so nothing already translated is overwritten. Nothing is ever deleted from here.' d='Modules.Ecomaftermigration.Admin'}</p>

			<h4>{l s='Arrays reaching pSQL()' d='Modules.Ecomaftermigration.Admin'}</h4>
			<p>{l s='Db::escape() ends in strip_tags(), and under PHP 8 an array there is a fatal error. It is always a module saving an ObjectModel field with an array. The override keeps the page alive and writes down the file and the line: that is the module that has to be corrected.' d='Modules.Ecomaftermigration.Admin'}</p>

			<h4>{l s='After installing the overrides' d='Modules.Ecomaftermigration.Admin'}</h4>
			<p>{l s='The class index is regenerated on its own. If the shop keeps behaving as before, empty the cache in Advanced parameters > Performance and check that overrides are not disabled there.' d='Modules.Ecomaftermigration.Admin'}</p>
		</div>
	</div>

</div>
