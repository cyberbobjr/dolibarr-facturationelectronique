<?php
/* Copyright (C) 2026 Benjamin Marchand <contact@superpdp.tech>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       htdocs/custom/facturation_electronique/inbound_list.php
 *	\ingroup    facturation_electronique
 *	\brief      List page for incoming supplier invoices received via PDP with checkbox selection
 */

// Bootstrap Dolibarr
require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';

if (!class_exists('FacturelectClient')) {
	require_once './class/facturelectclient.class.php';
}

// Access control
if (!$user->rights->fournisseur->facture->lire) {
	accessforbidden();
}
if (!getDolGlobalInt('FACTURELECT_FEATURE_EINVOICING', 1)) {
	llxHeader('', 'Facturation Électronique');
	print '<div class="info">La fonctionnalité de <strong>transmission électronique</strong> est désactivée. Activez-la dans <a href="' . dol_buildpath('/facturationelectronique/admin/setup.php', 1) . '">Configuration > Fonctionnalités</a>.</div>';
	llxFooter();
	exit;
}

$langs->loadLangs(array("admin", "bills", "suppliers", "facturation_electronique@facturationelectronique"));

// Get parameters
$action = GETPOST('action', 'alpha');
$search_ref = GETPOST('search_ref', 'alpha');
$search_supplier = GETPOST('search_supplier', 'alpha');
$search_siren = GETPOST('search_siren', 'alpha');
$search_pdp_id = GETPOST('search_pdp_id', 'alpha');
$search_status = GETPOST('search_status', 'alpha');

// Clear filters
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter.x', 'alpha')) {
	$search_ref = '';
	$search_supplier = '';
	$search_siren = '';
	$search_pdp_id = '';
	$search_status = '';
}

$client = new FacturelectClient($db);
$sync_triggered = false;
$sync_count = 0;
$client_err_msg = '';

// Whether importing incoming supplier invoices is allowed. When disabled, the user
// can only download the raw PDF/XML of each invoice instead of importing it.
$allow_import = getDolGlobalInt('FACTURELECT_ALLOW_IMPORT', 1);

// Handle selective sync action (guarded server-side by the allow-import setting)
if ($action === 'sync_selected') {
	if (!$allow_import) {
		setEventMessages($langs->trans("FacturelectImportDisabledError"), null, 'errors');
	} else {
		$sync_triggered = true;
		$import_ids = GETPOST('import_ids', 'array');
		if (!empty($import_ids)) {
			$sync_res = $client->syncIncomingInvoices($import_ids);
			if ($sync_res !== false) {
				$sync_count = $sync_res;
			} else {
				$client_err_msg = $client->error;
			}
		}
	}
}

// Fetch incoming invoices from SuperPDP API
$invoices = $client->listIncomingInvoices();

// Load imported invoices map from DB
$imported_invoices = array();
$sql = "SELECT facturelect_invoice_id, fk_object FROM " . MAIN_DB_PREFIX . "facture_fourn_extrafields WHERE facturelect_invoice_id IS NOT NULL";
$res = $db->query($sql);
if ($res) {
	while ($row = $db->fetch_object($res)) {
		$imported_invoices[$row->facturelect_invoice_id] = $row->fk_object;
	}
}

// Filter invoices list in PHP based on search parameters
$invoices_data = array();
if ($invoices !== false && !empty($invoices['data'])) {
	$invoices_data = $invoices['data'];
	
	if (!empty($search_ref)) {
		$invoices_data = array_filter($invoices_data, function($inv) use ($search_ref) {
			return stripos($inv['en_invoice']['number'], $search_ref) !== false;
		});
	}
	if (!empty($search_supplier)) {
		$invoices_data = array_filter($invoices_data, function($inv) use ($search_supplier) {
			return stripos($inv['en_invoice']['seller']['name'], $search_supplier) !== false;
		});
	}
	if (!empty($search_siren)) {
		$invoices_data = array_filter($invoices_data, function($inv) use ($search_siren) {
			$seller_siren = '';
			$seller = $inv['en_invoice']['seller'];
			if ($seller && !empty($seller['identifiers'])) {
				foreach ($seller['identifiers'] as $ident) {
					if ($ident['scheme'] === '0225' || $ident['scheme'] === 'fr_siren') {
						$seller_siren = $ident['value'];
						break;
					}
				}
			}
			if (empty($seller_siren) && $seller && !empty($seller['legal_registration_identifier'])) {
				$seller_siren = $seller['legal_registration_identifier']['value'];
			}
			return stripos($seller_siren, $search_siren) !== false;
		});
	}
	if (!empty($search_pdp_id)) {
		$invoices_data = array_filter($invoices_data, function($inv) use ($search_pdp_id) {
			return stripos((string)$inv['id'], $search_pdp_id) !== false;
		});
	}
	if (!empty($search_status) && $search_status !== '-1') {
		$invoices_data = array_filter($invoices_data, function($inv) use ($search_status, $imported_invoices) {
			$pdp_id = $inv['id'];
			$is_imported = isset($imported_invoices[$pdp_id]);
			if ($search_status === 'imported') {
				return $is_imported;
			} elseif ($search_status === 'not_imported') {
				return !$is_imported;
			}
			return true;
		});
	}
}

$num = count($invoices_data);

// Layout headers
$cssfile = dol_buildpath('/facturationelectronique/css/facturation_electronique.css', 0);
$cssurl = dol_buildpath('/facturationelectronique/css/facturation_electronique.css', 1);
if (file_exists($cssfile)) {
	$cssurl .= '?v=' . filemtime($cssfile);
}
llxHeader('', $langs->trans("FacturelectInboundListTitle"), '', '', '', '', array($cssurl));

// Output container class for custom premium touches
print '<div class="fe-container">';

// Inform the user when import is disabled (download-only mode)
if (!$allow_import) {
	print '<div class="info">' . $langs->trans("FacturelectImportDisabledInfo") . '</div>';
}

// Title & Sync Result Alerts
if ($sync_triggered) {
	if ($sync_count !== false) {
		setEventMessages("<strong>Synchronisation réussie !</strong> " . ($sync_count > 0 ? "<strong>" . $sync_count . "</strong> nouvelle(s) facture(s) importée(s) en Brouillon." : "Aucune nouvelle facture importée."), null, 'mesgs');
	} else {
		setEventMessages("Erreur lors de la synchronisation : " . $client_err_msg, null, 'errors');
	}
}

print '<form method="POST" id="searchFormList" name="searchFormList" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="mainmenu" value="facturelect">';
print '<input type="hidden" name="leftmenu" value="inbound">';

// Explicit "fetch from SuperPDP" button. The list is already polled live on each load
// (listIncomingInvoices → GET /invoices?direction=in), so this simply re-polls the provider.
$refresh_url = $_SERVER["PHP_SELF"] . '?mainmenu=facturelect&leftmenu=inbound';
$newcardbutton = '<a class="fe-btn fe-btn-secondary fe-btn-sm" href="' . $refresh_url . '"><span class="fa fa-sync"></span> ' . $langs->trans("FacturelectRefreshInbound") . '</a>';

// Native List Bar
print_barre_liste(
	$langs->trans("FacturelectInboundListTitle"),
	0,
	$_SERVER["PHP_SELF"],
	'',
	'',
	'',
	'',
	$num,
	$num,
	'bill',
	0,
	$newcardbutton,
	'',
	$num,
	0,
	0,
	1
);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">'."\n";

// Fields title search filters row
print '<tr class="liste_titre_filter">';

// Checkbox column filter empty
print '<td class="liste_titre" align="center"></td>';

// Search Ref
print '<td class="liste_titre" align="left">';
print '<input class="flat maxwidth100" type="text" name="search_ref" value="'.dol_escape_htmltag($search_ref).'" placeholder="Réf. Facture">';
print '</td>';

// Search Supplier
print '<td class="liste_titre" align="left">';
print '<input class="flat maxwidth150" type="text" name="search_supplier" value="'.dol_escape_htmltag($search_supplier).'" placeholder="Fournisseur">';
print '</td>';

// Search SIREN
print '<td class="liste_titre" align="left">';
print '<input class="flat maxwidth100" type="text" name="search_siren" value="'.dol_escape_htmltag($search_siren).'" placeholder="SIREN">';
print '</td>';

// Date Facture filter empty
print '<td class="liste_titre" align="center"></td>';

// Total HT filter empty
print '<td class="liste_titre" align="right"></td>';

// Total TTC filter empty
print '<td class="liste_titre" align="right"></td>';

// ID Réseau
print '<td class="liste_titre" align="center">';
print '<input class="flat maxwidth100" type="text" name="search_pdp_id" value="'.dol_escape_htmltag($search_pdp_id).'" placeholder="'.dol_escape_htmltag($langs->trans("FacturelectInvoiceId")).'">';
print '</td>';

// Statut filter
print '<td class="liste_titre" align="center">';
print '<select class="flat" name="search_status" onchange="this.form.submit()">';
print '  <option value="-1"' . ($search_status === '-1' || empty($search_status) ? ' selected' : '') . '></option>';
print '  <option value="imported"' . ($search_status === 'imported' ? ' selected' : '') . '>' . $langs->trans("FacturelectImported") . ' (Importée)</option>';
print '  <option value="not_imported"' . ($search_status === 'not_imported' ? ' selected' : '') . '>' . $langs->trans("FacturelectNotImported") . ' (Non importée)</option>';
print '</select>';
print '</td>';

// Action buttons (Filter / Purge)
print '<td class="liste_titre center maxwidthsearch actioncolumn">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';

print '</tr>'."\n";

// List Headers
print '<tr class="liste_titre">';
if ($allow_import) {
	print '<th class="liste_titre" align="center" style="width: 30px;"><input type="checkbox" id="check-all-inbound" onclick="feToggleAllInbound(this)"></th>';
} else {
	print '<th class="liste_titre" align="center" style="width: 30px;"></th>';
}
print_liste_field_titre('Réf. Facture', $_SERVER['PHP_SELF'], '', '', '', '', '', '');
print_liste_field_titre('Fournisseur', $_SERVER['PHP_SELF'], '', '', '', '', '', '');
print_liste_field_titre('SIREN', $_SERVER['PHP_SELF'], '', '', '', '', '', '');
print_liste_field_titre('Date Facture', $_SERVER['PHP_SELF'], '', '', '', '', '', '', 'center ');
print_liste_field_titre('Montant HT', $_SERVER['PHP_SELF'], '', '', '', '', '', '', 'right ');
print_liste_field_titre('Total TTC', $_SERVER['PHP_SELF'], '', '', '', '', '', '', 'right ');
print_liste_field_titre($langs->trans("FacturelectInvoiceId"), $_SERVER['PHP_SELF'], '', '', '', '', '', '', 'center ');
print_liste_field_titre('Statut Import', $_SERVER['PHP_SELF'], '', '', '', '', '', '', 'center ');
print_liste_field_titre('', $_SERVER['PHP_SELF'], '', '', '', '', '', '', 'center maxwidthsearch actioncolumn');
print '</tr>'."\n";

// List Rows
if ($num > 0) {
	$has_unimported = false;
	
	foreach ($invoices_data as $inv) {
		$pdp_id = $inv['id'];
		$en_invoice = !empty($inv['en_invoice']) ? $inv['en_invoice'] : null;
		if (empty($en_invoice)) {
			continue;
		}

		$inv_number = !empty($en_invoice['number']) ? $en_invoice['number'] : 'Sans numéro';
		$inv_date = !empty($en_invoice['issue_date']) ? dol_print_date(strtotime($en_invoice['issue_date']), 'day') : '';

		$seller = !empty($en_invoice['seller']) ? $en_invoice['seller'] : null;
		$seller_name = !empty($seller['name']) ? $seller['name'] : 'Fournisseur inconnu';
		$seller_siren = '';
		if ($seller && !empty($seller['identifiers'])) {
			foreach ($seller['identifiers'] as $ident) {
				if ($ident['scheme'] === '0225' || $ident['scheme'] === 'fr_siren') {
					$seller_siren = $ident['value'];
					break;
				}
			}
		}
		if (empty($seller_siren) && $seller && !empty($seller['legal_registration_identifier'])) {
			$seller_siren = $seller['legal_registration_identifier']['value'];
		}

		// Search if the thirdparty exists in Dolibarr by SIREN
		$thirdparty_found = false;
		$fk_soc = 0;
		if (!empty($seller_siren)) {
			$clean_siren = preg_replace('/\s+/', '', $seller_siren);
			$sql_check = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE REPLACE(siren, ' ', '') = '" . $db->escape($clean_siren) . "'";
			$res_check = $db->query($sql_check);
			if ($res_check && $db->num_rows($res_check) > 0) {
				$row_check = $db->fetch_object($res_check);
				$thirdparty_found = true;
				$fk_soc = $row_check->rowid;
			}
		}

		$amount_ht = !empty($en_invoice['totals']['total_without_vat']) ? floatval($en_invoice['totals']['total_without_vat']) : 0.0;
		$amount_ttc = !empty($en_invoice['totals']['total_with_vat']) ? floatval($en_invoice['totals']['total_with_vat']) : 0.0;

		$is_imported = isset($imported_invoices[$pdp_id]);

		print '<tr class="oddeven">';
		
		// Selection cell (checkbox only when import is allowed)
		if ($is_imported) {
			print '  <td align="center"><span class="fa fa-check-circle" style="color: #22c55e;"></span></td>';
		} elseif ($allow_import) {
			$has_unimported = true;
			print '  <td align="center"><input type="checkbox" class="inbound-checkbox" name="import_ids[]" value="' . $pdp_id . '"></td>';
		} else {
			print '  <td align="center">-</td>';
		}

		// Réf Facture
		print '<td style="font-weight: 600;">' . dol_escape_htmltag($inv_number) . '</td>';
		
		// Fournisseur
		$supplier_display = dol_escape_htmltag($seller_name);
		if ($thirdparty_found) {
			require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
			$soc_static = new Societe($db);
			$soc_static->id = $fk_soc;
			$soc_static->name = $seller_name;
			$supplier_display = $soc_static->getNomUrl(1) . ' <span class="fe-status-pill success" style="font-size: 9px; padding: 1px 4px; text-transform: none; font-weight: normal; margin-left: 5px;">' . $langs->trans("FacturelectThirdpartyExists") . '</span>';
		} else {
			$supplier_display .= ' <span class="fe-status-pill warning" style="font-size: 9px; padding: 1px 4px; text-transform: none; font-weight: normal; margin-left: 5px;">' . $langs->trans("FacturelectThirdpartyNew") . '</span>';
		}
		print '<td>' . $supplier_display . '</td>';
		
		// SIREN
		print '<td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px;">' . (!empty($seller_siren) ? $seller_siren : '-') . '</code></td>';
		
		// Date Facture
		print '<td align="center">' . $inv_date . '</td>';
		
		// Total HT
		print '<td align="right" style="font-weight:600;">' . price($amount_ht, 0, $langs, 0, -1, -1, 'EUR') . '</td>';
		
		// Total TTC
		print '<td align="right" style="font-weight:700; color:#0f172a;">' . price($amount_ttc, 0, $langs, 0, -1, -1, 'EUR') . '</td>';
		
		// ID PDP
		print '<td align="center"><code style="font-size:11px; background:#f1f5f9; padding:2px 6px; border-radius:4px;">' . $pdp_id . '</code></td>';

		// Statut Import
		if ($is_imported) {
			$fk_facture = $imported_invoices[$pdp_id];
			$fac = new FactureFournisseur($db);
			$fac->fetch($fk_facture);
			$ref_link = '<a href="' . DOL_URL_ROOT . '/fourn/facture/card.php?id=' . $fk_facture . '">' . img_object('', 'bill') . ' ' . dol_escape_htmltag($fac->ref) . '</a>';
			print '  <td align="center"><span class="fe-status-pill success" style="font-size: 10px;">' . $langs->trans("FacturelectImported") . ' (' . $ref_link . ')</span></td>';
		} else {
			print '  <td align="center"><span class="fe-status-pill warning" style="font-size: 10px;">' . $langs->trans("FacturelectNotImported") . '</span></td>';
		}

		// Action column — download the raw PDF/XML file received from the network
		$dl_url = dol_buildpath('/facturationelectronique/download_inbound_invoice.php', 1) . '?id=' . urlencode($pdp_id);
		print '<td align="center"><a class="fe-btn fe-btn-secondary fe-btn-sm" href="' . $dl_url . '"><span class="fa fa-download"></span> ' . $langs->trans("FacturelectDownloadPdf") . '</a></td>';

		print '</tr>'."\n";
	}
} else {
	print '<tr><td colspan="10" class="opacitymedium" align="center" style="padding: 30px;">';
	print '<span class="fa fa-folder-open" style="font-size: 32px; margin-bottom:10px; display:block; color:#cbd5e1;"></span>';
	if ($invoices === false) {
		print '<span style="color: #ef4444;">' . $langs->trans("FacturelectErrorCommunication", $client->getProviderName(), dol_escape_htmltag($client->error)) . '</span>';
	} else {
		print $langs->trans("FacturelectNoInboundInvoices");
	}
	print '</td></tr>';
}

print '</table>'."\n";
print '</div>'."\n";

if ($num > 0 && $has_unimported && $allow_import) {
	print '<div style="margin-top: 20px; text-align: left;">';
	print '  <button type="button" class="fe-btn fe-btn-primary" onclick="submitImport()"><span class="fa fa-download"></span> Importer les factures sélectionnées</button>';
	print '</div>';
}

print '</form>'."\n";
print '</div>'; // End fe-container

?>
<script type="text/javascript">
	function feToggleAllInbound(master) {
		const checkboxes = document.querySelectorAll('.inbound-checkbox');
		checkboxes.forEach(cb => {
			cb.checked = master.checked;
		});
	}

	function submitImport() {
		const checkboxes = document.querySelectorAll('.inbound-checkbox:checked');
		if (checkboxes.length === 0) {
			alert('Veuillez sélectionner au moins une facture à importer.');
			return;
		}
		const form = document.getElementById('searchFormList');
		// Change the action parameter to trigger the sync
		const actionInput = document.createElement('input');
		actionInput.type = 'hidden';
		actionInput.name = 'action';
		actionInput.value = 'sync_selected';
		
		// Remove existing action hidden input if any
		const existingAction = form.querySelector('input[name="action"]');
		if (existingAction) {
			form.removeChild(existingAction);
		}
		
		form.appendChild(actionInput);
		form.submit();
	}
</script>
<?php

llxFooter();
$db->close();
