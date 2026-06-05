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
 *	\file       htdocs/custom/facturationelectronique/admin/audit_logs.php
 *	\ingroup    facturationelectronique
 *	\brief      Audit logs console for Facturation Electronique module
 */

// Bootstrap Dolibarr
require_once '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

// Access control
if (!$user->admin) {
	accessforbidden();
}

$form = new Form($db);

$langs->load("admin");
$langs->load("facturation_electronique@facturationelectronique");

// Parameters
$action = GETPOST('action', 'alpha');

// Clear filters
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter.x', 'alpha')) {
	$_GET['filter_provider'] = '';
	$_GET['filter_action'] = '';
	$_GET['filter_status'] = '';
}

$limit = GETPOST('limit', 'int') ?: 20;
$page = GETPOST('page', 'int') ?: 0;
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;

$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'date_creation';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'DESC';

// Filter fields
$filter_provider = GETPOST('filter_provider', 'alpha');
$filter_action = GETPOST('filter_action', 'alpha');
$filter_status = GETPOST('filter_status', 'alpha'); // success / error

// Build SQL where clause
$where_clauses = array();
if (!empty($filter_provider)) {
	$where_clauses[] = "provider = '" . $db->escape($filter_provider) . "'";
}
if (!empty($filter_action)) {
	$where_clauses[] = "action LIKE '%" . $db->escape($filter_action) . "%'";
}
if (!empty($filter_status)) {
	if ($filter_status === 'success') {
		$where_clauses[] = "(http_status >= 200 AND http_status < 300)";
	} elseif ($filter_status === 'error') {
		$where_clauses[] = "(http_status < 200 OR http_status >= 300 OR http_status IS NULL OR error_message IS NOT NULL)";
	}
}

$where_sql = "";
if (!empty($where_clauses)) {
	$where_sql = " WHERE " . implode(" AND ", $where_clauses);
}

// Count total records
$sql_count = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "facturelect_log" . $where_sql;
$res_count = $db->query($sql_count);
$total_records = 0;
if ($res_count) {
	$row = $db->fetch_object($res_count);
	$total_records = (int) $row->total;
}

// Fetch logs
$sql_logs = "SELECT rowid, date_creation, provider, action, url, method, http_status, request_payload, response_payload, error_message, fk_user";
$sql_logs .= " FROM " . MAIN_DB_PREFIX . "facturelect_log";
$sql_logs .= $where_sql;

$allowed_sortfields = array('date_creation', 'provider', 'action', 'method', 'http_status', 'url');
$actual_sortfield = in_array($sortfield, $allowed_sortfields) ? $sortfield : 'date_creation';
$actual_sortorder = (strtoupper($sortorder) === 'ASC') ? 'ASC' : 'DESC';

$sql_logs .= " ORDER BY " . $actual_sortfield . " " . $actual_sortorder . ", rowid DESC";
$sql_logs .= $db->plimit($limit, $offset);

$res_logs = $db->query($sql_logs);

$num = $res_logs ? $db->num_rows($res_logs) : 0;

// Get unique values for dropdowns
$sql_providers = "SELECT DISTINCT provider FROM " . MAIN_DB_PREFIX . "facturelect_log ORDER BY provider";
$res_providers = $db->query($sql_providers);
$providers_list = array();
if ($res_providers) {
	while ($p_row = $db->fetch_object($res_providers)) {
		if ($p_row->provider) {
			$providers_list[] = $p_row->provider;
		}
	}
}

// Build query params for sorting/pagination
$param = '';
if (!empty($filter_provider)) {
	$param .= '&filter_provider=' . urlencode($filter_provider);
}
if (!empty($filter_action)) {
	$param .= '&filter_action=' . urlencode($filter_action);
}
if (!empty($filter_status)) {
	$param .= '&filter_status=' . urlencode($filter_status);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit=' . (int) $limit;
}

// Layout headers
llxHeader('', $langs->trans("FacturelectAuditLogs"), '');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restoreval=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("FacturelectAuditLogs"), $linkback, 'title_setup');

$links = array(
	array(
		'url' => dol_buildpath('/facturationelectronique/admin/setup.php', 1) . '?mainmenu=facturelect&leftmenu=setup',
		'title' => $langs->trans("FacturelectConnectionSettings"),
		'id' => 'setup'
	),
	array(
		'url' => dol_buildpath('/facturationelectronique/admin/audit_logs.php', 1) . '?mainmenu=facturelect&leftmenu=audit_logs',
		'title' => $langs->trans("FacturelectAuditLogs"),
		'id' => 'audit_logs'
	)
);
$active_tab = 'audit_logs';
dol_fiche_head($links, $active_tab, '', -1, 'setup');

print '<div class="fe-container">';

// Title Section & Native high pagination / summary
$title = $langs->trans("FacturelectAuditLogsTitle");
print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $total_records, 'title_generic.png', 0, '', '', $limit);

// Filters & List Form wrapper
print '<form action="' . $_SERVER['PHP_SELF'] . '" method="get" name="searchFormList" id="searchFormList">';
print '  <input type="hidden" name="mainmenu" value="facturelect">';
print '  <input type="hidden" name="leftmenu" value="audit_logs">';
print '  <input type="hidden" name="sortfield" value="' . dol_escape_htmltag($sortfield) . '">';
print '  <input type="hidden" name="sortorder" value="' . dol_escape_htmltag($sortorder) . '">';

// Results Table Container
print '<div class="div-table-responsive">';
print '<table class="liste centpercent">';
print '  <thead>';

// 1. Standard search filters row (liste_titre_filter)
print '    <tr class="liste_titre_filter">';

// Date (no filter)
print '      <td class="liste_titre"></td>';

// Provider select
print '      <td class="liste_titre" align="left">';
print '        <select class="flat maxwidth100" name="filter_provider">';
print '          <option value="">&nbsp;</option>';
foreach ($providers_list as $prov) {
	$selected = ($filter_provider === $prov) ? 'selected' : '';
	print '          <option value="' . dol_escape_htmltag($prov) . '" ' . $selected . '>' . strtoupper($prov) . '</option>';
}
print '        </select>';
print '      </td>';

// Action text search
print '      <td class="liste_titre" align="left">';
print '        <input class="flat maxwidth120" type="text" name="filter_action" value="' . dol_escape_htmltag($filter_action) . '" placeholder="Rechercher...">';
print '      </td>';

// Method (no filter)
print '      <td class="liste_titre"></td>';

// Status select
print '      <td class="liste_titre" align="left">';
print '        <select class="flat maxwidth100" name="filter_status">';
print '          <option value="">&nbsp;</option>';
print '          <option value="success" ' . ($filter_status === 'success' ? 'selected' : '') . '>Succès (2xx)</option>';
print '          <option value="error" ' . ($filter_status === 'error' ? 'selected' : '') . '>Échec / Erreur</option>';
print '        </select>';
print '      </td>';

// URL (no filter)
print '      <td class="liste_titre"></td>';

// Action buttons (Filter / Reset buttons generated by Dolibarr Form)
print '      <td class="liste_titre center maxwidthsearch actioncolumn">';
print $form->showFilterButtons();
print '      </td>';

print '    </tr>';

// 2. Standard table column header row (Titles with sorting links)
print '    <tr class="liste_titre">';
print_liste_field_titre($langs->trans("FacturelectLogDate"), $_SERVER["PHP_SELF"], "date_creation", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("FacturelectLogProvider"), $_SERVER["PHP_SELF"], "provider", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("FacturelectLogAction"), $_SERVER["PHP_SELF"], "action", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("FacturelectLogMethod"), $_SERVER["PHP_SELF"], "method", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("FacturelectLogStatus"), $_SERVER["PHP_SELF"], "http_status", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("FacturelectLogUrl"), $_SERVER["PHP_SELF"], "url", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Actions"), $_SERVER["PHP_SELF"], "", "", $param, 'align="center"', $sortfield, $sortorder);
print '    </tr>';
print '  </thead>';
print '  <tbody>';

if ($res_logs && $db->num_rows($res_logs) > 0) {
	while ($log = $db->fetch_object($res_logs)) {
		$status_class = 'danger';
		if ($log->http_status >= 200 && $log->http_status < 300) {
			$status_class = 'success';
		}
		
		$url_display = (strlen($log->url) > 50) ? substr($log->url, 0, 47) . '...' : $log->url;

		// Encode payloads in base64 to safely injection into HTML attributes
		$req_base64 = base64_encode($log->request_payload ?: '');
		$resp_base64 = base64_encode($log->response_payload ?: '');
		$err_base64 = base64_encode($log->error_message ?: '');

		print '    <tr class="oddeven">';
		print '      <td class="liste_td" style="font-weight: 500;">' . dol_print_date($db->jdate($log->date_creation), 'dayhour') . '</td>';
		print '      <td class="liste_td"><span class="fe-status-pill ' . ($log->provider === 'FactPulse' ? 'warning' : 'success') . '" style="font-size: 10px;">' . dol_escape_htmltag($log->provider) . '</span></td>';
		print '      <td class="liste_td" style="font-weight: 600;">' . dol_escape_htmltag($log->action) . '</td>';
		print '      <td class="liste_td"><code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-weight: 600;">' . dol_escape_htmltag($log->method) . '</code></td>';
		print '      <td class="liste_td"><span class="fe-status-pill ' . $status_class . '">' . ($log->http_status ?: 'ERR') . '</span></td>';
		print '      <td class="liste_td" style="font-size: 12px;" title="' . dol_escape_htmltag($log->url) . '">' . dol_escape_htmltag($url_display) . '</td>';
		print '      <td class="liste_td" style="text-align: center;">';
		print '        <button type="button" class="fe-btn fe-btn-secondary fe-btn-sm" style="gap: 4px;"';
		print '                data-req="' . $req_base64 . '"';
		print '                data-resp="' . $resp_base64 . '"';
		print '                data-err="' . $err_base64 . '"';
		print '                data-action="' . dol_escape_htmltag($log->action) . '"';
		print '                data-provider="' . dol_escape_htmltag($log->provider) . '"';
		print '                data-status="' . ($log->http_status ?: 'ERR') . '"';
		print '                data-url="' . dol_escape_htmltag($log->url) . '"';
		print '                onclick="feInspectLog(this)">';
		print '          <span class="fa fa-eye"></span> Inspecter';
		print '        </button>';
		print '      </td>';
		print '    </tr>';
	}
} else {
	print '    <tr><td colspan="7" class="opacitymedium" align="center" style="padding: 30px;">';
	print '      <span class="fa fa-history" style="font-size: 32px; margin-bottom:10px; display:block; color:#cbd5e1;"></span>';
	print '      ' . $langs->trans("FacturelectNoLogs");
	print '    </td></tr>';
}

print '  </tbody>';
print '</table>';
print '</div>';

print '</form>';

// Pagination bar in footer
print_barre_liste('', $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $total_records, '', 0, '', '', $limit);

print '</div>'; // End fe-container

// 4. Modal for inspecting payloads
print '<div id="fe-log-modal" class="fe-modal-overlay fe-hidden" onclick="feCloseModal(event)">';
print '  <div class="fe-modal-container" style="max-width: 800px; max-height: 90vh;" onclick="event.stopPropagation()">';
print '    <div class="fe-modal-header">';
print '      <h3><span class="fa fa-search"></span> Inspection de la transaction API</h3>';
print '      <button class="fe-modal-close" onclick="feHideLogModal()">&times;</button>';
print '    </div>';
print '    <div class="fe-modal-body" style="padding: 20px;">';
print '      <div id="fe-log-meta" style="background:#f8fafc; border: 1px solid #e2e8f0; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; line-height: 1.6; color:#334155;"></div>';
print '      ';
print '      <div class="fe-form-group">';
print '        <label style="font-weight: 700; color: #475569; margin-bottom: 8px; font-size: 13px;">Requête (Request Payload)</label>';
print '        <pre style="background: #0f172a; color: #e2e8f0; padding: 15px; border-radius: 8px; overflow-x: auto; font-family: monospace; font-size: 12px; max-height: 200px; margin: 0 0 15px 0;"><code id="fe-log-req-payload"></code></pre>';
print '      </div>';
print '      ';
print '      <div class="fe-form-group">';
print '        <label style="font-weight: 700; color: #475569; margin-bottom: 8px; font-size: 13px;">Réponse (Response Payload)</label>';
print '        <pre style="background: #0f172a; color: #38bdf8; padding: 15px; border-radius: 8px; overflow-x: auto; font-family: monospace; font-size: 12px; max-height: 350px; margin: 0;"><code id="fe-log-resp-payload"></code></pre>';
print '      </div>';
print '      ';
print '      <div class="fe-form-group" id="fe-log-err-group" style="display:none; margin-top: 15px;">';
print '        <label style="font-weight: 700; color: #ef4444; margin-bottom: 8px; font-size: 13px;">Message d\'erreur</label>';
print '        <pre style="background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px; border-radius: 8px; overflow-x: auto; font-family: monospace; font-size: 12px; margin: 0;"><code id="fe-log-err-message"></code></pre>';
print '      </div>';
print '    </div>';
print '  </div>';
print '</div>';

?>
<script type="text/javascript">
	function feInspectLog(btn) {
		const action = btn.getAttribute('data-action');
		const provider = btn.getAttribute('data-provider');
		const status = btn.getAttribute('data-status');
		const url = btn.getAttribute('data-url');

		// Decode base64 payloads safely
		const reqBase64 = btn.getAttribute('data-req');
		const respBase64 = btn.getAttribute('data-resp');
		const errBase64 = btn.getAttribute('data-err');

		const reqRaw = reqBase64 ? atob(reqBase64) : '';
		const respRaw = respBase64 ? atob(respBase64) : '';
		const errRaw = errBase64 ? atob(errBase64) : '';

		// Format metadata
		let metaHtml = '<strong>Fournisseur:</strong> ' + provider.toUpperCase();
		metaHtml += ' | <strong>Action:</strong> ' + action;
		metaHtml += ' | <strong>Statut HTTP:</strong> <span class="fe-status-pill ' + (status === 'ERR' || parseInt(status) >= 300 || parseInt(status) < 200 ? 'danger' : 'success') + '">' + status + '</span>';
		metaHtml += '<br/><strong>URL demandée:</strong> <code style="word-break: break-all;">' + url + '</code>';
		document.getElementById('fe-log-meta').innerHTML = metaHtml;

		// Format JSON or print raw text
		document.getElementById('fe-log-req-payload').textContent = formatJSONString(reqRaw);
		document.getElementById('fe-log-resp-payload').textContent = formatJSONString(respRaw);

		// Handle error block
		const errGroup = document.getElementById('fe-log-err-group');
		if (errRaw) {
			errGroup.style.display = 'block';
			document.getElementById('fe-log-err-message').textContent = errRaw;
		} else {
			errGroup.style.display = 'none';
		}

		// Show Modal
		document.getElementById('fe-log-modal').classList.remove('fe-hidden');
	}

	function formatJSONString(str) {
		if (!str) {
			return '(Aucune donnée / Empty)';
		}
		
		// If it is binary representation or custom tag
		if (str.startsWith('<Binary') || str.startsWith('<Large')) {
			return str;
		}

		try {
			const obj = JSON.parse(str);
			return JSON.stringify(obj, null, 2);
		} catch (e) {
			// Fallback to plain string if not valid JSON
			return str;
		}
	}

	function feHideLogModal() {
		document.getElementById('fe-log-modal').classList.add('fe-hidden');
	}

	function feCloseModal(event) {
		if (event.target.id === 'fe-log-modal') {
			feHideLogModal();
		}
	}
</script>
<?php

// Layout footers
dol_fiche_end();
llxFooter();
$db->close();
?>
