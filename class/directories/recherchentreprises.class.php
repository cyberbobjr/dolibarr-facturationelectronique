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
 *	\file       htdocs/custom/facturationelectronique/class/directories/recherchentreprises.class.php
 *	\ingroup    facturationelectronique
 *	\brief      Company directory backed by the French public API "Recherche d'entreprises" (DINUM)
 */

if (!class_exists('BaseFacturelectDirectory')) {
	require_once dirname(__FILE__) . '/basedirectory.class.php';
}

/**
 * Identification source based on https://recherche-entreprises.api.gouv.fr
 *
 * Open data, no API key, no account, ~7 requests/second per IP. Backed by the INSEE
 * Sirene database plus the RNA (associations), which makes it the most convenient
 * default for a module distributed as a ZIP: it works without any configuration.
 */
class RechercheEntreprisesDirectory extends BaseFacturelectDirectory
{
	/**
	 * @var string API endpoint
	 */
	const API_URL = 'https://recherche-entreprises.api.gouv.fr/search';

	/**
	 * @var int Maximum number of companies requested per query
	 */
	const MAX_RESULTS = 25;

	/**
	 * Legal forms: they carry no discriminating information and badly skew the ranking
	 * ("SARL Dupont Menuiserie" ranks "SARL IDEAL MENUISERIE" first, "Dupont Menuiserie"
	 * ranks the right company first).
	 *
	 * @var string[]
	 */
	private static $legal_forms = array(
		'sarl', 'sarlu', 'sas', 'sasu', 'sa', 'eurl', 'sci', 'scm', 'scp', 'snc', 'scop',
		'scic', 'sem', 'eirl', 'ei', 'gie', 'gaec', 'earl', 'ets', 'etablissement',
		'etablissements', 'association', 'asso', 'cie', 'compagnie', 'groupe', 'societe',
	);

	/**
	 * French grammatical stop words, dropped when the search must be broadened.
	 *
	 * @var string[]
	 */
	private static $stop_words = array(
		'de', 'du', 'des', 'la', 'le', 'les', 'et', 'en', 'au', 'aux', 'a', 'sur',
		'pour', 'par', 'dans', 'chez',
	);

	/**
	 * Words that are long but carry no discriminating power. Length alone is a poor proxy
	 * for distinctiveness: on "Agglomération du Val d'Oise Val Parisis", keeping the longest
	 * token would search "agglomeration" and surface a dissolved EPCI, while dropping it
	 * searches "parisis" and finds the right community first.
	 *
	 * Only used by the last-resort variants, once the literal search has already failed.
	 *
	 * @var string[]
	 */
	private static $generic_words = array(
		'agglomeration', 'communaute', 'commune', 'ville', 'mairie', 'syndicat',
		'intercommunal', 'intercommunale', 'mixte', 'departement', 'region',
		'entreprise', 'entreprises', 'centre', 'service', 'services',
		'france', 'national', 'nationale', 'international', 'internationale',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @return	string
	 */
	public function getCode()
	{
		return 'recherche_entreprises';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return	string
	 */
	public function getLabel()
	{
		return "Annuaire des Entreprises (API gouv.fr)";
	}

	/**
	 * Search companies by name, SIREN or SIRET, optionally filtered by location.
	 *
	 * The API scores the whole query and returns nothing as soon as one term matches no
	 * document, so a single attempt on a Dolibarr third-party name frequently yields zero
	 * result. The search is therefore progressively broadened until something is found,
	 * and the query that actually matched is exposed in $this->used_query.
	 *
	 * @param	string		$name	Company name, SIREN or SIRET
	 * @param	string		$zip	Postcode (5 digits) or department (2-3 chars), optional
	 * @return	array|bool			Array of normalized companies, or false on error
	 */
	public function searchCompanies($name, $zip = '')
	{
		$this->error = '';
		$this->used_query = '';

		$filters = self::buildLocationFilters($zip);

		$identifier = self::extractIdentifier($name);
		if ($identifier !== '') {
			// A raw SIREN/SIRET is unambiguous: query it as-is, without any location filter
			// that could contradict the registered head office.
			$queries = array($identifier);
			$filters = array();
		} else {
			$queries = self::buildQueryVariants($name, !empty($filters));
		}

		if (empty($queries)) {
			$this->error = "Veuillez saisir un nom, un SIREN ou un SIRET.";
			return false;
		}

		foreach ($queries as $query) {
			$params = array_merge(
				array('q' => $query, 'per_page' => self::MAX_RESULTS, 'page' => 1),
				$filters
			);

			$response = $this->httpGetJson(self::API_URL . '?' . http_build_query($params));
			if ($response === false) {
				return false;
			}

			if (!empty($response['results'])) {
				$this->used_query = $query;
				return self::sortActiveFirst(array_map(array($this, 'mapCompany'), $response['results']));
			}
		}

		// Every variant came back empty: not an error, just no match.
		$this->used_query = end($queries);
		return array();
	}

	/**
	 * Build the ordered list of increasingly permissive queries to try.
	 *
	 * @param	string	$name					Raw company name typed by the user
	 * @param	bool	$has_location_filter	True when a postcode/department narrows the search
	 * @return	string[]						Ordered, de-duplicated, non-empty queries
	 */
	public static function buildQueryVariants($name, $has_location_filter = false)
	{
		$normalized = self::normalizeSearchText($name);
		if ($normalized === '') {
			return array();
		}

		$tokens = explode(' ', $normalized);

		// 1. Legal forms are dropped up front, not as a fallback: they never discriminate and
		//    they actively poison the ranking ("SARL Dupont Menuiserie" returns SARL IDEAL
		//    MENUISERIE, "Dupont Menuiserie" returns DUPONT MENUISERIE).
		$base = self::removeTokens($tokens, self::$legal_forms);
		$variants = array(implode(' ', $base));

		// 2. Drop grammatical stop words and single letters ("Val d'Oise" -> "val oise")
		$meaningful = array();
		foreach ($base as $token) {
			if (strlen($token) > 1 && !in_array($token, self::$stop_words, true)) {
				$meaningful[] = $token;
			}
		}
		if (empty($meaningful)) {
			$meaningful = $base;
		}
		$variants[] = implode(' ', $meaningful);

		// 3. Keep only the most distinctive tokens: generic organizational words out, longest first
		$distinctive = self::removeTokens($meaningful, self::$generic_words);
		usort($distinctive, function ($a, $b) {
			return strlen($b) - strlen($a);
		});
		$variants[] = implode(' ', array_slice($distinctive, 0, 3));
		$variants[] = implode(' ', array_slice($distinctive, 0, 2));

		// 4. Last resort: the single most distinctive token. Only meaningful when a
		//    location filter narrows the result set — alone it would return thousands of rows.
		if ($has_location_filter) {
			$variants[] = implode(' ', array_slice($distinctive, 0, 1));
		}

		return array_values(array_unique(array_filter($variants, 'strlen')));
	}

	/**
	 * Push administratively closed companies to the bottom of the list.
	 *
	 * The registry keeps dissolved entities, and they sometimes outrank their successor
	 * (the dissolved EPCI "COMMUNAUTE AGGLOMERATION LE PARISIS" still shares the address of
	 * the active "CA VAL PARISIS"). Associating a closed company would produce an invoice
	 * that cannot be routed, so the active ones must be offered first. Relevance order
	 * inside each group is preserved (usort is stable as of PHP 8.0).
	 *
	 * @param	array	$companies	Normalized companies
	 * @return	array				Same companies, active ones first
	 */
	protected static function sortActiveFirst($companies)
	{
		usort($companies, function ($a, $b) {
			return (int) $b['is_active'] - (int) $a['is_active'];
		});

		return $companies;
	}

	/**
	 * Remove tokens present in a blacklist, keeping the original order and duplicates.
	 * Falls back to the untouched list when the blacklist would empty it.
	 *
	 * @param	string[]	$tokens		Tokens to filter
	 * @param	string[]	$blacklist	Tokens to remove
	 * @return	string[]				Filtered tokens
	 */
	private static function removeTokens($tokens, $blacklist)
	{
		$kept = array();
		foreach ($tokens as $token) {
			if (!in_array($token, $blacklist, true)) {
				$kept[] = $token;
			}
		}

		return empty($kept) ? array_values($tokens) : $kept;
	}

	/**
	 * Translate a postcode input into the right API filter.
	 *
	 * code_postal only accepts a full 5-digit postcode (the API rejects "95" with an
	 * explicit error), so a department is routed to the departement filter instead.
	 *
	 * @param	string	$zip	Raw postcode input
	 * @return	array			Filter parameters (possibly empty)
	 */
	public static function buildLocationFilters($zip)
	{
		$clean = preg_replace('/\s+/', '', (string) $zip);
		if ($clean === '') {
			return array();
		}

		if (preg_match('/^\d{5}$/', $clean)) {
			return array('code_postal' => $clean);
		}
		// 2-digit departments (75), 3-digit overseas ones (971) and Corsica (2A/2B)
		if (preg_match('/^(\d{2,3}|2[ab])$/i', $clean)) {
			return array('departement' => strtoupper($clean));
		}

		return array();
	}

	/**
	 * Map one API result onto the normalized company shape consumed by the modal.
	 *
	 * @param	array	$raw	One entry of the "results" array
	 * @return	array			Normalized company
	 */
	protected function mapCompany($raw)
	{
		$siege = !empty($raw['siege']) && is_array($raw['siege']) ? $raw['siege'] : array();

		$name = !empty($raw['nom_complet']) ? $raw['nom_complet'] : '';
		if ($name === '' && !empty($raw['nom_raison_sociale'])) {
			$name = $raw['nom_raison_sociale'];
		}

		$postcode = !empty($siege['code_postal']) ? $siege['code_postal'] : '';
		$city = !empty($siege['libelle_commune']) ? $siege['libelle_commune'] : '';

		return array(
			'number' => !empty($raw['siren']) ? $raw['siren'] : '',
			'formal_name' => $name,
			'address' => self::buildStreet($siege, $postcode, $city),
			'postcode' => $postcode,
			'city' => $city,
			'siret' => !empty($siege['siret']) ? $siege['siret'] : '',
			'is_active' => (isset($raw['etat_administratif']) && $raw['etat_administratif'] === 'A'),
			'source' => $this->getCode(),
		);
	}

	/**
	 * Rebuild the street line of the head office.
	 *
	 * The flat "adresse" field also contains the postcode and the city, which would be
	 * duplicated once written into Dolibarr — so the structured fields are preferred and
	 * the flat field is only used as a fallback, trimmed of its trailing "postcode city".
	 *
	 * @param	array	$siege		Head office block of the API result
	 * @param	string	$postcode	Resolved postcode
	 * @param	string	$city		Resolved city
	 * @return	string				Street line
	 */
	protected static function buildStreet($siege, $postcode, $city)
	{
		$parts = array();
		foreach (array('numero_voie', 'type_voie', 'libelle_voie') as $key) {
			if (!empty($siege[$key])) {
				$parts[] = trim($siege[$key]);
			}
		}
		if (!empty($parts)) {
			$street = implode(' ', $parts);
			if (!empty($siege['complement_adresse'])) {
				$street = trim($siege['complement_adresse']) . ' ' . $street;
			}
			return $street;
		}

		if (empty($siege['adresse'])) {
			return '';
		}

		$street = trim($siege['adresse']);
		$suffix = trim($postcode . ' ' . $city);
		if ($suffix !== '' && substr($street, -strlen($suffix)) === $suffix) {
			$street = trim(substr($street, 0, -strlen($suffix)));
		}

		return $street;
	}
}
