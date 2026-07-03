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
 *	\file       htdocs/custom/facturationelectronique/download_inbound_invoice.php
 *	\ingroup    facturationelectronique
 *	\brief      Streams the raw PDF/XML file of an incoming network invoice to the browser
 */

// Bootstrap Dolibarr
require_once '../../main.inc.php';

if (!class_exists('FacturelectClient')) {
	require_once './class/facturelectclient.class.php';
}

// Access control: read right on supplier invoices is required
if (!$user->rights->fournisseur->facture->lire) {
	accessforbidden();
}

// Feature must be enabled
if (!getDolGlobalInt('FACTURELECT_FEATURE_EINVOICING', 1)) {
	accessforbidden();
}

// Sanitize the invoice technical id (PDP integer id)
$pdp_id = GETPOSTINT('id');
if (empty($pdp_id)) {
	httponly_accessforbidden('Missing or invalid invoice id', 400);
}

$client = new FacturelectClient($db);
$content = $client->downloadInvoiceFile($pdp_id);

if ($content === false || $content === null || $content === '') {
	$err = $client->error ? $client->error : 'Unable to download the invoice file';
	dol_syslog('download_inbound_invoice.php: download failed for id=' . $pdp_id . ' : ' . $err, LOG_ERR);
	httponly_accessforbidden($err, 500);
}

// Detect the file type from its binary signature (endpoint returns PDF or XML)
$is_pdf = (substr($content, 0, 4) === '%PDF');
$extension = $is_pdf ? 'pdf' : 'xml';
$mime_type = $is_pdf ? 'application/pdf' : 'application/xml';
$filename = 'facture_reseau_' . $pdp_id . '.' . $extension;

// Discard any buffered output before streaming the binary file
while (ob_get_level()) {
	ob_end_clean();
}

top_httphead($mime_type);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

print $content;

$db->close();
exit;
