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
 *	\file       htdocs/custom/facturationelectronique/class/providers/baseprovider.class.php
 *	\ingroup    facturationelectronique
 *	\brief      Base Abstract class for Facturation Electronique Providers
 */

if (!interface_exists('FacturelectProvider')) {
	require_once dirname(__FILE__) . '/../facturelectprovider.interface.php';
}

abstract class BaseFacturelectProvider implements FacturelectProvider
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error message
	 */
	public $error = '';

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Send HTTP request helper using cURL
	 *
	 * @param	string			$url			Complete Request URL
	 * @param	string			$method			HTTP method (GET, POST, DELETE, etc.)
	 * @param	array			$headers		Request HTTP headers
	 * @param	array|string	$params			Request parameters (payload)
	 * @param	bool			$raw_data		True if raw string/binary fields, false if array
	 * @param	string			$mime_type		Mime type for request encoding
	 * @param	bool			$raw_response	True to return binary body instead of JSON parsing
	 * @return	array|string|bool				Parsed response array, raw body string, or false on error
	 */
	protected function sendHttpRequest($url, $method, $headers = array(), $params = null, $raw_data = false, $mime_type = 'application/json', $raw_response = false, $action = '')
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		$method = strtoupper($method);
		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, 1);
			if (!empty($params)) {
				if ($raw_data) {
					curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
				} else {
					if ($mime_type === 'application/json') {
						curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
					} else {
						curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
					}
				}
			}
		} elseif ($method === 'DELETE') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
		} elseif ($method !== 'GET') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			if (!empty($params)) {
				if ($raw_data) {
					curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
				} else {
					if ($mime_type === 'application/json') {
						curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
					} else {
						curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
					}
				}
			}
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_err = '';
		if ($response === false) {
			$curl_err = curl_error($ch);
		}
		curl_close($ch);

		// Helper to log audit trail
		$log_audit = function($err_msg = '') use ($url, $method, $http_code, $params, $response, $action) {
			if (!class_exists('FacturelectLog')) {
				require_once dirname(__FILE__) . '/../facturelectlog.class.php';
			}
			$provider_name = method_exists($this, 'getName') ? $this->getName() : 'Unknown';
			$action_name = $action;
			if (empty($action_name)) {
				$parsed = parse_url($url);
				$action_name = !empty($parsed['path']) ? $parsed['path'] : 'API Call';
			}
			FacturelectLog::log($this->db, $provider_name, $action_name, $url, $method, $http_code, $params, $response, $err_msg);
		};

		if ($response === false) {
			$this->error = "HTTP Request error: " . $curl_err;
			$log_audit($this->error);
			return false;
		}

		if ($http_code < 200 || $http_code >= 300) {
			$err_msg = "HTTP code " . $http_code;
			$decoded = json_decode($response, true);
			if (!empty($decoded) && !empty($decoded['errorMessage'])) {
				$err_msg .= ": " . $decoded['errorMessage'];
			} elseif (!empty($decoded) && !empty($decoded['detail'])) {
				$err_msg .= ": " . (is_array($decoded['detail']) ? json_encode($decoded['detail']) : $decoded['detail']);
			} else {
				$err_msg .= " - " . substr($response, 0, 500);
			}
			$this->error = $err_msg;
			$log_audit($this->error);
			return false;
		}

		// Log successful request
		$log_audit();

		if ($raw_response) {
			return $response;
		}

		$decoded = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->error = "Failed to parse JSON response: " . json_last_error_msg();
			return false;
		}

		return $decoded;
	}
}
