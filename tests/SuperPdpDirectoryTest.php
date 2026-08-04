<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/class/directories/superpdpdirectory.class.php';

/**
 * Minimal stand-in for FacturelectClient, recording the calls made by the directory.
 */
class StubFacturelectClient
{
	/** @var string Error surfaced to the caller */
	public $error = '';

	/** @var array|bool Response returned by both search methods */
	public $response = array('data' => array());

	/** @var array Calls recorded as array(method, arguments...) */
	public $calls = array();

	/**
	 * @param	string		$siren	SIREN
	 * @return	array|bool
	 */
	public function searchCompany($siren)
	{
		$this->calls[] = array('searchCompany', $siren);
		return $this->response;
	}

	/**
	 * @param	string		$name	Name prefix
	 * @param	string		$zip	Postcode prefix
	 * @return	array|bool
	 */
	public function searchCompaniesList($name, $zip = '')
	{
		$this->calls[] = array('searchCompaniesList', $name, $zip);
		return $this->response;
	}

	/**
	 * @return	string
	 */
	public function getProviderName()
	{
		return 'SuperPDP';
	}
}

class SuperPdpDirectoryTest extends TestCase
{
	private $db;

	/** @var StubFacturelectClient */
	private $client;

	/** @var SuperPdpDirectory */
	private $directory;

	protected function setUp(): void
	{
		$this->db = new DoliDB();
		$this->client = new StubFacturelectClient();
		$this->directory = new SuperPdpDirectory($this->db, $this->client);
	}

	public function testCodeAndLabel()
	{
		$this->assertSame('superpdp', $this->directory->getCode());
		$this->assertSame('Annuaire SuperPDP', $this->directory->getLabel());
	}

	public function testNameSearchGoesThroughThePrefixEndpoint()
	{
		$this->client->response = array('data' => array(
			array(
				'number' => '200058485',
				'formal_name' => 'CA VAL PARISIS',
				'address' => '271 CHAUSSEE JULES CESAR',
				'postcode' => '95250',
				'city' => 'BEAUCHAMP',
			),
		));

		$companies = $this->directory->searchCompanies('CA VAL', ' 95250 ');

		$this->assertSame(array('searchCompaniesList', 'CA VAL', '95250'), $this->client->calls[0]);
		$this->assertCount(1, $companies);
		$this->assertSame('200058485', $companies[0]['number']);
		$this->assertSame('superpdp', $companies[0]['source']);
		// The provider directory only lists routable companies, so absence of the flag means active
		$this->assertTrue($companies[0]['is_active']);
		$this->assertSame('', $companies[0]['siret']);
	}

	public function testSiretInputIsNarrowedToItsSirenAndUsesTheNumberEndpoint()
	{
		$this->directory->searchCompanies('20005848500018', '95250');

		$this->assertSame(array('searchCompany', '200058485'), $this->client->calls[0]);
	}

	public function testMissingDataKeyYieldsAnEmptyList()
	{
		$this->client->response = array('meta' => array('total' => 0));

		$this->assertSame(array(), $this->directory->searchCompanies('Inconnue'));
	}

	public function testProviderErrorIsPropagated()
	{
		$this->client->response = false;
		$this->client->error = 'HTTP code 401';

		$this->assertFalse($this->directory->searchCompanies('CA VAL'));
		$this->assertSame('HTTP code 401', $this->directory->error);
	}
}
