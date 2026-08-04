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
 *	\file       htdocs/custom/facturationelectronique/class/directories/superpdpdirectory.class.php
 *	\ingroup    facturationelectronique
 *	\brief      Company directory backed by the active PDP provider (French directory endpoint)
 */

if (!class_exists('BaseFacturelectDirectory')) {
	require_once dirname(__FILE__) . '/basedirectory.class.php';
}

/**
 * Identification source based on the /french_directory/companies endpoint of the active
 * PDP provider.
 *
 * Kept as an alternative because it is the very registry the PDP itself uses for routing,
 * but be aware that its name filter is a *prefix* match (formal_name_starts_with): the
 * search string must begin the legal name exactly.
 */
class SuperPdpDirectory extends BaseFacturelectDirectory
{
	/**
	 * @var FacturelectClient|null Lazily built provider gateway
	 */
	private $client;

	/**
	 * Constructor
	 *
	 * @param	DoliDB					$db			Database handler
	 * @param	FacturelectClient|null	$client		Optional pre-built client (used by tests)
	 */
	public function __construct($db, $client = null)
	{
		parent::__construct($db);
		$this->client = $client;
	}

	/**
	 * Get (and memoize) the provider gateway
	 *
	 * @return	FacturelectClient
	 */
	protected function getClient()
	{
		if ($this->client === null) {
			if (!class_exists('FacturelectClient')) {
				require_once dirname(__FILE__) . '/../facturelectclient.class.php';
			}
			$this->client = new FacturelectClient($this->db);
		}

		return $this->client;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return	string
	 */
	public function getCode()
	{
		return 'superpdp';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return	string
	 */
	public function getLabel()
	{
		return "Annuaire " . $this->getClient()->getProviderName();
	}

	/**
	 * Search companies through the provider French directory.
	 *
	 * @param	string		$name	Company name prefix, or SIREN
	 * @param	string		$zip	Postcode prefix, optional
	 * @return	array|bool			Array of normalized companies, or false on error
	 */
	public function searchCompanies($name, $zip = '')
	{
		$this->error = '';
		$this->used_query = trim((string) $name);

		$client = $this->getClient();

		$identifier = self::extractIdentifier($name);
		if ($identifier !== '') {
			// A raw SIREN/SIRET goes through the dedicated number lookup
			$this->used_query = substr($identifier, 0, 9);
			$res = $client->searchCompany($this->used_query);
		} else {
			$res = $client->searchCompaniesList($this->used_query, preg_replace('/\s+/', '', (string) $zip));
		}

		if ($res === false) {
			$this->error = $client->error;
			return false;
		}

		$rows = !empty($res['data']) && is_array($res['data']) ? $res['data'] : array();

		return array_map(array($this, 'mapCompany'), $rows);
	}

	/**
	 * Map one provider result onto the normalized company shape consumed by the modal.
	 *
	 * @param	array	$raw	One entry of the provider "data" array
	 * @return	array			Normalized company
	 */
	protected function mapCompany($raw)
	{
		return array(
			'number' => isset($raw['number']) ? $raw['number'] : '',
			'formal_name' => isset($raw['formal_name']) ? $raw['formal_name'] : '',
			'address' => isset($raw['address']) ? $raw['address'] : '',
			'postcode' => isset($raw['postcode']) ? $raw['postcode'] : '',
			'city' => isset($raw['city']) ? $raw['city'] : '',
			'siret' => isset($raw['siret']) ? $raw['siret'] : '',
			// The provider directory only exposes companies it can route to, so absence of
			// the flag is treated as active.
			'is_active' => isset($raw['is_active']) ? (bool) $raw['is_active'] : true,
			'source' => $this->getCode(),
		);
	}
}
