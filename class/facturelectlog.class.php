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
 *	\file       htdocs/custom/facturationelectronique/class/facturelectlog.class.php
 *	\ingroup    facturationelectronique
 *	\brief      Audit logging utility class for Facturation Electronique API transactions
 */
class FacturelectLog
{
	/**
	 * Log an API transaction to the audit database
	 *
	 * @param	DoliDB	$db					Database handler
	 * @param	string	$provider			Provider name (e.g. SuperPDP)
	 * @param	string	$action				Action or endpoint name
	 * @param	string	$url				URL requested
	 * @param	string	$method				HTTP method (GET, POST, etc.)
	 * @param	int		$http_status		HTTP status code
	 * @param	mixed	$request_payload	Request data (array, object, or string)
	 * @param	mixed	$response_payload	Response data (array, object, or string)
	 * @param	string	$error_message		Error message if any
	 * @return	int							Inserted log ID, or -1 on error
	 */
	public static function log($db, $provider, $action, $url, $method, $http_status, $request_payload, $response_payload, $error_message = '')
	{
		global $user;

		$date_creation = date('Y-m-d H:i:s');
		$fk_user = (!empty($user) && $user->id > 0) ? (int) $user->id : 0;

		// Format and truncate payloads if needed
		$req_str = self::formatPayload($request_payload);
		$resp_str = self::formatPayload($response_payload);

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "facturelect_log (";
		$sql .= " date_creation, provider, action, url, method, http_status, request_payload, response_payload, error_message, fk_user";
		$sql .= ") VALUES (";
		$sql .= " '" . $db->idate($date_creation) . "',";
		$sql .= " '" . $db->escape($provider) . "',";
		$sql .= " '" . $db->escape($action) . "',";
		$sql .= " '" . $db->escape($url) . "',";
		$sql .= " '" . $db->escape($method) . "',";
		$sql .= " " . ($http_status ? (int) $http_status : "NULL") . ",";
		$sql .= " " . ($req_str !== null ? "'" . $db->escape($req_str) . "'" : "NULL") . ",";
		$sql .= " " . ($resp_str !== null ? "'" . $db->escape($resp_str) . "'" : "NULL") . ",";
		$sql .= " " . ($error_message ? "'" . $db->escape($error_message) . "'" : "NULL") . ",";
		$sql .= " " . $fk_user;
		$sql .= ")";

		if ($db->query($sql)) {
			return $db->last_insert_id(MAIN_DB_PREFIX . "facturelect_log");
		}
		return -1;
	}

	/**
	 * Helper to format payload string with truncation for large files
	 *
	 * @param	mixed	$payload	Payload data
	 * @return	string|null
	 */
	private static function formatPayload($payload)
	{
		if (empty($payload)) {
			return null;
		}

		if (is_array($payload) || is_object($payload)) {
			return json_encode($payload);
		}

		$str = (string) $payload;
		
		// If payload is binary/PDF, detect and truncate
		if (strpos($str, '%PDF-') === 0 || strlen($str) > 10000 || !mb_check_encoding($str, 'UTF-8')) {
			if (strpos($str, '%PDF-') === 0) {
				return '<Binary PDF data: ' . strlen($str) . ' bytes>';
			}
			if (strpos($str, '<?xml') === 0) {
				if (strlen($str) > 10000) {
					return substr($str, 0, 10000) . "\n... [TRUNCATED " . (strlen($str) - 10000) . " BYTES]";
				}
				return $str;
			}
			return '<Large or Binary payload: ' . strlen($str) . ' bytes>';
		}

		return $str;
	}
}
