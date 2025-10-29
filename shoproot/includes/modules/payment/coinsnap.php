<?php
require_once(DIR_FS_EXTERNAL.'/coinsnap/loader.php');

if(!defined('COINSNAP_MODIFIED_VERSION')){ define( 'COINSNAP_MODIFIED_VERSION', '1.1.0' ); }
if(!defined('COINSNAP_MODIFIED_REFERRAL_CODE')){ define( 'COINSNAP_MODIFIED_REFERRAL_CODE', 'D19385' ); }
if(!defined('COINSNAP_CURRENCIES')){ define( 'COINSNAP_CURRENCIES', array("EUR","USD","SATS","BTC","CAD","JPY","GBP","CHF","RUB") ); }
if(!defined('COINSNAP_SERVER_URL')){ define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' );}
if(!defined('COINSNAP_API_PATH')){define( 'COINSNAP_API_PATH', '/api/v1/');}
if(!defined('COINSNAP_SERVER_PATH')){define( 'COINSNAP_SERVER_PATH', 'stores' );}
  
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
    
    public const COINSNAP_WEBHOOK_EVENTS = ['New','Expired','Settled','Processing'];
    public const BTCPAY_WEBHOOK_EVENTS = ['InvoiceCreated','InvoiceExpired','InvoiceSettled','InvoiceProcessing'];
    
    
    function __construct(){
        global $order;

        $this -> signature = 'coinsnap|1.1.0|2.2';
        $this -> code = 'coinsnap';
        $this -> title = MODULE_PAYMENT_COINSNAP_TEXT_TITLE;
        $this -> description = MODULE_PAYMENT_COINSNAP_TEXT_DESCRIPTION;
        $this -> SortOrder = defined('MODULE_PAYMENT_COINSNAP_SORT_ORDER')?MODULE_PAYMENT_COINSNAP_SORT_ORDER:'';
        $this -> enabled = (defined('MODULE_PAYMENT_COINSNAP_STATUS') && MODULE_PAYMENT_COINSNAP_STATUS == 'True') ? true : false;
        
        $this -> provider = (defined('MODULE_PAYMENT_COINSNAP_PROVIDER') &&  MODULE_PAYMENT_COINSNAP_PROVIDER === 'btcpay') ? 'btcpay' : 'coinsnap';
        
        $this -> ApiUrl = ($this -> provider === 'btcpay')? MODULE_PAYMENT_COINSNAP_BTCPAY_SERVER_URL : COINSNAP_SERVER_URL;
        $this -> StoreId = ($this -> provider === 'btcpay')? MODULE_PAYMENT_COINSNAP_BTCPAY_STORE_ID : MODULE_PAYMENT_COINSNAP_STORE_ID;
        $this -> ApiKey = ($this -> provider === 'btcpay')? MODULE_PAYMENT_COINSNAP_BTCPAY_API_KEY : MODULE_PAYMENT_COINSNAP_API_KEY;
        
        $this -> autoredirect = (defined('MODULE_PAYMENT_COINSNAP_AUTOREDIRECT') && MODULE_PAYMENT_COINSNAP_AUTOREDIRECT === True)? true : false;
        $this -> returnUrl = (defined('MODULE_PAYMENT_COINSNAP_RETURNURL') && !empty(MODULE_PAYMENT_COINSNAP_RETURNURL))? MODULE_PAYMENT_COINSNAP_RETURNURL : '';
        
        $this -> discountEnabled = MODULE_PAYMENT_COINSNAP_DISCOUNT_ENABLED;
        $this -> discountTtype = MODULE_PAYMENT_COINSNAP_DISCOUNT_TYPE;
        $this -> discountAmount = MODULE_PAYMENT_COINSNAP_DISCOUNT_AMOUNT;
        $this -> discountAmountLimit = MODULE_PAYMENT_COINSNAP_DISCOUNT_LIMIT;
        $this -> discountPercentage = MODULE_PAYMENT_COINSNAP_DISCOUNT_PERCENTAGE;
        
        $this -> webhookUrl = xtc_href_link('callback/coinsnap/coinsnap_callback.php');
        $this -> webhook = (defined('MODULE_PAYMENT_COINSNAP_WEBHOOK') && !empty(MODULE_PAYMENT_COINSNAP_WEBHOOK))? MODULE_PAYMENT_COINSNAP_WEBHOOK : '';
  
        if (!defined('RUN_MODE_ADMIN') && is_object($order)) {
            $this->update_status();
        }
    }

    // class methods
    function update_status() {
        global $order;

        if ( ($this->enabled == true) && ((int)MODULE_PAYMENT_COINSNAP_ZONE > 0) ){
            $check_flag = false;
            $check_query = xtc_db_query("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_COINSNAP_ZONE . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id");
            while ($check = xtc_db_fetch_array($check_query)){
                if ($check['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                }
                elseif($check['zone_id'] == $order->billing['zone_id']) {
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

    function selection(){      
        return ['id' => $this->code, 'module' => $this->title, 'description'=>$this->description];
    }

    function pre_confirmation_check(){
        return false;
    }

    function confirmation(){
      return false;
    }

    function process_button() {      
      return false;
    }

    function before_process() {
        return false;  
    }

    function get_error(){
        return false;
    }
    
    public function checkAmount($amount, $currency){
        $client = new \Coinsnap\Client\Invoice($this->ApiUrl, $this->ApiKey);
        $store = new \Coinsnap\Client\Store($this->ApiUrl, $this->ApiKey);
        $checkInvoice = [];

        try {
            $_provider = $this->provider;
            if ($_provider === 'btcpay') {
                try {
                    $storePaymentMethods = $store->getStorePaymentMethods($this->StoreId);

                    if ($storePaymentMethods['code'] === 200) {
                        if (!$storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']) {
                            $errorMessage = 'No payment method is configured on BTCPay server';
                            $checkInvoice = array('result' => false,'error' => $errorMessage);
                        }
                    } else {
                        $errorMessage = 'Error store loading. Wrong or empty Store ID';
                        $checkInvoice = array('result' => false,'error' => $errorMessage);
                    }

                    if ($storePaymentMethods['result']['onchain'] && !$storePaymentMethods['result']['lightning']) {
                        $checkInvoice = $client->checkPaymentData((float)$amount, strtoupper($currency), 'bitcoin');
                    } elseif ($storePaymentMethods['result']['lightning']) {
                        $checkInvoice = $client->checkPaymentData((float)$amount, strtoupper($currency), 'lightning');
                    }
                } catch (\Throwable $e) {
                    $errorMessage = 'API connection is not established';
                    $checkInvoice = array('result' => false,'error' => $errorMessage);
                }
            } else {
                $checkInvoice = $client->checkPaymentData((float)$amount, strtoupper($currency));
            }
        } catch (\Throwable $e) {
            $errorMessage = 'API connection is not established';
            $checkInvoice = array('result' => false,'error' => $errorMessage);
        }
        return $checkInvoice;
    }
    
    function after_process(){      
        global $order, $xtPrice, $insert_id, $_GET;        
        
        if (filter_input(INPUT_GET,'orderid',FILTER_SANITIZE_FULL_SPECIAL_CHARS) !== null){ return false; }
    
        $currency = $_SESSION['currency'];      
        if ($_SESSION['customers_status']['customers_status_show_price_tax'] === 0 && $_SESSION['customers_status']['customers_status_add_tax_ot'] === 1) {
            $total = $order->info['pp_total'] + $order->info['tax'];
        }
        else {
            $total = $order->info['pp_total'];
        }
      
        $OrderId = $order->info['orders_id'];
      
        $amount = round($total, $xtPrice->get_decimal_places($currency));
        
        
      
        if (! $this->webhookExists($this->ApiUrl, $this->ApiKey, $this->StoreId)){
            if (! $this->registerWebhook($this->ApiUrl, $this->ApiKey, $this->StoreId)) {                
                echo 'Unable to set Webhook url.';
                exit;
            }
        }
        
        $client = new \Coinsnap\Client\Invoice( $this->ApiUrl, $this->ApiKey);
        $checkInvoice = $client->checkPaymentData((float)$amount,strtoupper($currency));
        
        if($checkInvoice['result'] === true){
            
            $buyerName =  $order->billing['firstname'].' '.$order->billing['lastname'];
            $buyerEmail = $order->customer['email_address'];

            $redirectUrl = (!empty($this -> returnUrl))? $this -> returnUrl : xtc_href_link(FILENAME_CHECKOUT_PROCESS, 'orderid=' . $OrderId, 'NONSSL', true, false);
		
            $metadata = [];
            $metadata['orderNumber'] = $OrderId;
            $metadata['customerName'] = $buyerName;
            
            if($this->provider === 'btcpay'){
                $metadata['orderId'] = $OrderId;
            }

            $redirectAutomatically = $this -> autoredirect;
            $walletMessage = '';
            
            if($this->provider === 'btcpay' && $currency !== 'BTC'){
                $store = new \Coinsnap\Client\Store($this->ApiUrl, $this->ApiKey);
                $btcpayCurrencies = $store -> getStoreCurrenciesRates($this->StoreId,array($currency));
                $isCurrency = true;
                if(!isset($btcpayCurrencies['result']['error']) && count($btcpayCurrencies['result']['currencies'])>0){
                        if(!isset($btcpayCurrencies['result']['currencies']['BTC_'.$currency])){
                            $isCurrency = false;
                        }
                }
                else {
                    $isCurrency = false;
                }
                    
                // Handle currencies non-supported by BTCPay Server, we need to change them BTC and adjust the amount.
                if( !$isCurrency ){
                        $currency = 'BTC';
                        $rate = 1/$checkInvoice['rate'];
                        $amountBTC = bcdiv(strval($amount), strval($rate), 8);
                        $amount = (float)$amountBTC;
                }
            }
        
            $camount = ($currency === 'BTC')? \Coinsnap\Util\PreciseNumber::parseFloat($amount,8) : \Coinsnap\Util\PreciseNumber::parseFloat($amount,2);
            
            try {
                $invoice = $client->createInvoice(
                    $this->StoreId,  
                    $currency,
                    $camount,
                    $OrderId,
                    $buyerEmail,
                    $buyerName, 
                    $redirectUrl,
                    COINSNAP_MODIFIED_REFERRAL_CODE,
                    $metadata,
                    $redirectAutomatically,
                    $walletMessage
                );

                $payurl = $invoice->getData()['checkoutLink'];
                if (!empty($payurl)){				
                    xtc_redirect($payurl);        
                }
                else {
                    echo "API Error";
                    exit;
                } 
            }
            catch ( \Throwable $e ) {
                echo "Invoice request error: ".$e->getMessage();
            }
        }
    }

    function check() {
        if (!isset($this->_check)) {
            if (defined('MODULE_PAYMENT_COINSNAP_STATUS')) {
                $this->_check = true;
            }
            else {
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

        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_PROVIDER', '', '6', '0', now())");
      
      
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_STORE_ID', '', '6', '0', now())");
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_API_KEY', '', '6', '0', now())");
        
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_BTCPAY_SERVER_URL', '', '6', '0', now())");
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_BTCPAY_STORE_ID', '', '6', '0', now())");
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_BTCPAY_API_KEY', '', '6', '0', now())");
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_WEBHOOK', '', '6', '0', now())");
      
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, date_added) values ('MODULE_PAYMENT_COINSNAP_AUTOREDIRECT', 'True', '6', '0', 'xtc_cfg_select_option(array(\'True\', \'False\'), ', now())");
        
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_RETURNURL', '', '6', '0', now())");
        
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, date_added) values ('MODULE_PAYMENT_COINSNAP_DISCOUNT_ENABLED', 'False', '6', '0', 'xtc_cfg_select_option(array(\'True\', \'False\'), ', now())");
        
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_DISCOUNT_TYPE', '', '6', '0', now())");
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_DISCOUNT_AMOUNT', '', '6', '0', now())");
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_DISCOUNT_AMOUNT_LIMIT', '', '6', '0', now())");
        xtc_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) values ('MODULE_PAYMENT_COINSNAP_DISCOUNT_PERCENTAGE', '', '6', '0', now())");
        
      
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
        return [
            'MODULE_PAYMENT_COINSNAP_STATUS',                   
            'MODULE_PAYMENT_COINSNAP_PROVIDER',
            'MODULE_PAYMENT_COINSNAP_STORE_ID',
            'MODULE_PAYMENT_COINSNAP_API_KEY',
            'MODULE_PAYMENT_COINSNAP_BTCPAY_SERVER_URL',
            'MODULE_PAYMENT_COINSNAP_BTCPAY_STORE_ID',
            'MODULE_PAYMENT_COINSNAP_BTCPAY_API_KEY',
            
            'MODULE_PAYMENT_COINSNAP_WEBHOOK',
            
            'MODULE_PAYMENT_COINSNAP_AUTOREDIRECT',
            'MODULE_PAYMENT_COINSNAP_RETURNURL',
            'MODULE_PAYMENT_COINSNAP_DISCOUNT_ENABLED',
            'MODULE_PAYMENT_COINSNAP_DISCOUNT_TYPE',
            'MODULE_PAYMENT_COINSNAP_DISCOUNT_AMOUNT',
            'MODULE_PAYMENT_COINSNAP_DISCOUNT_AMOUNT_LIMIT',
            'MODULE_PAYMENT_COINSNAP_DISCOUNT_PERCENTAGE',
            
            'MODULE_PAYMENT_COINSNAP_EXP_ORDER_STATUS_ID',
            'MODULE_PAYMENT_COINSNAP_STL_ORDER_STATUS_ID',
            'MODULE_PAYMENT_COINSNAP_PRS_ORDER_STATUS_ID',
            'MODULE_PAYMENT_COINSNAP_ALLOWED',
            'MODULE_PAYMENT_COINSNAP_ZONE',                                      
            'MODULE_PAYMENT_COINSNAP_SORT_ORDER',
        ];
    }

    function webhookExists(string $apiUrl, string $apiKey, string $storeId): bool {	
        
        $whClient = new \Coinsnap\Client\Webhook($apiUrl, $apiKey);
        $storedWebhook = json_decode($this -> webhook,true,512,JSON_THROW_ON_ERROR);
        
	if (is_array($storedWebhook)) {
            
            try {
		$existingWebhook = $whClient->getWebhook( $storeId, $storedWebhook['id'] );
                
                if($existingWebhook->getData()['secret'] === $storedWebhook['secret'] && strpos( $existingWebhook->getData()['url'], $this -> webhookUrl ) !== false){
                    return true;
		}
            }
            catch (\Throwable $e) {
		echo "Webhook check error: ".$e->getMessage();
            }
	}
        try {
            $storeWebhooks = $whClient->getWebhooks( $storeId );
            foreach($storeWebhooks as $webhook){
                if(strpos( $webhook->getData()['url'], $this -> webhookUrl ) !== false){
                    $whClient->deleteWebhook( $storeId, $webhook->getData()['id'] );
                }
            }
        }
        catch (\Throwable $e) {
            echo "Webhook deletion error: ".$e->getMessage();
        }
        
	return false;
    }
    
    public function registerWebhook(string $apiUrl, $apiKey, $storeId){
        
        try {
            $whClient = new Webhook( $apiUrl, $apiKey );
            $webhook_events = ($this->provider === 'btcpay')? self::BTCPAY_WEBHOOK_EVENTS : self::COINSNAP_WEBHOOK_EVENTS;
            $webhook = $whClient->createWebhook(
                $storeId,   //$storeId
		$this -> webhookUrl, //$url
		$webhook_events,   //$specificEvents
		null    //$secret
            );
            
            $webhook_data = [
                    'id' => $webhook->getData()['id'],
                    'secret' => $webhook->getData()['secret'],
                    'url' => $webhook->getData()['url']
            ];
            
            xtc_db_query("UPDATE ".TABLE_CONFIGURATION." SET configuration_value = '".json_encode($webhook_data)."' WHERE configuration_key = 'MODULE_PAYMENT_COINSNAP_WEBHOOK'");
            return $webhook;
	}
        catch (\Throwable $e) {
            echo "Webhook creation error: ".$e->getMessage();
	}
        
        return false;
    }
}
