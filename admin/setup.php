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
 *	\file       htdocs/custom/facturation_electronique/admin/setup.php
 *	\ingroup    facturation_electronique
 *	\brief      Setup page for Facturation Electronique module
 */

// Bootstrap Dolibarr
require_once '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
if (!class_exists('FacturelectClient')) {
	require_once '../class/facturelectclient.class.php';
}
if (!class_exists('ActionsFacturationelectronique')) {
	require_once '../class/actions_facturationelectronique.class.php';
}
if (!class_exists('FacturelectDirectoryFactory')) {
	require_once '../class/facturelectdirectoryfactory.class.php';
}

// Access control
if (!$user->admin) {
	accessforbidden();
}

$langs->load("admin");
$langs->load("facturation_electronique@facturationelectronique");

$action = GETPOST('action', 'alpha');
$error = 0;

// Save configuration settings
if ($action === 'update_superpdp') {
	$mode = GETPOST('mode', 'alpha');
	$sandbox_id = GETPOST('sandbox_id', 'alpha');
	$sandbox_secret = GETPOST('sandbox_secret', 'alpha');
	$prod_id = GETPOST('prod_id', 'alpha');
	$prod_secret = GETPOST('prod_secret', 'alpha');

	$res1 = dolibarr_set_const($db, 'FACTURATION_ELECTRONIQUE_MODE', $mode, 'chaine', 0, 'Operating mode', $conf->entity);
	$res2 = dolibarr_set_const($db, 'FACTURATION_ELECTRONIQUE_SANDBOX_CLIENT_ID', $sandbox_id, 'chaine', 0, 'Sandbox client ID', $conf->entity);
	$res3 = dolibarr_set_const($db, 'FACTURATION_ELECTRONIQUE_SANDBOX_CLIENT_SECRET', $sandbox_secret, 'chaine', 0, 'Sandbox client secret', $conf->entity);
	$res4 = dolibarr_set_const($db, 'FACTURATION_ELECTRONIQUE_PROD_CLIENT_ID', $prod_id, 'chaine', 0, 'Production client ID', $conf->entity);
	$res5 = dolibarr_set_const($db, 'FACTURATION_ELECTRONIQUE_PROD_CLIENT_SECRET', $prod_secret, 'chaine', 0, 'Production client secret', $conf->entity);

	// Force clean cached token on credentials update
	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	dolibarr_del_const($db, 'FACTUR_ELECT_TOKEN', $conf->entity);
	dolibarr_del_const($db, 'FACTUR_ELECT_TOKEN_EXPIRES', $conf->entity);

	if ($res1 >= 0 && $res2 >= 0 && $res3 >= 0 && $res4 >= 0 && $res5 >= 0) {
		setEventMessages($langs->trans("SetupSaved") . " (SuperPDP)", null, 'mesgs');
	} else {
		setEventMessages($langs->trans("ErrorFailedToSave"), null, 'errors');
		$error++;
	}
}

if ($action === 'activate_provider') {
	$provider = GETPOST('provider', 'alpha');
	if ($provider === 'superpdp') {
		$res = dolibarr_set_const($db, 'FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER', $provider, 'chaine', 0, 'Active PDP Provider', $conf->entity);
		if ($res >= 0) {
			setEventMessages("Fournisseur actif mis a jour : " . strtoupper($provider), null, 'mesgs');
		} else {
			setEventMessages($langs->trans("ErrorFailedToSave"), null, 'errors');
			$error++;
		}
	}
}

if ($action === 'update_features') {
	$feat_einvoicing = GETPOSTINT('feat_einvoicing') ? 1 : 0;
	$feat_siren = GETPOSTINT('feat_siren') ? 1 : 0;
	$feat_allow_import = GETPOSTINT('feat_allow_import') ? 1 : 0;
	$r1 = dolibarr_set_const($db, 'FACTURELECT_FEATURE_EINVOICING', $feat_einvoicing, 'chaine', 0, 'Enable electronic invoice transmission feature', $conf->entity);
	$r2 = dolibarr_set_const($db, 'FACTURELECT_FEATURE_SIREN', $feat_siren, 'chaine', 0, 'Enable SIREN directory management feature', $conf->entity);
	$r3 = dolibarr_set_const($db, 'FACTURELECT_ALLOW_IMPORT', $feat_allow_import, 'chaine', 0, 'Allow importing incoming supplier invoices (else PDF download only)', $conf->entity);
	// Default identification source of the SIREN lookup modal. An unknown value is ignored
	// by the factory, which falls back to the public API.
	$siren_source = GETPOST('siren_source', 'alpha');
	if (!array_key_exists($siren_source, FacturelectDirectoryFactory::getAvailableSources())) {
		$siren_source = FacturelectDirectoryFactory::DEFAULT_SOURCE;
	}
	$r4 = dolibarr_set_const($db, FacturelectDirectoryFactory::SETTING_NAME, $siren_source, 'chaine', 0, 'Default directory used for SIREN lookup', $conf->entity);
	if ($r1 >= 0 && $r2 >= 0 && $r3 >= 0 && $r4 >= 0) {
		setEventMessages("Fonctionnalités mises à jour.", null, 'mesgs');
	} else {
		setEventMessages($langs->trans("ErrorFailedToSave"), null, 'errors');
		$error++;
	}
}

if ($action === 'update_notes') {
	// BR-FR-05 legal mentions (BT-22). Empty value => module falls back to the legal default.
	$note_penalty = GETPOST('note_penalty', 'restricthtml');
	$note_recovery = GETPOST('note_recovery', 'restricthtml');
	$note_discount = GETPOST('note_discount', 'restricthtml');
	$n1 = dolibarr_set_const($db, 'FACTURELECT_NOTE_PENALTY', trim($note_penalty), 'chaine', 0, 'Late payment penalties mention (BR-FR-05)', $conf->entity);
	$n2 = dolibarr_set_const($db, 'FACTURELECT_NOTE_RECOVERY', trim($note_recovery), 'chaine', 0, 'Recovery costs mention (BR-FR-05)', $conf->entity);
	$n3 = dolibarr_set_const($db, 'FACTURELECT_NOTE_DISCOUNT', trim($note_discount), 'chaine', 0, 'Early-payment discount mention (BR-FR-05)', $conf->entity);
	if ($n1 >= 0 && $n2 >= 0 && $n3 >= 0) {
		setEventMessages("Mentions légales mises à jour.", null, 'mesgs');
	} else {
		setEventMessages($langs->trans("ErrorFailedToSave"), null, 'errors');
		$error++;
	}
}


// Perform connection test
$connection_tested = false;
$connection_success = false;
$session_data = null;
$client_err_msg = '';

if ($action === 'test_conn') {
	$connection_tested = true;
	$client = new FacturelectClient($db);
	$session_data = $client->checkSession();
	if ($session_data !== false) {
		$connection_success = true;
	} else {
		$client_err_msg = $client->error;
	}
}

// Perform manual synchronization of incoming invoices
$sync_triggered = false;
$sync_count = 0;
if ($action === 'sync_selected' && !getDolGlobalInt('FACTURELECT_ALLOW_IMPORT', 1)) {
	// Import is disabled: block the server-side action even if the request was crafted manually.
	setEventMessages($langs->trans("FacturelectImportDisabledError"), null, 'errors');
	$action = '';
}
if ($action === 'sync_selected') {
	$sync_triggered = true;
	$import_ids = GETPOST('import_ids', 'array');
	if (!empty($import_ids)) {
		$client = new FacturelectClient($db);
		$sync_res = $client->syncIncomingInvoices($import_ids);
		if ($sync_res !== false) {
			$sync_count = $sync_res;
		} else {
			$client_err_msg = $client->error;
		}
	} else {
		$sync_count = 0;
	}
}

// Retrieve saved values
$feat_einvoicing = getDolGlobalInt('FACTURELECT_FEATURE_EINVOICING', 1);
$feat_siren = getDolGlobalInt('FACTURELECT_FEATURE_SIREN', 1);
$feat_allow_import = getDolGlobalInt('FACTURELECT_ALLOW_IMPORT', 1);
$siren_source = FacturelectDirectoryFactory::getDefaultCode();
$mode = getDolGlobalString('FACTURATION_ELECTRONIQUE_MODE');
if (empty($mode)) {
	$mode = 'sandbox'; // Default to sandbox
}
$sandbox_id = getDolGlobalString('FACTURATION_ELECTRONIQUE_SANDBOX_CLIENT_ID');
$sandbox_secret = getDolGlobalString('FACTURATION_ELECTRONIQUE_SANDBOX_CLIENT_SECRET');
$prod_id = getDolGlobalString('FACTURATION_ELECTRONIQUE_PROD_CLIENT_ID');
$prod_secret = getDolGlobalString('FACTURATION_ELECTRONIQUE_PROD_CLIENT_SECRET');

$active_provider = getDolGlobalString('FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER', 'superpdp');

// BR-FR-05 legal mentions: stored overrides (empty => the legal default is used at build time)
$legal_defaults = ActionsFacturationelectronique::getDefaultLegalMentions();
$note_penalty = getDolGlobalString('FACTURELECT_NOTE_PENALTY');
$note_recovery = getDolGlobalString('FACTURELECT_NOTE_RECOVERY');
$note_discount = getDolGlobalString('FACTURELECT_NOTE_DISCOUNT');

// Layout headers
llxHeader('', $langs->trans("FacturelectSetup"), '');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restoreval=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("FacturelectSetup"), $linkback, 'title_setup');

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
$active_tab = 'setup';
dol_fiche_head($links, $active_tab, '', -1, 'setup');

print '<div class="fe-container">';

// 1. Dashboard Title & Quick Sync
print '<div class="fe-header">';
print '  <div class="fe-title-section">';
print '    <span class="fa fa-file-invoice-dollar fe-icon-main"></span>';
print '    <div>';
print '      <h2>' . $langs->trans("FacturelectSetup") . '</h2>';
print '      <p style="margin: 3px 0 0 0; font-size:12px; color:#64748b;">Module de facturation electronique B2B pour la France</p>';
print '    </div>';
print '  </div>';

// Active status badge — reflect the LIVE connection state on every page load, not only
// right after an explicit "Test connection" click (issue #25: the badge wrongly showed
// "Disconnected" whenever the user navigated away and came back, despite a valid session).
if ($connection_tested) {
	$is_connected = $connection_success;
} else {
	$creds_configured = ($mode === 'production')
		? (!empty($prod_id) && !empty($prod_secret))
		: (!empty($sandbox_id) && !empty($sandbox_secret));
	$is_connected = false;
	if ($creds_configured) {
		$status_client = new FacturelectClient($db);
		$is_connected = ($status_client->checkSession() !== false);
	}
}

$status_class = 'disconnected';
$status_label = $langs->trans("FacturelectStatusDisconnected");
if ($is_connected) {
	$status_class = 'connected';
	$status_label = $langs->trans("FacturelectStatusConnected");
}
print '  <div class="fe-status-badge">';
print '    <span class="fe-status-dot ' . $status_class . '"></span>';
print '    <span><strong>Active Provider: ' . strtoupper($active_provider) . '</strong> (' . $status_label . ')</span>';
print '  </div>';
print '</div>';

// Visual Alerts
if ($connection_tested) {
	if ($connection_success) {
		print '<div class="fe-alert fe-alert-success">';
		print '  <span class="fa fa-check-circle" style="font-size: 20px;"></span>';
		print '  <div>';
		print '    <strong>Connexion reussie !</strong><br/>';
		if (!empty($session_data['company'])) {
			$comp = $session_data['company'];
			print '    Entreprise associee : <strong>' . $comp['formal_name'] . '</strong> (' . $comp['number_scheme'] . ' : ' . $comp['number'] . ')<br/>';
			print '    Adresse : ' . $comp['address'] . ', ' . $comp['postcode'] . ' ' . $comp['city'] . '<br/>';
			print '    Statut KYB : <span class="fe-status-pill ' . ($session_data['company_verification_status'] === 'verified' ? 'success' : 'warning') . '">' . $session_data['company_verification_status'] . '</span>';
		}
		print '  </div>';
		print '</div>';
	} else {
		print '<div class="fe-alert fe-alert-danger">';
		print '  <span class="fa fa-exclamation-triangle" style="font-size: 20px;"></span>';
		print '  <div>';
		print '    <strong>Erreur de connexion</strong><br/>';
		print '    Impossible de se connecter au PDP. Verifiez vos identifiants.<br/>';
		print '    Details : ' . $client_err_msg;
		print '  </div>';
		print '</div>';
	}
}

if ($sync_triggered) {
	if ($sync_count !== false) {
		print '<div class="fe-alert fe-alert-success">';
		print '  <span class="fa fa-sync-alt" style="font-size: 20px;"></span>';
		print '  <div>';
		print '    <strong>Synchronisation manuelle reussie !</strong><br/>';
		if ($sync_count > 0) {
			print '    Nombre de factures fournisseurs importees : <strong>' . $sync_count . '</strong>';
		} else {
			print '    Aucune nouvelle facture a importer.';
		}
		print '  </div>';
		print '</div>';
	} else {
		print '<div class="fe-alert fe-alert-danger">';
		print '  <span class="fa fa-exclamation-triangle" style="font-size: 20px;"></span>';
		print '  <div>';
		print '    <strong>Echec de la synchronisation</strong><br/>';
		print '    Details : ' . $client_err_msg;
		print '  </div>';
		print '</div>';
	}
}

// Feature toggles card (full width, above the credentials grid)
print '<div class="fe-card" style="margin-bottom:20px;">';
print '  <h3 class="fe-card-title"><span class="fa fa-toggle-on"></span> Fonctionnalités actives</h3>';
print '  <p style="color:#64748b; font-size:13px; margin-bottom:16px;">Activez ou désactivez chaque fonctionnalité indépendamment. Les deux peuvent coexister ou fonctionner séparément.</p>';
print '  <form action="' . $_SERVER['PHP_SELF'] . '" method="post">';
print '    <input type="hidden" name="token" value="' . newToken() . '">';
print '    <input type="hidden" name="action" value="update_features">';
print '    <div style="display:flex; flex-direction:column; gap:14px;">';

// Feature 1 — Transmission
$f1_on = (int) $feat_einvoicing;
print '      <label style="display:flex; align-items:flex-start; gap:12px; cursor:pointer; padding:12px; border-radius:8px; border:1px solid ' . ($f1_on ? '#10b981' : '#e2e8f0') . '; background:' . ($f1_on ? '#f0fdf4' : '#f8fafc') . ';">';
print '        <input type="checkbox" name="feat_einvoicing" value="1" ' . ($f1_on ? 'checked' : '') . ' style="margin-top:2px; width:16px; height:16px;">';
print '        <div>';
print '          <strong style="color:#1e293b;"><span class="fa fa-paper-plane" style="color:#10b981; margin-right:6px;"></span>Transmission électronique</strong>';
print '          <p style="margin:4px 0 0; font-size:12px; color:#64748b;">Envoi et réception de factures via le PDP (Factur-X, SuperPDP), onglet de statut sur les fiches factures, e-reporting paiements, listes Factures Émises / Reçues.</p>';
print '        </div>';
print '      </label>';

// Feature 2 — SIREN
$f2_on = (int) $feat_siren;
print '      <label style="display:flex; align-items:flex-start; gap:12px; cursor:pointer; padding:12px; border-radius:8px; border:1px solid ' . ($f2_on ? '#10b981' : '#e2e8f0') . '; background:' . ($f2_on ? '#f0fdf4' : '#f8fafc') . ';">';
print '        <input type="checkbox" name="feat_siren" value="1" ' . ($f2_on ? 'checked' : '') . ' style="margin-top:2px; width:16px; height:16px;">';
print '        <div>';
print '          <strong style="color:#1e293b;"><span class="fa fa-search" style="color:#10b981; margin-right:6px;"></span>Gestion SIREN &amp; Annuaire</strong>';
print '          <p style="margin:4px 0 0; font-size:12px; color:#64748b;">Recherche et vérification SIREN des tiers dans l\'annuaire national, détection des SIREN manquants/incorrects, enrichissement des coordonnées, bouton de vérification sur les fiches tiers.</p>';
print '        </div>';
print '      </label>';

// Feature 2 bis — Default identification source used by the SIREN lookup modal
print '      <div style="padding:12px 12px 12px 40px; margin-top:-8px;">';
print '        <label for="siren_source" style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:6px;">Source de données par défaut pour la recherche SIREN</label>';
print '        <select id="siren_source" name="siren_source" class="fe-input" style="max-width:340px;">';
foreach (FacturelectDirectoryFactory::getAvailableSources() as $source_code => $source_label) {
	print '          <option value="' . dol_escape_htmltag($source_code) . '"' . ($source_code === $siren_source ? ' selected' : '') . '>' . dol_escape_htmltag($source_label) . '</option>';
}
print '        </select>';
print '        <p style="margin:6px 0 0; font-size:12px; color:#64748b;">L\'<strong>API gouv.fr</strong> est gratuite, sans clé, et fait de la recherche plein texte (tolérante aux sigles et à l\'ordre des mots). L\'<strong>annuaire du PDP</strong> ne cherche que par <em>début</em> de raison sociale. Dans les deux cas, les adresses de réception PEPPOL restent lues chez le PDP. L\'utilisateur peut changer de source dans la fenêtre de recherche.</p>';
print '      </div>';

// Feature 3 — Allow import of incoming supplier invoices
$f3_on = (int) $feat_allow_import;
print '      <label style="display:flex; align-items:flex-start; gap:12px; cursor:pointer; padding:12px; border-radius:8px; border:1px solid ' . ($f3_on ? '#10b981' : '#e2e8f0') . '; background:' . ($f3_on ? '#f0fdf4' : '#f8fafc') . ';">';
print '        <input type="checkbox" name="feat_allow_import" value="1" ' . ($f3_on ? 'checked' : '') . ' style="margin-top:2px; width:16px; height:16px;">';
print '        <div>';
print '          <strong style="color:#1e293b;"><span class="fa fa-file-import" style="color:#10b981; margin-right:6px;"></span>' . $langs->trans("FacturelectFeatureAllowImport") . '</strong>';
print '          <p style="margin:4px 0 0; font-size:12px; color:#64748b;">' . $langs->trans("FacturelectFeatureAllowImportDesc") . '</p>';
print '        </div>';
print '      </label>';

print '    </div>';
print '    <div style="margin-top:16px;">';
print '      <button type="submit" class="fe-btn fe-btn-primary"><span class="fa fa-save"></span> Enregistrer les fonctionnalités</button>';
print '    </div>';
print '  </form>';
print '</div>';

// ==== Legal mentions (BR-FR-05) ====
print '<div class="fe-card" style="margin-bottom:20px;">';
print '  <h3 class="fe-card-title"><span class="fa fa-gavel"></span> Mentions légales obligatoires</h3>';
print '  <p style="color:#64748b; font-size:13px; margin-bottom:16px;">Trois mentions sont exigées sur chaque facture électronique française (règle <strong>BR-FR-05</strong>) et transmises dans le Factur-X. Laissez un champ vide pour utiliser la mention légale par défaut affichée en dessous.</p>';
print '  <form action="' . $_SERVER['PHP_SELF'] . '" method="post">';
print '    <input type="hidden" name="token" value="' . newToken() . '">';
print '    <input type="hidden" name="action" value="update_notes">';
print '    <div style="display:flex; flex-direction:column; gap:16px;">';

$note_fields = array(
	array('name' => 'note_penalty', 'val' => $note_penalty, 'def' => $legal_defaults['FACTURELECT_NOTE_PENALTY'], 'label' => 'Pénalités de retard', 'icon' => 'fa-percent', 'code' => 'PMD'),
	array('name' => 'note_recovery', 'val' => $note_recovery, 'def' => $legal_defaults['FACTURELECT_NOTE_RECOVERY'], 'label' => 'Frais de recouvrement (indemnité 40 €)', 'icon' => 'fa-euro-sign', 'code' => 'PMT'),
	array('name' => 'note_discount', 'val' => $note_discount, 'def' => $legal_defaults['FACTURELECT_NOTE_DISCOUNT'], 'label' => 'Escompte', 'icon' => 'fa-tag', 'code' => 'AAB'),
);
foreach ($note_fields as $fld) {
	print '      <div>';
	print '        <label style="display:block; font-weight:600; color:#1e293b; margin-bottom:4px;"><span class="fa ' . $fld['icon'] . '" style="color:#0284c7; margin-right:6px;"></span>' . $fld['label'] . ' <span style="font-weight:400; color:#94a3b8; font-size:11px;">(code ' . $fld['code'] . ')</span></label>';
	print '        <textarea name="' . $fld['name'] . '" rows="2" style="width:100%; box-sizing:border-box; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-family:inherit;" placeholder="Laissé vide : mention légale par défaut">' . dol_escape_htmltag($fld['val']) . '</textarea>';
	print '        <p style="margin:3px 0 0; font-size:11px; color:#94a3b8;"><em>Défaut : ' . dol_escape_htmltag($fld['def']) . '</em></p>';
	print '      </div>';
}

print '    </div>';
print '    <div style="margin-top:16px;">';
print '      <button type="submit" class="fe-btn fe-btn-primary"><span class="fa fa-save"></span> Enregistrer les mentions</button>';
print '    </div>';
print '  </form>';
print '</div>';

print '<div class="fe-grid">';

// 2A. SuperPDP Settings Card
print '  <div class="fe-card" style="position:relative;' . ($active_provider === 'superpdp' ? 'border: 2px solid #10b981; box-shadow:0 0 10px rgba(16,185,129,0.1);' : 'opacity:0.85;') . '">';
if ($active_provider === 'superpdp') {
	print '    <span class="fe-status-pill success" style="position:absolute; top:20px; right:20px;"><span class="fa fa-check"></span> FOURNISSEUR ACTIF</span>';
} else {
	print '    <a href="' . $_SERVER['PHP_SELF'] . '?action=activate_provider&provider=superpdp" class="fe-btn fe-btn-secondary" style="position:absolute; top:20px; right:20px;"><span class="fa fa-power-off"></span> Activer</a>';
}
print '    <h3 class="fe-card-title" style="margin-top:10px;"><span class="fa fa-plug"></span> Configuration SuperPDP</h3>';
print '    <form action="' . $_SERVER['PHP_SELF'] . '" method="post">';
print '      <input type="hidden" name="token" value="' . newToken() . '">';
print '      <input type="hidden" name="action" value="update_superpdp">';

// Radio buttons for Sandbox vs Production Mode
print '      <div class="fe-form-group">';
print '        <label>' . $langs->trans("FacturelectMode") . '</label>';
print '        <div class="fe-radio-group">';
print '          <label class="fe-radio-label ' . ($mode === 'sandbox' ? 'active' : '') . '" id="lbl-sandbox">';
print '            <input type="radio" name="mode" value="sandbox" ' . ($mode === 'sandbox' ? 'checked' : '') . ' onclick="toggleMode(\'sandbox\')">';
print '            ' . $langs->trans("FacturelectModeSandbox");
print '          </label>';
print '          <label class="fe-radio-label ' . ($mode === 'production' ? 'active' : '') . '" id="lbl-prod">';
print '            <input type="radio" name="mode" value="production" ' . ($mode === 'production' ? 'checked' : '') . ' onclick="toggleMode(\'production\')">';
print '            ' . $langs->trans("FacturelectModeProd");
print '          </label>';
print '        </div>';
print '      </div>';

// Sandbox Client ID & Secret
print '      <div id="sandbox-fields" style="' . ($mode === 'sandbox' ? '' : 'display:none;') . '">';
print '        <div class="fe-form-group">';
print '          <label>' . $langs->trans("FacturelectSandboxClientId") . '</label>';
print '          <input type="text" class="fe-input" name="sandbox_id" value="' . dol_escape_htmltag($sandbox_id) . '">';
print '        </div>';
print '        <div class="fe-form-group">';
print '          <label>' . $langs->trans("FacturelectSandboxClientSecret") . '</label>';
print '          <div class="fe-input-wrapper">';
print '            <input type="password" class="fe-input" id="sandbox_secret" name="sandbox_secret" value="' . dol_escape_htmltag($sandbox_secret) . '">';
print '            <button type="button" class="fe-toggle-pwd" onclick="togglePwd(\'sandbox_secret\')"><span class="fa fa-eye" id="eye-sandbox_secret"></span></button>';
print '          </div>';
print '        </div>';
print '      </div>';

// Production Client ID & Secret
print '      <div id="prod-fields" style="' . ($mode === 'production' ? '' : 'display:none;') . '">';
print '        <div class="fe-form-group">';
print '          <label>' . $langs->trans("FacturelectProdClientId") . '</label>';
print '          <input type="text" class="fe-input" name="prod_id" value="' . dol_escape_htmltag($prod_id) . '">';
print '        </div>';
print '        <div class="fe-form-group">';
print '          <label>' . $langs->trans("FacturelectProdClientSecret") . '</label>';
print '          <div class="fe-input-wrapper">';
print '            <input type="password" class="fe-input" id="prod_secret" name="prod_secret" value="' . dol_escape_htmltag($prod_secret) . '">';
print '            <button type="button" class="fe-toggle-pwd" onclick="togglePwd(\'prod_secret\')"><span class="fa fa-eye" id="eye-prod_secret"></span></button>';
print '          </div>';
print '        </div>';
print '      </div>';

// Action Buttons
print '      <div style="margin-top: 20px; display:flex; gap:10px;">';
print '        <button type="submit" class="fe-btn fe-btn-primary"><span class="fa fa-save"></span> ' . $langs->trans("Save") . '</button>';
if ($active_provider === 'superpdp') {
	print '        <a href="' . $_SERVER['PHP_SELF'] . '?action=test_conn" class="fe-btn fe-btn-secondary"><span class="fa fa-vial"></span> ' . $langs->trans("FacturelectConnectionTest") . '</a>';
}
print '      </div>';
print '    </form>';
print '  </div>';

// 3. Directory Search & Sync Card
print '  <div class="fe-card">';
print '    <h3 class="fe-card-title"><span class="fa fa-search"></span> ' . $langs->trans("FacturelectSirenLookupTitle") . '</h3>';

// SIREN Search Form
print '    <div class="fe-form-group">';
print '      <label>' . $langs->trans("FacturelectSirenPlaceholder") . '</label>';
print '      <div style="display:flex; gap:10px;">';
print '        <input type="text" class="fe-input" id="fe-siren-search-input" maxlength="9" placeholder="ex. 853322915">';
print '        <button type="button" class="fe-btn fe-btn-primary" onclick="feSearchSiren()"><span class="fa fa-search"></span> ' . $langs->trans("FacturelectSearchButton") . '</button>';
print '      </div>';
print '    </div>';

// Search results area
print '    <div id="fe-search-results-area" style="display:none; margin-top:20px;">';
print '      <h4 style="font-size:14px; font-weight:700; color:#1e293b; margin:0 0 10px 0;">' . $langs->trans("FacturelectSearchResults") . '</h4>';
print '      <div id="fe-search-results-content"></div>';
print '    </div>';

// Inbound Sync Box
print '    <div style="margin-top: 30px; border-top: 1px solid #f1f5f9; padding-top: 20px;">';
print '      <h4 style="font-size:14px; font-weight:700; color:#1e293b; margin:0 0 10px 0;">Factures fournisseurs entrantes (Achat)</h4>';
print '      <p style="font-size:12px; color:#64748b; margin-bottom:15px;">Une tâche planifiée automatique (Cron) est configurée pour récupérer périodiquement les factures d\'achat. Pour consulter, sélectionner et importer manuellement les factures d\'achat disponibles, rendez-vous dans le menu opérationnel.</p>';
print '      <a href="' . dol_buildpath('/facturationelectronique/inbound_list.php', 1) . '?mainmenu=facturelect&leftmenu=inbound" class="fe-btn fe-btn-secondary"><span class="fa fa-list"></span> Consulter et importer les factures d\'achat</a>';
print '    </div>';

print '  </div>'; // End search card

print '</div>'; // End fe-grid

print '</div>'; // End fe-container

// JS interactions for setups
?>
<script type="text/javascript">
	function toggleMode(mode) {
		const lblSandbox = document.getElementById('lbl-sandbox');
		const lblProd = document.getElementById('lbl-prod');
		const sandboxFields = document.getElementById('sandbox-fields');
		const prodFields = document.getElementById('prod-fields');

		if (mode === 'sandbox') {
			lblSandbox.classList.add('active');
			lblProd.classList.remove('active');
			sandboxFields.style.display = 'block';
			prodFields.style.display = 'none';
		} else {
			lblSandbox.classList.remove('active');
			lblProd.classList.add('active');
			sandboxFields.style.display = 'none';
			prodFields.style.display = 'block';
		}
	}

	function togglePwd(id) {
		const input = document.getElementById(id);
		const eye = document.getElementById('eye-' + id);
		if (input.type === 'password') {
			input.type = 'text';
			eye.classList.remove('fa-eye');
			eye.classList.add('fa-eye-slash');
		} else {
			input.type = 'password';
			eye.classList.remove('fa-eye-slash');
			eye.classList.add('fa-eye');
		}
	}

	function feSearchSiren() {
		const sirenInput = document.getElementById('fe-siren-search-input');
		const resultsArea = document.getElementById('fe-search-results-area');
		const resultsContent = document.getElementById('fe-search-results-content');
		
		const siren = sirenInput.value.replace(/\s+/g, '');
		if (siren.length !== 9 || isNaN(siren)) {
			alert('Veuillez saisir un SIREN valide a 9 chiffres.');
			return;
		}

		resultsArea.style.display = 'block';
		resultsContent.innerHTML = '<span class="fa fa-spinner fa-spin"></span> Recherche en cours dans l annuaire national...';

		fetch('<?php echo dol_buildpath('/facturationelectronique/siren_lookup.php', 1); ?>?action=check_siren&siren=' + siren)
			.then(response => response.json())
			.then(data => {
				if (data.success && data.company) {
					const comp = data.company;
					let html = '<div class="fe-card" style="background:#f8fafc; border-color:#e2e8f0; padding:15px; margin-bottom:15px;">';
					html += '  <h5 style="margin:0 0 5px 0; font-size:14px; font-weight:700; color:#0f172a;">' + comp.formal_name + '</h5>';
					html += '  <p style="margin:0; font-size:12px; color:#475569;">';
					html += '    SIREN : <strong>' + comp.number + '</strong><br/>';
					html += '    Adresse : ' + comp.address + ', ' + comp.postcode + ' ' + comp.city + '<br/>';
					html += '    Pays : ' + comp.country + '<br/>';
					html += '  </p>';
					html += '</div>';

					if (data.entries && data.entries.length > 0) {
						html += '<table class="fe-results-table">';
						html += '  <thead>';
						html += '    <tr>';
						html += '      <th>Identifiant Réseau (Participant ID)</th>';
						html += '      <th>Type d\'Annuaire</th>';
						html += '      <th>Statut Réception</th>';
						html += '    </tr>';
						html += '  </thead>';
						html += '  <tbody>';
						data.entries.forEach(entry => {
							const directoryName = entry.directory ? entry.directory : (entry.identifier.startsWith('0225') ? 'France (SIREN)' : 'SuperPDP');
							html += '    <tr>';
							html += '      <td><strong>' + entry.identifier + '</strong></td>';
							html += '      <td>' + directoryName.toUpperCase() + '</td>';
							html += '      <td><span class="fe-status-pill ' + (entry.is_active ? 'success' : 'warning') + '">' + (entry.is_active ? 'Actif' : 'Inactif') + '</span></td>';
							html += '    </tr>';
						});
						html += '  </tbody>';
						html += '</table>';
					} else {
						html += '<div class="fe-alert fe-alert-info"><?php echo dol_escape_js($langs->trans('FacturelectTiersNotFoundInDirectory')); ?></div>';
					}
					resultsContent.innerHTML = html;
				} else {
					const err = data.error || '<?php echo dol_escape_js($langs->trans('FacturelectNoResults')); ?>';
					resultsContent.innerHTML = '<div class="fe-alert fe-alert-danger"><span class="fa fa-exclamation-circle"></span> ' + err + '</div>';
				}
			})
			.catch(error => {
				resultsContent.innerHTML = '<div class="fe-alert fe-alert-danger">Erreur de communication avec l annuaire national.</div>';
			});
	}

	function feToggleAllInbound(master) {
		const checkboxes = document.querySelectorAll('.inbound-checkbox');
		checkboxes.forEach(cb => {
			cb.checked = master.checked;
		});
	}
</script>
<?php

// Layout footers
dol_fiche_end();
llxFooter();
$db->close();
?>
