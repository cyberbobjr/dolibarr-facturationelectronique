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

// 7th argument of llxHeader() is $arrayofjs and the 8th is $arrayofcss: passing the
// stylesheet in the JS slot makes the browser parse the CSS as a script
// ("Uncaught SyntaxError: Unexpected token '.'") and the module styles never apply.
llxHeader('', 'Tiers sans SIREN', '', '', '', '', array(), array($cssurl));

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

// Shared SIREN lookup modal (single source of truth: tpl/siren_modal.tpl.php).
// Rows carry their own third party, so no global prefill is set here.
$fe_modal_socid = 0;
$fe_modal_prefill_name = '';
$fe_modal_prefill_zip = '';
require_once dirname(__FILE__) . '/tpl/siren_modal.tpl.php';

llxFooter();
