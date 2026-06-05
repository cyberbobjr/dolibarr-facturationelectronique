<?php
/* Copyright (C) 2026 Benjamin Marchand <contact@superpdp.tech>
 */

require_once '../../main.inc.php';
if (!class_exists('FacturelectClient')) {
	require_once './class/facturelectclient.class.php';
}

// Access control
if (!$user->rights->facture->lire) {
	print 'Accès refusé.';
	exit;
}

$pdp_id = GETPOST('id', 'int');
if (empty($pdp_id)) {
	print '<div style="color:#64748b; font-size:13px; padding:10px;">ID technique réseau invalide ou manquant.</div>';
	exit;
}

$client = new FacturelectClient($db);
$inv_data = $client->getInvoice($pdp_id);

if ($inv_data === false || empty($inv_data['events'])) {
	print '<div style="color:#ef4444; font-size:13px; padding:10px;"><span class="fa fa-exclamation-triangle"></span> Impossible de récupérer les événements pour cette facture (ID: ' . $pdp_id . '). Erreur : ' . dol_escape_htmltag($client->error) . '</div>';
	exit;
}

// Render a gorgeous responsive horizontal or vertical timeline
print '<div style="font-family:\'Outfit\', \'Inter\', sans-serif; padding:5px 15px;">';
print '  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">';
$provider_name = $client->getProviderName();
$langs->loadLangs(array("facturation_electronique@facturationelectronique"));
print '    <h4 style="margin:0; font-size:14px; font-weight:600; color:#1e293b;"><span class="fa fa-history" style="color:#0284c7;"></span> ' . $langs->trans("FacturelectTransmissionEventsHistory", $provider_name) . '</h4>';
print '    <span style="font-size:11px; background:#f1f5f9; color:#475569; padding:2px 8px; border-radius:12px; font-weight:500;">' . $langs->trans("FacturelectTabPdpTechId") . ': ' . $pdp_id . '</span>';
print '  </div>';

print '  <div class="fe-timeline" style="position:relative; padding-left:30px; margin-left:10px; border-left:2px solid #e2e8f0;">';

// Sort events by ID to ensure correct chronology
$events = $inv_data['events'];
usort($events, function($a, $b) {
	return $a['id'] - $b['id'];
});

foreach ($events as $index => $evt) {
	$status_code = $evt['status_code'];
	$status_text = $evt['status_text'];
	$created_at = strtotime($evt['created_at']);
	
	// Harmonious status styling based on PDP definitions
	$bullet_color = '#cbd5e1';
	$bg_color = '#f8fafc';
	$text_color = '#334155';
	$icon = 'fa-circle';
	
	if ($status_code === 'api:uploaded') {
		$bullet_color = '#0284c7';
		$bg_color = '#f0f9ff';
		$icon = 'fa-file-upload';
	} elseif (strpos($status_code, 'fr:200') !== false || strpos($status_code, 'fr:201') !== false || strpos($status_code, 'fr:202') !== false || $status_code === 'api:validated' || $status_code === 'api:sent') {
		$bullet_color = '#10b981';
		$bg_color = '#ecfdf5';
		$icon = 'fa-check';
	} elseif (strpos($status_code, 'fr:213') !== false || strpos($status_code, 'fr:210') !== false || $status_code === 'api:invalid' || $status_code === 'api:rejected' || strpos($status_code, 'ack-error') !== false) {
		$bullet_color = '#ef4444';
		$bg_color = '#fef2f2';
		$text_color = '#991b1b';
		$icon = 'fa-times-circle';
	} elseif (strpos($status_code, 'fr:212') !== false || strpos($status_code, 'fr:211') !== false) {
		$bullet_color = '#8b5cf6';
		$bg_color = '#f5f3ff';
		$icon = 'fa-money-bill-wave';
	}
	
	$is_latest = ($index === count($events) - 1);
	$latest_border = $is_latest ? 'border: 1px solid ' . $bullet_color . '; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);' : '';
	
	print '    <div style="position:relative; margin-bottom:15px;">';
	
	// Timeline circle marker with micro-animations style
	print '      <span style="position:absolute; left:-39px; top:4px; width:16px; height:16px; border-radius:50%; background:#fff; border:3px solid ' . $bullet_color . '; display:flex; align-items:center; justify-content:center; z-index:2; box-shadow:0 0 0 4px #fff;">';
	print '      </span>';
	
	// Card
	print '      <div style="background:' . $bg_color . '; padding:10px 15px; border-radius:8px; ' . $latest_border . '">';
	print '        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:5px;">';
	print '          <strong style="font-size:12px; color:' . $text_color . ';"><span class="fa ' . $icon . '" style="color:' . $bullet_color . '; margin-right:5px;"></span> ' . dol_escape_htmltag($status_text) . '</strong>';
	print '          <span style="font-size:11px; color:#64748b; font-weight:500;">' . dol_print_date($created_at, 'dayhour') . '</span>';
	print '        </div>';
	print '        <div style="font-size:10px; color:#64748b; margin-top:3px;">';
	print '          Code technique : <code style="background:rgba(0,0,0,0.04); padding:1px 4px; border-radius:3px; font-size:9px;">' . $status_code . '</code>';
	print '        </div>';
	
	// Handle reasons for rejections
	if (!empty($evt['details'])) {
		foreach ($evt['details'] as $detail) {
			if (!empty($detail['reason'])) {
				print '        <div style="margin-top:6px; padding:6px 10px; background:#fff; border-left:3px solid #ef4444; border-radius:4px; font-size:11px; color:#b91c1c; font-weight:500;">';
				print '          <span class="fa fa-info-circle"></span> Motif du rejet : <strong>' . dol_escape_htmltag($detail['reason']) . '</strong>';
				print '        </div>';
			}
		}
	}
	if (!empty($evt['data']) && !empty($evt['data']['reason'])) {
		print '        <div style="margin-top:6px; padding:6px 10px; background:#fff; border-left:3px solid #ef4444; border-radius:4px; font-size:11px; color:#b91c1c; font-weight:500;">';
		print '          <span class="fa fa-info-circle"></span> Motif : <strong>' . dol_escape_htmltag($evt['data']['reason']) . '</strong>';
		print '        </div>';
	}
	
	print '      </div>';
	print '    </div>';
}

print '  </div>'; // End fe-timeline
print '</div>';
