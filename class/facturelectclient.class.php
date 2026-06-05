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
 *	\file       htdocs/custom/facturationelectronique/class/facturelectclient.class.php
 *	\ingroup    facturationelectronique
 *	\brief      Factory client gateway for Facturation Electronique module
 */

if (!class_exists('FacturelectProviderFactory')) {
	require_once dirname(__FILE__) . '/facturelectproviderfactory.class.php';
}

class FacturelectClient
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;



	/**
	 * @var FacturelectProvider The active provider driver
	 */
	public $provider;

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->provider = FacturelectProviderFactory::getProvider($db);
	}

	/**
	 * Magic getter to expose active provider error
	 *
	 * @param	string	$name	Property name
	 * @return	mixed
	 */
	public function __get($name)
	{
		if ($name === 'error') {
			return $this->provider->error;
		}
		return null;
	}

	/**
	 * Magic setter to set active provider error
	 *
	 * @param	string	$name	Property name
	 * @param	mixed	$value	Property value
	 * @return	void
	 */
	public function __set($name, $value)
	{
		if ($name === 'error') {
			$this->provider->error = $value;
		}
	}

	/**
	 * Check session and connection credentials
	 *
	 * @return	array|bool		Session data or false
	 */
	public function checkSession()
	{
		return $this->provider->checkSession();
	}

	/**
	 * Search company in the French registry by SIREN
	 *
	 * @param	string	$siren		9-digit SIREN
	 * @return	array|bool			Matched companies array or false
	 */
	public function searchCompany($siren)
	{
		if (method_exists($this->provider, 'searchCompany')) {
			return $this->provider->searchCompany($siren);
		}
		// Fallback for providers that don't implement French registry search
		$this->error = "La recherche de societe n'est pas supportee par ce fournisseur.";
		return false;
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
		if (method_exists($this->provider, 'searchCompaniesList')) {
			return $this->provider->searchCompaniesList($name_starts_with, $postcode_starts_with);
		}
		$this->error = "La recherche de societes n'est pas supportee par ce fournisseur.";
		return false;
	}

	/**
	 * List directory entries for a company (to check active electronic addresses)
	 *
	 * @param	string	$siren		9-digit SIREN
	 * @return	array|bool			Entries array or false
	 */
	public function getCompanyEntries($siren)
	{
		if (method_exists($this->provider, 'getCompanyEntries')) {
			return $this->provider->getCompanyEntries($siren);
		}
		$this->error = "L'annuaire des destinataires n'est pas supporte par ce fournisseur.";
		return false;
	}

	/**
	 * Convert JSON invoice to Factur-X PDF
	 *
	 * @param	array	$en_invoice		Standard JSON en_invoice
	 * @param	string	$pdf_path		Optional path to existing Dolibarr PDF file
	 * @return	string|bool				Raw PDF Factur-X file content or false
	 */
	public function convertInvoiceToFacturX($en_invoice, $pdf_path = '')
	{
		if (method_exists($this->provider, 'convertInvoiceToFacturX')) {
			return $this->provider->convertInvoiceToFacturX($en_invoice, $pdf_path);
		}
		$this->error = "La conversion de facture n'est pas supportee par ce fournisseur.";
		return false;
	}

	/**
	 * Get single invoice details by ID
	 *
	 * @param	string	$id		PDP technical ID
	 * @return	array|bool		Invoice data or false
	 */
	public function getInvoice($id)
	{
		return $this->provider->getInvoice($id);
	}

	/**
	 * Send customer invoice to the provider network (Backward Compatibility wrapper)
	 *
	 * @param	string	$file_content	Raw PDF binary content
	 * @param	string	$external_id	Local Invoice Reference
	 * @return	array|bool				Response array or false
	 */
	public function sendFacturXInvoice($file_content, $external_id)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$invoice_obj = new Facture($this->db);
		if ($invoice_obj->fetch(0, $external_id) > 0) {
			$invoice_obj->fetch_thirdparty();
			$res = $this->provider->sendInvoice($invoice_obj, $file_content);
			if ($res) {
				return array('id' => $res);
			}
			return false;
		}
		$this->error = "Impossible de charger la facture locale " . $external_id;
		return false;
	}

	/**
	 * Send an invoice event (e.g. payment received or sent) to PDP (Backward Compatibility wrapper)
	 *
	 * @param	string	$pdp_id			PDP Technical ID
	 * @param	string	$status_code	Event status code (e.g. 'fr:212', 'fr:211')
	 * @param	array	$details		Optional details array
	 * @return	array|bool				Response array or false
	 */
	public function sendInvoiceEvent($pdp_id, $status_code, $details = null)
	{
		if (method_exists($this->provider, 'sendInvoiceEvent')) {
			return $this->provider->sendInvoiceEvent($pdp_id, $status_code, $details);
		}

		// Fallback for providers like FactPulse that use direct object payments
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		
		$invoice_obj = null;
		
		// Check supplier invoices
		$sql = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "facture_fourn_extrafields WHERE facturelect_invoice_id = '" . $this->db->escape($pdp_id) . "'";
		$res = $this->db->query($sql);
		if ($res && $this->db->num_rows($res) > 0) {
			$row = $this->db->fetch_object($res);
			$invoice_obj = new FactureFournisseur($this->db);
			$invoice_obj->fetch($row->fk_object);
		} else {
			// Check customer invoices
			$sql = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "facture_extrafields WHERE facturelect_invoice_id = '" . $this->db->escape($pdp_id) . "'";
			$res = $this->db->query($sql);
			if ($res && $this->db->num_rows($res) > 0) {
				$row = $this->db->fetch_object($res);
				$invoice_obj = new Facture($this->db);
				$invoice_obj->fetch($row->fk_object);
			}
		}
		
		if ($invoice_obj) {
			$amount = 0.0;
			if (!empty($details) && is_array($details)) {
				if (isset($details[0]['amounts'][0]['amount'])) {
					$amount = floatval($details[0]['amounts'][0]['amount']);
				}
			}
			if ($amount == 0.0) {
				$amount = $invoice_obj->total_ttc;
			}
			
			$res = $this->provider->sendPaymentEvent($invoice_obj, $amount, time(), $details);
			if ($res) {
				return array('id' => $res);
			}
			return false;
		}
		
		$this->error = "Aucune facture trouvee pour l'ID PDP " . $pdp_id;
		return false;
	}

	/**
	 * List incoming invoices from the provider
	 *
	 * @return	array|bool						List of invoices or false on error
	 */
	public function listIncomingInvoices()
	{
		return $this->provider->listIncomingInvoices();
	}

	/**
	 * Synchronize/Import incoming supplier invoices
	 *
	 * @param	array|null		$specific_ids	Optional array of technical IDs to import selectively
	 * @return	int|bool						Number of imported invoices or false
	 */
	public function syncIncomingInvoices($specific_ids = null)
	{
		return $this->provider->syncIncomingInvoices($specific_ids);
	}

	/**
	 * Method executed by cron job to pull incoming invoices
	 *
	 * @return	int		0 if OK, <0 if KO
	 */
	public function syncIncomingInvoicesCron()
	{
		dol_syslog("FacturelectClient::syncIncomingInvoicesCron started", LOG_DEBUG);
		$result = $this->syncIncomingInvoices();
		if ($result === false) {
			dol_syslog("FacturelectClient::syncIncomingInvoicesCron error", LOG_ERR);
			return -1;
		}
		dol_syslog("FacturelectClient::syncIncomingInvoicesCron success: " . $result . " invoices synced", LOG_DEBUG);
		return 0;
	}

	/**
	 * Get the display name of the active provider
	 *
	 * @return	string
	 */
	public function getProviderName()
	{
		return $this->provider->getName();
	}
}
