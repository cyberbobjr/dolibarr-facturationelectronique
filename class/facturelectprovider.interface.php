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
 *	\file       htdocs/custom/facturationelectronique/class/facturelectprovider.interface.php
 *	\ingroup    facturationelectronique
 *	\brief      Interface for Facturation Electronique Providers
 */

interface FacturelectProvider
{
	/**
	 * Check provider connection session
	 *
	 * @return	array|bool		Session data array or false on error
	 */
	public function checkSession();

	/**
	 * Get detailed invoice status and event logs
	 *
	 * @param	string	$pdp_id		PDP Technical ID
	 * @return	array|bool			Invoice details or false on error
	 */
	public function getInvoice($pdp_id);

	/**
	 * Send customer invoice to the provider network
	 *
	 * @param	Facture	$invoice_obj	Dolibarr invoice object
	 * @param	string	$pdf_content	Binary PDF content
	 * @return	string|bool				PDP technical ID or false on error
	 */
	public function sendInvoice($invoice_obj, $pdf_content);

	/**
	 * Send payment event (e-reporting) to the provider
	 *
	 * @param	CommonObject	$invoice_obj	Dolibarr invoice or supplier invoice object
	 * @param	float			$payment_amount	Amount paid
	 * @param	int				$payment_date	Payment Unix timestamp
	 * @param	array			$details		VAT details breakdown
	 * @return	string|bool						Event technical ID or false on error
	 */
	public function sendPaymentEvent($invoice_obj, $payment_amount, $payment_date, $details);

	/**
	 * List incoming invoices from the provider
	 *
	 * @return	array|bool						List of invoices or false on error
	 */
	public function listIncomingInvoices();

	/**
	 * Synchronize/Import incoming supplier invoices from the provider
	 *
	 * @param	array|null		$specific_ids	Array of specific PDP invoice IDs to sync, or null for all new invoices
	 * @return	int|bool						Number of invoices successfully synchronized or false on error
	 */
	public function syncIncomingInvoices($specific_ids = null);

	/**
	 * Get the display name of the provider
	 *
	 * @return	string							Provider display name
	 */
	public function getName();
}
