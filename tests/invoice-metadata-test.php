<?php
// php tests/invoice-metadata-test.php
// Runs the module's payment_action() with stubs for the shop functions and a fake HTTP client, and checks the
// create-invoice request: top-level orderId (what the webhook matches on) and the order/customer metadata.
declare(strict_types=1);

namespace Coinsnap\Http {
    foreach (['ResponseInterface', 'ClientInterface', 'Response'] as $file) {
        require_once dirname(__DIR__) . '/shoproot/includes/external/coinsnap/Http/' . $file . '.php';
    }

    // Replaces the library's cURL client: answers every call and keeps the create-invoice body.
    class CurlClient implements ClientInterface
    {
        public static ?string $invoiceBody = null;
        public function setCurlOptions(array $options) {}
        public function request(string $method, string $url, array $headers = [], string $body = ''): ResponseInterface
        {
            if ($method === 'POST' && preg_match('#/invoices$#', $url)) {
                self::$invoiceBody = $body;
                return new Response(200, '{"id":"INV-1","checkoutLink":"https://pay.example/i/INV-1"}', []);
            }
            return new Response(404, '{}', []);
        }
    }
}

namespace {
    use Coinsnap\Http\CurlClient;

    error_reporting(E_ALL);
    set_error_handler(function (int $no, string $str, string $file, int $line) {
        throw new ErrorException($str, 0, $no, $file, $line);
    });

    // ---- modified eCommerce stubs ----
    define('DIR_FS_EXTERNAL', dirname(__DIR__) . '/shoproot/includes/external');
    define('TABLE_ORDERS', 'orders');
    define('TABLE_ORDERS_TOTAL', 'orders_total');
    define('FILENAME_CHECKOUT_PROCESS', 'checkout_process.php');
    define('FILENAME_CHECKOUT_PAYMENT', 'checkout_payment.php');
    define('MODULE_PAYMENT_COINSNAP_TEXT_TITLE', 'Coinsnap');
    define('MODULE_PAYMENT_COINSNAP_TEXT_DESCRIPTION', '');
    define('MODULE_PAYMENT_COINSNAP_TEXT_NOT_STARTED', 'not started');
    define('MODULE_PAYMENT_COINSNAP_STATUS', 'True');
    define('MODULE_PAYMENT_COINSNAP_STORE_ID', 'CS-STORE-1');
    define('MODULE_PAYMENT_COINSNAP_API_KEY', 'k');
    define('MODULE_PAYMENT_COINSNAP_ZONE', '0');
    define('ENABLE_SSL', true);
    define('HTTPS_SERVER', 'https://shop.example');
    define('HTTP_SERVER', 'http://shop.example');
    define('DIR_WS_CATALOG', '/');

    class Redirected extends Exception {}
    $GLOBALS['db_rows'] = [];
    function xtc_db_query($q)
    {
        foreach ($GLOBALS['db_rows'] as $pattern => $rows) {
            if (preg_match($pattern, $q)) {
                return new ArrayIterator($rows);
            }
        }
        return new ArrayIterator([]);
    }
    function xtc_db_fetch_array($r)
    {
        if (!$r instanceof ArrayIterator || !$r->valid()) {
            return false;
        }
        $row = $r->current();
        $r->next();
        return $row;
    }
    function xtc_db_input($v) { return addslashes((string) $v); }
    function xtc_db_perform(...$a) {}
    function xtc_href_link($page, $params = '', $ssl = 'NONSSL', $a = true, $b = true) { return 'https://shop.example/' . $page; }
    function xtc_redirect($url) { throw new Redirected($url); }

    require DIR_FS_EXTERNAL . '/../modules/payment/coinsnap.php';

    class TestCoinsnap extends coinsnap
    {
        function ensureWebhook(string $webhookUrl): bool { return true; }
    }

    $passed = 0;
    $failed = 0;
    function check(string $name, bool $ok, $detail = null): void
    {
        global $passed, $failed;
        $ok ? $passed++ : $failed++;
        echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === null ? '' : ' -- got ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    }

    function checkout($order, array $totals): array
    {
        global $insert_id, $xtPrice;
        $GLOBALS['order'] = $order;
        $insert_id = 1007;
        $xtPrice = null;
        $_SESSION = ['customer_id' => 3];
        $GLOBALS['db_rows'] = [
            '#SELECT `orders_id`, `customers_id`#' => [['orders_id' => 1007, 'customers_id' => 3, 'payment_method' => 'coinsnap', 'orders_status' => 1, 'currency' => 'EUR', 'currency_value' => 1, 'purchased_at' => time()]],
            "#`class` = 'ot_total'#" => [['value' => '108.90']],
            '#SELECT `class`, `value` FROM orders_total#' => $totals,
        ];
        CurlClient::$invoiceBody = null;
        try {
            (new TestCoinsnap())->payment_action();
        } catch (Redirected $e) {
            // xtc_redirect() to the checkout link ends the request.
        }
        return json_decode((string) CurlClient::$invoiceBody, true) ?: [];
    }

    // The global $order as checkout_process.php has it (includes/classes/order.php).
    $order = new stdClass();
    $order->info = ['currency' => 'EUR', 'comments' => 'Bitte klingeln', 'shipping_method' => 'DHL (Paket)', 'payment_method' => 'coinsnap', 'total' => 108.9, 'language' => 'german'];
    $order->customer = ['firstname' => 'Erika', 'lastname' => 'Mustermann', 'email_address' => 'erika@example.com', 'telephone' => '+49 30 123456'];
    $order->billing = ['firstname' => 'Erika', 'lastname' => 'Mustermann', 'company' => 'Muster &amp; Co', 'street_address' => 'Hauptstr. 5', 'suburb' => 'Hinterhaus',
        'city' => 'Berlin', 'postcode' => '10115', 'state' => '', 'country' => ['id' => 81, 'title' => 'Deutschland', 'iso_code_2' => 'DE']];
    $order->products = [
        ['qty' => 2, 'name' => 'Tasse', 'model' => 'MUG-1', 'id' => '7', 'price' => 19.5, 'final_price' => 39.0],
        ['qty' => 1, 'name' => 'T-Shirt', 'model' => '', 'id' => '9{3}12', 'price' => 70.0, 'final_price' => 70.0],
    ];
    $totals = [
        ['class' => 'ot_subtotal', 'value' => '109.0000'],
        ['class' => 'ot_shipping', 'value' => '4.9000'],
        ['class' => 'ot_coupon', 'value' => '-5.0000'],
        ['class' => 'ot_tax', 'value' => '18.1500'],
        ['class' => 'ot_total', 'value' => '108.9000'],
    ];

    $b = checkout($order, $totals);
    $m = $b['metadata'] ?? [];
    check('top-level orderId is orders_id (what the webhook matches on)', ($b['orderId'] ?? null) === '1007', $b['orderId'] ?? null);
    check('top-level amount / currency / buyerEmail', ($b['amount'] ?? null) === '108.90' && ($b['currency'] ?? null) === 'EUR' && ($b['buyerEmail'] ?? null) === 'erika@example.com', $b);
    check('metadata orderId / orderNumber', ($m['orderId'] ?? null) === '1007' && ($m['orderNumber'] ?? null) === '1007', $m);
    check('orderUrl', ($m['orderUrl'] ?? null) === 'https://shop.example/admin/orders.php?oID=1007&action=edit', $m['orderUrl'] ?? null);
    check('customerName as before', ($m['customerName'] ?? null) === 'Erika Mustermann');
    check('buyer contact', ($m['buyerName'] ?? null) === 'Erika Mustermann' && ($m['buyerEmail'] ?? null) === 'erika@example.com' && ($m['buyerPhone'] ?? null) === '+49 30 123456', $m);
    check('billing address', ($m['buyerCompany'] ?? null) === 'Muster & Co' && ($m['buyerAddress1'] ?? null) === 'Hauptstr. 5' && ($m['buyerAddress2'] ?? null) === 'Hinterhaus'
        && ($m['buyerCity'] ?? null) === 'Berlin' && ($m['buyerZip'] ?? null) === '10115' && ($m['buyerCountry'] ?? null) === 'DE' && !isset($m['buyerState']), $m);
    check('customer note', ($m['buyerMessage'] ?? null) === 'Bitte klingeln');
    // JSON numbers: 2.0 comes back as 2, so numbers are compared by value.
    check('line items', ($m['lineItems'] ?? null) == [
        ['name' => 'Tasse', 'sku' => 'MUG-1', 'productId' => '7', 'quantity' => 2, 'price' => 19.5, 'total' => 39],
        ['name' => 'T-Shirt', 'productId' => '9{3}12', 'quantity' => 1, 'price' => 70, 'total' => 70],
    ], $m['lineItems'] ?? null);
    check('itemDesc / itemCode', ($m['itemDesc'] ?? null) === '2 x Tasse, T-Shirt' && ($m['itemCode'] ?? null) === 'MUG-1, 9{3}12', [$m['itemDesc'] ?? null, $m['itemCode'] ?? null]);
    check('totals from orders_total', ($m['subtotal'] ?? null) == 109 && ($m['shippingTotal'] ?? null) == 4.9 && ($m['discountTotal'] ?? null) == 5
        && ($m['taxTotal'] ?? null) == 18.15 && ($m['orderTotal'] ?? null) == 108.9 && ($m['currency'] ?? null) === 'EUR', $m);
    check('platform / version / shipping / language', ($m['platform'] ?? null) === 'modified' && ($m['pluginVersion'] ?? null) === '1.0.1'
        && ($m['shippingMethod'] ?? null) === 'DHL (Paket)' && ($m['language'] ?? null) === 'german', $m);
    check('no empty values', $m !== [] && !in_array('', $m, true) && !in_array(null, $m, true));

    $bare = new stdClass();
    $bare->info = ['currency' => 'EUR'];
    $bare->customer = ['email_address' => 'a@example.com'];
    $bare->billing = ['firstname' => 'A', 'lastname' => 'B'];
    $b = checkout($bare, []);
    check('sparse order: invoice still sent with orderId', ($b['orderId'] ?? null) === '1007' && ($b['metadata']['orderNumber'] ?? null) === '1007'
        && ($b['metadata']['buyerName'] ?? null) === 'A B' && !isset($b['metadata']['lineItems']), $b['metadata'] ?? null);

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
