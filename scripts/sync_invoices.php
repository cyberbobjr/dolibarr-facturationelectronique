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
 *	\file       htdocs/custom/facturation_electronique/scripts/sync_invoices.php
 *	\ingroup    facturation_electronique
 *	\brief      CLI Script to sync incoming supplier invoices from SuperPDP
 */

$sapi_type = php_sapi_name();
if ($sapi_type !== 'cli') {
	print "Error: This script must be run from CLI command line only.\n";
	exit(-1);
}

$path = dirname(__FILE__) . '/';

// Bootstrap Dolibarr CLI (master.inc.php has no GUI)
require_once $path . '../../../master.inc.php';
if (!class_exists('FacturelectClient')) {
	require_once $path . '../class/facturelectclient.class.php';
}

global $db, $conf;

print "--------------------------------------------------------\n";
print "Dolibarr Facturation Electronique - CLI Sync Tool\n";
print "--------------------------------------------------------\n";
print "Demarrage de la synchronisation des factures fournisseurs...\n";

$client = new FacturelectClient($db);
$result = $client->syncIncomingInvoices();

if ($result === false) {
	print "Erreur lors de la synchronisation : " . $client->error . "\n";
	exit(-1);
}

print "Synchronisation reussie ! " . $result . " facture(s) fournisseur(s) importee(s).\n";
print "--------------------------------------------------------\n";
exit(0);
