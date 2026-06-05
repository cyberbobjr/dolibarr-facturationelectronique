<?php
define('NOCSRFCHECK', 1);
define('NOTOKENRENEWAL', 1);
require_once '/var/www/html/master.inc.php';

if (!class_exists('InterfaceFacturationElectroniqueTriggers')) {
	require_once dirname(dirname(__FILE__)) . '/core/triggers/interface_99_modFacturationElectronique_FacturationElectroniqueTriggers.class.php';
}
if (!class_exists('PaiementFourn')) {
	require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';
}
if (!class_exists('FactureFournisseur')) {
	require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
}

global $db, $user, $langs, $conf;

// Ensure user is loaded
if (empty($user->id)) {
	$user = new User($db);
	$user->fetch(1);
}

$trigger = new InterfaceFacturationElectroniqueTriggers($db);

// Target supplier invoice 11 (F20260604_202558_272, PDP ID: 66905)
$facid = 11;
$invoice = new FactureFournisseur($db);
if ($invoice->fetch($facid) <= 0) {
	echo "Error: Invoice 11 not found.\n";
	exit(1);
}
$invoice->fetch_optionals();
$pdp_id = $invoice->array_options['options_facturelect_invoice_id'];

echo "Testing supplier payment trigger on invoice REF: " . $invoice->ref . " (Dolibarr ID: " . $facid . ", PDP ID: " . $pdp_id . ")\n";

// Mock a supplier payment
$payment = new PaiementFourn($db);
$payment->id = 88888; // Mock payment ID
$payment->datepaye = time();
$payment->amounts = array(
	$facid => 500.00 // Paying 500.00 EUR
);

$action = 'PAYMENT_SUPPLIER_CREATE';
echo "Running trigger PAYMENT_SUPPLIER_CREATE...\n";
$res_trigger = $trigger->runTrigger($action, $payment, $user, $langs, $conf);

if ($res_trigger < 0) {
	echo "Trigger failed.\n";
	exit(1);
} else {
	echo "Trigger ran successfully! Let's fetch the invoice events from SuperPDP to verify.\n";
	exit(0);
}
