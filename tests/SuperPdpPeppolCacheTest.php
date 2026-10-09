<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/class/providers/superpdp.class.php';

if (!defined('DOL_DATA_ROOT')) {
	define('DOL_DATA_ROOT', sys_get_temp_dir().'/facturelect-cache-test-'.getmypid());
}

/** Provider stub counts actual directory requests without contacting SuperPDP. */
class PeppolCacheProvider extends SuperPdpProvider
{
	public $calls = 0;
	public $response = array('data' => array(array('identifier' => '123456789')));

	public function callApi($method, $path, $params = null, $raw_data = false, $mime_type = 'application/json', $raw_response = false, $action = '')
	{
		$this->calls++;
		return $this->response;
	}
}

/** Persistent directory cache contract. */
class SuperPdpPeppolCacheTest extends TestCase
{
	private $provider;
	private $oldConf;

	protected function setUp(): void
	{
		$this->oldConf = $GLOBALS['conf'] ?? null;
		$GLOBALS['conf'] = (object) array('entity' => 1);
		$GLOBALS['dolibarr_mock_globals'] = array();
		$this->provider = new PeppolCacheProvider(new DoliDB());
	}

	protected function tearDown(): void
	{
		foreach (glob(DOL_DATA_ROOT.'/facturationelectronique/peppol-cache/*') ?: array() as $file) {
			unlink($file);
		}
		$GLOBALS['conf'] = $this->oldConf;
		$GLOBALS['dolibarr_mock_globals'] = array();
	}

	public function testCachePersistsAcrossInstancesAndNormalizesSiren()
	{
		$this->assertSame($this->provider->response, $this->provider->getCompanyEntries('123 456 789'));
		$other = new PeppolCacheProvider(new DoliDB());
		$this->assertSame($this->provider->response, $other->getCompanyEntries('123456789'));
		$this->assertSame(1, $this->provider->calls);
		$this->assertSame(0, $other->calls);
		$this->provider->getCompanyEntries('987654321');
		$this->assertSame(2, $this->provider->calls);
	}

	public function testExpiryAndReducedDurationFetchAgain()
	{
		$this->provider->getCompanyEntries('123456789');
		$file = glob(DOL_DATA_ROOT.'/facturationelectronique/peppol-cache/*.json')[0];
		$cached = json_decode(file_get_contents($file), true);
		$cached['stored_at'] = time() - 120;
		file_put_contents($file, json_encode($cached));
		$this->provider->getCompanyEntries('123456789');
		$this->assertSame(1, $this->provider->calls);
		$GLOBALS['dolibarr_mock_globals']['FACTURATION_ELECTRONIQUE_PEPPOL_CACHE_MINUTES'] = 1;
		$this->provider->getCompanyEntries('123456789');
		$this->assertSame(2, $this->provider->calls);
	}

	public function testDefaultDurationExpiresAfterOneDay()
	{
		$this->provider->getCompanyEntries('123456789');
		$file = glob(DOL_DATA_ROOT.'/facturationelectronique/peppol-cache/*.json')[0];
		$cached = json_decode(file_get_contents($file), true);
		$cached['stored_at'] = time() - 86401;
		file_put_contents($file, json_encode($cached));
		$this->provider->getCompanyEntries('123456789');
		$this->assertSame(2, $this->provider->calls);
	}

	public function testDisabledCacheAlwaysCallsProvider()
	{
		$this->provider->getCompanyEntries('123456789');
		$GLOBALS['dolibarr_mock_globals']['FACTURATION_ELECTRONIQUE_PEPPOL_CACHE_MINUTES'] = 0;
		$this->provider->getCompanyEntries('123456789');
		$this->provider->getCompanyEntries('123456789');
		$this->assertSame(3, $this->provider->calls);
	}

	public function testEntityModeAndCredentialsAreIsolated()
	{
		$this->provider->getCompanyEntries('123456789');
		$GLOBALS['conf']->entity = 2;
		$this->provider->getCompanyEntries('123456789');
		$GLOBALS['dolibarr_mock_globals']['FACTURATION_ELECTRONIQUE_MODE'] = 'production';
		$this->provider->getCompanyEntries('123456789');
		$GLOBALS['dolibarr_mock_globals']['FACTURATION_ELECTRONIQUE_PROD_CLIENT_ID'] = 'other-account';
		$this->provider->getCompanyEntries('123456789');
		$GLOBALS['dolibarr_mock_globals']['FACTURATION_ELECTRONIQUE_PROD_CLIENT_SECRET'] = 'rotated-secret';
		$this->provider->getCompanyEntries('123456789');
		$this->assertSame(5, $this->provider->calls);
	}

	public function testErrorsAndMalformedResponsesAreNotCachedButEmptyListsAre()
	{
		foreach (array(false, array('unexpected' => true), array('data' => null)) as $response) {
			$this->provider->response = $response;
			$this->assertSame($response, $this->provider->getCompanyEntries('123456789'));
			$this->assertSame($response, $this->provider->getCompanyEntries('123456789'));
		}
		$this->assertSame(6, $this->provider->calls);
		$this->provider->response = array('data' => array());
		$this->provider->getCompanyEntries('123456789');
		$this->assertSame(array('data' => array()), $this->provider->getCompanyEntries('123456789'));
		$this->assertSame(7, $this->provider->calls);
	}

	public function testCorruptCacheAndUnavailableStorageFallBackToProvider()
	{
		$this->provider->getCompanyEntries('123456789');
		$file = glob(DOL_DATA_ROOT.'/facturationelectronique/peppol-cache/*.json')[0];
		file_put_contents($file, 'broken json');
		$this->provider->getCompanyEntries('123456789');
		$this->assertSame(2, $this->provider->calls);
		unlink($file);
		mkdir($file);
		try {
			$this->assertSame($this->provider->response, $this->provider->getCompanyEntries('123456789'));
			$this->assertSame(3, $this->provider->calls);
		} finally {
			rmdir($file);
		}
	}

	/** @dataProvider malformedEntries */
	public function testMalformedEntriesAreNotPersisted($entries)
	{
		$this->provider->response = array('data' => $entries);
		$this->provider->getCompanyEntries('123456789');
		$this->provider->getCompanyEntries('123456789');
		$this->assertSame(2, $this->provider->calls);
		$this->assertSame(array(), glob(DOL_DATA_ROOT.'/facturationelectronique/peppol-cache/*.json'));
	}

	/** @dataProvider malformedEntries */
	public function testExistingMalformedEntriesAreIgnoredAndReplaced($entries)
	{
		$this->provider->getCompanyEntries('123456789');
		$file = glob(DOL_DATA_ROOT.'/facturationelectronique/peppol-cache/*.json')[0];
		$cached = json_decode(file_get_contents($file), true);
		$cached['response']['data'] = $entries;
		file_put_contents($file, json_encode($cached));
		$this->assertSame($this->provider->response, $this->provider->getCompanyEntries('123456789'));
		$this->assertSame(2, $this->provider->calls);
		$this->provider->getCompanyEntries('123456789');
		$this->assertSame(2, $this->provider->calls);
	}

	/** @return array Invalid directory entry collections */
	public static function malformedEntries()
	{
		return array(
			'boolean entry' => array(array(false)),
			'null entry' => array(array(null)),
			'string entry' => array(array('invalid')),
			'numeric entry' => array(array(42)),
			'associative collection' => array(array('entry' => array('identifier' => '123456789'))),
			'mixed entries' => array(array(array('identifier' => '123456789'), false)),
		);
	}
}
