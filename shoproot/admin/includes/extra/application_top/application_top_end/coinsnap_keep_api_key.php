<?php
/* Coinsnap: an empty API key field in the module settings keeps the stored key.
   admin/modules.php saves every posted configuration value without loading the payment module, so the
   module itself can't do this. This file runs in the admin's application_top, after login and permission checks. */

if (isset($current_page) && basename((string) $current_page) === 'modules.php'
    && ($_GET['action'] ?? '') === 'save' && ($_GET['set'] ?? '') === 'payment' && ($_GET['module'] ?? '') === 'coinsnap'
    && isset($_POST['configuration']) && is_array($_POST['configuration'])
    && array_key_exists('MODULE_PAYMENT_COINSNAP_API_KEY', $_POST['configuration'])
    && trim((string) $_POST['configuration']['MODULE_PAYMENT_COINSNAP_API_KEY']) === '') {
  unset($_POST['configuration']['MODULE_PAYMENT_COINSNAP_API_KEY']);
}
