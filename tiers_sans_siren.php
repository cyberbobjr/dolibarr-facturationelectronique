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
 *	\file       custom/facturationelectronique/tiers_sans_siren.php
 *	\ingroup    facturationelectronique
 *	\brief      List of third parties without SIREN with inline SIREN lookup modal
 */

require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

if (!class_exists('FacturelectClient')) {
	require_once './class/facturelectclient.class.php';
}

// Access control
if (!$user->rights->facturation_electronique->lire && !$user->rights->societe->lire) {
	accessforbidden();
}
if (!getDolGlobalInt('FACTURELECT_FEATURE_SIREN', 1)) {
	llxHeader('', 'Tiers sans SIREN');
	print '<div class="info">La fonctionnalité de <strong>gestion SIREN</strong> est désactivée. Activez-la dans <a href="' . dol_buildpath('/facturationelectronique/admin/setup.php', 1) . '">Configuration > Fonctionnalités</a>.</div>';
	llxFooter();
	exit;
}

$langs->loadLangs(array("companies", "facturation_electronique@facturationelectronique"));

$form = new Form($db);
$client = new FacturelectClient($db);
$provider_name = $client->getProviderName();

// Parameters
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTINT('page');
$limit = getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25);
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;

if (empty($sortfield)) {
	$sortfield = 's.nom';
}
if (empty($sortorder)) {
	$sortorder = 'ASC';
}

// Search filters
$search_name = GETPOST('search_name', 'alpha');
$search_type = GETPOST('search_type', 'alpha');

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter.x', 'alpha')) {
	$search_name = '';
	$search_type = '';
}

// Build SQL
// NB: Dolibarr 20+ replaced the legacy idprof1 column with a native `siren` column on llx_societe.
// Raw SQL must target s.siren only (s.idprof1 no longer exists). See AGENTS.md #13.
$sql_where = " WHERE (s.siren IS NULL OR s.siren = '')";
$sql_where .= " AND (s.client > 0 OR s.fournisseur > 0)";
$sql_where .= " AND s.entity = " . ((int) $conf->entity);
$sql_where .= " AND s.status = 1";

if (!empty($search_name)) {
	$sql_where .= " AND s.nom LIKE '%" . $db->escape($search_name) . "%'";
}
if ($search_type === 'client') {
	$sql_where .= " AND s.client > 0";
} elseif ($search_type === 'fournisseur') {
	$sql_where .= " AND s.fournisseur > 0";
}

// Count total
$sql_count = "SELECT COUNT(s.rowid) AS nb FROM " . MAIN_DB_PREFIX . "societe s" . $sql_where;
$res_count = $db->query($sql_count);
$num = 0;
if ($res_count) {
	$row_count = $db->fetch_object($res_count);
	$num = (int) $row_count->nb;
}

// Fetch rows
$sql = "SELECT s.rowid, s.nom, s.zip, s.town, s.client, s.fournisseur, s.code_client, s.code_fournisseur";
$sql .= " FROM " . MAIN_DB_PREFIX . "societe s";
$sql .= $sql_where;
$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$res = $db->query($sql);
$rows = array();
if ($res) {
	while ($row = $db->fetch_object($res)) {
		$rows[] = $row;
	}
}
$has_more = (count($rows) > $limit);
if ($has_more) {
	array_pop($rows);
}

// Preserve filter params for pagination links
$param = '';
if (!empty($search_name)) {
	$param .= '&search_name=' . urlencode($search_name);
}
if (!empty($search_type)) {
	$param .= '&search_type=' . urlencode($search_type);
}
if (!empty($sortfield)) {
	$param .= '&sortfield=' . urlencode($sortfield);
}
if (!empty($sortorder)) {
	$param .= '&sortorder=' . urlencode($sortorder);
}

// CSS
$cssfile = dol_buildpath('/facturationelectronique/css/facturation_electronique.css', 0);
$cssurl = dol_buildpath('/facturationelectronique/css/facturation_electronique.css', 1);
if (file_exists($cssfile)) {
	$cssurl .= '?v=' . filemtime($cssfile);
}

llxHeader('', 'Tiers sans SIREN', '', '', '', '', array($cssurl));

print '<div class="fe-container">';
print '<form method="POST" id="searchFormList" name="searchFormList" action="' . $_SERVER["PHP_SELF"] . '">' . "\n";
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="mainmenu" value="facturelect">';
print '<input type="hidden" name="leftmenu" value="tiers_sans_siren">';

print_barre_liste(
	'Tiers sans SIREN',
	$page,
	$_SERVER["PHP_SELF"],
	$param,
	$sortfield,
	$sortorder,
	'',
	$num,
	$num,
	'company',
	0,
	'',
	'',
	$limit,
	0,
	0,
	1
);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">' . "\n";

// Filter row
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre">';
print '<input class="flat maxwidth150" type="text" name="search_name" value="' . dol_escape_htmltag($search_name) . '" placeholder="Nom...">';
print '</td>';
print '<td class="liste_titre">';
$type_options = array('' => 'Tous', 'client' => 'Client', 'fournisseur' => 'Fournisseur');
print $form->selectarray('search_type', $type_options, $search_type, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
print '</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre" align="center">';
print $form->showFilterButtons();
print '</td>';
print '</tr>';

// Column headers
print '<tr class="liste_titre">';
print_liste_field_titre('Nom du tiers', $_SERVER["PHP_SELF"], 's.nom', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Type', $_SERVER["PHP_SELF"], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Code Postal / Ville', $_SERVER["PHP_SELF"], 's.zip', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Code tiers', $_SERVER["PHP_SELF"], '', '', $param, '', $sortfield, $sortorder);
print '<th class="liste_titre" style="text-align: center;">Action</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr class="oddeven">';
	print '<td colspan="5" class="opacitymedium" style="text-align: center; padding: 20px;">Aucun tiers sans SIREN trouvé.</td>';
	print '</tr>';
} else {
	$i = 0;
	foreach ($rows as $row) {
		$thirdparty = new Societe($db);
		$thirdparty->id = $row->rowid;
		$thirdparty->name = $row->nom;
		$thirdparty->zip = $row->zip;
		$thirdparty->town = $row->town;
		$thirdparty->client = $row->client;
		$thirdparty->fournisseur = $row->fournisseur;
		$thirdparty->code_client = $row->code_client;
		$thirdparty->code_fournisseur = $row->code_fournisseur;

		$type_labels = array();
		if ($row->client > 0) {
			$type_labels[] = '<span class="badge badge-status1">Client</span>';
		}
		if ($row->fournisseur > 0) {
			$type_labels[] = '<span class="badge badge-status4">Fournisseur</span>';
		}

		$town_str = '';
		if (!empty($row->zip)) {
			$town_str .= dol_escape_htmltag($row->zip);
		}
		if (!empty($row->town)) {
			$town_str .= ($town_str ? ' ' : '') . dol_escape_htmltag($row->town);
		}

		$code_str = '';
		if (!empty($row->code_client)) {
			$code_str .= dol_escape_htmltag($row->code_client);
		}
		if (!empty($row->code_fournisseur)) {
			$code_str .= ($code_str ? ' / ' : '') . dol_escape_htmltag($row->code_fournisseur);
		}

		print '<tr class="oddeven">';

		// Name with link
		print '<td class="tdoverflowmax200">';
		print $thirdparty->getNomUrl(1);
		print '</td>';

		// Type badges
		print '<td>';
		print implode(' ', $type_labels);
		print '</td>';

		// Zip / Town
		print '<td>' . $town_str . '</td>';

		// Code
		print '<td class="opacitymedium">' . $code_str . '</td>';

		// SIREN lookup button
		print '<td style="text-align: center;">';
		print '<a href="#" class="butAction fe-btn-primary fe-btn-sm" ';
		print 'onclick="feOpenModalForRow(' . (int) $row->rowid . ', \'' . dol_escape_js($row->nom) . '\', \'' . dol_escape_js($row->zip) . '\'); return false;">';
		print '<span class="fa fa-search paddingrightonly"></span> Rechercher SIREN';
		print '</a>';
		print '</td>';

		print '</tr>';
		$i++;
	}
}

print '</table>';
print '</div>';
print '</form>';
print '</div>';

// Modal JS — inline equivalent of the hook-injected modal on thirdpartycard
$siren_lookup_url = dol_buildpath('/facturationelectronique/siren_lookup.php', 1);
?>
<script type="text/javascript">
let feSocId = 0;
let fePrefillName = '';
let fePrefillZip = '';
let feCurrentCompanies = [];

function feOpenModalForRow(socid, name, zip) {
	feSocId = socid;
	fePrefillName = name;
	fePrefillZip = zip;
	feOpenModal(socid);
}

function feOpenModal(socid) {
	feSocId = socid;
	if (!document.getElementById('fe-siren-modal')) {
		injectModalHtml();
	}
	document.getElementById('fe-search-name').value = fePrefillName;
	document.getElementById('fe-search-zip').value = fePrefillZip;
	document.getElementById('fe-modal-content').innerHTML = '';
	document.getElementById('fe-siren-modal').classList.remove('fe-hidden');
}

function feCloseModal() {
	const modal = document.getElementById('fe-siren-modal');
	if (modal) modal.classList.add('fe-hidden');
}

function injectModalHtml() {
	const html = `
		<div id="fe-siren-modal" class="fe-modal-overlay fe-hidden">
			<div class="fe-modal-container">
				<div class="fe-modal-header">
					<h3><span class="fa fa-search"></span> Annuaire des Entreprises (<?php echo dol_escape_js($provider_name); ?>)</h3>
					<button type="button" class="fe-modal-close" onclick="feCloseModal()">&times;</button>
				</div>
				<div class="fe-modal-body">
					<div class="fe-modal-form">
						<div class="fe-form-group">
							<label>Nom ou Raison Sociale</label>
							<input type="text" id="fe-search-name" class="fe-input" placeholder="Nom de l'entreprise...">
						</div>
						<div class="fe-form-group">
							<label>Code Postal (Département)</label>
							<input type="text" id="fe-search-zip" class="fe-input" placeholder="Ex: 75 ou 75002...">
						</div>
						<button type="button" class="fe-btn fe-btn-primary" onclick="fePerformSearch()">Rechercher</button>
						<div class="fe-form-group" style="grid-column: 1 / -1; margin-top: 10px; display: flex; align-items: center; gap: 8px;">
							<input type="checkbox" id="fe-update-details" checked style="margin: 0; width: 16px; height: 16px; cursor: pointer;">
							<label for="fe-update-details" style="font-size: 12px; font-weight: normal; color: #475569; margin: 0; cursor: pointer;">
								Mettre également à jour le nom et l'adresse du tiers avec les données officielles de l'annuaire
							</label>
						</div>
					</div>
					<div id="fe-modal-loader" class="fe-modal-loader fe-hidden">
						<span class="fa fa-spinner fa-spin"></span> Recherche en cours...
					</div>
					<div id="fe-modal-content" class="fe-modal-content"></div>
				</div>
			</div>
		</div>
	`;
	document.body.insertAdjacentHTML('beforeend', html);
}

function fePerformSearch() {
	const name = document.getElementById('fe-search-name').value;
	const zip = document.getElementById('fe-search-zip').value;
	const loader = document.getElementById('fe-modal-loader');
	const content = document.getElementById('fe-modal-content');

	if (!name) { alert('Veuillez saisir un nom pour la recherche.'); return; }

	loader.classList.remove('fe-hidden');
	content.innerHTML = '';
	feCurrentCompanies = [];

	fetch('<?php echo $siren_lookup_url; ?>?action=search_companies&name=' + encodeURIComponent(name) + '&zip=' + encodeURIComponent(zip))
		.then(r => r.json())
		.then(data => {
			loader.classList.add('fe-hidden');
			if (data.success && data.companies && data.companies.length > 0) {
				feCurrentCompanies = data.companies;
				let html = '<div class="fe-companies-list"><h4>Entreprises trouvées (' + data.companies.length + ')</h4>';
				data.companies.forEach((company, index) => {
					html += `
						<div class="fe-company-card">
							<div class="fe-company-info">
								<div class="fe-company-name">${company.formal_name}</div>
								<div class="fe-company-details">
									<strong>SIREN :</strong> ${company.number}<br/>
									<strong>Adresse :</strong> ${company.address}, ${company.postcode} ${company.city}
								</div>
							</div>
							<button type="button" class="fe-btn fe-btn-secondary" onclick="feSelectCompanyByIndex(${index})">Sélectionner</button>
						</div>
					`;
				});
				html += '</div>';
				content.innerHTML = html;
			} else {
				content.innerHTML = '<div class="fe-no-results">Aucune entreprise correspondante trouvée.</div>';
			}
		})
		.catch(() => {
			loader.classList.add('fe-hidden');
			content.innerHTML = '<div class="fe-no-results fe-text-danger">Erreur lors de la communication avec le serveur.</div>';
		});
}

function feSelectCompanyByIndex(index) {
	const company = feCurrentCompanies[index];
	if (!company) return;
	const loader = document.getElementById('fe-modal-loader');
	const content = document.getElementById('fe-modal-content');
	loader.classList.remove('fe-hidden');
	content.innerHTML = '';

	fetch('<?php echo $siren_lookup_url; ?>?action=get_entries&siren=' + company.number)
		.then(r => r.json())
		.then(data => {
			loader.classList.add('fe-hidden');
			if (data.success && data.entries && data.entries.length > 0) {
				let html = '<div class="fe-entries-list"><h4>Établissements / Adresses de facturation active</h4>';
				html += '<table class="fe-results-table"><thead><tr><th>Établissement / ID PEPPOL</th><th>Statut</th><th>Action</th></tr></thead><tbody>';
				data.entries.forEach(entry => {
					const parts = entry.identifier.split(':');
					const val = parts.length > 1 ? parts[1] : entry.identifier;
					let label = company.number;
					if (val.includes('*')) {
						const sub = val.split('*');
						label = 'SIRET: ' + sub[0] + sub[1] + ' (NIC ' + sub[1] + ')';
					} else {
						label = 'SIREN unique (Siège social)';
					}
					const statusClass = entry.is_active ? 'success' : 'danger';
					const statusText = entry.is_active ? 'Actif' : 'Inactif';
					html += `
						<tr>
							<td><strong>${label}</strong><br/><span class="fe-peppol-id">${entry.identifier}</span></td>
							<td><span class="fe-status-pill ${statusClass}">${statusText}</span></td>
							<td><button type="button" class="fe-btn fe-btn-primary fe-btn-sm" onclick="feAssociateTiers(${index}, '${entry.identifier}', '${entry.scheme}')">Associer</button></td>
						</tr>
					`;
				});
				html += '</tbody></table>';
				html += `<div style="margin-top: 15px; text-align: right;">
					<button type="button" class="fe-btn fe-btn-secondary" onclick="feAssociateTiers(${index}, '${company.number}', '0225')">
						Associer le SIREN uniquement (sans établissement spécifique)
					</button>
				</div>`;
				content.innerHTML = html;
			} else {
				let html = '<div class="fe-no-results">Aucun établissement enregistré dans l\'annuaire PEPPOL pour cette entreprise.</div>';
				html += `<div style="margin-top: 15px; text-align: center;">
					<button type="button" class="fe-btn fe-btn-primary" onclick="feAssociateTiers(${index}, '${company.number}', '0225')">
						Associer quand même le SIREN uniquement
					</button>
				</div>`;
				content.innerHTML = html;
			}
		})
		.catch(() => {
			loader.classList.add('fe-hidden');
			content.innerHTML = '<div class="fe-no-results fe-text-danger">Erreur lors de la récupération des établissements.</div>';
		});
}

function feAssociateTiers(companyIndex, identifier, scheme) {
	const company = feCurrentCompanies[companyIndex];
	if (!company) return;

	const updateDetails = document.getElementById('fe-update-details').checked ? 1 : 0;
	let msg;
	if (updateDetails) {
		msg = `Attention : En associant ce tiers, les informations locales de votre Dolibarr seront écrasées et synchronisées par les coordonnées officielles de l'annuaire :\n\n` +
		      `- Nom : ${company.formal_name}\n- Adresse : ${company.address}\n- Code Postal : ${company.postcode}\n- Ville : ${company.city}\n\nSouhaitez-vous continuer ?`;
	} else {
		msg = `Souhaitez-vous associer le SIREN et l'adresse de réception électronique pour ce tiers sans modifier son nom et son adresse locale ?`;
	}
	if (!confirm(msg)) return;

	const loader = document.getElementById('fe-modal-loader');
	const content = document.getElementById('fe-modal-content');
	loader.classList.remove('fe-hidden');
	content.innerHTML = '';

	const params = new URLSearchParams({
		action: 'update_tiers',
		socid: feSocId,
		identifier: identifier,
		scheme: scheme,
		name: company.formal_name,
		address: company.address,
		zip: company.postcode,
		city: company.city,
		update_details: updateDetails
	});

	fetch('<?php echo $siren_lookup_url; ?>?' + params.toString())
		.then(r => r.json())
		.then(data => {
			loader.classList.add('fe-hidden');
			if (data.success) {
				content.innerHTML = `
					<div class="fe-alert fe-alert-success">
						<span class="fa fa-check-circle" style="font-size:24px;"></span>
						<div>
							<strong>Association réussie !</strong><br/>
							SIREN : ${data.siren}${data.siret ? '<br/>SIRET : ' + data.siret : ''}<br/>
							Identifiant PEPPOL : ${data.scheme}:${data.identifier}
						</div>
					</div>
				`;
				setTimeout(() => { feCloseModal(); location.reload(); }, 2000);
			} else {
				content.innerHTML = `
					<div class="fe-alert fe-alert-danger">
						<span class="fa fa-exclamation-triangle" style="font-size:24px;"></span>
						<div><strong>Erreur lors de l'association</strong><br/>${data.error}</div>
					</div>
					<div style="text-align: center; margin-top:15px;">
						<button type="button" class="fe-btn fe-btn-secondary" onclick="feSelectCompanyByIndex(${companyIndex})">Retour</button>
					</div>
				`;
			}
		})
		.catch(() => {
			loader.classList.add('fe-hidden');
			content.innerHTML = '<div class="fe-no-results fe-text-danger">Erreur réseau lors de l\'association.</div>';
		});
}
</script>
<?php

llxFooter();
