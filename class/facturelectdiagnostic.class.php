<?php
/* Copyright (C) 2026 Benjamin Marchand <ben.marchand@free.fr> */

require_once __DIR__.'/facturelectlog.class.php';
require_once __DIR__.'/facturelectpeppolid.class.php';

/**
 * Invoice-scoped transmission evidence, stored in the existing API audit table.
 */
class FacturelectDiagnostic
{
	/**
	 * Check internal invoice write permission and the explicit session CSRF token.
	 * @param object $user Current user
	 * @param string $token Submitted token
	 * @param array $tokens Session tokens
	 * @param string $method HTTP method
	 * @return bool Authorized transmission
	 */
	public static function canTransmit($user, $token, $tokens, $method)
	{
		return $method === 'POST' && empty($user->socid) && ($user->admin || !empty($user->rights->facture->creer))
			&& $token !== '' && in_array($token, array_filter($tokens), true);
	}

	/** @param object $invoice Invoice @return bool Validated or paid invoice */
	public static function canSendInvoice($invoice)
	{
		return in_array((int) ($invoice->statut ?? -1), array(1, 2), true);
	}

	/** @param object $invoice Invoice @param string $label Button label @param string $class CSS classes @param string $id Optional button ID @return string Button attached to an external POST form */
	public static function sendButton($invoice, $label, $class, $id = '')
	{
		global $user;
		if (!self::canSendInvoice($invoice) || !empty($user->socid) || !($user->admin || !empty($user->rights->facture->creer))) {
			return '';
		}
		return '<button type="submit" form="fe-transmit-'.((int) $invoice->id).'" class="'.dol_escape_htmltag($class).'"'.($id !== '' ? ' id="'.dol_escape_htmltag($id).'"' : '').'><span class="fa fa-paper-plane paddingrightonly"></span> '.dol_escape_htmltag($label).'</button>';
	}

	/** @param object $invoice Invoice @return string Standalone POST form rendered outside native forms */
	public static function sendForm($invoice)
	{
		return '<form id="fe-transmit-'.((int) $invoice->id).'" method="post" action="'.dol_buildpath('/compta/facture/card.php', 1).'"><input type="hidden" name="id" value="'.((int) $invoice->id).'"><input type="hidden" name="action" value="send_facturelect"><input type="hidden" name="token" value="'.dol_escape_htmltag(newToken()).'"></form>';
	}

	/**
	 * Read recent matching evidence without calling the provider during page rendering.
	 * @param object $db Database
	 * @param object $invoice Invoice with loaded buyer
	 * @param object $client Provider client (name only)
	 * @return array|null Cached verdict, or no recent matching evidence
	 */
	public static function cachedRoutingVerdict($db, $invoice, $client)
	{
		$current = self::context($invoice, $client);
		$records = array_merge(self::load($db, $invoice, 'check') ?: array(), self::load($db, $invoice, 'send') ?: array());
		usort($records, function ($a, $b) { return strcmp($b['at'] ?? '', $a['at'] ?? ''); });
		foreach ($records as $record) {
			$at = strtotime($record['at'] ?? '');
			if (!$at || $at < time() - 300 || $at > time() || ($record['provider'] ?? '') !== $current['provider']
				|| ($record['mode'] ?? '') !== $current['mode'] || ($record['routing']['siren'] ?? '') !== $current['routing']['siren']
				|| FacturelectRouting::normalize($record['routing']['identifier'] ?? '', $record['routing']['scheme'] ?? '') !== FacturelectRouting::normalize($current['routing']['identifier'], $current['routing']['scheme'])) {
				continue;
			}
			if (!empty($record['routing_check']) && $record['routing_check']['status'] !== 'skipped') {
				return $record['routing_check'];
			}
			if (isset($record['directory']['entries']) && in_array($record['directory']['status'], array('active', 'not_active', 'empty'), true)) {
				$entries = array_map(function ($entry) { return array('identifier' => $entry['identifier'], 'is_active' => $entry['active']); }, $record['directory']['entries']);
				return FacturelectRouting::evaluate($current['routing']['identifier'], $current['routing']['scheme'], $current['routing']['siren'], $entries);
			}
		}
		return null;
	}

	/**
	 * Resolve exactly the address used by the invoice payload.
	 *
	 * @param object $thirdparty Loaded third party, including extrafields
	 * @param object|null $invoice Invoice holding an optional full buyer address
	 * @return array Routing and legal identifiers
	 */
	public static function routing($thirdparty, $invoice = null)
	{
		$options = $thirdparty->array_options ?? array();
		$siren = preg_replace('/\s+/', '', (string) ($thirdparty->idprof1 ?? ''));
		$associated = !empty($options['options_facturelect_id']);
		$identifier = $associated ? $options['options_facturelect_id'] : $siren;
		$scheme = !empty($options['options_facturelect_scheme']) ? $options['options_facturelect_scheme'] : '0225';
		$source = $associated ? 'associated' : 'siren';
		$invoice_address = trim((string) ($invoice->array_options['options_facturelect_buyer_address'] ?? ''));
		if ($invoice_address !== '') {
			$parsed = FacturelectPeppolId::parse($invoice_address);
			$identifier = $parsed['identifier'];
			$scheme = $parsed['scheme'];
			$source = 'invoice';
		}

		// Resolve a prefixed address once so the check and BT-49 share the same scheme/value.
		// Keep historical sandbox transformations unchanged.
		if (getDolGlobalString('FACTURATION_ELECTRONIQUE_MODE') === 'production') {
			$parsed = FacturelectPeppolId::parse($identifier, $scheme);
			$identifier = $parsed['identifier'];
			$scheme = $parsed['scheme'];
		}

		if (getDolGlobalString('FACTURATION_ELECTRONIQUE_MODE') !== 'production'
			&& getDolGlobalString('FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER') === 'superpdp') {
			if ($identifier === '000000001' || preg_match('/_000000001$/', $identifier)) {
				$identifier = '315143296_7181';
				$source .= '_sandbox';
			} elseif (preg_match('/^[0-9]{9}$|^[0-9]{14}$/', $identifier)) {
				$identifier = '315143296_7182_'.$identifier;
				$source .= '_sandbox';
			}
		}
		return array('siren' => $siren, 'siret' => (string) ($thirdparty->idprof2 ?? ''),
			'name' => (string) ($thirdparty->name ?? ''), 'scheme' => $scheme,
			'identifier' => $identifier, 'source' => $source);
	}

	/**
	 * Snapshot the current invoice and effective environment, without credentials.
	 *
	 * @param object $invoice Invoice
	 * @param object $client Provider client
	 * @return array Snapshot
	 */
	public static function context($invoice, $client)
	{
		if (method_exists($invoice, 'fetch_optionals') && empty($invoice->array_options)) {
			$invoice->fetch_optionals();
		}
		if (empty($invoice->thirdparty)) {
			$invoice->fetch_thirdparty();
		}
		if (!empty($invoice->thirdparty) && empty($invoice->thirdparty->array_options)) {
			$invoice->thirdparty->fetch_optionals();
		}
		return array('at' => date('c'), 'invoice_id' => (int) $invoice->id, 'reference' => $invoice->ref,
			'provider' => $client->getProviderName(),
			'mode' => getDolGlobalString('FACTURATION_ELECTRONIQUE_MODE') === 'production' ? 'production' : 'sandbox',
			'routing' => self::routing($invoice->thirdparty, $invoice));
	}

	/**
	 * Prepare, convert and deposit, retaining failures from both UI send paths.
	 *
	 * @param object $invoice Invoice
	 * @param object $actions Payload builder
	 * @param object $client Provider client
	 * @param object $langs Translations
	 * @return array Response, PDF and error for the existing caller
	 */
	public static function send($invoice, $actions, $client, $langs)
	{
		global $conf;
		if (!self::canSendInvoice($invoice)) {
			return array('response' => false, 'pdf' => false, 'error' => $langs->trans('FacturelectSendInvalidStatus'));
		}
		$record = self::context($invoice, $client);
		$record['steps'] = array();
		$result = array('response' => false, 'pdf' => false, 'error' => '');
		try {
			$verdict = $actions->checkBuyerRouting($invoice, $client);
			$record['routing_check'] = $verdict;
			if ($verdict['blocking']) {
				$result['error'] = FacturelectRouting::message($verdict, $langs);
				$record['steps'][] = array('stage' => 'routing', 'at' => date('c'), 'ok' => false, 'error' => $result['error']);
				return $result;
			}
			if (!in_array($verdict['status'], array('ok', 'skipped'), true) && function_exists('setEventMessages')) {
				setEventMessages(FacturelectRouting::message($verdict, $langs), null, 'warnings');
			}
			$payload = $actions->buildEnInvoiceJson($invoice);
			$record['payload'] = $payload;
			$record['steps'][] = array('stage' => 'prepare', 'at' => date('c'), 'ok' => (bool) $payload,
				'error' => $payload ? '' : $actions->error);
			if (!$payload) {
				$result['error'] = $actions->error;
				return $result;
			}
			// Store the actual payload address, even if a provider later transforms its PDF.
			$record['routing']['scheme'] = $payload['buyer']['electronic_address']['scheme'];
			$record['routing']['identifier'] = $payload['buyer']['electronic_address']['value'];
			$pdf_dir = $conf->facture->dir_output.'/'.dol_sanitizeFileName($invoice->ref);
			$pdf_file = $pdf_dir.'/'.dol_sanitizeFileName($invoice->ref).'.pdf';
			if (!file_exists($pdf_file)) {
				$invoice->generateDocument(!empty($invoice->model_pdf) ? $invoice->model_pdf : 'crabe', $langs);
			}
			$client->provider->lastHttpExchange = array();
			$result['pdf'] = $client->convertInvoiceToFacturX($payload, file_exists($pdf_file) ? $pdf_file : '');
			$record['steps'][] = array('stage' => 'convert', 'at' => date('c'), 'ok' => $result['pdf'] !== false,
				'error' => $result['pdf'] === false ? $client->error : '', 'http' => $client->provider->lastHttpExchange ?? array());
			if ($result['pdf'] === false) {
				$result['error'] = $client->error;
				return $result;
			}
			$client->provider->lastHttpExchange = array();
			$result['response'] = $client->sendFacturXInvoice($result['pdf'], $invoice->ref);
			$reused = false;
			if ($result['response'] === false && preg_match('/d[eé]j[aà] existante\s*\(id\s*(\d+)\)/ui', $client->error, $matches)) {
				$result['response'] = array('id' => $matches[1]);
				$reused = true;
				$actions->writeLog($invoice->ref, 'INFO', 'Recovered existing PDP invoice ID: '.$matches[1]);
			}
			$record['steps'][] = array('stage' => 'deposit', 'at' => date('c'), 'ok' => $result['response'] !== false, 'reused' => $reused,
				'error' => $result['response'] === false ? $client->error : '', 'http' => $client->provider->lastHttpExchange ?? array());
			$result['error'] = $result['response'] === false ? $client->error : '';
			$record['pdp_id'] = $result['response']['id'] ?? '';
			return $result;
		} catch (Throwable $e) {
			$record['steps'][] = array('stage' => 'interrupted', 'at' => date('c'), 'ok' => false, 'error' => $e->getMessage());
			throw $e;
		} finally {
			self::store($invoice->db, $invoice, 'send', $record);
		}
	}

	/**
	 * Check connection and French directory; never deposit an invoice.
	 *
	 * @param object $invoice Invoice
	 * @param object $client Provider client
	 * @return array Check evidence
	 */
	public static function check($invoice, $client)
	{
		$record = self::context($invoice, $client);
		$session = $client->checkSession();
		$record['connection'] = array('ok' => $session !== false, 'error' => $session === false ? $client->error : '');
		$record['directory'] = array('status' => 'unverified', 'entries' => array(), 'error' => '');
		if ($session !== false && FacturelectPeppolId::isSiren($record['routing']['siren'])) {
			$client->provider->lastHttpExchange = array();
			$res = $client->getCompanyEntries($record['routing']['siren']);
			$record['directory']['http'] = $client->provider->lastHttpExchange ?? array();
			if ($res === false || !isset($res['data']) || !is_array($res['data'])) {
				$record['directory']['status'] = 'error';
				$record['directory']['error'] = $res === false ? $client->error : 'Réponse annuaire inattendue : champ data absent ou invalide.';
			} else {
				$record['directory'] = array_merge($record['directory'], self::directory($res['data'], $record['routing']));
			}
		} elseif ($session !== false) {
			$record['directory']['error'] = 'Un SIREN à neuf chiffres est nécessaire pour consulter l’annuaire français.';
		}
		self::store($invoice->db, $invoice, 'check', $record);
		return $record;
	}

	/**
	 * Classify directory entries without treating technical return addresses as buyers.
	 *
	 * @param array $rows Directory rows
	 * @param array $routing Address being checked
	 * @return array Status and entries
	 */
	public static function directory($rows, $routing)
	{
		$entries = array();
		$matched = false;
		foreach ($rows as $row) {
			$parsed = FacturelectPeppolId::parse($row['identifier'] ?? '', $row['scheme'] ?? '');
			$technical = (bool) preg_match('/_replyto$/i', $parsed['identifier']);
			$active = ($row['is_active'] ?? null) === true;
			$matches = strcasecmp($parsed['scheme'], $routing['scheme']) === 0
				&& strcasecmp($parsed['identifier'], $routing['identifier']) === 0;
			$matched = $matched || ($matches && $active && !$technical);
			$entries[] = array('identifier' => $parsed['scheme'].':'.$parsed['identifier'],
				'label' => FacturelectPeppolId::describe($parsed), 'active' => $active,
				'technical' => $technical, 'matches' => $matches);
		}
		return array('status' => $matched ? 'active' : (empty($entries) ? 'empty' : 'not_active'), 'entries' => $entries);
	}

	/**
	 * Store one immutable snapshot in an entity- and invoice-specific audit scope.
	 *
	 * @param object $db Database
	 * @param object $invoice Invoice
	 * @param string $kind send or check
	 * @param array $record Snapshot
	 * @return int Log ID
	 */
	public static function store($db, $invoice, $kind, $record)
	{
		$scope = 'invoice-diagnostic:'.((int) $invoice->entity).':'.((int) $invoice->id).':'.$kind;
		$record = self::withoutSecrets($record);
		$provider = $record['provider'];
		$json = json_encode($record);
		$result = -1;
		if ($json !== false && strlen($json) > 40000) {
			// The audit table uses TEXT: split large snapshots instead of losing long invoices.
			$part_scope = $scope.':part:'.bin2hex(random_bytes(8));
			$parts = str_split($json, 20000);
			foreach ($parts as $part) {
				$result = FacturelectLog::log($db, $record['provider'], $part_scope, '', 'LOCAL', 0, null, array('part' => $part));
				if ($result < 0) {
					break;
				}
			}
			$record = array('parts_scope' => $part_scope, 'parts_count' => count($parts));
		} elseif ($json !== false) {
			$result = 0;
		}
		if ($result >= 0) {
			$result = FacturelectLog::log($db, $provider, $scope, '', 'LOCAL', 0, null, $record);
		}
		if ($result < 0) {
			dol_syslog('Invoice diagnostic could not be saved: '.$scope, LOG_ERR);
			if (function_exists('setEventMessages')) {
				setEventMessages('Le diagnostic n’a pas pu être enregistré. Consultez le journal d’audit du module.', null, 'warnings');
			}
		}
		return $result;
	}

	/**
	 * Retrieve the latest retained snapshots, scoped to the authorized invoice.
	 *
	 * @param object $db Database
	 * @param object $invoice Authorized invoice
	 * @param string $kind send or check
	 * @return array|false Records, or false on database error
	 */
	public static function load($db, $invoice, $kind)
	{
		$scope = 'invoice-diagnostic:'.((int) $invoice->entity).':'.((int) $invoice->id).':'.$kind;
		$sql = 'SELECT rowid, response_payload FROM '.MAIN_DB_PREFIX.'facturelect_log WHERE action = \''.$db->escape($scope).'\' ORDER BY rowid DESC';
		$sql .= $db->plimit($kind === 'check' ? 1 : 20);
		$res = $db->query($sql);
		if (!$res) {
			return false;
		}
		$records = array();
		while ($row = $db->fetch_object($res)) {
			$record = json_decode($row->response_payload, true);
			if (!is_array($record)) {
				return false;
			}
			if (isset($record['parts_scope'], $record['parts_count'])) {
				if (!preg_match('/^'.preg_quote($scope, '/').':part:[a-f0-9]{16}$/', $record['parts_scope'])) {
					return false;
				}
				$parts_result = $db->query('SELECT response_payload FROM '.MAIN_DB_PREFIX.'facturelect_log WHERE action = \''.$db->escape($record['parts_scope']).'\' ORDER BY rowid ASC');
				if (!$parts_result) {
					return false;
				}
				$json = '';
				$count = 0;
				while ($part_row = $db->fetch_object($parts_result)) {
					$part = json_decode($part_row->response_payload, true);
					$json .= $part['part'] ?? '';
					$count++;
				}
				$db->free($parts_result);
				if ($count !== (int) $record['parts_count']) {
					return false;
				}
				$record = json_decode($json, true);
				if (!is_array($record)) {
					return false;
				}
			}
			if (is_array($record)) {
				$record['log_id'] = (int) $row->rowid;
				$records[] = $record;
			}
		}
		$db->free($res);
		return $records;
	}

	/**
	 * Remove credential fields recursively before retaining provider JSON.
	 *
	 * @param mixed $value JSON-compatible value
	 * @return mixed Sanitized value
	 */
	public static function withoutSecrets($value)
	{
		if (!is_array($value)) {
			return is_string($value) ? preg_replace('/\bBearer\s+\S+/i', 'Bearer [masqué]', $value) : $value;
		}
		foreach ($value as $key => $item) {
			$value[$key] = preg_match('/token|secret|password|authorization|api.?key|client_id/i', (string) $key)
				? '[masqué]' : self::withoutSecrets($item);
		}
		return $value;
	}

	/**
	 * Allowlist a support report with no business identifiers, payload or free text.
	 *
	 * @param array $records Snapshots
	 * @return array Masked report
	 */
	public static function supportReport($records)
	{
		$report = array();
		foreach ($records as $record) {
			$item = array('at' => $record['at'], 'provider' => $record['provider'], 'mode' => $record['mode'],
				'address_source' => $record['routing']['source'], 'scheme' => $record['routing']['scheme']);
			foreach ($record['steps'] ?? array() as $step) {
				$item['steps'][] = array('stage' => $step['stage'], 'at' => $step['at'], 'ok' => $step['ok'],
					'http_status' => $step['http']['status'] ?? null,
					'endpoint' => $step['http']['path'] ?? '',
					'reason' => strpos($step['error'], 'does not exist in peppol directory') !== false ? 'peppol_address_missing' : ($step['ok'] ? '' : 'see_private_diagnostic'));
			}
			if (isset($record['directory'])) {
				$item['directory_status'] = $record['directory']['status'];
				$item['connection_ok'] = $record['connection']['ok'];
			}
			$report[] = $item;
		}
		return $report;
	}
}
