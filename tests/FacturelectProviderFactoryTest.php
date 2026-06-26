<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/class/facturelectproviderfactory.class.php';

class FacturelectProviderFactoryTest extends TestCase
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

	public function testGetProviderDefaultReturnsSuperPdp()
	{
		// By default (no global set), the factory should return SuperPdpProvider
		$provider = FacturelectProviderFactory::getProvider($this->db);
		$this->assertInstanceOf('SuperPdpProvider', $provider);
	}

	public function testGetProviderReturnsSuperPdpWhenExplicitlyConfigured()
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals['FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER'] = 'superpdp';

		$provider = FacturelectProviderFactory::getProvider($this->db);
		$this->assertInstanceOf('SuperPdpProvider', $provider);
	}
}
