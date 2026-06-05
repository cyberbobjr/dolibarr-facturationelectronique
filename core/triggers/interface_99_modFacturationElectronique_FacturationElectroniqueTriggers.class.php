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
 *  \file       htdocs/custom/facturationelectronique/core/triggers/interface_99_modFacturationElectronique_FacturationElectroniqueTriggers.class.php
 *  \ingroup    facturation_electronique
 *  \brief      Trigger to automatically report payment events to SuperPDP
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
if (!class_exists('FacturelectClient')) {
	require_once dirname(dirname(dirname(__FILE__))).'/class/facturelectclient.class.php';
}

/**
 *  Class of triggers for FacturationElectronique module
 */
class InterfaceFacturationElectroniqueTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		parent::__construct($db);
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->description = "Trigger automatique pour declarer les reglements et paiements de factures au PDP.";
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'technic';
		$this->family = 'facturationelectronique';
	}

	/**
	 *  Function called when a Dolibarr business event is done.
	 *
	 *  @param string       $action     Event action code
	 *  @param Object       $object     Object
	 *  @param User         $user       Object user
	 *  @param Translate    $langs      Object langs
	 *  @param conf         $conf       Object conf
	 *  @return int                     if KO: <0 || if no trigger ran: 0 || if OK: >0
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (empty($conf->facturationelectronique->enabled)) {
			return 0;
		}

		// 1. Customer Payment Created (Sales Invoices / Outbound)
		if ($action === 'PAYMENT_CUSTOMER_CREATE') {
			dol_syslog("FacturationElectroniqueTriggers running for action ".$action, LOG_DEBUG);

			if (is_object($object) && !empty($object->amounts)) {
				require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
				$client = new FacturelectClient($this->db);

				foreach ($object->amounts as $facid => $amount_paid) {
					if ($amount_paid <= 0) {
						continue;
					}

					$invoice = new Facture($this->db);
					if ($invoice->fetch($facid) > 0) {
						$invoice->fetch_optionals();
						$pdp_id = !empty($invoice->array_options['options_facturelect_invoice_id']) ? $invoice->array_options['options_facturelect_invoice_id'] : '';

						if (!empty($pdp_id)) {
							dol_syslog("FacturationElectroniqueTriggers: Customer Invoice ID ".$facid." has PDP ID ".$pdp_id.". Reporting payment...", LOG_INFO);

							// Compute VAT breakdown details for the payment
							$details = $this->buildPaymentDetails($invoice, $amount_paid, $object->datepaye, 'MEN');

							$res = $client->sendInvoiceEvent($pdp_id, 'fr:212', $details);
							if ($res === false) {
								dol_syslog("FacturationElectroniqueTriggers error: Failed to report payment for invoice ".$facid.". ".$client->error, LOG_ERR);
							} else {
								dol_syslog("FacturationElectroniqueTriggers success: Payment received event fr:212 sent for invoice ".$facid, LOG_INFO);
							}
						}
					}
				}
				return 1;
			}
		}

		// 2. Supplier Payment Created (Purchase Invoices / Inbound)
		if ($action === 'PAYMENT_SUPPLIER_CREATE') {
			dol_syslog("FacturationElectroniqueTriggers running for action ".$action, LOG_DEBUG);

			if (is_object($object) && !empty($object->amounts)) {
				require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
				$client = new FacturelectClient($this->db);

				foreach ($object->amounts as $facid => $amount_paid) {
					if ($amount_paid <= 0) {
						continue;
					}

					$invoice = new FactureFournisseur($this->db);
					if ($invoice->fetch($facid) > 0) {
						$invoice->fetch_optionals();
						$pdp_id = !empty($invoice->array_options['options_facturelect_invoice_id']) ? $invoice->array_options['options_facturelect_invoice_id'] : '';

						if (!empty($pdp_id)) {
							dol_syslog("FacturationElectroniqueTriggers: Supplier Invoice ID ".$facid." has PDP ID ".$pdp_id.". Reporting payment...", LOG_INFO);

							// Compute VAT breakdown details for the payment
							$details = $this->buildPaymentDetails($invoice, $amount_paid, $object->datepaye, 'MPA');

							$res = $client->sendInvoiceEvent($pdp_id, 'fr:211', $details);
							if ($res === false) {
								dol_syslog("FacturationElectroniqueTriggers error: Failed to report payment for invoice ".$facid.". ".$client->error, LOG_ERR);
							} else {
								dol_syslog("FacturationElectroniqueTriggers success: Payment sent event fr:211 sent for invoice ".$facid, LOG_INFO);
							}
						}
					}
				}
				return 1;
			}
		}

		return 0;
	}

	/**
	 * Build payment event details with VAT breakdown proportional split
	 *
	 * @param CommonObject $invoice      Invoice object (Facture or FactureFournisseur)
	 * @param float        $amount_paid  Amount allocated to this invoice (TTC)
	 * @param int          $payment_date Unix timestamp of payment date
	 * @param string       $type_code    'MEN' (sales) or 'MPA' (purchase)
	 * @return array                     Details payload for SuperPDP API
	 */
	private function buildPaymentDetails($invoice, $amount_paid, $payment_date, $type_code)
	{
		$invoice->fetch_lines();

		// Group invoice lines by VAT rate and calculate total TTC per rate
		$vat_groups = array();
		$invoice_total_ttc = 0.0;

		foreach ($invoice->lines as $line) {
			$line_ht = floatval($line->total_ht);
			$line_tva = floatval($line->total_tva);
			$line_ttc = $line_ht + $line_tva;

			$vat_rate = sprintf("%.2f", floatval($line->tva_tx));

			if (!isset($vat_groups[$vat_rate])) {
				$vat_groups[$vat_rate] = 0.0;
			}
			$vat_groups[$vat_rate] += $line_ttc;
			$invoice_total_ttc += $line_ttc;
		}

		$amounts = array();
		$formatted_date = date('Y-m-d', $payment_date ? $payment_date : time());

		if ($invoice_total_ttc > 0) {
			foreach ($vat_groups as $vat_rate => $group_ttc) {
				// Proportional calculation of the payment amount for this VAT category
				$ratio = $group_ttc / $invoice_total_ttc;
				$split_amount = $amount_paid * $ratio;

				$amounts[] = array(
					'currency_code' => 'EUR',
					'date' => $formatted_date,
					'net_amount' => sprintf("%.2f", $split_amount),
					'type_code' => $type_code,
					'vat_rate' => sprintf("%.1f", floatval($vat_rate))
				);
			}
		} else {
			// Fallback to simple allocation if invoice total is zero or negative
			$amounts[] = array(
				'currency_code' => 'EUR',
				'date' => $formatted_date,
				'net_amount' => sprintf("%.2f", $amount_paid),
				'type_code' => $type_code,
				'vat_rate' => '20.0' // Default fallback rate
			);
		}

		return array(
			array(
				'amounts' => $amounts
			)
		);
	}
}
