<?php
/* Copyright (C) 2026 Benjamin Marchand <contact@superpdp.tech>
 */

require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/fourn.lib.php';
if (!class_exists('FacturelectClient')) {
	require_once './class/facturelectclient.class.php';
}

if (!getDolGlobalInt('FACTURELECT_FEATURE_EINVOICING', 1)) {
	llxHeader('', 'Facturation Électronique');
	print '<div class="info">La fonctionnalité de <strong>transmission électronique</strong> est désactivée. Activez-la dans <a href="' . dol_buildpath('/facturationelectronique/admin/setup.php', 1) . '">Configuration > Fonctionnalités</a>.</div>';
	llxFooter();
	exit;
}

$id = GETPOST('id', 'int');
if ($id <= 0) {
	accessforbidden();
}

$type = GETPOST('type', 'alpha');
$type = preg_replace('/[^a-zA-Z0-9_]/', '', $type);

if (!empty($type)) {
	$is_supplier = ($type === 'supplier' || $type === 'supplier_invoice');
} else {
	// Fallback auto-detection by checking database table
	$is_supplier = false;
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture_fourn WHERE rowid = ".((int) $id);
	$res = $db->query($sql);
	if ($res && $db->num_rows($res) > 0) {
		$is_supplier = true;
	}
}

if ($is_supplier) {
	$object = new FactureFournisseur($db);
	if ($object->fetch($id) <= 0) {
		accessforbidden();
	}
	
	// Access control
	if (!$user->rights->fournisseur->facture->lire && !$user->rights->supplier_invoice->lire) {
		accessforbidden();
	}
} else {
	$object = new Facture($db);
	if ($object->fetch($id) <= 0) {
		accessforbidden();
	}
	
	// Access control
	if (!$user->rights->facture->lire) {
		accessforbidden();
	}
}

$langs->loadLangs(array("bills", "companies", "facturation_electronique@facturationelectronique"));

$object->fetch_optionals();
$pdp_id = !empty($object->array_options['options_facturelect_invoice_id']) ? $object->array_options['options_facturelect_invoice_id'] : '';
$pdp_status = !empty($object->array_options['options_facturelect_status']) ? $object->array_options['options_facturelect_status'] : 'not_sent';

$client = new FacturelectClient($db);
$inv_data = null;
if (!empty($pdp_id)) {
	$inv_data = $client->getInvoice($pdp_id);
}

// Prepare head
if ($is_supplier) {
	$head = facturefourn_prepare_head($object);
	$titre = $langs->trans("SupplierInvoice");
	$linkback = '<a href="'.DOL_URL_ROOT.'/fourn/facture/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
} else {
	$head = facture_prepare_head($object);
	$titre = $langs->trans("CustomerBill");
	$linkback = '<a href="'.DOL_URL_ROOT.'/compta/facture/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
}
llxHeader('', $langs->trans("Bill"), '');

// Display card tabs and main info
print dol_get_fiche_head($head, 'facturelect_tab', $titre, -1, 'bill');

// Display object card top header
$morehtmlref = '<div class="refidno">';
$morehtmlref .= '</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

print '<div class="fichecenter">';
print '  <div class="fichehalfleft">';

// Custom E-Invoicing Info Panel
$provider_name = $client->getProviderName();
print '    <div class="fe-card" style="background:#fff; border:1px solid #cbd5e1; border-radius:12px; padding:20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); font-family:\'Outfit\', \'Inter\', sans-serif;">';
$card_title = $is_supplier ? $langs->trans("FacturelectTabIntegrationDetails", $provider_name) : $langs->trans("FacturelectTabTransmissionDetails", $provider_name);
print '      <h3 style="margin:0 0 15px 0; font-size:16px; font-weight:700; color:#0f172a; border-bottom:1px solid #e2e8f0; padding-bottom:8px; display:flex; align-items:center; gap:8px;">';
print '        <span class="fa fa-paper-plane" style="color:#0284c7; font-size:20px;"></span> ' . $card_title;
print '      </h3>';

if (empty($pdp_id)) {
	print '      <div style="text-align:center; padding:30px 10px; color:#64748b;">';
	print '        <span class="fa fa-file-invoice-dollar" style="font-size:36px; color:#cbd5e1; display:block; margin-bottom:10px;"></span>';
	if ($is_supplier) {
		print '        <p style="margin:0; font-size:14px; font-weight:600;">' . $langs->trans("FacturelectTabNotLinkedTitle") . '</p>';
		print '        <p style="margin:5px 0 15px 0; font-size:12px;">' . $langs->trans("FacturelectTabNotLinkedSupplierDesc") . '</p>';
	} else {
		print '        <p style="margin:0; font-size:14px; font-weight:600;">' . $langs->trans("FacturelectTabNotSentTitle") . '</p>';
		print '        <p style="margin:5px 0 15px 0; font-size:12px;">' . $langs->trans("FacturelectTabNotSentCustomerDesc") . '</p>';
		if ($object->statut > 0) {
			print '        <a href="' . dol_buildpath('/facturationelectronique/outbound_list.php', 1) . '?action=send&id=' . $object->id . '&mainmenu=facturelect&leftmenu=outbound" class="butAction fe-btn-primary">';
			print '          <span class="fa fa-paper-plane"></span> ' . $langs->trans("FacturelectTabTransmitNow");
			print '        </a>';
		}
	}
	print '      </div>';
} else {
	print '      <table style="width:100%; border-collapse:collapse; font-size:13px; color:#334155;">';
	print '        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; font-weight:600; color:#475569; width:180px;">' . $langs->trans("FacturelectTabPdpTechId") . ' :</td><td style="padding:10px 0;"><code style="font-size:12px; background:#f1f5f9; padding:3px 8px; border-radius:6px; font-weight:700; color:#0f172a;">' . $pdp_id . '</code></td></tr>';
	
	// Status Badge mapping
	if ($is_supplier) {
		$status_title = $langs->trans("FacturelectTabLocalIntegrationStatus") . " :";
		$status_class = 'success';
		$status_label = $langs->trans("FacturelectImported");
	} else {
		$status_title = $langs->trans("FacturelectTabLocalTransmissionStatus") . " :";
		$status_class = 'warning';
		$status_label = $langs->trans("FacturelectInvoiceStatus_not_sent");
		if ($pdp_status === 'queued') {
			$status_class = 'warning';
			$status_label = $langs->trans("FacturelectInvoiceStatus_queued");
		} elseif ($pdp_status === 'transmitted') {
			$status_class = 'success';
			$status_label = $langs->trans("FacturelectInvoiceStatus_transmitted");
		} elseif ($pdp_status === 'failed') {
			$status_class = 'danger';
			$status_label = $langs->trans("FacturelectInvoiceStatus_failed") . ' / Rejetée';
		}
	}
	
	print '        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; font-weight:600; color:#475569;">' . $status_title . '</td><td style="padding:10px 0;"><span class="fe-status-pill ' . $status_class . '">' . $status_label . '</span></td></tr>';
	
	if ($inv_data) {
		$dir = !empty($inv_data['direction']) ? $inv_data['direction'] : 'out';
		$dir_label = $dir === 'out' ? '<span class="fa fa-arrow-alt-circle-up" style="color:#0284c7;"></span> ' . $langs->trans("FacturelectTabFlowDirectionOut") : '<span class="fa fa-arrow-alt-circle-down" style="color:#8b5cf6;"></span> ' . $langs->trans("FacturelectTabFlowDirectionIn");
		print '        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; font-weight:600; color:#475569;">' . $langs->trans("FacturelectTabFlowDirection") . ' :</td><td style="padding:10px 0;">' . $dir_label . '</td></tr>';
		print '        <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 0; font-weight:600; color:#475569;">' . $langs->trans("FacturelectTabPdpRegistrationDate") . ' :</td><td style="padding:10px 0;">' . dol_print_date(strtotime($inv_data['created_at']), 'dayhour') . '</td></tr>';
	}
	
	// Direct Link to Provider Portal dashboard
	$direct_link = 'https://www.superpdp.tech/app/invoices/' . $pdp_id;
	
	print '        <tr><td style="padding:10px 0; font-weight:600; color:#475569;">' . $langs->trans("FacturelectTabDirectLinkPortal") . ' :</td><td style="padding:10px 0;"><a href="' . $direct_link . '" target="_blank" class="fe-btn fe-btn-secondary" style="font-size:11px; padding:4px 10px; border-radius:6px; display:inline-flex; align-items:center; gap:5px;"><span class="fa fa-external-link-alt"></span> ' . $langs->trans("FacturelectTabOpenPortal", $provider_name) . '</a></td></tr>';
	print '      </table>';
}

print '    </div>';

// VAT exemption (VATEX) contextual help — customer invoices carrying exempt lines only.
// Shows the exact BT-120/BT-121 mentions that will be transmitted, plus how to override them.
if (!$is_supplier) {
	if (!class_exists('ActionsFacturationelectronique')) {
		require_once './class/actions_facturationelectronique.class.php';
	}
	if (!class_exists('VatexMapper')) {
		require_once './class/vatexmapper.class.php';
	}
	if (empty($object->thirdparty)) {
		$object->fetch_thirdparty();
	}
	if (!empty($object->thirdparty) && empty($object->thirdparty->array_options)) {
		$object->thirdparty->fetch_optionals();
	}
	if (empty($object->lines)) {
		$object->fetch_lines();
	}

	$feActions = new ActionsFacturationelectronique($db);
	$exempt_categories = array();
	if (!empty($object->lines)) {
		foreach ($object->lines as $line) {
			if (!empty($line->special_code)) {
				continue;
			}
			if ((int) $line->product_type >= 9 && floatval($line->total_ht) == 0 && floatval($line->total_tva) == 0) {
				continue;
			}
			$cat = $feActions->resolveVatCategoryCode(floatval($line->tva_tx), (int) ($line->info_bits ?? 0), $line->vat_src_code ?? '');
			if (VatexMapper::isExemptCategory($cat)) {
				$exempt_categories[$cat] = true;
			}
		}
	}

	if (!empty($exempt_categories)) {
		$override_active = !empty($object->array_options['options_facturelect_vatex_code'])
			|| (!empty($object->thirdparty) && !empty($object->thirdparty->array_options['options_facturelect_vatex_code']));

		print '    <div class="fe-card" style="background:#fff; border:1px solid #cbd5e1; border-radius:12px; padding:20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); font-family:\'Outfit\', \'Inter\', sans-serif;">';
		print '      <h3 style="margin:0 0 12px 0; font-size:16px; font-weight:700; color:#0f172a; border-bottom:1px solid #e2e8f0; padding-bottom:8px; display:flex; align-items:center; gap:8px;">';
		print '        <span class="fa fa-percent" style="color:#0284c7; font-size:18px;"></span> ' . $langs->trans("FacturelectVatexHelpTitle");
		print '      </h3>';
		print '      <p style="margin:0 0 12px 0; font-size:12px; color:#64748b; line-height:1.5;">' . $langs->trans("FacturelectVatexHelpIntro") . '</p>';

		print '      <table style="width:100%; border-collapse:collapse; font-size:12px; color:#334155;">';
		print '        <tr style="border-bottom:1px solid #e2e8f0; text-align:left; color:#475569;">';
		print '          <th style="padding:6px 8px 6px 0; font-weight:600;">' . $langs->trans("FacturelectVatexColCategory") . '</th>';
		print '          <th style="padding:6px 8px; font-weight:600;">' . $langs->trans("FacturelectVatexColCode") . '</th>';
		print '          <th style="padding:6px 0; font-weight:600;">' . $langs->trans("FacturelectVatexColReason") . '</th>';
		print '        </tr>';
		foreach (array_keys($exempt_categories) as $cat) {
			$exemption = $feActions->resolveVatExemption($cat, $object);
			$cat_label = $langs->trans("FacturelectVatexCat_" . $cat);
			print '        <tr style="border-bottom:1px solid #f1f5f9;">';
			print '          <td style="padding:8px 8px 8px 0; white-space:nowrap;"><code style="background:#f1f5f9; padding:2px 6px; border-radius:5px; font-weight:700; color:#0f172a;">' . dol_escape_htmltag($cat) . '</code> <span style="color:#64748b;">' . dol_escape_htmltag($cat_label) . '</span></td>';
			print '          <td style="padding:8px;"><code style="background:#ecfdf5; color:#047857; padding:2px 6px; border-radius:5px; font-weight:700;">' . dol_escape_htmltag($exemption['reason_code']) . '</code></td>';
			print '          <td style="padding:8px 0; color:#475569;">' . dol_escape_htmltag($exemption['reason']) . '</td>';
			print '        </tr>';
		}
		print '      </table>';

		if ($override_active && count($exempt_categories) > 1) {
			// Single-regime override "steamroller": one forced code applied to several exempt
			// categories at once is almost certainly wrong. Warn explicitly (see AGENTS.md #50).
			print '      <div style="margin-top:12px; padding:8px 12px; background:#fffbeb; border-left:4px solid #f59e0b; border-radius:6px; font-size:12px; color:#92400e; line-height:1.5;">';
			print '        <span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("FacturelectVatexOverrideMultiWarning");
			print '      </div>';
		} elseif ($override_active) {
			print '      <div style="margin-top:12px; padding:8px 12px; background:#f0f9ff; border-left:4px solid #0284c7; border-radius:6px; font-size:12px; color:#075985;">';
			print '        <span class="fa fa-check-circle"></span> ' . $langs->trans("FacturelectVatexOverrideActive");
			print '      </div>';
		} else {
			print '      <div style="margin-top:12px; padding:8px 12px; background:#f8fafc; border-left:4px solid #cbd5e1; border-radius:6px; font-size:12px; color:#64748b; line-height:1.5;">';
			print '        <span class="fa fa-lightbulb" style="color:#f59e0b;"></span> ' . $langs->trans("FacturelectVatexOverrideHint");
			print '      </div>';
		}
		print '    </div>';
	}
}

print '  </div>'; // End Left Half

print '  <div class="fichehalfright">';

// Right Half: Timeline of Events
if (!empty($pdp_id)) {
	if (empty($inv_data)) {
		print '    <div class="fe-card" style="background:#fff; border:1px solid #cbd5e1; border-radius:12px; padding:20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); font-family:\'Outfit\', \'Inter\', sans-serif;">';
		print '      <h3 style="margin:0 0 15px 0; font-size:16px; font-weight:700; color:#0f172a; border-bottom:1px solid #e2e8f0; padding-bottom:8px; display:flex; align-items:center; gap:8px;">';
		print '        <span class="fa fa-exclamation-triangle" style="color:#ef4444; font-size:20px;"></span> ' . $langs->trans("FacturelectTabErrorTitle", $provider_name);
		print '      </h3>';
		print '      <div style="color:#64748b; font-size:13px; line-height:1.5;">';
		print '        <p>' . $langs->trans("FacturelectTabErrorDesc", $provider_name) . '</p>';
		print '        <p style="background:#fef2f2; color:#991b1b; padding:10px; border-radius:6px; border-left:4px solid #ef4444; font-family:monospace; font-size:12px; margin:10px 0;">' . (!empty($client->error) ? dol_escape_htmltag($client->error) : 'Erreur inconnue') . '</p>';
		print '        <p style="margin-top:10px; font-size:12px;">' . $langs->trans("FacturelectTabErrorHelp") . '</p>';
		print '      </div>';
		print '    </div>';
	} elseif (!empty($inv_data['events'])) {
		print '    <div class="fe-card" style="background:#fff; border:1px solid #cbd5e1; border-radius:12px; padding:20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); font-family:\'Outfit\', \'Inter\', sans-serif;">';
		print '      <h3 style="margin:0 0 15px 0; font-size:16px; font-weight:700; color:#0f172a; border-bottom:1px solid #e2e8f0; padding-bottom:8px; display:flex; align-items:center; gap:8px;">';
		print '        <span class="fa fa-history" style="color:#0284c7; font-size:20px;"></span> ' . $langs->trans("FacturelectTabTimelineTitle");
		print '      </h3>';
	
		print '      <div class="fe-timeline" style="position:relative; padding-left:25px; margin-left:10px; border-left:2px solid #e2e8f0;">';
		
		$events = $inv_data['events'];
		usort($events, function($a, $b) {
			return $a['id'] - $b['id'];
		});
		
		foreach ($events as $index => $evt) {
			$status_code = $evt['status_code'];
			$status_text = $evt['status_text'];
			$created_at = strtotime($evt['created_at']);
			
			$bullet_color = '#cbd5e1';
			$bg_color = '#f8fafc';
			$text_color = '#334155';
			$icon = 'fa-circle';
			
			if ($status_code === 'api:uploaded') {
				$bullet_color = '#0284c7';
				$bg_color = '#f0f9ff';
				$icon = 'fa-file-upload';
			} elseif (strpos($status_code, 'fr:200') !== false || strpos($status_code, 'fr:201') !== false || strpos($status_code, 'fr:202') !== false || $status_code === 'api:validated' || $status_code === 'api:sent') {
				$bullet_color = '#10b981';
				$bg_color = '#ecfdf5';
				$icon = 'fa-check';
			} elseif (strpos($status_code, 'fr:213') !== false || strpos($status_code, 'fr:210') !== false || $status_code === 'api:invalid' || $status_code === 'api:rejected' || strpos($status_code, 'ack-error') !== false) {
				$bullet_color = '#ef4444';
				$bg_color = '#fef2f2';
				$text_color = '#991b1b';
				$icon = 'fa-times-circle';
			} elseif (strpos($status_code, 'fr:212') !== false || strpos($status_code, 'fr:211') !== false) {
				$bullet_color = '#8b5cf6';
				$bg_color = '#f5f3ff';
				$icon = 'fa-money-bill-wave';
			}
			
			$is_latest = ($index === count($events) - 1);
			$latest_border = $is_latest ? 'border: 1px solid ' . $bullet_color . '; box-shadow:0 2px 4px rgba(0,0,0,0.02);' : '';
			
			print '        <div style="position:relative; margin-bottom:15px;">';
			print '          <span style="position:absolute; left:-34px; top:4px; width:14px; height:14px; border-radius:50%; background:#fff; border:3px solid ' . $bullet_color . '; z-index:2; box-shadow:0 0 0 3px #fff;"></span>';
			print '          <div style="background:' . $bg_color . '; padding:10px 15px; border-radius:8px; ' . $latest_border . '">';
			print '            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:5px;">';
			print '              <strong style="font-size:12px; color:' . $text_color . ';"><span class="fa ' . $icon . '" style="color:' . $bullet_color . '; margin-right:5px;"></span> ' . dol_escape_htmltag($status_text) . '</strong>';
			print '              <span style="font-size:11px; color:#64748b;">' . dol_print_date($created_at, 'dayhour') . '</span>';
			print '            </div>';
			print '            <div style="font-size:10px; color:#64748b; margin-top:3px;">';
			print '              Code technique : <code style="background:rgba(0,0,0,0.04); padding:1px 4px; border-radius:3px; font-size:9px;">' . $status_code . '</code>';
			print '            </div>';
			
			if (!empty($evt['details'])) {
				foreach ($evt['details'] as $detail) {
					if (!empty($detail['reason'])) {
						print '            <div style="margin-top:6px; padding:6px 10px; background:#fff; border-left:3px solid #ef4444; border-radius:4px; font-size:11px; color:#b91c1c; font-weight:500;">';
						print '              <span class="fa fa-info-circle"></span> Motif du rejet : <strong>' . dol_escape_htmltag($detail['reason']) . '</strong>';
						print '            </div>';
					}
				}
			}
			if (!empty($evt['data']) && !empty($evt['data']['reason'])) {
				print '            <div style="margin-top:6px; padding:6px 10px; background:#fff; border-left:3px solid #ef4444; border-radius:4px; font-size:11px; color:#b91c1c; font-weight:500;">';
				print '              <span class="fa fa-info-circle"></span> Motif : <strong>' . dol_escape_htmltag($evt['data']['reason']) . '</strong>';
				print '            </div>';
			}
			
			print '          </div>';
			print '        </div>';
		}
		
		print '      </div>';
		print '    </div>';
	}
}
print '  </div>'; // End Right Half
print '</div>'; // End Fichecenter

// Print tab footers
print dol_get_fiche_end();

llxFooter();
$db->close();
