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
 *	\file       htdocs/custom/facturation_electronique/siren_lookup.php
 *	\ingroup    facturation_electronique
 *	\brief      AJAX controller for SIREN/Directory lookup and third-party updates
 */

if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML'))  define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', '1');

// Bootstrap Dolibarr
require_once '../../main.inc.php';
if (!class_exists('FacturelectClient')) {
	require_once './class/facturelectclient.class.php';
}

// Check permissions (allow admin or users with read access to third parties)
if (!$user->admin && !$user->rights->societe->lire) {
	header('Content-Type: application/json');
	echo json_encode(array('success' => false, 'error' => 'Permission denied'));
	exit;
}
if (!getDolGlobalInt('FACTURELECT_FEATURE_SIREN', 1)) {
	header('Content-Type: application/json');
	echo json_encode(array('success' => false, 'error' => 'La fonctionnalité Gestion SIREN est désactivée.'));
	exit;
}

$action = GETPOST('action', 'alpha');
$siren = GETPOST('siren', 'alpha');
$socid = GETPOST('socid', 'int');

$client = new FacturelectClient($db);

header('Content-Type: application/json');

if ($action === 'search_companies') {
	$name = GETPOST('name', 'alpha');
	$zip = GETPOST('zip', 'alpha');

	if (empty($name)) {
		echo json_encode(array('success' => false, 'error' => 'Nom requis pour la recherche'));
		exit;
	}

	$res = $client->searchCompaniesList($name, $zip);
	if ($res === false) {
		echo json_encode(array('success' => false, 'error' => $client->error));
		exit;
	}

	echo json_encode(array('success' => true, 'companies' => $res['data']));
	exit;
}

if ($action === 'get_entries') {
	if (empty($siren)) {
		echo json_encode(array('success' => false, 'error' => 'SIREN requis'));
		exit;
	}

	$clean_siren = preg_replace('/\s+/', '', $siren);
	$res = $client->getCompanyEntries($clean_siren);
	if ($res === false) {
		echo json_encode(array('success' => false, 'error' => $client->error));
		exit;
	}

	echo json_encode(array('success' => true, 'entries' => $res['data']));
	exit;
}

if ($action === 'check_siren') {
	if (empty($siren)) {
		echo json_encode(array('success' => false, 'error' => 'SIREN requis'));
		exit;
	}

	$clean_siren = preg_replace('/\s+/', '', $siren);
	if (strlen($clean_siren) !== 9 || !is_numeric($clean_siren)) {
		echo json_encode(array('success' => false, 'error' => 'Format SIREN invalide (9 chiffres attendus)'));
		exit;
	}

	// 1. Search company
	$company_res = $client->searchCompany($clean_siren);
	if ($company_res === false) {
		echo json_encode(array('success' => false, 'error' => $client->error));
		exit;
	}

	// 2. Fetch active entries
	$entries_res = $client->getCompanyEntries($clean_siren);
	if ($entries_res === false) {
		echo json_encode(array('success' => false, 'error' => $client->error));
		exit;
	}

	echo json_encode(array(
		'success' => true,
		'company' => !empty($company_res['data']) ? $company_res['data'][0] : null,
		'entries' => $entries_res['data']
	));
	exit;
}

if ($action === 'update_tiers') {
	if (!$user->admin && !$user->rights->societe->creer) {
		echo json_encode(array('success' => false, 'error' => 'Permission non accordee (droits de modification du tiers requis)'));
		exit;
	}

	if (empty($socid)) {
		echo json_encode(array('success' => false, 'error' => 'ID Tiers requis'));
		exit;
	}

	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	$thirdparty = new Societe($db);
	$fetch_res = $thirdparty->fetch($socid);
	if ($fetch_res <= 0) {
		echo json_encode(array('success' => false, 'error' => 'Tiers introuvable'));
		exit;
	}

	$identifier = GETPOST('identifier', 'alpha'); // e.g. "0225:853322915*00012" or "853322915"
	$scheme = GETPOST('scheme', 'alpha'); // e.g. "0225"
	$company_name = GETPOST('name', 'alpha');
	$company_address = GETPOST('address', 'alpha');
	$company_zip = GETPOST('zip', 'alpha');
	$company_city = GETPOST('city', 'alpha');
	$update_details = GETPOST('update_details', 'int');

	if (empty($identifier)) {
		// Fallback to old behavior if no identifier is passed (from ID Prof 1)
		$siren_id = $thirdparty->siren ? $thirdparty->siren : $thirdparty->idprof1;
		$clean_siren = preg_replace('/\s+/', '', $siren_id);
		if (empty($clean_siren)) {
			echo json_encode(array('success' => false, 'error' => 'Veuillez renseigner un SIREN pour ce tiers'));
			exit;
		}
		$identifier = $clean_siren;
		$scheme = '0225';
	}

	// Parse scheme and identifier
	$active_scheme = $scheme ? $scheme : '0225';
	$active_identifier = $identifier;
	if (strpos($identifier, ':') !== false) {
		$parts = explode(':', $identifier);
		$active_scheme = $parts[0];
		$active_identifier = $parts[1];
	}

	// Extract SIREN and SIRET
	$siren = $active_identifier;
	$siret = '';
	if (strpos($active_identifier, '*') !== false) {
		$parts = explode('*', $active_identifier);
		$siren = $parts[0];
		$nic = $parts[1];
		if (strlen($nic) === 5) {
			$siret = $siren . $nic;
		} else {
			$siret = $siren . str_pad($nic, 5, '0', STR_PAD_LEFT);
		}
	}

	// Update thirdparty standard fields
	$thirdparty->siren = $siren;
	$thirdparty->idprof1 = $siren;
	if (!empty($siret)) {
		$thirdparty->siret = $siret;
		$thirdparty->idprof2 = $siret;
	}

	// Update details from registry if requested OR if they were empty
	if ($update_details || empty($thirdparty->name)) {
		if (!empty($company_name)) $thirdparty->name = $company_name;
	}
	if ($update_details || empty($thirdparty->address) || $thirdparty->address === 'Adresse Client') {
		if (!empty($company_address)) $thirdparty->address = $company_address;
	}
	if ($update_details || empty($thirdparty->zip) || $thirdparty->zip === '00000') {
		if (!empty($company_zip)) $thirdparty->zip = $company_zip;
	}
	if ($update_details || empty($thirdparty->city) || $thirdparty->city === 'Ville Client') {
		if (!empty($company_city)) {
			$thirdparty->city = $company_city;
			$thirdparty->town = $company_city;
		}
	}

	$update_res = $thirdparty->update($thirdparty->id, $user);
	if ($update_res < 0) {
		echo json_encode(array('success' => false, 'error' => 'Erreur lors de la mise a jour des donnees du tiers: ' . $thirdparty->error));
		exit;
	}

	$status_text = 'Actif';

	// Update Extrafields safely according to AGENTS.md Rule 9 (no standalone setValueFrom)
	if (method_exists($thirdparty, 'fetch_optionals') && empty($thirdparty->array_options)) {
		$thirdparty->fetch_optionals();
	}
	$thirdparty->array_options['options_facturelect_scheme'] = $active_scheme;
	$thirdparty->array_options['options_facturelect_id'] = $active_identifier;
	$thirdparty->array_options['options_facturelect_status'] = $status_text;
	$thirdparty->array_options['options_facturelect_last_check'] = time();

	$err = 0;
	if ($thirdparty->updateExtraField('facturelect_scheme') < 0) $err++;
	if ($thirdparty->updateExtraField('facturelect_id') < 0) $err++;
	if ($thirdparty->updateExtraField('facturelect_status') < 0) $err++;
	if ($thirdparty->updateExtraField('facturelect_last_check') < 0) $err++;

	if ($err > 0) {
		echo json_encode(array('success' => false, 'error' => 'Erreur lors de la mise a jour des attributs supplementaires (extrafields) : ' . $thirdparty->error));
		exit;
	}

	echo json_encode(array(
		'success' => true,
		'status' => $status_text,
		'scheme' => $active_scheme,
		'identifier' => $active_identifier,
		'siren' => $siren,
		'siret' => $siret
	));
	exit;
}

echo json_encode(array('success' => false, 'error' => 'Action inconnue'));
exit;
