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
 *	\file       htdocs/custom/facturation_electronique/class/actions_facturation_electronique.class.php
 *	\ingroup    facturation_electronique
 *	\brief      Hooks class for Facturation Electronique custom workflows
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
if (!class_exists('FacturelectClient')) {
	require_once dirname(__FILE__) . '/facturelectclient.class.php';
}
if (!class_exists('VatexMapper')) {
	require_once dirname(__FILE__) . '/vatexmapper.class.php';
}
if (!class_exists('FacturelectB2cResolver')) {
	require_once dirname(__FILE__) . '/b2cresolver.class.php';
}

/**
 *	Class ActionsFacturationElectronique
 */
class ActionsFacturationelectronique extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code
	 */
	public $error = '';

	/**
	 * @var string HTML (CSS <link> and <script> blocks) deferred from addMoreActionsButtons
	 *             to printCommonFooter. Emitting scripts inside the action-buttons bar breaks
	 *             Dolibarr V24's button-collapsing, so they are output in the page footer instead.
	 */
	private $deferredFooterHtml = '';

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
	 * Get CSS URL with filemtime as version for cache busting
	 *
	 * @return string CSS Url
	 */
	private function getCssUrl()
	{
		$cssfile = dol_buildpath('/facturationelectronique/css/facturation_electronique.css', 0);
		$cssurl = dol_buildpath('/facturationelectronique/css/facturation_electronique.css', 1);
		if (file_exists($cssfile)) {
			$cssurl .= '?v=' . filemtime($cssfile);
		}
		return $cssurl;
	}

	/**
	 * Inject custom CSS on all pages
	 *
	 * @param   array           $parameters     Hook metadatas
	 * @param   CommonObject    $object         The object
	 * @param   string          $action         Current action
	 * @param   HookManager     $hookmanager    Hook manager
	 * @return  int                             0
	 */
	public function pageHeadTemplates($parameters, &$object, &$action, $hookmanager)
	{
		echo '<link rel="stylesheet" type="text/css" href="' . $this->getCssUrl() . '">';
		return 0;
	}

	/**
	 * Overload actions in invoice card (intercept send action)
	 *
	 * @param   array           $parameters     Hook metadatas
	 * @param   CommonObject    $object         Invoice object
	 * @param   string          $action         Current action
	 * @param   HookManager     $hookmanager    Hook manager
	 * @return  int                             0 or 1
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		if (!getDolGlobalInt('FACTURELECT_FEATURE_EINVOICING', 1)) {
			return 0;
		}

		if ($parameters['currentcontext'] === 'invoicecard' && $action === 'send_facturelect') {
			$langs->load('facturation_electronique@facturationelectronique');

			$this->writeLog($object->ref, 'INFO', 'Debut de la transmission electronique (action: send_facturelect).');

			// 1. Compile standard en_invoice JSON
			$en_invoice = $this->buildEnInvoiceJson($object);
			if (!$en_invoice) {
				$this->writeLog($object->ref, 'ERROR', 'Echec de la compilation du payload JSON : ' . $this->error);
				setEventMessages($this->error, null, 'errors');
				$action = 'view';
				return 0;
			}

			// LOG PAYLOAD FOR DEBUGGING
			$log_dir = DOL_DATA_ROOT . '/facturation_electronique';
			require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
			dol_mkdir($log_dir);
			file_put_contents($log_dir . '/last_payload.json', json_encode($en_invoice, JSON_PRETTY_PRINT));
			$this->writeLog($object->ref, 'INFO', 'Payload JSON genere avec succes et enregistre dans last_payload.json.');

			// 2. Convert and send
			$client = new FacturelectClient($this->db);

			// Call convert endpoint with the existing Dolibarr PDF to preserve layout
			$pdf_dir = $conf->facture->dir_output . '/' . dol_sanitizeFileName($object->ref);
			$pdf_file = $pdf_dir . '/' . dol_sanitizeFileName($object->ref) . '.pdf';
			if (!file_exists($pdf_file)) {
				$model = !empty($object->model_pdf) ? $object->model_pdf : 'crabe';
				$object->generateDocument($model, $langs);
			}

			$pdf_content = $client->convertInvoiceToFacturX($en_invoice, file_exists($pdf_file) ? $pdf_file : '');
			if ($pdf_content === false) {
				// LOG THE EXACT API RESPONSE ERROR FOR DEBUGGING
				file_put_contents($log_dir . '/last_error.txt', "Convert API Error: " . $client->error);
				$this->writeLog($object->ref, 'ERROR', 'Echec de la conversion en Factur-X : ' . $client->error);
				setEventMessages($langs->trans('FacturelectSendError', $client->error), null, 'errors');
				$action = 'view';
				return 0;
			}

			$this->writeLog($object->ref, 'SUCCESS', 'Conversion en Factur-X reussie.');

			// Upload/Send endpoint
			$send_res = $client->sendFacturXInvoice($pdf_content, $object->ref);
			$pdp_id = '';
			if ($send_res === false) {
				if (preg_match('/d[eé]j[aà] existante\s*\(id\s*(\d+)\)/ui', $client->error, $matches)) {
					$pdp_id = $matches[1];
					$this->writeLog($object->ref, 'INFO', 'La facture existe deja sur le PDP. Recuperation de l ID existant : ' . $pdp_id);
				} else {
					// LOG THE EXACT API RESPONSE ERROR FOR DEBUGGING
					file_put_contents($log_dir . '/last_error.txt', "Upload API Error: " . $client->error);
					$this->writeLog($object->ref, 'ERROR', 'Echec de la transmission via ' . $client->getProviderName() . ' : ' . $client->error);
					setEventMessages($langs->trans('FacturelectSendError', $client->error), null, 'errors');
					$action = 'view';
					return 0;
				}
			} else {
				$pdp_id = $send_res['id'];
			}

			// 1. Update Extrafields safely according to AGENTS.md Rule 9 (triggers might regenerate standard PDF here, so we do it first)
			$object->array_options['options_facturelect_invoice_id'] = $pdp_id;
			$object->array_options['options_facturelect_status'] = 'transmitted';
			$object->array_options['options_facturelect_send_date'] = dol_now();
			$object->updateExtraField('facturelect_invoice_id');
			$object->updateExtraField('facturelect_status');
			$object->updateExtraField('facturelect_send_date');

			// 2. Overwrite local Dolibarr generated PDF with the certified Factur-X PDF (suffixing to avoid collision)
			$upload_dir = $conf->facture->dir_output . '/' . dol_sanitizeFileName($object->ref);
			require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
			dol_mkdir($upload_dir);

			$file_name = dol_sanitizeFileName($object->ref) . '_facturX.pdf';
			$dest_path = $upload_dir . '/' . $file_name;

			file_put_contents($dest_path, $pdf_content);

			// 3. Register the file in the database index so it's tracked in the document manager
			if (file_exists($dest_path)) {
				addFileIntoDatabaseIndex($upload_dir, $file_name, '', 'generated', 0, $object);
			}

			$this->writeLog($object->ref, 'SUCCESS', 'Transmission via ' . $client->getProviderName() . ' reussie. ID technique PDP : ' . $pdp_id);

			setEventMessages($langs->trans('FacturelectSendSuccess', $pdp_id), null, 'mesgs');
			$action = 'view';
			return 0;
		}

		if ($parameters['currentcontext'] === 'invoicecard' && $action === 'fetch_facturelect') {
			$langs->load('facturation_electronique@facturationelectronique');

			if (method_exists($object, 'fetch_optionals') && empty($object->array_options)) {
				$object->fetch_optionals();
			}
			$pdp_id = !empty($object->array_options['options_facturelect_invoice_id']) ? $object->array_options['options_facturelect_invoice_id'] : '';

			if (empty($pdp_id)) {
				setEventMessages("Aucun ID PDP associe a cette facture. Veuillez la transmettre au prealable.", null, 'errors');
				$action = 'view';
				return 0;
			}

			$client = new FacturelectClient($this->db);
			$pdf_content = $client->downloadFacturXFile($pdp_id);

			if ($pdf_content === false) {
				setEventMessages("Erreur de recuperation Factur-X : " . $client->error, null, 'errors');
				$action = 'view';
				return 0;
			}

			$upload_dir = $conf->facture->dir_output . '/' . dol_sanitizeFileName($object->ref);
			require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
			dol_mkdir($upload_dir);

			$file_name = dol_sanitizeFileName($object->ref) . '_facturX.pdf';
			$dest_path = $upload_dir . '/' . $file_name;

			file_put_contents($dest_path, $pdf_content);

			if (file_exists($dest_path)) {
				addFileIntoDatabaseIndex($upload_dir, $file_name, '', 'generated', 0, $object);
				setEventMessages("Fichier Factur-X recupere avec succes depuis " . $client->getProviderName() . " et lie a la facture Dolibarr !", null, 'mesgs');
			} else {
				setEventMessages("Echec de l'ecriture du fichier Factur-X local.", null, 'errors');
			}

			$action = 'view';
			return 0;
		}

		return 0;
	}

	/**
	 * Add custom button to invoice and thirdparty cards
	 *
	 * @param   array           $parameters     Hook metadatas
	 * @param   CommonObject    $object         The object (Societe or Facture)
	 * @param   string          $action         Current action
	 * @param   HookManager     $hookmanager    Hook manager
	 * @return  int                             0
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $conf;

		$langs->load('facturation_electronique@facturationelectronique');

		$thirdparty_id = 0;
		$thirdparty_name = '';
		$thirdparty_zip = '';

		if ($parameters['currentcontext'] === 'thirdpartycard') {
			$thirdparty_id = $object->id;
			$thirdparty_name = $object->name;
			$thirdparty_zip = $object->zip;
		} elseif ($parameters['currentcontext'] === 'invoicecard') {
			if (empty($object->thirdparty)) {
				$object->fetch_thirdparty();
			}
			$thirdparty_id = $object->thirdparty->id;
			$thirdparty_name = $object->thirdparty->name;
			$thirdparty_zip = $object->thirdparty->zip;
		}

		// Feature flags — default ON so installations without the constants behave as before
		$feat_einvoicing = (bool) getDolGlobalInt('FACTURELECT_FEATURE_EINVOICING', 1);
		$feat_siren = (bool) getDolGlobalInt('FACTURELECT_FEATURE_SIREN', 1);

		// 1. Third-party Card — SIREN lookup feature only
		if ($parameters['currentcontext'] === 'thirdpartycard') {
			if (!$feat_siren) {
				return 0;
			}
		}

		// 1. Third-party Card & Invoice Card (for fast SIREN lookup modal)
		if ($parameters['currentcontext'] === 'thirdpartycard' || $parameters['currentcontext'] === 'invoicecard') {
			if (!$feat_siren && !$feat_einvoicing) {
				return 0;
			}

			// Ensure CSS is loaded (deferred to the footer, see $deferredFooterHtml)
			$this->deferredFooterHtml .= '<link rel="stylesheet" type="text/css" href="' . $this->getCssUrl() . '">';

			$client = new FacturelectClient($this->db);
			$provider_name = $client->getProviderName();

			if ($feat_siren && $parameters['currentcontext'] === 'thirdpartycard') {
				$siren = !empty($object->siren) ? $object->siren : $object->idprof1;
				$btn_text = empty($siren) ? "Rechercher SIREN (" . $provider_name . ")" : "Vérifier/Mettre à jour SIREN (" . $provider_name . ")";

				// Render a very premium action button
				echo '<a class="butAction fe-btn-primary" id="fe-verify-btn" href="#" onclick="feOpenModal(' . $thirdparty_id . '); return false;">';
				echo '<span class="fa fa-search paddingrightonly"></span> ' . $btn_text;
				echo '</a>';
			}

			// Add JavaScript for modern real-time visual alerts and dynamic updates.
			// Captured with output buffering and deferred to printCommonFooter (never emitted
			// inside the action-buttons bar, which breaks Dolibarr V24's button layout).
			ob_start();
			?>
			<script type="text/javascript">
				console.log("Script facturationelectronique.class.php loaded globally");
				let feSocId = <?php echo (int) $thirdparty_id; ?>;
				let fePrefillName = "<?php echo dol_escape_js($thirdparty_name); ?>";
				let fePrefillZip = "<?php echo dol_escape_js($thirdparty_zip); ?>";
				let feCurrentCompanies = [];

				function feOpenModal(socid) {
					console.log("feOpenModal called with socid:", socid);
					feSocId = socid;
					if (!document.getElementById('fe-siren-modal')) {
						console.log("fe-siren-modal element does not exist, calling injectModalHtml");
						injectModalHtml();
					} else {
						console.log("fe-siren-modal element already exists");
					}
					document.getElementById('fe-search-name').value = fePrefillName;
					document.getElementById('fe-search-zip').value = fePrefillZip;
					document.getElementById('fe-modal-content').innerHTML = '';
					document.getElementById('fe-siren-modal').classList.remove('fe-hidden');
					console.log("fe-siren-modal should be visible now");
				}

				function feCloseModal() {
					console.log("feCloseModal called");
					const modal = document.getElementById('fe-siren-modal');
					if (modal) {
						modal.classList.add('fe-hidden');
					}
				}

				function injectModalHtml() {
					console.log("injectModalHtml execution started");
					const html = `
						<div id="fe-siren-modal" class="fe-modal-overlay fe-hidden">
							<div class="fe-modal-container">
								<div class="fe-modal-header">
									<h3><span class="fa fa-search"></span> Annuaire des Entreprises (<?php echo dol_escape_js($provider_name); ?>)</h3>
									<button type="button" class="fe-modal-close" onclick="feCloseModal()">&times;</button>
								</div>
								<div class="fe-modal-body">
									<div class="fe-modal-form">
										<div class="fe-form-group">
											<label>Nom ou Raison Sociale</label>
											<input type="text" id="fe-search-name" class="fe-input" placeholder="Nom de l'entreprise...">
										</div>
										<div class="fe-form-group">
											<label>Code Postal (Département)</label>
											<input type="text" id="fe-search-zip" class="fe-input" placeholder="Ex: 75 ou 75002...">
										</div>
										<button type="button" class="fe-btn fe-btn-primary" onclick="fePerformSearch()">Rechercher</button>
										<div class="fe-form-group" style="grid-column: 1 / -1; margin-top: 10px; display: flex; align-items: center; gap: 8px;">
											<input type="checkbox" id="fe-update-details" checked style="margin: 0; width: 16px; height: 16px; cursor: pointer;">
											<label for="fe-update-details" style="font-size: 12px; font-weight: normal; color: #475569; margin: 0; cursor: pointer;">
												Mettre également à jour le nom et l'adresse du tiers avec les données officielles de l'annuaire
											</label>
										</div>
									</div>
									
									<div id="fe-modal-loader" class="fe-modal-loader fe-hidden">
										<span class="fa fa-spinner fa-spin"></span> Recherche en cours...
									</div>
									
									<div id="fe-modal-content" class="fe-modal-content">
										<!-- Results will be injected here -->
									</div>
								</div>
							</div>
						</div>
					`;
					document.body.insertAdjacentHTML('beforeend', html);
				}

				function fePerformSearch() {
					const name = document.getElementById('fe-search-name').value;
					const zip = document.getElementById('fe-search-zip').value;
					const loader = document.getElementById('fe-modal-loader');
					const content = document.getElementById('fe-modal-content');

					if (!name) {
						alert('Veuillez saisir un nom pour la recherche.');
						return;
					}

					loader.classList.remove('fe-hidden');
					content.innerHTML = '';
					feCurrentCompanies = [];

					fetch('<?php echo dol_buildpath('/facturationelectronique/siren_lookup.php', 1); ?>?action=search_companies&name=' + encodeURIComponent(name) + '&zip=' + encodeURIComponent(zip))
						.then(response => response.json())
						.then(data => {
							loader.classList.add('fe-hidden');
							if (data.success && data.companies && data.companies.length > 0) {
								feCurrentCompanies = data.companies;
								let html = '<div class="fe-companies-list">';
								html += '<h4>Entreprises trouvées (' + data.companies.length + ')</h4>';
								data.companies.forEach((company, index) => {
									html += `
										<div class="fe-company-card">
											<div class="fe-company-info">
												<div class="fe-company-name">${company.formal_name}</div>
												<div class="fe-company-details">
													<strong>SIREN :</strong> ${company.number}<br/>
													<strong>Adresse :</strong> ${company.address}, ${company.postcode} ${company.city}
												</div>
											</div>
											<button type="button" class="fe-btn fe-btn-secondary" onclick="feSelectCompanyByIndex(${index})">
												Sélectionner
											</button>
										</div>
									`;
								});
								html += '</div>';
								content.innerHTML = html;
							} else {
								content.innerHTML = '<div class="fe-no-results">Aucune entreprise correspondante trouvée.</div>';
							}
						})
						.catch(error => {
							loader.classList.add('fe-hidden');
							content.innerHTML = '<div class="fe-no-results fe-text-danger">Erreur lors de la communication avec Dolibarr.</div>';
						});
				}

				function feSelectCompanyByIndex(index) {
					const company = feCurrentCompanies[index];
					if (!company) return;

					const loader = document.getElementById('fe-modal-loader');
					const content = document.getElementById('fe-modal-content');

					loader.classList.remove('fe-hidden');
					content.innerHTML = '';

					fetch('<?php echo dol_buildpath('/facturationelectronique/siren_lookup.php', 1); ?>?action=get_entries&siren=' + company.number)
						.then(response => response.json())
						.then(data => {
							loader.classList.add('fe-hidden');
							if (data.success && data.entries && data.entries.length > 0) {
								let html = '<div class="fe-entries-list">';
								html += '<h4>Établissements / Adresses de facturation active</h4>';
								html += '<table class="fe-results-table"><thead><tr><th>Établissement / ID PEPPOL</th><th>Statut</th><th>Action</th></tr></thead><tbody>';
								data.entries.forEach(entry => {
									const parts = entry.identifier.split(':');
									const val = parts.length > 1 ? parts[1] : entry.identifier;
									
									let establishmentLabel = company.number;
									if (val.includes('*')) {
										const sub = val.split('*');
										establishmentLabel = 'SIRET: ' + sub[0] + sub[1] + ' (NIC ' + sub[1] + ')';
									} else {
										establishmentLabel = 'SIREN unique (Siège social)';
									}

									const statusClass = entry.is_active ? 'success' : 'danger';
									const statusText = entry.is_active ? 'Actif' : 'Inactif';

									html += `
										<tr>
											<td>
												<strong>${establishmentLabel}</strong><br/>
												<span class="fe-peppol-id">${entry.identifier}</span>
											</td>
											<td>
												<span class="fe-status-pill ${statusClass}">${statusText}</span>
											</td>
											<td>
												<button type="button" class="fe-btn fe-btn-primary fe-btn-sm" onclick="feAssociateTiers(${index}, '${entry.identifier}', '${entry.scheme}')">
													Associer
												</button>
											</td>
										</tr>
									`;
								});
								html += '</tbody></table>';
								
								// Button to associate SIREN only
								html += `
									<div style="margin-top: 15px; text-align: right;">
										<button type="button" class="fe-btn fe-btn-secondary" onclick="feAssociateTiers(${index}, '${company.number}', '0225')">
											Associer le SIREN uniquement (sans établissement spécifique)
										</button>
									</div>
								`;
								
								content.innerHTML = html;
							} else {
								// No active entries, still allow associating SIREN only
								let html = '<div class="fe-no-results">Aucun établissement enregistré dans l\'annuaire PEPPOL pour cette entreprise.</div>';
								html += `
									<div style="margin-top: 15px; text-align: center;">
										<button type="button" class="fe-btn fe-btn-primary" onclick="feAssociateTiers(${index}, '${company.number}', '0225')">
											Associer quand même le SIREN uniquement
										</button>
									</div>
								`;
								content.innerHTML = html;
							}
						})
						.catch(error => {
							loader.classList.add('fe-hidden');
							content.innerHTML = '<div class="fe-no-results fe-text-danger">Erreur lors de la récupération des établissements.</div>';
						});
				}

				function feAssociateTiers(companyIndex, identifier, scheme) {
					const company = feCurrentCompanies[companyIndex];
					if (!company) return;

					const updateDetails = document.getElementById('fe-update-details').checked ? 1 : 0;

					if (updateDetails) {
						const msg = `Attention : En associant ce tiers, les informations locales de votre Dolibarr seront écrasées et synchronisées par les coordonnées officielles de l'annuaire :\n\n` +
						            `- Nom : ${company.formal_name}\n` +
						            `- Adresse : ${company.address}\n` +
						            `- Code Postal : ${company.postcode}\n` +
						            `- Ville : ${company.city}\n\n` +
						            `Souhaitez-vous continuer ?`;
						if (!confirm(msg)) {
							return;
						}
					} else {
						const msg = `Souhaitez-vous associer le SIREN et l'adresse de réception électronique pour ce tiers sans modifier son nom et son adresse locale ?`;
						if (!confirm(msg)) {
							return;
						}
					}

					const loader = document.getElementById('fe-modal-loader');
					const content = document.getElementById('fe-modal-content');

					loader.classList.remove('fe-hidden');
					content.innerHTML = '';

					const params = new URLSearchParams({
						action: 'update_tiers',
						socid: feSocId,
						identifier: identifier,
						scheme: scheme,
						name: company.formal_name,
						address: company.address,
						zip: company.postcode,
						city: company.city,
						update_details: updateDetails
					});

					fetch('<?php echo dol_buildpath('/facturationelectronique/siren_lookup.php', 1); ?>?' + params.toString())
						.then(response => response.json())
						.then(data => {
							loader.classList.add('fe-hidden');
							if (data.success) {
								content.innerHTML = `
									<div class="fe-alert fe-alert-success">
										<span class="fa fa-check-circle" style="font-size:24px;"></span>
										<div>
											<strong>Association réussie !</strong><br/>
											Le tiers a été mis à jour avec succès :<br/>
											- SIREN : ${data.siren}<br/>
											${data.siret ? '- SIRET : ' + data.siret + '<br/>' : ''}
											- Identifiant PEPPOL : ${data.scheme}:${data.identifier}
										</div>
									</div>
								`;
								// Reload page after 2 seconds to show changes
								setTimeout(() => {
									feCloseModal();
									location.reload();
								}, 2000);
							} else {
								content.innerHTML = `
									<div class="fe-alert fe-alert-danger">
										<span class="fa fa-exclamation-triangle" style="font-size:24px;"></span>
										<div>
											<strong>Erreur lors de l'association</strong><br/>
											${data.error}
										</div>
									</div>
									<div style="text-align: center; margin-top:15px;">
										<button type="button" class="fe-btn fe-btn-secondary" onclick="feSelectCompanyByIndex(${companyIndex})">
											Retour
										</button>
									</div>
								`;
							}
						})
						.catch(error => {
							loader.classList.add('fe-hidden');
							content.innerHTML = '<div class="fe-no-results fe-text-danger">Erreur réseau lors de l\'association.</div>';
						});
				}
			</script>
			<?php
			$this->deferredFooterHtml .= ob_get_clean();
		}

		// 2. Customer Invoice Card — Transmission feature (banner + action buttons)
		if ($feat_einvoicing && $parameters['currentcontext'] === 'invoicecard') {
			// Ensure CSS is loaded (deferred to the footer, see $deferredFooterHtml)
			$this->deferredFooterHtml .= '<link rel="stylesheet" type="text/css" href="' . $this->getCssUrl() . '">';

			if (method_exists($object, 'fetch_optionals') && empty($object->array_options)) {
				$object->fetch_optionals();
			}

			$pdp_status = !empty($object->array_options['options_facturelect_status']) ? $object->array_options['options_facturelect_status'] : 'not_sent';
			$pdp_id = !empty($object->array_options['options_facturelect_invoice_id']) ? $object->array_options['options_facturelect_invoice_id'] : '';
			$send_date = !empty($object->array_options['options_facturelect_send_date']) ? $object->array_options['options_facturelect_send_date'] : '';

			$formatted_date = '';
			if (!empty($send_date)) {
				$formatted_date = dol_print_date($send_date, 'dayhour');
			}

			// Check client SIREN and seller SIREN for B2B routing helper
			if (empty($object->thirdparty)) {
				$object->fetch_thirdparty();
			}
			$buyer_siren = preg_replace('/\s+/', '', $object->thirdparty->idprof1);
			global $mysoc;
			$seller_siren = preg_replace('/\s+/', '', $mysoc->idprof1);

			// Load the buyer extrafields early: needed both for the B2C override and PEPPOL association below.
			if (empty($object->thirdparty->array_options)) {
				$object->thirdparty->fetch_optionals();
			}

			// A private individual (B2C) has no SIREN and is out of scope of B2B e-invoicing (issue #27).
			// Detected via the native Dolibarr "Particulier" nature (TE_PRIVATE) or the explicit override checkbox.
			$is_b2c = FacturelectB2cResolver::isB2c(
				$object->thirdparty->typent_code,
				isset($object->thirdparty->array_options['options_facturelect_b2c']) ? $object->thirdparty->array_options['options_facturelect_b2c'] : null
			);

			// Validate SIREN formats (exactly 9 digits required or empty).
			// The seller SIREN is always required; the buyer SIREN is only an error for B2B customers.
			$seller_siren_invalid = empty($seller_siren) || (strlen($seller_siren) !== 9 || !ctype_digit($seller_siren));
			$buyer_siren_invalid = FacturelectB2cResolver::isBuyerSirenInvalid($buyer_siren, $is_b2c);

			// Check buyer PEPPOL routing association (facturelect_id extrafield)
			// In production: empty facturelect_id means routing falls back to raw SIREN — buyer may not be registered in PPF
			// In sandbox: SIREN always gets the sandbox prefix automatically, so no risk
			$buyer_facturelect_id = !empty($object->thirdparty->array_options['options_facturelect_id']) ? $object->thirdparty->array_options['options_facturelect_id'] : '';
			$fe_mode = getDolGlobalString('FACTURATION_ELECTRONIQUE_MODE');
			// A B2C customer is never routed by SIREN, so the "not associated to the directory" warning does not apply.
			$buyer_not_associated = empty($buyer_facturelect_id) && $fe_mode === 'production' && !$is_b2c;

			// Neutral note shown instead of a SIREN error when the customer is a private individual (B2C).
			$b2c_note_html = '';
			if ($is_b2c) {
				$b2c_note_html .= '<div class="fe-alert fe-alert-info fe-invoice-b2c-note" style="margin-bottom:10px; display:flex; align-items:flex-start; gap:10px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px;">';
				$b2c_note_html .= '<span class="fa fa-info-circle" style="color:#3b82f6; font-size:20px; margin-top:2px; flex-shrink:0;"></span>';
				$b2c_note_html .= '<div><strong style="color:#1e40af;">'.$langs->trans('FacturelectB2cNoteTitle').'</strong><br/>';
				$b2c_note_html .= $langs->trans('FacturelectB2cNoteBody');
				$b2c_note_html .= '</div></div>';
			}

			// Build warning banner (non-blocking) for missing PEPPOL association in production
			$warning_html = '';
			if ($buyer_not_associated && !$buyer_siren_invalid) {
				$warning_html .= '<div class="fe-alert fe-alert-warning fe-invoice-config-warning" style="margin-bottom:10px; display:flex; align-items:flex-start; gap:10px; background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:12px;">';
				$warning_html .= '<span class="fa fa-exclamation-circle" style="color:#f59e0b; font-size:20px; margin-top:2px; flex-shrink:0;"></span>';
				$warning_html .= '<div><strong style="color:#92400e;">Tiers non associé à l\'annuaire PDP</strong><br/>';
				$warning_html .= 'Ce tiers n\'a pas été associé via l\'annuaire. La facture sera routée par SIREN (<code style="background:#fef3c7; padding:2px 6px; border-radius:3px;">0225:'.dol_escape_htmltag($buyer_siren).'</code>).<br/>';
				$warning_html .= 'Si ce tiers n\'est pas inscrit au <strong>Portail Public de Facturation (PPF)</strong>, la facture sera transmise mais jamais délivrée au destinataire.<br/>';
				$warning_html .= '<a href="#" onclick="feOpenModal('.$thirdparty_id.'); return false;" class="butAction" style="margin-top:8px; display:inline-flex; align-items:center; gap:5px; font-size:12px; padding:4px 10px; border:1px solid #d97706!important; border-radius:6px; color:#ffffff!important; background:#f59e0b!important; background-image:none!important; text-decoration:none!important;">';
				$warning_html .= '<span class="fa fa-search"></span> Vérifier et associer ce tiers';
				$warning_html .= '</a></div></div>';
			}

			// Build configuration error banners
			$config_error_html = '';
			if ($seller_siren_invalid) {
				$seller_siren_length = strlen($seller_siren);
				$config_error_html .= '<div class="fe-alert fe-alert-danger fe-invoice-config-error" style="margin-bottom:10px; display:flex; align-items:flex-start; gap:10px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:12px;">';
				$config_error_html .= '<span class="fa fa-exclamation-triangle" style="color:#ef4444; font-size:20px; margin-top:2px; flex-shrink:0;"></span>';
				$config_error_html .= '<div><strong style="color:#991b1b;">Erreur de configuration : SIREN de votre entreprise</strong><br/>';
				if (empty($seller_siren)) {
					$config_error_html .= 'Le SIREN (Prof Id 1 / MAIN_INFO_SIREN) de votre societe n\'est pas renseigne. L\'envoi electronique requiert un SIREN valide a 9 chiffres.<br/>';
				} else {
					$config_error_html .= 'Le champ SIREN (Prof Id 1 / MAIN_INFO_SIREN) de votre societe contient <strong>'.$seller_siren_length.' chiffres</strong> au lieu de 9.<br/>';
					if ($seller_siren_length === 14) {
						$config_error_html .= 'Il semble que vous ayez saisi un <strong>SIRET</strong> (14 chiffres) au lieu du <strong>SIREN</strong> (9 chiffres). Pour rappel, le SIRET correspond au SIREN (9 premiers chiffres) + NIC (5 derniers chiffres).<br/>';
					}
					$config_error_html .= '<em>Valeur actuelle : <code style="background:#fee; padding:2px 6px; border-radius:3px;">'.dol_escape_htmltag($seller_siren).'</code></em><br/>';
				}
				$config_error_html .= '<a href="'.DOL_URL_ROOT.'/admin/company.php" target="_blank" class="butAction" style="margin-top:8px; display:inline-flex; align-items:center; gap:5px; font-size:12px; padding:4px 10px; border:1px solid #dc2626!important; border-radius:6px; color:#ffffff!important; background:#ef4444!important; background-image:none!important; text-decoration:none!important;">';
				$config_error_html .= '<span class="fa fa-wrench"></span> Corriger dans la configuration societe';
				$config_error_html .= '</a>';
				$config_error_html .= '</div></div>';
			}
			if ($buyer_siren_invalid) {
				$buyer_siren_length = strlen($buyer_siren);
				$config_error_html .= '<div class="fe-alert fe-alert-danger fe-invoice-config-error" style="margin-bottom:10px; display:flex; align-items:flex-start; gap:10px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:12px;">';
				$config_error_html .= '<span class="fa fa-exclamation-triangle" style="color:#ef4444; font-size:20px; margin-top:2px; flex-shrink:0;"></span>';
				$config_error_html .= '<div><strong style="color:#991b1b;">Erreur : SIREN du client</strong><br/>';
				if (empty($buyer_siren)) {
					$config_error_html .= 'Le SIREN (Prof Id 1) du client <strong>'.dol_escape_htmltag($object->thirdparty->name).'</strong> n\'est pas renseigne. L\'envoi electronique requiert un SIREN valide a 9 chiffres.<br/>';
				} else {
					$config_error_html .= 'Le champ SIREN (Prof Id 1) du client <strong>'.dol_escape_htmltag($object->thirdparty->name).'</strong> contient <strong>'.$buyer_siren_length.' chiffres</strong> au lieu de 9.<br/>';
					if ($buyer_siren_length === 14) {
						$config_error_html .= 'Il semble que vous ayez saisi un <strong>SIRET</strong> (14 chiffres) au lieu du <strong>SIREN</strong> (9 chiffres).<br/>';
					}
					$config_error_html .= '<em>Valeur actuelle : <code style="background:#fee; padding:2px 6px; border-radius:3px;">'.dol_escape_htmltag($buyer_siren).'</code></em><br/>';
				}
				$config_error_html .= '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $object->thirdparty->id).'" target="_blank" class="butAction" style="margin-top:8px; display:inline-flex; align-items:center; gap:5px; font-size:12px; padding:4px 10px; border:1px solid #dc2626!important; border-radius:6px; color:#ffffff!important; background:#ef4444!important; background-image:none!important; text-decoration:none!important;">';
				$config_error_html .= '<span class="fa fa-wrench"></span> Corriger la fiche tiers';
				$config_error_html .= '</a>';
				// Also offer the modal search/associate button for cases where the customer SIREN is missing or wrong
				$config_error_html .= ' <a href="#" onclick="feOpenModal('.$thirdparty_id.'); return false;" class="butAction" style="margin-top:8px; display:inline-flex; align-items:center; gap:5px; font-size:12px; padding:4px 10px; border:1px solid #dc2626!important; border-radius:6px; color:#ffffff!important; background:#ef4444!important; background-image:none!important; text-decoration:none!important;">';
				$config_error_html .= '<span class="fa fa-search"></span> Rechercher et associer';
				$config_error_html .= '</a>';
				$config_error_html .= '</div></div>';
			}

			$client = new FacturelectClient($this->db);
			$provider_name = $client->getProviderName();

			$token = newToken();

			$banner_html = '';
			if ($object->statut == 0) {
				// Draft
				$banner_html = $b2c_note_html . $config_error_html . $warning_html . '<div class="fe-alert fe-alert-info fe-invoice-status-banner" style="margin-bottom: 20px;">';
				$banner_html .= '<span class="fa fa-info-circle" style="font-size: 20px; margin-top: 2px;"></span>';
				$banner_html .= '<div><strong>Facturation Électronique (B2B)</strong><br/>';
				$banner_html .= 'Cette facture est actuellement à l\'état de <strong>Brouillon</strong>. Veuillez valider la facture pour l\'envoyer électroniquement via ' . dol_escape_htmltag($provider_name) . '.</div>';
				$banner_html .= '</div>';
			} else {
				if ($pdp_status === 'transmitted') {
					$banner_html = $b2c_note_html . $config_error_html . $warning_html . '<div class="fe-alert fe-alert-success fe-invoice-status-banner" style="margin-bottom: 20px;">';
					$banner_html .= '<span class="fa fa-check-circle" style="font-size: 20px; margin-top: 2px;"></span>';
					$banner_html .= '<div><strong>Facture transmise avec succès au PDP</strong><br/>';
					$banner_html .= 'Cette facture a été convertie en format Factur-X certifié et transmise sur le réseau national.<br/>';
					$banner_html .= '<ul style="margin: 5px 0 0 0; padding-left: 20px;">';
					$banner_html .= '<li><strong>ID technique PDP :</strong> ' . $pdp_id . '</li>';
					if ($formatted_date) {
						$banner_html .= '<li><strong>Date de transmission :</strong> ' . $formatted_date . '</li>';
					}
					$banner_html .= '</ul>';
					if (!empty($buyer_siren)) {
						$banner_html .= '<a class="butAction fe-btn-secondary" style="margin-top: 10px; display: inline-flex; align-items: center;" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=send_facturelect&token=' . $token . '">';
						$banner_html .= '<span class="fa fa-paper-plane paddingrightonly"></span> Renvoyer au format électronique';
						$banner_html .= '</a>';
					}
					$banner_html .= '</div></div>';
				} elseif ($pdp_status === 'queued') {
					$banner_html = $b2c_note_html . $config_error_html . $warning_html . '<div class="fe-alert fe-alert-info fe-invoice-status-banner" style="margin-bottom: 20px;">';
					$banner_html .= '<span class="fa fa-clock" style="font-size: 20px; margin-top: 2px;"></span>';
					$banner_html .= '<div><strong>Facture en cours de traitement / File d\'attente</strong><br/>';
					$banner_html .= 'La facture est planifiée pour envoi au PDP et sera transmise sous peu.</div>';
					$banner_html .= '</div>';
				} elseif ($pdp_status === 'failed') {
					$banner_html = $b2c_note_html . $config_error_html . $warning_html . '<div class="fe-alert fe-alert-danger fe-invoice-status-banner" style="margin-bottom: 20px;">';
					$banner_html .= '<span class="fa fa-exclamation-triangle" style="font-size: 20px; margin-top: 2px;"></span>';
					$banner_html .= '<div><strong>Échec de la transmission électronique</strong><br/>';
					$banner_html .= 'La transmission a échoué. Veuillez vérifier les informations et réessayer.<br/>';
					if (empty($buyer_siren) && !$is_b2c) {
						$banner_html .= '<br/><span class="fa fa-warning"></span> <strong>Avertissement :</strong> Le SIREN (Identifiant Professionnel 1) de ce client n\'est pas configuré. C\'est nécessaire pour l\'envoi B2B.<br/>';
					}
					$banner_html .= '<a class="butAction fe-btn-primary" style="margin-top: 10px; display: inline-flex; align-items: center;" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=send_facturelect&token=' . $token . '">';
					$banner_html .= '<span class="fa fa-paper-plane paddingrightonly"></span> Relancer la transmission';
					$banner_html .= '</a>';
					$banner_html .= '</div></div>';
				} else { // not_sent
					$banner_html = $b2c_note_html . $config_error_html . $warning_html . '<div class="fe-alert fe-alert-info fe-invoice-status-banner" style="margin-bottom: 20px;">';
					$banner_html .= '<span class="fa fa-file-invoice-dollar" style="font-size: 20px; margin-top: 2px;"></span>';
					$banner_html .= '<div><strong>Prête pour Facturation Électronique (B2B)</strong><br/>';
					$banner_html .= 'Cette facture est validée et peut être transmise instantanément sous forme de Factur-X certifié au réseau national via ' . dol_escape_htmltag($provider_name) . '.<br/>';
					if (empty($buyer_siren) && !$is_b2c) {
						$banner_html .= '<br/><span class="fa fa-warning"></span> <strong>Avertissement :</strong> Le SIREN (Identifiant Professionnel 1) du client n\'est pas renseigné dans sa fiche tiers. L\'envoi électronique requiert un SIREN valide.<br/>';
						$banner_html .= '<a class="butAction fe-btn-warning" style="margin-top: 10px; display: inline-flex; align-items: center;" href="#" onclick="feOpenModal(' . $thirdparty_id . '); return false;">';
						$banner_html .= '<span class="fa fa-search paddingrightonly"></span> Rechercher et associer le tiers';
						$banner_html .= '</a>';
					} else {
						$banner_html .= '<a class="butAction fe-btn-primary" style="margin-top: 10px; display: inline-flex; align-items: center;" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=send_facturelect&token=' . $token . '">';
						$banner_html .= '<span class="fa fa-paper-plane paddingrightonly"></span> ' . $langs->trans('FacturelectTabTransmitNow');
						$banner_html .= '</a>';
					}
					$banner_html .= '</div></div>';
				}
			}

			// Dynamic JS Injection of the Banner (deferred to the footer via output buffering)
			ob_start();
			?>
			<script type="text/javascript">
				(function() {
					function injectBanner() {
						const card = document.querySelector('.fiche');
						if (card) {
							const bannerHtml = <?php echo json_encode($banner_html); ?>;
							// Avoid duplicate injection
							if (!document.querySelector('.fe-invoice-status-banner')) {
								card.insertAdjacentHTML('afterbegin', bannerHtml);
							}
						}
					}
					if (document.readyState === 'loading') {
						document.addEventListener('DOMContentLoaded', injectBanner);
					} else {
						injectBanner();
					}
				})();
			</script>
			<?php
			$this->deferredFooterHtml .= ob_get_clean();

			// Standard Actions Bar Button (only when validated/paid)
			if ($object->statut == 1 || $object->statut == 2) {
				$can_send = !$seller_siren_invalid && !$buyer_siren_invalid;
				if ($can_send) {
					if ($pdp_status === 'transmitted') {
						echo '<a class="butAction fe-btn-secondary" id="fe-resend-btn" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=send_facturelect&token=' . $token . '">';
						echo '<span class="fa fa-paper-plane paddingrightonly"></span> Renvoyer au format électronique';
						echo '</a>';
					} else {
						echo '<a class="butAction fe-btn-primary" id="fe-send-btn" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=send_facturelect&token=' . $token . '">';
						echo '<span class="fa fa-paper-plane paddingrightonly"></span> ' . $langs->trans('FacturelectSendInvoice');
						echo '</a>';
					}
				} elseif ($seller_siren_invalid) {
					echo '<a class="butAction" id="fe-config-btn" href="'.DOL_URL_ROOT.'/admin/company.php" target="_blank" style="border:1px solid #dc2626!important; color:#ffffff!important; background:#ef4444!important; background-image:none!important;" title="Le SIREN de votre entreprise est invalide ou manquant. Cliquer pour corriger.">';
					echo '<span class="fa fa-wrench paddingrightonly"></span> Corriger SIREN société';
					echo '</a>';
				} elseif ($buyer_siren_invalid) {
					echo '<a class="butAction" id="fe-client-siren-btn" href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $object->thirdparty->id).'" target="_blank" style="border:1px solid #dc2626!important; color:#ffffff!important; background:#ef4444!important; background-image:none!important;" title="Le SIREN du client est invalide ou manquant. Cliquer pour corriger.">';
					echo '<span class="fa fa-wrench paddingrightonly"></span> Corriger SIREN client';
					echo '</a>';
					echo ' <a class="butAction" href="#" onclick="feOpenModal('.$thirdparty_id.'); return false;" style="border:1px solid #dc2626!important; color:#ffffff!important; background:#ef4444!important; background-image:none!important;" title="Rechercher et associer le tiers via l\'annuaire.">';
					echo '<span class="fa fa-search paddingrightonly"></span> Rechercher et associer';
					echo '</a>';
				}
				if (!empty($pdp_id)) {
					echo '<a class="butAction fe-btn-secondary" id="fe-fetch-btn" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=fetch_facturelect&token=' . $token . '" style="border: 1px dashed #ef4444; color: #ef4444; margin-left: 5px;">';
					echo '<span class="fa fa-bug paddingrightonly"></span> Debug : Lier Factur-X';
					echo '</a>';
				}
			}
		}

		return 0;
	}

	/**
	 * Output, in the page footer, the CSS <link> and <script> blocks accumulated by
	 * addMoreActionsButtons. Emitting them inside the action-buttons bar (.tabsAction)
	 * breaks Dolibarr V24's button-collapsing logic and truncates the page; the footer
	 * is a neutral location that works across all Dolibarr versions.
	 *
	 * The HookManager reuses the same action-class instance for every hook of a page, and
	 * addMoreActionsButtons (body) always runs before printCommonFooter (llxFooter), so the
	 * deferred HTML is guaranteed to be populated by the time this hook fires.
	 *
	 * @param   array           $parameters     Hook metadatas
	 * @param   CommonObject    $object         Current object
	 * @param   string          $action         Current action
	 * @param   HookManager     $hookmanager    Hook manager
	 * @return  int                             0
	 */
	public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
	{
		if (!empty($this->deferredFooterHtml)) {
			echo $this->deferredFooterHtml;
			$this->deferredFooterHtml = '';
		}
		return 0;
	}

	/**
	 * Build standardized en_invoice JSON payload from Dolibarr Invoice
	 *
	 * @param	Facture		$object		Invoice object
	 * @return	array|bool				Array representing en_invoice or false
	 */
	public function buildEnInvoiceJson($object)
	{
		global $conf, $mysoc;

		// Fetch third party details if empty
		if (empty($object->thirdparty)) {
			$object->fetch_thirdparty();
		}

		// Lines may not be loaded yet when called from doActions hook (fired before fetch_lines in card.php)
		if (empty($object->lines)) {
			$object->fetch_lines();
		}

		// Invoice extrafields (per-invoice VATEX override, statuses…) may not be loaded yet.
		if (empty($object->array_options)) {
			$object->fetch_optionals();
		}
		// Third-party extrafields (routing id, per-client VATEX default…) are read below.
		if (!empty($object->thirdparty) && empty($object->thirdparty->array_options)) {
			$object->thirdparty->fetch_optionals();
		}

		$clean_buyer_siren = preg_replace('/\s+/', '', $object->thirdparty->idprof1);
		if (empty($clean_buyer_siren)) {
			$client = new FacturelectClient($this->db);
			$this->error = "Le SIREN (Prof Id 1) du client est requis pour l envoi via " . $client->getProviderName();
			return false;
		}
		if (strlen($clean_buyer_siren) !== 9 || !ctype_digit($clean_buyer_siren)) {
			if (strlen($clean_buyer_siren) === 14 && ctype_digit($clean_buyer_siren)) {
				$this->error = "Le SIREN du client contient 14 chiffres. Vous avez saisi un SIRET au lieu d un SIREN (9 chiffres). Veuillez corriger la fiche tiers.";
			} else {
				$this->error = "Le SIREN du client est invalide (" . strlen($clean_buyer_siren) . " caracteres). Un SIREN doit comporter exactement 9 chiffres. Valeur actuelle : " . $clean_buyer_siren;
			}
			return false;
		}

		$clean_seller_siren = preg_replace('/\s+/', '', $mysoc->idprof1);
		if (empty($clean_seller_siren)) {
			$this->error = "Le SIREN (Prof Id 1) de votre propre entreprise n est pas configure dans Dolibarr";
			return false;
		}
		if (strlen($clean_seller_siren) !== 9 || !ctype_digit($clean_seller_siren)) {
			if (strlen($clean_seller_siren) === 14 && ctype_digit($clean_seller_siren)) {
				$this->error = "Le SIREN de votre entreprise contient 14 chiffres. Vous avez saisi un SIRET au lieu d un SIREN (9 chiffres). Veuillez corriger dans Configuration > Societe.";
			} else {
				$this->error = "Le SIREN de votre entreprise est invalide (" . strlen($clean_seller_siren) . " caracteres). Un SIREN doit comporter exactement 9 chiffres. Valeur actuelle : " . $clean_seller_siren;
			}
			return false;
		}

		$mode = getDolGlobalString('FACTURATION_ELECTRONIQUE_MODE');
		if ($mode !== 'production') {
			$clean_seller_siren = '000000002';
		}

		// Calculate standard invoice code
		// Dolibarr types: 0 = standard, 2 = credit note
		$type_code = 380; // standard (integer for Go unmarshal)
		if ($object->type == 2) {
			$type_code = 381; // credit note (integer for Go unmarshal)
		}

		// Formulate lines
		// EN16931 rule BR-27 forbids negative item_net_price (BT-146).
		// Dolibarr encodes global discounts and advance-payment deductions as negative lines.
		// These must become document-level allowances (BG-20) instead.
		$lines = array();
		$document_level_allowances = array();
		$line_count = 1;
		$sum_positive_lines_ht = 0.0;
		$sum_allowances_ht = 0.0;
		$has_ae_lines = false;

		foreach ($object->lines as $line) {
			// Skip decorative lines added by external modules (subtotal titles, section
			// headers, subtotal aggregation lines…). Any non-zero special_code marks a
			// line as non-commercial — confirmed by Dolibarr core card.php:1629.
			if (!empty($line->special_code)) {
				continue;
			}
			// Skip informational text lines with no financial value (product_type >= 9
			// and zero amount). These are pure comment/free-text lines, not invoice items.
			if ((int) $line->product_type >= 9 && floatval($line->total_ht) == 0 && floatval($line->total_tva) == 0) {
				continue;
			}

			$qty = !empty($line->qty) ? floatval($line->qty) : 1.0;
			$total_ht = floatval($line->total_ht);
			$line_vat_rate = floatval($line->tva_tx);
			$line_vat_code = $this->resolveVatCategoryCode($line_vat_rate, (int) ($line->info_bits ?? 0), $line->vat_src_code ?? '');

			if ($line_vat_code === 'AE') {
				$has_ae_lines = true;
			}

			if ($total_ht < 0) {
				$sum_allowances_ht += abs($total_ht);
				$document_level_allowances[] = array(
					'amount' => sprintf("%.2f", abs($total_ht)),
					'reason' => !empty($line->desc) ? $this->cleanText($line->desc) : 'Remise',
					'vat_category_code' => $line_vat_code,
					'vat_rate' => sprintf("%.1f", $line_vat_rate)
				);
				continue;
			}

			$net_price = floatval($line->subprice);
			$sum_positive_lines_ht += $total_ht;

			$lines[] = array(
				'identifier' => (string) $line_count,
				'invoiced_quantity' => sprintf("%.2f", $qty),
				'invoiced_quantity_code' => $this->resolveUnitCode($line->fk_unit ?? 0),
				'net_amount' => sprintf("%.2f", $total_ht),
				'price_details' => array(
					'item_net_price' => sprintf("%.2f", $net_price)
				),
				'vat_information' => array(
					'invoiced_item_vat_category_code' => $line_vat_code,
					'invoiced_item_vat_rate' => sprintf("%.1f", $line_vat_rate)
				),
				'item_information' => array(
					'name' => !empty($line->desc) ? $this->cleanText($line->desc) : 'Ligne de facture'
				)
			);
			$line_count++;
		}

		// BR-16: an invoice SHALL have at least one invoice line (BG-25).
		// If every Dolibarr line was negative it went into document_level_allowances
		// and $lines is empty — the payload would be rejected by the PDP.
		if (empty($lines)) {
			$this->error = "Impossible de transmettre cette facture : elle ne contient aucune ligne positive (règle BR-16 EN16931). "
				. "Les remises et déductions globales ne peuvent pas constituer l'intégralité d'une facture électronique. "
				. "Ajoutez au moins une ligne de prestation ou de produit avec un montant HT positif.";
			return false;
		}

		// Gather VAT breakdowns grouped by (rate, category_code) — EN16931 BG-23.
		// Lines at 0% with different legal regimes (AE, E, K…) go into separate buckets.
		$vat_breakdowns = array();
		$vat_details = array();
		if (!empty($object->lines)) {
			foreach ($object->lines as $line) {
				if (!empty($line->special_code)) {
					continue;
				}
				if ((int) $line->product_type >= 9 && floatval($line->total_ht) == 0 && floatval($line->total_tva) == 0) {
					continue;
				}
				$rate = sprintf("%.1f", floatval($line->tva_tx));
				$cat_code = $this->resolveVatCategoryCode(floatval($line->tva_tx), (int) ($line->info_bits ?? 0), $line->vat_src_code ?? '');
				$bucket = $rate . '#' . $cat_code;
				if (!isset($vat_details[$bucket])) {
					$vat_details[$bucket] = array(
						'rate' => $rate,
						'cat_code' => $cat_code,
						'vat_src_code' => (string) ($line->vat_src_code ?? ''),
						'taxable_amount' => 0.0,
						'tax_amount' => 0.0
					);
				}
				$vat_details[$bucket]['taxable_amount'] += floatval($line->total_ht);
				$vat_details[$bucket]['tax_amount'] += floatval($line->total_tva);
			}
		}

		foreach ($vat_details as $amounts) {
			$breakdown = array(
				'vat_category_taxable_amount' => sprintf("%.2f", $amounts['taxable_amount']),
				'vat_category_tax_amount' => sprintf("%.2f", $amounts['tax_amount']),
				'vat_category_code' => $amounts['cat_code'],
				'vat_category_rate' => $amounts['rate']
			);
			// BR-E-10/BR-G-10/BR-IC-10/BR-O-10/BR-AE-10: exempt categories SHALL carry a
			// VAT exemption reason code (BT-121, VATEX list) and/or reason text (BT-120).
			$dict_vatex = $this->resolveDictionaryVatex($amounts['vat_src_code']);
			$exemption = $this->resolveVatExemption($amounts['cat_code'], $object, $dict_vatex);
			if ($exemption !== null) {
				$breakdown['vat_exemption_reason_code'] = $exemption['reason_code'];
				$breakdown['vat_exemption_reason'] = $exemption['reason'];
			}
			$vat_breakdowns[] = $breakdown;
		}

		// Retrieve client routing details from third party extrafields
		$buyer_scheme = !empty($object->thirdparty->array_options['options_facturelect_scheme']) ? $object->thirdparty->array_options['options_facturelect_scheme'] : '0225';
		$buyer_identifier = !empty($object->thirdparty->array_options['options_facturelect_id']) ? $object->thirdparty->array_options['options_facturelect_id'] : $clean_buyer_siren;

		// Automatically prefix with SuperPDP sandbox routing identifier if in sandbox/test mode and identifier is a raw SIREN/SIRET
		$mode = getDolGlobalString('FACTURATION_ELECTRONIQUE_MODE');
		$active_provider = getDolGlobalString('FACTURATION_ELECTRONIQUE_ACTIVE_PROVIDER');
		if ($mode !== 'production' && $active_provider === 'superpdp') {
			if ($buyer_identifier === '000000001' || preg_match('/_000000001$/', $buyer_identifier)) {
				$buyer_identifier = '315143296_7181';
			} elseif (preg_match('/^[0-9]{9}$/', $buyer_identifier)) {
				$buyer_identifier = '315143296_7182_' . $buyer_identifier;
			} elseif (preg_match('/^[0-9]{14}$/', $buyer_identifier)) {
				$buyer_identifier = '315143296_7182_' . $buyer_identifier;
			}
		}

		// Extract a clean 9-digit SIREN for official business identifier fields
		$legal_buyer_siren = $clean_buyer_siren;
		if (preg_match('/_([0-9]{9})$/', $buyer_identifier, $matches)) {
			$legal_buyer_siren = $matches[1];
		}

		// BT-30 / BT-47 legal registration identifier scheme = ISO/IEC 6523 ICD list.
		// French SIREN (9 digits) => 0002 (SIRENE), SIRET (14 digits) => 0009.
		// This is a DIFFERENT code list from the CEF EAS scheme (0225) used for the
		// electronic_address (BT-34 / BT-49) below — do not unify them.
		$seller_iso_scheme = (strlen($clean_seller_siren) === 14) ? '0009' : '0002';
		$buyer_iso_scheme = (strlen($legal_buyer_siren) === 14) ? '0009' : '0002';

		// Compute remaining amount due (not auto-hydrated by fetch())
		$total_paid = $object->getSommePaiement();
		$remains_to_pay = round(floatval($object->total_ttc) - $total_paid, 2);

		// BR-S-02: seller MUST have a VAT identifier when any line is standard-rated (S).
		// total_tva > 0 is the simplest proxy — avoids re-scanning all lines.
		if (empty($mysoc->tva_intra) && floatval($object->total_tva) > 0) {
			$this->error = "Votre entreprise n'a pas de numéro TVA intracommunautaire configuré "
				. "(Paramètres → Société → Informations fiscales). "
				. "La transmission est impossible pour une facture avec TVA au taux normal (règle BR-S-02 EN16931).";
			return false;
		}

		// Resolve invoice currency — use multicurrency_code when the Multicurrency module is active.
		$currency_code = !empty($object->multicurrency_code) ? strtoupper($object->multicurrency_code) : 'EUR';
		$is_multicurrency = ($currency_code !== 'EUR');

		// For multicurrency invoices use the amounts expressed in the invoice currency,
		// not the EUR-converted amounts stored in total_ht/total_tva/total_ttc.
		$inv_total_ht  = $is_multicurrency ? floatval($object->multicurrency_total_ht)  : floatval($object->total_ht);
		$inv_total_tva = $is_multicurrency ? floatval($object->multicurrency_total_tva) : floatval($object->total_tva);
		$inv_total_ttc = $is_multicurrency ? floatval($object->multicurrency_total_ttc) : floatval($object->total_ttc);
		$inv_remains   = round($inv_total_ttc - $total_paid, 2);

		// Seller address is legally mandatory (art. 242 nonies A CGI). Block if incomplete.
		$missing_seller = array();
		if (empty($mysoc->address)) {
			$missing_seller[] = 'adresse';
		}
		if (empty($mysoc->zip)) {
			$missing_seller[] = 'code postal';
		}
		if (empty($mysoc->town)) {
			$missing_seller[] = 'ville';
		}
		if (!empty($missing_seller)) {
			$this->error = "Les champs suivants de votre société sont manquants et obligatoires pour la transmission électronique : "
				. implode(', ', $missing_seller)
				. ". Veuillez les compléter dans Configuration > Société > Coordonnées.";
			return false;
		}

		// Build final payload matching EN16931 exactly
		$en_invoice = array(
			'number' => $object->ref,
			'issue_date' => dol_print_date($object->date, '%Y-%m-%d'),
			'type_code' => $type_code,
			'currency_code' => $currency_code,
			'process_control' => array(
				'specification_identifier' => 'urn:cen.eu:en16931:2017',
				'business_process_type' => 'B1'
			),
			'notes' => $this->buildInvoiceNotes($object, $has_ae_lines),
			'seller' => array(
				'name' => $mysoc->name,
				'postal_address' => array(
					'address_line1' => $mysoc->address,
					'post_code' => $mysoc->zip,
					'city' => $mysoc->town,
					'country_code' => !empty($mysoc->country_code) ? strtoupper($mysoc->country_code) : 'FR',
					'country_subdivision' => !empty($mysoc->country) ? $mysoc->country : ''
				),
				'legal_registration_identifier' => array(
					'value' => $clean_seller_siren,
					'scheme' => $seller_iso_scheme
				),
				'trading_name' => $mysoc->name,
				'identifiers' => array(
					array('value' => $clean_seller_siren, 'scheme' => $seller_iso_scheme)
				),
				'electronic_address' => array(
					'value' => ($mode !== 'production' && $active_provider === 'superpdp') ? '315143296_7182' : $clean_seller_siren,
					'scheme' => '0225'
				)
			),
			'buyer' => array(
				'name' => $object->thirdparty->name,
				'postal_address' => array_filter(array(
					'address_line1' => !empty($object->thirdparty->address) ? $object->thirdparty->address : null,
					'post_code' => !empty($object->thirdparty->zip) ? $object->thirdparty->zip : null,
					'city' => !empty($object->thirdparty->town) ? $object->thirdparty->town : null,
					'country_code' => !empty($object->thirdparty->country_code) ? strtoupper($object->thirdparty->country_code) : 'FR',
					'country_subdivision' => !empty($object->thirdparty->state) ? $object->thirdparty->state : null,
				)),
				'legal_registration_identifier' => array(
					'value' => $legal_buyer_siren,
					'scheme' => $buyer_iso_scheme
				),
				'trading_name' => $object->thirdparty->name,
				'identifiers' => array(
					array('value' => $legal_buyer_siren, 'scheme' => $buyer_iso_scheme)
				),
				'electronic_address' => array(
					'value' => $buyer_identifier,
					'scheme' => $buyer_scheme
				)
			),
			'totals' => array(
				// BT-106: sum of positive invoice line net amounts (excludes document-level allowances)
				'sum_invoice_lines_amount' => sprintf("%.2f", $sum_positive_lines_ht),
				// BT-107: total of document-level allowances (discounts, advance-payment deductions)
				'sum_allowances_amount' => sprintf("%.2f", $sum_allowances_ht),
				'sum_charges_amount' => '0.00',
				// BT-109: net total after allowances = BT-106 - BT-107
				'total_without_vat' => sprintf("%.2f", $inv_total_ht),
				'total_with_vat' => sprintf("%.2f", $inv_total_ttc),
				'paid_amount' => sprintf("%.2f", $total_paid),
				'amount_due_for_payment' => sprintf("%.2f", $inv_remains),
				'rounding_amount' => '0.00',
				'total_vat_amount' => array(
					'currency_code' => $currency_code,
					'value' => sprintf("%.2f", $inv_total_tva)
				)
			),
			'vat_break_down' => $vat_breakdowns,
			'lines' => $lines
		);

		// vat_identifier is optional in EN16931 — only inject when actually set (never a fake value)
		if (!empty($mysoc->tva_intra)) {
			$en_invoice['seller']['vat_identifier'] = $mysoc->tva_intra;
		}
		if (!empty($object->thirdparty->tva_intra)) {
			$en_invoice['buyer']['vat_identifier'] = $object->thirdparty->tva_intra;
		}

		if (!empty($document_level_allowances)) {
			$en_invoice['document_level_allowances'] = $document_level_allowances;
		}

		if (!empty($object->date_lim_reglement)) {
			$en_invoice['payment_due_date'] = dol_print_date($object->date_lim_reglement, '%Y-%m-%d');
		}

		// BT-72 Actual delivery date (BG-13). Default to the invoice date (standard French
		// practice for services). Besides carrying a correct BT-72, it populates the
		// ApplicableHeaderTradeDelivery element and avoids the empty-element warning
		// (PEPPOL-EN16931-R008) emitted by the converter when the group is absent.
		if (!empty($object->date)) {
			$en_invoice['delivery_information'] = array(
				'delivery_date' => dol_print_date($object->date, '%Y-%m-%d')
			);
		}

		if (!empty($object->ref_client)) {
			// BT-10 Buyer reference — the "référence client" the buyer expects on the invoice.
			$en_invoice['buyer_reference'] = $object->ref_client;
			// BT-13 kept for backward compatibility (purchase order reference).
			$en_invoice['purchase_order_reference'] = $object->ref_client;
		}

		// BT-12 Contract reference — from a linked Dolibarr contract, when present.
		$contract_ref = $this->resolveLinkedContractRef($object);
		if ($contract_ref !== '') {
			$en_invoice['contract_reference'] = $contract_ref;
		}

		// BT-20 Payment terms — carries the retained-warranty (retenue de garantie) mention
		// and guarantees BR-CO-25 (a positive amount due requires BT-9 due date OR BT-20).
		// EN16931 core has no structured retainage field and SuperPDP's schema exposes none,
		// so amount_due_for_payment stays EN16931-correct (BR-CO-16) and the retention is
		// described textually here.
		$payment_terms_parts = array();

		$retained_amount = 0.0;
		if (!empty($object->retained_warranty) && method_exists($object, 'getRetainedWarrantyAmount')) {
			$retained_amount = round((float) $object->getRetainedWarrantyAmount(), 2);
		}
		if ($retained_amount > 0) {
			$net_now = round($inv_remains - $retained_amount, 2);
			$rw_terms = sprintf('Retenue de garantie de %g%% (%.2f EUR)', (float) $object->retained_warranty, $retained_amount);
			if (!empty($object->retained_warranty_date_limit)) {
				$rw_terms .= ' liberable le ' . dol_print_date($object->retained_warranty_date_limit, '%d/%m/%Y');
			}
			$rw_terms .= sprintf('. Net a payer a ce jour : %.2f EUR.', $net_now);
			$payment_terms_parts[] = $rw_terms;
		}

		// BR-CO-25 fallback: with no due date and no other term, BT-20 must still be present.
		if (empty($en_invoice['payment_due_date']) && empty($payment_terms_parts)) {
			$cond_label = '';
			if (!empty($object->cond_reglement_doc)) {
				$cond_label = $this->cleanText($object->cond_reglement_doc);
			} elseif (!empty($object->cond_reglement_label)) {
				$cond_label = $this->cleanText($object->cond_reglement_label);
			}
			$payment_terms_parts[] = ($cond_label !== '') ? $cond_label : 'Paiement a reception de facture.';
		}

		if (!empty($payment_terms_parts)) {
			$en_invoice['payment_terms'] = implode(' ', $payment_terms_parts);
		}

		// BR-49/BR-50: invoice SHALL have at least one Payment Instructions block (BG-16 / BT-81)
		$en_invoice['payment_means'] = array($this->buildPaymentMeans($object, $mysoc));

		// BR-55: a Credit Note (type_code 381) SHALL include a preceding invoice reference (BG-3).
		// Dolibarr stores the source invoice ID in fk_facture_source on type=2 objects.
		if ($object->type == 2) {
			if (!empty($object->fk_facture_source)) {
				require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
				$source_invoice = new Facture($this->db);
				if ($source_invoice->fetch($object->fk_facture_source) > 0) {
					$en_invoice['preceding_invoice_reference'] = array(
						array(
							'preceding_invoice_reference' => $source_invoice->ref,
							'preceding_invoice_issue_date' => dol_print_date($source_invoice->date, '%Y-%m-%d')
						)
					);
				}
			} else {
				dol_syslog('buildEnInvoiceJson: credit note ' . $object->ref . ' has no fk_facture_source — BR-55 preceding_invoice_reference will be absent', LOG_WARNING);
			}
		}

		return $en_invoice;
	}

	/**
	 * Map a Dolibarr payment method code to an UNCL4461 code for EN16931 BT-81.
	 *
	 * @param  string $dolibarr_code  e.g. 'VIR', 'PRE', 'CHE', 'CB', 'LIQ'
	 * @return string                 UNCL4461 code ('1' = unspecified as fallback)
	 */
	private function mapPaymentMeansCode($dolibarr_code)
	{
		$map = array(
			'VIR' => '30', // SEPA Credit Transfer
			'PRE' => '31', // SEPA Direct Debit
			'LIQ' => '10', // Cash
			'CHE' => '20', // Cheque
			'CB'  => '48', // Card
			'TIP' => '31', // TIP → direct debit
			'VAD' => '48', // Remote card payment
		);
		return isset($map[$dolibarr_code]) ? $map[$dolibarr_code] : '1';
	}

	/**
	 * Build the BG-16 payment_means block for an EN16931 payload.
	 * Follows the same bank account resolution as Dolibarr's pdf_crabe:
	 *   1. $object->fk_account (bank account linked to the invoice)
	 *   2. FACTURE_RIB_NUMBER  (default billing account in admin/invoice.php)
	 *
	 * @param  Facture $object   The invoice object
	 * @param  Societe $mysoc    The company object
	 * @return array             payment_means entry (caller wraps it in an outer array)
	 */
	private function buildPaymentMeans($object, $mysoc)
	{
		$code = $this->mapPaymentMeansCode($object->mode_reglement_code);
		$means = array('payment_means_type_code' => $code);

		// Include IBAN/BIC only for wire transfer (30) or direct debit (31)
		if (in_array($code, array('30', '31'))) {
			$bankid = !empty($object->fk_account) ? $object->fk_account : getDolGlobalInt('FACTURE_RIB_NUMBER');
			if ($bankid > 0) {
				require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
				$account = new Account($this->db);
				if ($account->fetch($bankid) > 0) {
					$iban = preg_replace('/\s+/', '', $account->iban ?: $account->iban_prefix);
					$bic  = preg_replace('/\s+/', '', $account->bic ?? '');
					if (!empty($iban)) {
						$means['payment_account_identifier'] = strtoupper($iban);
						$means['payment_account_name'] = $mysoc->name;
						if (!empty($bic)) {
							$means['payment_service_provider_identifier'] = strtoupper($bic);
						}
					}
				}
			} else {
				dol_syslog('buildPaymentMeans: no bank account configured (fk_account or FACTURE_RIB_NUMBER) — IBAN will be absent from BG-16', LOG_WARNING);
			}
		}

		return $means;
	}

	/**
	 * Build the full notes array for an EN16931 payload.
	 * Merges free-text payment notes with any mandatory legal mentions
	 * required by the resolved VAT category codes (e.g. AE → BR-AE-10).
	 *
	 * @param   Facture $object     The invoice object
	 * @param   bool    $has_ae     True when at least one line carries VAT code AE
	 * @return  array               Array of note entries for the EN16931 payload
	 */
	private function buildInvoiceNotes($object, $has_ae = false)
	{
		$notes = $this->buildPaymentNotes($object);

		// BR-FR-05 (French CTC / FNFE): three legal mentions are mandatory in the notes (BG-1):
		//   - late-payment penalties          (subject code PMD)
		//   - €40 fixed recovery indemnity     (subject code PMT)
		//   - early-payment discount / absence (subject code AAB)
		// Texts are overridable via module constants so they are never wrongly hard-coded
		// (root cause of issue #21). Defaults use safe legal wording (art. L441-10 C. com.);
		// the €40 forfait is a fixed legal amount, so it is safe as a default.
		foreach ($this->buildMandatoryFrenchNotes() as $mandatory_note) {
			$notes[] = $mandatory_note;
		}

		if ($has_ae) {
			$notes[] = array(
				'note' => 'Autoliquidation de la TVA - Article 283-2 du CGI',
				'subject_code' => 'TAX'
			);
		}

		return $notes;
	}

	/**
	 * Build the three legally mandatory French invoice mentions (BR-FR-05 / BG-1).
	 * Each text is overridable via a module constant to avoid hard-coding wrong values
	 * (issue #21); defaults fall back to safe legal wording.
	 *
	 * @return  array   Note entries with their UNTDID 4451 subject codes
	 */
	private function buildMandatoryFrenchNotes()
	{
		$defaults = self::getDefaultLegalMentions();

		$penalty = getDolGlobalString('FACTURELECT_NOTE_PENALTY');
		if ($penalty === '') {
			$penalty = $defaults['FACTURELECT_NOTE_PENALTY'];
		}
		$recovery = getDolGlobalString('FACTURELECT_NOTE_RECOVERY');
		if ($recovery === '') {
			$recovery = $defaults['FACTURELECT_NOTE_RECOVERY'];
		}
		$discount = getDolGlobalString('FACTURELECT_NOTE_DISCOUNT');
		if ($discount === '') {
			$discount = $defaults['FACTURELECT_NOTE_DISCOUNT'];
		}

		return array(
			array('note' => $penalty, 'subject_code' => 'PMD'),
			array('note' => $recovery, 'subject_code' => 'PMT'),
			array('note' => $discount, 'subject_code' => 'AAB'),
		);
	}

	/**
	 * Default legal wording (BR-FR-05 / BT-22) for the three mandatory French mentions,
	 * keyed by their module constant. Single source of truth shared by the payload builder
	 * (buildMandatoryFrenchNotes) and the setup page. The €40 recovery indemnity is a fixed
	 * legal amount (art. L441-10), so it is safe as a default; the penalty text cites no
	 * invented rate. Admins may override any of them in Configuration.
	 *
	 * @return array<string, string>  Constant name => default text
	 */
	public static function getDefaultLegalMentions()
	{
		return array(
			'FACTURELECT_NOTE_PENALTY'  => "Tout retard de paiement entraine l'application de penalites de retard au taux prevu par l'article L441-10 du Code de commerce, exigibles sans rappel.",
			'FACTURELECT_NOTE_RECOVERY' => "Indemnite forfaitaire de 40 EUR pour frais de recouvrement en cas de retard de paiement (art. L441-10 et D441-5 du Code de commerce).",
			'FACTURELECT_NOTE_DISCOUNT' => "Pas d'escompte pour paiement anticipe.",
		);
	}

	/**
	 * Build the notes array for an EN16931 invoice payload from Dolibarr native fields.
	 * INVOICE_FREE_TEXT (admin/invoice.php) carries global mentions (penalties, discount…).
	 * note_public carries per-invoice free text.
	 *
	 * @param   Facture $object     The invoice object
	 * @return  array               Array of note entries for the EN16931 payload
	 */
	private function buildPaymentNotes($object)
	{
		$notes = array();

		// Global invoice mention configured in admin/invoice.php ("Mention complémentaire")
		$free_text = getDolGlobalString('INVOICE_FREE_TEXT');
		if (!empty($free_text)) {
			$notes[] = array(
				'note' => $this->cleanText($free_text),
				'subject_code' => 'ZZZ'
			);
		}

		// Per-invoice public note
		if (!empty($object->note_public)) {
			$notes[] = array(
				'note' => $this->cleanText($object->note_public),
				'subject_code' => 'ZZZ'
			);
		}

		return $notes;
	}

	/**
	 * Sanitise a Dolibarr rich-text value for the EN16931 payload: strip HTML tags,
	 * decode HTML entities (&eacute;, &amp;, &#039;…) to their UTF-8 characters, and
	 * collapse whitespace. strip_tags() alone leaves entities literal, so accented
	 * text stored as entities is transmitted mangled (issue #31).
	 *
	 * @param  string $html  Raw description / note, possibly containing HTML
	 * @return string        Clean UTF-8 plain text
	 */
	private function cleanText($html)
	{
		if ($html === null || $html === '') {
			return '';
		}
		$text = strip_tags((string) $html);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return trim(preg_replace('/\s+/', ' ', $text));
	}

	/**
	 * Resolve the reference of a Dolibarr contract linked to the invoice (BT-12).
	 * Queries the element_element link table directly: fetchObjectLinked() silently
	 * skips objects whose module is disabled, and the link may be stored in either
	 * direction, so a direct lookup is the robust way to find the contract.
	 *
	 * @param  Facture $object  The invoice
	 * @return string           The contract ref, or '' when none is linked
	 */
	private function resolveLinkedContractRef($object)
	{
		if (empty($object->id)) {
			return '';
		}
		$id = (int) $object->id;
		$sql = "SELECT fk_source AS cid FROM " . $this->db->prefix() . "element_element";
		$sql .= " WHERE targettype = 'facture' AND fk_target = " . $id . " AND sourcetype = 'contrat'";
		$sql .= " UNION SELECT fk_target AS cid FROM " . $this->db->prefix() . "element_element";
		$sql .= " WHERE sourcetype = 'facture' AND fk_source = " . $id . " AND targettype = 'contrat'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return '';
		}
		$row = $this->db->fetch_object($resql);
		if (empty($row) || empty($row->cid)) {
			return '';
		}
		require_once DOL_DOCUMENT_ROOT . '/contrat/class/contrat.class.php';
		$linked_contract = new Contrat($this->db);
		if ($linked_contract->fetch((int) $row->cid) > 0 && !empty($linked_contract->ref)) {
			return $linked_contract->ref;
		}
		return '';
	}

	/**
	 * Map a Dolibarr unit (llx_c_units.rowid) to a UN/ECE Rec. 20 unit code for BT-130.
	 * Results are cached per unique fk_unit to avoid N SQL queries for N invoice lines.
	 *
	 * @param  int    $fk_unit  Dolibarr unit ID (0 or null = no unit)
	 * @return string           UN/ECE Rec. 20 unit code (default 'C62' = piece)
	 */
	private function resolveUnitCode($fk_unit)
	{
		static $cache = array();

		$fk_unit = (int) $fk_unit;
		if ($fk_unit <= 0) {
			return 'C62';
		}
		if (isset($cache[$fk_unit])) {
			return $cache[$fk_unit];
		}

		$sql = 'SELECT code FROM ' . MAIN_DB_PREFIX . 'c_units WHERE rowid = ' . $fk_unit . ' LIMIT 1';
		$res = $this->db->query($sql);
		if (!$res || $this->db->num_rows($res) === 0) {
			$cache[$fk_unit] = 'C62';
			return 'C62';
		}
		$row = $this->db->fetch_object($res);
		$dolibarr_code = strtolower(trim($row->code ?? ''));

		$map = array(
			'h'     => 'HUR', 'hr'    => 'HUR', 'heure' => 'HUR',
			'j'     => 'DAY', 'day'   => 'DAY', 'jour'  => 'DAY',
			'mois'  => 'MON', 'mon'   => 'MON',
			'an'    => 'ANN', 'ann'   => 'ANN', 'year'  => 'ANN',
			'kg'    => 'KGM', 'kgm'   => 'KGM',
			'km'    => 'KMT',
			'm'     => 'MTR',
			'm2'    => 'MTK',
			'm3'    => 'MTQ',
			'l'     => 'LTR', 'lt'    => 'LTR',
			'pc'    => 'C62', 'pce'   => 'C62', 'u'     => 'C62',
		);

		if (isset($map[$dolibarr_code])) {
			$cache[$fk_unit] = $map[$dolibarr_code];
		} else {
			dol_syslog('resolveUnitCode: unknown Dolibarr unit code "' . $dolibarr_code . '" (fk_unit=' . $fk_unit . ') — defaulting to C62', LOG_WARNING);
			$cache[$fk_unit] = 'C62';
		}

		return $cache[$fk_unit];
	}

	/**
	 * Map Dolibarr VAT fields to the correct EN16931 VAT category code (BT-151 / BT-95 / BT-118).
	 *
	 * Priority:
	 *   1. vat_src_code from llx_c_tva (AE, K, G, Z, O, EX…)
	 *   2. info_bits bitmask — bit 0 = not subject to VAT → O
	 *   3. rate > 0 → S, rate = 0 → E (default exempt)
	 *
	 * @param  float  $vat_rate     Effective VAT rate (e.g. 20.0, 0.0)
	 * @param  int    $info_bits    Dolibarr line info_bits bitmask
	 * @param  string $vat_src_code Dolibarr VAT source code from llx_c_tva
	 * @return string               EN16931 VAT category code
	 */
	public function resolveVatCategoryCode($vat_rate, $info_bits = 0, $vat_src_code = '')
	{
		if ($vat_rate > 0) {
			return 'S';
		}
		if (!empty($vat_src_code)) {
			$src = strtoupper(trim($vat_src_code));
			$map = array('AE' => 'AE', 'K' => 'K', 'G' => 'G', 'Z' => 'Z', 'O' => 'O', 'EX' => 'E', 'E' => 'E');
			if (isset($map[$src])) {
				return $map[$src];
			}
		}
		if ($info_bits & 1) {
			return 'O';
		}
		return 'E';
	}

	/**
	 * Resolve the VAT exemption reason (BT-120 text / BT-121 VATEX code) for a breakdown bucket.
	 *
	 * Applies only to exempt / out-of-scope / reverse-charge categories (E, G, K, O, AE).
	 * Resolution priority:
	 *   1. Invoice extrafield override (facturelect_vatex_code / facturelect_vatex_reason)
	 *   2. Third-party extrafield override (per-client default)
	 *   3. VAT dictionary column (llx_c_tva.einvoice_vatex, native since Dolibarr V24) — per rate
	 *   4. Category default from VatexMapper (standard French legal wording)
	 *
	 * The dictionary tier (3) is per VAT rate/code, so unlike the single-pair overrides it does
	 * NOT suffer from the mixed-regime limitation below: an invoice mixing an AE line and an E
	 * line gets the right VATEX on each bucket from the dictionary. Prefer configuring the
	 * dictionary over the invoice/third-party overrides when an invoice can mix exempt regimes.
	 *
	 * LIMITATION — single-regime override: an override (invoice or third party) is a single
	 * code+reason pair applied to EVERY exempt breakdown bucket of the invoice. It is meant for
	 * the common case where an invoice carries one exemption regime. On an invoice that mixes
	 * several exempt categories (e.g. a G export line AND a K intra-community line), the override
	 * stamps the same VATEX code on both buckets, which is incorrect. For mixed-regime invoices,
	 * leave the override empty and rely on the dictionary column or the per-category mapping.
	 *
	 * @param  string       $cat_code    EN16931 VAT category code for the bucket
	 * @param  CommonObject $object      The invoice object (carries array_options and thirdparty)
	 * @param  string       $dict_vatex  VATEX code read from the VAT dictionary for this bucket ('' if none)
	 * @return array|null                array('reason_code' => …, 'reason' => …) or null when no exemption applies
	 */
	public function resolveVatExemption($cat_code, $object, $dict_vatex = '')
	{
		if (!VatexMapper::isExemptCategory($cat_code)) {
			return null;
		}

		$default = VatexMapper::getDefaultExemption($cat_code);
		$code = $default['reason_code'];
		$reason = $default['reason'];

		$invoice_opts = is_object($object) && !empty($object->array_options) ? $object->array_options : array();
		$tp_opts = is_object($object) && !empty($object->thirdparty) && !empty($object->thirdparty->array_options)
			? $object->thirdparty->array_options : array();

		if (!empty($invoice_opts['options_facturelect_vatex_code'])) {
			$code = trim($invoice_opts['options_facturelect_vatex_code']);
			if (!empty($invoice_opts['options_facturelect_vatex_reason'])) {
				$reason = trim($invoice_opts['options_facturelect_vatex_reason']);
			} else {
				// No custom text: use the picked code's own description as BT-120 reason.
				$reason = VatexMapper::labelForCode($code) ?: $reason;
			}
		} elseif (!empty($tp_opts['options_facturelect_vatex_code'])) {
			$code = trim($tp_opts['options_facturelect_vatex_code']);
			if (!empty($tp_opts['options_facturelect_vatex_reason'])) {
				$reason = trim($tp_opts['options_facturelect_vatex_reason']);
			} else {
				$reason = VatexMapper::labelForCode($code) ?: $reason;
			}
		} elseif ($dict_vatex !== '') {
			// VATEX code pinned per VAT rate in the dictionary (BT-121). The dictionary stores
			// only the code, so derive the BT-120 reason text from the known label, keeping the
			// category default wording when the code is not in our curated list.
			$code = $dict_vatex;
			$reason = VatexMapper::labelForCode($code) ?: $reason;
		}

		return array('reason_code' => $code, 'reason' => $reason);
	}

	/**
	 * Read the VAT exemption reason code (VATEX / BT-121) pinned in the VAT dictionary.
	 *
	 * Since Dolibarr V24 the VAT dictionary table llx_c_tva carries an `einvoice_vatex`
	 * column (varchar(32)) so an admin can pin a VATEX code per VAT rate/code, directly from
	 * Setup → Dictionaries → VAT rates. This reads it for the c_tva row identified by the line's
	 * source code (BT-95/BT-118 vat_src_code = c_tva.code). It returns '' when:
	 *   - the column is absent (Dolibarr 20–23: the guarded query fails and the feature is
	 *     transparently skipped for the rest of the request),
	 *   - the line carries no source code (a bare 0% rate is ambiguous across the AE/E/K/G/O
	 *     regimes, which share taux = 0 but differ by code),
	 *   - or no matching active dictionary row defines a VATEX code.
	 *
	 * @param  string $vat_src_code  Dolibarr line source code (maps to llx_c_tva.code)
	 * @return string                A VATEX code (e.g. 'VATEX-EU-AE') or '' when none configured
	 */
	private function resolveDictionaryVatex($vat_src_code)
	{
		static $available = null;   // null: unknown yet, false: column absent for this request
		static $cache = array();

		$src = trim((string) $vat_src_code);
		if ($src === '' || $available === false) {
			return '';
		}
		if (isset($cache[$src])) {
			return $cache[$src];
		}

		$sql = 'SELECT einvoice_vatex FROM ' . MAIN_DB_PREFIX . 'c_tva'
			. " WHERE code = '" . $this->db->escape($src) . "'"
			. ' AND entity IN (' . getEntity('c_tva') . ')'
			. ' AND active = 1 LIMIT 1';
		$res = $this->db->query($sql);
		if ($res === false) {
			// Most likely the einvoice_vatex column does not exist (Dolibarr < 24): skip for good.
			$available = false;
			return '';
		}
		$available = true;
		$vatex = '';
		if ($this->db->num_rows($res) > 0) {
			$row = $this->db->fetch_object($res);
			$vatex = strtoupper(trim($row->einvoice_vatex ?? ''));
		}
		$cache[$src] = $vatex;
		return $vatex;
	}

	/**
	 * Write a custom log line to the dedicated electronic invoicing log file
	 *
	 * @param   string  $ref        Invoice reference
	 * @param   string  $level      Log level (INFO, SUCCESS, ERROR)
	 * @param   string  $message    The log message
	 * @return  void
	 */
	public function writeLog($ref, $level, $message)
	{
		$log_dir = DOL_DATA_ROOT . '/facturation_electronique';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		dol_mkdir($log_dir);

		$log_file = $log_dir . '/facturelect.log';
		$timestamp = dol_print_date(dol_now(), 'dayhourlog'); // Standard YYYY-MM-DD HH:MM:SS format
		$log_line = '[' . $timestamp . '] [' . strtoupper($level) . '] [' . $ref . '] ' . $message . "\n";
		file_put_contents($log_file, $log_line, FILE_APPEND);
	}

	/**
	 * Overload createFrom action (when creating invoice from a clone)
	 *
	 * @param   array           $parameters     Hook metadatas
	 * @param   CommonObject    $object         The new Invoice object (clone)
	 * @param   string          $action         Current action
	 * @param   HookManager     $hookmanager    Hook manager
	 * @return  int                             0
	 */
	public function createFrom($parameters, &$object, &$action, $hookmanager)
	{
		if (!getDolGlobalInt('FACTURELECT_FEATURE_EINVOICING', 1)) {
			return 0;
		}
		if (is_object($object) && $object->element === 'facture') {
			$object->array_options['options_facturelect_invoice_id'] = '';
			$object->array_options['options_facturelect_status'] = 'not_sent';
			$object->array_options['options_facturelect_send_date'] = '';

			$object->updateExtraField('facturelect_invoice_id');
			$object->updateExtraField('facturelect_status');
			$object->updateExtraField('facturelect_send_date');
		}
		return 0;
	}
}
