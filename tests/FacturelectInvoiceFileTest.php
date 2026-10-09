<?php
/**
 * Tests for the readable PDF in incoming invoice files.
 */
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/class/facturelectinvoicefile.class.php';

class FacturelectInvoiceFileTest extends TestCase
{
	private const PDF = "%PDF-1.3\nreadable invoice\n%%EOF";

	/** @return void */
	public function testDirectPdfIsPreserved()
	{
		$this->assertSame(self::PDF, FacturelectInvoiceFile::readablePdf(self::PDF));
	}

	/** @return void */
	public function testUblReadableAttachmentIsDecodedWithWhitespace()
	{
		$xml = $this->invoice($this->attachment(chunk_split(base64_encode(self::PDF)), 'LISIBLE'));
		$this->assertSame(self::PDF, FacturelectInvoiceFile::readablePdf($xml));
	}

	/** @return void */
	public function testReadablePdfWinsOverAnotherPdfAttachment()
	{
		$xml = $this->invoice($this->attachment(base64_encode('%PDF-1.4 annex'), 'Annexe')
			.$this->attachment(base64_encode(self::PDF), 'LISIBLE'));
		$this->assertSame(self::PDF, FacturelectInvoiceFile::readablePdf($xml));
	}

	/** @return void */
	public function testSinglePdfWithoutReadableLabelIsAccepted()
	{
		$this->assertSame(self::PDF, FacturelectInvoiceFile::readablePdf(
			$this->invoice($this->attachment(base64_encode(self::PDF), ''))));
	}

	/** @return void */
	public function testNonPdfReadableAttachmentMustNotFallBackToAnAnnex()
	{
		foreach (array('text/plain', '') as $mime) {
			$xml = $this->invoice($this->attachment(base64_encode(self::PDF), 'LISIBLE', $mime)
				.$this->attachment(base64_encode(self::PDF), 'Annexe'));
			$this->assertFalse(FacturelectInvoiceFile::readablePdf($xml));
		}
	}

	/** @return void */
	public function testNonPdfAnnexDoesNotPreventReadablePdfDownload()
	{
		$xml = $this->invoice($this->attachment(base64_encode('annex'), 'Annexe', 'text/plain')
			.$this->attachment(base64_encode(self::PDF), 'LISIBLE'));
		$this->assertSame(self::PDF, FacturelectInvoiceFile::readablePdf($xml));
	}

	/** @return void */
	public function testUnusableOrAmbiguousDocumentsAreRejected()
	{
		foreach (array('', '<broken', '{"error":"failed"}', $this->invoice(''),
			$this->invoice($this->attachment('not base64!', 'LISIBLE')),
			$this->invoice($this->attachment(base64_encode('not a PDF'), 'LISIBLE')),
			$this->invoice($this->attachment('invalid!', 'LISIBLE')
				.$this->attachment(base64_encode(self::PDF), 'Annexe')),
			$this->invoice($this->attachment(base64_encode(self::PDF), 'Annex A')
				.$this->attachment(base64_encode(self::PDF), 'Annex B')),
			$this->invoice($this->attachment(base64_encode(self::PDF), '', 'text/plain')),
			'<!DOCTYPE Invoice [<!ENTITY secret SYSTEM "file:///etc/passwd">]>'
				.$this->invoice($this->attachment('&secret;', 'LISIBLE'))
		) as $xml) {
			$this->assertFalse(FacturelectInvoiceFile::readablePdf($xml));
		}
	}

	/** @return void */
	public function testUblCreditNotePdfIsDecoded()
	{
		$xml = str_replace(array('Invoice-2', '<Invoice ', '</Invoice>'),
			array('CreditNote-2', '<CreditNote ', '</CreditNote>'),
			$this->invoice($this->attachment(base64_encode(self::PDF), 'LISIBLE')));
		$this->assertSame(self::PDF, FacturelectInvoiceFile::readablePdf($xml));
	}

	/** @param string $attachments Attachments @return string */
	private function invoice($attachments)
	{
		return '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
			.' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
			.' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
			.$attachments.'</Invoice>';
	}

	/** @param string $data Base64 @param string $label Label @param string $mime MIME @return string */
	private function attachment($data, $label, $mime = 'application/pdf')
	{
		return '<cac:AdditionalDocumentReference><cbc:DocumentDescription>'.$label.'</cbc:DocumentDescription>'
			.'<cac:Attachment><cbc:EmbeddedDocumentBinaryObject mimeCode="'.$mime.'">'.$data
			.'</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>';
	}
}
