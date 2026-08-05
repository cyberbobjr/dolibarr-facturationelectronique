<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/class/facturelectdirectoryfactory.class.php';

class FacturelectDirectoryFactoryTest extends TestCase
{
	private $db;

	protected function setUp(): void
	{
		$this->db = new DoliDB();
	}

	protected function tearDown(): void
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals = array();
	}

	public function testDefaultSourceIsThePublicApi()
	{
		// No key, no account: it must work on a freshly installed module
		$this->assertSame('recherche_entreprises', FacturelectDirectoryFactory::getDefaultCode());
		$this->assertInstanceOf('RechercheEntreprisesDirectory', FacturelectDirectoryFactory::getDirectory($this->db));
	}

	public function testConfiguredSourceIsHonoured()
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals[FacturelectDirectoryFactory::SETTING_NAME] = 'superpdp';

		$this->assertSame('superpdp', FacturelectDirectoryFactory::getDefaultCode());
		$this->assertInstanceOf('SuperPdpDirectory', FacturelectDirectoryFactory::getDirectory($this->db));
	}

	public function testStoredGarbageFallsBackToTheDefault()
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals[FacturelectDirectoryFactory::SETTING_NAME] = 'a_provider_that_was_removed';

		$this->assertSame('recherche_entreprises', FacturelectDirectoryFactory::getDefaultCode());
		$this->assertInstanceOf('RechercheEntreprisesDirectory', FacturelectDirectoryFactory::getDirectory($this->db));
	}

	public function testExplicitCodeOverridesTheConfiguredDefault()
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals[FacturelectDirectoryFactory::SETTING_NAME] = 'superpdp';

		$this->assertInstanceOf(
			'RechercheEntreprisesDirectory',
			FacturelectDirectoryFactory::getDirectory($this->db, 'recherche_entreprises')
		);
	}

	public function testUnknownRequestedCodeFallsBackToTheConfiguredDefault()
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals[FacturelectDirectoryFactory::SETTING_NAME] = 'superpdp';

		// A truncated or tampered URL parameter must never break the lookup modal
		$this->assertInstanceOf('SuperPdpDirectory', FacturelectDirectoryFactory::getDirectory($this->db, 'nope'));
	}

	public function testEveryAdvertisedSourceCanBeInstantiatedAndReportsItsOwnCode()
	{
		foreach (array_keys(FacturelectDirectoryFactory::getAvailableSources()) as $code) {
			$directory = FacturelectDirectoryFactory::getDirectory($this->db, $code);

			$this->assertInstanceOf('FacturelectDirectory', $directory);
			$this->assertSame($code, $directory->getCode());
		}
	}
}
