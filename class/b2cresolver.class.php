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
 *	\file       htdocs/custom/facturationelectronique/class/b2cresolver.class.php
 *	\ingroup    facturationelectronique
 *	\brief      Decides whether a third party is a private individual (B2C) out of scope of B2B e-invoicing
 */

/**
 * Pure, dependency-free resolver for B2C (private individual) third parties.
 *
 * French e-invoicing (routing an invoice to a buyer SIREN via the PDP/PPF) only applies
 * to B2B transactions. Sales to private individuals (particuliers) have no buyer SIREN and
 * fall under e-reporting instead. Treating a missing buyer SIREN as an error for a B2C
 * customer is therefore a false positive (issue #27).
 *
 * A third party is considered B2C when EITHER:
 *   - its native Dolibarr nature is "Particulier" (typent code TE_PRIVATE — the same test
 *     the core uses in Societe::isProfined via preg_match('/^TE_PRIVATE/', ...)), OR
 *   - the module's explicit "Client particulier (B2C)" checkbox (extrafield
 *     facturelect_b2c) is ticked on the third-party card, for cases where the nature
 *     field is left unset.
 */
class FacturelectB2cResolver
{
	/**
	 * Whether the third party must be treated as a B2C (private individual) customer.
	 *
	 * @param	string	$typent_code	Native Dolibarr entity type code (e.g. 'TE_PRIVATE', 'TE_SMALL')
	 * @param	mixed	$b2c_flag		Explicit override extrafield value (options_facturelect_b2c)
	 * @return	bool					True if the third party is B2C
	 */
	public static function isB2c($typent_code, $b2c_flag)
	{
		if (!empty($b2c_flag)) {
			return true;
		}
		return (is_string($typent_code) && preg_match('/^TE_PRIVATE/', $typent_code) === 1);
	}

	/**
	 * Whether a buyer SIREN must be flagged as an invalid/blocking error.
	 *
	 * For a B2C customer the buyer SIREN is never required, so it is never an error.
	 * For a B2B customer a valid SIREN is exactly 9 digits (whitespace is trimmed first).
	 *
	 * @param	string	$buyer_siren	Raw buyer SIREN as stored on the third party
	 * @param	bool	$is_b2c			Result of self::isB2c() for that third party
	 * @return	bool					True when the SIREN must be reported as an error
	 */
	public static function isBuyerSirenInvalid($buyer_siren, $is_b2c)
	{
		if ($is_b2c) {
			return false;
		}
		$siren = preg_replace('/\s+/', '', (string) $buyer_siren);
		return empty($siren) || strlen($siren) !== 9 || !ctype_digit($siren);
	}
}
