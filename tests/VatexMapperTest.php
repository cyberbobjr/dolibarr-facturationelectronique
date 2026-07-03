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

require_once dirname(__DIR__) . '/class/vatexmapper.class.php';

/**
 * Unit tests for the VATEX exemption reason mapper (EN16931 BT-120 / BT-121).
 */
class VatexMapperTest extends TestCase
{
	/**
	 * Standard-rated (S) and zero-rated (Z) supplies never carry an exemption reason.
	 */
	public function testStandardAndZeroRatedAreNotExempt()
	{
		$this->assertFalse(VatexMapper::isExemptCategory('S'));
		$this->assertFalse(VatexMapper::isExemptCategory('Z'));
		$this->assertNull(VatexMapper::getDefaultExemption('S'));
		$this->assertNull(VatexMapper::getDefaultExemption('Z'));
	}

	/**
	 * Export outside EU (G) maps to VATEX-EU-G with a French legal reason.
	 */
	public function testExportOutsideEuMapsToVatexEuG()
	{
		$this->assertTrue(VatexMapper::isExemptCategory('G'));
		$res = VatexMapper::getDefaultExemption('G');
		$this->assertSame('VATEX-EU-G', $res['reason_code']);
		$this->assertNotEmpty($res['reason']);
		$this->assertStringContainsString('262', $res['reason']);
	}

	/**
	 * Intra-community supply (K) maps to VATEX-EU-IC.
	 */
	public function testIntraCommunityMapsToVatexEuIc()
	{
		$res = VatexMapper::getDefaultExemption('K');
		$this->assertSame('VATEX-EU-IC', $res['reason_code']);
		$this->assertStringContainsString('262 ter', $res['reason']);
	}

	/**
	 * Reverse charge (AE) maps to VATEX-EU-AE.
	 */
	public function testReverseChargeMapsToVatexEuAe()
	{
		$res = VatexMapper::getDefaultExemption('AE');
		$this->assertSame('VATEX-EU-AE', $res['reason_code']);
		$this->assertStringContainsString('283', $res['reason']);
	}

	/**
	 * Out-of-scope (O) maps to VATEX-EU-O.
	 */
	public function testOutOfScopeMapsToVatexEuO()
	{
		$res = VatexMapper::getDefaultExemption('O');
		$this->assertSame('VATEX-EU-O', $res['reason_code']);
		$this->assertNotEmpty($res['reason']);
	}

	/**
	 * Generic exempt (E) defaults to the most common French case: franchise en base (293 B).
	 */
	public function testExemptDefaultsToFrenchFranchise()
	{
		$res = VatexMapper::getDefaultExemption('E');
		$this->assertSame('VATEX-FR-FRANCHISE', $res['reason_code']);
		$this->assertStringContainsString('293 B', $res['reason']);
	}

	/**
	 * Unknown / unmapped categories return null rather than a fake code.
	 */
	public function testUnknownCategoryReturnsNull()
	{
		$this->assertNull(VatexMapper::getDefaultExemption('X'));
		$this->assertNull(VatexMapper::getDefaultExemption(''));
		$this->assertFalse(VatexMapper::isExemptCategory('L'));
	}

	/**
	 * Lower-case / padded input is normalised before lookup.
	 */
	public function testInputIsNormalised()
	{
		$res = VatexMapper::getDefaultExemption(' g ');
		$this->assertSame('VATEX-EU-G', $res['reason_code']);
		$this->assertTrue(VatexMapper::isExemptCategory('ae'));
	}

	/**
	 * The curated VATEX code list contains the common French B2B codes and is well-formed.
	 */
	public function testCodeLabelsContainCommonCodes()
	{
		$labels = VatexMapper::CODE_LABELS;
		$this->assertArrayHasKey('VATEX-EU-G', $labels);
		$this->assertArrayHasKey('VATEX-EU-IC', $labels);
		$this->assertArrayHasKey('VATEX-EU-AE', $labels);
		$this->assertArrayHasKey('VATEX-EU-O', $labels);
		$this->assertArrayHasKey('VATEX-FR-FRANCHISE', $labels);
		$this->assertArrayHasKey('VATEX-EU-132-1C', $labels);
		foreach ($labels as $code => $desc) {
			$this->assertStringStartsWith('VATEX-', $code);
			$this->assertNotEmpty($desc);
		}
	}

	/**
	 * Every automatic category default must reference a code that exists in the select list,
	 * so users never see an auto code they cannot also pick manually.
	 */
	public function testEveryDefaultCodeIsInTheList()
	{
		foreach (array('AE', 'G', 'K', 'O', 'E') as $cat) {
			$code = VatexMapper::getDefaultExemption($cat)['reason_code'];
			$this->assertArrayHasKey($code, VatexMapper::CODE_LABELS, "Default code $code for category $cat missing from CODE_LABELS");
		}
	}

	/**
	 * selectOptions() returns a code => "CODE - description" map suitable for an extrafield param.
	 */
	public function testSelectOptionsIncludeCodeInLabel()
	{
		$opts = VatexMapper::selectOptions();
		$this->assertArrayHasKey('VATEX-EU-G', $opts);
		$this->assertStringContainsString('VATEX-EU-G', $opts['VATEX-EU-G']);
		$this->assertStringContainsString('Export', $opts['VATEX-EU-G']);
	}

	/**
	 * labelForCode() returns the plain description (usable as BT-120 reason text) or null.
	 */
	public function testLabelForCode()
	{
		$this->assertStringContainsString('intracommunautaire', VatexMapper::labelForCode('VATEX-EU-IC'));
		$this->assertNull(VatexMapper::labelForCode('VATEX-UNKNOWN-XYZ'));
		$this->assertNull(VatexMapper::labelForCode(''));
	}
}
