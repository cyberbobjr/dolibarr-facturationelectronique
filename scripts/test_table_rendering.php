<?php
define('NOCSRFCHECK', 1);
define('NOTOKENRENEWAL', 1);

// Start session to mock logged in admin user
session_start();
$_SESSION['dol_login'] = 'admin';

chdir('/var/www/html/custom/facturationelectronique');

ob_start();
include './inbound_list.php';
$html = ob_get_clean();

$pos = strpos($html, '<table class="tagtable liste">');
if ($pos !== false) {
    echo substr($html, $pos, 4500);
} else {
    echo "Table not found. HTML length: " . strlen($html) . "\n";
    echo substr($html, 0, 1500) . "\n";
}
