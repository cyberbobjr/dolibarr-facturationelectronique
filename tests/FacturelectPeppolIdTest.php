<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/class/facturelectpeppolid.class.php';

class FacturelectPeppolIdTest extends TestCase
{
	// ---------------------------------------------------------------- scheme handling

	public function testSchemeIsSplitOffTheIdentifier()
	{
		$parsed = FacturelectPeppolId::parse('0225:200058485');

		$this->assertSame('0225', $parsed['scheme']);
		$this->assertSame('200058485', $parsed['identifier']);
	}

	public function testMissingSchemeFallsBackToTheProvidedOneThenToTheDefault()
	{
		$this->assertSame('0009', FacturelectPeppolId::parse('200058485', '0009')['scheme']);
		$this->assertSame('0225', FacturelectPeppolId::parse('200058485')['scheme']);
	}

	public function testExplicitSchemeInTheIdentifierWinsOverTheProvidedOne()
	{
		$this->assertSame('0225', FacturelectPeppolId::parse('0225:200058485', '0009')['scheme']);
	}

	// ---------------------------------------------------------------- underscore form

	public function testSirenOnly()
	{
		$parsed = FacturelectPeppolId::parse('0225:200058485');

		$this->assertSame('200058485', $parsed['siren']);
		$this->assertSame('', $parsed['siret']);
		$this->assertSame('', $parsed['service']);
	}

	public function testSirenAndSiret()
	{
		$parsed = FacturelectPeppolId::parse('0225:200058485_20005848500018');

		$this->assertSame('200058485', $parsed['siren']);
		$this->assertSame('20005848500018', $parsed['siret']);
		$this->assertSame('', $parsed['service']);
	}

	public function testSirenSiretAndServiceCode()
	{
		$parsed = FacturelectPeppolId::parse('0225:200058485_20005848500018_RH');

		$this->assertSame('200058485', $parsed['siren']);
		$this->assertSame('20005848500018', $parsed['siret']);
		$this->assertSame('RH', $parsed['service']);
	}

	public function testServiceCodeMayContainUnderscores()
	{
		// Real SuperPDP value: everything after the SIRET belongs to the service code,
		// however many underscores it holds.
		$parsed = FacturelectPeppolId::parse('0225:200058485_20005848500018_FACTURES_PUBLIQUES');

		$this->assertSame('200058485', $parsed['siren']);
		$this->assertSame('20005848500018', $parsed['siret']);
		$this->assertSame('FACTURES_PUBLIQUES', $parsed['service']);
	}

	public function testSirenFollowedDirectlyByAServiceCode()
	{
		// Second block is not a 14-digit SIRET, so it is part of the service code
		$parsed = FacturelectPeppolId::parse('0225:200058485_FACTURES_PUBLIQUES');

		$this->assertSame('200058485', $parsed['siren']);
		$this->assertSame('', $parsed['siret']);
		$this->assertSame('FACTURES_PUBLIQUES', $parsed['service']);
	}

	public function testSiretNotBelongingToTheSirenIsRejected()
	{
		// A SIRET always starts with its SIREN; inconsistent data must not be written
		$parsed = FacturelectPeppolId::parse('0225:200058485_99999999900018');

		$this->assertSame('200058485', $parsed['siren']);
		$this->assertSame('', $parsed['siret']);
		$this->assertSame('99999999900018', $parsed['service']);
	}

	// ---------------------------------------------------------------- legacy star form

	public function testLegacyStarFormBuildsTheSiretFromTheNic()
	{
		$parsed = FacturelectPeppolId::parse('0225:853322915*00012');

		$this->assertSame('853322915', $parsed['siren']);
		$this->assertSame('85332291500012', $parsed['siret']);
	}

	public function testLegacyStarFormLeftPadsAShortNic()
	{
		$parsed = FacturelectPeppolId::parse('853322915*12');

		$this->assertSame('85332291500012', $parsed['siret']);
	}

	// ---------------------------------------------------------------- bare SIRET

	public function testBareSiretYieldsBothSirenAndSiret()
	{
		$parsed = FacturelectPeppolId::parse('20005848500018');

		$this->assertSame('200058485', $parsed['siren']);
		$this->assertSame('20005848500018', $parsed['siret']);
	}

	// ---------------------------------------------------------------- safety

	public function testIdentifierKeepsTheFullRoutingValue()
	{
		// What gets stored in the facturelect_id extrafield must stay the complete
		// routing address, service code included.
		$parsed = FacturelectPeppolId::parse('0225:200058485_20005848500018_RH');

		$this->assertSame('200058485_20005848500018_RH', $parsed['identifier']);
	}

	public function testUnparsableSirenIsReportedEmptyRatherThanGuessed()
	{
		// The whole point: never let a non-SIREN value reach llx_societe.siren
		foreach (array('0225:ABCDEF', '0225:12345', '0225:', 'FR12345678901') as $raw) {
			$parsed = FacturelectPeppolId::parse($raw);
			$this->assertSame('', $parsed['siren'], 'for ' . $raw);
			$this->assertSame('', $parsed['siret'], 'for ' . $raw);
		}
	}

	public function testWhitespaceAndEmptyInputAreTolerated()
	{
		$parsed = FacturelectPeppolId::parse('  0225:200058485  ');
		$this->assertSame('200058485', $parsed['siren']);

		$empty = FacturelectPeppolId::parse('');
		$this->assertSame('', $empty['identifier']);
		$this->assertSame('', $empty['siren']);
		$this->assertSame('0225', $empty['scheme']);
	}

	public function testLabelDescribesWhatTheAddressActuallyRoutesTo()
	{
		$this->assertSame('SIREN seul (toute l\'entreprise)', FacturelectPeppolId::describe(FacturelectPeppolId::parse('0225:200058485')));
		$this->assertSame('SIRET 20005848500018', FacturelectPeppolId::describe(FacturelectPeppolId::parse('0225:200058485_20005848500018')));
		$this->assertSame('SIRET 20005848500018 — service RH', FacturelectPeppolId::describe(FacturelectPeppolId::parse('0225:200058485_20005848500018_RH')));
		$this->assertSame('SIREN seul (toute l\'entreprise) — service FACTURES_PUBLIQUES', FacturelectPeppolId::describe(FacturelectPeppolId::parse('0225:200058485_FACTURES_PUBLIQUES')));
	}
}
