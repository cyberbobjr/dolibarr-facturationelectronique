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
 *	\file       htdocs/custom/facturationelectronique/class/providers/factpulse.class.php
 *	\ingroup    facturationelectronique
 *	\brief      FactPulse API Provider implementation
 */

if (!class_exists('BaseFacturelectProvider')) {
	require_once dirname(__FILE__) . '/baseprovider.class.php';
}

class FactPulseProvider extends BaseFacturelectProvider
{
	/**
	 * @var string API Base URL
	 */
	private $api_url = 'https://factpulse.fr/api';

	/**
	 * Get access token, fetching a new one via credentials if expired
	 *
	 * @return	string|bool		Access token or false on error
	 */
	public function getAccessToken()
	{
		global $conf;

		// Load stored token and expiration
		$stored_token = getDolGlobalString('FACTUR_ELECT_FACTPULSE_TOKEN');
		$expires_at = getDolGlobalString('FACTUR_ELECT_FACTPULSE_TOKEN_EXPIRES');

		$now = time();
		// If token exists and is valid for at least 60 more seconds, return it
		if (!empty($stored_token) && !empty($expires_at) && ($expires_at - $now) > 60) {
			return $stored_token;
		}

		// Otherwise, fetch a new one using Email / Password
		$email = getDolGlobalString('FACTURATION_ELECTRONIQUE_FACTPULSE_EMAIL');
		$password = getDolGlobalString('FACTURATION_ELECTRONIQUE_FACTPULSE_PASSWORD');

		if (empty($email) || empty($password)) {
			$this->error = "Identifiants FactPulse (Email / Mot de passe) non configures.";
			return false;
		}

		$post_fields = array(
			'username' => $email,
			'password' => $password
		);

		$url = $this->api_url . '/token/';
		$headers = array('Content-Type: application/json');

		$response = $this->sendHttpRequest($url, 'POST', $headers, $post_fields, false, 'application/json');
		if ($response === false) {
			return false;
		}

		if (empty($response['access'])) {
			$this->error = "Token d'acces non present dans la reponse FactPulse";
			return false;
		}

		$token = $response['access'];
		// FactPulse access tokens are typically valid for 30 minutes (1800s)
		$expires_timestamp = time() + 1700;

		// Store in llx_const
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_set_const($this->db, 'FACTUR_ELECT_FACTPULSE_TOKEN', $token, 'chaine', 0, 'Auto saved FactPulse token', $conf->entity);
		dolibarr_set_const($this->db, 'FACTUR_ELECT_FACTPULSE_TOKEN_EXPIRES', $expires_timestamp, 'chaine', 0, 'Auto saved FactPulse token expires', $conf->entity);

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

		$url = $this->api_url . $path;

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
		return $this->callApi('GET', '/v1/me');
	}

	/**
	 * Get single invoice details by ID
	 *
	 * @param	string	$pdp_id		FactPulse/PDP Flow ID
	 * @return	array|bool			Invoice details mapped to Dolibarr GUI expectation
	 */
	public function getInvoice($pdp_id)
	{
		// Fetch flow details from FactPulse
		$flow = $this->callApi('GET', '/v1/afnor/flow/v1/flows/' . $pdp_id);
		if ($flow === false) {
			return false;
		}

		// Map FactPulse flow structure to Dolibarr unified GUI expected format
		$mapped = array(
			'id' => $flow['flowId'] ?? $pdp_id,
			'created_at' => $flow['created'] ?? date('Y-m-d H:i:s'),
			'direction' => (isset($flow['direction']) && $flow['direction'] === 'IN') ? 'in' : 'out',
			'events' => array()
		);

		// If FactPulse lists lifecycle states, map them as events
		if (!empty($flow['lifecycle'])) {
			foreach ($flow['lifecycle'] as $idx => $lc) {
				$mapped['events'][] = array(
					'id' => $idx + 1,
					'status_code' => $lc['statusCode'] ?? 'api:unknown',
					'status_text' => $lc['statusLabel'] ?? 'Statut inconnu',
					'created_at' => $lc['date'] ?? date('Y-m-d H:i:s'),
					'details' => !empty($lc['reason']) ? array(array('reason' => $lc['reason'])) : null
				);
			}
		} else {
			// Fallback placeholder event based on global status
			$status_lbl = $flow['status'] ?? 'Importé';
			$mapped['events'][] = array(
				'id' => 1,
				'status_code' => 'api:imported',
				'status_text' => $status_lbl,
				'created_at' => $flow['created'] ?? date('Y-m-d H:i:s')
			);
		}

		return $mapped;
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
		global $mysoc, $conf;

		// 1. Build SimplifiedInvoiceData JSON
		$lines = array();
		if (!empty($invoice_obj->lines)) {
			foreach ($invoice_obj->lines as $line) {
				$lines[] = array(
					'description' => $line->desc ? $line->desc : 'Ligne de facture',
					'quantity' => floatval($line->qty),
					'unitPrice' => floatval($line->subprice),
					'vatRate' => floatval($line->tva_tx)
				);
			}
		}

		// Retrieve supplier (our own company) details
		$supplier_siret = !empty($mysoc->siret) ? $mysoc->siret : $mysoc->idprof2;
		$supplier_siret = preg_replace('/\s+/', '', $supplier_siret);
		
		// Retrieve bank details
		$iban = '';
		if (!empty($invoice_obj->fk_account)) {
			require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
			$acc = new Account($this->db);
			if ($acc->fetch($invoice_obj->fk_account) > 0) {
				$iban = preg_replace('/\s+/', '', $acc->iban);
			}
		}
		if (empty($iban)) {
			$iban = getDolGlobalString('FACTURATION_ELECTRONIQUE_DEFAULT_IBAN');
		}

		// Retrieve customer (recipient) details
		$recipient_siret = !empty($invoice_obj->thirdparty->siret) ? $invoice_obj->thirdparty->siret : $invoice_obj->thirdparty->idprof2;
		$recipient_siret = preg_replace('/\s+/', '', $recipient_siret);

		if (empty($supplier_siret) || empty($recipient_siret)) {
			$this->error = "SIRET Emetteur ou Destinataire manquant sur la fiche societe.";
			return false;
		}

		$supplier_siren = substr($supplier_siret, 0, 9);
		$recipient_siren = substr($recipient_siret, 0, 9);

		$invoice_data = array(
			'number' => $invoice_obj->ref,
			'notes' => array(
				array(
					'subject_code' => 'PMT',
					'subjectCode' => 'PMT',
					'content' => 'Indemnite forfaitaire de recouvrement de 40 euros en cas de retard.'
				),
				array(
					'subject_code' => 'PMD',
					'subjectCode' => 'PMD',
					'content' => 'Penalites de retard applicables : 3 fois le taux d interest legal.'
				),
				array(
					'subject_code' => 'AAB',
					'subjectCode' => 'AAB',
					'content' => 'Pas d escompte pour paiement anticipe.'
				)
			),
			'supplier' => array(
				'siret' => $supplier_siret,
				'siren' => $supplier_siren,
				'name' => $mysoc->name,
				'electronic_address' => array(
					'identifier' => $supplier_siret,
					'schemeId' => '0225',
					'scheme_id' => '0225'
				),
				'electronicAddress' => array(
					'identifier' => $supplier_siret,
					'schemeId' => '0225',
					'scheme_id' => '0225'
				)
			),
			'recipient' => array(
				'siret' => $recipient_siret,
				'siren' => $recipient_siren,
				'name' => $invoice_obj->thirdparty->name,
				'electronic_address' => array(
					'identifier' => $recipient_siret,
					'schemeId' => '0225',
					'scheme_id' => '0225'
				),
				'electronicAddress' => array(
					'identifier' => $recipient_siret,
					'schemeId' => '0225',
					'scheme_id' => '0225'
				)
			),
			'lines' => $lines
		);

		if (!empty($iban)) {
			$invoice_data['supplier']['iban'] = $iban;
		}

		// Check if recipient is a public entity (for Chorus Pro routing)
		$dest_type = 'afnor';
		if (!empty($invoice_obj->thirdparty->typent_code) && strpos(strtolower($invoice_obj->thirdparty->typent_code), 'pub') !== false) {
			$dest_type = 'chorus_pro';
		}

		$payload = array(
			'invoiceData' => $invoice_data,
			'sourcePdf' => base64_encode($pdf_content),
			'destination' => array(
				'type' => $dest_type,
				'processingRule' => 'ArchiveOnly',
				'processing_rule' => 'ArchiveOnly'
			),
			'options' => array(
				'autoEnrich' => false,
				'facturxProfile' => 'EN16931',
				'validateXml' => true
			)
		);

		$res = $this->callApi('POST', '/v1/processing/invoices/submit-complete', $payload);
		if ($res === false) {
			return false;
		}

		// Return task ID or flow ID if immediately generated
		if (!empty($res['taskId'])) {
			return $res['taskId'];
		}
		if (!empty($res['flowId'])) {
			return $res['flowId'];
		}

		return 'TASK-' . uniqid();
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
		global $mysoc;

		$pdp_id = $invoice_obj->array_options['options_facturelect_invoice_id'];
		if (empty($pdp_id)) {
			$this->error = "Cette facture n'est pas liee a un ID technique PDP.";
			return false;
		}

		$is_supplier = ($invoice_obj->element === 'facture_fourn');

		if (empty($invoice_obj->thirdparty)) {
			$invoice_obj->fetch_thirdparty();
		}

		// Determine lifecycle status
		// PAID (212) if invoice is fully paid, otherwise PARTIALLY_PAID (211)
		$status = '211';
		if ($is_supplier) {
			if ($invoice_obj->statut == 2 || $invoice_obj->paye == 1) {
				$status = '212';
			}
		} else {
			if ($invoice_obj->statut == 2 || $invoice_obj->paye == 1) {
				$status = '212';
			}
		}

		$mysoc_siren = !empty($mysoc->siren) ? $mysoc->siren : $mysoc->idprof1;
		$mysoc_siren = preg_replace('/\s+/', '', $mysoc_siren);

		$thirdparty_siren = !empty($invoice_obj->thirdparty->siren) ? $invoice_obj->thirdparty->siren : $invoice_obj->thirdparty->idprof1;
		$thirdparty_siren = preg_replace('/\s+/', '', $thirdparty_siren);

		$payload = array(
			'documentId' => 'PAY-' . $invoice_obj->ref . '-' . time(),
			'businessProcess' => 'REGULATED',
			'typeCode' => '23',
			'senderSiren' => $mysoc_siren,
			'senderRole' => $is_supplier ? 'BY' : 'SE',
			'invoiceId' => $invoice_obj->ref,
			'invoiceIssueDate' => date('Y-m-d', $invoice_obj->date),
			'invoiceTypeCode' => ($invoice_obj->type == 1) ? '381' : '380', // 381 for credit note, 380 for invoice
			'status' => $status,
			'encaisseAmount' => floatval($payment_amount),
			'flowType' => $is_supplier ? 'SupplierInvoiceLC' : 'CustomerInvoiceLC'
		);

		if ($is_supplier) {
			$payload['invoiceSellerSiren'] = $thirdparty_siren;
			$payload['invoiceBuyerSiren'] = $mysoc_siren;
		} else {
			$payload['invoiceSellerSiren'] = $mysoc_siren;
			$payload['invoiceBuyerSiren'] = $thirdparty_siren;
		}

		$res = $this->callApi('POST', '/v1/cdar/submit', $payload);
		if ($res === false) {
			return false;
		}

		return $res['documentId'] ?? 'EVENT-' . uniqid();
	}

	/**
	 * Pull incoming invoices and create draft supplier invoices in Dolibarr
	 *
	 * @param	array|null		$specific_ids	Optional array of FactPulse flow IDs to import selectively
	 * @return	int|bool		Number of imported invoices or false on error
	/**
	 * List incoming invoices (where direction = IN) from FactPulse
	 *
	 * @return	array|bool		Invoices array or false on error
	 */
	public function listIncomingInvoices()
	{
		$params = array(
			'direction' => 'IN',
			'status' => 'RECEIVED'
		);
		$res = $this->callApi('GET', '/v1/afnor/flow/v1/flows/search', $params);
		if ($res === false) {
			return false;
		}

		$invoices = array(
			'data' => array()
		);

		if (empty($res['flows'])) {
			return $invoices;
		}

		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';

		foreach ($res['flows'] as $flow) {
			$flow_id = $flow['flowId'];

			// Check if already imported locally
			$sql = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "facture_fourn_extrafields WHERE facturelect_invoice_id = '" . $this->db->escape($flow_id) . "'";
			$sql_res = $this->db->query($sql);

			$invoice_data = null;

			if ($sql_res && $this->db->num_rows($sql_res) > 0) {
				$row = $this->db->fetch_object($sql_res);
				$fk_facture = $row->fk_object;

				$fac = new FactureFournisseur($this->db);
				if ($fac->fetch($fk_facture) > 0) {
					$fac->fetch_thirdparty();

					// Map from local Dolibarr invoice data
					$invoice_data = array(
						'number' => $fac->ref_supplier ? $fac->ref_supplier : $fac->ref,
						'issue_date' => date('Y-m-d', $fac->date),
						'seller' => array(
							'name' => $fac->thirdparty->name,
							'identifiers' => array(
								array('scheme' => '0225', 'value' => $fac->thirdparty->siren)
							)
						),
						'totals' => array(
							'total_without_vat' => floatval($fac->total_ht),
							'total_with_vat' => floatval($fac->total_ttc)
						)
					);
				}
			}

			// If not found locally, download PDF and parse it to get metadata
			if (empty($invoice_data)) {
				$file_data = $this->callApi('GET', '/v1/afnor/incoming-flows/' . $flow_id, null, true, 'application/json', true);
				if (!empty($file_data)) {
					$tmp_dir = DOL_DATA_ROOT . '/facturationelectronique/temp';
					dol_mkdir($tmp_dir);
					$tmp_file = $tmp_dir . '/tmp_list_' . $flow_id . '.pdf';
					file_put_contents($tmp_file, $file_data);

					$parsed = $this->parseFacturXFile($tmp_file);
					unlink($tmp_file);

					if ($parsed !== false) {
						$inv_data = !empty($parsed['invoiceData']) ? $parsed['invoiceData'] : (!empty($parsed['invoice']) ? $parsed['invoice'] : null);
						if (!empty($inv_data)) {
							$number = $inv_data['number'] ?? ($inv_data['invoiceNumber'] ?? ('FP-' . $flow_id));
							$issue_date = $inv_data['issueDate'] ?? ($inv_data['invoiceDate'] ?? date('Y-m-d'));
							
							// Extract supplier/seller
							$supplier = $inv_data['supplier'] ?? ($inv_data['seller'] ?? null);
							$seller_name = $supplier['name'] ?? 'Fournisseur FactPulse';
							$seller_siren = $supplier['siret'] ?? ($supplier['siren'] ?? '');

							// Extract totals
							$totals = $inv_data['totals'] ?? null;
							$total_without_vat = floatval($totals['taxExclusiveAmount'] ?? ($totals['totalNetAmount'] ?? 0.0));
							$total_with_vat = floatval($totals['taxInclusiveAmount'] ?? ($totals['totalGrossAmount'] ?? ($totals['amountDue'] ?? 0.0)));

							$invoice_data = array(
								'number' => $number,
								'issue_date' => date('Y-m-d', strtotime($issue_date)),
								'seller' => array(
									'name' => $seller_name,
									'identifiers' => array(
										array('scheme' => '0225', 'value' => $seller_siren)
									)
								),
								'totals' => array(
									'total_without_vat' => $total_without_vat,
									'total_with_vat' => $total_with_vat
								)
							);
						}
					}
				}
			}

			// Fallback placeholder if parsing failed or file could not be downloaded
			if (empty($invoice_data)) {
				$invoice_data = array(
					'number' => 'FP-' . $flow_id,
					'issue_date' => date('Y-m-d'),
					'seller' => array(
						'name' => 'Fournisseur inconnu',
						'identifiers' => array(
							array('scheme' => '0225', 'value' => '')
						)
					),
					'totals' => array(
						'total_without_vat' => 0.0,
						'total_with_vat' => 0.0
					)
				);
			}

			$invoices['data'][] = array(
				'id' => $flow_id,
				'en_invoice' => $invoice_data
			);
		}

		return $invoices;
	}

	/**
	 * Synchronize/Import incoming supplier invoices from the provider
	 *
	 * @param	array|null		$specific_ids	Optional array of FactPulse invoice IDs to import selectively
	 * @return	int|bool		Number of imported invoices or false on error
	 */
	public function syncIncomingInvoices($specific_ids = null)
	{
		global $user, $conf;

		if (empty($user->id)) {
			$user = new User($this->db);
			$user->fetch(1);
		}

		// 1. Search incoming flows from FactPulse
		$params = array(
			'direction' => 'IN',
			'status' => 'RECEIVED'
		);
		$res = $this->callApi('GET', '/v1/afnor/flow/v1/flows/search', $params);
		if ($res === false) {
			return false;
		}

		if (empty($res['flows'])) {
			return 0;
		}

		$flows = $res['flows'];
		if (is_array($specific_ids)) {
			$flows = array_filter($flows, function($f) use ($specific_ids) {
				return in_array($f['flowId'], $specific_ids);
			});
		}

		if (empty($flows)) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

		$extrafields = new ExtraFields($this->db);
		$imported_count = 0;

		foreach ($flows as $flow) {
			$flow_id = $flow['flowId'];

			// Check if already imported
			$sql = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "facture_fourn_extrafields WHERE facturelect_invoice_id = '" . $this->db->escape($flow_id) . "'";
			$sql_res = $this->db->query($sql);
			if ($sql_res && $this->db->num_rows($sql_res) > 0) {
				continue;
			}

			// 2. Download flow PDF file (which is Factur-X compliant)
			$file_data = $this->callApi('GET', '/v1/afnor/incoming-flows/' . $flow_id, null, true, 'application/json', true);
			if (empty($file_data)) {
				dol_syslog("FactPulseProvider::syncIncomingInvoices warning: failed to download file for flow " . $flow_id, LOG_WARNING);
				continue;
			}

			// Save file temporarily to disk to call parsing
			$tmp_dir = DOL_DATA_ROOT . '/facturationelectronique/temp';
			dol_mkdir($tmp_dir);
			$tmp_file = $tmp_dir . '/tmp_' . $flow_id . '.pdf';
			file_put_contents($tmp_file, $file_data);

			// 3. Call FactPulse parsing API to extract structured XML data
			$parsed = $this->parseFacturXFile($tmp_file);
			unlink($tmp_file); // Clean up

			if ($parsed === false || empty($parsed['invoiceData'])) {
				dol_syslog("FactPulseProvider::syncIncomingInvoices warning: failed to parse PDF Factur-X for flow " . $flow_id . " : " . $this->error, LOG_WARNING);
				continue;
			}

			$inv_data = $parsed['invoiceData'];

			// Extract vendor information
			$seller_name = $inv_data['supplier']['name'] ?? 'Fournisseur FactPulse';
			$seller_siren = $inv_data['supplier']['siret'] ?? '';

			// Search or create Tiers Supplier in Dolibarr
			$thirdparty = new Societe($this->db);
			$thirdparty_found = false;

			if (!empty($seller_siren)) {
				$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE REPLACE(siren, ' ', '') = '" . $this->db->escape($seller_siren) . "'";
				$db_res = $this->db->query($sql);
				if ($db_res && $this->db->num_rows($db_res) > 0) {
					$row = $this->db->fetch_object($db_res);
					$thirdparty->fetch($row->rowid);
					$thirdparty_found = true;
				}
			}

			if (!$thirdparty_found) {
				$thirdparty->name = $seller_name;
				$thirdparty->idprof1 = $seller_siren;
				$thirdparty->client = 0;
				$thirdparty->fournisseur = 1;
				$thirdparty->status = 0; // Draft

				if (!empty($inv_data['supplier']['postalAddress'])) {
					$addr = $inv_data['supplier']['postalAddress'];
					$thirdparty->address = $addr['lineOne'] ?? '';
					if (!empty($addr['lineTwo'])) {
						$thirdparty->address .= "\n" . $addr['lineTwo'];
					}
					$thirdparty->zip = $addr['postalCode'] ?? '';
					$thirdparty->town = $addr['city'] ?? '';
					$thirdparty->country_id = 1;
					$thirdparty->country_code = $addr['countryCode'] ?? 'FR';
				}

				$soc_id = $thirdparty->create($user);
				if ($soc_id <= 0) {
					dol_syslog("FactPulseProvider::syncIncomingInvoices error: " . $thirdparty->error, LOG_ERR);
					continue;
				}
				$thirdparty->fetch($soc_id);
			}

			// Create the Draft Supplier Invoice
			$invoice_supplier = new FactureFournisseur($this->db);
			$invoice_supplier->socid = $thirdparty->id;
			$invoice_supplier->ref_supplier = $inv_data['number'] ?? ('FP-' . $flow_id);
			$invoice_supplier->libelle = "Facture réseau FactPulse " . $invoice_supplier->ref_supplier;
			$invoice_supplier->label = $invoice_supplier->libelle;

			$issue_date = !empty($inv_data['issueDate']) ? strtotime($inv_data['issueDate']) : time();
			$due_date = !empty($inv_data['paymentDueDate']) ? strtotime($inv_data['paymentDueDate']) : ($issue_date + 30 * 86400);

			$invoice_supplier->date = $issue_date;
			$invoice_supplier->date_lim_reglement = $due_date;
			$invoice_supplier->date_echeance = $due_date;
			
			$invoice_supplier->cond_reglement_id = 2; // Default 30 days
			$invoice_supplier->mode_reglement_id = 2; // Virement
			$invoice_supplier->paye = 0;
			$invoice_supplier->statut = FactureFournisseur::STATUS_DRAFT;

			$invoice_supplier_id = $invoice_supplier->create($user);
			if ($invoice_supplier_id <= 0) {
				dol_syslog("FactPulseProvider::syncIncomingInvoices error: " . $invoice_supplier->error, LOG_ERR);
				continue;
			}
			$invoice_supplier->id = $invoice_supplier_id;

			// Add lines
			if (!empty($inv_data['lines'])) {
				foreach ($inv_data['lines'] as $line) {
					$desc = $line['description'] ?? 'Ligne Factur-X';
					$qty = floatval($line['quantity'] ?? 1.0);
					$unit_price = isset($line['unitPrice']) ? floatval($line['unitPrice']) : 0.0;
					$gross_price = isset($line['itemGrossPrice']) ? floatval($line['itemGrossPrice']) : (isset($line['grossPrice']) ? floatval($line['grossPrice']) : 0.0);
					
					// Resolve VAT rate with strict isset checks to handle 0% correctly
					$vat_rate = 20.0;
					if (isset($line['vatRate'])) {
						$vat_rate = floatval($line['vatRate']);
					} elseif (isset($line['vat_rate'])) {
						$vat_rate = floatval($line['vat_rate']);
					}

					// Add discount info to description if present
					if ($gross_price > 0 && $gross_price > $unit_price) {
						$disc_amt = $gross_price - $unit_price;
						$desc .= " (Remise unitaire: " . price($disc_amt) . ")";
					}

					$invoice_supplier->addline(
						$desc,
						$unit_price,
						$vat_rate,
						0, 0,
						$qty,
						0, 0, 0, 0, 0, 0,
						'HT', 0
					);
				}
			}

			// Add Document-level Allowances (Remises globales)
			$doc_allowances = $inv_data['documentLevelAllowances'] ?? ($inv_data['document_level_allowances'] ?? null);
			if (!empty($doc_allowances)) {
				foreach ($doc_allowances as $allowance) {
					$amount = floatval($allowance['amount'] ?? 0.0);
					if ($amount > 0) {
						$desc = !empty($allowance['reason']) ? "Remise: " . $allowance['reason'] : "Remise globale";
						$vat_rate = isset($allowance['vatRate']) ? floatval($allowance['vatRate']) : (isset($allowance['vat_rate']) ? floatval($allowance['vat_rate']) : 20.0);
						$invoice_supplier->addline($desc, -$amount, $vat_rate, 0, 0, 1, 0, 0, 0, 0, 0, 0, 'HT', 0);
					}
				}
			}

			// Add Document-level Charges (Frais de port, etc.)
			$doc_charges = $inv_data['documentLevelCharges'] ?? ($inv_data['document_level_charges'] ?? null);
			if (!empty($doc_charges)) {
				foreach ($doc_charges as $charge) {
					$amount = floatval($charge['amount'] ?? 0.0);
					if ($amount > 0) {
						$desc = !empty($charge['reason']) ? "Charge: " . $charge['reason'] : "Frais de port / Livraison";
						$vat_rate = isset($charge['vatRate']) ? floatval($charge['vatRate']) : (isset($charge['vat_rate']) ? floatval($charge['vat_rate']) : 20.0);
						$invoice_supplier->addline($desc, $amount, $vat_rate, 0, 0, 1, 0, 0, 0, 0, 0, 0, 'HT', 0);
					}
				}
			}

			// Store PDP ID
			$invoice_supplier->array_options['options_facturelect_invoice_id'] = $flow_id;
			$invoice_supplier->array_options['options_facturelect_send_date'] = date('Y-m-d H:i:s', $issue_date);
			$invoice_supplier->updateExtraField('facturelect_invoice_id');
			$invoice_supplier->updateExtraField('facturelect_send_date');

			// Attach PDF to Dolibarr Document Management
			require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
			$upload_dir = $conf->fournisseur->facture->dir_output . '/' . get_exdir($invoice_supplier->id, 2, 0, 0, $invoice_supplier, 'invoice_supplier') . dol_sanitizeFileName($invoice_supplier->ref);
			dol_mkdir($upload_dir);

			$file_name = dol_sanitizeFileName($invoice_supplier->ref) . '_facturX.pdf';
			$dest_path = $upload_dir . '/' . $file_name;

			if (file_put_contents($dest_path, $file_data)) {
				dol_syslog("FactPulseProvider::syncIncomingInvoices attached file " . $file_name, LOG_INFO);
				addFileIntoDatabaseIndex($upload_dir, $file_name, '', 'generated', 0, $invoice_supplier);
			}

			$imported_count++;
		}

		return $imported_count;
	}

	/**
	 * Call FactPulse parsing API to extract structured XML data from Factur-X PDF
	 *
	 * @param	string	$pdf_path		Path to PDF file
	 * @return	array|bool				Parsed invoice data or false
	 */
	private function parseFacturXFile($pdf_path)
	{
		$token = $this->getAccessToken();
		if (!$token) {
			return false;
		}

		$url = $this->api_url . '/v1/processing/parse-facturx';
		$boundary = uniqid();
		$post_data = '';

		// Add PDF file part
		$post_data .= "--" . $boundary . "\r\n";
		$post_data .= "Content-Disposition: form-data; name=\"pdf_file\"; filename=\"" . basename($pdf_path) . "\"\r\n";
		$post_data .= "Content-Type: application/pdf\r\n\r\n";
		$post_data .= file_get_contents($pdf_path) . "\r\n";
		$post_data .= "--" . $boundary . "--\r\n";

		$headers = array(
			'Authorization: Bearer ' . $token,
			'Content-Type: multipart/form-data; boundary=' . $boundary
		);

		return $this->sendHttpRequest($url, 'POST', $headers, $post_data, true);
	}

	/**
	 * Get the display name of the provider
	 *
	 * @return	string
	 */
	public function getName()
	{
		return 'FactPulse';
	}
}
