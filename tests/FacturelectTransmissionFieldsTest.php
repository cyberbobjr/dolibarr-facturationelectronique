<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/class/facturelecttransmissionfields.class.php';

if (!class_exists('DolibarrTriggers')) {
	/** Minimal native trigger base for offline tests. */
	class DolibarrTriggers
	{
		const VERSIONS = array('prod' => 'test');
		public $error = '';
		public $name, $description, $version, $picto, $family;
		/** @param DoliDB $db Database @return void */
		public function __construct(DoliDB $db) {}
	}
}
require_once dirname(__DIR__).'/core/triggers/interface_99_modFacturationElectronique_FacturationElectroniqueTriggers.class.php';

/**
 * Unit tests for the reset of the transmission extrafields of a new customer invoice.
 *
 * When Dolibarr creates the next situation invoice (or a clone), it copies the extrafields
 * of the source invoice. The new draft must not inherit its PDP ID, status or send date (#34).
 */
class FacturelectTransmissionFieldsTest extends TestCase
{
	/** @return void */
	public function testTriggerFailsCreationWhenResetCannotBePersisted()
	{
		global $dolibarr_mock_globals;
		$dolibarr_mock_globals['FACTURELECT_FEATURE_EINVOICING'] = 0;
		$invoice = new class {
			public $element = 'facture';
			public $id = 45;
			public $error = 'Database write failed';
			public $array_options = array('options_facturelect_invoice_id' => 'source-id');
			public $calls = array();
			/** @param string $key Extrafield @return int Write result */
			public function updateExtraField($key) { $this->calls[] = $key; return -1; }
		};
		$trigger = new InterfaceFacturationElectroniqueTriggers(new DoliDB());
		$langs = new class extends Translate {
			/** @param string $domain Translation domain @return void */
			public function load($domain) {}
		};
		$this->assertLessThan(0, $trigger->runTrigger('BILL_CREATE', $invoice, new User(), $langs, new Conf()));
		$this->assertStringContainsString('FacturelectResetError', $trigger->error);
		$this->assertSame(array('facturelect_invoice_id'), $invoice->calls);
	}

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
