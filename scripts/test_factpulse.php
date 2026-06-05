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
 *	\file       htdocs/custom/facturationelectronique/scripts/test_factpulse.php
 *	\ingroup    facturationelectronique
 *	\brief      CLI Test script for FactPulse integration and connection
 */

$sapi_type = php_sapi_name();
if ($sapi_type !== 'cli') {
	print "Error: This script must be run from CLI command line only.\n";
	exit(-1);
}

$path = dirname(__FILE__) . '/';
// Bootstrap Dolibarr CLI (master.inc.php has no GUI)
if (file_exists($path . '../../../master.inc.php')) {
	require_once $path . '../../../master.inc.php';
} else {
	require_once $path . '../../../htdocs/master.inc.php';
}

if (!class_exists('FacturelectClient')) {
	require_once $path . '../class/facturelectclient.class.php';
}

global $db, $conf;

print "--------------------------------------------------------\n";
print "Dolibarr Facturation Electronique - FactPulse Test Tool\n";
print "--------------------------------------------------------\n";

$jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ0b2tlbl90eXBlIjoiYWNjZXNzIiwiZXhwIjoxNzgwNjU4ODAwLCJpYXQiOjE3ODA2NTcwMDAsImp0aSI6ImQ1MmMyYjAxZjEyOTQyNzQ4NjQ5MmEzZjI1YTRjNjU5IiwidXNlcl9pZCI6IjE1OTciLCJtb2RlIjoidGVzdCIsInRyaWFsIjp0cnVlfQ.NnIxkz7iF5XcAXQmERYfd_k2GsLuYT1xt8u7DG46Tys';
$expires = time() + 3600; // Force token to look valid locally

print "Sauvegarde du token JWT de test FactPulse...\n";
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dolibarr_set_const($db, 'FACTUR_ELECT_FACTPULSE_TOKEN', $jwt, 'chaine', 0, 'Test token', $conf->entity);
dolibarr_set_const($db, 'FACTUR_ELECT_FACTPULSE_TOKEN_EXPIRES', $expires, 'chaine', 0, 'Test token expires', $conf->entity);

print "Activation de FactPulse comme fournisseur actif...\n";
dolibarr_set_const($db, 'FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER', 'factpulse', 'chaine', 0, 'Active PDP Provider', $conf->entity);

print "Instanciation de FacturelectClient...\n";
$client = new FacturelectClient($db);

print "Active provider loaded: " . strtoupper(getDolGlobalString('FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER')) . "\n";
print "Active provider class: " . get_class($client->provider) . "\n";

print "\nExecution de checkSession() sur FactPulse...\n";
$session = $client->checkSession();

if ($session === false) {
	print "ECHEC : " . $client->error . "\n";
	print "--------------------------------------------------------\n";
	exit(-1);
}

print "CONNEXION REUSSIE !\n";
print "Reponse de l'API FactPulse /v1/me :\n";
print_r($session);
print "--------------------------------------------------------\n";
exit(0);
