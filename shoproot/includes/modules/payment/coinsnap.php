<?php
  require_once(DIR_FS_EXTERNAL.'/coinsnap/autoload.php');
  
  class coinsnap {
  
    var $code;
    var $title;
    var $info;
    var $description;
    var $SortOrder;
    var $enabled;    
    var $_check;
    
    var $signature;
    var $ApiUrl;
    public const WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];

    
    function __construct() {
      global $order;

      $this->signature = 'coinsnap|1.0|2.2';
      $this->code = 'coinsnap';
      $this->title = MODULE_PAYMENT_COINSNAP_TEXT_TITLE;
      $this->description = MODULE_PAYMENT_COINSNAP_TEXT_DESCRIPTION;
      $this->SortOrder = defined('MODULE_PAYMENT_COINSNAP_SORT_ORDER')?MODULE_PAYMENT_COINSNAP_SORT_ORDER:'';
      $this->enabled = ((defined('MODULE_PAYMENT_COINSNAP_STATUS') && MODULE_PAYMENT_COINSNAP_STATUS == 'True') ? true : false);
      $this->ApiUrl = 'https://app.coinsnap.io';
      $this->StoreId = defined('MODULE_PAYMENT_COINSNAP_STORE_ID')?MODULE_PAYMENT_COINSNAP_STORE_ID:'';
      $this->ApiKey = defined('MODULE_PAYMENT_COINSNAP_API_KEY')?MODULE_PAYMENT_COINSNAP_API_KEY:'';
  
      if (!defined('RUN_MODE_ADMIN') && is_object($order)) {
        $this->update_status();
      }
      if (!defined('COINSNAP_SERVER_PATH'))	define( 'COINSNAP_SERVER_PATH', 'stores' );  

      
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

    function before_process() {
      return false;  
    }


    function get_error() {
      return false;
    }
    function after_process() {      
      global $order, $xtPrice,$insert_id, $_GET;        
      if (isset($_GET['orderid'])) return false;
      $currencyCode = $_SESSION['currency'];      
      if ($_SESSION['customers_status']['customers_status_show_price_tax'] == 0 && $_SESSION['customers_status']['customers_status_add_tax_ot'] == 1) {
        $total = $order->info['pp_total'] + $order->info['tax'];
      } else {
        $total = $order->info['pp_total'];
      }
      
      $OrderId = $order->info['orders_id'];
      
      $amount = round($total, $xtPrice->get_decimal_places($currencyCode));
      
      
      $redirectUrl = xtc_href_link(FILENAME_CHECKOUT_PROCESS, 'orderid=' . $OrderId, 'NONSSL', true, false);
      
            
      $WebhookUrl = $this->getWebhookUrl();        
      
      
     if (! $this->webhookExists($this->StoreId, $this->ApiKey, $WebhookUrl)){
         if (! $this->registerWebhook($this->StoreId, $this->ApiKey,$WebhookUrl)) {                
             echo 'unable to set Webhook url.';
             exit;
         }
      }  
  

      $buyerName =  $order->billing['firstname'].' '.$order->billing['lastname'];
		  $buyerEmail = $order->customer['email_address'];

		  $metadata = [];
		  $metadata['orderNumber'] = $OrderId;
		  $metadata['customerName'] = $buyerName;
		
		  $checkoutOptions = new \Coinsnap\Client\InvoiceCheckoutOptions();
		
  		$checkoutOptions->setRedirectURL( $redirectUrl );
	  	$client = new \Coinsnap\Client\Invoice( $this->ApiUrl, $this->ApiKey );			
		  $camount = \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
		  $invoice = $client->createInvoice(
			  $this->StoreId,  
			  $currencyCode,
			  $camount,
			  $OrderId,
			  $buyerEmail,
			  $buyerName, 
			  $redirectUrl,
			  '',     
			  $metadata,
			  $checkoutOptions
		  );
		
		  $payurl = $invoice->getData()['checkoutLink'] ;
      if (!empty($payurl)){				
        xtc_redirect($payurl);        
      }
      else {
        echo "API Error";      		
        exit;
      }  
  
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
      xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_API_KEY', '', '6', '0', now())");
      xtc_db_query("insert into " . TABLE_CONFIGURATION.  " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, use_function, date_added) values ('MODULE_PAYMENT_COINSNAP_EXP_ORDER_STATUS_ID', '".$DefaultExpId."', '6', '0', 'xtc_cfg_pull_down_order_statuses(', 'xtc_get_order_status_name', now())");
      xtc_db_query("insert into " . TABLE_CONFIGURATION.  " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, use_function, date_added) values ('MODULE_PAYMENT_COINSNAP_STL_ORDER_STATUS_ID', '".$DefaultStlId."', '6', '0', 'xtc_cfg_pull_down_order_statuses(', 'xtc_get_order_status_name', now())");      
      xtc_db_query("insert into " . TABLE_CONFIGURATION.  " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, use_function, date_added) values ('MODULE_PAYMENT_COINSNAP_PRS_ORDER_STATUS_ID', '".$DefaultPrsId."', '6', '0', 'xtc_cfg_pull_down_order_statuses(', 'xtc_get_order_status_name', now())");            
      xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_ALLOWED', '', '6', '0', now())");
      xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_SORT_ORDER', '0', '6', '0', now())");
      xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, use_function, set_function, date_added) values ('MODULE_PAYMENT_COINSNAP_ZONE', '0', '6', '2', 'xtc_get_zone_class_title', 'xtc_cfg_pull_down_zone_classes(', now())");      
    }

    function remove() {
      xtc_db_query("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
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

    
    function getWebhookUrl() {		
        return xtc_href_link('callback/coinsnap/coinsnap_callback.php');
    }    

    function webhookExists(string $storeId, string $apiKey, string $webhook): bool {	
      try {		
        
        $whClient = new \Coinsnap\Client\Webhook( $this->ApiUrl, $apiKey );	        
				$Webhooks = $whClient->getWebhooks( $storeId );
        
        foreach ($Webhooks as $Webhook){					
          //$this->deleteWebhook($storeId,$apiKey, $Webhook->getData()['id']);
          if ($Webhook->getData()['url'] == $webhook) return true;	
        }
      }catch (\Throwable $e) {			        
        return false;
      }
    
      return false;
    }
    function registerWebhook(string $storeId, string $apiKey, string $webhook): bool {	
      try {			
        $whClient = new \Coinsnap\Client\Webhook($this->ApiUrl, $apiKey);
        
        $webhook = $whClient->createWebhook(
          $storeId,   //$storeId
          $webhook, //$url
          self::WEBHOOK_EVENTS,   //$specificEvents
          null    //$secret
        );					
        
        return true;
      } catch (\Throwable $e) {
        echo "err".$e->getMessage();        
        return false;	
      }
  
      return false;
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
