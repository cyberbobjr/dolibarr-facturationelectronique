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
 *	\file       htdocs/custom/facturation_electronique/outbound_list.php
 *	\ingroup    facturation_electronique
 *	\brief      List page for outgoing customer invoices sent via PDP
 */

// Bootstrap Dolibarr
require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

if (!class_exists('FacturelectClient')) {
	require_once './class/facturelectclient.class.php';
}
if (!class_exists('ActionsFacturationelectronique')) {
	require_once './class/actions_facturationelectronique.class.php';
}

// Access control
if (!$user->rights->facture->lire) {
	accessforbidden();
}

$langs->loadLangs(array("admin", "bills", "companies", "facturation_electronique@facturationelectronique"));

// Get parameters
$action = GETPOST('action', 'aZ09');
$search_ref = GETPOST('search_ref', 'alpha');
$search_client = GETPOST('search_client', 'alpha');
$search_siren = GETPOST('search_siren', 'alpha');
$search_status = GETPOST('search_status', 'alpha');

// Clear filters
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter.x', 'alpha')) {
	$search_ref = '';
	$search_client = '';
	$search_siren = '';
	$search_status = '';
}

// Pagination & Sorting parameters
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma') ? GETPOST('sortfield', 'aZ09comma') : 'f.datef';
$sortorder = GETPOST('sortorder', 'aZ09comma') ? GETPOST('sortorder', 'aZ09comma') : 'DESC';
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;

// Standard forms instantiations
$form = new Form($db);

$client = new FacturelectClient($db);
$hook = new ActionsFacturationelectronique($db);

$msg_success = '';
$msg_error = '';

$sync_statuses_triggered = false;
$sync_statuses_count = 0;

if ($action === 'sync_status') {
	$sync_statuses_triggered = true;
	$sql_sync = "SELECT f.rowid, ex.facturelect_invoice_id FROM " . MAIN_DB_PREFIX . "facture as f";
	$sql_sync .= " INNER JOIN " . MAIN_DB_PREFIX . "facture_extrafields as ex ON f.rowid = ex.fk_object";
	$sql_sync .= " WHERE ex.facturelect_invoice_id IS NOT NULL AND ex.facturelect_invoice_id != ''";
	$resql_sync = $db->query($sql_sync);
	if ($resql_sync) {
		while ($obj_sync = $db->fetch_object($resql_sync)) {
			$inv_data = $client->getInvoice($obj_sync->facturelect_invoice_id);
			if ($inv_data && !empty($inv_data['events'])) {
				// Parse events and get the latest status using unique strictly increasing event ID
				$latest_status = '';
				$max_event_id = 0;
				foreach ($inv_data['events'] as $evt) {
					if ($evt['id'] > $max_event_id) {
						$max_event_id = $evt['id'];
						$latest_status = $evt['status_code'];
					}
				}
				
				// Map status code to Dolibarr extrafield status
				$new_status = 'transmitted';
				if (in_array($latest_status, array('api:invalid', 'api:rejected', 'fr:210', 'fr:213', 'ppf:validated-ack-error', 'ppf:refused-ack-error', 'ppf:rejected-ack-error', 'ppf:flow-1-ack-error', 'ppf:flow-1-rejected'))) {
					$new_status = 'failed';
				} elseif (in_array($latest_status, array('api:uploaded', 'api:validated', 'fr:200'))) {
					$new_status = 'queued';
				}
				
				// Update extrafields safely according to AGENTS.md Rule 9
				$invoice_obj = new Facture($db);
				if ($invoice_obj->fetch($obj_sync->rowid) > 0) {
					if ($invoice_obj->array_options['options_facturelect_status'] !== $new_status) {
						$invoice_obj->array_options['options_facturelect_status'] = $new_status;
						$invoice_obj->updateExtraField('facturelect_status');
						$sync_statuses_count++;
					}
				}
			}
		}
		$db->free($resql_sync);
	}
}

// Handle manual transmission trigger
if ($action === 'send') {
	$facid = GETPOST('id', 'int');
	if ($facid > 0) {
		$invoice = new Facture($db);
		if ($invoice->fetch($facid) > 0) {
			if ($invoice->statut == 0) {
				$msg_error = "Impossible d'envoyer une facture à l'état de brouillon. Veuillez la valider au préalable.";
			} else {
				// Fetch lines and extrafields
				$invoice->fetch_lines();

				// 1. Compile standard en_invoice JSON
				$en_invoice = $hook->buildEnInvoiceJson($invoice);
				if (!$en_invoice) {
					$msg_error = $hook->error;
				} else {
					// 2. Convert and send
					// Generate native Dolibarr PDF if missing to preserve layout
					$pdf_dir = $conf->facture->dir_output . '/' . dol_sanitizeFileName($invoice->ref);
					$pdf_file = $pdf_dir . '/' . dol_sanitizeFileName($invoice->ref) . '.pdf';
					if (!file_exists($pdf_file)) {
						$model = !empty($invoice->model_pdf) ? $invoice->model_pdf : 'crabe';
						$invoice->generateDocument($model, $langs);
					}

					$pdf_content = $client->convertInvoiceToFacturX($en_invoice, file_exists($pdf_file) ? $pdf_file : '');
					if ($pdf_content === false) {
						$msg_error = "Erreur de conversion Factur-X : " . $client->error;
					} else {
						// Upload/Send endpoint
						$send_res = $client->sendFacturXInvoice($pdf_content, $invoice->ref);
						if ($send_res === false) {
							$msg_error = "Erreur de transmission " . $client->getProviderName() . " : " . $client->error;
						} else {
							$pdp_id = $send_res['id'];

							// Trigger SuperPDP async processing: the backend only starts validation when the invoice is polled
							$client->getInvoice($pdp_id);

							// 1. Update Extrafields safely according to AGENTS.md Rule 9 (triggers might regenerate standard PDF here, so we do it first)
							$invoice->array_options['options_facturelect_invoice_id'] = $pdp_id;
							$invoice->array_options['options_facturelect_status'] = 'transmitted';
							$invoice->updateExtraField('facturelect_invoice_id');
							$invoice->updateExtraField('facturelect_status');

							// 2. Overwrite local Dolibarr generated PDF with the certified Factur-X PDF
							$upload_dir = $conf->facture->dir_output . '/' . dol_sanitizeFileName($invoice->ref);
							require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
							// Ensure the directory exists
							dol_mkdir($upload_dir);

							$file_name = dol_sanitizeFileName($invoice->ref) . '_facturX.pdf';
							$dest_path = $upload_dir . '/' . $file_name;
							// Write the Factur-X PDF content (coexisting with the original PDF)
							file_put_contents($dest_path, $pdf_content);

							// 3. Register the file in the database index so it's tracked in the document manager
							if (file_exists($dest_path)) {
								addFileIntoDatabaseIndex($upload_dir, $file_name, '', 'generated', 0, $invoice);
							}

							$msg_success = "Facture client <strong>" . $invoice->ref . "</strong> transmise avec succès ! ID Technique : " . $pdp_id;
						}
					}
				}
			}
		} else {
			$msg_error = "Facture introuvable.";
		}
	}
}

// Fields for standard Dolibarr list customization
$arrayfields = array(
	'f.ref' => array('label' => 'Réf. Dolibarr', 'checked' => 1, 'position' => 10),
	's.nom' => array('label' => 'Client', 'checked' => 1, 'position' => 20),
	's.siren' => array('label' => 'SIREN', 'checked' => 1, 'position' => 30),
	'f.datef' => array('label' => 'Date Facture', 'checked' => 1, 'position' => 40),
	'f.total_ht' => array('label' => 'Total HT', 'checked' => 1, 'position' => 50),
	'f.total_ttc' => array('label' => 'Total TTC', 'checked' => 1, 'position' => 60),
	'f.fk_statut' => array('label' => 'Statut Dolibarr', 'checked' => 1, 'position' => 70),
	'ex.facturelect_status' => array('label' => $langs->trans("FacturelectStatusLabel"), 'checked' => 1, 'position' => 80),
	'ex.facturelect_invoice_id' => array('label' => $langs->trans("FacturelectInvoiceId"), 'checked' => 1, 'position' => 90),
);

// URL parameters
$param = '';
if (!empty($search_ref)) $param .= '&search_ref=' . urlencode($search_ref);
if (!empty($search_client)) $param .= '&search_client=' . urlencode($search_client);
if (!empty($search_siren)) $param .= '&search_siren=' . urlencode($search_siren);
if (!empty($search_status)) $param .= '&search_status=' . urlencode($search_status);
if ($limit > 0 && $limit != $conf->liste_limit) $param .= '&limit=' . ((int) $limit);

// Build SQL Query - COUNT
$sql_count = "SELECT COUNT(f.rowid) as nb";
$sql_count .= " FROM " . MAIN_DB_PREFIX . "facture as f";
$sql_count .= " INNER JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid";
$sql_count .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture_extrafields as ex ON f.rowid = ex.fk_object";
$sql_count .= " WHERE 1 = 1";

if (!empty($search_ref)) {
	$sql_count .= natural_search('f.ref', $search_ref);
}
if (!empty($search_client)) {
	$sql_count .= natural_search('s.nom', $search_client);
}
if (!empty($search_siren)) {
	$sql_count .= natural_search('s.siren', $search_siren);
}
if (!empty($search_status)) {
	if ($search_status === 'not_sent') {
		$sql_count .= " AND (ex.facturelect_status IS NULL OR ex.facturelect_status = '' OR ex.facturelect_status = 'not_sent')";
	} else {
		$sql_count .= " AND ex.facturelect_status = '" . $db->escape($search_status) . "'";
	}
}

$resql_count = $db->query($sql_count);
$nbtotalofrecords = 0;
if ($resql_count) {
	$obj = $db->fetch_object($resql_count);
	$nbtotalofrecords = $obj->nb;
	$db->free($resql_count);
}

// Build SQL Query - SELECT
$sql = "SELECT f.rowid, f.ref, f.total_ht, f.total_ttc, f.datef, f.paye, f.fk_statut, f.type,";
$sql .= " COALESCE((SELECT SUM(pf.amount) FROM " . MAIN_DB_PREFIX . "paiement_facture pf WHERE pf.fk_facture = f.rowid), 0) as total_regle,";
$sql .= " s.rowid as socid, s.nom as client_name, s.siren as siren,";
$sql .= " ex.facturelect_invoice_id, ex.facturelect_status as pdp_status";
$sql .= " FROM " . MAIN_DB_PREFIX . "facture as f";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture_extrafields as ex ON f.rowid = ex.fk_object";
$sql .= " WHERE 1 = 1";

if (!empty($search_ref)) {
	$sql .= natural_search('f.ref', $search_ref);
}
if (!empty($search_client)) {
	$sql .= natural_search('s.nom', $search_client);
}
if (!empty($search_siren)) {
	$sql .= natural_search('s.siren', $search_siren);
}
if (!empty($search_status)) {
	if ($search_status === 'not_sent') {
		$sql .= " AND (ex.facturelect_status IS NULL OR ex.facturelect_status = '' OR ex.facturelect_status = 'not_sent')";
	} else {
		$sql .= " AND ex.facturelect_status = '" . $db->escape($search_status) . "'";
	}
}

$sql .= " ORDER BY " . $sortfield . " " . $sortorder;
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
$invoices_list = array();
if ($resql) {
	while ($row = $db->fetch_object($resql)) {
		$invoices_list[] = $row;
	}
	$db->free($resql);
}
$num = count($invoices_list);

// Layout headers
llxHeader('', $langs->trans("FacturelectOutboundListTitle"), '');

// Output container class for custom premium touches
print '<div class="fe-container">';

// Success & Error Alerts
if ($sync_statuses_triggered) {
	setEventMessages("<strong>Synchronisation des statuts réussie !</strong> " . ($sync_statuses_count > 0 ? "<strong>" . $sync_statuses_count . "</strong> facture(s) mise(s) à jour." : "Tous les statuts de transmission sont déjà à jour."), null, 'mesgs');
}
if (!empty($msg_success)) {
	setEventMessages($msg_success, null, 'mesgs');
}
if (!empty($msg_error)) {
	setEventMessages($msg_error, null, 'errors');
}

print '<form method="POST" id="searchFormList" name="searchFormList" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="mainmenu" value="facturelect">';
print '<input type="hidden" name="leftmenu" value="outbound">';

$syncbutton = '<a href="' . $_SERVER['PHP_SELF'] . '?action=sync_status&mainmenu=facturelect&leftmenu=outbound" class="butAction fe-btn-sync"><span class="fa fa-sync-alt paddingrightonly"></span> ' . $langs->trans("FacturelectSyncStatuses", "Synchroniser les statuts") . '</a>';

// Native List Bar
print_barre_liste(
	$langs->trans("FacturelectOutboundListTitle"),
	$page,
	$_SERVER["PHP_SELF"],
	$param,
	$sortfield,
	$sortorder,
	'',
	$num,
	$nbtotalofrecords,
	'bill',
	0,
	$syncbutton,
	'',
	$limit,
	0,
	0,
	1
);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">'."\n";

// Fields title search filters row
print '<tr class="liste_titre_filter">';

// Search Ref
if (!empty($arrayfields['f.ref']['checked'])) {
	print '<td class="liste_titre" align="left">';
	print '<input class="flat maxwidth100" type="text" name="search_ref" value="'.dol_escape_htmltag($search_ref).'" placeholder="Réf.">';
	print '</td>';
}
// Search Client
if (!empty($arrayfields['s.nom']['checked'])) {
	print '<td class="liste_titre" align="left">';
	print '<input class="flat maxwidth150" type="text" name="search_client" value="'.dol_escape_htmltag($search_client).'" placeholder="Client">';
	print '</td>';
}
// Search SIREN
if (!empty($arrayfields['s.siren']['checked'])) {
	print '<td class="liste_titre" align="left">';
	print '<input class="flat maxwidth100" type="text" name="search_siren" value="'.dol_escape_htmltag($search_siren).'" placeholder="SIREN">';
	print '</td>';
}
// Date Facture
if (!empty($arrayfields['f.datef']['checked'])) {
	print '<td class="liste_titre" align="center"></td>';
}
// Total HT
if (!empty($arrayfields['f.total_ht']['checked'])) {
	print '<td class="liste_titre" align="right"></td>';
}
// Total TTC
if (!empty($arrayfields['f.total_ttc']['checked'])) {
	print '<td class="liste_titre" align="right"></td>';
}
// Statut Dolibarr
if (!empty($arrayfields['f.fk_statut']['checked'])) {
	print '<td class="liste_titre" align="left"></td>';
}
// Transmission PDP Status
if (!empty($arrayfields['ex.facturelect_status']['checked'])) {
	print '<td class="liste_titre" align="left">';
	print '<select class="flat maxwidth120" name="search_status">';
	print '<option value="">&nbsp;</option>';
	print '<option value="not_sent" ' . ($search_status === 'not_sent' ? 'selected' : '') . '>' . $langs->trans("FacturelectInvoiceStatus_not_sent") . '</option>';
	print '<option value="queued" ' . ($search_status === 'queued' ? 'selected' : '') . '>' . $langs->trans("FacturelectInvoiceStatus_queued") . '</option>';
	print '<option value="transmitted" ' . ($search_status === 'transmitted' ? 'selected' : '') . '>' . $langs->trans("FacturelectInvoiceStatus_transmitted") . '</option>';
	print '<option value="failed" ' . ($search_status === 'failed' ? 'selected' : '') . '>' . $langs->trans("FacturelectInvoiceStatus_failed") . '</option>';
	print '</select>';
	print '</td>';
}
// ID PDP
if (!empty($arrayfields['ex.facturelect_invoice_id']['checked'])) {
	print '<td class="liste_titre" align="center"></td>';
}

// Action buttons (Filter / Purge)
print '<td class="liste_titre center maxwidthsearch actioncolumn">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';

print '</tr>'."\n";

// List Headers (with Sorting links)
print '<tr class="liste_titre">';
if (!empty($arrayfields['f.ref']['checked'])) {
	print_liste_field_titre($arrayfields['f.ref']['label'], $_SERVER['PHP_SELF'], 'f.ref', '', $param, '', $sortfield, $sortorder);
}
if (!empty($arrayfields['s.nom']['checked'])) {
	print_liste_field_titre($arrayfields['s.nom']['label'], $_SERVER['PHP_SELF'], 's.nom', '', $param, '', $sortfield, $sortorder);
}
if (!empty($arrayfields['s.siren']['checked'])) {
	print_liste_field_titre($arrayfields['s.siren']['label'], $_SERVER['PHP_SELF'], 's.siren', '', $param, '', $sortfield, $sortorder);
}
if (!empty($arrayfields['f.datef']['checked'])) {
	print_liste_field_titre($arrayfields['f.datef']['label'], $_SERVER['PHP_SELF'], 'f.datef', '', $param, '', $sortfield, $sortorder, 'center ');
}
if (!empty($arrayfields['f.total_ht']['checked'])) {
	print_liste_field_titre($arrayfields['f.total_ht']['label'], $_SERVER['PHP_SELF'], 'f.total_ht', '', $param, '', $sortfield, $sortorder, 'right ');
}
if (!empty($arrayfields['f.total_ttc']['checked'])) {
	print_liste_field_titre($arrayfields['f.total_ttc']['label'], $_SERVER['PHP_SELF'], 'f.total_ttc', '', $param, '', $sortfield, $sortorder, 'right ');
}
if (!empty($arrayfields['f.fk_statut']['checked'])) {
	print_liste_field_titre($arrayfields['f.fk_statut']['label'], $_SERVER['PHP_SELF'], 'f.fk_statut', '', $param, '', $sortfield, $sortorder);
}
if (!empty($arrayfields['ex.facturelect_status']['checked'])) {
	print_liste_field_titre($arrayfields['ex.facturelect_status']['label'], $_SERVER['PHP_SELF'], 'ex.facturelect_status', '', $param, '', $sortfield, $sortorder);
}
if (!empty($arrayfields['ex.facturelect_invoice_id']['checked'])) {
	print_liste_field_titre($arrayfields['ex.facturelect_invoice_id']['label'], $_SERVER['PHP_SELF'], 'ex.facturelect_invoice_id', '', $param, '', $sortfield, $sortorder, 'center ');
}
print_liste_field_titre($langs->trans("Action"), $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'center maxwidthsearch actioncolumn');
print '</tr>'."\n";

// List Rows
if ($num > 0) {
	$facture_static = new Facture($db);
	$thirdparty_static = new Societe($db);

	foreach ($invoices_list as $invoice) {
		// Fetching native URLs
		$facture_static->id = $invoice->rowid;
		$facture_static->ref = $invoice->ref;

		$thirdparty_static->id = $invoice->socid;
		$thirdparty_static->name = $invoice->client_name;

		print '<tr class="oddeven">';

		// Réf Dolibarr
		if (!empty($arrayfields['f.ref']['checked'])) {
			print '<td>' . $facture_static->getNomUrl(1) . '</td>';
		}
		// Client
		if (!empty($arrayfields['s.nom']['checked'])) {
			print '<td>' . $thirdparty_static->getNomUrl(1) . '</td>';
		}
		// SIREN
		if (!empty($arrayfields['s.siren']['checked'])) {
			print '<td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px;">' . (!empty($invoice->siren) ? $invoice->siren : '-') . '</code></td>';
		}
		// Date Facture
		if (!empty($arrayfields['f.datef']['checked'])) {
			print '<td align="center">' . dol_print_date($invoice->datef, 'day') . '</td>';
		}
		// Total HT
		if (!empty($arrayfields['f.total_ht']['checked'])) {
			print '<td align="right" style="font-weight:600;">' . price($invoice->total_ht, 0, $langs, 0, -1, -1, 'EUR') . '</td>';
		}
		// Total TTC
		if (!empty($arrayfields['f.total_ttc']['checked'])) {
			print '<td align="right" style="font-weight:700; color:#0f172a;">' . price($invoice->total_ttc, 0, $langs, 0, -1, -1, 'EUR') . '</td>';
		}
		// Statut Dolibarr
		if (!empty($arrayfields['f.fk_statut']['checked'])) {
			$facture_static->statut = $invoice->fk_statut;
			$facture_static->paye = $invoice->paye;
			$facture_static->type = $invoice->type;
			print '<td>' . $facture_static->getLibStatut(3, (float) $invoice->total_regle) . '</td>';
		}
		// Transmission PDP Status Badge
		if (!empty($arrayfields['ex.facturelect_status']['checked'])) {
			$pdp_status = !empty($invoice->pdp_status) ? $invoice->pdp_status : 'not_sent';
			$pdp_badge_class = 'warning';
			$pdp_badge_label = $langs->trans("FacturelectInvoiceStatus_not_sent");

			if ($pdp_status === 'queued') {
				$pdp_badge_class = 'warning';
				$pdp_badge_label = $langs->trans("FacturelectInvoiceStatus_queued");
			} elseif ($pdp_status === 'transmitted') {
				$pdp_badge_class = 'success';
				$pdp_badge_label = $langs->trans("FacturelectInvoiceStatus_transmitted");
			} elseif ($pdp_status === 'failed') {
				$pdp_badge_class = 'danger';
				$pdp_badge_label = $langs->trans("FacturelectInvoiceStatus_failed");
			}
			print '<td>';
			print '<span class="fe-status-pill ' . $pdp_badge_class . '">' . $pdp_badge_label . '</span>';
			if (!empty($invoice->facturelect_invoice_id)) {
				print ' <a href="#" onclick="feToggleEvents(this, ' . $invoice->rowid . ', ' . $invoice->facturelect_invoice_id . '); return false;" style="margin-left:5px; color:#64748b;" title="Afficher l\'historique des événements">';
				print '<span class="fa fa-history fe-history-icon-' . $invoice->rowid . '"></span>';
				print '</a>';
			}
			print '</td>';
		}
		// ID PDP
		if (!empty($arrayfields['ex.facturelect_invoice_id']['checked'])) {
			print '<td align="center"><code style="font-size:11px; background:#f1f5f9; padding:2px 6px; border-radius:4px;">' . (!empty($invoice->facturelect_invoice_id) ? $invoice->facturelect_invoice_id : '-') . '</code></td>';
		}

		// Action Send button
		print '<td align="center">';
		$pdp_status = !empty($invoice->pdp_status) ? $invoice->pdp_status : 'not_sent';
		if ($invoice->fk_statut > 0 && $pdp_status !== 'transmitted') {
			$send_url = $_SERVER['PHP_SELF'] . '?action=send&id=' . $invoice->rowid . '&mainmenu=facturelect&leftmenu=outbound';
			if (!empty($search_ref)) $send_url .= '&search_ref=' . urlencode($search_ref);
			if (!empty($search_client)) $send_url .= '&search_client=' . urlencode($search_client);
			if (!empty($search_siren)) $send_url .= '&search_siren=' . urlencode($search_siren);
			if (!empty($search_status)) $send_url .= '&search_status=' . urlencode($search_status);

			print '<a href="' . $send_url . '" class="fe-btn fe-btn-primary" style="padding:4px 8px; font-size:11px; border-radius:4px;" title="' . $langs->trans("FacturelectSendInvoiceTooltip") . '">';
			print '<span class="fa fa-paper-plane"></span> ' . $langs->trans("Send");
			print '</a>';
		} else {
			print '<span style="font-size:11px; color:#94a3b8;"><span class="fa fa-check-double"></span> ' . $langs->trans("None") . '</span>';
		}
		print '</td>';

		print '</tr>'."\n";
		if (!empty($invoice->facturelect_invoice_id)) {
			print '<tr id="fe-events-row-' . $invoice->rowid . '" style="display:none; background:#f8fafc;"><td colspan="11" style="padding:15px; border-left:4px solid #0284c7;" id="fe-events-content-' . $invoice->rowid . '"></td></tr>';
		}
		print "\n";
	}
} else {
	print '<tr><td colspan="10" class="opacitymedium" align="center" style="padding: 30px;">';
	print '<span class="fa fa-folder-open" style="font-size: 32px; margin-bottom:10px; display:block; color:#cbd5e1;"></span>';
	print $langs->trans("FacturelectNoOutboundInvoices", "Aucune facture client trouvée");
	print '</td></tr>';
}

print '</table>'."\n";
print '</div>'."\n";

print '</form>'."\n";
print '</div>'; // End fe-container

print '<script type="text/javascript">
function feToggleEvents(link, rowid, pdpId) {
	const row = document.getElementById("fe-events-row-" + rowid);
	const content = document.getElementById("fe-events-content-" + rowid);
	const icon = document.querySelector(".fe-history-icon-" + rowid);
	
	if (row.style.display === "none") {
		row.style.display = "table-row";
		if (content.innerHTML === "") {
			content.innerHTML = \'<div style="padding:10px; color:#64748b;"><span class="fa fa-spinner fa-spin"></span> Chargement des événements...</div>\';
			fetch("' . dol_buildpath('/facturationelectronique/ajax_events.php', 1) . '?id=" + pdpId)
				.then(response => response.text())
				.then(html => {
					content.innerHTML = html;
				})
				.catch(err => {
					content.innerHTML = \'<div style="color:#ef4444; padding:10px;"><span class="fa fa-exclamation-triangle"></span> Erreur de chargement.</div>\';
				});
		}
		icon.classList.remove("fa-history");
		icon.classList.add("fa-chevron-up");
	} else {
		row.style.display = "none";
		icon.classList.remove("fa-chevron-up");
		icon.classList.add("fa-history");
	}
}
</script>';

llxFooter();
$db->close();
