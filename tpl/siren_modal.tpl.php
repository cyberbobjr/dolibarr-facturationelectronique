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
 *	\file       htdocs/custom/facturationelectronique/tpl/siren_modal.tpl.php
 *	\ingroup    facturationelectronique
 *	\brief      Shared SIREN lookup modal (search a company, pick an establishment, associate it)
 *
 *	Single source of truth for the lookup modal. Included by:
 *	  - ActionsFacturationelectronique::addMoreActionsButtons() (third-party and invoice cards)
 *	  - tiers_sans_siren.php (operational list of third parties without a SIREN)
 *
 *	Optional variables the includer may define beforehand:
 *	  int    $fe_modal_socid          Third-party id preselected (0 when each row carries its own)
 *	  string $fe_modal_prefill_name   Value prefilled in the name field
 *	  string $fe_modal_prefill_zip    Value prefilled in the postcode field
 *
 *	Public JS entry points: feOpenModal(socid), feOpenModalForRow(socid, name, zip).
 */

// Defaults, so the template can be included without any setup
$fe_modal_socid = isset($fe_modal_socid) ? (int) $fe_modal_socid : 0;
$fe_modal_prefill_name = isset($fe_modal_prefill_name) ? $fe_modal_prefill_name : '';
$fe_modal_prefill_zip = isset($fe_modal_prefill_zip) ? $fe_modal_prefill_zip : '';

if (!class_exists('FacturelectDirectoryFactory')) {
	require_once dirname(__FILE__) . '/../class/facturelectdirectoryfactory.class.php';
}

$fe_modal_lookup_url = dol_buildpath('/facturationelectronique/siren_lookup.php', 1);

// Identification sources. The list is static and module-controlled, so it can be embedded
// as-is inside the JS template literal below.
$fe_modal_default_source = FacturelectDirectoryFactory::getDefaultCode();
$fe_modal_source_options = '';
foreach (FacturelectDirectoryFactory::getAvailableSources() as $fe_source_code => $fe_source_label) {
	$fe_modal_source_options .= '<option value="' . dol_escape_htmltag($fe_source_code) . '"'
		. ($fe_source_code === $fe_modal_default_source ? ' selected' : '') . '>'
		. dol_escape_htmltag($fe_source_label) . '</option>';
}
?>
<script type="text/javascript">
/* Shared SIREN lookup modal.
   Everything is attached to window and guarded by feSirenModalLoaded: a page that both
   renders the list and triggers the hook would otherwise redeclare the same bindings and
   break the whole script with "Identifier has already been declared". */
if (!window.feSirenModalLoaded) {
	window.feSirenModalLoaded = true;

	window.feSocId = <?php echo (int) $fe_modal_socid; ?>;
	window.fePrefillName = "<?php echo dol_escape_js($fe_modal_prefill_name); ?>";
	window.fePrefillZip = "<?php echo dol_escape_js($fe_modal_prefill_zip); ?>";
	window.feCurrentCompanies = [];

	const feLookupUrl = '<?php echo dol_escape_js($fe_modal_lookup_url); ?>';

	/* Open the modal for a row of a list, carrying that row's own third party and prefills */
	window.feOpenModalForRow = function (socid, name, zip) {
		window.fePrefillName = name;
		window.fePrefillZip = zip;
		window.feOpenModal(socid);
	};

	window.feOpenModal = function (socid) {
		window.feSocId = socid;
		if (!document.getElementById('fe-siren-modal')) {
			feInjectModalHtml();
		}
		document.getElementById('fe-search-name').value = window.fePrefillName;
		document.getElementById('fe-search-zip').value = window.fePrefillZip;
		document.getElementById('fe-modal-content').innerHTML = '';
		document.getElementById('fe-siren-modal').classList.remove('fe-hidden');
	};

	window.feCloseModal = function () {
		const modal = document.getElementById('fe-siren-modal');
		if (modal) {
			modal.classList.add('fe-hidden');
		}
	};

	function feInjectModalHtml() {
		const html = `
			<div id="fe-siren-modal" class="fe-modal-overlay fe-hidden">
				<div class="fe-modal-container">
					<div class="fe-modal-header">
						<h3><span class="fa fa-search"></span> Recherche d'entreprise (annuaires officiels)</h3>
						<button type="button" class="fe-modal-close" onclick="feCloseModal()">&times;</button>
					</div>
					<div class="fe-modal-body">
						<div class="fe-modal-form">
							<div class="fe-form-group" style="grid-column: 1 / -1; margin: 0;">
								<label>Source de données</label>
								<select id="fe-search-source" class="fe-input" style="max-width: 340px;"><?php echo $fe_modal_source_options; ?></select>
							</div>
							<div class="fe-form-group">
								<label>Nom, SIREN ou SIRET</label>
								<input type="text" id="fe-search-name" class="fe-input" placeholder="Nom de l'entreprise, SIREN ou SIRET...">
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

						<div id="fe-modal-content" class="fe-modal-content"></div>
					</div>
				</div>
			</div>
		`;
		document.body.insertAdjacentHTML('beforeend', html);
	}

	/* Mirrors BaseFacturelectDirectory::normalizeSearchText() closely enough to decide
	   whether the backend had to broaden the search. */
	function feNormalizeQuery(value) {
		return (value || '')
			.toLowerCase()
			.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
			.replace(/[^a-z0-9]+/g, ' ')
			.trim();
	}

	window.fePerformSearch = function () {
		const name = document.getElementById('fe-search-name').value;
		const zip = document.getElementById('fe-search-zip').value;
		const source = document.getElementById('fe-search-source').value;
		const loader = document.getElementById('fe-modal-loader');
		const content = document.getElementById('fe-modal-content');

		if (!name) {
			alert('Veuillez saisir un nom, un SIREN ou un SIRET pour la recherche.');
			return;
		}

		loader.classList.remove('fe-hidden');
		content.innerHTML = '';
		window.feCurrentCompanies = [];

		const searchParams = new URLSearchParams({
			action: 'search_companies',
			name: name,
			zip: zip,
			source: source
		});

		fetch(feLookupUrl + '?' + searchParams.toString())
			.then(response => response.json())
			.then(data => {
				loader.classList.add('fe-hidden');
				if (!data.success) {
					content.innerHTML = '<div class="fe-no-results fe-text-danger">' + (data.error || 'Erreur inconnue') + '</div>';
					return;
				}
				if (data.companies && data.companies.length > 0) {
					window.feCurrentCompanies = data.companies;
					let html = '<div class="fe-companies-list">';
					html += '<h4>Entreprises trouvées (' + data.companies.length + ')</h4>';
					if (data.used_query && feNormalizeQuery(data.used_query) !== feNormalizeQuery(name)) {
						html += '<div class="fe-search-hint">Aucun résultat exact : recherche élargie sur <strong>« ' + data.used_query + ' »</strong>.</div>';
					}
					data.companies.forEach((company, index) => {
						const inactiveBadge = company.is_active === false
							? ' <span class="fe-status-pill danger">Cessée</span>'
							: '';
						const siretLine = company.siret
							? `<strong>SIRET siège :</strong> ${company.siret}<br/>`
							: '';
						html += `
							<div class="fe-company-card">
								<div class="fe-company-info">
									<div class="fe-company-name">${company.formal_name}${inactiveBadge}</div>
									<div class="fe-company-details">
										<strong>SIREN :</strong> ${company.number}<br/>
										${siretLine}
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
					content.innerHTML = '<div class="fe-no-results">Aucune entreprise correspondante trouvée'
						+ (data.used_query ? ' (dernière tentative : « ' + data.used_query + ' »)' : '')
						+ '. Essayez un nom plus court, ou saisissez directement le SIREN.</div>';
				}
			})
			.catch(() => {
				loader.classList.add('fe-hidden');
				content.innerHTML = '<div class="fe-no-results fe-text-danger">Erreur lors de la communication avec le serveur.</div>';
			});
	};

	/* Step 2: the PEPPOL electronic addresses always come from the PDP, whatever
	   identification source produced the SIREN. */
	window.feSelectCompanyByIndex = function (index) {
		const company = window.feCurrentCompanies[index];
		if (!company) {
			return;
		}

		const loader = document.getElementById('fe-modal-loader');
		const content = document.getElementById('fe-modal-content');

		loader.classList.remove('fe-hidden');
		content.innerHTML = '';

		fetch(feLookupUrl + '?action=get_entries&siren=' + encodeURIComponent(company.number))
			.then(response => response.json())
			.then(data => {
				loader.classList.add('fe-hidden');
				if (data.success && data.entries && data.entries.length > 0) {
					let html = '<div class="fe-entries-list">';
					html += '<h4>Établissements / Adresses de facturation active</h4>';
					html += '<table class="fe-results-table"><thead><tr><th>Établissement / ID PEPPOL</th><th>Statut</th><th>Action</th></tr></thead><tbody>';
					data.entries.forEach(entry => {
						// Identifiers are decomposed server-side (FacturelectPeppolId): the modal
						// must never parse a routing address itself.
						const establishmentLabel = entry.label || entry.identifier;

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
					html += `
						<div style="margin-top: 15px; text-align: right;">
							<button type="button" class="fe-btn fe-btn-secondary" onclick="feAssociateTiers(${index}, '${company.number}', '0225')">
								Associer le SIREN uniquement (sans établissement spécifique)
							</button>
						</div>
					`;
					content.innerHTML = html;
				} else {
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
			.catch(() => {
				loader.classList.add('fe-hidden');
				content.innerHTML = '<div class="fe-no-results fe-text-danger">Erreur lors de la récupération des établissements.</div>';
			});
	};

	window.feAssociateTiers = function (companyIndex, identifier, scheme) {
		const company = window.feCurrentCompanies[companyIndex];
		if (!company) {
			return;
		}

		const updateDetails = document.getElementById('fe-update-details').checked ? 1 : 0;

		let msg;
		if (updateDetails) {
			msg = `Attention : En associant ce tiers, les informations locales de votre Dolibarr seront écrasées et synchronisées par les coordonnées officielles de l'annuaire :\n\n`
				+ `- Nom : ${company.formal_name}\n`
				+ `- Adresse : ${company.address}\n`
				+ `- Code Postal : ${company.postcode}\n`
				+ `- Ville : ${company.city}\n\n`
				+ `Souhaitez-vous continuer ?`;
		} else {
			msg = `Souhaitez-vous associer le SIREN et l'adresse de réception électronique pour ce tiers sans modifier son nom et son adresse locale ?`;
		}
		if (!confirm(msg)) {
			return;
		}

		const loader = document.getElementById('fe-modal-loader');
		const content = document.getElementById('fe-modal-content');

		loader.classList.remove('fe-hidden');
		content.innerHTML = '';

		const params = new URLSearchParams({
			action: 'update_tiers',
			socid: window.feSocId,
			identifier: identifier,
			scheme: scheme,
			name: company.formal_name,
			address: company.address,
			zip: company.postcode,
			city: company.city,
			update_details: updateDetails
		});

		fetch(feLookupUrl + '?' + params.toString())
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
								${data.siren ? '- SIREN : ' + data.siren + '<br/>' : ''}
								${data.siret ? '- SIRET : ' + data.siret + '<br/>' : ''}
								- Adresse de réception : ${data.label}<br/>
								- Identifiant PEPPOL : ${data.scheme}:${data.identifier}
							</div>
						</div>
					`;
					setTimeout(() => {
						window.feCloseModal();
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
			.catch(() => {
				loader.classList.add('fe-hidden');
				content.innerHTML = '<div class="fe-no-results fe-text-danger">Erreur réseau lors de l\'association.</div>';
			});
	};
}
</script>
