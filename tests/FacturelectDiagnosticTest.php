<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/class/facturelectdiagnostic.class.php';
require_once dirname(__DIR__).'/class/facturelectrouting.class.php';

if (!function_exists('dol_sanitizeFileName')) {
	function dol_sanitizeFileName($name) { return preg_replace('/[^a-zA-Z0-9_-]/', '', $name); }
}

/** Database double retaining diagnostic snapshots and supporting scoped reads. */
class DiagnosticTestDb extends DoliDB
{
	public $snapshots = array();
	public $rows = array();
	public $fail = false;
	public $stored = array();
	private $pendingPayload;
	public function escape($string) {
		$value = json_decode($string, true);
		if (is_array($value)) { $this->pendingPayload = $string; }
		if (is_array($value) && isset($value['routing'])) { $this->snapshots[] = $value; }
		return parent::escape($string);
	}
	public function query($sql) {
		parent::query($sql);
		if ($this->fail) { return false; }
		if (strpos($sql, 'INSERT') === 0 && preg_match("/VALUES \\( '[^']*', '[^']*', '([^']*)'/", $sql, $matches)) {
			$this->stored[] = (object) array('rowid' => count($this->stored) + 1, 'action' => $matches[1], 'response_payload' => $this->pendingPayload);
		}
		if (strpos($sql, 'SELECT') === 0 && !empty($this->stored) && preg_match("/WHERE action = '([^']*)'/", $sql, $matches)) {
			$rows = array_values(array_filter($this->stored, function ($row) use ($matches) { return $row->action === $matches[1]; }));
			if (strpos($sql, 'DESC') !== false) { $rows = array_reverse($rows); }
			return new ArrayIterator($rows);
		}
		return true;
	}
	public function plimit($limit) { return ' LIMIT '.(int) $limit; }
	public function fetch_object($res) {
		if ($res instanceof ArrayIterator) { $row = $res->current(); $res->next(); return $row; }
		return array_shift($this->rows);
	}
	public function free($res) {}
}

/** Provider client double; no real invoice or external API is used. */
class DiagnosticTestClient
{
	public $provider;
	public $error = '';
	public $calls = array();
	public $pdf = '%PDF-test';
	public $sent = array('id' => 123);
	public $session = array('company' => 1);
	public $entries = array('data' => array());
	public function __construct() { $this->provider = (object) array('lastHttpExchange' => array()); }
	public function getProviderName() { return 'SuperPDP'; }
	public function convertInvoiceToFacturX($payload, $path) {
		$this->calls[] = 'convert';
		$this->provider->lastHttpExchange = array('path' => '/v1.beta/invoices/convert', 'status' => $this->pdf === false ? 400 : 200);
		return $this->pdf;
	}
	public function sendFacturXInvoice($pdf, $ref) {
		$this->calls[] = 'deposit';
		$this->provider->lastHttpExchange = array('path' => '/v1.beta/invoices', 'status' => $this->sent === false ? 400 : 200,
			'response' => array('message' => $this->error));
		return $this->sent;
	}
	public function checkSession() { $this->calls[] = 'connection'; return $this->session; }
	public function getCompanyEntries($siren) { $this->calls[] = 'directory'; return $this->entries; }
}

/** Configurable payload builder. */
class DiagnosticTestActions
{
	public $payload;
	public $error = 'Invalid invoice';
	/** @param string $ref Reference @param string $level Log level @param string $message Message @return void */
	public function writeLog($ref, $level, $message) {}
	public function buildEnInvoiceJson($invoice) { return $this->payload; }
	/** @param object $invoice Invoice @param object $client Directory client @return array Verdict */
	public function checkBuyerRouting($invoice, $client) { return (new FacturelectRouting())->check($invoice->thirdparty, $client); }

}

class FacturelectDiagnosticTest extends TestCase
{
	/** @return void */
	public function testTransmissionRequiresInternalWritePermissionAndSessionToken()
	{
		$user = (object) array('admin' => false, 'socid' => 0, 'rights' => (object) array('facture' => (object) array('creer' => false)));
		$this->assertFalse(FacturelectDiagnostic::canTransmit($user, 'valid', array('valid'), 'POST'));
		$user->rights->facture->creer = true;
		$this->assertTrue(FacturelectDiagnostic::canTransmit($user, 'valid', array('old', 'valid'), 'POST'));
		$this->assertFalse(FacturelectDiagnostic::canTransmit($user, '', array(''), 'POST'));
		$this->assertFalse(FacturelectDiagnostic::canTransmit($user, 'wrong', array('valid'), 'POST'));
		$this->assertFalse(FacturelectDiagnostic::canTransmit($user, 'valid', array('valid'), 'GET'));
		$user->socid = 10;
		$user->admin = true;
		$this->assertFalse(FacturelectDiagnostic::canTransmit($user, 'valid', array('valid'), 'POST'));
	}

	/** @return void */
	public function testInvalidInvoiceStatesNeverCallProviderOrStoreAnAttempt()
	{
		foreach (array(0, 3, -1) as $status) {
			$this->invoice->statut = $status;
			$res = FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
			$this->assertFalse($res['response']);
			$this->assertSame('FacturelectSendInvalidStatus', $res['error']);
		}
		$this->assertSame(array(), $this->client->calls);
		$this->assertSame(array(), $this->db->snapshots);
		$this->invoice->statut = 2;
		$this->assertSame(123, FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate())['response']['id']);
	}

	/** @return void */
	public function testDuplicateDepositIsReconciledBeforeDiagnosticPersistence()
	{
		$this->client->sent = false;
		$this->client->error = 'Facture déjà existante (id 456)';
		$res = FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
		$this->assertSame('456', $res['response']['id']);
		$this->assertSame('', $res['error']);
		$record = $this->db->snapshots[0];
		$this->assertSame('456', $record['pdp_id']);
		$this->assertTrue($record['steps'][2]['ok']);
		$this->assertTrue($record['steps'][2]['reused']);
		$this->assertSame(400, $record['steps'][2]['http']['status']);
		$this->assertSame(array('convert', 'deposit'), $this->client->calls);
	}

	/** @return void */
	public function testBannerUsesOnlyFreshMatchingPersistedDirectoryEvidence()
	{
		$record = FacturelectDiagnostic::context($this->invoice, $this->client);
		$record['directory'] = array('status' => 'not_active', 'entries' => array(array('identifier' => '0225:999044340_OTHER', 'active' => true)));
		$this->assertNull(FacturelectDiagnostic::cachedRoutingVerdict($this->db, $this->invoice, $this->client));
		FacturelectDiagnostic::store($this->db, $this->invoice, 'check', $record);
		$this->assertSame('missing', FacturelectDiagnostic::cachedRoutingVerdict($this->db, $this->invoice, $this->client)['status']);
		$this->invoice->array_options['options_facturelect_buyer_address'] = '0225:999044340_OTHER';
		$this->assertNull(FacturelectDiagnostic::cachedRoutingVerdict($this->db, $this->invoice, $this->client));
		$this->invoice->array_options = array();
		$this->db->stored[0]->response_payload = json_encode(array_merge($record, array('at' => date('c', time() - 301))));
		$this->assertNull(FacturelectDiagnostic::cachedRoutingVerdict($this->db, $this->invoice, $this->client));
		$this->assertSame(array(), $this->client->calls);
	}

	private $db;
	private $invoice;
	private $client;
	private $actions;
	private $temp;

	protected function setUp(): void
	{
		global $dolibarr_mock_globals, $conf;
		$dolibarr_mock_globals = array('FACTURATION_ELECTRONIQUE_MODE' => 'production', 'FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER' => 'superpdp', 'FACTURATION_ELECTRONIQUE_ROUTING_CHECK_MODE' => 'off');
		$this->db = new DiagnosticTestDb();
		$this->invoice = (object) array('id' => 28, 'statut' => 1, 'ref' => 'TEST-28', 'entity' => 2, 'db' => $this->db,
			'thirdparty' => (object) array('name' => 'PRIVATE BUYER', 'idprof1' => '999044340', 'idprof2' => '99904434000012',
				'array_options' => array('options_facturelect_id' => '999044340_SERVICE')));
		$this->client = new DiagnosticTestClient();
		$this->actions = new DiagnosticTestActions();
		$this->actions->payload = array('buyer' => array('name' => 'PRIVATE BUYER',
			'electronic_address' => array('scheme' => '0225', 'value' => '999044340_SERVICE')));
		$this->temp = sys_get_temp_dir().'/fe-diagnostic-'.bin2hex(random_bytes(6));
		mkdir($this->temp.'/TEST-28', 0700, true);
		file_put_contents($this->temp.'/TEST-28/TEST-28.pdf', '%PDF-test');
		$conf = (object) array('facture' => (object) array('dir_output' => $this->temp));
	}

	protected function tearDown(): void
	{
		unlink($this->temp.'/TEST-28/TEST-28.pdf');
		rmdir($this->temp.'/TEST-28');
		rmdir($this->temp);
	}

	/** @return void */
	public function testRoutingBlocksBeforeConversionAndWarnAllowsSending()
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals['FACTURATION_ELECTRONIQUE_ROUTING_CHECK_MODE'] = 'block';
		$res = FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
		$this->assertFalse($res['pdf']);
		$this->assertSame('FacturelectRoutingEmpty', $res['error']);
		$this->assertSame(array('directory'), $this->client->calls);
		$this->assertSame('routing', $this->db->snapshots[0]['steps'][0]['stage']);
		$dolibarr_mock_globals['FACTURATION_ELECTRONIQUE_ROUTING_CHECK_MODE'] = 'warn';
		$res = FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
		$this->assertSame(123, $res['response']['id']);
		$this->assertSame(array('directory', 'directory', 'convert', 'deposit'), $this->client->calls);
	}

	/** @return void */
	public function testRoutingApiFailureAllowsConversionAndDeposit()
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals['FACTURATION_ELECTRONIQUE_ROUTING_CHECK_MODE'] = 'block';
		$this->client->entries = false;
		$this->client->error = 'HTTP 503';
		$GLOBALS['routing_test_events'] = array();
		$res = FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
		$this->assertSame(123, $res['response']['id']);
		$this->assertSame(array('directory', 'convert', 'deposit'), $this->client->calls);
		$this->assertSame('error', $this->db->snapshots[0]['routing_check']['status']);
		$this->assertSame(array('warnings', 'FacturelectRoutingError'), $GLOBALS['routing_test_events'][0]);
	}

	public function testBothSuccessfulStagesAndActualPayloadAddressAreRetained()
	{
		$this->actions->payload['buyer']['electronic_address']['value'] = 'ACTUAL-PAYLOAD-ADDRESS';
		$res = FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
		$this->assertSame(123, $res['response']['id']);
		$this->assertSame(array('convert', 'deposit'), $this->client->calls);
		$this->assertSame(array('prepare', 'convert', 'deposit'), array_column($this->db->snapshots[0]['steps'], 'stage'));
		$this->assertSame('ACTUAL-PAYLOAD-ADDRESS', $this->db->snapshots[0]['routing']['identifier']);
		$this->assertStringContainsString('invoice-diagnostic:2:28:send', $this->db->last_query);
	}

	public function testPreparationFailureNeverConvertsOrDeposits()
	{
		$this->actions->payload = false;
		$res = FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
		$this->assertFalse($res['pdf']);
		$this->assertSame('Invalid invoice', $res['error']);
		$this->assertSame(array(), $this->client->calls);
		$this->assertFalse($this->db->snapshots[0]['steps'][0]['ok']);
	}

	public function testConversionFailureNeverDeposits()
	{
		$this->client->pdf = false;
		$this->client->error = 'Conversion rejected';
		FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
		$this->assertSame(array('convert'), $this->client->calls);
		$this->assertSame(400, $this->db->snapshots[0]['steps'][1]['http']['status']);
	}

	public function testPeppolFailureAndRetryKeepSeparateImmutableSnapshots()
	{
		$this->client->sent = false;
		$this->client->error = 'HTTP code 400 - pre-check: receiver address <0225:999044340> does not exist in peppol directory';
		$res = FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
		$this->assertFalse($res['response']);
		$this->client->sent = array('id' => 456);
		$this->actions->payload['buyer']['electronic_address']['value'] = 'UPDATED-ADDRESS';
		FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
		$this->assertCount(2, $this->db->snapshots);
		$this->assertSame('999044340_SERVICE', $this->db->snapshots[0]['routing']['identifier']);
		$this->assertFalse($this->db->snapshots[0]['steps'][2]['ok']);
		$this->assertTrue($this->db->snapshots[1]['steps'][2]['ok']);
		$this->assertSame('peppol_address_missing', FacturelectDiagnostic::supportReport($this->db->snapshots)[0]['steps'][2]['reason']);
	}

	public function testDirectoryCheckDoesNotSendAndDoesNotTurnAnApiErrorIntoEmptyResults()
	{
		$this->client->entries = false;
		$this->client->error = 'HTTP code 500';
		$res = FacturelectDiagnostic::check($this->invoice, $this->client);
		$this->assertSame(array('connection', 'directory'), $this->client->calls);
		$this->assertSame('error', $res['directory']['status']);
		$this->assertSame('HTTP code 500', $res['directory']['error']);
		$this->client->entries = array('meta' => array());
		$this->assertSame('error', FacturelectDiagnostic::check($this->invoice, $this->client)['directory']['status']);
	}

	public function testFailedConnectionLeavesDirectoryUnverified()
	{
		$this->client->session = false;
		$res = FacturelectDiagnostic::check($this->invoice, $this->client);
		$this->assertSame('unverified', $res['directory']['status']);
		$this->assertSame(array('connection'), $this->client->calls);
	}

	public function testDirectoryMatchingIsCaseInsensitiveAndTechnicalOrInactiveEntriesCannotQualify()
	{
		$routing = array('scheme' => '0225', 'identifier' => '999044340_SERVICE');
		$res = FacturelectDiagnostic::directory(array(array('identifier' => '0225:999044340_service', 'is_active' => true)), $routing);
		$this->assertSame('active', $res['status']);
		$res = FacturelectDiagnostic::directory(array(array('identifier' => '0225:999044340_SERVICE', 'is_active' => false)), $routing);
		$this->assertSame('not_active', $res['status']);
		$routing['identifier'] = '999044340_replyto';
		$res = FacturelectDiagnostic::directory(array(array('identifier' => '0225:999044340_REPLYTO', 'is_active' => true)), $routing);
		$this->assertSame('not_active', $res['status']);
		$this->assertTrue($res['entries'][0]['technical']);
		$this->assertSame('empty', FacturelectDiagnostic::directory(array(), $routing)['status']);
	}

	public function testSandboxAndSirenFallbackKeepExistingRoutingBehavior()
	{
		global $dolibarr_mock_globals;
		$this->invoice->thirdparty->array_options = array();
		$this->assertSame('999044340', FacturelectDiagnostic::routing($this->invoice->thirdparty)['identifier']);
		$dolibarr_mock_globals['FACTURATION_ELECTRONIQUE_MODE'] = 'sandbox';
		$res = FacturelectDiagnostic::routing($this->invoice->thirdparty);
		$this->assertSame('315143296_7182_999044340', $res['identifier']);
		$this->assertSame('siren_sandbox', $res['source']);
		$this->invoice->thirdparty->idprof1 = '000000001';
		$this->assertSame('315143296_7181', FacturelectDiagnostic::routing($this->invoice->thirdparty)['identifier']);
	}

	public function testSupportExportExcludesBusinessDataAndProviderFreeText()
	{
		$this->client->sent = false;
		$this->client->error = 'Private error with bank account FRSECRET';
		FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
		$report = json_encode(FacturelectDiagnostic::supportReport($this->db->snapshots));
		foreach (array('999044340', 'PRIVATE BUYER', 'FRSECRET', 'TEST-28', 'payload') as $secret) {
			$this->assertStringNotContainsString($secret, $report);
		}
		$this->assertStringContainsString('400', $report);
	}

	public function testCredentialFieldsAreRemovedRecursively()
	{
		$value = FacturelectDiagnostic::withoutSecrets(array('access_token' => 'SECRET', 'nested' => array('client_secret' => 'SECRET', 'message' => 'safe')));
		$this->assertStringNotContainsString('SECRET', json_encode($value));
		$this->assertSame('safe', $value['nested']['message']);
	}

	public function testLargeInvoiceSnapshotRoundTripsWithinTextColumnSize()
	{
		$this->actions->payload['long_lines'] = str_repeat('A line with "quotes" and a \\ slash.', 5000);
		FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
		$this->assertGreaterThan(2, count($this->db->stored));
		foreach ($this->db->stored as $row) { $this->assertLessThan(65535, strlen($row->response_payload)); }
		$res = FacturelectDiagnostic::load($this->db, $this->invoice, 'send');
		$this->assertSame($this->actions->payload, $res[0]['payload']);
		$this->assertCount(1, $res);
	}

	public function testMissingSnapshotPartIsReportedAsUnavailableHistory()
	{
		$this->actions->payload['long_lines'] = str_repeat('Invoice line', 6000);
		FacturelectDiagnostic::send($this->invoice, $this->actions, $this->client, new Translate());
		array_shift($this->db->stored);
		$this->assertFalse(FacturelectDiagnostic::load($this->db, $this->invoice, 'send'));
	}

	public function testHistoryReadScopesToInvoiceEntityAndPreservesDatabaseErrors()
	{
		$this->db->rows = array((object) array('rowid' => 9, 'response_payload' => json_encode(array('at' => 'old'))));
		$res = FacturelectDiagnostic::load($this->db, $this->invoice, 'send');
		$this->assertSame(9, $res[0]['log_id']);
		$this->assertStringContainsString("action = 'invoice-diagnostic:2:28:send'", $this->db->last_query);
		$this->db->fail = true;
		$this->assertFalse(FacturelectDiagnostic::load($this->db, $this->invoice, 'send'));
	}
}
