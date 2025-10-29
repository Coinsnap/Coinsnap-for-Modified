<?php
chdir('../../');
require_once('includes/application_top.php');

// include needed classes
require_once(DIR_WS_CLASSES.'order.php');
require_once(DIR_FS_EXTERNAL.'coinsnap/loader.php');

use Coinsnap\Client\Webhook;

if(!defined('COINSNAP_SERVER_URL')){ define( 'COINSNAP_SERVER_URL', 'https://app.coinsnap.io' );}
if(!defined('COINSNAP_API_PATH')){define( 'COINSNAP_API_PATH', '/api/v1/');}
if(!defined('COINSNAP_SERVER_PATH')){define( 'COINSNAP_SERVER_PATH', 'stores' );}

$provider = (defined('MODULE_PAYMENT_COINSNAP_PROVIDER') &&  MODULE_PAYMENT_COINSNAP_PROVIDER === 'btcpay') ? 'btcpay' : 'coinsnap';
$ApiUrl = ($provider === 'btcpay')? MODULE_PAYMENT_COINSNAP_BTCPAY_SERVER_URL : COINSNAP_SERVER_URL;
$StoreId = ($provider === 'btcpay')? MODULE_PAYMENT_COINSNAP_BTCPAY_STORE_ID : MODULE_PAYMENT_COINSNAP_STORE_ID;
$ApiKey = ($provider === 'btcpay')? MODULE_PAYMENT_COINSNAP_BTCPAY_API_KEY : MODULE_PAYMENT_COINSNAP_API_KEY;


try {
            // First check if we have any input
            $rawPostData = file_get_contents("php://input");
            if (!$rawPostData) {
                http_response_code(400);
                die('No raw post data received');
            }

            // Get headers and check for signature
            $headers = getallheaders();
            $signature = null; $payloadKey = null;
                
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'x-coinsnap-sig' || strtolower($key) === 'btcpay-sig') {
                    $signature = $value;
                    $payloadKey = strtolower($key);
                }
            }

            // Handle missing or invalid signature
            if (null === $signature) {
                http_response_code(401);
                die('Authentication required');
            }

            // Validate the signature
            $webhookJSON = (defined('MODULE_PAYMENT_COINSNAP_WEBHOOK') && !empty(MODULE_PAYMENT_COINSNAP_WEBHOOK))? MODULE_PAYMENT_COINSNAP_WEBHOOK : '';
            $webhook = json_decode($webhookJSON,true);
            if (!Webhook::isIncomingWebhookRequestValid($rawPostData, $signature, $webhook['secret'])) {
                http_response_code(401);
                die('Invalid authentication signature');
            }
            
            try {
                // Parse the JSON payload
                $postData = json_decode($rawPostData, false, 512, JSON_THROW_ON_ERROR);
                
                if ($postData->invoiceId === null) {
                    http_response_code(400);
                    die('No Coinsnap invoiceId provided');
                }

                if(strpos($postData->invoiceId,'test_') !== false){
                    http_response_code(200);
                    die('Successful webhook test');
                }

                $invoice_id = esc_html($postData->invoiceId);
                
                $this->form_data = wpforms()->get( 'form' )->get($form_id,['content_only' => true]);
                $payment_settings = $this->form_data['payments'][ $this->slug ];		
                $this->payment_settings = $payment_settings;


                $client = new \Coinsnap\Client\Invoice( $ApiUrl, $ApiKey );			
                $csinvoice = $client->getInvoice($StoreId, $invoice_id);
                $status = $csinvoice->getData()['status'];
                $orderId = ($this->getPaymentProvider() === 'btcpay')? $csinvoice->getData()['metadata']['orderId'] : $csinvoice->getData()['orderId'];
	
                $order_status = 0;
                
                switch($status){
                    case 'Expired':
                    case 'InvoiceExpired':
                        $order_status = MODULE_PAYMENT_COINSNAP_EXP_ORDER_STATUS_ID;
                        break;
                    
                    case 'Processing':
                    case 'InvoiceProcessing':
                        $order_status = MODULE_PAYMENT_COINSNAP_PRS_ORDER_STATUS_ID;
                        break;
                    
                    case 'Settled':
                    case 'InvoiceSettled':
                        $order_status = MODULE_PAYMENT_COINSNAP_STL_ORDER_STATUS_ID;
                        break;
                    default: break;
                }
                
                if ($order_status != 0){  
                    $comments = '';
                    xtc_db_query("UPDATE ".TABLE_ORDERS." SET orders_status = '".$order_status."' WHERE orders_id = '".(int) $orderId."'");
                    $sql_data_array = array('orders_id' => (int) $orderId,
                          'orders_status_id' => $order_status,
                          'date_added' => 'now()',
                          'customer_notified' => '0',
                          'comments' => decode_htmlentities($comments),
                          'comments_sent' => '0'
                          );
                    xtc_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);  
                }
                
                echo "OK";
                exit;
            }
            catch (JsonException $e) {
                http_response_code(400);
                die('Invalid JSON payload');
            }
        }
        
        catch (\Throwable $e) {
            http_response_code(500);
            die('Internal server error ' . $e->getMessage());
        }

echo "OK";
  
