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

/**
 *	\file       htdocs/custom/facturationelectronique/class/vatexmapper.class.php
 *	\ingroup    facturationelectronique
 *	\brief      Maps EN16931 VAT category codes to their default VATEX exemption reason (BT-120 / BT-121)
 */

/**
 * Pure, dependency-free mapper from an EN16931 VAT category code (BT-95 / BT-118 / BT-151)
 * to a default VAT exemption reason code (BT-121, CEF VATEX code list) and reason text (BT-120).
 *
 * When a VAT breakdown (BG-23) carries a category other than S (standard) or Z (zero-rated),
 * rules BR-E-10 / BR-G-10 / BR-IC-10 / BR-O-10 / BR-AE-10 REQUIRE an exemption reason.
 * Omitting it — or sending a code outside the VATEX list — makes the PDP reject the invoice
 * with "Value of <ram:ExemptionReasonCode> is not allowed".
 *
 * The reason texts default to the standard French legal wording (CGI articles). Callers may
 * override both the code and the text per invoice or per third party (see ActionsFacturationelectronique).
 */
class VatexMapper
{
	/**
	 * Default exemption per VAT category code.
	 * Format: category code => array(BT-121 VATEX reason code, BT-120 reason text).
	 * Only exempt / out-of-scope categories are listed; S and Z are intentionally absent.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	const DEFAULTS = array(
		'AE' => array('VATEX-EU-AE', 'Autoliquidation - Article 283-2 du CGI'),
		'G'  => array('VATEX-EU-G', 'Exonération de TVA - Exportation hors UE (article 262 I du CGI)'),
		'K'  => array('VATEX-EU-IC', 'Exonération de TVA - Livraison intracommunautaire (article 262 ter I du CGI)'),
		'O'  => array('VATEX-EU-O', 'Opération hors du champ d\'application de la TVA'),
		'E'  => array('VATEX-FR-FRANCHISE', 'TVA non applicable, article 293 B du CGI (franchise en base)'),
	);

	/**
	 * Curated CEF VATEX code list (BT-121) with plain French descriptions (BT-120 reason text).
	 * Ordered most-common-first. Covers the frequent French B2B cases plus the article-132
	 * exemptions of general interest (medical, education, non-profit…). Descriptions are ASCII
	 * to match the module's existing select extrafields and avoid any encoding surprise.
	 *
	 * @var array<string, string>
	 */
	const CODE_LABELS = array(
		'VATEX-EU-AE'        => 'Autoliquidation (reverse charge)',
		'VATEX-EU-G'         => 'Exportation hors UE',
		'VATEX-EU-IC'        => 'Livraison intracommunautaire',
		'VATEX-EU-O'         => 'Hors champ de la TVA',
		'VATEX-FR-FRANCHISE' => 'Franchise en base (art. 293 B CGI)',
		'VATEX-FR-CNWVAT'    => 'Client non etabli - pas de TVA',
		'VATEX-EU-79-C'      => 'Exoneration art. 79 c) directive TVA',
		'VATEX-EU-132'       => 'Exoneration art. 132 (interet general)',
		'VATEX-EU-132-1A'    => 'Services postaux publics (132-1-a)',
		'VATEX-EU-132-1B'    => 'Hospitalisation et soins medicaux (132-1-b)',
		'VATEX-EU-132-1C'    => 'Soins a la personne - professions medicales (132-1-c)',
		'VATEX-EU-132-1D'    => 'Sang, organes et lait humains (132-1-d)',
		'VATEX-EU-132-1E'    => 'Protheses dentaires (132-1-e)',
		'VATEX-EU-132-1F'    => 'Groupements autonomes de personnes (132-1-f)',
		'VATEX-EU-132-1G'    => 'Aide sociale et securite sociale (132-1-g)',
		'VATEX-EU-132-1H'    => 'Protection de l enfance et de la jeunesse (132-1-h)',
		'VATEX-EU-132-1I'    => 'Enseignement (132-1-i)',
		'VATEX-EU-132-1J'    => 'Lecons donnees a titre personnel (132-1-j)',
		'VATEX-EU-132-1K'    => 'Mise a disposition de personnel (132-1-k)',
		'VATEX-EU-132-1L'    => 'Organismes sans but lucratif (132-1-l)',
		'VATEX-EU-132-1M'    => 'Sport et education physique (132-1-m)',
		'VATEX-EU-132-1N'    => 'Services culturels (132-1-n)',
		'VATEX-EU-132-1O'    => 'Manifestations de collecte de fonds (132-1-o)',
		'VATEX-EU-132-1P'    => 'Transport de malades ou blesses (132-1-p)',
		'VATEX-EU-132-1Q'    => 'Radiodiffusion et television publiques (132-1-q)',
		'VATEX-EU-143'       => 'Exoneration art. 143 (importations/exportations)',
		'VATEX-EU-148'       => 'Exoneration art. 148 (navigation maritime et aerienne)',
		'VATEX-EU-151'       => 'Exoneration art. 151 (diplomatique/organismes int.)',
		'VATEX-EU-159'       => 'Exoneration art. 159',
		'VATEX-EU-309'       => 'Exoneration art. 309 (agences de voyages)',
		'VATEX-EU-D'         => 'Acquisition intracommunautaire - moyens de transport (D)',
		'VATEX-EU-F'         => 'Acquisition intracommunautaire - biens d occasion (F)',
		'VATEX-EU-I'         => 'Acquisition intracommunautaire - objets d art (I)',
	);

	/**
	 * Build the option map for a Dolibarr 'select' extrafield: code => "CODE - description".
	 * The stored value stays the raw VATEX code; the label shows both for clarity.
	 *
	 * @return array<string, string>
	 */
	public static function selectOptions()
	{
		$options = array();
		foreach (self::CODE_LABELS as $code => $desc) {
			$options[$code] = $code . ' - ' . $desc;
		}
		return $options;
	}

	/**
	 * Return the plain description (BT-120 reason text) for a VATEX code, or null if unknown.
	 *
	 * @param  string $code  A VATEX reason code
	 * @return string|null
	 */
	public static function labelForCode($code)
	{
		$code = strtoupper(trim((string) $code));
		return isset(self::CODE_LABELS[$code]) ? self::CODE_LABELS[$code] : null;
	}

	/**
	 * Normalise a raw category code (trim + upper-case) for lookup.
	 *
	 * @param  string $cat_code  Raw VAT category code
	 * @return string            Normalised code
	 */
	private static function normalise($cat_code)
	{
		return strtoupper(trim((string) $cat_code));
	}

	/**
	 * Tell whether a VAT category code requires an exemption reason (BT-120 / BT-121).
	 *
	 * @param  string $cat_code  EN16931 VAT category code (S, E, G, K, O, AE, Z…)
	 * @return bool              True when the category is exempt / out-of-scope / reverse-charged
	 */
	public static function isExemptCategory($cat_code)
	{
		return isset(self::DEFAULTS[self::normalise($cat_code)]);
	}

	/**
	 * Return the default exemption reason for a VAT category code.
	 *
	 * @param  string $cat_code  EN16931 VAT category code
	 * @return array|null        array('reason_code' => BT-121, 'reason' => BT-120) or null when
	 *                           the category needs no exemption reason (S, Z, unknown…)
	 */
	public static function getDefaultExemption($cat_code)
	{
		$code = self::normalise($cat_code);
		if (!isset(self::DEFAULTS[$code])) {
			return null;
		}
		return array(
			'reason_code' => self::DEFAULTS[$code][0],
			'reason'      => self::DEFAULTS[$code][1],
		);
	}
}
