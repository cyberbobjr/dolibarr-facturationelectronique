<?php
/* Copyright (C) 2026 Benjamin Marchand <contact@superpdp.tech>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/class/b2cresolver.class.php';

/**
 * Unit tests for the B2C (private individual) third-party resolver.
 *
 * A B2C customer (particulier) legitimately has no SIREN and is out of scope of
 * B2B e-invoicing (it falls under e-reporting). The resolver decides whether a
 * missing/invalid buyer SIREN must be treated as an error (B2B) or ignored (B2C).
 */
class B2cResolverTest extends TestCase
{
	/**
	 * A company (no private type, no override) is B2B, not B2C.
	 */
	public function testCompanyIsNotB2c()
	{
		$this->assertFalse(FacturelectB2cResolver::isB2c('TE_SMALL', 0));
		$this->assertFalse(FacturelectB2cResolver::isB2c('', 0));
		$this->assertFalse(FacturelectB2cResolver::isB2c(null, null));
	}

	/**
	 * The native Dolibarr "Particulier" nature (TE_PRIVATE) is detected as B2C.
	 */
	public function testNativePrivateTypeIsB2c()
	{
		$this->assertTrue(FacturelectB2cResolver::isB2c('TE_PRIVATE', 0));
	}

	/**
	 * Detection follows the core convention: any code starting with TE_PRIVATE matches.
	 */
	public function testPrivateTypePrefixIsB2c()
	{
		$this->assertTrue(FacturelectB2cResolver::isB2c('TE_PRIVATE_SUBTYPE', 0));
	}

	/**
	 * The explicit thirdparty override checkbox forces B2C even without the native type.
	 */
	public function testExplicitOverrideForcesB2c()
	{
		$this->assertTrue(FacturelectB2cResolver::isB2c('', 1));
		$this->assertTrue(FacturelectB2cResolver::isB2c('TE_SMALL', '1'));
		$this->assertTrue(FacturelectB2cResolver::isB2c(null, true));
	}

	/**
	 * A zero/empty override does not force B2C on a company.
	 */
	public function testEmptyOverrideDoesNotForceB2c()
	{
		$this->assertFalse(FacturelectB2cResolver::isB2c('TE_SMALL', 0));
		$this->assertFalse(FacturelectB2cResolver::isB2c('TE_SMALL', ''));
		$this->assertFalse(FacturelectB2cResolver::isB2c('TE_SMALL', '0'));
	}

	/**
	 * Buyer SIREN must be validated for B2B, ignored for B2C.
	 */
	public function testBuyerSirenErrorOnlyForB2b()
	{
		// B2B, empty SIREN -> invalid (error)
		$this->assertTrue(FacturelectB2cResolver::isBuyerSirenInvalid('', false));
		// B2B, malformed SIREN -> invalid
		$this->assertTrue(FacturelectB2cResolver::isBuyerSirenInvalid('123', false));
		// B2B, valid 9-digit SIREN -> valid
		$this->assertFalse(FacturelectB2cResolver::isBuyerSirenInvalid('853322915', false));
		// B2C, empty SIREN -> never an error
		$this->assertFalse(FacturelectB2cResolver::isBuyerSirenInvalid('', true));
		// B2C, even a malformed SIREN is not flagged as a blocking error
		$this->assertFalse(FacturelectB2cResolver::isBuyerSirenInvalid('123', true));
	}

	/**
	 * SIREN validation trims surrounding whitespace before checking length.
	 */
	public function testBuyerSirenValidationTrimsWhitespace()
	{
		$this->assertFalse(FacturelectB2cResolver::isBuyerSirenInvalid(' 853 322 915 ', false));
	}
}
