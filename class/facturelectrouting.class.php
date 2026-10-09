<?php
/* Copyright (C) 2026 Benjamin Marchand */

require_once __DIR__.'/facturelectdiagnostic.class.php';
require_once __DIR__.'/b2cresolver.class.php';

/** Buyer routing validation, with directory responses cached only for this request. */
class FacturelectRouting
{
	/** @var array Directory responses keyed by SIREN */
	private $responses = array();

	/**
	 * Normalize comparisons without changing the address sent to the provider.
	 *
	 * @param string $identifier Routing value, optionally prefixed by its scheme
	 * @param string $scheme Fallback scheme
	 * @return string Comparable scheme and identifier
	 */
	public static function normalize($identifier, $scheme = '0225')
	{
		$parsed = FacturelectPeppolId::parse(strtoupper(preg_replace('/\s+/u', '', (string) $identifier)), trim($scheme));
		$value = $parsed['identifier'];
		if (preg_match('/^(\d{9})\*(\d{5})$/', $value, $match)) {
			$value = $match[1].'_'.$match[1].$match[2];
		}
		return strtoupper(preg_replace('/\s+/u', '', $parsed['scheme'].':'.$value));
	}

	/**
	 * Evaluate a directory snapshot; message is a translation key with separate arguments.
	 *
	 * @param string $identifier Effective buyer identifier
	 * @param string $scheme Effective buyer scheme
	 * @param string $siren Legal buyer SIREN, used for a missing identifier
	 * @param array $entries Raw directory rows
	 * @return array Structured verdict
	 */
	public static function evaluate($identifier, $scheme, $siren, $entries)
	{
		$compared = self::normalize($identifier !== '' ? $identifier : $siren, $scheme);
		$active = array();
		$identifiers = array();
		$matched = false;
		$inactive = false;
		foreach ($entries as $entry) {
			$id = self::normalize($entry['identifier'] ?? '', $entry['scheme'] ?? '0225');
			$identifiers[] = $id;
			// Technical return addresses are not buyer reception addresses (existing modal policy).
			$is_active = ($entry['is_active'] ?? null) === true && !preg_match('/_REPLYTO$/', $id);
			if ($is_active) {
				$active[] = $id;
			}
			$matched = $matched || ($id === $compared && $is_active);
			$inactive = $inactive || ($id === $compared && !$is_active);
		}
		$status = $matched ? 'ok' : ($inactive ? 'inactive' : (empty($entries) ? 'empty' : (empty($active) ? 'not_ready' : ($compared === self::normalize($siren, $scheme) ? 'unassociated' : 'missing'))));
		return array('status' => $status, 'blocking' => !$matched, 'message' => 'FacturelectRouting'.ucfirst($status),
			'address' => $compared, 'active_addresses' => array_values(array_unique($active)), 'compared_addresses' => $identifiers, 'error' => '');
	}

	/**
	 * Apply environment policy and fetch a directory snapshot, failing open on API errors.
	 *
	 * @param object $thirdparty Loaded buyer including extrafields
	 * @param object $client Provider client
	 * @param object|null $invoice Invoice with an optional buyer address override
	 * @return array Structured verdict
	 */
	public function check($thirdparty, $client, $invoice = null)
	{
		$mode = getDolGlobalString('FACTURATION_ELECTRONIQUE_ROUTING_CHECK_MODE', 'block');
		$verdict = array('status' => 'skipped', 'blocking' => false, 'message' => '', 'active_addresses' => array(), 'compared_addresses' => array(), 'address' => '', 'error' => '');
		if ($mode === 'off' || getDolGlobalString('FACTURATION_ELECTRONIQUE_MODE') !== 'production'
			|| getDolGlobalString('FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER', 'superpdp') !== 'superpdp'
			|| FacturelectB2cResolver::isB2c($thirdparty->typent_code ?? '', $thirdparty->array_options['options_facturelect_b2c'] ?? null)) {
			return $verdict;
		}
		$routing = FacturelectDiagnostic::routing($thirdparty, $invoice);
		$siren = $routing['siren'];
		if (!isset($this->responses[$siren])) {
			try {
				$response = $client->getCompanyEntries($siren);
				$error = $response === false ? $client->error : '';
			} catch (Throwable $e) {
				$response = false;
				$error = $e->getMessage();
			}
			$this->responses[$siren] = array('response' => $response, 'error' => $error);
		}
		$cached = $this->responses[$siren];
		$response = $cached['response'];
		if (!is_array($response) || !isset($response['data']) || !is_array($response['data'])) {
			$verdict['status'] = 'error';
			$verdict['message'] = 'FacturelectRoutingError';
			$verdict['error'] = $cached['error'];
			$verdict['address'] = self::normalize($routing['identifier'], $routing['scheme']);
			return $verdict;
		}
		$verdict = self::evaluate($routing['identifier'], $routing['scheme'], $siren, $response['data']);
		if ($verdict['status'] === 'unassociated' && $routing['source'] !== 'siren') {
			$verdict['status'] = 'missing';
			$verdict['message'] = 'FacturelectRoutingMissing';
		}
		if ($mode === 'warn') {
			$verdict['blocking'] = false;
		}
		return $verdict;
	}

	/**
	 * Keep public entry lookup available to invoice editors without granting company writes.
	 *
	 * @param string $action Directory endpoint action
	 * @param object $user Dolibarr user and native rights
	 * @return bool Whether the endpoint's initial read gate is satisfied
	 */
	public static function canReadDirectory($action, $user)
	{
		return !empty($user->admin) || !empty($user->rights->societe->lire)
			|| ($action === 'get_entries' && empty($user->socid)
				&& !empty($user->rights->facture->lire) && !empty($user->rights->facture->creer)
				&& getDolGlobalInt('FACTURELECT_FEATURE_EINVOICING', 1));
	}

	/**
	 * Save a per-invoice address only after checking that the submitted choice is active.
	 * Clearing the override restores the third-party default without a directory call.
	 * Authorization and CSRF are enforced by the invoice page before calling this method.
	 *
	 * @param object $invoice Authorized customer invoice with loaded buyer
	 * @param string $address Full address selected in the directory, or empty for the default
	 * @param object $client Directory client
	 * @return array Success flag and translation key
	 */
	public static function saveBuyerAddress($invoice, $address, $client)
	{
		$validated = self::validateAddress($invoice->thirdparty, $address, $client);
		if (!$validated['success']) { return $validated; }
		$address = $validated['address'];
		$previous = $invoice->array_options['options_facturelect_buyer_address'] ?? '';
		$invoice->array_options['options_facturelect_buyer_address'] = $address;
		if ($invoice->updateExtraField('facturelect_buyer_address') < 0) {
			$invoice->array_options['options_facturelect_buyer_address'] = $previous;
			return array('success' => false, 'message' => 'FacturelectBuyerAddressSaveError');
		}
		return array('success' => true, 'message' => 'FacturelectBuyerAddressSaved');
	}

	/**
	 * Validate a user-selected address against active entries; empty clears the choice.
	 *
	 * @param object $thirdparty Buyer with loaded extrafields
	 * @param string $address Selected full address
	 * @param object $client Directory client
	 * @return array Validation result and canonical address on success
	 */
	public static function validateAddress($thirdparty, $address, $client)
	{
		$address = trim((string) $address);
		if ($address !== '') {
			try {
				$response = $client->getCompanyEntries(FacturelectDiagnostic::routing($thirdparty)['siren']);
			} catch (Throwable $e) {
				$response = false;
			}
			if (!is_array($response) || !isset($response['data']) || !is_array($response['data'])) {
				return array('success' => false, 'message' => 'FacturelectBuyerAddressDirectoryError');
			}
			$verdict = self::evaluate($address, '0225', FacturelectDiagnostic::routing($thirdparty)['siren'], $response['data']);
			if ($verdict['blocking'] || strlen($address) > 255) {
				return array('success' => false, 'message' => 'FacturelectBuyerAddressInvalid');
			}
			$parsed = FacturelectPeppolId::parse($address);
			$address = $parsed['scheme'].':'.$parsed['identifier'];
		}
		return array('success' => true, 'address' => $address);
	}

	/**
	 * Save the third-party default without changing its legal identifiers.
	 * Authorization and CSRF are enforced by the third-party card.
	 *
	 * @param object $thirdparty Authorized third party
	 * @param string $address Selected full address, or empty to clear
	 * @param object $client Directory client
	 * @return array Success flag and translation key
	 */
	public static function saveThirdpartyAddress($thirdparty, $address, $client)
	{
		$validated = self::validateAddress($thirdparty, $address, $client);
		if (!$validated['success']) { return $validated; }
		$parsed = FacturelectPeppolId::parse($validated['address']);
		$previous = $thirdparty->array_options;
		$thirdparty->array_options['options_facturelect_scheme'] = $parsed['scheme'];
		$thirdparty->array_options['options_facturelect_id'] = $parsed['identifier'];
		$thirdparty->db->begin();
		if ($thirdparty->updateExtraField('facturelect_scheme') < 0 || $thirdparty->updateExtraField('facturelect_id') < 0) {
			$thirdparty->db->rollback();
			$thirdparty->array_options = $previous;
			return array('success' => false, 'message' => 'FacturelectBuyerAddressSaveError');
		}
		$thirdparty->db->commit();
		return array('success' => true, 'message' => 'FacturelectThirdpartyAddressSaved');
	}

	/**
	 * Translate a verdict for an event or an escaped HTML banner.
	 *
	 * @param array $verdict Routing verdict
	 * @param object $langs Dolibarr translator
	 * @return string User-facing message
	 */
	public static function message($verdict, $langs)
	{
		return $langs->trans($verdict['message'], $verdict['address'], implode(', ', $verdict['active_addresses']));
	}
}
