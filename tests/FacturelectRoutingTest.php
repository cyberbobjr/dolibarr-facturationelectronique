<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/class/facturelectrouting.class.php';
require_once dirname(__DIR__).'/class/actions_facturationelectronique.class.php';


/** Capture routing logs without filesystem side effects. */
class RoutingTestActions extends ActionsFacturationelectronique
{
	public $logs = array();
	/** @param string $ref Invoice reference @param string $level Severity @param string $message Log @return void */
	public function writeLog($ref, $level, $message) { $this->logs[] = $message; }
}


/** Directory double without network access. */
class RoutingTestClient
{
	public $calls = array();
	public $response = array('data' => array());
	public $error = 'Directory unavailable';

	/** @param string $siren Buyer SIREN @return array|false Directory response */
	public function getCompanyEntries($siren)
	{
		$this->calls[] = $siren;
		return $this->response;
	}
}

/** Routing verdicts and request-scoped directory access. */
class FacturelectRoutingTest extends TestCase
{
	/** @return array ENGIE directory fixture */
	private function engie()
	{
		$rows = array();
		foreach (array('BIENS_SERVICES_FRAIS_GENERAUX', 'FRAIS_BANCAIRES', 'ENERGIES_BIOMASSE', 'ENERGIES_CONTRAT', 'ENERGIES_HYDROCARBURES', 'FLEET', 'LEI_CYCLE_DE_VIE', 'TELECOM_OBS') as $index => $suffix) {
			$rows[] = array('company' => array('number' => '552046955'), 'identifier' => '0225:552046955_'.$suffix, 'is_active' => $index < 2);
		}
		return $rows;
	}

	/** @return void */
	public function testEngieBareActiveInactiveAndMissingAddresses()
	{
		foreach (array('552046955' => 'unassociated', '552046955_BIENS_SERVICES_FRAIS_GENERAUX' => 'ok', '552046955_FLEET' => 'inactive', '552046955_UNKNOWN' => 'missing') as $id => $status) {
			$res = FacturelectRouting::evaluate($id, '0225', '552046955', $this->engie());
			$this->assertSame($status, $res['status']);
			$this->assertSame($status !== 'ok', $res['blocking']);
			$this->assertCount(2, $res['active_addresses']);
			$this->assertSame('FacturelectRouting'.ucfirst($status), $res['message']);
		}
	}

	/** @return void */
	public function testBareFallbackAndEmptyOrInactiveDirectory()
	{
		$this->assertFalse(FacturelectRouting::evaluate('', '0225', '552046955', array(array('identifier' => '0225:552046955', 'is_active' => true)))['blocking']);
		$this->assertSame('empty', FacturelectRouting::evaluate('', '0225', '552046955', array())['status']);
		$res = FacturelectRouting::evaluate('552046955_FLEET', '0225', '552046955', array($this->engie()[5]));
		$this->assertSame('inactive', $res['status']);
		$this->assertTrue($res['blocking']);
		$this->assertSame('not_ready', FacturelectRouting::evaluate('552046955', '0225', '552046955', array($this->engie()[5]))['status']);
	}

	/** @return void */
	public function testNormalizationPreservesSchemeAndServiceBoundaries()
	{
		foreach (array(array(' 0225 : 552046955_biens_services_frais_generaux ', $this->engie()[0]), array('0225:853322915*00012', array('identifier' => '0225:853322915_85332291500012', 'is_active' => true))) as $case) {
			$this->assertFalse(FacturelectRouting::evaluate($case[0], '0225', '552046955', array($case[1]))['blocking']);
		}
		$this->assertTrue(FacturelectRouting::evaluate('552046955_BIENS_SERVICES_FRAIS_GENERAUX', '0002', '552046955', $this->engie())['blocking']);
	}

	/** @return void */
	public function testModesB2cFailuresAndRequestCache()
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals = array('FACTURATION_ELECTRONIQUE_MODE' => 'production', 'FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER' => 'superpdp');
		$buyer = (object) array('idprof1' => '552046955', 'array_options' => array());
		$client = new RoutingTestClient();
		$client->response = array('data' => $this->engie());
		$check = new FacturelectRouting();
		$this->assertTrue($check->check($buyer, $client)['blocking']);
		$this->assertTrue($check->check($buyer, $client)['blocking']);
		$this->assertCount(1, $client->calls);
		$dolibarr_mock_globals['FACTURATION_ELECTRONIQUE_ROUTING_CHECK_MODE'] = 'warn';
		$this->assertFalse($check->check($buyer, $client)['blocking']);
		$this->assertNotEmpty($check->check($buyer, $client)['message']);
		foreach (array(array('FACTURATION_ELECTRONIQUE_ROUTING_CHECK_MODE', 'off'), array('FACTURATION_ELECTRONIQUE_MODE', 'sandbox'), array('FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER', 'afnor')) as $setting) {
			$dolibarr_mock_globals['FACTURATION_ELECTRONIQUE_ROUTING_CHECK_MODE'] = 'block';
			$dolibarr_mock_globals[$setting[0]] = $setting[1];
			$client->calls = array();
			$this->assertSame('skipped', (new FacturelectRouting())->check($buyer, $client)['status']);
			$this->assertSame(array(), $client->calls);
			$dolibarr_mock_globals[$setting[0]] = $setting[0] === 'FACTURATION_ELECTRONIQUE_MODE' ? 'production' : 'superpdp';
		}
		foreach (array(array('typent_code' => 'TE_PRIVATE'), array('array_options' => array('options_facturelect_b2c' => 1))) as $fields) {
			$b2c = (object) array_merge((array) $buyer, $fields);
			$this->assertSame('skipped', (new FacturelectRouting())->check($b2c, $client)['status']);
			$this->assertSame(array(), $client->calls);
		}
		$client->response = false;
		$res = (new FacturelectRouting())->check($buyer, $client);
		$this->assertSame('error', $res['status']);
		$this->assertFalse($res['blocking']);
		$this->assertSame('Directory unavailable', $res['error']);
		$client->response = array('meta' => array());
		$this->assertSame('error', (new FacturelectRouting())->check($buyer, $client)['status']);
	}
	/** @return void */
	public function testActualPayloadAndCheckUseTheSameAddressAndErrorsAreLogged()
	{
		global $dolibarr_mock_globals, $mysoc;
		$dolibarr_mock_globals = array('FACTURATION_ELECTRONIQUE_MODE' => 'production', 'FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER' => 'superpdp');
		$mysoc = (object) array('idprof1' => '451830939', 'name' => 'Seller', 'address' => 'Street', 'zip' => '75001', 'town' => 'Paris', 'tva_intra' => 'FR00451830939');
		$invoice = new class {
			public $thirdparty;
			public $array_options = array('loaded' => true);
			public $lines;
			public $ref = 'TEST';
			public $type = 0;
			public $mode_reglement_code = 'CB';
			public $date = 1700000000;
			public $total_ht = 100;
			public $total_tva = 20;
			public $total_ttc = 120;
			/** @return int Paid amount */
			public function getSommePaiement() { return 0; }
		};
		$invoice->thirdparty = (object) array('idprof1' => '552046955', 'name' => 'Buyer', 'array_options' => array('loaded' => true));
		$invoice->lines = array((object) array('product_type' => 1, 'qty' => 1, 'total_ht' => 100, 'total_tva' => 20, 'tva_tx' => 20, 'subprice' => 100, 'desc' => 'Service'));
		$client = new RoutingTestClient();
		$actions = new RoutingTestActions(new DoliDB());
		foreach (array('', '552046955_BIENS_SERVICES_FRAIS_GENERAUX', '0225:552046955_BIENS_SERVICES_FRAIS_GENERAUX') as $id) {
			$invoice->thirdparty->array_options['options_facturelect_id'] = $id;
			$invoice->thirdparty->array_options['options_facturelect_scheme'] = strpos($id, ':') !== false ? '0002' : '0225';
			$payload = $actions->buildEnInvoiceJson($invoice);
			$this->assertIsArray($payload, $actions->error);
			$address = $payload['buyer']['electronic_address'];
			$verdict = $actions->checkBuyerRouting($invoice, $client);
			$this->assertSame($address['scheme'].':'.$address['value'], $verdict['address']);
			$this->assertSame('0225', $address['scheme']);
			$this->assertStringNotContainsString(':', $address['value']);
		}
		$invoice->array_options['options_facturelect_buyer_address'] = '0225:552046955_FRAIS_BANCAIRES';
		$payload = $actions->buildEnInvoiceJson($invoice);
		$this->assertSame(array('value' => '552046955_FRAIS_BANCAIRES', 'scheme' => '0225'), $payload['buyer']['electronic_address']);
		$this->assertSame('0225:552046955_FRAIS_BANCAIRES', $actions->checkBuyerRouting($invoice, $client)['address']);
		unset($invoice->array_options['options_facturelect_buyer_address']);
		$client->response = false;
		$actions = new RoutingTestActions(new DoliDB());
		$this->assertFalse($actions->checkBuyerRouting($invoice, $client)['blocking']);
		$this->assertStringContainsString('Directory unavailable', $actions->logs[0]);
		$this->assertStringContainsString('552046955_BIENS_SERVICES_FRAIS_GENERAUX', $actions->logs[0]);
	}

	/** @return void */
	public function testInvoiceAddressHasPriorityOverBuyerAndBareSiren()
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals = array('FACTURATION_ELECTRONIQUE_MODE' => 'production', 'FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER' => 'superpdp');
		$buyer = (object) array('idprof1' => '552046955', 'array_options' => array('options_facturelect_id' => '552046955_BIENS_SERVICES_FRAIS_GENERAUX'));
		$invoice = (object) array('array_options' => array('options_facturelect_buyer_address' => '0225:552046955_FRAIS_BANCAIRES'));
		$client = new RoutingTestClient();
		$client->response = array('data' => $this->engie());
		$routing = FacturelectDiagnostic::routing($buyer, $invoice);
		$this->assertSame('552046955_FRAIS_BANCAIRES', $routing['identifier']);
		$this->assertSame('invoice', $routing['source']);
		$this->assertSame('0225:552046955_FRAIS_BANCAIRES', (new FacturelectRouting())->check($buyer, $client, $invoice)['address']);
		$invoice->array_options['options_facturelect_buyer_address'] = '';
		$this->assertSame('552046955_BIENS_SERVICES_FRAIS_GENERAUX', FacturelectDiagnostic::routing($buyer, $invoice)['identifier']);
		$buyer->array_options = array();
		$this->assertSame('552046955', FacturelectDiagnostic::routing($buyer, $invoice)['identifier']);
	}

	/** @return void */
	public function testSavingAnInvoiceChoiceRejectsInactiveMissingAndDirectoryErrors()
	{
		$invoice = new class {
			public $thirdparty;
			public $array_options = array();
			public $saved = array();
			public $fail = false;
			/** @param string $key Extrafield @return int Result */
			public function updateExtraField($key) { $this->saved[] = $key; return $this->fail ? -1 : 1; }
		};
		$invoice->thirdparty = (object) array('idprof1' => '552046955', 'array_options' => array());
		$client = new RoutingTestClient();
		$client->response = array('data' => $this->engie());
		$res = FacturelectRouting::saveBuyerAddress($invoice, '0225:552046955_FRAIS_BANCAIRES', $client);
		$this->assertTrue($res['success']);
		$this->assertSame('0225:552046955_FRAIS_BANCAIRES', $invoice->array_options['options_facturelect_buyer_address']);
		$this->assertSame(array('facturelect_buyer_address'), $invoice->saved);
		foreach (array('0225:552046955_FLEET', '0225:552046955_UNKNOWN', '0225:451830939_OTHER') as $id) {
			$this->assertFalse(FacturelectRouting::saveBuyerAddress($invoice, $id, $client)['success']);
			$this->assertCount(1, $invoice->saved);
		}
		$client->response = false;
		$this->assertFalse(FacturelectRouting::saveBuyerAddress($invoice, '0225:552046955_FRAIS_BANCAIRES', $client)['success']);
		$client->calls = array();
		$this->assertTrue(FacturelectRouting::saveBuyerAddress($invoice, '', $client)['success']);
		$this->assertSame('', $invoice->array_options['options_facturelect_buyer_address']);
		$this->assertSame(array(), $client->calls);
		$invoice->fail = true;
		$this->assertFalse(FacturelectRouting::saveBuyerAddress($invoice, '', $client)['success']);
	}

	/** @return void */
	public function testCloneClearsInvoiceChoiceWithoutChangingOtherBusinessFields()
	{
		$invoice = new class {
			public $element = 'facture';
			public $array_options = array('options_facturelect_status' => 'not_sent', 'options_facturelect_buyer_address' => '0225:552046955_FRAIS_BANCAIRES', 'options_facturelect_vatex_code' => 'VATEX-EU-AE');
			public $saved = array();
			/** @param string $key Field @return int Result */
			public function updateExtraField($key) { $this->saved[] = $key; return 1; }
		};
		$actions = new RoutingTestActions(new DoliDB());
		$action = 'createFrom';
		$actions->createFrom(array(), $invoice, $action, null);
		$this->assertSame('', $invoice->array_options['options_facturelect_buyer_address']);
		$this->assertSame('VATEX-EU-AE', $invoice->array_options['options_facturelect_vatex_code']);
		$this->assertSame(array('facturelect_buyer_address'), $invoice->saved);
	}

	/** @return void */
	public function testInvoiceEditorsMayReadEntriesWithoutCompanyWriteOrSearchRights()
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals = array();
		$user = (object) array('rights' => (object) array('facture' => (object) array('lire' => 1, 'creer' => 1)));
		$this->assertTrue(FacturelectRouting::canReadDirectory('get_entries', $user));
		$this->assertFalse(FacturelectRouting::canReadDirectory('update_tiers', $user));
		$this->assertFalse(FacturelectRouting::canReadDirectory('search_companies', $user));
		$user->socid = 1;
		$this->assertFalse(FacturelectRouting::canReadDirectory('get_entries', $user));
		$user->socid = 0;
		$user->rights->facture->creer = 0;
		$this->assertFalse(FacturelectRouting::canReadDirectory('get_entries', $user));
	}

	/** @return void */
	public function testThirdpartyDefaultIsValidatedAndInheritedUnlessInvoiceOverridesIt()
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals['FACTURATION_ELECTRONIQUE_MODE'] = 'production';
		$party = new class {
			public $idprof1 = '552046955';
			public $array_options = array();
			public $updates = array();
			public $fail = false;
			public $db;
			/** @return void */
			public function __construct() {
				$this->db = new class {
					public $events = array();
					/** @return void */
					public function begin() { $this->events[] = 'begin'; }
					/** @return void */
					public function commit() { $this->events[] = 'commit'; }
					/** @return void */
					public function rollback() { $this->events[] = 'rollback'; }
				};
			}
			/** @param string $name Field @return int Result */
			public function updateExtraField($name) { $this->updates[] = $name; return $this->fail ? -1 : 1; }
		};
		$client = new RoutingTestClient();
		$client->response = array('data' => $this->engie());
		$address = '0225:552046955_BIENS_SERVICES_FRAIS_GENERAUX';
		$this->assertTrue(FacturelectRouting::saveThirdpartyAddress($party, $address, $client)['success']);
		$this->assertSame(array('begin', 'commit'), $party->db->events);
		$invoice = (object) array('array_options' => array());
		$this->assertSame('552046955_BIENS_SERVICES_FRAIS_GENERAUX', FacturelectDiagnostic::routing($party, $invoice)['identifier']);
		$invoice->array_options['options_facturelect_buyer_address'] = '0225:552046955_FRAIS_BANCAIRES';
		$this->assertSame('552046955_FRAIS_BANCAIRES', FacturelectDiagnostic::routing($party, $invoice)['identifier']);
		$previous = $party->array_options;
		$this->assertFalse(FacturelectRouting::saveThirdpartyAddress($party, '0225:552046955_FLEET', $client)['success']);
		$this->assertSame($previous, $party->array_options);
		$party->fail = true;
		$this->assertFalse(FacturelectRouting::saveThirdpartyAddress($party, '0225:552046955_FRAIS_BANCAIRES', $client)['success']);
		$this->assertSame($previous, $party->array_options);
		$this->assertSame('rollback', end($party->db->events));
		$party->fail = false;
		$client->calls = array();
		$this->assertTrue(FacturelectRouting::saveThirdpartyAddress($party, '', $client)['success']);
		$this->assertSame(array(), $client->calls);
		$this->assertSame('', $party->array_options['options_facturelect_id']);
	}

}
