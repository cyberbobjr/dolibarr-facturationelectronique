<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/class/facturelecttransmissionfields.class.php';

/**
 * Unit tests for the reset of the transmission extrafields of a new customer invoice.
 *
 * When Dolibarr creates the next situation invoice (or a clone), it copies the extrafields
 * of the source invoice. The new draft must not inherit its PDP ID, status or send date (#34).
 */
class FacturelectTransmissionFieldsTest extends TestCase
{
	/**
	 * Fields copied from an already transmitted invoice are reset to their defaults.
	 */
	public function testResetsFieldsCopiedFromTransmittedInvoice()
	{
		$invoice = new stdClass();
		$invoice->array_options = array(
			'options_facturelect_invoice_id' => 'inv_12345',
			'options_facturelect_status' => 'transmitted',
			'options_facturelect_send_date' => 1767225600,
		);

		$changed = FacturelectTransmissionFields::reset($invoice);

		$this->assertSame(array('facturelect_invoice_id', 'facturelect_status', 'facturelect_send_date'), $changed);
		$this->assertSame('', $invoice->array_options['options_facturelect_invoice_id']);
		$this->assertSame('not_sent', $invoice->array_options['options_facturelect_status']);
		$this->assertSame('', $invoice->array_options['options_facturelect_send_date']);
	}

	/**
	 * Other extrafields (e.g. the VAT exemption reason) are business data and are kept.
	 */
	public function testKeepsOtherExtrafields()
	{
		$invoice = new stdClass();
		$invoice->array_options = array(
			'options_facturelect_invoice_id' => 'inv_12345',
			'options_facturelect_vatex_code' => 'VATEX-EU-AE',
			'options_facturelect_vatex_reason' => 'Autoliquidation',
		);

		FacturelectTransmissionFields::reset($invoice);

		$this->assertSame('VATEX-EU-AE', $invoice->array_options['options_facturelect_vatex_code']);
		$this->assertSame('Autoliquidation', $invoice->array_options['options_facturelect_vatex_reason']);
	}

	/**
	 * An invoice already at the defaults needs no database update.
	 */
	public function testNothingToPersistWhenAlreadyAtDefaults()
	{
		$invoice = new stdClass();
		$invoice->array_options = array(
			'options_facturelect_invoice_id' => '',
			'options_facturelect_status' => 'not_sent',
			'options_facturelect_send_date' => null,
		);

		$this->assertSame(array(), FacturelectTransmissionFields::reset($invoice));
	}

	/**
	 * An invoice without any extrafield gets the default status.
	 */
	public function testInvoiceWithoutExtrafieldsGetsDefaultStatus()
	{
		$invoice = new stdClass();

		$changed = FacturelectTransmissionFields::reset($invoice);

		$this->assertSame(array('facturelect_status'), $changed);
		$this->assertSame('not_sent', $invoice->array_options['options_facturelect_status']);
	}
}
