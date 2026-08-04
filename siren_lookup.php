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
if (!class_exists('FacturelectDirectoryFactory')) {
	require_once './class/facturelectdirectoryfactory.class.php';
}
if (!class_exists('FacturelectPeppolId')) {
	require_once './class/facturelectpeppolid.class.php';
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

/**
 * Decompose each PEPPOL routing address server-side, so no client ever has to parse
 * identifiers itself (single source of truth: FacturelectPeppolId).
 *
 * @param	array	$rows	Raw entries returned by the provider
 * @return	array			Same entries, each with a 'parsed' block and a 'label'
 */
function facturelectDescribeEntries($rows)
{
	$entries = array();
	foreach ((array) $rows as $entry) {
		$parsed = FacturelectPeppolId::parse(
			isset($entry['identifier']) ? $entry['identifier'] : '',
			isset($entry['scheme']) ? $entry['scheme'] : ''
		);
		$entry['parsed'] = $parsed;
		$entry['label'] = FacturelectPeppolId::describe($parsed);
		$entries[] = $entry;
	}

	return $entries;
}

if ($action === 'search_companies') {
	$name = GETPOST('name', 'alpha');
	$zip = GETPOST('zip', 'alpha');
	// Sanitize the source the same way as any other discriminator parameter: copy/pasted
	// URLs may carry typographic quotes or stray characters (see AGENTS.md rule 29).
	// 'aZ09' blanks the value entirely on any unexpected character, and the factory then
	// falls back to the configured default.
	$source = preg_replace('/[^a-z0-9_]/', '', strtolower(GETPOST('source', 'aZ09')));

	if (empty($name)) {
		echo json_encode(array('success' => false, 'error' => 'Nom requis pour la recherche'));
		exit;
	}

	$directory = FacturelectDirectoryFactory::getDirectory($db, $source);
	$companies = $directory->searchCompanies($name, $zip);
	if ($companies === false) {
		echo json_encode(array('success' => false, 'error' => $directory->error));
		exit;
	}

	echo json_encode(array(
		'success' => true,
		'companies' => $companies,
		'source' => $directory->getCode(),
		'source_label' => $directory->getLabel(),
		// Reported back so the user knows when the search had to be broadened
		'used_query' => $directory->used_query
	));
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

	echo json_encode(array('success' => true, 'entries' => facturelectDescribeEntries($res['data'])));
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
		'entries' => facturelectDescribeEntries($entries_res['data'])
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

	// Split the routing address into its legal components. The full address is kept for
	// PEPPOL routing, but only a validated 9-digit SIREN may reach llx_societe.siren —
	// SuperPDP identifiers such as "0225:200058485_20005848500018_RH" would otherwise be
	// written whole and break the Factur-X legal_registration_identifier (AGENTS.md #16).
	$parsed = FacturelectPeppolId::parse($identifier, $scheme);
	$active_scheme = $parsed['scheme'];
	$active_identifier = $parsed['identifier'];
	$siren = $parsed['siren'];
	$siret = $parsed['siret'];

	// Update thirdparty standard fields. Dolibarr persists idprof1/idprof2 to the
	// siren/siret columns (AGENTS.md #32); an unparsable component is left untouched
	// rather than overwritten with an invalid value.
	if (!empty($siren)) {
		$thirdparty->siren = $siren;
		$thirdparty->idprof1 = $siren;
	}
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
		'siret' => $siret,
		'label' => FacturelectPeppolId::describe($parsed)
	));
	exit;
}

echo json_encode(array('success' => false, 'error' => 'Action inconnue'));
exit;
