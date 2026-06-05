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

			$client = new FacturelectClient($this->db);
			$provider_name = $client->getProviderName();

			$token = newToken();

			$banner_html = '';
			if ($object->statut == 0) {
				// Draft
				$banner_html = '<div class="fe-alert fe-alert-info fe-invoice-status-banner" style="margin-bottom: 20px;">';
				$banner_html .= '<span class="fa fa-info-circle" style="font-size: 20px; margin-top: 2px;"></span>';
				$banner_html .= '<div><strong>Facturation Électronique (B2B)</strong><br/>';
				$banner_html .= 'Cette facture est actuellement à l\'état de <strong>Brouillon</strong>. Veuillez valider la facture pour l\'envoyer électroniquement via ' . dol_escape_htmltag($provider_name) . '.</div>';
				$banner_html .= '</div>';
			} else {
				if ($pdp_status === 'transmitted') {
					$banner_html = '<div class="fe-alert fe-alert-success fe-invoice-status-banner" style="margin-bottom: 20px;">';
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
					$banner_html = '<div class="fe-alert fe-alert-info fe-invoice-status-banner" style="margin-bottom: 20px;">';
					$banner_html .= '<span class="fa fa-clock" style="font-size: 20px; margin-top: 2px;"></span>';
					$banner_html .= '<div><strong>Facture en cours de traitement / File d\'attente</strong><br/>';
					$banner_html .= 'La facture est planifiée pour envoi au PDP et sera transmise sous peu.</div>';
					$banner_html .= '</div>';
				} elseif ($pdp_status === 'failed') {
					$banner_html = '<div class="fe-alert fe-alert-danger fe-invoice-status-banner" style="margin-bottom: 20px;">';
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
					$banner_html = '<div class="fe-alert fe-alert-info fe-invoice-status-banner" style="margin-bottom: 20px;">';
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
				if (!empty($buyer_siren)) {
					if ($pdp_status === 'transmitted') {
						echo '<a class="butAction fe-btn-secondary" id="fe-resend-btn" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=send_facturelect&token=' . $token . '">';
						echo '<span class="fa fa-paper-plane paddingrightonly"></span> Renvoyer au format électronique';
						echo '</a>';
					} else {
						echo '<a class="butAction fe-btn-primary" id="fe-send-btn" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=send_facturelect&token=' . $token . '">';
						echo '<span class="fa fa-paper-plane paddingrightonly"></span> ' . $langs->trans('FacturelectSendInvoice');
						echo '</a>';
					}
				} else {
					echo '<a class="butAction fe-btn-warning" id="fe-associate-btn" href="#" onclick="feOpenModal(' . $thirdparty_id . '); return false;" title="SIREN manquant. Cliquer pour rechercher et associer ce tiers.">';
					echo '<span class="fa fa-search paddingrightonly"></span> Associer le tiers (requis)';
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

		$clean_buyer_siren = preg_replace('/\s+/', '', $object->thirdparty->idprof1);
		if (empty($clean_buyer_siren)) {
			$client = new FacturelectClient($this->db);
			$this->error = "Le SIREN (Prof Id 1) du client est requis pour l envoi via " . $client->getProviderName();
			return false;
		}

		$clean_seller_siren = preg_replace('/\s+/', '', $mysoc->idprof1);
		if (empty($clean_seller_siren)) {
			$this->error = "Le SIREN (Prof Id 1) de votre propre entreprise n est pas configure dans Dolibarr";
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
		$lines = array();
		$line_count = 1;
		
		foreach ($object->lines as $line) {
			$qty = !empty($line->qty) ? floatval($line->qty) : 1.0;
			$net_price = floatval($line->subprice); // price HT

			$lines[] = array(
				'identifier' => (string) $line_count,
				'invoiced_quantity' => sprintf("%.2f", $qty),
				'invoiced_quantity_code' => 'C62', // Pieces
				'net_amount' => sprintf("%.2f", $line->total_ht),
				'price_details' => array(
					'item_net_price' => sprintf("%.2f", $net_price)
				),
				'vat_information' => array(
					'invoiced_item_vat_category_code' => 'S',
					'invoiced_item_vat_rate' => sprintf("%.1f", floatval($line->tva_tx))
				),
				'item_information' => array(
					'name' => !empty($line->desc) ? strip_tags($line->desc) : 'Ligne de facture'
				)
			);
			$line_count++;
		}

		// Gather VAT breakdowns
		$vat_breakdowns = array();
		$vat_details = array();
		if (!empty($object->lines)) {
			foreach ($object->lines as $line) {
				$rate = sprintf("%.1f", floatval($line->tva_tx));
				if (!isset($vat_details[$rate])) {
					$vat_details[$rate] = array(
						'taxable_amount' => 0.0,
						'tax_amount' => 0.0
					);
				}
				$vat_details[$rate]['taxable_amount'] += floatval($line->total_ht);
				$vat_details[$rate]['tax_amount'] += floatval($line->total_tva);
			}
		}

		foreach ($vat_details as $rate => $amounts) {
			$vat_breakdowns[] = array(
				'vat_category_taxable_amount' => sprintf("%.2f", $amounts['taxable_amount']),
				'vat_category_tax_amount' => sprintf("%.2f", $amounts['tax_amount']),
				'vat_category_code' => 'S',
				'vat_category_rate' => $rate
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
			'notes' => array(
				array(
					'note' => 'Indemnite forfaitaire pour frais de recouvrement de 40 euros en cas de retard de paiement.',
					'subject_code' => 'PMT'
				),
				array(
					'note' => 'Penalites de retard applicables en cas de non-paiement a l\'echeance au taux de 10% par an.',
					'subject_code' => 'PMD'
				),
				array(
					'note' => 'Pas d\'escompte consenti pour paiement anticipe.',
					'subject_code' => 'AAB'
				)
			),
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
				'vat_identifier' => !empty($mysoc->tva_intra) ? $mysoc->tva_intra : 'FR00000000000',
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
				'vat_identifier' => !empty($object->thirdparty->tva_intra) ? $object->thirdparty->tva_intra : 'FR00000000000',
				'electronic_address' => array(
					'value' => $buyer_identifier,
					'scheme' => $buyer_scheme
				)
			),
			'totals' => array(
				'sum_invoice_lines_amount' => sprintf("%.2f", floatval($object->total_ht)),
				'total_without_vat' => sprintf("%.2f", floatval($object->total_ht)),
				'total_with_vat' => sprintf("%.2f", floatval($object->total_ttc)),
				'paid_amount' => sprintf("%.2f", floatval($object->total_ttc - $object->remains_to_pay)),
				'amount_due_for_payment' => sprintf("%.2f", floatval($object->remains_to_pay)),
				'rounding_amount' => '0.00',
				'sum_allowances_amount' => '0.00',
				'sum_charges_amount' => '0.00',
				'total_vat_amount' => array(
					'value' => sprintf("%.2f", floatval($object->total_tva))
				)
			),
			'vat_break_down' => $vat_breakdowns,
			'lines' => $lines
		);

		if (!empty($object->date_lim_reglement)) {
			$en_invoice['payment_due_date'] = dol_print_date($object->date_lim_reglement, '%Y-%m-%d');
		}
		if (!empty($object->ref_client)) {
			$en_invoice['purchase_order_reference'] = $object->ref_client;
		}

		return $en_invoice;
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
