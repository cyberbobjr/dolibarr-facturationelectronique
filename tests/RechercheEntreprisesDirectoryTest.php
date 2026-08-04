<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/class/directories/recherchentreprises.class.php';

/**
 * Test double capturing the URLs requested and replaying canned API responses in order.
 */
class StubRechercheEntreprisesDirectory extends RechercheEntreprisesDirectory
{
	/** @var array Queued responses, shifted on each call */
	public $responses = array();

	/** @var array URLs requested so far */
	public $requested_urls = array();

	/**
	 * @param	string		$url	Requested URL
	 * @return	array|bool			Next canned response
	 */
	protected function httpGetJson($url)
	{
		$this->requested_urls[] = $url;
		if (empty($this->responses)) {
			return array('results' => array());
		}

		$next = array_shift($this->responses);
		if ($next === false) {
			$this->error = 'stubbed transport error';
		}

		return $next;
	}

	/**
	 * Query strings of the requests performed, in order
	 *
	 * @return	string[]
	 */
	public function getRequestedQueries()
	{
		$queries = array();
		foreach ($this->requested_urls as $url) {
			parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
			$queries[] = isset($params['q']) ? $params['q'] : '';
		}

		return $queries;
	}
}

class RechercheEntreprisesDirectoryTest extends TestCase
{
	private $db;

	protected function setUp(): void
	{
		$this->db = new DoliDB();
	}

	/**
	 * One realistic API result, trimmed to the fields the mapper reads.
	 *
	 * @return	array
	 */
	private function apiResult()
	{
		return array(
			'siren' => '200058485',
			'nom_complet' => 'CA VAL PARISIS (CAVP)',
			'nom_raison_sociale' => 'CA VAL PARISIS',
			'etat_administratif' => 'A',
			'siege' => array(
				'siret' => '20005848500018',
				'numero_voie' => '271',
				'type_voie' => 'CHAUSSEE',
				'libelle_voie' => 'JULES CESAR',
				'code_postal' => '95250',
				'libelle_commune' => 'BEAUCHAMP',
				'adresse' => '271 CHAUSSEE JULES CESAR 95250 BEAUCHAMP',
			),
		);
	}

	// ---------------------------------------------------------------- normalization

	public function testNormalizeStripsAccentsPunctuationAndCase()
	{
		$this->assertSame(
			'agglomeration du val d oise',
			RechercheEntreprisesDirectory::normalizeSearchText("Agglomération du Val d'Oise")
		);
	}

	public function testNormalizeCollapsesSeparatorsAndTrims()
	{
		$this->assertSame('s a r l dupont', RechercheEntreprisesDirectory::normalizeSearchText('  S.A.R.L.  --  Dupont  '));
		$this->assertSame('', RechercheEntreprisesDirectory::normalizeSearchText('   '));
	}

	// ---------------------------------------------------------------- identifiers

	public function testExtractIdentifierAcceptsSirenAndSiret()
	{
		$this->assertSame('200058485', RechercheEntreprisesDirectory::extractIdentifier('200 058 485'));
		$this->assertSame('20005848500018', RechercheEntreprisesDirectory::extractIdentifier('20005848500018'));
	}

	public function testExtractIdentifierRejectsNamesAndWrongLengths()
	{
		$this->assertSame('', RechercheEntreprisesDirectory::extractIdentifier('SARL 2000'));
		$this->assertSame('', RechercheEntreprisesDirectory::extractIdentifier('12345'));
		$this->assertSame('', RechercheEntreprisesDirectory::extractIdentifier(''));
	}

	// ---------------------------------------------------------------- query variants

	public function testFirstVariantKeepsTheWholeNameButDropsLegalForms()
	{
		// A legal form never discriminates and demonstrably poisons the ranking, so it is
		// stripped up front rather than as a fallback.
		$variants = RechercheEntreprisesDirectory::buildQueryVariants('SARL Dupont Menuiserie');
		$this->assertSame('dupont menuiserie', $variants[0]);

		$variants = RechercheEntreprisesDirectory::buildQueryVariants("Agglomération du Val d'Oise Val Parisis");
		$this->assertSame('agglomeration du val d oise val parisis', $variants[0]);
	}

	public function testSecondVariantDropsStopWordsAndSingleLetters()
	{
		$variants = RechercheEntreprisesDirectory::buildQueryVariants("Agglomération du Val d'Oise Val Parisis");
		$this->assertSame('agglomeration val oise val parisis', $variants[1]);
	}

	public function testDistinctiveVariantsIgnoreGenericOrganizationalWords()
	{
		$variants = RechercheEntreprisesDirectory::buildQueryVariants("Agglomération du Val d'Oise Val Parisis");
		// "agglomeration" is the longest token but the least distinctive one: keeping it
		// surfaces a dissolved EPCI instead of the active community.
		$this->assertContains('parisis oise val', $variants);
		$this->assertContains('parisis oise', $variants);
		$this->assertNotContains('agglomeration parisis', $variants);
	}

	public function testNameMadeOnlyOfGenericWordsStillProducesAQuery()
	{
		$variants = RechercheEntreprisesDirectory::buildQueryVariants('Communauté de Communes', true);

		$this->assertNotEmpty($variants);
		foreach ($variants as $variant) {
			$this->assertNotSame('', $variant);
		}
	}

	public function testSingleTokenVariantOnlyWhenALocationFilterNarrowsTheSearch()
	{
		$without = RechercheEntreprisesDirectory::buildQueryVariants("Agglomération du Val d'Oise Val Parisis", false);
		$with = RechercheEntreprisesDirectory::buildQueryVariants("Agglomération du Val d'Oise Val Parisis", true);

		$this->assertNotContains('parisis', $without);
		$this->assertContains('parisis', $with);
	}

	public function testQueryVariantsAreDeduplicatedAndNeverEmpty()
	{
		$variants = RechercheEntreprisesDirectory::buildQueryVariants('Michelin');

		$this->assertSame(array('michelin'), $variants);
	}

	public function testQueryVariantsOfBlankInputIsEmpty()
	{
		$this->assertSame(array(), RechercheEntreprisesDirectory::buildQueryVariants('   '));
	}

	// ---------------------------------------------------------------- location filters

	public function testFullPostcodeUsesCodePostalFilter()
	{
		$this->assertSame(array('code_postal' => '95250'), RechercheEntreprisesDirectory::buildLocationFilters('95250'));
	}

	public function testDepartmentUsesDepartementFilter()
	{
		// The API rejects a 2-digit value on code_postal, hence the dedicated filter
		$this->assertSame(array('departement' => '95'), RechercheEntreprisesDirectory::buildLocationFilters('95'));
		$this->assertSame(array('departement' => '2A'), RechercheEntreprisesDirectory::buildLocationFilters('2a'));
		$this->assertSame(array('departement' => '971'), RechercheEntreprisesDirectory::buildLocationFilters('971'));
	}

	public function testUnusableLocationIsIgnored()
	{
		$this->assertSame(array(), RechercheEntreprisesDirectory::buildLocationFilters(''));
		$this->assertSame(array(), RechercheEntreprisesDirectory::buildLocationFilters('Beauchamp'));
	}

	// ---------------------------------------------------------------- search & mapping

	public function testSearchMapsApiResultOntoTheModalShape()
	{
		$directory = new StubRechercheEntreprisesDirectory($this->db);
		$directory->responses = array(array('results' => array($this->apiResult())));

		$companies = $directory->searchCompanies('CA Val Parisis', '95250');

		$this->assertCount(1, $companies);
		$this->assertSame('200058485', $companies[0]['number']);
		$this->assertSame('CA VAL PARISIS (CAVP)', $companies[0]['formal_name']);
		// The street must not repeat the postcode and the city, which Dolibarr stores separately
		$this->assertSame('271 CHAUSSEE JULES CESAR', $companies[0]['address']);
		$this->assertSame('95250', $companies[0]['postcode']);
		$this->assertSame('BEAUCHAMP', $companies[0]['city']);
		$this->assertSame('20005848500018', $companies[0]['siret']);
		$this->assertTrue($companies[0]['is_active']);
		$this->assertSame('recherche_entreprises', $companies[0]['source']);
	}

	public function testStreetFallsBackToTheFlatAddressWithoutPostcodeAndCity()
	{
		$raw = $this->apiResult();
		unset($raw['siege']['numero_voie'], $raw['siege']['type_voie'], $raw['siege']['libelle_voie']);

		$directory = new StubRechercheEntreprisesDirectory($this->db);
		$directory->responses = array(array('results' => array($raw)));

		$companies = $directory->searchCompanies('CA Val Parisis');

		$this->assertSame('271 CHAUSSEE JULES CESAR', $companies[0]['address']);
	}

	public function testClosedCompanyIsFlaggedInactive()
	{
		$raw = $this->apiResult();
		$raw['etat_administratif'] = 'C';

		$directory = new StubRechercheEntreprisesDirectory($this->db);
		$directory->responses = array(array('results' => array($raw)));

		$companies = $directory->searchCompanies('CA Val Parisis');

		$this->assertFalse($companies[0]['is_active']);
	}

	public function testSearchStopsAtTheFirstVariantThatMatches()
	{
		$directory = new StubRechercheEntreprisesDirectory($this->db);
		$directory->responses = array(array('results' => array($this->apiResult())));

		$directory->searchCompanies('CA Val Parisis', '95250');

		$this->assertCount(1, $directory->requested_urls);
		$this->assertSame('ca val parisis', $directory->used_query);
	}

	public function testSearchBroadensUntilAVariantMatchesAndReportsTheQueryUsed()
	{
		$directory = new StubRechercheEntreprisesDirectory($this->db);
		// The user typed the Dolibarr third-party name: exact and intermediate variants miss,
		// only the most distinctive token combined with the postcode finds the company.
		// 5 distinct variants are produced for this input, the last one being the single
		// most distinctive token (allowed here because a postcode narrows the search).
		$directory->responses = array(
			array('results' => array()),
			array('results' => array()),
			array('results' => array()),
			array('results' => array()),
			array('results' => array($this->apiResult())),
		);

		$companies = $directory->searchCompanies("Agglomération du Val d'Oise Val Parisis", '95250');

		$this->assertCount(1, $companies);
		$this->assertSame('parisis', $directory->used_query);
		$queries = $directory->getRequestedQueries();
		$this->assertSame('agglomeration du val d oise val parisis', $queries[0]);
		// Every request keeps the postcode filter
		foreach ($directory->requested_urls as $url) {
			$this->assertStringContainsString('code_postal=95250', $url);
		}
	}

	public function testSearchByIdentifierIssuesASingleUnfilteredQuery()
	{
		$directory = new StubRechercheEntreprisesDirectory($this->db);
		$directory->responses = array(array('results' => array($this->apiResult())));

		$directory->searchCompanies('200 058 485', '75002');

		$this->assertCount(1, $directory->requested_urls);
		$this->assertSame('200058485', $directory->used_query);
		// A wrong postcode typed next to a SIREN must not hide the company
		$this->assertStringNotContainsString('code_postal', $directory->requested_urls[0]);
	}

	public function testExhaustedVariantsReturnAnEmptyListNotAnError()
	{
		$directory = new StubRechercheEntreprisesDirectory($this->db);
		$directory->responses = array();

		$companies = $directory->searchCompanies('Entreprise Totalement Inconnue', '95250');

		$this->assertSame(array(), $companies);
		$this->assertSame('', $directory->error);
	}

	public function testTransportErrorAbortsTheWholeSearch()
	{
		$directory = new StubRechercheEntreprisesDirectory($this->db);
		$directory->responses = array(false);

		$companies = $directory->searchCompanies('CA Val Parisis');

		$this->assertFalse($companies);
		$this->assertNotSame('', $directory->error);
		// No retry loop on a broken transport
		$this->assertCount(1, $directory->requested_urls);
	}

	public function testActiveCompaniesAreOfferedBeforeClosedOnes()
	{
		$closed = $this->apiResult();
		$closed['siren'] = '249500521';
		$closed['nom_complet'] = 'COMMUNAUTE AGGLOMERATION LE PARISIS (CALP)';
		$closed['etat_administratif'] = 'C';

		$directory = new StubRechercheEntreprisesDirectory($this->db);
		// The registry returns the dissolved EPCI first, at the very same address
		$directory->responses = array(array('results' => array($closed, $this->apiResult())));

		$companies = $directory->searchCompanies('Val Parisis', '95250');

		$this->assertSame('200058485', $companies[0]['number']);
		$this->assertTrue($companies[0]['is_active']);
		// Closed companies stay visible (badged in the modal), just never on top
		$this->assertSame('249500521', $companies[1]['number']);
	}

	public function testEmptyNameIsRejected()
	{
		$directory = new StubRechercheEntreprisesDirectory($this->db);

		$this->assertFalse($directory->searchCompanies('   '));
		$this->assertNotSame('', $directory->error);
		$this->assertCount(0, $directory->requested_urls);
	}
}
