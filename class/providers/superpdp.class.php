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
 *	\file       htdocs/custom/facturationelectronique/class/providers/superpdp.class.php
 *	\ingroup    facturationelectronique
 *	\brief      SuperPDP API Provider implementation
 */

if (!class_exists('BaseFacturelectProvider')) {
	require_once dirname(__FILE__) . '/baseprovider.class.php';
}

class SuperPdpProvider extends BaseFacturelectProvider
{
	/**
	 * @var string API Base URL
	 */
	private $api_url = 'https://api.superpdp.tech';

	/**
	 * Get access token, fetching a new one via Client Credentials if expired
	 *
	 * @return	string|bool		Access token or false on error
	 */
	public function getAccessToken()
	{
		global $conf;

		// Load stored token and expiration
		$stored_token = getDolGlobalString('FACTUR_ELECT_TOKEN');
		$expires_at = getDolGlobalString('FACTUR_ELECT_TOKEN_EXPIRES');

		$now = time();
		// If token exists and is valid for at least 60 more seconds, return it
		if (!empty($stored_token) && !empty($expires_at) && ($expires_at - $now) > 60) {
			return $stored_token;
		}

		// Otherwise, fetch a new one
		$mode = getDolGlobalString('FACTURATION_ELECTRONIQUE_MODE');
		if ($mode === 'production') {
			$client_id = getDolGlobalString('FACTURATION_ELECTRONIQUE_PROD_CLIENT_ID');
			$client_secret = getDolGlobalString('FACTURATION_ELECTRONIQUE_PROD_CLIENT_SECRET');
		} else {
			$client_id = getDolGlobalString('FACTURATION_ELECTRONIQUE_SANDBOX_CLIENT_ID');
			$client_secret = getDolGlobalString('FACTURATION_ELECTRONIQUE_SANDBOX_CLIENT_SECRET');
		}

		if (empty($client_id) || empty($client_secret)) {
			$this->error = "Identifiants API non configures pour le mode " . ($mode === 'production' ? 'Production' : 'Bac a sable');
			return false;
		}

		$post_fields = array(
			'grant_type' => 'client_credentials',
			'client_id' => $client_id,
			'client_secret' => $client_secret
		);

		$url = $this->api_url . '/oauth2/token';

		$headers = array('Content-Type: application/x-www-form-urlencoded');

		$response = $this->sendHttpRequest($url, 'POST', $headers, $post_fields, false, 'application/x-www-form-urlencoded');
		if ($response === false) {
			return false;
		}

		if (empty($response['access_token'])) {
			$this->error = "Token non present dans la reponse";
			return false;
		}

		$token = $response['access_token'];
		$expires_in = !empty($response['expires_in']) ? $response['expires_in'] : 1800;
		$expires_timestamp = time() + $expires_in;

		// Store in llx_const
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_set_const($this->db, 'FACTUR_ELECT_TOKEN', $token, 'chaine', 0, 'Auto saved token', $conf->entity);
		dolibarr_set_const($this->db, 'FACTUR_ELECT_TOKEN_EXPIRES', $expires_timestamp, 'chaine', 0, 'Auto saved token expires', $conf->entity);

		return $token;
	}

	/**
	 * Base API Call wrapper using cURL inherited helper
	 *
	 * @param	string	$method			HTTP Method (GET, POST, DELETE)
	 * @param	string	$path			API Path starting with /
	 * @param	mixed	$params			Params array
	 * @param	bool	$raw_data		True if raw payload
	 * @param	string	$mime_type		Mime type
	 * @param	bool	$raw_response	True to return raw body
	 * @return	mixed					Parsed JSON or false
	 */
	public function callApi($method, $path, $params = null, $raw_data = false, $mime_type = 'application/json', $raw_response = false, $action = '')
	{
		$token = $this->getAccessToken();
		if (!$token) {
			return false;
		}

		$url = $this->api_url . '/v1.beta' . $path;

		$headers = array(
			'Authorization: Bearer ' . $token
		);

		if ($method === 'GET' && is_array($params)) {
			$url .= '?' . http_build_query($params);
		}

		if ($method === 'POST') {
			$headers[] = 'Content-Type: ' . $mime_type;
		}

		return $this->sendHttpRequest($url, $method, $headers, $params, $raw_data, $mime_type, $raw_response, $action);
	}

	/**
	 * Check session and connection credentials
	 *
	 * @return	array|bool		Session data or false
	 */
	public function checkSession()
	{
		return $this->callApi('GET', '/oauth2_sessions/me');
	}

	/**
	 * Fetch the authenticated company (GET /companies/me), incl. has_vat_on_debits / vat_regime.
	 *
	 * @return	array|bool		Company data or false on error
	 */
	public function getCompanyInfo()
	{
		return $this->callApi('GET', '/companies/me');
	}

	/**
	 * Search company in the French registry by SIREN
	 *
	 * @param	string	$siren		9-digit SIREN
	 * @return	array|bool			Matched companies array or false
	 */
	public function searchCompany($siren)
	{
		$clean_siren = preg_replace('/\s+/', '', $siren);
		return $this->callApi('GET', '/french_directory/companies', array('number' => $clean_siren));
	}

	/**
	 * Search companies in the French registry by name and optionally postcode
	 *
	 * @param	string	$name_starts_with		Company formal name starting prefix
	 * @param	string	$postcode_starts_with	Postcode starting prefix (optional)
	 * @return	array|bool						Matched companies array or false
	 */
	public function searchCompaniesList($name_starts_with, $postcode_starts_with = '')
	{
		$params = array(
			'formal_name_starts_with' => $name_starts_with
		);
		if (!empty($postcode_starts_with)) {
			$params['post_code_starts_with'] = $postcode_starts_with;
		}
		return $this->callApi('GET', '/french_directory/companies', $params);
	}

	/**
	 * List directory entries for a company (to check recipient electronic address)
	 *
	 * @param	string	$siren		9-digit SIREN
	 * @return	array|bool			Entries array or false
	 */
	public function getCompanyEntries($siren)
	{
		$clean_siren = preg_replace('/\s+/', '', $siren);
		return $this->callApi('GET', '/french_directory/entries', array('number' => $clean_siren));
	}

	/**
	 * Convert JSON invoice to Factur-X PDF, optionally preserving existing PDF layout
	 *
	 * @param	array	$en_invoice		Standard JSON en_invoice
	 * @param	string	$pdf_path		Optional path to existing Dolibarr PDF file
	 * @return	string|bool				Raw PDF Factur-X file content or false
	 */
	public function convertInvoiceToFacturX($en_invoice, $pdf_path = '')
	{
		if (!empty($pdf_path) && file_exists($pdf_path)) {
			return $this->callApiMultipartConvert('/invoices/convert?from=en16931&to=factur-x', $en_invoice, $pdf_path);
		}
		// JSON-only path: bypass callApi() to set explicit Content-Length and avoid chunked transfer encoding
		$token = $this->getAccessToken();
		if (!$token) {
			return false;
		}
		$json_body = json_encode($en_invoice);
		$url = $this->api_url . '/v1.beta/invoices/convert?from=en16931&to=factur-x';
		$headers = array(
			'Authorization: Bearer ' . $token,
			'Content-Type: application/json',
			'Content-Length: ' . strlen($json_body),
		);
		return $this->sendHttpRequest($url, 'POST', $headers, $json_body, true, 'application/json', true);
	}

	/**
	 * Get single invoice details by ID
	 *
	 * @param	string	$pdp_id		SuperPDP invoice ID
	 * @return	array|bool			Invoice data or false
	 */
	public function getInvoice($pdp_id)
	{
		return $this->callApi('GET', '/invoices/' . $pdp_id);
	}

	/**
	 * Send customer invoice to the provider network
	 *
	 * @param	Facture	$invoice_obj	Dolibarr invoice object
	 * @param	string	$pdf_content	Binary PDF content
	 * @return	string|bool				PDP technical ID or false on error
	 */
	public function sendInvoice($invoice_obj, $pdf_content)
	{
		$processing_rule = $this->resolveProcessingRule($invoice_obj);
		$res = $this->sendFacturXInvoice($pdf_content, $invoice_obj->ref, $processing_rule);
		if ($res && !empty($res['id'])) {
			return $res['id'];
		}
		return false;
	}

	/**
	 * Resolve the AFNOR processing rule (B2B / B2C / B2BInt) for an outgoing invoice,
	 * from its buyer third party. Sent as the processing_rule query param of POST /invoices.
	 *
	 * @param	Facture	$invoice_obj	Dolibarr invoice (thirdparty expected to be loaded)
	 * @return	string					'B2B', 'B2C' or 'B2BInt'
	 */
	private function resolveProcessingRule($invoice_obj)
	{
		if (empty($invoice_obj->thirdparty)) {
			$invoice_obj->fetch_thirdparty();
		}
		$tp = $invoice_obj->thirdparty;
		if (empty($tp)) {
			return 'B2B';
		}
		if (empty($tp->array_options)) {
			$tp->fetch_optionals();
		}
		if (!class_exists('FacturelectB2cResolver')) {
			require_once dirname(__FILE__) . '/../b2cresolver.class.php';
		}
		$b2c_flag = !empty($tp->array_options['options_facturelect_b2c']) ? $tp->array_options['options_facturelect_b2c'] : 0;
		return FacturelectB2cResolver::resolveProcessingRule($tp->typent_code, $b2c_flag, $tp->country_code);
	}

	/**
	 * Send payment event (e-reporting) to the provider
	 *
	 * @param	CommonObject	$invoice_obj	Dolibarr invoice or supplier invoice object
	 * @param	float			$payment_amount	Amount paid
	 * @param	int				$payment_date	Payment Unix timestamp
	 * @param	array			$details		VAT details breakdown
	 * @return	string|bool						Event technical ID or false on error
	 */
	public function sendPaymentEvent($invoice_obj, $payment_amount, $payment_date, $details)
	{
		$pdp_id = $invoice_obj->array_options['options_facturelect_invoice_id'];
		$is_supplier = ($invoice_obj->element === 'facture_fourn');
		$status_code = $is_supplier ? 'fr:211' : 'fr:212';
		
		$res = $this->sendInvoiceEvent($pdp_id, $status_code, $details);
		if ($res && !empty($res['id'])) {
			return $res['id'];
		}
		return false;
	}

	/**
	 * Send an invoice event (e.g. payment received or sent) to SuperPDP
	 *
	 * @param	string	$pdp_id			SuperPDP invoice ID
	 * @param	string	$status_code	Event status code (e.g. 'fr:212', 'fr:211')
	 * @param	array	$details		Optional details array
	 * @return	array|bool				Response array or false
	 */
	public function sendInvoiceEvent($pdp_id, $status_code, $details = null)
	{
		$params = array(
			'invoice_id' => (int) $pdp_id,
			'status_code' => $status_code
		);
		if (!empty($details)) {
			$params['details'] = $details;
		}
		return $this->callApi('POST', '/invoice_events', $params);
	}

	/**
	 * cURL wrapper for multipart/form-data conversion
	 *
	 * @param	string	$path			API Path
	 * @param	array	$en_invoice		JSON invoice array
	 * @param	string	$pdf_path		Path to PDF file
	 * @return	string|bool				Raw file response or false on error
	 */
	public function callApiMultipartConvert($path, $en_invoice, $pdf_path)
	{
		$token = $this->getAccessToken();
		if (!$token) {
			return false;
		}

		$url = $this->api_url . '/v1.beta' . $path;

		$boundary = uniqid();
		$post_data = '';

		// 1. Add "invoice" part (JSON data)
		$post_data .= "--" . $boundary . "\r\n";
		$post_data .= "Content-Disposition: form-data; name=\"invoice\"\r\n";
		$post_data .= "Content-Type: application/json\r\n\r\n";
		$post_data .= json_encode($en_invoice) . "\r\n";

		// 2. Add "pdf" part (Binary PDF file)
		$post_data .= "--" . $boundary . "\r\n";
		$post_data .= "Content-Disposition: form-data; name=\"pdf\"; filename=\"" . basename($pdf_path) . "\"\r\n";
		$post_data .= "Content-Type: application/pdf\r\n\r\n";
		$post_data .= file_get_contents($pdf_path) . "\r\n";

		$post_data .= "--" . $boundary . "--\r\n";

		$headers = array(
			'Authorization: Bearer ' . $token,
			'Content-Type: multipart/form-data; boundary=' . $boundary
		);

		return $this->sendHttpRequest($url, 'POST', $headers, $post_data, true, 'application/json', true);
	}

	/**
	 * Send raw Factur-X file to SuperPDP
	 *
	 * @param	string	$file_content	Raw Factur-X PDF binary content
	 * @param	string	$external_id	Local Invoice Reference
	 * @return	array|bool				Response array or false
	 */
	public function sendFacturXInvoice($file_content, $external_id, $processing_rule = '')
	{
		$path = '/invoices?external_id=' . urlencode($external_id);
		if ($processing_rule !== '') {
			$path .= '&processing_rule=' . urlencode($processing_rule);
		}
		return $this->callApi('POST', $path, $file_content, true, 'application/pdf');
	}

	/**
	 * List incoming invoices (where direction = in / buyer role)
	 *
	 * @return	array|bool		Invoices array or false
	 */
	public function listIncomingInvoices()
	{
		return $this->callApi('GET', '/invoices?direction=in&expand[]=en_invoice&expand[]=en_invoice.seller&expand[]=en_invoice.buyer&expand[]=en_invoice.lines');
	}

	/**
	 * Download raw invoice file content
	 *
	 * @param	string	$pdp_id		SuperPDP invoice ID
	 * @return	string|bool			Original XML/PDF file content or false
	 */
	public function downloadInvoiceFile($pdp_id)
	{
		return $this->callApi('GET', '/invoices/' . $pdp_id . '?format=original', null, true, 'application/json', true);
	}

	/**
	 * Download PDF Factur-X file content specifically
	 *
	 * @param	string	$pdp_id		SuperPDP invoice ID
	 * @return	string|bool			Factur-X PDF file content or false
	 */
	public function downloadFacturXFile($pdp_id)
	{
		return $this->callApi('GET', '/invoices/' . $pdp_id . '?format=factur-x', null, true, 'application/json', true);
	}

	/**
	 * Pull incoming invoices and create draft supplier invoices in Dolibarr
	 *
	 * @param	array|null		$specific_ids	Optional array of SuperPDP invoice IDs to import selectively
	 * @return	int|bool		Number of imported invoices or false on error
	 */
	public function syncIncomingInvoices($specific_ids = null)
	{
		global $user, $conf;

		// Synchronisation context requires a user. If none, load admin user
		if (empty($user->id)) {
			$user = new User($this->db);
			$user->fetch(1); // Load main administrator
		}

		$invoices = $this->listIncomingInvoices();
		if ($invoices === false) {
			return false;
		}

		if (empty($invoices['data'])) {
			return 0;
		}

		$invoices_data = $invoices['data'];
		if (is_array($specific_ids)) {
			$invoices_data = array_filter($invoices_data, function($inv) use ($specific_ids) {
				return in_array($inv['id'], $specific_ids);
			});
		}

		if (empty($invoices_data)) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($this->db);

		$imported_count = 0;

		foreach ($invoices_data as $inv) {
			$pdp_id = $inv['id'];

			// Check if we already imported this invoice
			$sql = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "facture_fourn_extrafields WHERE facturelect_invoice_id = '" . $this->db->escape($pdp_id) . "'";
			$res = $this->db->query($sql);
			if ($res && $this->db->num_rows($res) > 0) {
				continue; // Already imported
			}

			$en_invoice = $inv['en_invoice'];
			if (empty($en_invoice)) {
				continue; // Missing structured data
			}

			// Get supplier details from incoming invoice JSON
			if (empty($en_invoice['seller'])) {
				dol_syslog("SuperPdpProvider::syncIncomingInvoices warning: missing seller object for invoice id " . $pdp_id, LOG_WARNING);
				continue;
			}
			$seller = $en_invoice['seller'];
			$seller_name = !empty($seller['name']) ? $seller['name'] : 'Fournisseur inconnu';
			$seller_siren = '';

			// Locate SIREN in identifiers
			if (!empty($seller['identifiers'])) {
				foreach ($seller['identifiers'] as $ident) {
					if ($ident['scheme'] === '0225' || $ident['scheme'] === 'fr_siren') {
						$seller_siren = $ident['value'];
						break;
					}
				}
			}
			if (empty($seller_siren) && !empty($seller['legal_registration_identifier'])) {
				$seller_siren = $seller['legal_registration_identifier']['value'];
			}

			// Search for matching third party in Dolibarr by SIREN
			$thirdparty = new Societe($this->db);
			$thirdparty_found = false;

			if (!empty($seller_siren)) {
				// Search by siren removing spaces for robust matching
				$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE REPLACE(siren, ' ', '') = '" . $this->db->escape($seller_siren) . "'";
				$res = $this->db->query($sql);
				if ($res && $this->db->num_rows($res) > 0) {
					$row = $this->db->fetch_object($res);
					$thirdparty->fetch($row->rowid);
					$thirdparty_found = true;
				}
			}

			// If vendor is not found, automatically create it as "Draft" (Brouillon)
			if (!$thirdparty_found) {
				$thirdparty->name = $seller_name;
				$thirdparty->idprof1 = $seller_siren;
				$thirdparty->client = 0;  // Not a customer
				$thirdparty->fournisseur = 1; // Is a supplier
				$thirdparty->status = 0; // Draft status!

				if (!empty($seller['postal_address'])) {
					$addr = $seller['postal_address'];
					$thirdparty->address = !empty($addr['address_line1']) ? $addr['address_line1'] : (!empty($addr['street_name']) ? $addr['street_name'] : '');
					if (!empty($addr['additional_street_name'])) {
						$thirdparty->address .= "\n" . $addr['additional_street_name'];
					}
					$thirdparty->zip = !empty($addr['post_code']) ? $addr['post_code'] : (!empty($addr['postcode']) ? $addr['postcode'] : '');
					$thirdparty->town = !empty($addr['city']) ? $addr['city'] : '';
					if (!empty($addr['country_subdivision'])) {
						$thirdparty->state = $addr['country_subdivision'];
					}
					// Default country ID for France is 1
					$thirdparty->country_id = 1; 
					$thirdparty->country_code = 'FR';
				}

				$soc_id = $thirdparty->create($user);
				if ($soc_id <= 0) {
					$this->error = "Echec de la creation automatique du tiers " . $seller_name . " : " . $thirdparty->error;
					dol_syslog("SuperPdpProvider::syncIncomingInvoices error: " . $this->error, LOG_ERR);
					continue;
				}
				$thirdparty->fetch($soc_id);
				dol_syslog("SuperPdpProvider::syncIncomingInvoices created draft third party ID " . $soc_id . " for vendor SIREN " . $seller_siren, LOG_INFO);
			}

			// Create the Draft Supplier Invoice in Dolibarr
			$invoice_supplier = new FactureFournisseur($this->db);
			$invoice_supplier->socid = $thirdparty->id;
			$invoice_supplier->ref_supplier = $en_invoice['number'];
			$invoice_supplier->libelle = "Facture réseau SuperPDP " . $en_invoice['number'];
			$invoice_supplier->label = "Facture réseau SuperPDP " . $en_invoice['number'];
			
			$issue_date = !empty($en_invoice['issue_date']) ? strtotime($en_invoice['issue_date']) : time();
			$due_date = !empty($en_invoice['payment_due_date']) ? strtotime($en_invoice['payment_due_date']) : $issue_date + (30 * 86400);

			$invoice_supplier->date = $issue_date;
			$invoice_supplier->date_lim_reglement = $due_date;
			$invoice_supplier->date_echeance = $due_date;
			
			// Set payment terms
			if (!empty($thirdparty->cond_reglement_id)) {
				$invoice_supplier->cond_reglement_id = $thirdparty->cond_reglement_id;
			} else {
				$days_diff = round(($due_date - $issue_date) / 86400);
				if ($days_diff <= 0) {
					$invoice_supplier->cond_reglement_id = 1;
				} elseif ($days_diff <= 10) {
					$invoice_supplier->cond_reglement_id = 9;
				} elseif ($days_diff <= 14) {
					$invoice_supplier->cond_reglement_id = 11;
				} elseif ($days_diff <= 30) {
					$invoice_supplier->cond_reglement_id = 2;
				} elseif ($days_diff <= 60) {
					$invoice_supplier->cond_reglement_id = 4;
				} else {
					$invoice_supplier->cond_reglement_id = 2;
				}
			}

			// Set payment method
			if (!empty($thirdparty->mode_reglement_id)) {
				$invoice_supplier->mode_reglement_id = $thirdparty->mode_reglement_id;
			} else {
				$invoice_supplier->mode_reglement_id = 2;
			}
			
			// Set defaults
			$invoice_supplier->paye = 0;
			$invoice_supplier->statut = FactureFournisseur::STATUS_DRAFT;

			$invoice_supplier_id = $invoice_supplier->create($user);
			if ($invoice_supplier_id <= 0) {
				$this->error = "Echec de la creation de la facture fournisseur " . $invoice_supplier->ref_supplier . " : " . $invoice_supplier->error;
				dol_syslog("SuperPdpProvider::syncIncomingInvoices error: " . $this->error, LOG_ERR);
				continue;
			}
			$invoice_supplier->id = $invoice_supplier_id;

			// Add invoice lines
			$vat_fallback_used = false;
			if (!empty($en_invoice['lines'])) {
				foreach ($en_invoice['lines'] as $line) {
					$qty = !empty($line['invoiced_quantity']) ? floatval($line['invoiced_quantity']) : 1.0;
					$unit_price = 0.0;
					$gross_price = 0.0;
					if (!empty($line['price_details'])) {
						if (isset($line['price_details']['item_net_price'])) {
							$unit_price = floatval($line['price_details']['item_net_price']);
						} elseif (isset($line['price_details']['net_price'])) {
							$unit_price = floatval($line['price_details']['net_price']);
						}
						if (isset($line['price_details']['item_gross_price'])) {
							$gross_price = floatval($line['price_details']['item_gross_price']);
						} elseif (isset($line['price_details']['gross_price'])) {
							$gross_price = floatval($line['price_details']['gross_price']);
						}
					}
					if ($unit_price == 0.0 && !empty($qty)) {
						$line_amount = floatval($line['net_amount']);
						$unit_price = $line_amount / $qty;
					}

					// Resolve VAT rate — strict isset to handle 0% correctly (see CLAUDE.md #37)
					$vat_rate = 20.0;
					$vat_rate_resolved = false;
					if (isset($line['vat_information']['invoiced_item_vat_rate'])) {
						$vat_rate = floatval($line['vat_information']['invoiced_item_vat_rate']);
						$vat_rate_resolved = true;
					} elseif (isset($line['item_information']['vat_rate'])) {
						$vat_rate = floatval($line['item_information']['vat_rate']);
						$vat_rate_resolved = true;
					} elseif (isset($line['vat_rate'])) {
						$vat_rate = floatval($line['vat_rate']);
						$vat_rate_resolved = true;
					} elseif (isset($line['tax_rate'])) {
						$vat_rate = floatval($line['tax_rate']);
						$vat_rate_resolved = true;
					} elseif (isset($line['vat']['rate'])) {
						$vat_rate = floatval($line['vat']['rate']);
						$vat_rate_resolved = true;
					} elseif (isset($line['tax_amount']) && isset($line['net_amount'])) {
						$line_net = floatval($line['net_amount']);
						$line_tax = floatval($line['tax_amount']);
						if ($line_net > 0) {
							$vat_rate = round(($line_tax / $line_net) * 100, 2);
							$vat_rate_resolved = true;
						}
					}
					if (!$vat_rate_resolved) {
						$vat_fallback_used = true;
						$line_label = $line['item_information']['name'] ?? 'unknown';
						dol_syslog("SuperPdpProvider::syncIncomingInvoices WARNING: could not resolve VAT rate for line '{$line_label}' in invoice PDP ID {$pdp_id}. Defaulting to 20%. Manual verification required.", LOG_WARNING);
					}

					$item_info = !empty($line['item_information']) ? $line['item_information'] : array();
					$desc = !empty($item_info['name']) ? $item_info['name'] : 'Ligne de facture';
					if (!empty($item_info['description']) && $item_info['description'] !== $desc) {
						$desc .= ' — ' . $item_info['description'];
					}

					// Add discount info to description if present
					if ($gross_price > 0 && $gross_price > $unit_price) {
						$disc_amt = $gross_price - $unit_price;
						$desc .= " (Remise unitaire: " . price($disc_amt) . ")";
					}

					// Try to match an existing Dolibarr product (buyer ref > seller ref > label)
					$product_match = $this->findProductByLineInfo($item_info);

					$line_result = $invoice_supplier->addline(
						$desc,
						$unit_price,
						$vat_rate,
						0, // txlocaltax1
						0, // txlocaltax2
						$qty,
						$product_match['id'], // fk_product (0 = free text if no match)
						0, // remise_percent
						0, // date_start
						0, // date_end
						0, // fk_code_ventilation
						0, // info_bits
						'HT', // price_base_type
						$product_match['type'] // type (0=product, 1=service)
					);

					if ($line_result <= 0) {
						dol_syslog("SuperPdpProvider::syncIncomingInvoices warning: failed to add line " . $desc, LOG_WARNING);
					}
				}
			}

			// Add Document-level Allowances (Remises globales)
			if (!empty($en_invoice['document_level_allowances'])) {
				foreach ($en_invoice['document_level_allowances'] as $allowance) {
					$amount = floatval($allowance['amount'] ?? 0.0);
					if ($amount > 0) {
						$desc = !empty($allowance['reason']) ? "Remise: " . $allowance['reason'] : "Remise globale";
						if (isset($allowance['vat_rate'])) {
							$vat_rate = floatval($allowance['vat_rate']);
						} else {
							$vat_rate = 20.0;
							$vat_fallback_used = true;
							dol_syslog("SuperPdpProvider::syncIncomingInvoices WARNING: could not resolve VAT rate for allowance '{$desc}' in invoice PDP ID {$pdp_id}. Defaulting to 20%.", LOG_WARNING);
						}
						$invoice_supplier->addline($desc, -$amount, $vat_rate, 0, 0, 1, 0, 0, 0, 0, 0, 0, 'HT', 0);
					}
				}
			}

			// Add Document-level Charges (Frais de port, etc.)
			if (!empty($en_invoice['document_level_charges'])) {
				foreach ($en_invoice['document_level_charges'] as $charge) {
					$amount = floatval($charge['amount'] ?? 0.0);
					if ($amount > 0) {
						$desc = !empty($charge['reason']) ? "Charge: " . $charge['reason'] : "Frais de port / Livraison";
						if (isset($charge['vat_rate'])) {
							$vat_rate = floatval($charge['vat_rate']);
						} else {
							$vat_rate = 20.0;
							$vat_fallback_used = true;
							dol_syslog("SuperPdpProvider::syncIncomingInvoices WARNING: could not resolve VAT rate for charge '{$desc}' in invoice PDP ID {$pdp_id}. Defaulting to 20%.", LOG_WARNING);
						}
						$invoice_supplier->addline($desc, $amount, $vat_rate, 0, 0, 1, 0, 0, 0, 0, 0, 0, 'HT', 0);
					}
				}
			}

			// If any line used the 20% VAT fallback, append a visible warning to note_private
			if ($vat_fallback_used) {
				$warning = "[ATTENTION] Taux TVA non résolu sur au moins une ligne — vérification manuelle requise (taux par défaut 20% appliqué).";
				$invoice_supplier->setValueFrom('note_private', trim($invoice_supplier->note_private . "\n" . $warning));
			}

			// Store PDP Technical ID and transmission date in Invoice Extrafields
			$invoice_supplier->array_options['options_facturelect_invoice_id'] = $pdp_id;
			if (!empty($inv['created_at'])) {
				$invoice_supplier->array_options['options_facturelect_send_date'] = date('Y-m-d H:i:s', strtotime($inv['created_at']));
			}
			$invoice_supplier->updateExtraField('facturelect_invoice_id');
			$invoice_supplier->updateExtraField('facturelect_send_date');

			// Download and attach Factur-X PDF to Dolibarr Document Management
			$file_data = $this->downloadFacturXFile($pdp_id);
			if (!$file_data) {
				$file_data = $this->downloadInvoiceFile($pdp_id);
			}

			if ($file_data) {
				require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
				
				$upload_dir = $conf->fournisseur->facture->dir_output . '/' . get_exdir($invoice_supplier->id, 2, 0, 0, $invoice_supplier, 'invoice_supplier') . dol_sanitizeFileName($invoice_supplier->ref);
				dol_mkdir($upload_dir);

				$extension = 'pdf';
				if (strpos($file_data, '<?xml') !== false) {
					$extension = 'xml';
				}

				$file_name = dol_sanitizeFileName($invoice_supplier->ref) . '_facturX.' . $extension;
				$dest_path = $upload_dir . '/' . $file_name;

				$write_result = file_put_contents($dest_path, $file_data);
				if ($write_result) {
					dol_syslog("SuperPdpProvider::syncIncomingInvoices attached file " . $file_name . " to supplier invoice ID " . $invoice_supplier_id, LOG_INFO);
					addFileIntoDatabaseIndex($upload_dir, $file_name, '', 'generated', 0, $invoice_supplier);
				}
			}

			$imported_count++;
		}

		return $imported_count;
	}

	/**
	 * Try to match an invoice line to an existing Dolibarr product.
	 *
	 * Lookup order (stops at first match):
	 *   1. buyer_identifier (BT-156)  → llx_product.ref          (our own reference)
	 *   2. seller_identifier (BT-155) → llx_product_fournisseur_price.ref_fourn
	 *   3. name (BT-153)              → llx_product.label         (last resort)
	 *
	 * @param	array	$item_info	item_information block from EN16931 line
	 * @return	array				['id' => int, 'type' => int]  (type 0=product, 1=service)
	 */
	private function findProductByLineInfo(array $item_info)
	{
		global $conf;

		$not_found = array('id' => 0, 'type' => 1);
		$entity = (int) $conf->entity;

		// 1. Match by buyer_identifier → our own product ref
		if (!empty($item_info['buyer_identifier'])) {
			$ref = $this->db->escape($item_info['buyer_identifier']);
			$sql = "SELECT rowid, fk_product_type FROM " . MAIN_DB_PREFIX . "product"
				. " WHERE ref = '" . $ref . "' AND entity = " . $entity . " LIMIT 1";
			$res = $this->db->query($sql);
			if ($res && $this->db->num_rows($res) > 0) {
				$row = $this->db->fetch_object($res);
				return array('id' => (int) $row->rowid, 'type' => (int) $row->fk_product_type);
			}
		}

		// 2. Match by seller_identifier → supplier's ref in purchase price table
		if (!empty($item_info['seller_identifier'])) {
			$ref_fourn = $this->db->escape($item_info['seller_identifier']);
			$sql = "SELECT p.rowid, p.fk_product_type FROM " . MAIN_DB_PREFIX . "product p"
				. " INNER JOIN " . MAIN_DB_PREFIX . "product_fournisseur_price pfp ON pfp.fk_product = p.rowid"
				. " WHERE pfp.ref_fourn = '" . $ref_fourn . "' AND p.entity = " . $entity . " LIMIT 1";
			$res = $this->db->query($sql);
			if ($res && $this->db->num_rows($res) > 0) {
				$row = $this->db->fetch_object($res);
				return array('id' => (int) $row->rowid, 'type' => (int) $row->fk_product_type);
			}
		}

		// 3. Match by name → product label (least reliable, avoid false positives on short names)
		if (!empty($item_info['name']) && strlen($item_info['name']) > 3) {
			$label = $this->db->escape($item_info['name']);
			$sql = "SELECT rowid, fk_product_type FROM " . MAIN_DB_PREFIX . "product"
				. " WHERE label = '" . $label . "' AND entity = " . $entity . " LIMIT 1";
			$res = $this->db->query($sql);
			if ($res && $this->db->num_rows($res) > 0) {
				$row = $this->db->fetch_object($res);
				return array('id' => (int) $row->rowid, 'type' => (int) $row->fk_product_type);
			}
		}

		return $not_found;
	}

	/**
	 * Get the display name of the provider
	 *
	 * @return	string
	 */
	public function getName()
	{
		return 'SuperPDP';
	}
}
