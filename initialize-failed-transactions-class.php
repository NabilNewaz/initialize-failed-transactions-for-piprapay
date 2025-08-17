<?php

if (!defined('pp_allowed_access')) {
    die('Direct access not allowed');
}

$plugin_meta = [
    'Plugin Name'       => 'Initialize & Failed Transactions',
    'Description'       => 'Initialize & Failed Transactions is a powerful PipraPay plugin that provides detailed transaction analytics of initialize and failed transactions to the PipraPay Payment Panel App — completely free.',
    'Version'           => '1.0.0',
    'Author'            => 'Nabil Newaz',
    'Author URI'        => 'https://nabilnewaz.com/',
    'License'           => 'GPL-2.0+',
    'License URI'       => 'http://www.gnu.org/licenses/gpl-2.0.txt',
    'Requires at least' => '1.0.0',
    'Plugin URI'        => '',
    'Text Domain'       => '',
    'Domain Path'       => '',
    'Requires PHP'      => ''
];

$funcFile = __DIR__ . '/functions.php';
if (file_exists($funcFile)) {
    require_once $funcFile;
}

// Load the admin UI rendering function
function initialize_failed_transactions_admin_page()
{
    $viewFile = __DIR__ . '/views/admin-ui.php';

    if (file_exists($viewFile)) {
        include $viewFile;
    } else {
        echo "<div class='alert alert-warning'>Admin UI not found.</div>";
    }
}
