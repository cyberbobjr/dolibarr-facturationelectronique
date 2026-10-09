<?php
/**
 * Readable PDF extraction from incoming network invoice files.
 */
class FacturelectInvoiceFile
{
	/**
	 * Return the original PDF or the readable PDF embedded in a UBL invoice.
	 *
	 * @param string $content Original invoice file
	 * @return string|false PDF bytes, or false when no unambiguous PDF is available
	 */
	public static function readablePdf($content)
	{
		if (substr($content, 0, 5) === '%PDF-') {
			return $content;
		}
		if ($content === '' || stripos($content, '<!DOCTYPE') !== false) {
			return false;
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$xml = new DOMDocument();
			// Never load DTDs or expand entities from provider-supplied XML.
			if (!$xml->loadXML($content, LIBXML_NONET) || $xml->doctype !== null) {
				return false;
			}
			$xpath = new DOMXPath($xml);
			$xpath->registerNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
			$xpath->registerNamespace('credit', 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2');
			$xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
			$xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
			$attachments = $xpath->query('/*[self::inv:Invoice or self::credit:CreditNote]/cac:AdditionalDocumentReference'
				.'/cac:Attachment/cbc:EmbeddedDocumentBinaryObject[@mimeCode="application/pdf"]');
			$pdfs = array();
			$readable = array();
			foreach ($attachments as $attachment) {
				$label = $xpath->evaluate('string(cbc:DocumentDescription)', $attachment->parentNode->parentNode);
				$is_readable = strtoupper(trim($label)) === 'LISIBLE';
				$pdf = base64_decode(preg_replace('/\s+/', '', $attachment->textContent), true);
				if ($pdf === false || substr($pdf, 0, 5) !== '%PDF-') {
					if ($is_readable) {
						return false;
					}
					continue;
				}
				$pdfs[] = $pdf;
				if ($is_readable) {
					$readable[] = $pdf;
				}
			}
			if (count($readable) === 1) {
				return $readable[0];
			}
			return count($pdfs) === 1 ? $pdfs[0] : false;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}
}
