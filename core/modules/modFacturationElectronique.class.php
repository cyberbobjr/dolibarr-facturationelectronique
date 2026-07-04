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
 *	\defgroup   facturation_electronique      Module Facturation Electronique
 *	\brief      Module to manage B2B electronic invoicing via SuperPDP
 *	\file       htdocs/custom/facturation_electronique/core/modules/modFacturationElectronique.class.php
 *	\ingroup    facturation_electronique
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *	Class to describe and enable module Facturation Electronique
 */
class modFacturationElectronique extends DolibarrModules
{
	/**
	 *   Constructor. Define names, constants, directories, boxes, permissions
	 *
	 *   @param      DoliDB		$db      Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 559100;

		$this->family = "interface";
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Module de facturation electronique B2B via SuperPDP (Factur-X, UBL, CII)";
		$this->descriptionlong = "Conformite facturation B2B francaise. Liaison tiers a l'annuaire national, conversion en Factur-X et transmission securisee de factures clients, et recuperation automatique de factures d'achat.";

		$this->version = '1.8.0-alpha.3';

		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-file-invoice-dollar';

		$this->dirs = array();
		$this->depends = array("modSociete", "modFacture", "modFournisseur");
		$this->requiredby = array();

		$this->config_page_url = array("setup.php@facturationelectronique");

		$this->const = array();
		$this->boxes = array();
		
		$this->rights_class = 'facturation_electronique';
		$r = 0;
		
		$this->rights[$r][0] = 559101; // Unique permission ID
		$this->rights[$r][1] = 'Lire les factures electroniques B2B et l annuaire'; // Description
		$this->rights[$r][2] = 'r'; // Type (r/w/d)
		$this->rights[$r][3] = 1; // Enabled by default for admins
		$this->rights[$r][4] = 'lire'; // Code check (subclass)
		$r++;

		$this->rights[$r][0] = 559102;
		$this->rights[$r][1] = 'Transmettre des factures Factur-X et synchroniser les flux';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'creer';
		$r++;

		// Register hooks and cron jobs
		$this->module_parts = array(
			'triggers' => 1,
			'hooks' => array(
				'data' => array(
					'thirdpartycard',
					'invoicecard',
					'invoicesuppliercard',
					'globalcard',
					'invoicedao'
				),
				'entity' => '0',
			),
			'css' => array(
				'/facturationelectronique/css/facturation_electronique.css'
			),
			'cronjobs' => array(
				0 => array(
					'label' => 'SyncIncomingInvoicesFacturElect',
					'jobtype' => 'method',
					'class' => '/facturationelectronique/class/facturelectclient.class.php',
					'objectname' => 'FacturelectClient',
					'method' => 'syncIncomingInvoicesCron',
					'parameters' => '',
					'comment' => 'Synchronize incoming supplier invoices from the active electronic invoicing provider',
					'frequency' => 1,
					'unitfrequency' => 3600,
					'status' => 0,
					'test' => 'isModEnabled("facturationelectronique") && getDolGlobalInt("FACTURELECT_FEATURE_EINVOICING", 1)',
					'priority' => 50,
				),
			),
		);

		if (!isset($conf->facturation_electronique) || !isset($conf->facturation_electronique->enabled)) {
			$conf->facturation_electronique = new stdClass();
			$conf->facturation_electronique->enabled = isModEnabled('facturationelectronique') ? 1 : 0;
		}

		// Main menu entries
		$this->menu = array();
		$r = 0;
		
		// 1. Topbar menu entry
		$this->menu[$r] = array(
			'fk_menu' => '0', // 0 = this is a top menu entry
			'mainmenu' => 'facturelect',
			'leftmenu' => '',
			'type' => 'top',
			'titre' => 'Facturation Électronique',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth"'),
			'url' => '/custom/facturationelectronique/inbound_list.php?mainmenu=facturelect&leftmenu=inbound',
			'langs' => 'facturation_electronique@facturationelectronique',
			'position' => 100,
			'enabled' => 'isModEnabled("facturationelectronique")',
			'perms' => '$user->rights->facturation_electronique->lire',
			'target' => '',
			'user' => 0,
		);
		$r++;

		// 2. Submenu Factures Reçues (Transmission feature only)
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=facturelect',
			'mainmenu' => 'facturelect',
			'leftmenu' => 'inbound',
			'type' => 'left',
			'titre' => 'Factures Reçues (Achats)',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth"'),
			'url' => '/custom/facturationelectronique/inbound_list.php?mainmenu=facturelect&leftmenu=inbound',
			'langs' => 'facturation_electronique@facturationelectronique',
			'position' => 100,
			'enabled' => 'isModEnabled("facturationelectronique") && getDolGlobalInt("FACTURELECT_FEATURE_EINVOICING", 1)',
			'perms' => '$user->rights->facturation_electronique->lire',
			'target' => '',
			'user' => 0,
		);
		$r++;

		// 3. Submenu Factures Émises (Transmission feature only)
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=facturelect',
			'mainmenu' => 'facturelect',
			'leftmenu' => 'outbound',
			'type' => 'left',
			'titre' => 'Factures Émises (Ventes)',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth"'),
			'url' => '/custom/facturationelectronique/outbound_list.php?mainmenu=facturelect&leftmenu=outbound',
			'langs' => 'facturation_electronique@facturationelectronique',
			'position' => 110,
			'enabled' => 'isModEnabled("facturationelectronique") && getDolGlobalInt("FACTURELECT_FEATURE_EINVOICING", 1)',
			'perms' => '$user->rights->facturation_electronique->lire',
			'target' => '',
			'user' => 0,
		);
		$r++;

		// 4. Submenu Tiers sans SIREN (SIREN feature only)
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=facturelect',
			'mainmenu' => 'facturelect',
			'leftmenu' => 'tiers_sans_siren',
			'type' => 'left',
			'titre' => 'Tiers sans SIREN',
			'prefix' => img_picto('', 'company', 'class="paddingright pictofixedwidth"'),
			'url' => '/custom/facturationelectronique/tiers_sans_siren.php?mainmenu=facturelect&leftmenu=tiers_sans_siren',
			'langs' => 'facturation_electronique@facturationelectronique',
			'position' => 115,
			'enabled' => 'isModEnabled("facturationelectronique") && getDolGlobalInt("FACTURELECT_FEATURE_SIREN", 1)',
			'perms' => '$user->rights->facturation_electronique->lire',
			'target' => '',
			'user' => 0,
		);
		$r++;

		// 5. Submenu Configuration
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=facturelect',
			'mainmenu' => 'facturelect',
			'leftmenu' => 'setup',
			'type' => 'left',
			'titre' => 'Configuration',
			'prefix' => img_picto('', 'setup', 'class="paddingright pictofixedwidth"'),
			'url' => '/custom/facturationelectronique/admin/setup.php?mainmenu=facturelect&leftmenu=setup',
			'langs' => 'facturation_electronique@facturationelectronique',
			'position' => 120,
			'enabled' => 'isModEnabled("facturationelectronique")',
			'perms' => '$user->rights->facturation_electronique->lire',
			'target' => '',
			'user' => 0,
		);
		$r++;

		// 6. Submenu Journal d'audit
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=facturelect',
			'mainmenu' => 'facturelect',
			'leftmenu' => 'audit_logs',
			'type' => 'left',
			'titre' => 'Journal d\'audit',
			'prefix' => img_picto('', 'history', 'class="paddingright pictofixedwidth"'),
			'url' => '/custom/facturationelectronique/admin/audit_logs.php?mainmenu=facturelect&leftmenu=audit_logs',
			'langs' => 'facturation_electronique@facturationelectronique',
			'position' => 130,
			'enabled' => 'isModEnabled("facturationelectronique")',
			'perms' => '$user->rights->facturation_electronique->lire',
			'target' => '',
			'user' => 0,
		);
		$r++;

		$this->tabs = array();
		$this->tabs[] = array('data'=>'invoice:+facturelect_tab:FacturelectTabName:facturation_electronique@facturationelectronique:$user->rights->facture->lire:/custom/facturationelectronique/invoice_facturelect_tab.php?id=__ID__&type=customer');
		$this->tabs[] = array('data'=>'supplier_invoice:+facturelect_tab:FacturelectTabName:facturation_electronique@facturationelectronique:$user->rights->fournisseur->facture->lire || $user->rights->supplier_invoice->lire:/custom/facturationelectronique/invoice_facturelect_tab.php?id=__ID__&type=supplier');
		$this->langfiles = array("facturation_electronique@facturationelectronique");
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function adds constants, extrafields, permissions and menus.
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int             	1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		$this->remove($options);

		// Create table llx_facturelect_log if not exists
		$sql_table = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "facturelect_log (
			rowid integer AUTO_INCREMENT PRIMARY KEY,
			date_creation datetime NOT NULL,
			provider varchar(50) NOT NULL,
			action varchar(100) NOT NULL,
			url varchar(255) NULL,
			method varchar(10) NULL,
			http_status integer NULL,
			request_payload text NULL,
			response_payload text NULL,
			error_message text NULL,
			fk_user integer DEFAULT 0
		) ENGINE=innodb;";
		$this->db->query($sql_table);

		// Add index on date_creation for sorting performance
		$sql_index = "ALTER TABLE " . MAIN_DB_PREFIX . "facturelect_log ADD INDEX idx_facturelect_log_date (date_creation);";
		$this->db->query($sql_index);

		$sql = array();

		// Add extrafields for thirdparties and invoices
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		if (!class_exists('VatexMapper')) {
			require_once dirname(__FILE__) . '/../../class/vatexmapper.class.php';
		}
		$extrafields = new ExtraFields($this->db);

		// 1. Third-party extrafields
		$extrafields->addExtraField(
			'facturelect_scheme',
			'Schema Annuaire FE',
			'select',
			101,
			50,
			'thirdparty',
			0,
			0,
			'0225',
			array('options' => array('0225' => 'France SIREN (0225)', 'sandbox' => 'Sandbox', '0208' => 'Belgique BCE (0208)')),
			1,
			'',
			'-1',
			'Scheme for electronic invoicing identifier',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		$extrafields->addExtraField(
			'facturelect_id',
			'Identifiant Annuaire FE',
			'varchar',
			102,
			50,
			'thirdparty',
			0,
			0,
			'',
			'',
			1,
			'',
			'-1',
			'Identifier in the electronic invoicing network',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		$extrafields->addExtraField(
			'facturelect_status',
			'Statut Annuaire FE',
			'varchar',
			103,
			100,
			'thirdparty',
			0,
			0,
			'',
			'',
			1,
			'',
			'-1',
			'Registry status in the electronic invoicing network',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		$extrafields->addExtraField(
			'facturelect_last_check',
			'Derniere verification FE',
			'datetime',
			104,
			'',
			'thirdparty',
			0,
			0,
			'',
			'',
			1,
			'',
			'-1',
			'Last registry lookup timestamp',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		$extrafields->addExtraField(
			'facturelect_vatex_code',
			'FacturelectVatexCodeLabel',
			'select',
			105,
			20,
			'thirdparty',
			0,
			0,
			'',
			array('options' => VatexMapper::selectOptions()),
			1,
			'',
			'-1',
			'FacturelectVatexCodeHelp',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		$extrafields->addExtraField(
			'facturelect_vatex_reason',
			'FacturelectVatexReasonLabel',
			'varchar',
			106,
			255,
			'thirdparty',
			0,
			0,
			'',
			'',
			1,
			'',
			'-1',
			'FacturelectVatexReasonHelp',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		$extrafields->addExtraField(
			'facturelect_b2c',
			'FacturelectB2cLabel',
			'boolean',
			107,
			'',
			'thirdparty',
			0,
			0,
			'',
			'',
			1,
			'',
			'-1',
			'FacturelectB2cHelp',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		// 2. Customer invoice extrafields
		$extrafields->addExtraField(
			'facturelect_invoice_id',
			'ID Facture PDP',
			'varchar',
			101,
			50,
			'facture',
			0,
			0,
			'',
			'',
			1,
			'',
			'-1',
			'Technical ID of the invoice on the PDP',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		$extrafields->addExtraField(
			'facturelect_status',
			'Statut Transmission FE',
			'select',
			102,
			50,
			'facture',
			0,
			0,
			'not_sent',
			array('options' => array('not_sent' => 'Non envoyee', 'queued' => 'En file d attente', 'transmitted' => 'Transmise', 'failed' => 'Echec')),
			1,
			'',
			'-1',
			'Transmission status to SuperPDP',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		$extrafields->addExtraField(
			'facturelect_send_date',
			'Date Transmission FE',
			'datetime',
			103,
			'',
			'facture',
			0,
			0,
			'',
			'',
			1,
			'',
			'-1',
			'Transmission date to SuperPDP',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		$extrafields->addExtraField(
			'facturelect_vatex_code',
			'FacturelectVatexCodeLabel',
			'select',
			104,
			20,
			'facture',
			0,
			0,
			'',
			array('options' => VatexMapper::selectOptions()),
			1,
			'',
			'-1',
			'FacturelectVatexCodeHelp',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		$extrafields->addExtraField(
			'facturelect_vatex_reason',
			'FacturelectVatexReasonLabel',
			'varchar',
			105,
			255,
			'facture',
			0,
			0,
			'',
			'',
			1,
			'',
			'-1',
			'FacturelectVatexReasonHelp',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		// 3. Supplier invoice extrafields
		$extrafields->addExtraField(
			'facturelect_invoice_id',
			'ID Facture PDP',
			'varchar',
			101,
			50,
			'facture_fourn',
			0,
			0,
			'',
			'',
			1,
			'',
			'-1',
			'Technical ID of the fetched invoice on the PDP',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		$extrafields->addExtraField(
			'facturelect_send_date',
			'Date Transmission FE',
			'datetime',
			102,
			'',
			'facture_fourn',
			0,
			0,
			'',
			'',
			1,
			'',
			'-1',
			'Technical date of the fetched invoice',
			'',
			'',
			'facturation_electronique@facturationelectronique'
		);

		return $this->_init($sql, $options);
	}

	/**
	 *  Function called when module is disabled.
	 *  Remove from database constants, boxes and permissions.
	 *
	 *  @param      string	$options    Options when enabling module ('', 'noboxes')
	 *  @return     int                 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
