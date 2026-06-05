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
 *	\file       htdocs/custom/facturationelectronique/class/facturelectproviderfactory.class.php
 *	\ingroup    facturationelectronique
 *	\brief      Factory class for Facturation Electronique Providers
 */

class FacturelectProviderFactory
{
	/**
	 * Get the active provider instance
	 *
	 * @param	DoliDB	$db		Database handler
	 * @return	FacturelectProvider
	 */
	public static function getProvider($db)
	{
		$active = getDolGlobalString('FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER', 'superpdp');
		if ($active === 'factpulse') {
			require_once dirname(__FILE__) . '/providers/factpulse.class.php';
			return new FactPulseProvider($db);
		}

		// Fallback to SuperPDP
		require_once dirname(__FILE__) . '/providers/superpdp.class.php';
		return new SuperPdpProvider($db);
	}
}
