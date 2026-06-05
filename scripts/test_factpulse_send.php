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
 *	\file       htdocs/custom/facturationelectronique/scripts/test_factpulse_send.php
 *	\ingroup    facturationelectronique
 *	\brief      CLI Test script to send a sales invoice via FactPulse
 */

$sapi_type = php_sapi_name();
if ($sapi_type !== 'cli') {
	print "Error: This script must be run from CLI command line only.\n";
	exit(-1);
}

$path = dirname(__FILE__) . '/';

if (file_exists($path . '../../../master.inc.php')) {
	require_once $path . '../../../master.inc.php';
} else {
	require_once $path . '../../../htdocs/master.inc.php';
}

if (!class_exists('FacturelectClient')) {
	require_once $path . '../class/facturelectclient.class.php';
}
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

global $db, $conf, $mysoc;

// Fetch admin user for updates
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
$user = new User($db);
if ($user->fetch(1) <= 0) {
	print "ERREUR : Impossible de charger l'utilisateur admin ID 1.\n";
	exit(-1);
}

print "--------------------------------------------------------\n";
print "Dolibarr Facturation Electronique - FactPulse Send Test\n";
print "--------------------------------------------------------\n";

print "Configuration du SIRET de test pour notre entreprise...\n";
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dolibarr_set_const($db, 'MAIN_INFO_SIRET', '92019522900017', 'chaine', 0, '', $conf->entity);
$mysoc->setMysoc($conf); // Refresh corporate settings

print "Chargement de la facture ID 6...\n";
$invoice = new Facture($db);
if ($invoice->fetch(6) <= 0) {
	print "ERREUR : Impossible de charger la facture ID 6.\n";
	exit(-1);
}
$invoice->fetch_thirdparty();

print "Facture trouvee : " . $invoice->ref . "\n";
print "Client : " . $invoice->thirdparty->name . "\n";

// Force a valid test SIRET on customer if empty for testing
if (empty($invoice->thirdparty->siret) && empty($invoice->thirdparty->idprof2)) {
	print "Affectation d'un SIRET de test au client...\n";
	$invoice->thirdparty->idprof2 = '35600000000048'; // Valid French company SIRET (Chorus Pro test recipient)
	$invoice->thirdparty->siret = '35600000000048';
	$res_update = $invoice->thirdparty->update($invoice->thirdparty->id, $user);
	if ($res_update <= 0) {
		print "ERREUR : Impossible de mettre a jour le SIRET du client : " . $invoice->thirdparty->error . "\n";
	}
}

// Ensure default IBAN is configured in Dolibarr
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
$iban = getDolGlobalString('FACTURATION_ELECTRONIQUE_DEFAULT_IBAN');
if (empty($iban)) {
	print "Configuration d'un IBAN de test par defaut...\n";
	dolibarr_set_const($db, 'FACTURATION_ELECTRONIQUE_DEFAULT_IBAN', 'FR7630001007941234567890185', 'chaine', 0, 'Test Default IBAN', $conf->entity);
}

// Locate invoice PDF file
$pdf_file = $conf->facture->dir_output . '/' . dol_sanitizeFileName($invoice->ref) . '/' . dol_sanitizeFileName($invoice->ref) . '.pdf';
print "Recherche du PDF de la facture : " . $pdf_file . "\n";

if (!file_exists($pdf_file)) {
	print "PDF non trouve. Generation du PDF de la facture...\n";
	// Call native Dolibarr PDF generation function
	$result = odf_generate($db, $invoice, 'crabe', $pdf_file, $langs);
	if ($result <= 0) {
		// Try fallback invoice_pdf_create
		include_once DOL_DOCUMENT_ROOT.'/core/modules/facture/doc/pdf_crabe.class.php';
		$pdf_crabe = new pdf_crabe($db);
		$result = $pdf_crabe->write_file($invoice, $langs, $pdf_file);
	}
	if (!file_exists($pdf_file)) {
		print "ERREUR : Impossible de generer le PDF de la facture.\n";
		exit(-1);
	}
	print "PDF genere avec succes !\n";
}

$pdf_content = file_get_contents($pdf_file);

print "Activation de FactPulse comme fournisseur actif...\n";
dolibarr_set_const($db, 'FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER', 'factpulse', 'chaine', 0, 'Active PDP Provider', $conf->entity);

print "Instanciation de FacturelectClient...\n";
$client = new FacturelectClient($db);

print "DEBUG SIRET/SIREN:\n";
print "  mysoc->siret = [" . (isset($mysoc->siret) ? $mysoc->siret : 'NOT SET') . "]\n";
print "  mysoc->idprof2 = [" . (isset($mysoc->idprof2) ? $mysoc->idprof2 : 'NOT SET') . "]\n";
print "  invoice->thirdparty->siret = [" . (isset($invoice->thirdparty->siret) ? $invoice->thirdparty->siret : 'NOT SET') . "]\n";
print "  invoice->thirdparty->idprof2 = [" . (isset($invoice->thirdparty->idprof2) ? $invoice->thirdparty->idprof2 : 'NOT SET') . "]\n";

print "Envoi de la facture via FactPulse...\n";
$res = $client->sendFacturXInvoice($pdf_content, $invoice->ref);

if ($res === false) {
	print "ECHEC DE L'ENVOI : " . $client->error . "\n";
	print "--------------------------------------------------------\n";
	exit(-1);
}

print "ENVOI REUSSI !\n";
print "Reponse de l'API FactPulse :\n";
print_r($res);
print "--------------------------------------------------------\n";
exit(0);
