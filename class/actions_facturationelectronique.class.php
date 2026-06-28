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

		// 1. Third-party Card & Invoice Card (for fast SIREN lookup modal)
		if ($parameters['currentcontext'] === 'thirdpartycard' || $parameters['currentcontext'] === 'invoicecard') {
			// Ensure CSS is loaded
			echo '<link rel="stylesheet" type="text/css" href="' . $this->getCssUrl() . '">';

			$client = new FacturelectClient($this->db);
			$provider_name = $client->getProviderName();

			if ($parameters['currentcontext'] === 'thirdpartycard') {
				$siren = !empty($object->siren) ? $object->siren : $object->idprof1;
				$btn_text = empty($siren) ? "Rechercher SIREN (" . $provider_name . ")" : "Vérifier/Mettre à jour SIREN (" . $provider_name . ")";

				// Render a very premium action button
				echo '<a class="butAction fe-btn-primary" id="fe-verify-btn" href="#" onclick="feOpenModal(' . $thirdparty_id . '); return false;">';
				echo '<span class="fa fa-search paddingrightonly"></span> ' . $btn_text;
				echo '</a>';
			}

			// Add JavaScript for modern real-time visual alerts and dynamic updates
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
		}

		// 2. Customer Invoice Card
		if ($parameters['currentcontext'] === 'invoicecard') {
			// Ensure CSS is loaded
			echo '<link rel="stylesheet" type="text/css" href="' . $this->getCssUrl() . '">';

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

			// Validate SIREN formats (exactly 9 digits required or empty)
			$seller_siren_invalid = empty($seller_siren) || (strlen($seller_siren) !== 9 || !ctype_digit($seller_siren));
			$buyer_siren_invalid = empty($buyer_siren) || (strlen($buyer_siren) !== 9 || !ctype_digit($buyer_siren));

			// Check buyer PEPPOL routing association (facturelect_id extrafield)
			// In production: empty facturelect_id means routing falls back to raw SIREN — buyer may not be registered in PPF
			// In sandbox: SIREN always gets the sandbox prefix automatically, so no risk
			if (empty($object->thirdparty->array_options)) {
				$object->thirdparty->fetch_optionals();
			}
			$buyer_facturelect_id = !empty($object->thirdparty->array_options['options_facturelect_id']) ? $object->thirdparty->array_options['options_facturelect_id'] : '';
			$fe_mode = getDolGlobalString('FACTURATION_ELECTRONIQUE_MODE');
			$buyer_not_associated = empty($buyer_facturelect_id) && $fe_mode === 'production';

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
				$banner_html = $config_error_html . $warning_html . '<div class="fe-alert fe-alert-info fe-invoice-status-banner" style="margin-bottom: 20px;">';
				$banner_html .= '<span class="fa fa-info-circle" style="font-size: 20px; margin-top: 2px;"></span>';
				$banner_html .= '<div><strong>Facturation Électronique (B2B)</strong><br/>';
				$banner_html .= 'Cette facture est actuellement à l\'état de <strong>Brouillon</strong>. Veuillez valider la facture pour l\'envoyer électroniquement via ' . dol_escape_htmltag($provider_name) . '.</div>';
				$banner_html .= '</div>';
			} else {
				if ($pdp_status === 'transmitted') {
					$banner_html = $config_error_html . $warning_html . '<div class="fe-alert fe-alert-success fe-invoice-status-banner" style="margin-bottom: 20px;">';
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
					$banner_html = $config_error_html . $warning_html . '<div class="fe-alert fe-alert-info fe-invoice-status-banner" style="margin-bottom: 20px;">';
					$banner_html .= '<span class="fa fa-clock" style="font-size: 20px; margin-top: 2px;"></span>';
					$banner_html .= '<div><strong>Facture en cours de traitement / File d\'attente</strong><br/>';
					$banner_html .= 'La facture est planifiée pour envoi au PDP et sera transmise sous peu.</div>';
					$banner_html .= '</div>';
				} elseif ($pdp_status === 'failed') {
					$banner_html = $config_error_html . $warning_html . '<div class="fe-alert fe-alert-danger fe-invoice-status-banner" style="margin-bottom: 20px;">';
					$banner_html .= '<span class="fa fa-exclamation-triangle" style="font-size: 20px; margin-top: 2px;"></span>';
					$banner_html .= '<div><strong>Échec de la transmission électronique</strong><br/>';
					$banner_html .= 'La transmission a échoué. Veuillez vérifier les informations et réessayer.<br/>';
					if (empty($buyer_siren)) {
						$banner_html .= '<br/><span class="fa fa-warning"></span> <strong>Avertissement :</strong> Le SIREN (Identifiant Professionnel 1) de ce client n\'est pas configuré. C\'est nécessaire pour l\'envoi B2B.<br/>';
					}
					$banner_html .= '<a class="butAction fe-btn-primary" style="margin-top: 10px; display: inline-flex; align-items: center;" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=send_facturelect&token=' . $token . '">';
					$banner_html .= '<span class="fa fa-paper-plane paddingrightonly"></span> Relancer la transmission';
					$banner_html .= '</a>';
					$banner_html .= '</div></div>';
				} else { // not_sent
					$banner_html = $config_error_html . $warning_html . '<div class="fe-alert fe-alert-info fe-invoice-status-banner" style="margin-bottom: 20px;">';
					$banner_html .= '<span class="fa fa-file-invoice-dollar" style="font-size: 20px; margin-top: 2px;"></span>';
					$banner_html .= '<div><strong>Prête pour Facturation Électronique (B2B)</strong><br/>';
					$banner_html .= 'Cette facture est validée et peut être transmise instantanément sous forme de Factur-X certifié au réseau national via ' . dol_escape_htmltag($provider_name) . '.<br/>';
					if (empty($buyer_siren)) {
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

			// Dynamic JS Injection of the Banner
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
					'reason' => !empty($line->desc) ? strip_tags($line->desc) : 'Remise',
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
				'invoiced_quantity_code' => 'C62',
				'net_amount' => sprintf("%.2f", $total_ht),
				'price_details' => array(
					'item_net_price' => sprintf("%.2f", $net_price)
				),
				'vat_information' => array(
					'invoiced_item_vat_category_code' => $line_vat_code,
					'invoiced_item_vat_rate' => sprintf("%.1f", $line_vat_rate)
				),
				'item_information' => array(
					'name' => !empty($line->desc) ? strip_tags($line->desc) : 'Ligne de facture'
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
				$rate = sprintf("%.1f", floatval($line->tva_tx));
				$cat_code = $this->resolveVatCategoryCode(floatval($line->tva_tx), (int) ($line->info_bits ?? 0), $line->vat_src_code ?? '');
				$bucket = $rate . '#' . $cat_code;
				if (!isset($vat_details[$bucket])) {
					$vat_details[$bucket] = array(
						'rate' => $rate,
						'cat_code' => $cat_code,
						'taxable_amount' => 0.0,
						'tax_amount' => 0.0
					);
				}
				$vat_details[$bucket]['taxable_amount'] += floatval($line->total_ht);
				$vat_details[$bucket]['tax_amount'] += floatval($line->total_tva);
			}
		}

		foreach ($vat_details as $amounts) {
			$vat_breakdowns[] = array(
				'vat_category_taxable_amount' => sprintf("%.2f", $amounts['taxable_amount']),
				'vat_category_tax_amount' => sprintf("%.2f", $amounts['tax_amount']),
				'vat_category_code' => $amounts['cat_code'],
				'vat_category_rate' => $amounts['rate']
			);
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

		// Build final payload matching EN16931 exactly
		$en_invoice = array(
			'number' => $object->ref,
			'issue_date' => dol_print_date($object->date, '%Y-%m-%d'),
			'type_code' => $type_code,
			'currency_code' => 'EUR',
			'process_control' => array(
				'specification_identifier' => 'urn:cen.eu:en16931:2017',
				'business_process_type' => 'B1'
			),
			'notes' => $this->buildInvoiceNotes($object, $has_ae_lines),
			'seller' => array(
				'name' => $mysoc->name,
				'postal_address' => array(
					'address_line1' => !empty($mysoc->address) ? $mysoc->address : 'Adresse',
					'post_code' => !empty($mysoc->zip) ? $mysoc->zip : '00000',
					'city' => !empty($mysoc->town) ? $mysoc->town : 'Ville',
					'country_code' => !empty($mysoc->country_code) ? strtoupper($mysoc->country_code) : 'FR',
					'country_subdivision' => 'France'
				),
				'legal_registration_identifier' => array(
					'value' => $clean_seller_siren,
					'scheme' => '0225'
				),
				'trading_name' => $mysoc->name,
				'identifiers' => array(
					array('value' => $clean_seller_siren, 'scheme' => '0225')
				),
				'electronic_address' => array(
					'value' => ($mode !== 'production' && $active_provider === 'superpdp') ? '315143296_7182' : $clean_seller_siren,
					'scheme' => '0225'
				)
			),
			'buyer' => array(
				'name' => $object->thirdparty->name,
				'postal_address' => array(
					'address_line1' => !empty($object->thirdparty->address) ? $object->thirdparty->address : 'Adresse Client',
					'post_code' => !empty($object->thirdparty->zip) ? $object->thirdparty->zip : '00000',
					'city' => !empty($object->thirdparty->town) ? $object->thirdparty->town : 'Ville Client',
					'country_code' => !empty($object->thirdparty->country_code) ? strtoupper($object->thirdparty->country_code) : 'FR',
					'country_subdivision' => !empty($object->thirdparty->state) ? $object->thirdparty->state : 'France'
				),
				'legal_registration_identifier' => array(
					'value' => $legal_buyer_siren,
					'scheme' => '0225'
				),
				'trading_name' => $object->thirdparty->name,
				'identifiers' => array(
					array('value' => $legal_buyer_siren, 'scheme' => '0225')
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
				'total_without_vat' => sprintf("%.2f", floatval($object->total_ht)),
				'total_with_vat' => sprintf("%.2f", floatval($object->total_ttc)),
				'paid_amount' => sprintf("%.2f", $total_paid),
				'amount_due_for_payment' => sprintf("%.2f", $remains_to_pay),
				'rounding_amount' => '0.00',
				'total_vat_amount' => array(
					'currency_code' => 'EUR',
					'value' => sprintf("%.2f", floatval($object->total_tva))
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
		if (!empty($object->ref_client)) {
			$en_invoice['purchase_order_reference'] = $object->ref_client;
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

		if ($has_ae) {
			$notes[] = array(
				'note' => 'Autoliquidation de la TVA - Article 283-2 du CGI',
				'subject_code' => 'TAX'
			);
		}

		return $notes;
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
				'note' => strip_tags($free_text),
				'subject_code' => 'ZZZ'
			);
		}

		// Per-invoice public note
		if (!empty($object->note_public)) {
			$notes[] = array(
				'note' => strip_tags($object->note_public),
				'subject_code' => 'ZZZ'
			);
		}

		return $notes;
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
	private function resolveVatCategoryCode($vat_rate, $info_bits = 0, $vat_src_code = '')
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
