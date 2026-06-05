<?php
define('NOCSRFCHECK', 1);
define('NOTOKENRENEWAL', 1);
require_once '/var/www/html/master.inc.php';
if (!class_exists('FacturelectClient')) {
    require_once dirname(dirname(__FILE__)) . '/class/facturelectclient.class.php';
}

global $db, $conf;
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dolibarr_set_const($db, 'FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER', 'factpulse', 'chaine', 0, 'Active PDP Provider', $conf->entity);

$client = new FacturelectClient($db);
$invoices = $client->listIncomingInvoices();

if ($invoices === false) {
    echo "Error: " . $client->error . "\n";
    exit(1);
}

echo json_encode($invoices, JSON_PRETTY_PRINT) . "\n";
exit(0);
