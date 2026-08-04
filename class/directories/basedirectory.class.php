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
 *	\file       htdocs/custom/facturationelectronique/class/directories/basedirectory.class.php
 *	\ingroup    facturationelectronique
 *	\brief      Base abstract class for company identification directories
 */

if (!interface_exists('FacturelectDirectory')) {
	require_once dirname(__FILE__) . '/facturelectdirectory.interface.php';
}

abstract class BaseFacturelectDirectory implements FacturelectDirectory
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error message
	 */
	public $error = '';

	/**
	 * @var string Query actually sent to the directory (may differ from the user input
	 *             when the search had to be broadened). Shown back to the user.
	 */
	public $used_query = '';

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Perform a GET request and decode the JSON body.
	 *
	 * Delegates to Dolibarr's native getURLContent() so that MAIN_PROXY_* settings and
	 * the SSL policy of the installation are honoured. Overridden in unit tests.
	 *
	 * @param	string		$url	Absolute URL including the query string
	 * @return	array|bool			Decoded JSON as an array, or false on error
	 */
	protected function httpGetJson($url)
	{
		if (!function_exists('getURLContent')) {
			require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';
		}

		$res = getURLContent($url, 'GET', '', 1, array('Accept: application/json'), array('https'), 0);

		if (!empty($res['curl_error_msg'])) {
			$this->error = "Erreur réseau lors de l'appel à " . $this->getLabel() . " : " . $res['curl_error_msg'];
			return false;
		}

		$http_code = isset($res['http_code']) ? (int) $res['http_code'] : 0;
		if ($http_code < 200 || $http_code >= 300) {
			$this->error = $this->getLabel() . " a répondu avec le code HTTP " . $http_code . ' : ' . substr((string) $res['content'], 0, 300);
			return false;
		}

		$decoded = json_decode((string) $res['content'], true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->error = "Réponse illisible de " . $this->getLabel() . " : " . json_last_error_msg();
			return false;
		}

		return $decoded;
	}

	/**
	 * Normalize a free-text search input: strip accents and punctuation, collapse spaces.
	 *
	 * Implemented with an explicit translation table rather than iconv()/intl so the
	 * behaviour is identical on every PHP build (and testable without Dolibarr loaded).
	 *
	 * @param	string	$text	Raw user input
	 * @return	string			Normalized upper-case-insensitive text
	 */
	public static function normalizeSearchText($text)
	{
		$map = array(
			'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
			'ç' => 'c',
			'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
			'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
			'ñ' => 'n',
			'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
			'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
			'ý' => 'y', 'ÿ' => 'y',
			'œ' => 'oe', 'æ' => 'ae',
		);

		$text = (string) $text;
		$text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
		$text = strtr($text, $map);

		// Everything that is not a letter or a digit becomes a separator (apostrophes,
		// dashes, dots in "S.A.R.L.", etc.)
		$text = preg_replace('/[^a-z0-9]+/', ' ', $text);

		return trim(preg_replace('/\s+/', ' ', $text));
	}

	/**
	 * Extract the digits of an input and return it when it is a valid SIREN or SIRET.
	 *
	 * @param	string	$text	Raw user input
	 * @return	string			9 or 14 digit identifier, or '' when the input is not one
	 */
	public static function extractIdentifier($text)
	{
		$digits = preg_replace('/\D/', '', (string) $text);
		if ($digits === '') {
			return '';
		}
		// Only treat the input as an identifier when it contains nothing but digits and
		// separators — "SARL 2000" must stay a name search.
		if (preg_replace('/[\s.\-]/', '', (string) $text) !== $digits) {
			return '';
		}

		return (strlen($digits) === 9 || strlen($digits) === 14) ? $digits : '';
	}
}
