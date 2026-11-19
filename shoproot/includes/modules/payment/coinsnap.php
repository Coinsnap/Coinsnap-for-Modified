<?php
  require_once(DIR_FS_EXTERNAL.'/coinsnap/autoload.php');
  require_once(DIR_FS_EXTERNAL.'/coinsnap_module/Store.php');
  require_once(DIR_FS_EXTERNAL.'/coinsnap_module/Orders.php');
  require_once(DIR_FS_EXTERNAL.'/coinsnap_module/InvoiceDecision.php');
  require_once(DIR_FS_EXTERNAL.'/coinsnap_module/ReturnCheck.php');
  require_once(DIR_FS_EXTERNAL.'/coinsnap_module/InvoiceMetadata.php');

  if (!function_exists('coinsnap_cfg_api_key')) {
    // Admin edit field: empty, with a hint; the stored key never reaches the browser.
    function coinsnap_cfg_api_key($value, $key) {
      $hint = ($value !== '') ? 'A key ending in ' . htmlspecialchars(substr(html_entity_decode((string) $value, ENT_QUOTES), -4), ENT_QUOTES) . ' is saved. Leave empty to keep it.' : '';
      return '<input type="password" name="configuration[' . htmlspecialchars((string) $key, ENT_QUOTES) . ']" value="" autocomplete="new-password" class="inputModule" />'
        . ($hint !== '' ? '<br /><small>' . $hint . '</small>' : '');
    }
  }
  if (!function_exists('coinsnap_cfg_api_key_hint')) {
    // Admin module overview: only the last characters of the key.
    function coinsnap_cfg_api_key_hint($value) {
      return ((string) $value === '') ? '' : '&hellip;' . htmlspecialchars(substr((string) $value, -4), ENT_QUOTES);
    }
  }
  
  class coinsnap {
  
    var $code;
    var $title;
    var $info;
    var $description;
    var $SortOrder;
    var $enabled;    
    var $_check;
    var $referralCode;
    var $signature;
    var $ApiUrl;
    var $StoreId;
    var $ApiKey;
    // The order is created in a waiting status before the payment page, and its checkout (confirmation mail,
    // cart reset, success page) completes only when the customer returns and the API shows the payment.
    var $tmpOrders = true;
    var $tmpStatus;
    var $form_action_url = '';
    public const WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];
    
    
    function __construct(){
        global $order;

        $this->signature = 'coinsnap|1.0.1|2.2';
        $this->code = 'coinsnap';
        $this->title = MODULE_PAYMENT_COINSNAP_TEXT_TITLE;
        $this->description = MODULE_PAYMENT_COINSNAP_TEXT_DESCRIPTION;
        $this->SortOrder = defined('MODULE_PAYMENT_COINSNAP_SORT_ORDER')?MODULE_PAYMENT_COINSNAP_SORT_ORDER:'';
        $this->enabled = ((defined('MODULE_PAYMENT_COINSNAP_STATUS') && MODULE_PAYMENT_COINSNAP_STATUS == 'True') ? true : false);
        $this->ApiUrl = 'https://app.coinsnap.io';
        $this->StoreId = defined('MODULE_PAYMENT_COINSNAP_STORE_ID')?MODULE_PAYMENT_COINSNAP_STORE_ID:'';
        $this->ApiKey = defined('MODULE_PAYMENT_COINSNAP_API_KEY')?MODULE_PAYMENT_COINSNAP_API_KEY:'';
        $this->referralCode = 'D19385';
        $this->tmpStatus = defined('DEFAULT_ORDERS_STATUS_ID') ? (int) DEFAULT_ORDERS_STATUS_ID : 1;
  
        if (!defined('RUN_MODE_ADMIN') && is_object($order)) {
            $this->update_status();
        }
      
        if (!defined('COINSNAP_SERVER_PATH')){
            define( 'COINSNAP_SERVER_PATH', 'stores' );  
        }

        if (defined('RUN_MODE_ADMIN')) {
            $this->keepStoredApiKey();
        }
    }

    // An empty key field keeps the stored key. admin/modules.php saves without loading this module, so the save itself
    // is handled by admin/includes/extra/application_top/application_top_end/coinsnap_keep_api_key.php; this covers callers that do load it.
    private function keepStoredApiKey() {
        if (($_GET['action'] ?? '') === 'save' && ($_GET['module'] ?? '') === $this->code
            && isset($_POST['configuration']) && is_array($_POST['configuration'])
            && array_key_exists('MODULE_PAYMENT_COINSNAP_API_KEY', $_POST['configuration'])
            && trim((string) $_POST['configuration']['MODULE_PAYMENT_COINSNAP_API_KEY']) === '') {
            unset($_POST['configuration']['MODULE_PAYMENT_COINSNAP_API_KEY']);
        }
        // Installs from before 1.0.1 render the key as a plain text field.
        if ($this->check()) {
            xtc_db_query("update " . TABLE_CONFIGURATION . " set set_function = 'coinsnap_cfg_api_key(', use_function = 'coinsnap_cfg_api_key_hint' where configuration_key = 'MODULE_PAYMENT_COINSNAP_API_KEY' and (set_function is null or set_function = '')");
        }
    }

    // class methods
    function update_status() {
      global $order;

      if ( ($this->enabled == true) && ((int)MODULE_PAYMENT_COINSNAP_ZONE > 0) ) {
        $check_flag = false;
        $check_query = xtc_db_query("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_COINSNAP_ZONE . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id");
        while ($check = xtc_db_fetch_array($check_query)) {
          if ($check['zone_id'] < 1) {
            $check_flag = true;
            break;
          } elseif ($check['zone_id'] == $order->billing['zone_id']) {
            $check_flag = true;
            break;
          }
        }

        if ($check_flag == false) {
          $this->enabled = false;
        }
      }
    }

    function javascript_validation() {
      return false;
    }

    function selection() {      
      return array('id' => $this->code,
                    'module' => $this->title,
                    'description'=>$this->description);
    }

    function pre_confirmation_check() {
		  return false;
	  }

    function confirmation() {
      return false;
    }

    function process_button() {      
      return false;
    }

    // Runs again when the customer comes back to checkout_process.php: the waiting order is completed only
    // when the API shows one of its invoices paid or being paid (guide 6.6). The first pass has no order yet.
    function before_process() {
      if (!isset($_SESSION['tmp_oID']) || !is_numeric($_SESSION['tmp_oID'])) {
        return false;
      }
      $orderId = (int) $_SESSION['tmp_oID'];
      $storeId = (string) $this->StoreId;
      $apiKey = (string) $this->ApiKey;
      $apiUrl = $this->ApiUrl;
      $result = \CoinsnapModified\ReturnCheck::check(new \CoinsnapModified\Store(), $orderId,
        static function (string $invoiceId) use ($apiUrl, $apiKey, $storeId): array {
          return (new \Coinsnap\Client\Invoice($apiUrl, $apiKey))->getInvoice($storeId, $invoiceId)->getData();
        });
      if ($result === \CoinsnapModified\ReturnCheck::SEEN) {
        return false;
      }
      error_log('Coinsnap: checkout return for order ' . $orderId . ' without a payment (' . $result . ')');
      $this->paymentError($result === \CoinsnapModified\ReturnCheck::UNKNOWN ? MODULE_PAYMENT_COINSNAP_TEXT_NOT_CONFIRMED : MODULE_PAYMENT_COINSNAP_TEXT_NOT_PAID);
    }

    // Called by checkout_process.php right after the waiting order was written. The invoice is made from the
    // stored order: its ot_total in the order's own currency (guide 6.2), never from the session or the request.
    function payment_action() {
      global $insert_id, $order, $xtPrice;

      $orderId = (int) (isset($insert_id) ? $insert_id : ($_SESSION['tmp_oID'] ?? 0));
      $stored = $orderId > 0 ? (new \CoinsnapModified\Orders())->find($orderId) : null;
      if ($stored === null || ($stored['payment_method'] ?? '') !== $this->code
          || (int) ($stored['customers_id'] ?? 0) !== (int) ($_SESSION['customer_id'] ?? -1)) {
        error_log('Coinsnap: payment not started, order ' . $orderId . ' not found');
        $this->paymentError(MODULE_PAYMENT_COINSNAP_TEXT_NOT_STARTED);
      }
      $currencyCode = (string) $stored['currency'];
      $decimals = (is_object($xtPrice) && method_exists($xtPrice, 'get_decimal_places')) ? (int) $xtPrice->get_decimal_places($currencyCode) : 2;
      $amount = number_format(round((float) $stored['total'], $decimals), $decimals, '.', '');
      if ((float) $amount <= 0) {
        error_log('Coinsnap: payment not started, order ' . $orderId . ' has no total');
        $this->paymentError(MODULE_PAYMENT_COINSNAP_TEXT_NOT_STARTED);
      }

      $store = new \CoinsnapModified\Store();
      // Recorded so a status the merchant sets by hand later is never overwritten by a webhook (guide 6.8).
      $store->rememberStatus($orderId, (int) $stored['orders_status']);

      if (!$this->ensureWebhook($this->getWebhookUrl())) {
        $this->paymentError(MODULE_PAYMENT_COINSNAP_TEXT_NOT_STARTED);
      }

      $redirectUrl = xtc_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL', true, false);
      $buyerName = trim(($order->billing['firstname'] ?? '') . ' ' . ($order->billing['lastname'] ?? ''));
      $buyerEmail = (string) ($order->customer['email_address'] ?? '');

      $checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
      $checkoutOptions->setRedirectURL($redirectUrl);
      $client = new \Coinsnap\Client\Invoice($this->ApiUrl, $this->ApiKey);
      try {
        $invoiceData = $client->createInvoice(
          $this->StoreId,
          $currencyCode,
          \Coinsnap\Util\PreciseNumber::parseString($amount),
          (string) $orderId,
          $buyerEmail,
          $buyerName,
          $redirectUrl,
          $this->referralCode,
          // orderId / orderNumber stay orders_id: the webhook finds the order by the invoice's orderId.
          $this->invoiceMetadata($order, $orderId),
          $checkoutOptions
        )->getData();
      } catch (\Throwable $e) {
        error_log('Coinsnap: invoice creation failed for order ' . $orderId . ' (status ' . (int) $e->getCode() . ')');
        $invoiceData = [];
      }

      if (empty($invoiceData['checkoutLink']) || empty($invoiceData['id'])) {
        $this->paymentError(MODULE_PAYMENT_COINSNAP_TEXT_NOT_STARTED);
      }
      // Only this invoice, for this amount and currency, can pay the order.
      $store->rememberInvoice((string) $invoiceData['id'], $orderId, $amount, $currencyCode);
      xtc_redirect($invoiceData['checkoutLink']);
    }

    // Order, customer and line-item data for the invoice; a missing field never stops the checkout.
    private function invoiceMetadata($order, int $orderId): array {
      try {
        $data = is_object($order) ? get_object_vars($order) : [];
        // The checkout's order has no language (only orders loaded from the database do).
        if (empty($data['info']['language']) && !empty($_SESSION['language'])) {
          $data['info']['language'] = (string) $_SESSION['language'];
        }
        $totals = (new \CoinsnapModified\Orders())->totals($orderId);
        $server = (defined('ENABLE_SSL') && ENABLE_SSL == true && defined('HTTPS_SERVER') && HTTPS_SERVER != '') ? HTTPS_SERVER : (defined('HTTP_SERVER') ? HTTP_SERVER : '');
        $orderUrl = $server !== ''
          ? rtrim($server, '/') . '/' . ltrim((defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/') . (defined('DIR_ADMIN') ? DIR_ADMIN : 'admin/'), '/') . 'orders.php?oID=' . $orderId . '&action=edit'
          : '';
        $version = explode('|', (string) $this->signature)[1] ?? '';
        $metadata = \CoinsnapModified\InvoiceMetadata::build($data, (string) $orderId, $totals, $orderUrl, $version);
      } catch (\Throwable $e) {
        $metadata = [];
      }
      // As before: the number shown in the admin order list.
      $metadata['orderNumber'] = $orderId;
      return $metadata;
    }

    function get_error() {
      if (!isset($_SESSION['coinsnap_error']) || !is_string($_SESSION['coinsnap_error'])) {
        return false;
      }
      $error = array('title' => MODULE_PAYMENT_COINSNAP_TEXT_TITLE, 'error' => $_SESSION['coinsnap_error']);
      unset($_SESSION['coinsnap_error']);
      return $error;
    }

    function after_process() {
      unset($_SESSION['coinsnap_error']);
      return false;
    }

    // Back to the payment selection with a fixed message; nothing about the API's answer reaches the customer.
    private function paymentError(string $message) {
      $_SESSION['coinsnap_error'] = $message;
      xtc_redirect(xtc_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error=' . $this->code, 'SSL'));
    }

    function check() {
      if (!isset($this->_check)) {
        if (defined('MODULE_PAYMENT_COINSNAP_STATUS')) {
          $this->_check = true;
        } else {
          $check_query = xtc_db_query("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_COINSNAP_STATUS'");
          $this->_check = xtc_db_num_rows($check_query);
        }
      }
      return $this->_check;
    }

    function install() {

      $DefaultExpId = '4';
      $DefaultStlId = '2';
      $DefaultPrsId = '2';

      xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, date_added) values ('MODULE_PAYMENT_COINSNAP_STATUS', 'True', '6', '0', 'xtc_cfg_select_option(array(\'True\', \'False\'), ', now())");      
      xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_STORE_ID', '', '6', '0', now())");
      xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, use_function, date_added) values ('MODULE_PAYMENT_COINSNAP_API_KEY', '', '6', '0', 'coinsnap_cfg_api_key(', 'coinsnap_cfg_api_key_hint', now())");
      xtc_db_query("insert into " . TABLE_CONFIGURATION.  " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, use_function, date_added) values ('MODULE_PAYMENT_COINSNAP_EXP_ORDER_STATUS_ID', '".$DefaultExpId."', '6', '0', 'xtc_cfg_pull_down_order_statuses(', 'xtc_get_order_status_name', now())");
      xtc_db_query("insert into " . TABLE_CONFIGURATION.  " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, use_function, date_added) values ('MODULE_PAYMENT_COINSNAP_STL_ORDER_STATUS_ID', '".$DefaultStlId."', '6', '0', 'xtc_cfg_pull_down_order_statuses(', 'xtc_get_order_status_name', now())");      
      xtc_db_query("insert into " . TABLE_CONFIGURATION.  " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, use_function, date_added) values ('MODULE_PAYMENT_COINSNAP_PRS_ORDER_STATUS_ID', '".$DefaultPrsId."', '6', '0', 'xtc_cfg_pull_down_order_statuses(', 'xtc_get_order_status_name', now())");            
      xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_ALLOWED', '', '6', '0', now())");
      xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_SORT_ORDER', '0', '6', '0', now())");
      xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, use_function, set_function, date_added) values ('MODULE_PAYMENT_COINSNAP_ZONE', '0', '6', '2', 'xtc_get_zone_class_title', 'xtc_cfg_pull_down_zone_classes(', now())");      
    }

    function remove() {
      xtc_db_query("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
      // The webhook secrets go with the module; invoice records stay, they are the payment history of existing orders.
      xtc_db_query("DROP TABLE IF EXISTS `coinsnap_webhook_store`");
      xtc_db_query("DROP TABLE IF EXISTS `coinsnap_webhook`");
    }

    function keys() {
      return array('MODULE_PAYMENT_COINSNAP_STATUS',                   
                   'MODULE_PAYMENT_COINSNAP_STORE_ID',
                   'MODULE_PAYMENT_COINSNAP_API_KEY',
                   'MODULE_PAYMENT_COINSNAP_EXP_ORDER_STATUS_ID',
                   'MODULE_PAYMENT_COINSNAP_STL_ORDER_STATUS_ID',
                   'MODULE_PAYMENT_COINSNAP_PRS_ORDER_STATUS_ID',
                   'MODULE_PAYMENT_COINSNAP_ALLOWED',
                   'MODULE_PAYMENT_COINSNAP_ZONE',                                      
                   'MODULE_PAYMENT_COINSNAP_SORT_ORDER',
                   );
    }

    
    // From the shop's configuration (configure.php), never from the request's Host header (guide 6.3), and without
    // the session id or SEO rewriting that xtc_href_link() may add.
    function getWebhookUrl() {
      $server = (defined('ENABLE_SSL') && ENABLE_SSL == true && defined('HTTPS_SERVER') && HTTPS_SERVER != '') ? HTTPS_SERVER : HTTP_SERVER;
      return rtrim($server, '/') . '/' . ltrim(DIR_WS_CATALOG, '/') . 'callback/coinsnap/coinsnap_callback.php';
    }

    // True when a webhook with a stored secret is in place for this store, registering one if it is missing.
    function ensureWebhook(string $webhookUrl): bool {
      $store = new \CoinsnapModified\Store();
      $client = new \Coinsnap\Client\Webhook($this->ApiUrl, $this->ApiKey);
      $storeId = (string) $this->StoreId;
      $stored = $store->webhook($storeId);
      $legacy = $stored === null ? $store->legacyWebhook() : null;
      $candidate = $stored ?? $legacy;
      if ($candidate !== null) {
        try {
          $existing = $client->getWebhook($storeId, (string) $candidate['webhook_id']);
          if (($existing->getData()['url'] ?? '') === $webhookUrl) {
            if ($legacy !== null) {
              // The pre-release record belongs to this store: keep it under the store's own key (guide 6.4).
              $store->saveWebhook($storeId, (string) $legacy['webhook_id'], (string) $legacy['secret']);
            }
            return true;
          }
        } catch (\Coinsnap\Exception\RequestException $e) {
          // An API error is not "no webhook": registering now would replace a working secret.
          if (!in_array($e->getCode(), [400, 404], true)) {
            return $stored !== null;
          }
        } catch (\Throwable $e) {
          return $stored !== null;
        }
      }
      return $this->registerWebhook($store, $client, $webhookUrl);
    }

    function registerWebhook(\CoinsnapModified\Store $store, \Coinsnap\Client\Webhook $client, string $webhookUrl): bool {
      $storeId = (string) $this->StoreId;
      try {
        $webhook = $client->createWebhook($storeId, $webhookUrl, self::WEBHOOK_EVENTS, null)->getData();
      } catch (\Throwable $e) {
        error_log('Coinsnap: webhook could not be registered (status ' . (int) $e->getCode() . ')');
        return false;
      }
      if (empty($webhook['id']) || empty($webhook['secret'])) {
        return false;
      }
      $store->saveWebhook($storeId, (string) $webhook['id'], (string) $webhook['secret']);

      // Version 1.0.0 never stored its webhook's secret; remove those webhooks so only this one delivers.
      try {
        foreach ($client->getWebhooks($storeId) as $other) {
          if (($other->getData()['url'] ?? '') === $webhookUrl && ($other->getData()['id'] ?? '') !== $webhook['id']) {
            $client->deleteWebhook($storeId, (string) $other->getData()['id']);
          }
        }
      } catch (\Throwable $e) {
        error_log('Coinsnap: older webhooks could not be removed');
      }
      return true;
    }

    function deleteWebhook(string $storeId, string $apiKey, string $webhookid): bool {	    
      
      try {			
        $whClient = new \Coinsnap\Client\Webhook($this->ApiUrl, $apiKey);        
        $webhook = $whClient->deleteWebhook(
          $storeId,   //$storeId
          $webhookid, //$url			
        );					
        return true;
      } catch (\Throwable $e) {
        
        return false;	
      }
  
      }
  }
