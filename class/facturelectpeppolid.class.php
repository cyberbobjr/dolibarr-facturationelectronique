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
 *	\file       htdocs/custom/facturationelectronique/class/facturelectpeppolid.class.php
 *	\ingroup    facturationelectronique
 *	\brief      Parser for PEPPOL electronic address identifiers
 */

/**
 * Splits a PEPPOL routing identifier into its legal components.
 *
 * The routing address and the legal business identifier must never be confused
 * (see AGENTS.md #16): the full address is what the network routes on, while only the
 * bare 9-digit SIREN may be written to llx_societe.siren and sent as the Factur-X
 * legal_registration_identifier.
 *
 * Two syntaxes are supported:
 *   SIREN[_SIRET][_SERVICE...]   current SuperPDP form, e.g. 200058485_20005848500018_RH
 *   SIREN*NIC                    legacy form, e.g. 853322915*00012
 *
 * Parsing is unambiguous despite the service code being allowed to contain underscores,
 * because SIREN and SIRET are fixed-length numeric values: once the first two blocks are
 * recognised by their length, everything left over is the service code.
 */
class FacturelectPeppolId
{
	/**
	 * @var string Scheme used for French SIREN-based addresses
	 */
	const DEFAULT_SCHEME = '0225';

	/**
	 * Parse a PEPPOL identifier.
	 *
	 * Never guesses: any component that cannot be validated is returned empty, so a caller
	 * writing to the database can simply skip the empty ones instead of storing garbage.
	 *
	 * @param	string	$raw				Identifier, with or without its "scheme:" prefix
	 * @param	string	$fallback_scheme	Scheme to use when the identifier carries none
	 * @return	array						scheme, identifier (routing value), siren, siret, service
	 */
	public static function parse($raw, $fallback_scheme = '')
	{
		$raw = trim((string) $raw);
		$scheme = $fallback_scheme !== '' ? $fallback_scheme : self::DEFAULT_SCHEME;

		// A scheme prefix is separated by the first colon; the routing value may not contain one
		if (strpos($raw, ':') !== false) {
			list($scheme, $raw) = explode(':', $raw, 2);
			$raw = trim($raw);
			$scheme = trim($scheme);
		}

		$result = array(
			'scheme' => $scheme,
			'identifier' => $raw,
			'siren' => '',
			'siret' => '',
			'service' => '',
		);

		if ($raw === '') {
			return $result;
		}

		if (strpos($raw, '*') !== false) {
			return array_merge($result, self::parseStarForm($raw));
		}

		return array_merge($result, self::parseUnderscoreForm($raw));
	}

	/**
	 * Parse the legacy SIREN*NIC syntax.
	 *
	 * @param	string	$raw	Routing value without its scheme
	 * @return	array			siren / siret / service fragment
	 */
	private static function parseStarForm($raw)
	{
		list($siren, $nic) = array_pad(explode('*', $raw, 2), 2, '');

		if (!self::isSiren($siren)) {
			return array();
		}

		$nic = preg_replace('/\D/', '', $nic);
		if ($nic === '' || strlen($nic) > 5) {
			return array('siren' => $siren);
		}

		return array(
			'siren' => $siren,
			'siret' => $siren . str_pad($nic, 5, '0', STR_PAD_LEFT),
		);
	}

	/**
	 * Parse the SIREN[_SIRET][_SERVICE...] syntax.
	 *
	 * @param	string	$raw	Routing value without its scheme
	 * @return	array			siren / siret / service
	 */
	private static function parseUnderscoreForm($raw)
	{
		$parts = explode('_', $raw);
		$head = array_shift($parts);

		// A bare SIRET carries its own SIREN in the first 9 digits
		if (empty($parts) && self::isSiret($head)) {
			return array('siren' => substr($head, 0, 9), 'siret' => $head);
		}

		if (!self::isSiren($head)) {
			return array();
		}

		$parsed = array('siren' => $head);

		// The second block is the establishment only when it is a SIRET of that very SIREN.
		// Anything else (a service code, or an inconsistent SIRET) stays in the service part.
		if (!empty($parts) && self::isSiret($parts[0]) && strpos($parts[0], $head) === 0) {
			$parsed['siret'] = array_shift($parts);
		}

		if (!empty($parts)) {
			$parsed['service'] = implode('_', $parts);
		}

		return $parsed;
	}

	/**
	 * Human readable description of what an address routes to.
	 *
	 * @param	array	$parsed		Output of parse()
	 * @return	string				Label for the establishment column
	 */
	public static function describe($parsed)
	{
		if (!empty($parsed['siret'])) {
			$label = 'SIRET ' . $parsed['siret'];
		} elseif (!empty($parsed['siren'])) {
			$label = "SIREN seul (toute l'entreprise)";
		} else {
			$label = 'Adresse de routage';
		}

		if (!empty($parsed['service'])) {
			$label .= ' — service ' . $parsed['service'];
		}

		return $label;
	}

	/**
	 * @param	string	$value	Candidate
	 * @return	bool			True for a 9-digit SIREN
	 */
	public static function isSiren($value)
	{
		return (bool) preg_match('/^\d{9}$/', (string) $value);
	}

	/**
	 * @param	string	$value	Candidate
	 * @return	bool			True for a 14-digit SIRET
	 */
	public static function isSiret($value)
	{
		return (bool) preg_match('/^\d{14}$/', (string) $value);
	}
}
