<?php

chdir('../../');
require_once('includes/application_top.php');

require_once(DIR_FS_EXTERNAL.'coinsnap/autoload.php');
require_once(DIR_FS_EXTERNAL.'coinsnap_module/Signature.php');
require_once(DIR_FS_EXTERNAL.'coinsnap_module/InvoiceDecision.php');
require_once(DIR_FS_EXTERNAL.'coinsnap_module/Store.php');
require_once(DIR_FS_EXTERNAL.'coinsnap_module/Orders.php');
require_once(DIR_FS_EXTERNAL.'coinsnap_module/WebhookProcessor.php');
if (!defined('COINSNAP_SERVER_PATH')) define('COINSNAP_SERVER_PATH', 'stores');

$storeId = defined('MODULE_PAYMENT_COINSNAP_STORE_ID') ? MODULE_PAYMENT_COINSNAP_STORE_ID : '';
$apiKey = defined('MODULE_PAYMENT_COINSNAP_API_KEY') ? MODULE_PAYMENT_COINSNAP_API_KEY : '';

$processor = new \CoinsnapModified\WebhookProcessor(
    new \CoinsnapModified\Store(),
    new \CoinsnapModified\Orders(),
    static function (string $invoiceId) use ($storeId, $apiKey): array {
        return (new \Coinsnap\Client\Invoice('https://app.coinsnap.io', $apiKey))->getInvoice($storeId, $invoiceId)->getData();
    },
    static function (string $line): void {
        error_log($line);
    },
    [
        'default' => defined('DEFAULT_ORDERS_STATUS_ID') ? DEFAULT_ORDERS_STATUS_ID : 1,
        'processing' => MODULE_PAYMENT_COINSNAP_PRS_ORDER_STATUS_ID,
        'settled' => MODULE_PAYMENT_COINSNAP_STL_ORDER_STATUS_ID,
        'expired' => MODULE_PAYMENT_COINSNAP_EXP_ORDER_STATUS_ID,
    ],
    (string) $storeId
);

// Only the invoice id is taken from the request; the invoice's state comes from the Coinsnap API.
$status = $processor->handle(
    (string) file_get_contents('php://input'),
    (string) ($_SERVER['HTTP_X_COINSNAP_SIGNATURE'] ?? ''),
    time()
);
http_response_code($status);
echo $status === 200 ? 'OK' : ($status === 401 ? 'Unauthorized' : 'Retry');
