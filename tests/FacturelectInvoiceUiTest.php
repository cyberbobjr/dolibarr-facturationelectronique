<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/class/facturelectrouting.class.php';

if (!defined('DOL_URL_ROOT')) { define('DOL_URL_ROOT', ''); }
if (!function_exists('dol_buildpath')) {
	/** @param string $path Module path @param int $mode URL mode @return string URL */
	function dol_buildpath($path, $mode = 1) { return '/custom'.$path; }
}
if (!function_exists('dol_escape_htmltag')) {
	/** @param string $value Text @return string Escaped text */
	function dol_escape_htmltag($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('dol_escape_js')) {
	/** @param string $value Text @return string Escaped JavaScript string */
	function dol_escape_js($value) { return substr(json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), 1, -1); }
}
if (!function_exists('newToken')) {
	/** @return string Test-only CSRF token */
	function newToken() { return 'ui-test-token'; }
}

/** Authorized invoice controls and diagnostic content, without network access. */
class FacturelectInvoiceUiTest extends TestCase
{
	/** @return void */
	public function testTransmissionButtonsUseStandalonePostFormWithoutUrlToken()
	{
		global $user;
		$user = (object) array('admin' => true, 'socid' => 0);
		$invoice = (object) array('id' => 45, 'statut' => 1);
		$button = FacturelectDiagnostic::sendButton($invoice, 'Send', 'butAction', 'fe-send-btn');
		$form = FacturelectDiagnostic::sendForm($invoice);
		$doc = new DOMDocument();
		$doc->loadHTML('<div>'.$button.'</div>'.$form);
		$xpath = new DOMXPath($doc);
		$this->assertSame('fe-transmit-45', $xpath->query('//button')->item(0)->getAttribute('form'));
		$this->assertSame('post', $xpath->query('//form')->item(0)->getAttribute('method'));
		$this->assertSame('/custom/compta/facture/card.php', $xpath->query('//form')->item(0)->getAttribute('action'));
		$this->assertSame('ui-test-token', $xpath->query('//input[@name="token"]')->item(0)->getAttribute('value'));
		$this->assertSame(0, $xpath->query('//a | //form/form')->length);
		$invoice->statut = 3;
		$this->assertSame('', FacturelectDiagnostic::sendButton($invoice, 'Send', 'butAction'));
	}

	/** @param string $template Template name @param bool $admin Administrator @param bool $editable Editable address @param bool $thirdparty Third-party control @param string $override Invoice override @return string Rendered HTML */
	private function render($template, $admin, $editable = true, $thirdparty = false, $override = '')
	{
		$object = (object) array('id' => 44, 'socid' => 8, 'array_options' => array());
		$object->array_options['options_facturelect_id'] = '200058485_20005848500018_SI';
		$object->thirdparty = (object) array('array_options' => $object->array_options);
		$object->array_options['options_facturelect_buyer_address'] = $override;
		$fe_address_is_thirdparty = $thirdparty;
		$user = (object) array('admin' => $admin);
		$langs = new class extends Translate {
			/** @param string $key Translation key @return string Plain translation */
			public function transnoentities($key) { return $key; }
		};
		$fe_can_edit_buyer_address = $editable;
		$fe_diagnostic_current = array('provider' => 'SuperPDP', 'mode' => 'sandbox', 'routing' => array(
			'name' => 'Test buyer', 'siren' => '200058485', 'siret' => '', 'scheme' => '0225', 'identifier' => '200058485_20005848500018_SI', 'source' => 'associated'));
		$fe_diagnostic_checks = array();
		$fe_diagnostic_attempts = array(array('log_id' => 1, 'at' => '2026-10-09T11:38:34+02:00', 'provider' => 'SuperPDP', 'mode' => 'sandbox',
			'routing' => $fe_diagnostic_current['routing'], 'payload' => array('notes' => 'PRIVATE_PAYLOAD_ONLY_FOR_ADMIN'),
			'steps' => array(array('stage' => 'deposit', 'at' => '2026-10-09T11:38:34+02:00', 'ok' => false,
				'http' => array('status' => 400), 'error' => 'receiver address does not exist in peppol directory'))));
		ob_start();
		require dirname(__DIR__).'/tpl/'.$template.'.tpl.php';
		return ob_get_clean();
	}

	/** @return void */
	public function testAddressFormTargetsMainInvoiceAndReadonlyHasNoForm()
	{
		$editable = str_replace('\\/', '/', $this->render('buyer_address', true));
		$this->assertStringContainsString('/compta/facture/card.php?id=44', $editable);
		$this->assertStringNotContainsString('invoice_facturelect_tab.php', $editable);
		$this->assertStringContainsString('save_buyer_address', $editable);
		$this->assertStringContainsString('ui-test-token', $editable);
		$this->assertStringNotContainsString('save_buyer_address', $this->render('buyer_address', false, false));
	}

	/** @return void */
	public function testCopiedDiagnosticContainsOnlyContentAuthorizedForViewer()
	{
		$regular = $this->render('diagnostic_panel', false);
		$this->assertStringContainsString('fe-diagnostic-copy', $regular);
		$this->assertStringContainsString('400', $regular);
		$this->assertStringContainsString('200058485_20005848500018_SI', $regular);
		$this->assertStringNotContainsString('PRIVATE_PAYLOAD_ONLY_FOR_ADMIN', $regular);
		$this->assertStringContainsString('PRIVATE_PAYLOAD_ONLY_FOR_ADMIN', $this->render('diagnostic_panel', true));
	}
	/** @return void */
	public function testThirdpartyControlTargetsItsCardAndInvoiceNamesItsDefault()
	{
		$tier = str_replace('\/', '/', $this->render('buyer_address', true, true, true));
		$this->assertStringContainsString('/societe/card.php?socid=44', $tier);
		$this->assertStringContainsString('save_thirdparty_address', $tier);
		$this->assertStringContainsString('FacturelectThirdpartyAddress', $tier);
		$this->assertStringContainsString('.societe_extras_facturelect_id', $tier);
		$invoice = $this->render('buyer_address', true);
		$this->assertStringContainsString('FacturelectBuyerAddressDefaultValue', $invoice);
		$this->assertStringContainsString('.facture_extras_facturelect_invoice_id', $invoice);
		$this->assertStringNotContainsString("card.insertAdjacentHTML('afterbegin'", $invoice);
	}

	/** @param string $rendered Deferred script @return DOMXPath Parsed injected row */
	private function rowDocument($rendered)
	{
		preg_match('/row\.insertAdjacentHTML\(\'beforebegin\', (".*")\);/', $rendered, $match);
		$html = json_decode($match[1], true);
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$document->loadHTML('<table><tbody>'.$html.'</tbody></table>');
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return new DOMXPath($document);
	}

	/** @return void */
	public function testInvoiceShowsSavedAddressAndEditsOnlyInModal()
	{
		$row = $this->rowDocument($this->render('buyer_address', true));
		$this->assertSame(1, $row->query('//*[@id="fe-buyer-address-display"]')->length);
		$this->assertSame('0225:200058485_20005848500018_SI', $row->query('//*[@id="fe-buyer-address-display"]')->item(0)->textContent);
		$this->assertSame(0, $row->query('//tr[@id="fe-buyer-address-row"]/td/form | //tr[@id="fe-buyer-address-row"]/td/select')->length);
		$this->assertSame(1, $row->query('//button[@id="fe-buyer-address-edit"]')->length);
		$this->assertSame(1, $row->query('//dialog[@id="fe-buyer-address-dialog"]//select[@name="buyer_address"]')->length);
		$this->assertSame('button', $row->query('//*[@id="fe-buyer-address-cancel"]')->item(0)->getAttribute('type'));
		$this->assertSame('save_buyer_address', $row->query('//dialog//input[@name="action"]')->item(0)->getAttribute('value'));
		$this->assertSame(0, $row->query('//dialog//input[@value="save_thirdparty_address"]')->length);
		$override = '0225:200058485_20005848500018_RH';
		$changed = $this->rowDocument($this->render('buyer_address', true, true, false, $override));
		$this->assertSame($override, $changed->query('//*[@id="fe-buyer-address-display"]')->item(0)->textContent);
		$readonly = $this->rowDocument($this->render('buyer_address', false, false));
		$this->assertSame(0, $readonly->query('//dialog | //button[@id="fe-buyer-address-edit"]')->length);
	}

}
