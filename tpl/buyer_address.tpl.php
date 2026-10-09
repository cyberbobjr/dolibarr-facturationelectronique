<?php
/* Copyright (C) 2026 Benjamin Marchand <ben.marchand@free.fr> */
/** @var bool $fe_can_edit_buyer_address Authorization computed by the customer invoice page */

$fe_address_is_thirdparty = !empty($fe_address_is_thirdparty);
$fe_address_label = $fe_address_is_thirdparty ? 'FacturelectThirdpartyAddress' : 'FacturelectBuyerAddress';
$fe_associated = $fe_address_is_thirdparty ? $object : $object->thirdparty;
$fe_default_address = !empty($fe_associated->array_options['options_facturelect_id'])
	? FacturelectPeppolId::parse($fe_associated->array_options['options_facturelect_id'], $fe_associated->array_options['options_facturelect_scheme'] ?? '0225') : null;
$fe_default_value = $fe_default_address ? $fe_default_address['scheme'].':'.$fe_default_address['identifier'] : '';
$fe_buyer_address = $fe_address_is_thirdparty ? $fe_default_value : (string) ($object->array_options['options_facturelect_buyer_address'] ?? '');
$fe_display_value = $fe_buyer_address ?: ($fe_default_value ?: (($fe_diagnostic_current['routing']['siren'] ?? '') !== '' ? ($fe_associated->array_options['options_facturelect_scheme'] ?? '0225').':'.$fe_diagnostic_current['routing']['siren'] : ''));
$fe_address_page = $fe_address_is_thirdparty ? DOL_URL_ROOT.'/societe/card.php?socid='.(int) $object->id : DOL_URL_ROOT.'/compta/facture/card.php?id='.(int) $object->id;
ob_start();
?>
<tr id="fe-buyer-address-row">
	<td id="fe-buyer-address-title" title="<?php echo dol_escape_htmltag($langs->transnoentities($fe_address_is_thirdparty ? 'FacturelectThirdpartyAddressHelp' : 'FacturelectBuyerAddressHelp')); ?>"><?php echo $langs->trans($fe_address_label); ?> <span class="fa fa-info-circle opacitymedium" aria-hidden="true"></span></td>
	<td colspan="2">
	<?php if (!$fe_address_is_thirdparty) { ?>
		<div style="display:flex;align-items:center;gap:8px;">
			<span id="fe-buyer-address-display" class="wordbreakimp"><?php echo dol_escape_htmltag($fe_display_value ?: $langs->transnoentities('NotDefined')); ?></span>
			<?php if ($fe_can_edit_buyer_address) { ?>
				<button id="fe-buyer-address-edit" type="button" class="fe-buyer-address-edit" title="<?php echo dol_escape_htmltag($langs->transnoentities('Modify')); ?>" aria-label="<?php echo dol_escape_htmltag($langs->transnoentities('Modify')); ?>" aria-haspopup="dialog" aria-controls="fe-buyer-address-dialog"><span class="fas fa-pencil-alt" aria-hidden="true"></span></button>
			<?php } ?>
		</div>
	<?php } ?>
	<?php if ($fe_can_edit_buyer_address) { ?>
		<?php if (!$fe_address_is_thirdparty) { ?>
			<dialog id="fe-buyer-address-dialog" class="fe-modal-container fe-buyer-address-dialog" aria-labelledby="fe-buyer-address-dialog-title">
				<div class="fe-modal-header">
					<h3 id="fe-buyer-address-dialog-title"><?php echo $langs->trans('FacturelectBuyerAddress'); ?></h3>
					<button id="fe-buyer-address-close" type="button" class="fe-modal-close" aria-label="<?php echo dol_escape_htmltag($langs->transnoentities('CloseWindow')); ?>">&times;</button>
				</div>
				<div class="fe-modal-body">
					<p><?php echo $langs->trans('FacturelectBuyerAddressHelp'); ?></p>
		<?php } ?>
		<?php if ($fe_diagnostic_current['mode'] === 'sandbox') { ?>
			<details><summary><?php echo $langs->trans('FacturelectBuyerAddressSandboxTitle'); ?></summary><p class="warning"><?php echo $langs->trans('FacturelectBuyerAddressSandboxHelp'); ?></p></details>
		<?php } ?>
		<form method="post" action="<?php echo dol_escape_htmltag($fe_address_page.($fe_address_is_thirdparty ? '#fe-buyer-address-title' : '')); ?>">
			<input type="hidden" name="token" value="<?php echo dol_escape_htmltag(newToken()); ?>">
			<input type="hidden" name="action" value="<?php echo $fe_address_is_thirdparty ? 'save_thirdparty_address' : 'save_buyer_address'; ?>">
			<label class="hideobject" for="fe-buyer-address"><?php echo $langs->trans($fe_address_label); ?></label>
			<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<select id="fe-buyer-address" aria-labelledby="<?php echo $fe_address_is_thirdparty ? 'fe-buyer-address-title' : 'fe-buyer-address-dialog-title'; ?>" name="buyer_address" style="min-width:0;max-width:100%;flex:1;" disabled>
				<option value=""><?php echo $langs->trans($fe_address_is_thirdparty ? 'FacturelectThirdpartyAddressDefault' : ($fe_default_value ? 'FacturelectBuyerAddressDefaultValue' : 'FacturelectBuyerAddressDefault'), dol_escape_htmltag($fe_default_value)); ?></option>
				<?php if ($fe_buyer_address !== '') { ?>
					<option value="<?php echo dol_escape_htmltag($fe_buyer_address); ?>" selected disabled><?php echo dol_escape_htmltag($fe_buyer_address); ?></option>
				<?php } ?>
			</select>
			<?php if (!$fe_address_is_thirdparty) { ?>
				<button id="fe-buyer-address-cancel" type="button" class="butAction" style="margin:0;flex-shrink:0;"><?php echo $langs->trans('Cancel'); ?></button>
			<?php } ?>
			<button id="fe-buyer-address-save" type="submit" class="butAction" style="margin:0;flex-shrink:0;" disabled><?php echo $langs->trans($fe_address_is_thirdparty ? 'Save' : 'Validate'); ?></button>
			</div>
			<p id="fe-buyer-address-status" role="status" aria-live="polite"><?php echo $langs->trans('FacturelectBuyerAddressLoading'); ?></p>
		</form>
		<?php if (!$fe_address_is_thirdparty) { ?>
				</div>
			</dialog>
		<?php } ?>
	<?php } elseif ($fe_address_is_thirdparty) { ?>
		<p><code><?php echo dol_escape_htmltag($fe_buyer_address ?: $langs->transnoentities('FacturelectThirdpartyAddressDefault')); ?></code></p>
	<?php } ?>
	</td>
</tr>

<?php $fe_address_html = ob_get_clean(); ?>
<script>
(function () {
	function showBuyerAddress() {
		const anchor = document.querySelector('<?php echo $fe_address_is_thirdparty ? '.societe_extras_facturelect_id' : '.facture_extras_facturelect_invoice_id'; ?>');
		if (!anchor || document.getElementById('fe-buyer-address-title')) { return; }
		const row = anchor.closest('tr');
		row.insertAdjacentHTML('beforebegin', <?php echo json_encode($fe_address_html, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
		<?php if ($fe_address_is_thirdparty) { ?>
		row.remove();
		<?php } else { ?>
		const nativeChoice = document.querySelector('.facture_extras_facturelect_buyer_address');
		if (nativeChoice) { nativeChoice.closest('tr').remove(); }
		<?php } ?>

		const select = document.getElementById('fe-buyer-address');
		if (!select) { return; }
		const save = document.getElementById('fe-buyer-address-save');
		const status = document.getElementById('fe-buyer-address-status');
		const current = select.value;
		const invalidMessage = '<?php echo dol_escape_js($langs->transnoentities('FacturelectBuyerAddressInvalid')); ?>';
		const lookup = '<?php echo dol_escape_js(dol_buildpath('/facturationelectronique/siren_lookup.php', 1)); ?>';
		const siren = '<?php echo dol_escape_js($fe_diagnostic_current['routing']['siren']); ?>';
		select.addEventListener('change', function () {
			save.disabled = select.selectedOptions[0].disabled;
			if (!save.disabled && status.textContent === invalidMessage) { status.textContent = ''; }
		});
		const dialog = document.getElementById('fe-buyer-address-dialog');
		const initialOptions = select.innerHTML;
		let directoryRequest = 0;
		/** Load active choices without persisting the user's pending selection. */
		function loadEntries() {
			const request = ++directoryRequest;
			select.innerHTML = initialOptions;
			select.value = current;
			select.disabled = true;
			save.disabled = true;
			status.textContent = '<?php echo dol_escape_js($langs->transnoentities('FacturelectBuyerAddressLoading')); ?>';
			fetch(lookup + '?action=get_entries&siren=' + encodeURIComponent(siren))
				.then(response => { if (!response.ok) { throw new Error(); } return response.json(); })
				.then(data => {
					if (request !== directoryRequest || (dialog && !dialog.open)) { return; }
					if (!data.success || !Array.isArray(data.entries)) { throw new Error(); }
					const active = data.entries.filter(entry => entry.is_active === true && entry.parsed && !/_replyto$/i.test(entry.parsed.identifier));
					active.forEach(entry => {
						const address = entry.parsed.scheme + ':' + entry.parsed.identifier;
						const option = new Option(address, address);
						if (address === current) {
							select.remove(1);
							option.selected = true;
						}
						select.add(option);
					});
					select.disabled = false;
					status.textContent = active.length ? (select.selectedOptions[0].disabled ? invalidMessage : '') : '<?php echo dol_escape_js($langs->transnoentities('FacturelectBuyerAddressNone')); ?>';
					save.disabled = select.selectedOptions[0].disabled;
				})
				.catch(() => {
					if (request !== directoryRequest || (dialog && !dialog.open)) { return; }
					select.disabled = false;
					status.textContent = '<?php echo dol_escape_js($langs->transnoentities('FacturelectBuyerAddressDirectoryError')); ?>';
					save.disabled = select.selectedOptions[0].disabled;
				});
		}
		if (dialog) {
			const edit = document.getElementById('fe-buyer-address-edit');
			edit.addEventListener('click', function () {
				dialog.showModal();
				loadEntries();
			});
			document.getElementById('fe-buyer-address-cancel').addEventListener('click', function () { dialog.close(); });
			document.getElementById('fe-buyer-address-close').addEventListener('click', function () { dialog.close(); });
			dialog.addEventListener('close', function () { edit.focus(); });
		} else {
			loadEntries();
		}

	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', showBuyerAddress);
	} else {
		showBuyerAddress();
	}
})();
</script>
