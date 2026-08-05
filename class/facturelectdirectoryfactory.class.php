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
 *	\file       htdocs/custom/facturationelectronique/class/facturelectdirectoryfactory.class.php
 *	\ingroup    facturationelectronique
 *	\brief      Factory for company identification directories (SIREN lookup sources)
 */

class FacturelectDirectoryFactory
{
	/**
	 * @var string Source used when nothing is configured. The public API needs no key and
	 *             does full-text search, so it works out of the box on every installation.
	 */
	const DEFAULT_SOURCE = 'recherche_entreprises';

	/**
	 * @var string Dolibarr constant holding the installation-wide default source
	 */
	const SETTING_NAME = 'FACTURELECT_SIREN_SOURCE';

	/**
	 * List the selectable sources
	 *
	 * @return	array	Map of technical code => human readable label
	 */
	public static function getAvailableSources()
	{
		return array(
			'recherche_entreprises' => "Annuaire des Entreprises (API gouv.fr)",
			'superpdp' => "Annuaire du PDP (SuperPDP)",
		);
	}

	/**
	 * Get the configured default source code
	 *
	 * @return	string
	 */
	public static function getDefaultCode()
	{
		$code = getDolGlobalString(self::SETTING_NAME, self::DEFAULT_SOURCE);

		return array_key_exists($code, self::getAvailableSources()) ? $code : self::DEFAULT_SOURCE;
	}

	/**
	 * Instantiate a directory
	 *
	 * An unknown or empty code silently falls back to the configured default, so a stale
	 * bookmark or a truncated URL parameter can never break the lookup modal.
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	string	$code	Requested source code, empty for the configured default
	 * @return	FacturelectDirectory
	 */
	public static function getDirectory($db, $code = '')
	{
		$sources = self::getAvailableSources();
		if (empty($code) || !array_key_exists($code, $sources)) {
			$code = self::getDefaultCode();
		}

		if ($code === 'superpdp') {
			require_once dirname(__FILE__) . '/directories/superpdpdirectory.class.php';
			return new SuperPdpDirectory($db);
		}

		require_once dirname(__FILE__) . '/directories/recherchentreprises.class.php';
		return new RechercheEntreprisesDirectory($db);
	}
}
