<?php


chdir('../../');
require_once('includes/application_top.php');

// include needed classes
require_once(DIR_WS_CLASSES.'order.php');
require_once(DIR_FS_EXTERNAL.'coinsnap/autoload.php');
if (!defined('COINSNAP_SERVER_PATH'))	define( 'COINSNAP_SERVER_PATH', 'stores' );  

$notify_json = file_get_contents('php://input');  
  
if (empty($notify_json)) {
  echo "Data Missing";
  exit;
}
$notify_ar = json_decode($notify_json, true);
$invoiceId = $notify_ar['invoiceId'];
if (empty($invoiceId)) {
  echo "Invoice id Missing";
  exit;
}
$ApiUrl = 'https://app.coinsnap.io';
$StoreId = defined('MODULE_PAYMENT_COINSNAP_STORE_ID')?MODULE_PAYMENT_COINSNAP_STORE_ID:'';
$ApiKey = defined('MODULE_PAYMENT_COINSNAP_API_KEY')?MODULE_PAYMENT_COINSNAP_API_KEY:'';

try {
  $client = new \Coinsnap\Client\Invoice( $ApiUrl, $ApiKey );			
  $csinvoice = $client->getInvoice($StoreId, $invoiceId);
  $status = $csinvoice->getData()['status'] ;
  $orderId = $csinvoice->getData()['orderId'] ;				

}catch (\Throwable $e) {													
    echo "Error";
    exit;
}
if (empty($orderId)) {
  echo "order Id Missing";
  exit;
}

$statusId = 0;
if ($status == 'Expired') $statusId = MODULE_PAYMENT_COINSNAP_EXP_ORDER_STATUS_ID;
else if ($status == 'Processing') $statusId = MODULE_PAYMENT_COINSNAP_PRS_ORDER_STATUS_ID;
else if ($status == 'Settled') $statusId = MODULE_PAYMENT_COINSNAP_STL_ORDER_STATUS_ID;	
if ($statusId != 0){  
  $comments = '';
  xtc_db_query("UPDATE ".TABLE_ORDERS." SET orders_status = '".$statusId."' WHERE orders_id = '".(int) $orderId."'");
  $sql_data_array = array('orders_id' => (int) $orderId,
                          'orders_status_id' => $statusId,
                          'date_added' => 'now()',
                          'customer_notified' => '0',
                          'comments' => decode_htmlentities($comments),
                          'comments_sent' => '0'
                          );
    xtc_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);  
}
echo "OK";
  
