<?php
/* Copyright (C) 2026 Benjamin Marchand <ben.marchand@free.fr> */
/** @var array $fe_diagnostic_current Current routing context, authorized by the invoice page */
if (!isset($fe_diagnostic_current, $object, $user) || !is_array($fe_diagnostic_current)) {
	http_response_code(403);
	exit;
}
$fe_current = $fe_diagnostic_current;
$fe_check = is_array($fe_diagnostic_checks) ? ($fe_diagnostic_checks[0] ?? null) : null;
$fe_latest = is_array($fe_diagnostic_attempts) ? ($fe_diagnostic_attempts[0] ?? null) : null;
$fe_panel_url = dol_buildpath('/facturationelectronique/invoice_facturelect_tab.php', 1).'?id='.(int) $object->id.'&type=customer';
$fe_source_labels = array('invoice' => $langs->trans('FacturelectBuyerAddressSource'), 'invoice_sandbox' => $langs->trans('FacturelectBuyerAddressSource'), 'associated' => 'Adresse associée au tiers', 'siren' => 'Repli sur le SIREN du tiers',
	'associated_sandbox' => 'Adresse associée, adaptée au bac à sable', 'siren_sandbox' => 'SIREN adapté au bac à sable');
$fe_stage_labels = array('prepare' => 'Préparation du document', 'convert' => 'Conversion Factur-X',
	'deposit' => 'Dépôt auprès de la plateforme', 'interrupted' => 'Traitement interrompu');
$fe_directory_labels = array('active' => 'Adresse trouvée et active dans l’annuaire français',
	'empty' => 'Aucune adresse retournée par l’annuaire français', 'not_active' => 'Adresse utilisée non trouvée parmi les adresses actives',
	'error' => 'Échec de la consultation de l’annuaire', 'unverified' => 'Annuaire non vérifié');
?>
<section class="fe-card fe-diagnostic" aria-labelledby="fe-diagnostic-title">
	<h2 id="fe-diagnostic-title" class="fe-card-title"><span class="fa fa-stethoscope" aria-hidden="true"></span> Diagnostic de transmission <button type="button" id="fe-diagnostic-copy" class="butAction" style="margin-left:auto;"><?php echo $langs->trans('FacturelectDiagnosticCopy'); ?></button></h2>
	<p id="fe-diagnostic-copy-status" role="status" aria-live="polite"></p>
	<?php if ($fe_diagnostic_attempts === false || $fe_diagnostic_checks === false) { ?>
		<p class="error">Le journal de diagnostic est indisponible. La configuration actuelle reste consultable.</p>
	<?php } ?>
	<div class="fe-diagnostic-grid">
		<div>
			<h3>Configuration actuelle</h3>
			<dl>
				<dt>Plateforme / environnement</dt><dd><?php echo dol_escape_htmltag($fe_current['provider'].' / '.$fe_current['mode']); ?></dd>
				<dt>Destinataire</dt><dd><?php echo dol_escape_htmltag($fe_current['routing']['name']); ?></dd>
				<dt>SIREN / SIRET</dt><dd><?php echo dol_escape_htmltag(($fe_current['routing']['siren'] ?: 'Non renseigné').' / '.($fe_current['routing']['siret'] ?: 'Non renseigné')); ?></dd>
				<dt>Adresse prévue</dt><dd><code><?php echo dol_escape_htmltag($fe_current['routing']['scheme'].':'.$fe_current['routing']['identifier']); ?></code></dd>
				<dt>Origine de l’adresse</dt><dd><?php echo dol_escape_htmltag($fe_source_labels[$fe_current['routing']['source']] ?? $fe_current['routing']['source']); ?></dd>
			</dl>
			<p class="fe-diagnostic-note">Une adresse enregistrée sur le tiers ne prouve pas qu’elle est active. Les contrôles ne déposent aucune facture.</p>
		</div>
		<div>
			<h3>Dernière vérification du destinataire</h3>
			<?php if ($fe_check) {
				$fe_same_context = $fe_check['mode'] === $fe_current['mode'] && $fe_check['provider'] === $fe_current['provider']
					&& $fe_check['routing']['scheme'] === $fe_current['routing']['scheme']
					&& $fe_check['routing']['identifier'] === $fe_current['routing']['identifier']
					&& $fe_check['routing']['siren'] === $fe_current['routing']['siren']; ?>
				<p><?php echo dol_escape_htmltag($fe_check['at']); ?> — <?php echo dol_escape_htmltag($fe_check['mode']); ?></p>
				<?php if (!$fe_same_context) { ?><p class="warning">La configuration ou l’adresse a changé depuis ce contrôle. Vérifiez à nouveau.</p><?php } ?>
				<p><strong>Connexion :</strong> <?php echo $fe_check['connection']['ok'] ? 'Contrôlée avec succès' : 'Échec du contrôle'; ?></p>
				<?php if (!empty($fe_check['connection']['error'])) { ?><p class="error"><?php echo dol_escape_htmltag($fe_check['connection']['error']); ?></p><?php } ?>
				<p><strong><?php echo dol_escape_htmltag($fe_directory_labels[$fe_check['directory']['status']] ?? 'Annuaire non vérifié'); ?></strong></p>
				<?php if (!empty($fe_check['directory']['http'])) { ?><p><code><?php echo dol_escape_htmltag(($fe_check['directory']['http']['method'] ?? '').' '.($fe_check['directory']['http']['path'] ?? '').' — HTTP '.($fe_check['directory']['http']['status'] ?? 'non reçu')); ?></code></p><?php } ?>
				<?php if (!empty($fe_check['directory']['error'])) { ?><p class="error"><?php echo dol_escape_htmltag($fe_check['directory']['error']); ?></p><?php } ?>
				<?php if (!empty($fe_check['directory']['entries'])) { ?>
					<div class="div-table-responsive"><table class="noborder centpercent"><caption>Adresses retournées pour <?php echo dol_escape_htmltag($fe_check['routing']['siren']); ?></caption>
						<thead><tr class="liste_titre"><th>Adresse</th><th>État / usage</th></tr></thead><tbody>
						<?php foreach ($fe_check['directory']['entries'] as $fe_entry) { ?>
							<tr><td><code><?php echo dol_escape_htmltag($fe_entry['identifier']); ?></code><br><?php echo dol_escape_htmltag($fe_entry['label']); ?></td>
								<td><?php echo $fe_entry['active'] ? 'Active' : 'Inactive ou état inconnu'; ?><?php echo $fe_entry['technical'] ? ' — retour technique, pas un destinataire' : ($fe_entry['matches'] ? ' — adresse utilisée' : ''); ?></td></tr>
						<?php } ?></tbody></table></div>
				<?php } ?>
				<?php if ($user->admin) { ?><details><summary>Détails du contrôle — administrateur</summary><pre><?php echo htmlspecialchars(json_encode($fe_check, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre></details><?php } ?>
			<?php } else { ?><p>Connexion et annuaire non vérifiés pour cette facture.</p><?php } ?>
			<p class="fe-diagnostic-note">Ce contrôle consulte l’annuaire français via la plateforme. Il ne vérifie pas indépendamment PEPPOL. Une synchronisation ou une portabilité peut affecter une ligne ; ce panneau ne permet pas de l’affirmer.</p>
		</div>
	</div>
	<div class="fe-diagnostic-actions">
		<form method="post" action="<?php echo dol_escape_htmltag($fe_panel_url); ?>#fe-diagnostic-title">
			<input type="hidden" name="token" value="<?php echo dol_escape_htmltag(newToken()); ?>">
			<input type="hidden" name="action" value="diagnostic_check">
			<button type="submit" class="butAction">Vérifier le destinataire</button>
		</form>
		<?php if (getDolGlobalInt('FACTURELECT_FEATURE_SIREN', 1) && ($user->admin || !empty($user->rights->societe->creer))) { ?>
			<button type="button" class="butAction" onclick="feOpenModal(<?php echo (int) $object->socid; ?>)">Choisir une adresse active</button>
		<?php } ?>
		<a class="butAction" href="<?php echo dol_escape_htmltag($fe_panel_url.'&action=diagnostic_export'); ?>">Exporter le diagnostic masqué</a>
		<a href="https://www.superpdp.tech/outils/info-annuaire" target="_blank" rel="noopener noreferrer">Consulter les annuaires <span class="fa fa-external-link-alt" aria-hidden="true"></span></a>
	</div>
	<h3>Tentatives de transmission</h3>
	<?php if (!$fe_latest) { ?>
		<p>Aucune tentative conservée pour cette facture. Les anciens fichiers de diagnostic communs ne permettent pas de reconstituer un historique fiable.</p>
	<?php } else { ?>
		<p>Les 20 dernières tentatives conservées sont affichées. Un dépôt accepté ne garantit pas la livraison : consultez les événements de la plateforme ci-dessous.</p>
		<?php foreach ($fe_diagnostic_attempts as $fe_index => $fe_attempt) {
			$fe_steps = $fe_attempt['steps'];
			$fe_last_step = end($fe_steps);
			$fe_missing = strpos($fe_last_step['error'] ?? '', 'does not exist in peppol directory') !== false;
			$fe_outcome = $fe_missing ? 'Dépôt refusé : adresse introuvable dans PEPPOL' : ($fe_last_step['ok'] ? 'Dépôt accepté par la plateforme' : 'Échec : '.($fe_stage_labels[$fe_last_step['stage']] ?? 'traitement'));
			?>
			<details class="fe-diagnostic-attempt" <?php echo $fe_index === 0 ? 'open' : ''; ?>>
				<summary><?php echo dol_escape_htmltag('Tentative #'.$fe_attempt['log_id'].' — '.$fe_attempt['at'].' — '.$fe_outcome); ?></summary>
				<p><strong>Configuration utilisée :</strong> <?php echo dol_escape_htmltag($fe_attempt['provider'].' / '.$fe_attempt['mode']); ?><br>
					<strong>Adresse utilisée :</strong> <code><?php echo dol_escape_htmltag($fe_attempt['routing']['scheme'].':'.$fe_attempt['routing']['identifier']); ?></code><br>
					<strong>Origine :</strong> <?php echo dol_escape_htmltag($fe_source_labels[$fe_attempt['routing']['source']] ?? $fe_attempt['routing']['source']); ?></p>
				<?php if ($fe_missing) { ?><p class="warning">Vérifiez les adresses actives du destinataire. Ce rejet ne prouve pas que l’entreprise est absente de l’annuaire français.</p><?php } ?>
				<ol>
					<?php foreach ($fe_attempt['steps'] as $fe_step) { ?>
						<li><strong><?php echo dol_escape_htmltag($fe_stage_labels[$fe_step['stage']] ?? $fe_step['stage']); ?> :</strong> <?php echo $fe_step['ok'] ? 'Réussie' : 'Échec'; ?> — <?php echo dol_escape_htmltag($fe_step['at']); ?>
							<?php if (!empty($fe_step['http'])) { ?><br><code><?php echo dol_escape_htmltag(($fe_step['http']['method'] ?? '').' '.($fe_step['http']['path'] ?? '').' — HTTP '.($fe_step['http']['status'] ?? 'non reçu')); ?></code><?php } ?>
							<?php if (!empty($fe_step['error'])) { ?><p class="error"><?php echo dol_escape_htmltag($fe_step['error']); ?></p><?php } ?>
						</li>
					<?php } ?>
					<li><strong>Livraison :</strong> non déterminée par ce diagnostic ; consulter le suivi de la plateforme.</li>
				</ol>
				<?php if (!empty($fe_attempt['pdp_id'])) { ?><p>Identifiant plateforme : <code><?php echo dol_escape_htmltag($fe_attempt['pdp_id']); ?></code></p><?php } ?>
				<?php if ($user->admin) { ?>
					<details><summary>Détails techniques complets — administrateur</summary><p>Contient des données de facturation. L’export support masque ces données.</p>
						<pre><?php echo htmlspecialchars(json_encode($fe_attempt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>
					</details>
				<?php } ?>
			</details>
		<?php } ?>
	<?php } ?>
</section>

<script>
(function () {
	const button = document.getElementById('fe-diagnostic-copy');
	const status = document.getElementById('fe-diagnostic-copy-status');
	button.addEventListener('click', async function () {
		const content = button.closest('.fe-diagnostic').cloneNode(true);
		content.querySelectorAll('button, form, script, #fe-diagnostic-copy-status, .fe-diagnostic-actions').forEach(node => node.remove());
		const text = content.textContent.trim();
		try {
			if (navigator.clipboard && window.isSecureContext) {
				await navigator.clipboard.writeText(text);
			} else {
				const field = document.createElement('textarea');
				field.value = text;
				field.style.position = 'fixed';
				field.style.opacity = '0';
				document.body.appendChild(field);
				field.select();
				let copied;
				try { copied = document.execCommand('copy'); } finally { field.remove(); button.focus(); }
				if (!copied) { throw new Error(); }
			}
			status.textContent = '<?php echo dol_escape_js($langs->transnoentities('FacturelectDiagnosticCopied')); ?>';
		} catch (error) {
			status.textContent = '<?php echo dol_escape_js($langs->transnoentities('FacturelectDiagnosticCopyError')); ?>';
		}
	});
})();
</script>
