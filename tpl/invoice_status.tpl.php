<?php
/* Copyright (C) 2026 Benjamin Marchand <ben.marchand@free.fr> */
/** Single invoice status panel; correction permissions and cached routing come from the hook. */

$fe_status_style = 'info';
$fe_status_action = '';
$fe_status_title = 'FacturelectPrepareSendTitle';
$fe_status_body = $fe_routing_check_enabled ? 'FacturelectRecipientAutoCheck' : 'FacturelectPrepareSendBody';
$fe_routing_status = $routing_verdict['status'] ?? '';
$fe_diagnostic_url = dol_buildpath('/facturationelectronique/invoice_facturelect_tab.php', 1).'?id='.(int) $object->id.'&type=customer';

if ($pdp_status === 'transmitted') {
	$fe_status_style = 'success';
	$fe_status_title = 'FacturelectDepositedTitle';
	$fe_status_body = 'FacturelectDepositedBody';
} elseif ($pdp_status === 'queued') {
	$fe_status_title = 'FacturelectQueuedTitle';
	$fe_status_body = 'FacturelectQueuedBody';
} elseif ($seller_siren_invalid) {
	$fe_status_title = 'FacturelectCompleteSellerTitle';
	$fe_status_body = 'FacturelectCompleteSellerBody';
	$fe_status_action = !empty($user->admin) && empty($user->socid) ? 'company' : '';
} elseif ($buyer_siren_invalid) {
	$fe_status_title = 'FacturelectCompleteBuyerTitle';
	$fe_status_body = empty($buyer_siren) ? 'FacturelectCompleteBuyerBody' : 'FacturelectCorrectBuyerBody';
	$fe_status_action = $fe_can_associate ? 'associate' : (empty($user->socid) ? 'thirdparty' : '');
} elseif (!$is_b2c && in_array($fe_routing_status, array('inactive', 'missing', 'unassociated', 'empty', 'not_ready'), true)) {
	$fe_status_style = 'warning';
	$fe_status_title = 'FacturelectChooseRecipientTitle';
	$fe_status_body = 'FacturelectChooseRecipientBody';
	$fe_status_action = $fe_can_choose_address ? 'address' : '';
} elseif ($pdp_status === 'failed') {
	$fe_status_style = 'warning';
	$fe_status_title = 'FacturelectSendFailedTitle';
	$fe_status_body = 'FacturelectSendFailedBody';
} elseif ($object->statut == 0) {
	$fe_status_title = 'FacturelectDraftTitle';
	$fe_status_body = 'FacturelectDraftBody';
} elseif ($is_b2c) {
	$fe_status_title = 'FacturelectB2cNoteTitle';
	$fe_status_body = 'FacturelectB2cNoteBody';
} elseif ($fe_routing_status === 'ok') {
	$fe_status_title = 'FacturelectRecipientCompleteTitle';
} elseif ($fe_routing_status === 'error') {
	$fe_status_body = 'FacturelectRecipientCheckUnavailable';
}
?>
<div class="fe-alert fe-alert-<?php echo $fe_status_style; ?> fe-invoice-status-banner">
	<span class="fa fa-info-circle" aria-hidden="true"></span>
	<div class="fe-invoice-status-content">
		<strong><?php echo $langs->trans($fe_status_title); ?></strong>
		<p><?php echo $langs->trans($fe_status_body); ?></p>
		<?php if ($pdp_status === 'transmitted' && $pdp_id !== '') { ?>
			<p><?php echo $langs->trans('FacturelectDepositReference'); ?> : <?php echo dol_escape_htmltag($pdp_id); ?><?php if ($formatted_date !== '') { echo ' — '.dol_escape_htmltag($formatted_date); } ?></p>
		<?php } ?>
		<div class="fe-invoice-status-actions">
			<?php if ($fe_status_action === 'associate') { ?>
				<button type="button" id="fe-status-associate" class="butAction" onclick="feOpenModal(<?php echo (int) $thirdparty_id; ?>);" aria-haspopup="dialog"><?php echo $langs->trans('FacturelectSearchCompany'); ?></button>
			<?php } elseif ($fe_status_action === 'address') { ?>
				<a id="fe-status-address" class="butAction" href="<?php echo dol_escape_htmltag($fe_diagnostic_url); ?>" onclick="const edit = document.getElementById('fe-buyer-address-edit'); if (edit) { edit.click(); return false; }" aria-haspopup="dialog"><?php echo $langs->trans('FacturelectChooseAddress'); ?></a>
			<?php } elseif ($fe_status_action === 'company') { ?>
				<a id="fe-status-company" class="butAction" href="<?php echo DOL_URL_ROOT; ?>/admin/company.php"><?php echo $langs->trans('FacturelectCompleteCompany'); ?></a>
			<?php } elseif ($fe_status_action === 'thirdparty') { ?>
				<a class="butAction" href="<?php echo DOL_URL_ROOT; ?>/societe/card.php?socid=<?php echo (int) $thirdparty_id; ?>"><?php echo $langs->trans('FacturelectOpenThirdparty'); ?></a>
			<?php } ?>
			<a class="fe-invoice-diagnostic-link" href="<?php echo dol_escape_htmltag($fe_diagnostic_url); ?>"><?php echo $langs->trans('FacturelectTransmissionDetails'); ?></a>
		</div>
	</div>
</div>
