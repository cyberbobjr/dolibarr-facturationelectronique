<?php
define('NOCSRFCHECK', 1);
define('NOTOKENRENEWAL', 1);
require_once '/var/www/html/master.inc.php';
if (!class_exists('FacturelectClient')) {
    require_once dirname(dirname(__FILE__)) . '/class/facturelectclient.class.php';
}

$client = new FacturelectClient($db);

echo "Starting manual inbound synchronization...\n";
$res = $client->syncIncomingInvoices();

if ($res === false) {
    echo "Synchronization failed with error: " . $client->error . "\n";
    exit(1);
} else {
    echo "Synchronization completed successfully. Imported " . $res . " new supplier invoices.\n";
    exit(0);
}
