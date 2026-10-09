<?php
/**
 *	\file       htdocs/custom/facturationelectronique/class/facturelecttransmissionfields.class.php
 *	\ingroup    facturationelectronique
 *	\brief      Resets the PDP transmission extrafields of a newly created customer invoice
 */

/**
 * Pure, dependency-free helper for the customer invoice transmission extrafields.
 *
 * The PDP invoice ID, the transmission status and the transmission date describe what
 * happened to ONE invoice on the PDP. Dolibarr copies every extrafield of the source
 * invoice when it creates a new one from it (next situation invoice, clone, ...), so a
 * brand new draft would otherwise claim to be already transmitted under the PDP ID of
 * its predecessor (issue #34). A freshly created invoice has never been sent, so these
 * fields must always start from their defaults.
 */
class FacturelectTransmissionFields
{
	/**
	 * Default value of each transmission extrafield (without the 'options_' prefix).
	 */
	const DEFAULTS = array(
		'facturelect_invoice_id' => '',
		'facturelect_status' => 'not_sent',
		'facturelect_send_date' => '',
	);

	/**
	 * Reset the transmission extrafields of an invoice to their defaults, in memory only.
	 *
	 * @param	object		$object		Invoice object holding an array_options property
	 * @return	string[]				Extrafield keys whose value changed, to persist with updateExtraField()
	 */
	public static function reset($object)
	{
		if (!isset($object->array_options) || !is_array($object->array_options)) {
			$object->array_options = array();
		}

		$changed = array();
		foreach (self::DEFAULTS as $key => $default) {
			$current = isset($object->array_options['options_'.$key]) ? $object->array_options['options_'.$key] : '';
			if ((string) $current !== $default) {
				$object->array_options['options_'.$key] = $default;
				$changed[] = $key;
			}
		}
		return $changed;
	}
}
