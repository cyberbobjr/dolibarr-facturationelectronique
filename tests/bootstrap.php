<?php
/**
 * Bootstrap file for PHPUnit tests
 * Mocks Dolibarr core functions and classes to allow isolated testing.
 */

// Define standard Dolibarr constants
if (!defined('DOL_DOCUMENT_ROOT')) {
	define('DOL_DOCUMENT_ROOT', dirname(dirname(dirname(dirname(__FILE__)))) . '/htdocs');
}
if (!defined('MAIN_DB_PREFIX')) {
	define('MAIN_DB_PREFIX', 'llx_');
}

// Global variable to hold mock settings for testing
global $dolibarr_mock_globals;
$dolibarr_mock_globals = array();

/**
 * Mock getDolGlobalString
 */
if (!function_exists('getDolGlobalString')) {
	function getDolGlobalString($name, $default = '') {
		global $dolibarr_mock_globals;
		return isset($dolibarr_mock_globals[$name]) ? $dolibarr_mock_globals[$name] : $default;
	}
}

/**
 * Mock getDolGlobalInt
 */
if (!function_exists('getDolGlobalInt')) {
	function getDolGlobalInt($name, $default = 0) {
		global $dolibarr_mock_globals;
		return isset($dolibarr_mock_globals[$name]) ? (int) $dolibarr_mock_globals[$name] : $default;
	}
}

/**
 * Mock dol_syslog
 */
if (!function_exists('dol_syslog')) {
	function dol_syslog($message, $level = 0, $chkheader = 0, $nofile = 0, $file_suffix = '', $islocked = 0) {
		// Do nothing or output to stdout/logs in debug if needed
	}
}

/**
 * Mock dol_print_date
 */
if (!function_exists('dol_print_date')) {
	function dol_print_date($time, $format = '', $tzoutput = 'auto') {
		return date('Y-m-d H:i:s', $time);
	}
}

/**
 * Mock price
 */
if (!function_exists('price')) {
	function price($amount, $html = 0, $langs = null, $doubleformat = 0, $dec = -1, $minusone = -1, $currency = '') {
		return number_format($amount, 2, ',', ' ') . ' ' . ($currency ? $currency : 'EUR');
	}
}

/**
 * Mock DoliDB Database Class
 */
if (!class_exists('DoliDB')) {
	class DoliDB {
		public $ok = true;
		public $error = '';
		public $queries = array();
		public $escape_queries = array();
		public $last_query = '';

		public function escape($string) {
			return addslashes($string);
		}

		public function idate($date) {
			return $date;
		}

		public function query($sql) {
			$this->queries[] = $sql;
			$this->last_query = $sql;
			return true;
		}

		public function last_insert_id($table) {
			return 42; // Fictional mock ID
		}
	}
}

/**
 * Mock CommonObject Class if not exists
 */
if (!class_exists('CommonObject')) {
	class CommonObject {
		public $id;
		public $ref;
		public $db;
		public $lines = array();
		public $array_options = array();

		public function __construct($db) {
			$this->db = $db;
		}

		public function fetch_optionals() {
			return 1;
		}
	}
}

/**
 * Mock Translate Class if not exists
 */
if (!class_exists('Translate')) {
	class Translate {
		public function trans($key, $param1 = '', $param2 = '', $param3 = '', $param4 = '', $param5 = '') {
			return $key;
		}
	}
}

/**
 * Mock Conf Class if not exists
 */
if (!class_exists('Conf')) {
	class Conf {
		public $global;
		public $facturationelectronique;

		public function __construct() {
			$this->global = new stdClass();
			$this->facturationelectronique = new stdClass();
			$this->facturationelectronique->enabled = 1;
		}
	}
}

/**
 * Mock User Class if not exists
 */
if (!class_exists('User')) {
	class User {
		public $id = 1;
		public $rights;

		public function __construct() {
			$this->rights = new stdClass();
			$this->rights->facture = new stdClass();
			$this->rights->facture->lire = 1;
			$this->rights->facturation_electronique = new stdClass();
			$this->rights->facturation_electronique->lire = 1;
		}
	}
}
