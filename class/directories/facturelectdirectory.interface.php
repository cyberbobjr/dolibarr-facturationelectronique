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
 *	\file       htdocs/custom/facturationelectronique/class/directories/facturelectdirectory.interface.php
 *	\ingroup    facturationelectronique
 *	\brief      Interface for company identification directories (SIREN lookup sources)
 */

/**
 * A directory is an *identification* source only: it resolves a company name into
 * SIREN / SIRET / legal name / address.
 *
 * It never resolves PEPPOL routing addresses: the list of active electronic
 * addresses (entries) is network-specific and stays the responsibility of the
 * active PDP provider (see FacturelectClient::getCompanyEntries()).
 */
interface FacturelectDirectory
{
	/**
	 * Search companies by name (or by raw SIREN/SIRET) and optional postcode
	 *
	 * Returned companies MUST use the normalized shape consumed by the lookup modal:
	 *   number      string  9-digit SIREN
	 *   formal_name string  Legal name
	 *   address     string  Street only (no postcode, no city)
	 *   postcode    string  5-digit postcode
	 *   city        string  City name
	 *   siret       string  14-digit SIRET of the head office ('' if unknown)
	 *   is_active   bool    Administratively active company
	 *   source      string  Directory code that produced the row
	 *
	 * @param	string		$name	Company name, SIREN or SIRET
	 * @param	string		$zip	Postcode (5 digits) or department (2-3 chars), optional
	 * @return	array|bool			Array of normalized companies, or false on error
	 */
	public function searchCompanies($name, $zip = '');

	/**
	 * Technical code of the directory, used in URLs and stored settings
	 *
	 * @return	string
	 */
	public function getCode();

	/**
	 * Human readable label shown in the source selector
	 *
	 * @return	string
	 */
	public function getLabel();
}
